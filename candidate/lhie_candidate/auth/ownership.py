"""Order-scoped Recovery Key grants and explicit guest claims, never identity merges."""
import hmac
import json
import re
import time
from urllib.parse import parse_qsl
from uuid import UUID

from django.conf import settings
from django.db import transaction

from ..checkout.db import Database
from ..compat.identity import PHP_TRIM, signed_url_digest, verify_recovery_key
from .sessions import customer
from .service import hash_algorithm
from .types import AuthError, legacy_now

_FIELDS = "id,invoice_number,customer_id,payment_status,order_status AS status,credential_access_key_hash,checkout_key"




def safe_order(row):
    return {name: str(row[name]) if name == "id" else row[name]
            for name in ("id", "invoice_number", "payment_status", "status")}


class OrderAccess:
    def __init__(self, *, limits, app_key, db=None, clock=legacy_now, timestamp=time.time):
        self.limits, self.app_key = limits, app_key
        self.db, self.clock, self.timestamp = db or Database(), clock, timestamp


    def _invoice(self, invoice):
        return invoice.strip(PHP_TRIM).upper()

    def _key(self, row, key):
        stored = row.get("credential_access_key_hash") or ""
        algorithm = hash_algorithm(stored)
        return bool(algorithm and verify_recovery_key(key, stored, algorithm=algorithm))

    def _order(self, order_id):
        try:
            identifier = str(UUID(str(order_id)))
        except (ValueError, TypeError, AttributeError):
            raise AuthError("not_found", 404) from None
        row = self.db.one(f"SELECT {_FIELDS} FROM public.orders WHERE id=%s", (identifier,))
        if row is None:
            raise AuthError("not_found", 404)
        return row

    def recovery(self, request, invoice, key):
        invoice = self._invoice(invoice)
        browser = request.auth_session.browser(request)
        self.limits.reserve([("order-recovery-ip", browser.ip, 5, 60),
                             ("order-recovery-invoice", invoice, 5, 60)])
        row = self.db.one(f"SELECT {_FIELDS} FROM public.orders WHERE invoice_number=%s", (invoice,))
        if row is None or row["payment_status"] != "paid" or not self._key(row, key):
            raise AuthError()
        grants = dict(request.auth_session.data.get("orders") or {})
        now = self.timestamp()
        grants = {order: expiry for order, expiry in grants.items() if type(expiry) in (int, float) and expiry > now}
        grants[str(row["id"])] = now + 1800
        request.auth_session.set("orders", grants)
        return safe_order(row)

    def recovered(self, request, order_id):
        row = self._order(order_id)
        expiry = (request.auth_session.data.get("orders") or {}).get(str(row["id"]), 0)
        if row["payment_status"] != "paid" or type(expiry) not in (int, float) or expiry <= self.timestamp():
            raise AuthError()
        return safe_order(row)

    def owned(self, request, order_id):
        actor = customer(request, db=self.db)
        row = self._order(order_id)
        if row["customer_id"] != actor["id"]:
            raise AuthError("not_found", 404)
        return safe_order(row)

    def owned_list(self, request):
        actor = customer(request, db=self.db)
        rows = self.db.rows(f"SELECT {_FIELDS} FROM public.orders WHERE customer_id=%s "
                            "ORDER BY created_at DESC,id DESC LIMIT 50", (actor["id"],))
        return [safe_order(row) for row in rows]

    def claim(self, request, invoice, key):
        actor = customer(request, db=self.db)
        browser = request.auth_session.browser(request)
        self.limits.reserve([("order-claim", str(actor["id"]) + "\0" + browser.ip, 5, 900)])
        invoice = self._invoice(invoice)
        with transaction.atomic(using=self.db.using):
            found = self.db.one("SELECT customer_id FROM public.orders WHERE invoice_number=%s", (invoice,))
            if found is None or found["customer_id"] is None:
                raise AuthError()
            owner_id = found["customer_id"]
            locked = self.db.rows("SELECT id,email,telegram_id,password,email_verified_at,auth_version FROM public.customers "
                                  "WHERE id IN (%s,%s) ORDER BY id FOR UPDATE", (actor["id"], owner_id))
            customers = {row["id"]: row for row in locked}
            customer(request, db=self.db)
            claimant, owner = customers.get(actor["id"]), customers.get(owner_id)
            row = self.db.one(f"SELECT {_FIELDS} FROM public.orders WHERE invoice_number=%s FOR UPDATE", (invoice,))
            if (not claimant or not owner or not row or row["payment_status"] != "paid"
                    or claimant["auth_version"] != request.auth_session.browser(request).auth_version or not self._key(row, key)):
                raise AuthError()
            if row["customer_id"] == actor["id"]:
                return safe_order(row)
            if row["customer_id"] != owner_id or any(owner.get(field) is not None for field in
                                                     ("email", "telegram_id", "password", "email_verified_at")):
                raise AuthError()
            if self.db.one("SELECT id FROM public.customer_identities WHERE customer_id=%s LIMIT 1", (owner_id,)):
                raise AuthError()
            self.db.execute("UPDATE public.orders SET customer_id=%s,updated_at=%s WHERE id=%s",
                            (actor["id"], self.clock(), row["id"]))
            # Customer actors must never be written to users-backed audit foreign keys.
            self.db.execute("INSERT INTO public.audit_logs(action,subject_type,subject_id,new_values,ip_address,created_at) "
                            "VALUES('order.customer_claimed',%s,%s,%s::json,%s,%s)",
                            ("App\\Models\\Order", str(row["id"]), json.dumps({"customer_id": actor["id"]}),
                             request.META.get("REMOTE_ADDR"), self.clock()))
            return safe_order(row)

    def signed(self, request, order_id):
        raw = request.META.get("QUERY_STRING", "")
        pairs = parse_qsl(raw, keep_blank_values=True)
        signatures = [value for name, value in pairs if name == "signature"]
        expiries = [value for name, value in pairs if name == "expires"]
        if (len(signatures) != 1 or not re.fullmatch(r"[a-f0-9]{64}", signatures[0])
                or len(expiries) != 1 or not re.fullmatch(r"[0-9]{1,12}", expiries[0])
                or self.timestamp() > int(expiries[0])):
            raise AuthError()
        # Laravel signs the raw ordered query, not a parse/re-encoded approximation.
        unsigned_query = "&".join(part for part in raw.split("&") if not part.startswith("signature="))
        path = request.get_full_path().split("?", 1)[0]
        suffix = path + ("?" + unsigned_query if unsigned_query else "")
        candidates = [suffix, request.build_absolute_uri(path) + ("?" + unsigned_query if unsigned_query else "")]
        legacy = getattr(settings, "CANDIDATE_LEGACY_APP_URL", "").rstrip("/")
        if legacy:
            candidates.append(legacy + suffix)
        if not any(hmac.compare_digest(signatures[0], signed_url_digest(value, self.app_key)) for value in candidates):
            raise AuthError()
        return safe_order(self._order(order_id))
