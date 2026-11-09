"""ID-only processing; session advisory locks serialize chats without DB transactions over I/O."""
import json
import re

from django.db import connection
from .customers import resolve_customer
from .admin_capture import AdminCapture
from .api_management import ApiActions
from .intents import message_intent
from .types import Message
from ..customer_api.admission import ApiFailure

_PAYMENT_CALLBACK = ("(COALESCE(payload #>> '{callback_query,data}', '') LIKE 'detail:pay:%%' "
                     "OR COALESCE(payload #>> '{callback_query,data}', '') LIKE 'checkout:check:%%' "
                     "OR COALESCE(payload #>> '{callback_query,data}', '') LIKE 'checkout:hint:%%' "
                     "OR (payload ? 'message' AND EXISTS ("
                     "SELECT 1 FROM public.customers hint_customer "
                     "JOIN django_candidate.navigation_sessions hint_session "
                     "ON hint_session.customer_id=hint_customer.id "
                     "WHERE hint_customer.telegram_id::text=payload #>> '{message,from,id}' "
                     "AND jsonb_typeof(hint_session.state->'payment_hint')='object')))")


def payment_callback(payload):
    callback = payload.get("callback_query")
    data = callback.get("data") if isinstance(callback, dict) else None
    return isinstance(data, str) and data.startswith(("detail:pay:", "checkout:check:", "checkout:hint:"))


def _root_free_callback(data):
    """One-off admin/order screens do not have to live on the canonical root."""
    return (data in ('status', 'checkout-maintenance:status', 'batal', 'statistik_harian')
            or data.startswith(('order:', 'producticon:', 'familyicon:', 'menuicon:',
                                'messageicon:', 'content:')))


def payment_update(payload):
    if payment_callback(payload):
        return True
    message = payload.get('message')
    if type(message) is not dict or type(message.get('from')) is not dict:
        return False
    with connection.cursor() as cursor:
        cursor.execute('''SELECT EXISTS (SELECT 1 FROM public.customers c
            JOIN django_candidate.navigation_sessions s ON s.customer_id=c.id
            WHERE c.telegram_id=%s AND jsonb_typeof(s.state->'payment_hint')='object')''',
            [message['from']['id']])
        return cursor.fetchone()[0]


def _family_filter(family):
    if family == "payment":
        return " AND " + _PAYMENT_CALLBACK
    if family == "interaction":
        return " AND NOT " + _PAYMENT_CALLBACK
    if family is None:
        return ""
    raise ValueError("invalid navigation family")


class NavigationProcessor:
    def __init__(self, repository, presenter, roots, transport, *, family=None,
                 authentication=None, auth_encrypter=None):
        self.repository = repository
        self.presenter = presenter
        self.roots = roots
        self.transport = transport
        self.family_filter = _family_filter(family)
        self.authentication = authentication
        self.auth_encrypter = auth_encrypter
        self.admin = AdminCapture(presenter, repository)
        self.api = ApiActions()

    def process(self, update_id):
        if connection.in_atomic_block or not connection.get_autocommit():
            raise RuntimeError("Navigation processing requires autocommit")
        with connection.cursor() as cursor:
            # The CASE prevents a lock from being taken for an already-settled row.
            cursor.execute("SELECT chat_id,state,"
                           "CASE WHEN state='pending' THEN pg_try_advisory_lock(chat_id) END "
                           "FROM django_candidate.navigation_updates WHERE update_id=%s" + self.family_filter, [update_id])
            row = cursor.fetchone()
            if row is None or row[1] != "pending":
                return "duplicate"
            chat_id = row[0]
            # A session lock, not a transaction: Telegram never holds catalog/payment row locks.
            if not row[2]:
                return "busy"
        try:
            with connection.cursor() as cursor:
                cursor.execute("SELECT "
                               "EXISTS (SELECT 1 FROM django_candidate.navigation_updates "
                               "WHERE chat_id=%s AND update_id<%s AND state IN ('pending','processing')),"
                               "EXISTS (SELECT 1 FROM django_candidate.navigation_updates "
                               "WHERE chat_id=%s AND update_id>%s "
                               "AND state IN ('processing','processed','unknown'))",
                               [chat_id, update_id, chat_id, update_id])
                earlier, later = cursor.fetchone()
                if earlier:
                    return "waiting"
                if later:
                    cursor.execute("UPDATE django_candidate.navigation_updates "
                                   "SET state='processed',outcome='superseded',completed_at=clock_timestamp() "
                                   "WHERE update_id=%s AND state='pending'", [update_id])
                    return "superseded"
                cursor.execute("UPDATE django_candidate.navigation_updates SET state='processing',claimed_at=clock_timestamp() "
                               "WHERE update_id=%s AND state='pending' RETURNING payload,claimed_at", [update_id])
                claimed = cursor.fetchone()
            if claimed is None:
                return "duplicate"
            payload = claimed[0]
            if isinstance(payload, str):
                payload = json.loads(payload)
            try:
                outcome = self._navigate(payload, update_id, claimed[1])
            except Exception:
                self._finish(update_id, claimed[1], "unknown", "handler_failure")
                raise
            self._finish(update_id, claimed[1], "unknown" if outcome == "ambiguous" else "processed", outcome)
            return outcome
        finally:
            with connection.cursor() as cursor:
                cursor.execute("SELECT pg_advisory_unlock(%s)", [chat_id])

    def _finish(self, update_id, claimed_at, state, outcome):
        with connection.cursor() as cursor:
            cursor.execute("UPDATE django_candidate.navigation_updates SET state=%s,outcome=%s,completed_at=clock_timestamp(), "
                           "payload=CASE WHEN payload ? '_encrypted_web_auth' THEN "
                           "(payload - '_encrypted_web_auth') || '{\"_web_auth_consumed\":true}'::jsonb ELSE payload END "
                           "WHERE update_id=%s AND state='processing' AND claimed_at=%s",
                           [state, outcome, update_id, claimed_at])

    def _navigate(self, payload, update_id, claimed_at):
        from ..auth.telegram_ingress import handle
        auth_outcome = handle(payload, self.transport, authentication=self.authentication, crypto=self.auth_encrypter)
        if auth_outcome is not None:
            return auth_outcome
        callback = payload.get("callback_query")
        source = callback or payload["message"]
        message = source["message"] if callback else source
        chat = message["chat"]
        # P06 private-root admission. Group public routing remains isolated from customer roots.
        if chat["type"] != "private" or chat["id"] != source["from"]["id"]:
            return "foreign"
        customer = (self.repository.customer(source["from"]["id"]) if callback
                    else resolve_customer(self.repository, source["from"]))
        if customer is None:
            return "unknown_customer"
        from django.conf import settings
        if getattr(getattr(settings, 'RUNTIME', None), 'notifications', False):
            from ..notifications.broadcast_intents import BroadcastIntents
            from ..notifications.policy import TestRecipients
            outcome = BroadcastIntents(self.repository, self.transport, TestRecipients(frozenset({'1'}))).handle(
                customer, str(chat['id']), source, callback=bool(callback))
            if outcome is not None:
                if callback:
                    self.transport.execute('answerCallbackQuery', {'callback_query_id': callback['id']})
                return outcome
        self.presenter.callback_notice = None
        self.presenter.callback_handled = False
        receipt_action = None
        if callback:
            match = re.fullmatch(r'(txt:request|receipt:buy_again|receipt:main_menu):([0-9a-fA-F-]{36})', callback["data"])
            if match:
                action, order_id = match.groups()
                from lhie_candidate.receipt.runtime import receipt_service
                receipt = receipt_service()
                try:
                    allocation, _ = receipt.source.authorize(order_id, chat['id'])
                except ValueError:
                    return self._feedback(customer, callback, "stale")
                if allocation['customer_id'] != customer['id']:
                    return self._feedback(customer, callback, "stale")
                if action == 'txt:request':
                    result = receipt.request_download(order_id, customer['id'], chat['id'], payload['update_id'])
                    return self._feedback(customer, callback, "acknowledged" if result else "stale")
                receipt_action = 'menu' if action == 'receipt:main_menu' else 'katalog'
        root = self.roots.current_for_chat(customer, str(chat["id"]))
        state = self.repository.session_state(customer["id"])
        original_state = json.dumps(state, sort_keys=True)

        # A pending admin prompt owns the next private message. Content editing
        # clears itself on an unrelated command; icon capture consumes everything
        # except /admin so a slash command cannot accidentally become an icon.
        if not callback:
            outcome = self._capture_pending(customer, source, state)
            if outcome is not None:
                return outcome

        if callback and receipt_action is None and not _root_free_callback(callback["data"]):
            if root is None or root.message_id != message["message_id"]:
                return self._feedback(customer, callback, "stale")

        surface = None
        data = None
        command = ''
        if callback:
            data = receipt_action or callback["data"]
            if receipt_action is None:
                locale_match = re.fullmatch(r'locale:(id|en|ms)', data)
                if locale_match:
                    self.repository.set_locale(customer["id"], locale_match[1])
                    customer = self.repository.customer(customer["telegram_id"]) or customer
                    state.clear()
                    surface = self.presenter.home(customer)
                    data = 'menu:home'
                elif data.startswith('api:'):
                    surface = self._api_callback(customer, callback, data, state)
                    if surface is not None:
                        data = 'api:menu'
                elif data.startswith(('producticon:', 'familyicon:', 'menuicon:', 'messageicon:', 'content:')):
                    return self._admin_callback(customer, callback, data, state)
                elif data in ('status', 'status:home'):
                    if data == 'status:home':
                        state.clear()
                        surface = self.presenter.home(customer)
                        data = 'menu:home'
                    else:
                        self._clear_state(customer, state)
                        return self._send_surface(customer, callback, self.presenter.order_status(customer))
                elif data == 'statistik_harian':
                    return self._admin_stats(customer, callback)
                elif data == 'checkout-maintenance:status':
                    return self._maintenance_status(customer, callback)
                elif data == 'batal':
                    self.repository.abandon_customer_carts(customer["id"])
                    self._clear_state(customer, state)
                    return self._send_text(customer, callback,
                                           self.presenter.t(customer, 'cart.cancelled'))
                elif re.fullmatch(r'om:[0-9]{6}(?::[on]:[0-9]{14}:[0-9a-fA-F-]{36})?', data):
                    if data.count(':') == 1:
                        surface = self.presenter.orders(customer, month=data[3:])
                    else:
                        surface = self.presenter.orders(customer, data, month=data[3:9])
                        data = 'orders:open'
                elif re.fullmatch(r'order:[^\s:]+', data):
                    self._clear_state(customer, state)
                    return self._order_detail(customer, callback, data[6:])
        else:
            proof = source.get('text', source.get('caption', '')).strip()
            if re.fullmatch(r'/payment(?:@[A-Za-z0-9_]+)?(?:\s+\S+)?', proof, re.I):
                self._clear_state(customer, state)
                return self._manual_proof(customer, source, proof)
            intent = message_intent(proof)
            command = source.get('text', '').strip().split('@', 1)[0].lower()
            if intent.kind == 'start':
                if self.presenter.usdt_funding is not None:
                    self.presenter.usdt_funding.cancel_open(customer["id"])
                state.clear()
                data = 'menu'
                if intent.value:
                    kind, identity = intent.value
                    if kind == 'family':
                        surface = self.presenter.family(customer, identity, state=state)
                    else:
                        surface = self.presenter.detail(customer, identity, state=state)
                    if surface is None:
                        surface = self.presenter.home(customer)
            elif intent.kind == 'menu':
                state.clear()
                data = 'menu'
            elif intent.kind == 'catalog':
                state.clear()
                data = 'katalog'
            elif intent.kind == 'search':
                keyword = intent.value or ''
                if not keyword:
                    self._clear_state(customer, state)
                    return self._send_text(customer, None,
                                           self.presenter.t(customer, 'search.prompt'))
                state.clear()
                surface = self.presenter.catalog(customer, search=keyword, state=state)
                data = 'katalog'
            elif intent.kind == 'language':
                state.clear()
                surface = self.presenter.language(customer)
                data = 'menu:language'
            elif intent.kind == 'support':
                state.clear()
                surface = self.presenter.support(customer)
                data = 'menu:support'
            elif intent.kind == 'account':
                self._clear_state(customer, state)
                return self._send_surface(customer, None, self.presenter.account(customer))
            elif intent.kind == 'order_status':
                self._clear_state(customer, state)
                return self._send_surface(customer, None, self.presenter.order_status(customer))
            elif intent.kind == 'order_history':
                state.clear()
                surface = self.presenter.orders(customer)
                data = 'menu:orders'
            elif intent.kind == 'order_detail':
                invoice = intent.value or ''
                if not invoice:
                    self._clear_state(customer, state)
                    return self._send_text(customer, None, self.presenter.t(
                        customer, 'order.detail.invoice_prompt', example='INV-12345678'))
                self._clear_state(customer, state)
                return self._order_detail(customer, None, invoice)
            elif intent.kind == 'stock':
                self._clear_state(customer, state)
                return self._send_messages(customer, None, self.presenter.stock_messages(customer))
            elif intent.kind == 'admin':
                if not self.admin.is_admin(customer, str(chat['id'])):
                    return self._send_text(customer, None,
                                           self.presenter.t(customer, 'router.access_denied'))
                state.clear()
                self.repository.clear_session_state(customer["id"])
                return self._send_surface(customer, None,
                                          self.admin.content_menu(customer, 0))
            elif intent.kind == 'admin_stats':
                self._clear_state(customer, state)
                return self._admin_stats(customer, None)
            elif intent.kind == 'admin_stock':
                self._clear_state(customer, state)
                if not self.admin.is_admin(customer, str(chat['id'])):
                    return self._send_text(customer, None,
                                           self.presenter.t(customer, 'router.access_denied'))
                return self._admin_stock(customer)
            elif intent.kind == 'admin_dashboard':
                if not self.admin.is_admin(customer, str(chat['id'])):
                    return self._send_text(customer, None,
                                           self.presenter.t(customer, 'router.access_denied'))
                self._clear_state(customer, state)
                dashboard = self.presenter.admin_dashboard_url()
                if dashboard is None:
                    return self._send_text(customer, None,
                                           'Dashboard admin belum punya URL HTTPS publik yang valid.')
                button = {'text': '🌐 Buka Dashboard', 'url': dashboard, 'style': 'primary'}
                return self._send_messages(customer, None, [Message('Buka dashboard admin:',
                                                                    keyboard={'inline_keyboard': [[button]]})])
            elif intent.kind == 'cancel':
                if not self.admin.is_admin(customer, str(chat['id'])):
                    return self._send_text(customer, None,
                                           self.presenter.t(customer, 'router.admin_required'))
                self._clear_state(customer, state)
                return self._admin_cancel(customer, intent.value or '')
            elif intent.kind == 'batal':
                self.repository.abandon_customer_carts(customer["id"])
                self._clear_state(customer, state)
                return self._send_text(customer, None, self.presenter.t(customer, 'cart.cancelled'))
            elif intent.kind == 'deposit':
                state.clear()
                data = "balance:deposit"
                surface = self.presenter.funding_deposit(
                    customer, source.get("text", ""), root=root, chat_id=str(chat["id"]))
            elif (state.get("selection") or {}).get("mode") == "awaiting_custom_quantity":
                data = "quantity:input"
                surface = self.presenter.quantity_input(customer, source.get("text", ""), state)
            elif state.get("payment_hint"):
                data = "checkout:hint:input"
                if command in ("/batal", "/cancel"):
                    state.pop("payment_hint", None)
                    surface = self.presenter.home(customer)
            elif state.get("usdt_amount"):
                data = "usdt:amount:input"
                if command in ("/batal", "/cancel"):
                    state.pop("usdt_amount", None)
                    surface = self.presenter.usdt_chooser(customer)
                else:
                    surface = self.presenter.usdt_amount_input(
                        customer, source.get("text", ""), state, root, str(chat["id"]))
            elif state.get("usdt_hint"):
                data = "usdt:hint:input"
                if command in ("/batal", "/cancel"):
                    state.pop("usdt_hint", None)
                    surface = self.presenter.usdt_chooser(customer)
                else:
                    surface = self.presenter.usdt_hint_input(
                        customer, source.get("text", ""), state, root, str(chat["id"]))
            else:
                if state.get("selection") is not None:
                    state.clear()
                return self._send_text(customer, None,
                                       self.presenter.t(customer, 'router.unknown_command'))
        if callback and data and data.startswith("detail:pay:"):
            from .presentation import _CHECKOUT_PAY, locale
            match = _CHECKOUT_PAY.fullmatch(data)
            selection = state.get("selection") or {}
            if (not match or selection.get("mode") != "browsing"
                    or selection.get("product_id") != int(match[2])
                    or selection.get("interaction_token") != match[3]
                    or selection.get("locale") != locale(customer)):
                return self._feedback(customer, callback, "stale")
        elif callback and data and data.startswith(("checkout:check:", "checkout:hint:")):
            from uuid import UUID
            from .presentation import _CHECKOUT_CHECK, _CHECKOUT_HINT
            match = _CHECKOUT_CHECK.fullmatch(data)
            hint_match = _CHECKOUT_HINT.fullmatch(data)
            try:
                payment_id = UUID(match[1] if match else hint_match[2]) if (match or hint_match) else None
            except ValueError:
                payment_id = None
            if payment_id is None:
                return self._feedback(customer, callback, "stale")
            with connection.cursor() as cursor:
                cursor.execute("SELECT EXISTS (SELECT 1 FROM public.payments p "
                               "JOIN public.orders o ON o.id=p.order_id "
                               "WHERE p.id=%s AND o.customer_id=%s)",
                               [payment_id, customer["id"]])
                if not cursor.fetchone()[0]:
                    return self._feedback(customer, callback, "stale")
        if surface is None:
            if (callback and payment_callback(payload)) or data == "checkout:hint:input":
                with connection.cursor() as cursor:
                    cursor.execute("SELECT pg_advisory_unlock(%s)", [chat["id"]])
                try:
                    if data == "checkout:hint:input":
                        surface = self.presenter.payment_hint(customer, source.get("text", ""), state, root)
                    else:
                        surface = self.presenter.route(customer, data, state,
                                                       root=root, chat_id=str(chat["id"]))
                finally:
                    with connection.cursor() as cursor:
                        cursor.execute("SELECT pg_advisory_lock(%s)", [chat["id"]])
                with connection.cursor() as cursor:
                    cursor.execute(
                        "SELECT EXISTS (SELECT 1 FROM django_candidate.navigation_updates "
                        "WHERE update_id=%s AND state='processing' AND claimed_at=%s), "
                        "(SELECT state FROM django_candidate.navigation_sessions WHERE customer_id=%s)",
                        [update_id, claimed_at, customer["id"]])
                    owned, current_state = cursor.fetchone()
                if isinstance(current_state, str):
                    current_state = json.loads(current_state)
                current_root = self.roots.current_for_chat(customer, str(chat["id"]))
                if (not owned or current_root is None or root is None
                        or current_root.message_id != root.message_id
                        or current_root.generation != root.generation
                        or json.dumps(current_state or {}, sort_keys=True) != original_state):
                    return "superseded"
            else:
                surface = self.presenter.route(customer, data, state,
                                               root=root, chat_id=str(chat["id"]))
        if surface is None:
            if callback and self.presenter.callback_handled:
                return self._feedback(customer, callback, "acknowledged")
            return self._feedback(customer, callback, "stale") if callback else "unsupported"
        fresh_root = not callback and (data == "katalog" or command == "/start")
        if surface.screen == "home":
            self.roots.banner_reference = surface.options.get("photo_url")
            self.roots.banner_version = surface.options.get("asset_version")
        else:
            banner = self.repository.banner_asset(self.roots.bot_asset_scope) or {}
            self.roots.banner_reference = banner.get("file_id")
            self.roots.banner_version = banner.get("version")
        if root is None or fresh_root:
            outcome = self.roots.establish(customer, str(chat["id"]), surface)
        else:
            outcome = self.roots.render(customer, root, str(chat["id"]), surface,
                                        refresh=bool(callback and data == "catalog:refresh"))
        if outcome in ("applied", "replaced", "established"):
            if state.get("payment_hint"):
                current_root = self.roots.current_for_chat(customer, str(chat["id"]))
                if current_root is None:
                    state.pop("payment_hint", None)
                else:
                    state["payment_hint"].update(chat_id=current_root.chat_id,
                        message_id=current_root.message_id, generation=current_root.generation)
            if state.get("usdt_amount"):
                current_root = self.roots.current_for_chat(customer, str(chat["id"]))
                if current_root is None:
                    state.pop("usdt_amount", None)
                else:
                    state["usdt_amount"].update(chat_id=current_root.chat_id,
                        message_id=current_root.message_id, generation=current_root.generation)
            if state.get("usdt_hint"):
                current_root = self.roots.current_for_chat(customer, str(chat["id"]))
                if current_root is None:
                    state.pop("usdt_hint", None)
                else:
                    state["usdt_hint"].update(chat_id=current_root.chat_id,
                        message_id=current_root.message_id, generation=current_root.generation)
            self.repository.save_session_state(customer["id"], state)
        return self._feedback(customer, callback, outcome) if callback else outcome


    # -- outbound adapters -------------------------------------------

    @staticmethod
    def _chat_id(customer, callback):
        return str(callback["message"]["chat"]["id"] if callback
                   else customer["telegram_id"])

    def _payload(self, message):
        payload = {"chat_id": None, "text": message.text}
        if message.html:
            payload["parse_mode"] = "HTML"
        if message.entities:
            payload["entities"] = list(message.entities)
        if message.keyboard:
            payload["reply_markup"] = message.keyboard
        return payload

    def _send_messages(self, customer, callback, messages):
        chat_id = self._chat_id(customer, callback)
        outcome = "applied"
        ids = []
        for message in messages:
            payload = self._payload(message)
            payload["chat_id"] = chat_id
            result = self.transport.execute("sendMessage", payload)
            if result.outcome == "success" and result.message_id:
                ids.append(result.message_id)
            if result.outcome == "ambiguous":
                outcome = "ambiguous"
                break
            if result.outcome == "definite":
                outcome = "stale"
                break
        self._last_message_ids = ids
        return self._feedback(customer, callback, outcome) if callback else outcome

    def _send_surface(self, customer, callback, surface):
        chat_id = self._chat_id(customer, callback)
        payload = {"chat_id": chat_id, "text": surface.caption}
        if surface.options.get("parse_mode"):
            payload["parse_mode"] = surface.options["parse_mode"]
        if surface.keyboard.get("inline_keyboard"):
            payload["reply_markup"] = surface.keyboard
        result = self.transport.execute("sendMessage", payload)
        outcome = "ambiguous" if result.outcome == "ambiguous" else (
            "stale" if result.outcome == "definite" else "applied")
        self._last_message_ids = ([result.message_id] if result.message_id else [])
        return self._feedback(customer, callback, outcome) if callback else outcome

    def _send_text(self, customer, callback, text, *, keyboard=None):
        return self._send_messages(customer, callback,
                                   [Message(text, keyboard=keyboard or {})])

    def _edit_or_send(self, customer, callback, surface, message_id):
        chat_id = self._chat_id(customer, callback)
        if message_id:
            payload = {"chat_id": chat_id, "message_id": message_id,
                       "text": surface.caption}
            if surface.options.get("parse_mode"):
                payload["parse_mode"] = surface.options["parse_mode"]
            if surface.keyboard.get("inline_keyboard"):
                payload["reply_markup"] = surface.keyboard
            result = self.transport.execute("editMessageText", payload)
            if result.outcome in ("success", "not_modified"):
                return "applied"
            if result.outcome != "definite":
                return "ambiguous"
        return self._send_surface(customer, None, surface)

    def _send_secret(self, customer, callback, secret):
        """One memory-only send. Never retries, persists or echoes the value."""
        chat_id = self._chat_id(customer, callback)
        try:
            result = self.transport.execute("sendMessage", {
                "chat_id": chat_id,
                "text": self.presenter.api_secret_message(customer, secret),
                "parse_mode": "HTML",
            })
            return result.outcome == "success"
        except Exception:
            return False

    # -- pending admin flows -----------------------------------------

    def _clear_state(self, customer, state):
        state.clear()
        self.repository.save_session_state(customer["id"], state)

    def _capture_pending(self, customer, source, state):
        content = state.get("content_edit")
        if isinstance(content, dict):
            if not self.admin.is_admin(customer, str(source["chat"]["id"])):
                state.pop("content_edit", None)
                self.repository.save_session_state(customer["id"], state)
                return self._send_text(customer, None,
                                       self.presenter.t(customer, "router.access_denied"))
            messages, next_state = self.admin.capture_content(customer, source, content)
            if next_state is None:
                return self._send_messages(customer, None, messages)
            state.pop("content_edit", None)
            if next_state:
                state.update(next_state)
            self.repository.save_session_state(customer["id"], state)
            if messages:
                return self._send_messages(customer, None, messages)
            return None
        capture = state.get("admin_capture")
        if isinstance(capture, dict):
            if not self.admin.is_admin(customer, str(source["chat"]["id"])):
                state.pop("admin_capture", None)
                self.repository.save_session_state(customer["id"], state)
                return self._send_text(customer, None,
                                       self.presenter.t(customer, "router.access_denied"))
            if message_intent(source.get("text", "")).kind == "admin":
                state.pop("admin_capture", None)
                self.repository.save_session_state(customer["id"], state)
                return None
            surface, next_state = self.admin.capture_icon(customer, source, capture)
            if next_state is None:
                return self._send_surface(customer, None, surface)
            state.pop("admin_capture", None)
            if next_state:
                state.update(next_state)
            self.repository.save_session_state(customer["id"], state)
            if capture.get("message_id"):
                outcome = self._edit_or_send(customer, None, surface, capture["message_id"])
                return self._feedback(customer, None, outcome)
            return self._send_surface(customer, None, surface)
        return None

    # -- command implementations -------------------------------------

    def _api_callback(self, customer, callback, data, state):
        chat_id = str(callback["message"]["chat"]["id"])
        message_id = callback["message"]["message_id"]

        def failure_surface(error):
            key = ("account.api.rate_limited" if error.status == 429
                   else "account.api.unavailable" if error.status == 503
                   else "account.api.stale")
            self.presenter.callback_notice = self.presenter.t(customer, key)
            if error.status == 503 and error.code == "api_maintenance":
                self.presenter.callback_notice = self.presenter.t(
                    customer, "account.api.maintenance_callback")
                return self.presenter.api_maintenance(customer)
            if error.status == 403:
                return self.presenter.api_stale(customer)
            try:
                summary = self.api.summary(customer, chat_id, message_id)
                return self.presenter.api_management(customer, summary, key)
            except ApiFailure:
                return self.presenter.api_maintenance(customer)

        try:
            if not self.api.enabled():
                self.presenter.callback_notice = self.presenter.t(
                    customer, "account.api.maintenance_callback")
                return self.presenter.api_maintenance(customer)
            if data == "api:menu":
                self.presenter.callback_notice = None
                return self.presenter.api_management(
                    customer, self.api.summary(customer, chat_id, message_id))
            if data in ("api:rotate", "api:disable"):
                if data == "api:rotate":
                    nonce = self.api.prepare_rotation(customer, chat_id, message_id)
                    return self.presenter.api_confirmation(customer, "rotate", nonce)
                nonce = self.api.prepare_disable(customer, chat_id, message_id)
                return self.presenter.api_confirmation(customer, "disable", nonce)
            if data == "api:preferences":
                return self.presenter.api_preferences(customer)
            if data == "api:generate":
                issued = self.api.generate(customer, chat_id, message_id)
                sent = self._send_secret(customer, callback, issued.take_secret())
                self.presenter.callback_notice = None
                return self.presenter.api_management(
                    customer, self.api.summary(customer, chat_id, message_id),
                    "account.api.displayed" if sent else "account.api.display_failed")
            match = re.fullmatch(r"api:(rotate|disable):([a-f0-9]{32})", data)
            if match:
                if match[1] == "rotate":
                    issued = self.api.rotate(customer, chat_id, message_id, match[2])
                    sent = self._send_secret(customer, callback, issued.take_secret())
                    notice = "account.api.displayed" if sent else "account.api.display_failed"
                else:
                    self.api.disable(customer, chat_id, message_id, match[2])
                    notice = None
                self.presenter.callback_notice = None
                return self.presenter.api_management(
                    customer, self.api.summary(customer, chat_id, message_id), notice)
            if data.startswith("api:preference:"):
                self.api.preference(customer, chat_id, message_id,
                                    data[len("api:preference:"):])
                customer = self.repository.customer(customer["telegram_id"]) or customer
                self.presenter.callback_notice = None
                return self.presenter.api_management(
                    customer, self.api.summary(customer, chat_id, message_id))
        except ApiFailure as error:
            return failure_surface(error)
        self.presenter.callback_notice = self.presenter.t(customer, "account.api.stale")
        try:
            return self.presenter.api_management(
                customer, self.api.summary(customer, chat_id, message_id))
        except ApiFailure:
            return self.presenter.api_maintenance(customer)

    def _admin_callback(self, customer, callback, data, state):
        chat_id = str(callback["message"]["chat"]["id"])
        if not self.admin.is_admin(customer, chat_id):
            return self._feedback(customer, callback, "denied")
        message_id = callback["message"]["message_id"]
        if data == "producticon:list":
            surface = self.admin.icon_products(customer)
        elif data.startswith("producticon:family:"):
            surface = self.admin.icon_family(customer, int(data.rsplit(":", 1)[1]))
        elif data == "menuicon:list":
            surface = self.admin.icon_menu(customer)
        elif data == "messageicon:list":
            surface = self.admin.icon_message(customer)
        elif re.fullmatch(r"(producticon|familyicon|menuicon|messageicon):pick:[0-9]+", data):
            kind = {"producticon": "product", "familyicon": "family",
                    "menuicon": "menu", "messageicon": "message"}[data.split(":", 1)[0]]
            started = self.admin.start_icon(customer, kind, int(data.rsplit(":", 1)[1]),
                                            message_id)
            if started is None:
                return self._feedback(customer, callback, "stale")
            surface, pending = started
            state.update(pending)
            self.repository.save_session_state(customer["id"], state)
            return self._feedback(customer, callback,
                                  self._edit_or_send(customer, callback, surface, message_id))
        elif re.fullmatch(r"(producticon|familyicon|menuicon|messageicon):clear:[0-9]+", data):
            kind = {"producticon": "product", "familyicon": "family",
                    "menuicon": "menu", "messageicon": "message"}[data.split(":", 1)[0]]
            surface = self.admin.clear_icon(customer, kind, int(data.rsplit(":", 1)[1]))
        elif re.fullmatch(r"content:page:[0-9]+", data):
            surface = self.admin.content_menu(customer, int(data.rsplit(":", 1)[1]))
        elif re.fullmatch(r"content:product:[0-9]+", data):
            surface = self.admin.content_product(customer, int(data.rsplit(":", 1)[1]))
        elif re.fullmatch(r"content:edit:[0-9]+:(description|warranty_policy)", data):
            _, _, product_id, field = data.split(":")
            surface = self.admin.content_locales(customer, int(product_id), field)
        elif re.fullmatch(r"content:locale:[0-9]+:(description|warranty_policy):(id|ms|en)", data):
            _, _, product_id, field, locale = data.split(":")
            messages, pending = self.admin.start_content(
                customer, int(product_id), field, locale, chat_id)
            if pending is None:
                return self._send_messages(customer, callback, messages)
            outcome = self._send_messages(customer, None, messages)
            if outcome not in ("applied",):
                return self._feedback(customer, callback, outcome)
            prompt_id = self._last_message_ids[-1] if self._last_message_ids else None
            pending["content_edit"]["prompt_id"] = prompt_id
            state.update(pending)
            self.repository.save_session_state(customer["id"], state)
            return self._feedback(customer, callback, "applied")
        else:
            return self._feedback(customer, callback, "stale")
        return self._feedback(customer, callback,
                              self._edit_or_send(customer, callback, surface, message_id))

    def _admin_stats(self, customer, callback):
        if not self.admin.is_admin(customer, self._chat_id(customer, callback)):
            if callback:
                return self._feedback(customer, callback, "denied")
            return self._send_text(customer, None,
                                   self.presenter.t(customer, "router.access_denied"))
        stats = self.repository.admin_day_stats()
        lines = [
            "📊 Statistik", "━━━━━━━━━━━━━━",
            "Order Baru: " + str(stats["new_orders"]),
            "Order Dibayar: " + str(stats["paid_orders"]),
            "Produk Terjual: " + str(stats["sold_products"]),
            "Nilai Penjualan: Rp " + f"{stats['sales_idr']:,}".replace(",", "."),
            "", "Pembayaran Diterima:",
            "IDR: Rp " + f"{stats['received_idr']:,}".replace(",", "."),
        ]
        if stats["received_usdt"]:
            from ..domain.money import Currency, Money
            lines.append("USDT: " + Money(Currency.USDT, stats["received_usdt"]).format_decimal())
        rows = []
        dashboard = self.presenter.admin_dashboard_url()
        if dashboard:
            rows.append([{"text": "🌐 Mini App Dashboard", "url": dashboard,
                          "style": "primary"}])
        surface = self.presenter.screen(customer, "\n".join(lines), rows,
                                        screen="admin", html=False)
        return self._send_surface(customer, callback, surface)

    def _admin_stock(self, customer):
        products = sorted(self.repository.products(),
                          key=lambda product: (product["available_stock"],
                                               (product["name"] or "").lower(),
                                               product["id"]))
        if not products:
            return self._send_text(customer, None,
                                   "📦 Stok Produk\n━━━━━━━━━━━━━━\nBelum ada produk aktif.")
        lines = ["📦 Stok Produk", "━━━━━━━━━━━━━━"]
        for product in products:
            available = product["available_stock"]
            icon = "❌" if available <= 0 else ("⚠️" if available <= 2 else "✅")
            line = f"{icon} {product['name']}: {available if available > 0 else 'habis'}"
            if product.get("reserved_stock"):
                line += f" ({product['reserved_stock']} direservasi)"
            lines.append(line)
        lines += ["", "Total produk aktif: " + str(len(products))]
        return self._send_messages(
            customer, None,
            [Message(text) for text in self.presenter._chunk_lines(lines)])

    def _maintenance_status(self, customer, callback):
        maintenance = self.repository.maintenance()
        if maintenance.get("is_active"):
            text = self.presenter.t(customer, "checkout.maintenance")
            if maintenance.get("public_note"):
                text += self.presenter.t(customer, "maintenance.admin_note",
                                         note=maintenance["public_note"])
            keyboard = {"inline_keyboard": [[self.presenter.raw_button(
                self.presenter.t(customer, "checkout.status_again"),
                "checkout-maintenance:status")]]}
            return self._send_text(customer, callback, text, keyboard=keyboard)
        rows = [[self.presenter.raw_button(
            self.presenter.t(customer, "checkout.browse_button"), "katalog")]]
        if self.repository.active_cart_exists(customer["id"]):
            rows.append([self.presenter.raw_button(
                self.presenter.t(customer, "checkout.continue_button"), "checkout")])
        rows.append([self.presenter.raw_button(
            self.presenter.t(customer, "checkout.orders_button"), "status")])
        return self._send_text(customer, callback,
                               self.presenter.t(customer, "checkout.reopened"),
                               keyboard={"inline_keyboard": rows})

    def _admin_cancel(self, customer, invoice):
        if not invoice:
            return self._send_text(customer, None,
                                   "Invoice belum diisi.\n\nGunakan format:\n/cancel INV-xxxx")
        order = self.repository.admin_pending_order(invoice)
        if order is None:
            return self._send_text(customer, None, "Order pending tidak ditemukan.")
        try:
            from ..checkout.lifecycle import OrderLifecycle
            result = OrderLifecycle().apply_pending_cancellation(
                str(order["id"]), customer_id=order["customer_id"])
            if result.order["order_status"] != "cancelled":
                raise ValueError("not cancellable")
            try:
                self.admin.audit(customer, "order.pending_cancelled_released",
                                 "App\\Models\\Order", order["id"],
                                 {"order_status": order["order_status"],
                                  "payment_status": order["payment_status"]},
                                 {"order_status": result.order["order_status"],
                                  "telegram_actor_id": str(customer["telegram_id"])})
            except Exception:
                pass
        except Exception:
            return self._send_text(customer, None,
                                   "Order sudah diproses atau tidak bisa dibatalkan.")
        return self._send_text(
            customer, None,
            "✅ Order dibatalkan\n━━━━━━━━━━━━━━\nInvoice "
            + order["invoice_number"] + " dibatalkan dan stok sudah dilepas kembali.")

    def _order_detail(self, customer, callback, invoice):
        order = self.repository.order_detail_data(customer["id"], invoice)
        if order is None:
            return self._send_text(customer, callback,
                                   self.presenter.t(customer, "order.detail.not_found"))
        header, instruction = self.presenter.order_detail_parts(customer, order)
        messages = list(header)
        if (order.get("payment_status") == "paid"
                and order.get("order_status") in ("processing", "completed")):
            try:
                from ..receipt.historical import normalize_unit, render_order_credentials
                from ..receipt.runtime import receipt_service
                service = receipt_service()
                allocation = service.source.load_customer(
                    str(order["id"]), customer["id"])
                units = render_order_credentials(
                    allocation, support_url=service.source.support_url())
                messages.append(Message(""))
                messages.append(Message(self.presenter.t(
                    customer, "order.detail.account_details")))
                for unit in units:
                    normalized = normalize_unit(unit)
                    messages.append(Message(
                        normalized.text, normalized.entities,
                        normalized.reply_markup, False))
            except Exception:
                # Missing/retired credentials stay an explicit header+guidance
                # screen; never a partial or fabricated account payload.
                pass
        messages.append(instruction)
        return self._send_messages(customer, callback, messages)


    def _feedback(self, customer, callback, outcome):
        if callback and outcome == "invalidated":
            self.transport.execute("sendMessage", {
                "chat_id": callback["message"]["chat"]["id"],
                "text": self.presenter.t(customer, "callback.navigation_stale"),
            })
            return outcome
        notice = None
        if callback and outcome in ("stale", "superseded", "ambiguous"):
            key = "callback.navigation_recovery" if outcome == "ambiguous" else "callback.navigation_stale"
            notice = self.presenter.t(customer, key)
        elif callback and outcome == "denied":
            notice = self.presenter.t(customer, "router.access_denied")
        elif callback and outcome == "invalid":
            notice = self.presenter.t(customer, "callback.unknown_action")
        elif callback and outcome in ("applied", "replaced", "acknowledged"):
            notice = self.presenter.callback_notice
        if notice:
            self.transport.execute("answerCallbackQuery", {
                "chat_id": callback["message"]["chat"]["id"],
                "callback_query_id": callback["id"], "show_alert": True, "text": notice,
            })
        return outcome

    def _manual_proof(self, customer, message, command):
        from ..checkout.errors import CheckoutError
        from ..payments.manual import ManualQrisLifecycle
        from ..payments.presentation import QrPresentation
        invoice = command.split(maxsplit=1)[1] if len(command.split(maxsplit=1)) == 2 else ''
        photos = message.get('photo') or (message.get('reply_to_message') or {}).get('photo') or []
        key = 'payment.proof.photo_required'
        if photos:
            photo = max(photos, key=lambda item: (item.get('width', 0) * item.get('height', 0), item.get('file_size', 0)))
            try:
                result = ManualQrisLifecycle(QrPresentation()).submit_proof(
                    customer_id=customer['id'], invoice=invoice, photo_file_id=photo['file_id'])
                key = {'accepted': 'payment.proof.forwarding', 'duplicate': 'payment.proof.duplicate',
                       'expired': 'payment.proof.deadline_passed',
                       'admin_unavailable': 'payment.proof.admin_unavailable'}[result]
            except CheckoutError:
                key = 'payment.proof.invoice_not_found'
        result = self.transport.execute('sendMessage', {
            'chat_id': str(message['chat']['id']),
            'text': self.presenter.t(customer, key, invoice=invoice)})
        return 'acknowledged' if result.outcome == 'success' else 'ambiguous'

    def drain(self, limit=25):
        if type(limit) is not int or not 1 <= limit <= 100:
            raise ValueError("invalid work limit")
        outcomes = []
        attempted = []
        while len(outcomes) < limit:
            with connection.cursor() as cursor:
                cursor.execute(
                    "SELECT updates.update_id FROM django_candidate.navigation_updates updates "
                    "WHERE updates.state='pending' " + self.family_filter +
                    " AND NOT (updates.update_id=ANY(%s))"
                    " AND NOT EXISTS (SELECT 1 FROM django_candidate.navigation_updates earlier "
                    "WHERE earlier.chat_id=updates.chat_id AND "
                    "(earlier.state='processing' OR "
                    "(earlier.state='pending' AND earlier.update_id<updates.update_id))) "
                    "ORDER BY updates.update_id LIMIT %s", [attempted, limit - len(outcomes)])
                identities = [row[0] for row in cursor.fetchall()]
            if not identities:
                break
            for identity in identities:
                attempted.append(identity)
                outcomes.append(self.process(identity))
        return outcomes

    def recover(self, limit=25, *, stale_seconds=60):
        """Resolve an abandoned claim without replaying an unknown Telegram ACK."""
        if type(limit) is not int or not 1 <= limit <= 100:
            raise ValueError("invalid work limit")
        if type(stale_seconds) is not int or not 1 <= stale_seconds <= 600:
            raise ValueError("invalid stale interval")
        with connection.cursor() as cursor:
            cursor.execute(
                "WITH stale AS ("
                "SELECT update_id FROM django_candidate.navigation_updates "
                "WHERE state='processing' AND claimed_at < clock_timestamp() - make_interval(secs => %s) "
                "AND pg_try_advisory_xact_lock(chat_id) "
                + self.family_filter + " ORDER BY update_id LIMIT %s FOR UPDATE SKIP LOCKED"
                ") UPDATE django_candidate.navigation_updates AS updates "
                "SET state='unknown',outcome='worker_lost',completed_at=clock_timestamp() "
                "FROM stale WHERE updates.update_id=stale.update_id AND updates.state='processing' "
                "RETURNING updates.update_id",
                [stale_seconds, limit],
            )
            return len(cursor.fetchall())
