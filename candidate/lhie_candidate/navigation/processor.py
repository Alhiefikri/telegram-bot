"""ID-only processing; session advisory locks serialize chats without DB transactions over I/O."""
import json

from django.db import connection
from .customers import resolve_customer


class NavigationProcessor:
    def __init__(self, repository, presenter, roots, transport):
        self.repository = repository
        self.presenter = presenter
        self.roots = roots
        self.transport = transport

    def process(self, update_id):
        if connection.in_atomic_block or not connection.get_autocommit():
            raise RuntimeError("Navigation processing requires autocommit")
        with connection.cursor() as cursor:
            cursor.execute("SELECT chat_id,state FROM django_candidate.navigation_updates WHERE update_id=%s", [update_id])
            row = cursor.fetchone()
            if row is None or row[1] != "pending":
                return "duplicate"
            chat_id = row[0]
            # A session lock, not a transaction: Telegram never holds catalog/payment row locks.
            cursor.execute("SELECT pg_try_advisory_lock(%s)", [chat_id])
            if not cursor.fetchone()[0]:
                return "busy"
        try:
            with connection.cursor() as cursor:
                cursor.execute("SELECT 1 FROM django_candidate.navigation_updates "
                               "WHERE chat_id=%s AND update_id<%s AND state='pending' LIMIT 1",
                               [chat_id, update_id])
                if cursor.fetchone():
                    return "waiting"
                cursor.execute("SELECT 1 FROM django_candidate.navigation_updates "
                               "WHERE chat_id=%s AND update_id>%s AND state IN ('processing','processed','unknown') LIMIT 1",
                               [chat_id, update_id])
                if cursor.fetchone():
                    cursor.execute("UPDATE django_candidate.navigation_updates "
                                   "SET state='processed',outcome='superseded',completed_at=clock_timestamp() "
                                   "WHERE update_id=%s AND state='pending'", [update_id])
                    return "superseded"
                cursor.execute("UPDATE django_candidate.navigation_updates SET state='processing',claimed_at=clock_timestamp() "
                               "WHERE update_id=%s AND state='pending' RETURNING payload", [update_id])
                claimed = cursor.fetchone()
            if claimed is None:
                return "duplicate"
            payload = claimed[0]
            if isinstance(payload, str):
                payload = json.loads(payload)
            try:
                outcome = self._navigate(payload)
            except Exception:
                self._finish(update_id, "unknown", "handler_failure")
                raise
            self._finish(update_id, "unknown" if outcome == "ambiguous" else "processed", outcome)
            return outcome
        finally:
            with connection.cursor() as cursor:
                cursor.execute("SELECT pg_advisory_unlock(%s)", [chat_id])

    def _finish(self, update_id, state, outcome):
        with connection.cursor() as cursor:
            cursor.execute("UPDATE django_candidate.navigation_updates SET state=%s,outcome=%s,completed_at=clock_timestamp() "
                           "WHERE update_id=%s AND state='processing'", [state, outcome, update_id])

    def _navigate(self, payload):
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
        root = self.roots.current_for_chat(customer, str(chat["id"]))
        if callback and (root is None or self.roots.match(customer, str(chat["id"]), message["message_id"]) is None):
            return self._feedback(customer, callback, "stale")
        with connection.cursor() as cursor:
            cursor.execute("SELECT state FROM django_candidate.navigation_sessions WHERE customer_id=%s", [customer["id"]])
            row = cursor.fetchone()
        state = row[0] if row else {}
        if isinstance(state, str):
            state = json.loads(state)
        surface = None
        if callback:
            data = callback["data"]
        else:
            command = source.get("text", "").strip().split("@", 1)[0].lower()
            if command in ("/start", "/menu", "☰ menu", "menu"):
                data = "menu"
            elif command in ("/product", "/products", "/produk", "/lihat_produk", "/lihat produk", "/katalog"):
                data = "katalog"
            elif (state.get("selection") or {}).get("mode") == "awaiting_custom_quantity":
                data = "quantity:input"
                surface = self.presenter.quantity_input(customer, source.get("text", ""), state)
            else:
                return "unsupported"
        if surface is None:
            surface = self.presenter.route(customer, data, state)
        if surface is None:
            if callback and self.presenter.callback_handled:
                return self._feedback(customer, callback, "acknowledged")
            return self._feedback(customer, callback, "stale") if callback else "unsupported"
        fresh_root = not callback and (data == "katalog" or command == "/start")
        if surface.screen == "home":
            self.roots.banner_reference = surface.options.get("photo_url")
            self.roots.banner_version = surface.options.get("asset_version")
        if root is None or fresh_root:
            outcome = self.roots.establish(customer, str(chat["id"]), surface)
        else:
            outcome = self.roots.render(customer, root, str(chat["id"]), surface,
                                        refresh=bool(callback and data == "catalog:refresh"))
        if outcome in ("applied", "replaced", "established"):
            with connection.cursor() as cursor:
                cursor.execute("INSERT INTO django_candidate.navigation_sessions (customer_id,state) VALUES (%s,%s::jsonb) "
                               "ON CONFLICT (customer_id) DO UPDATE SET state=EXCLUDED.state",
                               [customer["id"], json.dumps(state)])
        return self._feedback(customer, callback, outcome) if callback else outcome

    def _feedback(self, customer, callback, outcome):
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

    def drain(self, limit=25):
        if type(limit) is not int or not 1 <= limit <= 100:
            raise ValueError("invalid work limit")
        with connection.cursor() as cursor:
            cursor.execute("SELECT update_id FROM django_candidate.navigation_updates WHERE state='pending' "
                           "ORDER BY update_id LIMIT %s", [limit])
            identities = [row[0] for row in cursor.fetchall()]
        return [self.process(identity) for identity in identities]
