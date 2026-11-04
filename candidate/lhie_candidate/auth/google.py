"""Session-bound Google claims over the canonical Laravel customer tables."""
from datetime import datetime, timedelta
import hmac
import re
import secrets

from django.db import IntegrityError, connections, transaction

from ..checkout.db import Database
from ..compat.identity import browser_nonce_binding, normalize_credential_identity, sha256_digest
from .google_provider import GoogleIdentity
from .types import AuthError, LEGACY_ZONE, Principal, legacy_now, principal, require_fresh

_NONCE = re.compile(r"[A-Za-z0-9_-]{43}", re.ASCII)
_CUSTOMER_COLUMNS = "id,email,email_verified_at,auth_version,telegram_id"


def _proof_time(browser):
    if type(browser.authenticated_at) is not int:
        raise AuthError()
    try:
        return datetime.fromtimestamp(browser.authenticated_at, LEGACY_ZONE).replace(tzinfo=None)
    except (OverflowError, OSError, ValueError):
        raise AuthError() from None


class GoogleAuthentication:
    def __init__(self, *, app_key, limits, provider, db=None, clock=legacy_now):
        self.app_key, self.limits, self.provider = app_key, limits, provider
        self.db, self.clock = db or Database(), clock

    def ready(self):
        return bool(self.app_key) and self.provider.ready()

    def _boundary(self):
        connection = connections[self.db.using]
        if connection.in_atomic_block or not connection.get_autocommit():
            raise AuthError("unavailable", 503)

    def _limit(self, purpose, browser):
        enabled = self.db.one("SELECT enabled FROM public.customer_auth_settings WHERE singleton_key='customer_auth'")
        if not self.ready() or not enabled or enabled["enabled"] is not True:
            raise AuthError("unavailable", 503)
        self.limits.reserve([(purpose, browser.session_id + "\0" + browser.ip, 10, 900)])

    def _customer(self, customer_id):
        self.db.execute("SET LOCAL search_path = pg_catalog, public, pg_temp")
        return self.db.one(f"SELECT {_CUSTOMER_COLUMNS} FROM public.customers WHERE id=%s FOR UPDATE", [customer_id])

    def start(self, browser, operation):
        self._boundary()
        self._limit("google-start", browser)
        if operation not in ("login", "register", "link") or ((operation == "link") != (browser.customer_id is not None)):
            raise AuthError()
        state, nonce, now = secrets.token_urlsafe(32), secrets.token_urlsafe(32), self.clock()
        fingerprint = self.provider.configuration_fingerprint()
        if not isinstance(fingerprint, str) or re.fullmatch(r"[a-f0-9]{64}", fingerprint) is None:
            raise AuthError("unavailable", 503)
        with transaction.atomic(using=self.db.using):
            customer = self._customer(browser.customer_id) if operation == "link" else None
            if operation == "link":
                if not customer:
                    raise AuthError()
                require_fresh(browser, customer, now)
            self.db.execute(
                "INSERT INTO public.customer_google_auth_claims "
                "(operation,customer_id,auth_version,authenticated_at,state_hash,session_binding_hash,"
                "configuration_fingerprint,expires_at,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                [operation, customer["id"] if customer else None, customer["auth_version"] if customer else None,
                 _proof_time(browser) if customer else None, sha256_digest(state),
                 browser_nonce_binding(browser.session_id, nonce), fingerprint, now + timedelta(seconds=300), now, now])
        return {"authorization_url": self.provider.authorization_url(state), "nonce": nonce}

    def _browser_matches(self, claim, browser, now):
        if claim["operation"] in ("login", "register"):
            return browser.customer_id is None
        if (claim["customer_id"] != browser.customer_id or claim["auth_version"] != browser.auth_version
                or claim["authenticated_at"] != _proof_time(browser)):
            return False
        require_fresh(browser, {"id": claim["customer_id"], "auth_version": claim["auth_version"]}, now)
        return True

    def _configuration_matches(self, claim):
        fingerprint = self.provider.configuration_fingerprint()
        return isinstance(fingerprint, str) and hmac.compare_digest(claim["configuration_fingerprint"], fingerprint)

    def callback(self, browser, state, code, nonce, *, error=None):
        self._boundary()
        if (not isinstance(state, str) or _NONCE.fullmatch(state) is None
                or not isinstance(nonce, str) or _NONCE.fullmatch(nonce) is None):
            raise AuthError()
        snapshot = self.db.one("SELECT * FROM public.customer_google_auth_claims WHERE state_hash=%s", [sha256_digest(state)])
        if not snapshot:
            raise AuthError()
        with transaction.atomic(using=self.db.using):
            customer = self._customer(snapshot["customer_id"]) if snapshot["customer_id"] else None
            claim = self.db.one("SELECT * FROM public.customer_google_auth_claims WHERE id=%s FOR UPDATE", [snapshot["id"]])
            now = self.clock()
            if (not claim or claim["consumed_at"] is not None or claim["expires_at"] <= now
                    or not hmac.compare_digest(claim["session_binding_hash"], browser_nonce_binding(browser.session_id, nonce))
                    or not self._browser_matches(claim, browser, now)
                    or (claim["operation"] == "link" and (not customer or customer["auth_version"] != claim["auth_version"]))):
                raise AuthError()
            self.db.execute("UPDATE public.customer_google_auth_claims SET consumed_at=%s,updated_at=%s WHERE id=%s", [now, now, claim["id"]])
        # This commit is deliberately before configuration, exchange, or provider failure.
        if not self._configuration_matches(claim):
            raise AuthError("unavailable", 503)
        self._limit("google-callback", browser)
        if error is not None or not isinstance(code, str) or re.fullmatch(r"[\x21-\x7e]{1,4096}", code) is None:
            raise AuthError()
        try:
            proof = self.provider.user(code)
        except AuthError:
            raise
        except Exception:
            raise AuthError("unavailable", 503) from None
        if not isinstance(proof, GoogleIdentity):
            raise AuthError()
        if not self._configuration_matches(claim):
            raise AuthError("unavailable", 503)
        return self._link(claim, proof, browser) if claim["operation"] == "link" else self._sign_in(claim, proof, browser)

    def _save_identity(self, customer, proof, existing=None):
        now = self.clock()
        if existing:
            self.db.execute("UPDATE public.customer_identities SET provider_email=%s,last_used_at=%s,updated_at=%s WHERE id=%s",
                            [proof.email, now, now, existing["id"]])
            return existing["id"]
        row = self.db.one("INSERT INTO public.customer_identities "
                          "(customer_id,provider,provider_subject,provider_email,last_used_at,created_at,updated_at) "
                          "VALUES (%s,'google',%s,%s,%s,%s,%s) RETURNING id",
                          [customer["id"], proof.subject, proof.email, now, now, now])
        return row["id"]

    def _preserved(self, customer, browser, method=None):
        return Principal(customer["id"], customer["auth_version"], browser.authenticated_at, method or browser.method)

    def _link(self, claim, proof, browser):
        with transaction.atomic(using=self.db.using):
            customer = self._customer(claim["customer_id"])
            if (not customer or customer["auth_version"] != claim["auth_version"]
                    or not self._browser_matches(claim, browser, self.clock())):
                raise AuthError()
            owned = self.db.one("SELECT * FROM public.customer_identities WHERE provider='google' AND customer_id=%s FOR UPDATE", [customer["id"]])
            subject = self.db.one("SELECT * FROM public.customer_identities WHERE provider='google' AND provider_subject=%s", [proof.subject])
            if ((owned and owned["provider_subject"] != proof.subject)
                    or (subject and subject["customer_id"] != customer["id"])):
                raise AuthError("conflict")
            try:
                with transaction.atomic(using=self.db.using):
                    self._save_identity(customer, proof, owned)
            except IntegrityError:
                raise AuthError("conflict") from None
        return self._preserved(customer, browser)

    def _sign_in(self, claim, proof, browser):
        with transaction.atomic(using=self.db.using):
            if not self._browser_matches(claim, browser, self.clock()):
                raise AuthError()
            snapshot = self.db.one("SELECT * FROM public.customer_identities WHERE provider='google' AND provider_subject=%s", [proof.subject])
            if snapshot:
                customer = self._customer(snapshot["customer_id"])
                identity = self.db.one("SELECT * FROM public.customer_identities WHERE id=%s FOR UPDATE", [snapshot["id"]])
                if (not customer or not identity or identity["customer_id"] != customer["id"]
                        or identity["provider"] != "google" or identity["provider_subject"] != proof.subject):
                    raise AuthError()
                identity_id = self._save_identity(customer, proof, identity)
            else:
                if self.db.one("SELECT id FROM public.customers WHERE email=%s", [proof.email]):
                    raise AuthError("collision")
                if claim["operation"] != "register":
                    raise AuthError()
                now = self.clock()
                try:
                    with transaction.atomic(using=self.db.using):
                        customer = self.db.one("INSERT INTO public.customers "
                                               "(first_name,email,email_verified_at,auth_version,enrollment_version,created_at,updated_at) "
                                               f"VALUES ('Pelanggan',%s,%s,0,0,%s,%s) RETURNING {_CUSTOMER_COLUMNS}", [proof.email, now, now, now])
                        identity_id = self._save_identity(customer, proof)
                except IntegrityError:
                    raise AuthError("collision" if self.db.one("SELECT id FROM public.customers WHERE email=%s", [proof.email]) else "conflict") from None
        return principal(customer, "google:" + str(identity_id), self.clock())

    def _alternative(self, customer):
        now = self.clock()
        if isinstance(customer["email"], str) and customer["email_verified_at"] is not None and customer["email_verified_at"] <= now:
            try:
                normalize_credential_identity("email", customer["email"])
                return "magic"
            except ValueError:
                pass
        actor = str(customer["telegram_id"])
        if (re.fullmatch(r"[1-9][0-9]{0,18}", actor) and int(actor) <= 9223372036854775807
                and self.db.one("SELECT id FROM public.customer_identities WHERE customer_id=%s AND provider='telegram' AND provider_subject=%s", [customer["id"], actor])):
            return "telegram"
        return None

    def unlink(self, browser):
        self._boundary()
        self._limit("google-unlink", browser)
        with transaction.atomic(using=self.db.using):
            customer = self._customer(browser.customer_id)
            if not customer:
                raise AuthError()
            require_fresh(browser, customer, self.clock())
            identity = self.db.one("SELECT * FROM public.customer_identities WHERE provider='google' AND customer_id=%s FOR UPDATE", [customer["id"]])
            if not identity:
                raise AuthError()
            alternative = self._alternative(customer)
            if alternative is None:
                raise AuthError("last_method")
            self.db.execute("DELETE FROM public.customer_identities WHERE id=%s", [identity["id"]])
        method = alternative if (browser.method or "").startswith("google:") else browser.method
        return self._preserved(customer, browser, method)
