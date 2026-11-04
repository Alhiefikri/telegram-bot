"""Private-actor confirmation and separate browser consumption; no Telegram unlink."""
from datetime import datetime, timedelta
import hmac
import re
import secrets

from django.db import IntegrityError, connections, transaction

from ..checkout.db import Database
from ..compat.identity import browser_nonce_binding, sha256_digest
from .bot_identity import TelegramBotIdentity
from .types import AuthError, LEGACY_ZONE, legacy_now, principal, require_fresh

_NONCE = re.compile(r"[A-Za-z0-9_-]{43}", re.ASCII)


def canonical_actor(value):
    return (isinstance(value, str) and re.fullmatch(r"[1-9][0-9]{0,18}", value) is not None
            and (len(value) < 19 or value <= "9223372036854775807"))


def private_actor(actor_id, chat_id, chat_type):
    return chat_type == "private" and actor_id == chat_id and canonical_actor(actor_id)


class TelegramAuthentication:
    def __init__(self, *, app_key, limits, db=None, clock=legacy_now, identity=None):
        self.app_key, self.limits = app_key, limits
        self.db, self.clock = db or Database(), clock
        self.identity = identity if identity is not None else TelegramBotIdentity()

    def username(self):
        try:
            value = self.identity.username()
            return value if isinstance(value, str) and re.fullmatch(r"[A-Za-z][A-Za-z0-9_]{4,31}", value) else None
        except Exception:
            return None

    def ready(self):
        return bool(self.app_key) and self.username() is not None

    def _boundary(self):
        connection = connections[self.db.using]
        if connection.in_atomic_block or not connection.get_autocommit():
            raise AuthError("unavailable", 503)

    def _limit(self, purpose, identity, maximum):
        enabled = self.db.one("SELECT enabled FROM public.customer_auth_settings WHERE singleton_key='customer_auth'")
        if not self.ready() or not enabled or enabled["enabled"] is not True:
            raise AuthError("unavailable", 503)
        self.limits.reserve([(purpose, identity, maximum, 900)])

    def _customer(self, customer_id):
        self.db.execute("SET LOCAL search_path = pg_catalog, public, pg_temp")
        return self.db.one("SELECT id,auth_version,telegram_id FROM public.customers WHERE id=%s FOR UPDATE", [customer_id])

    def start(self, browser, link=False):
        self._boundary()
        self._limit("telegram-start", browser.session_id + "\0" + browser.ip, 10)
        if type(link) is not bool or link != (browser.customer_id is not None):
            raise AuthError()
        nonce, capability, now = secrets.token_urlsafe(32), "wa_" + secrets.token_urlsafe(32), self.clock()
        with transaction.atomic(using=self.db.using):
            customer = self._customer(browser.customer_id) if link else None
            if link:
                if not customer:
                    raise AuthError()
                require_fresh(browser, customer, now)
            claim = self.db.one(
                "INSERT INTO public.customer_telegram_auth_claims "
                "(operation,customer_id,auth_version,authenticated_at,session_binding_hash,capability_hash,expires_at,created_at,updated_at) "
                "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s) RETURNING id",
                ["link" if link else "login", customer["id"] if customer else None,
                 customer["auth_version"] if customer else None,
                 datetime.fromtimestamp(browser.authenticated_at, LEGACY_ZONE).replace(tzinfo=None) if customer else None,
                 browser_nonce_binding(browser.session_id, nonce), sha256_digest(capability),
                 now + timedelta(seconds=300), now, now])
        return {"deep_link": "https://t.me/" + self.username() + "?start=" + capability,
                "expires_in": 300, "claim_id": claim["id"], "nonce": nonce}

    def _browser_claim(self, browser, claim_id, nonce, *, lock=False):
        if type(claim_id) is not int or claim_id <= 0 or not isinstance(nonce, str) or _NONCE.fullmatch(nonce) is None:
            return None
        claim = self.db.one("SELECT * FROM public.customer_telegram_auth_claims WHERE id=%s" + (" FOR UPDATE" if lock else ""), [claim_id])
        if not claim or not hmac.compare_digest(claim["session_binding_hash"], browser_nonce_binding(browser.session_id, nonce)):
            return None
        return claim

    def _claim_fresh(self, claim, now):
        at = claim["authenticated_at"]
        return at is not None and now - timedelta(seconds=300) < at <= now

    def _browser_matches(self, claim, browser, now, *, fresh=True):
        if claim["operation"] == "login":
            return browser.customer_id is None
        if browser.customer_id != claim["customer_id"] or browser.auth_version != claim["auth_version"]:
            return False
        if fresh:
            require_fresh(browser, {"id": claim["customer_id"], "auth_version": claim["auth_version"]}, now)
            return self._claim_fresh(claim, now)
        return True

    def _version_matches(self, claim):
        customer_id = claim["proof_customer_id"] if claim["proof_customer_id"] is not None else claim["customer_id"]
        version = claim["proof_auth_version"] if claim["proof_auth_version"] is not None else claim["auth_version"]
        return customer_id is None or self.db.one("SELECT id FROM public.customers WHERE id=%s AND auth_version=%s", [customer_id, version]) is not None

    def status(self, browser, claim_id, nonce):
        self._limit("telegram-status", browser.session_id + "\0" + browser.ip, 120)
        claim, now = self._browser_claim(browser, claim_id, nonce), self.clock()
        if not claim or not self._browser_matches(claim, browser, now, fresh=False):
            return "invalid"
        if claim["expires_at"] <= now:
            return "expired"
        if claim["failure"] is not None:
            return claim["failure"]
        if (claim["consumed_at"] is not None or not self._version_matches(claim)
                or not self._browser_matches(claim, browser, now)):
            return "invalid"
        return "confirmed" if claim["confirmed_at"] is not None else "pending"

    def _active(self, claim):
        return (claim is not None and claim["failure"] is None and claim["consumed_at"] is None
                and claim["expires_at"] > self.clock())

    def _fail(self, claim, failure):
        self.db.execute("UPDATE public.customer_telegram_auth_claims SET failure=%s,updated_at=%s WHERE id=%s", [failure, self.clock(), claim["id"]])

    def _collision(self, customer, actor_id, link):
        if (link and customer["telegram_id"] is not None) or (not link and str(customer["telegram_id"]) != actor_id):
            return True
        if self.db.one("SELECT id FROM public.customers WHERE telegram_id=%s AND id<>%s", [actor_id, customer["id"]]):
            return True
        identities = self.db.rows("SELECT * FROM public.customer_identities WHERE provider='telegram' "
                                  "AND (customer_id=%s OR provider_subject=%s)", [customer["id"], actor_id])
        return any(link or identity["customer_id"] != customer["id"] or identity["provider_subject"] != actor_id for identity in identities)

    def prepare(self, capability, actor_id, chat_id, chat_type):
        if not private_actor(actor_id, chat_id, chat_type) or not isinstance(capability, str) or re.fullmatch(r"wa_[A-Za-z0-9_-]{43}", capability) is None:
            return None
        try:
            self._boundary()
            self._limit("telegram-proof", actor_id, 30)
            snapshot = self.db.one("SELECT * FROM public.customer_telegram_auth_claims WHERE capability_hash=%s", [sha256_digest(capability)])
            if not snapshot:
                return None
            with transaction.atomic(using=self.db.using):
                target = (snapshot["customer_id"] if snapshot["operation"] == "link" else
                          (self.db.one("SELECT id FROM public.customers WHERE telegram_id=%s", [actor_id]) or {}).get("id"))
                customer = self._customer(target) if target else None
                claim = self.db.one("SELECT * FROM public.customer_telegram_auth_claims WHERE id=%s FOR UPDATE", [snapshot["id"]])
                if (not self._active(claim) or claim["confirmed_at"] is not None
                        or (claim["actor_id"] is not None and claim["actor_id"] != actor_id)):
                    return None
                if not customer or (claim["operation"] == "login" and str(customer["telegram_id"]) != actor_id):
                    self._fail(claim, "conflict")
                    return None
                if (claim["operation"] == "link" and (customer["auth_version"] != claim["auth_version"]
                                                       or not self._claim_fresh(claim, self.clock()))):
                    self._fail(claim, "invalid")
                    return None
                if claim["prepared_at"] is not None and (claim["proof_customer_id"] != customer["id"] or claim["proof_auth_version"] != customer["auth_version"]):
                    self._fail(claim, "invalid")
                    return None
                if self._collision(customer, actor_id, claim["operation"] == "link"):
                    self._fail(claim, "conflict")
                    return None
                confirmation, now = "wa_confirm:" + secrets.token_urlsafe(32), self.clock()
                self.db.execute("UPDATE public.customer_telegram_auth_claims "
                                "SET actor_id=%s,proof_customer_id=%s,proof_auth_version=%s,confirmation_hash=%s,prepared_at=%s,updated_at=%s WHERE id=%s",
                                [actor_id, customer["id"], customer["auth_version"], sha256_digest(confirmation), claim["prepared_at"] or now, now, claim["id"]])
            return confirmation
        except AuthError:
            return None

    def confirm(self, confirmation, actor_id, chat_id, chat_type):
        if not private_actor(actor_id, chat_id, chat_type) or not isinstance(confirmation, str) or re.fullmatch(r"wa_confirm:[A-Za-z0-9_-]{43}", confirmation) is None:
            return False
        try:
            self._boundary()
            self._limit("telegram-proof", actor_id, 30)
            digest = sha256_digest(confirmation)
            snapshot = self.db.one("SELECT * FROM public.customer_telegram_auth_claims WHERE confirmation_hash=%s", [digest])
            if not snapshot:
                return False
            with transaction.atomic(using=self.db.using):
                customer = self._customer(snapshot["proof_customer_id"])
                claim = self.db.one("SELECT * FROM public.customer_telegram_auth_claims WHERE id=%s FOR UPDATE", [snapshot["id"]])
                if (not self._active(claim) or claim["confirmation_hash"] != digest or claim["confirmed_at"] is not None or claim["actor_id"] != actor_id):
                    return False
                if (not customer or customer["auth_version"] != claim["proof_auth_version"]
                        or (claim["operation"] == "link" and not self._claim_fresh(claim, self.clock()))):
                    self._fail(claim, "invalid")
                    return False
                if self._collision(customer, actor_id, claim["operation"] == "link"):
                    self._fail(claim, "conflict")
                    return False
                now = self.clock()
                self.db.execute("UPDATE public.customer_telegram_auth_claims SET confirmed_at=%s,updated_at=%s WHERE id=%s", [now, now, claim["id"]])
            return True
        except AuthError:
            return False

    def consume(self, browser, claim_id, nonce):
        self._boundary()
        self._limit("telegram-consume", browser.session_id + "\0" + browser.ip, 30)
        snapshot = self._browser_claim(browser, claim_id, nonce)
        if not snapshot or snapshot["proof_customer_id"] is None:
            raise AuthError()
        failure = None
        with transaction.atomic(using=self.db.using):
            customer = self._customer(snapshot["proof_customer_id"])
            claim = self._browser_claim(browser, claim_id, nonce, lock=True)
            if (not self._active(claim) or claim["confirmed_at"] is None
                    or not self._browser_matches(claim, browser, self.clock())):
                raise AuthError()
            if (not customer or customer["auth_version"] != claim["proof_auth_version"]
                    or (claim["operation"] == "link" and customer["auth_version"] != claim["auth_version"])):
                failure = "invalid"
            elif self._collision(customer, claim["actor_id"], claim["operation"] == "link"):
                failure = "conflict"
            else:
                try:
                    with transaction.atomic(using=self.db.using):
                        now = self.clock()
                        if claim["operation"] == "link":
                            self.db.execute("UPDATE public.customers SET telegram_id=%s,updated_at=%s WHERE id=%s", [claim["actor_id"], now, customer["id"]])
                        identity = self.db.one("SELECT id FROM public.customer_identities WHERE provider='telegram' AND provider_subject=%s", [claim["actor_id"]])
                        if identity:
                            self.db.execute("UPDATE public.customer_identities SET last_used_at=%s,updated_at=%s WHERE id=%s", [now, now, identity["id"]])
                        else:
                            self.db.execute("INSERT INTO public.customer_identities "
                                            "(customer_id,provider,provider_subject,last_used_at,created_at,updated_at) "
                                            "VALUES (%s,'telegram',%s,%s,%s,%s)", [customer["id"], claim["actor_id"], now, now, now])
                except IntegrityError:
                    failure = "conflict"
            if failure:
                self._fail(claim, failure)
            else:
                now = self.clock()
                self.db.execute("UPDATE public.customer_telegram_auth_claims SET consumed_at=%s,updated_at=%s WHERE id=%s", [now, now, claim["id"]])
        if failure:
            raise AuthError(failure)
        return principal(customer, "telegram", self.clock())
