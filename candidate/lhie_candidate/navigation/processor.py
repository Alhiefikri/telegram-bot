"""ID-only processing; session advisory locks serialize chats without DB transactions over I/O."""
import json
import re

from django.db import connection
from .customers import resolve_customer

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
        if callback and receipt_action is None and (
                root is None or root.message_id != message["message_id"]):
            return self._feedback(customer, callback, "stale")
        with connection.cursor() as cursor:
            cursor.execute("SELECT state FROM django_candidate.navigation_sessions WHERE customer_id=%s", [customer["id"]])
            row = cursor.fetchone()
        state = row[0] if row else {}
        if isinstance(state, str):
            state = json.loads(state)
        original_state = json.dumps(state, sort_keys=True)
        surface = None
        if callback:
            data = receipt_action or callback["data"]
        else:
            proof = source.get('text', source.get('caption', '')).strip()
            if re.fullmatch(r'/payment(?:@[A-Za-z0-9_]+)?(?:\s+\S+)?', proof, re.I):
                return self._manual_proof(customer, source, proof)
            command = source.get("text", "").strip().split("@", 1)[0].lower()
            if command in ("/start", "/menu", "☰ menu", "menu"):
                if command == "/start" and self.presenter.usdt_funding is not None:
                    self.presenter.usdt_funding.cancel_open(customer["id"])
                data = "menu"
            elif command in ("/product", "/products", "/produk", "/lihat_produk", "/lihat produk", "/katalog"):
                data = "katalog"
            elif re.match(r'^/usdt-return(?:@[A-Za-z0-9_]{1,32})?(?:\s|$)', proof, re.I):
                data = "usdt:return"
                state.pop("usdt_amount", None)
                state.pop("usdt_hint", None)
                surface = self.presenter.usdt_return(customer, proof, root, str(chat["id"]), update_id)
            elif re.match(r'^/usdt(?:@[A-Za-z0-9_]{1,32})?(?:\s|$)', proof, re.I):
                data = "usdt:open"
                state.pop("usdt_amount", None)
                state.pop("usdt_hint", None)
                parts = re.split(r'\s+', proof, maxsplit=1)
                surface = self.presenter.usdt_chooser(customer,
                    'balance.usdt.topup.unavailable' if len(parts) > 1 else None)
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
            elif command.startswith("/deposit"):
                data = "balance:deposit"
                surface = self.presenter.funding_deposit(
                    customer, source.get("text", ""), root=root, chat_id=str(chat["id"]))
            else:
                return "unsupported"
        if callback and data.startswith("detail:pay:"):
            from .presentation import _CHECKOUT_PAY, locale
            match = _CHECKOUT_PAY.fullmatch(data)
            selection = state.get("selection") or {}
            if (not match or selection.get("mode") != "browsing"
                    or selection.get("product_id") != int(match[2])
                    or selection.get("interaction_token") != match[3]
                    or selection.get("locale") != locale(customer)):
                return self._feedback(customer, callback, "stale")
        elif callback and data.startswith(("checkout:check:", "checkout:hint:")):
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
            with connection.cursor() as cursor:
                cursor.execute("INSERT INTO django_candidate.navigation_sessions (customer_id,state) VALUES (%s,%s::jsonb) "
                               "ON CONFLICT (customer_id) DO UPDATE SET state=EXCLUDED.state",
                               [customer["id"], json.dumps(state)])
        return self._feedback(customer, callback, outcome) if callback else outcome

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