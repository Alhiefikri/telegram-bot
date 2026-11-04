"""Customer-only auth owner over the existing Laravel grants and Customer rows."""
from datetime import timedelta
import hmac
import re
import secrets

import bcrypt
from django.db import IntegrityError, transaction

from ..checkout.db import Database
from ..compat.identity import (customer_password_input, email_otp_digest, normalize_customer_email,
                               sha256_digest, verify_customer_password)
from .mail import MailWork
from .types import AuthError, legacy_now, principal, require_fresh

_CAPABILITY = re.compile(r"[0-9a-f]{64}\Z")
_CUSTOMER = "id,email,email_verified_at,password,password_scheme,auth_version,enrollment_version,telegram_id"


def password_hash(password, *, rounds=12):
    if (not isinstance(password, str) or not 8 <= len(password) <= 128
            or not re.search(r"[A-Z]", password) or not re.search(r"[0-9]", password)
            or not re.search(r"[^a-zA-Z0-9]", password)):
        raise AuthError("invalid_password", 422)
    return bcrypt.hashpw(customer_password_input(password), bcrypt.gensalt(rounds)).decode("ascii").replace("$2b$", "$2y$", 1)


def hash_algorithm(stored):
    if isinstance(stored, str):
        for prefix, algorithm in (("$2", "bcrypt"), ("$argon2id$", "argon2id"), ("$argon2i$", "argon2i")):
            if stored.startswith(prefix):
                return algorithm
    return "bcrypt"


class AuthService:
    def __init__(self, *, app_key, encrypter, limits, db=None, clock=legacy_now, mail=None):
        self.app_key, self.limits = app_key, limits
        self.db, self.clock = db or Database(), clock
        self.mail = mail if mail is not None else MailWork(encrypter, app_key=app_key, db=self.db, clock=clock)

    def enabled(self):
        row = self.db.one("SELECT enabled FROM public.customer_auth_settings WHERE singleton_key='customer_auth'")
        return row is not None and row["enabled"] is True

    def _available(self):
        if not self.app_key or not self.enabled():
            raise AuthError("unavailable", 503)

    def _email(self, value):
        email = normalize_customer_email(value)
        if len(email) > 255 or not re.fullmatch(r"[^\s@]+@[^\s@]+\.[^\s@]+", email):
            raise AuthError("invalid_email", 422)
        return email

    def _customer(self, customer_id):
        self.db.execute("SET LOCAL search_path = pg_catalog, public, pg_temp")
        return self.db.one(f"SELECT {_CUSTOMER} FROM public.customers WHERE id=%s FOR UPDATE", (customer_id,))

    def _email_customer(self, email):
        self.db.execute("SET LOCAL search_path = pg_catalog, public, pg_temp")
        return self.db.one(f"SELECT {_CUSTOMER} FROM public.customers WHERE email=%s FOR UPDATE", (email,))

    def _issue(self, customer, purpose, proposed=None, binding=None):
        at, capability = self.clock(), secrets.token_hex(32)
        expires = at + timedelta(minutes=30)
        row = self.db.one("""INSERT INTO public.customer_auth_tokens
            (customer_id,email,purpose,token_hash,session_binding_hash,proposed_password_hash,
             auth_version,enrollment_version,expires_at,created_at,updated_at)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s) RETURNING id""",
            (customer["id"], customer["email"], purpose, sha256_digest(capability), binding, proposed,
             customer["auth_version"], customer["enrollment_version"], expires, at, at))
        self.mail.persist(row["id"], customer["email"], capability, expires)

    def register(self, email, password, name, browser):
        self._available()
        email = self._email(email)
        self.limits.mail(email, browser.ip)
        proposed, at = password_hash(password), self.clock()
        try:
            with transaction.atomic(using=self.db.using, durable=True):
                if self._email_customer(email):
                    return
                customer = self.db.one("""INSERT INTO public.customers
                    (email,first_name,auth_version,enrollment_version,created_at,updated_at)
                    VALUES (%s,%s,0,1,%s,%s) RETURNING """ + _CUSTOMER,
                    (email, (name or "Pelanggan")[:255], at, at))
                self._issue(customer, "email_verification", proposed)
        except IntegrityError as error:
            if getattr(error.__cause__, "sqlstate", None) != "23505":
                raise
            # Competing registration owns the unique email. Never replace its profile/hash.

    def login(self, email, password, browser):
        self._available()
        email = self._email(email)
        reservation = self.limits.reserve([("login-pair", email + "\0" + browser.ip, 5, 900),
                                           ("login-ip", browser.ip, 30, 900)])
        with transaction.atomic(using=self.db.using, durable=True):
            customer = self._email_customer(email)
            if not customer or not customer["password"]:
                bcrypt.hashpw(customer_password_input(password), bcrypt.gensalt(12))
                raise AuthError()
            stored = customer["password"]
            if (not verify_customer_password(password, stored, customer["password_scheme"], algorithm=hash_algorithm(stored))
                    or customer["email_verified_at"] is None):
                raise AuthError()
            if customer["password_scheme"] == "raw":
                # Existing passwords are not subject to the new-enrollment policy.
                updated = bcrypt.hashpw(customer_password_input(password), bcrypt.gensalt(12)).decode().replace("$2b$", "$2y$", 1)
                self.db.execute("UPDATE public.customers SET password=%s,password_scheme='sha384-v1',updated_at=%s WHERE id=%s",
                                (updated, self.clock(), customer["id"]))
            result = principal(customer, "password", self.clock())
        self.limits.release(reservation)
        return result

    def _lock_challenge(self, capability, purpose, *, challenge=False):
        if not isinstance(capability, str) or not _CAPABILITY.fullmatch(capability):
            raise AuthError()
        column = "challenge_hash" if challenge else "token_hash"
        digest = sha256_digest(capability)
        candidate = self.db.one(f"SELECT customer_id FROM public.customer_auth_tokens WHERE {column}=%s AND purpose=%s", (digest, purpose))
        if not candidate or candidate["customer_id"] is None:
            raise AuthError()
        customer = self._customer(candidate["customer_id"])
        token = self.db.one(f"SELECT * FROM public.customer_auth_tokens WHERE {column}=%s AND purpose=%s FOR UPDATE", (digest, purpose))
        if (not customer or not token or token["consumed_at"] is not None or token["expires_at"] <= self.clock()
                or token["attempts"] >= (5 if purpose == "email_otp" else 1)
                or token["customer_id"] != customer["id"] or token["email"] != customer["email"]
                or token["auth_version"] != customer["auth_version"]
                or token["enrollment_version"] != customer["enrollment_version"]):
            raise AuthError()
        return customer, token

    def _invalidate(self, customer, *, providers=False):
        at, customer_id, email = self.clock(), customer["id"], customer["email"]
        self.db.execute("""UPDATE public.customer_auth_tokens SET consumed_at=%s,updated_at=%s
            WHERE (customer_id=%s OR email=%s) AND consumed_at IS NULL""", (at, at, customer_id, email))
        self.db.execute("UPDATE public.customer_login_links SET used_at=%s,updated_at=%s WHERE email=%s AND used_at IS NULL", (at, at, email))
        if providers:
            self.db.execute("""UPDATE public.customer_telegram_auth_claims SET failure='invalid',updated_at=%s
                WHERE (customer_id=%s OR proof_customer_id=%s) AND consumed_at IS NULL AND failure IS NULL""", (at, customer_id, customer_id))
            self.db.execute("""UPDATE public.customer_google_auth_claims SET consumed_at=%s,updated_at=%s
                WHERE customer_id=%s AND consumed_at IS NULL AND expires_at>%s""", (at, at, customer_id, at))
        self.db.execute("""UPDATE public.customer_auth_mail_works SET status='cancelled',payload=NULL,last_error_code=NULL,updated_at=%s
            WHERE status='pending' AND (auth_token_id IN (SELECT id FROM public.customer_auth_tokens WHERE customer_id=%s OR email=%s)
            OR login_link_id IN (SELECT id FROM public.customer_login_links WHERE email=%s))""", (at, customer_id, email, email))

    def verify(self, token, browser):
        self._available()
        with transaction.atomic(using=self.db.using, durable=True):
            customer, challenge = self._lock_challenge(token, "email_verification")
            if customer["email_verified_at"] is not None or customer["password"] is not None:
                raise AuthError()
            proposed = challenge["proposed_password_hash"]
            if proposed is None:
                if (not challenge["session_binding_hash"]
                        or not hmac.compare_digest(challenge["session_binding_hash"], sha256_digest(browser.session_id))
                        or not self.db.one("""SELECT id FROM public.customer_identities
                            WHERE customer_id=%s AND provider='telegram' AND provider_subject=%s""", (customer["id"], str(customer["telegram_id"])))):
                    raise AuthError()
            at = self.clock()
            self.db.execute("""UPDATE public.customers SET email_verified_at=%s,password=%s,
                password_scheme=CASE WHEN %s IS NULL THEN password_scheme ELSE 'sha384-v1' END,
                enrollment_version=enrollment_version+1,updated_at=%s WHERE id=%s""", (at, proposed, proposed, at, customer["id"]))
            self._invalidate(customer)
            result = principal(customer, "verification", at)
        return result

    def request_reset(self, email, browser):
        self._available()
        email = self._email(email)
        self.limits.mail(email, browser.ip)
        with transaction.atomic(using=self.db.using, durable=True):
            customer = self._email_customer(email)
            if customer and customer["email_verified_at"] is not None:
                at = self.clock()
                self.db.execute("""UPDATE public.customer_auth_tokens SET consumed_at=%s,updated_at=%s
                    WHERE customer_id=%s AND purpose='password_reset' AND consumed_at IS NULL""", (at, at, customer["id"]))
                self._issue(customer, "password_reset")

    def reset(self, token, password, browser):
        self._available()
        proposed = password_hash(password)
        with transaction.atomic(using=self.db.using, durable=True):
            customer, _ = self._lock_challenge(token, "password_reset")
            if customer["email_verified_at"] is None:
                raise AuthError()
            self.db.execute("""UPDATE public.customers SET password=%s,password_scheme='sha384-v1',
                auth_version=auth_version+1,enrollment_version=enrollment_version+1,updated_at=%s WHERE id=%s""", (proposed, self.clock(), customer["id"]))
            self._invalidate(customer, providers=True)

    def set_password(self, password, browser):
        self._available()
        proposed = password_hash(password)
        with transaction.atomic(using=self.db.using, durable=True):
            customer = self._customer(browser.customer_id)
            if not customer:
                raise AuthError()
            require_fresh(browser, customer, self.clock())
            if customer["email_verified_at"] is None or not customer["email"] or customer["password"] is not None:
                raise AuthError()
            self.db.execute("""UPDATE public.customers SET password=%s,password_scheme='sha384-v1',auth_version=auth_version+1,
                enrollment_version=enrollment_version+1,updated_at=%s WHERE id=%s""", (proposed, self.clock(), customer["id"]))
            self._invalidate(customer, providers=True)
            result = principal({**customer, "auth_version": customer["auth_version"] + 1}, "password", self.clock())
        return result

    def enroll_email(self, email, browser):
        self._available()
        email = self._email(email)
        self.limits.mail(email, browser.ip)
        with transaction.atomic(using=self.db.using, durable=True):
            customer = self._customer(browser.customer_id)
            if not customer:
                raise AuthError()
            require_fresh(browser, customer, self.clock())
            if (browser.method != "telegram" or customer["email_verified_at"] is not None or customer["password"] is not None
                    or customer["email"] not in (None, email)
                    or self.db.one("SELECT id FROM public.customers WHERE email=%s AND id<>%s", (email, customer["id"]))
                    or not self.db.one("""SELECT id FROM public.customer_identities WHERE customer_id=%s
                        AND provider='telegram' AND provider_subject=%s""", (customer["id"], str(customer["telegram_id"])))):
                raise AuthError()
            self.db.execute("UPDATE public.customers SET email=%s,enrollment_version=enrollment_version+1,updated_at=%s WHERE id=%s", (email, self.clock(), customer["id"]))
            customer.update(email=email, enrollment_version=customer["enrollment_version"] + 1)
            self._issue(customer, "email_verification", binding=sha256_digest(browser.session_id))

    def request_otp(self, email, browser):
        self._available()
        email = self._email(email)
        self.limits.mail(email, browser.ip)
        challenge, at = secrets.token_hex(32), self.clock()
        with transaction.atomic(using=self.db.using, durable=True):
            customer = self._email_customer(email)
            if customer and customer["email_verified_at"] is not None:
                latest = self.db.one("SELECT created_at FROM public.customer_auth_tokens WHERE customer_id=%s AND purpose='email_otp' ORDER BY id DESC LIMIT 1", (customer["id"],))
                if latest and latest["created_at"] > at - timedelta(seconds=60):
                    raise AuthError("rate_limited", 429)
                self.db.execute("UPDATE public.customer_auth_tokens SET consumed_at=%s,updated_at=%s WHERE customer_id=%s AND purpose='email_otp' AND consumed_at IS NULL", (at, at, customer["id"]))
                code, digest, expires = f"{secrets.randbelow(1000000):06d}", sha256_digest(challenge), at + timedelta(minutes=10)
                row = self.db.one("""INSERT INTO public.customer_auth_tokens
                    (customer_id,email,purpose,token_hash,challenge_hash,session_binding_hash,auth_version,enrollment_version,expires_at,created_at,updated_at)
                    VALUES (%s,%s,'email_otp',%s,%s,%s,%s,%s,%s,%s,%s) RETURNING id""",
                    (customer["id"], email, email_otp_digest(email, digest, code, self.app_key), digest,
                     sha256_digest(browser.session_id), customer["auth_version"], customer["enrollment_version"], expires, at, at))
                self.mail.persist(row["id"], email, code, expires)
        return challenge

    def consume_otp(self, challenge, code, browser):
        self._available()
        self.limits.reserve([("otp-consume-ip", browser.ip, 30, 900)])
        result = None
        with transaction.atomic(using=self.db.using, durable=True):
            customer, token = self._lock_challenge(challenge, "email_otp", challenge=True)
            if (customer["email_verified_at"] is None or not token["session_binding_hash"]
                    or not hmac.compare_digest(token["session_binding_hash"], sha256_digest(browser.session_id))):
                raise AuthError()
            if (not isinstance(code, str) or not re.fullmatch(r"[0-9]{6}", code)
                    or not hmac.compare_digest(token["token_hash"], email_otp_digest(token["email"], token["challenge_hash"], code, self.app_key))):
                # Commit failed attempts before returning denial, including under concurrent consumption.
                self.db.execute("UPDATE public.customer_auth_tokens SET attempts=attempts+1,updated_at=%s WHERE id=%s", (self.clock(), token["id"]))
            else:
                self.db.execute("UPDATE public.customer_auth_tokens SET consumed_at=%s,updated_at=%s WHERE id=%s", (self.clock(), self.clock(), token["id"]))
                result = principal(customer, "otp", self.clock())
        if result is None:
            raise AuthError()
        return result

    def request_magic(self, email, browser):
        email = self._email(email)
        self.limits.mail(email, browser.ip)
        at, capability = self.clock(), secrets.token_hex(32)
        with transaction.atomic(using=self.db.using, durable=True):
            self._email_customer(email)
            row = self.db.one("""INSERT INTO public.customer_login_links
                (email,token_hash,expires_at,requested_ip,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s) RETURNING id""",
                (email, sha256_digest(capability), at + timedelta(minutes=15), browser.ip, at, at))
            self.mail.persist(row["id"], email, capability, at + timedelta(minutes=15), legacy=True)

    def consume_magic(self, token, browser):
        if not isinstance(token, str) or not _CAPABILITY.fullmatch(token):
            raise AuthError()
        with transaction.atomic(using=self.db.using, durable=True):
            candidate = self.db.one("SELECT * FROM public.customer_login_links WHERE token_hash=%s", (sha256_digest(token),))
            at = self.clock()
            if not candidate or candidate["used_at"] is not None or candidate["expires_at"] <= at:
                raise AuthError()
            self.db.execute("""INSERT INTO public.customers(email,first_name,created_at,updated_at) VALUES (%s,'Pelanggan',%s,%s)
                ON CONFLICT(email) DO NOTHING""", (candidate["email"], at, at))
            customer = self._email_customer(candidate["email"])
            link = self.db.one("SELECT * FROM public.customer_login_links WHERE id=%s FOR UPDATE", (candidate["id"],))
            if link["used_at"] is not None or link["expires_at"] <= self.clock():
                raise AuthError()
            self.db.execute("UPDATE public.customer_login_links SET used_at=%s,updated_at=%s WHERE id=%s", (at, at, link["id"]))
            self.db.execute("UPDATE public.customers SET email_verified_at=%s,enrollment_version=enrollment_version+1,updated_at=%s WHERE id=%s", (at, at, customer["id"]))
            self.db.execute("""UPDATE public.customer_auth_tokens SET consumed_at=%s,updated_at=%s
                WHERE customer_id=%s AND purpose='email_verification' AND consumed_at IS NULL""", (at, at, customer["id"]))
            result = principal(customer, "magic", at)
        return result

    def revoke(self, customer_id):
        with transaction.atomic(using=self.db.using, durable=True):
            customer = self._customer(customer_id)
            if not customer:
                raise AuthError()
            self.db.execute("UPDATE public.customers SET auth_version=auth_version+1,enrollment_version=enrollment_version+1,updated_at=%s WHERE id=%s", (self.clock(), customer_id))
            self._invalidate(customer, providers=True)
