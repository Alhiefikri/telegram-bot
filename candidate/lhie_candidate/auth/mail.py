"""Existing auth-mail ledger, attempt-fenced leases, and a disposable SMTP sink."""
from datetime import timedelta
from email.message import EmailMessage
import hmac
import re
import smtplib

from django.db import connections, transaction
from django.template.loader import render_to_string

from ..checkout.db import Database
from ..compat.identity import email_otp_digest, sha256_digest
from ..notifications.mail import DefiniteMailFailure
from ..notifications.policy import TestRecipients
from .types import AuthError, legacy_now


class LocalMailSink:
    """The P01 discard-only sink. No user-selected host or logging transport."""
    def send(self, email, purpose, capability):
        TestRecipients().mail(email)
        message = EmailMessage()
        message["From"] = "LhieStore <test@example.invalid>"
        message["To"] = email
        copy = auth_copy(purpose, capability)
        message["Subject"] = copy["subject"]
        message.set_content("\n\n".join(filter(None, (copy["greeting"], *copy["lines"], copy["url"], copy["expiry"], copy["ignore"]))))
        message.add_alternative(render_to_string("notifications/auth.html", copy), subtype="html")
        with smtplib.SMTP("127.0.0.1", 1025, timeout=5) as smtp:
            if smtp.send_message(message):
                raise DefiniteMailFailure()


def auth_copy(purpose, capability):
    """PHP notification copy; capabilities remain transient and fragment-only."""
    result = {"url": None, "action": None, "expiry": None}
    if purpose == "email_otp":
        result.update(subject="Kode login LhieStore", greeting="Login ke LhieStore",
                      lines=["Masukkan kode berikut pada halaman akun di browser tempat Anda meminta kode:", capability,
                             "Kode ini berlaku selama 10 menit dan hanya dapat digunakan sekali."],
                      ignore="Jika Anda tidak meminta kode ini, abaikan email ini.")
        return result
    route, subject, greeting, action, description, expiry, ignore = {
        "email_verification": ("verifikasi-email", "Verifikasi email", "Verifikasi email", "Verifikasi email saya",
            "Konfirmasikan alamat email Anda untuk menyelesaikan pendaftaran akun pelanggan.",
            "Link ini berlaku selama 30 menit dan hanya dapat digunakan sekali.", "Jika Anda tidak meminta email ini, abaikan pesan ini."),
        "password_reset": ("password-reset/confirm", "Atur ulang password", "Atur ulang password", "Atur ulang password saya",
            "Gunakan tombol berikut untuk memilih password baru akun pelanggan Anda.",
            "Link ini berlaku selama 30 menit dan hanya dapat digunakan sekali.", "Jika Anda tidak meminta email ini, abaikan pesan ini."),
        "magic": ("login-link", "Link login", "Login ke LhieStore", "Masuk ke akun",
            "Gunakan tombol di bawah untuk masuk ke akun pelanggan Anda. Link berlaku selama 15 menit dan hanya dapat digunakan sekali.",
            None, "Jika Anda tidak meminta link ini, abaikan email ini."),
    }[purpose]
    result.update(subject=subject + " LhieStore", greeting=greeting, action=action, lines=[description],
                  expiry=expiry, ignore=ignore, url="https://localhost/akun/" + route + "#token=" + capability)
    return result


class MailWork:
    def __init__(self, encrypter, *, app_key, db=None, clock=legacy_now, transport=None, wakeup=None):
        self.encrypter, self.app_key = encrypter, app_key
        self.db, self.clock = db or Database(), clock
        self.transport = transport if transport is not None else LocalMailSink()
        self.wakeup = wakeup

    def persist(self, grant_id, email, capability, expires_at, *, legacy=False):
        self.db.require_transaction()
        at = self.clock()
        payload = self.encrypter.encrypt_json({"email": email, "token": capability})
        work = self.db.one("""INSERT INTO public.customer_auth_mail_works
            (auth_token_id,login_link_id,payload,status,attempts,expires_at,next_attempt_at,created_at,updated_at)
            VALUES (%s,%s,%s,'pending',0,%s,%s,%s,%s) RETURNING id""",
            (None if legacy else grant_id, grant_id if legacy else None, payload, expires_at, at, at, at))
        if self.wakeup is not None:
            work_id = work["id"]
            transaction.on_commit(lambda: self.wakeup(work_id), using=self.db.using, robust=True)
        return work["id"]

    def _lock(self, work_id):
        binding = self.db.one("SELECT auth_token_id,login_link_id FROM public.customer_auth_mail_works WHERE id=%s", (work_id,))
        if not binding or (binding["auth_token_id"] is None) == (binding["login_link_id"] is None):
            return None, None, None
        if binding["auth_token_id"] is not None:
            candidate = self.db.one("SELECT customer_id FROM public.customer_auth_tokens WHERE id=%s", (binding["auth_token_id"],))
            customer = self.db.one("SELECT id,email,email_verified_at,password,auth_version,enrollment_version FROM public.customers WHERE id=%s FOR UPDATE", (candidate["customer_id"],)) if candidate else None
            grant = self.db.one("SELECT * FROM public.customer_auth_tokens WHERE id=%s FOR UPDATE", (binding["auth_token_id"],))
        else:
            candidate = self.db.one("SELECT email FROM public.customer_login_links WHERE id=%s", (binding["login_link_id"],))
            customer = self.db.one("SELECT id FROM public.customers WHERE email=%s FOR UPDATE", (candidate["email"],)) if candidate else None
            grant = self.db.one("SELECT *, 'magic' AS purpose FROM public.customer_login_links WHERE id=%s FOR UPDATE", (binding["login_link_id"],))
        work = self.db.one("SELECT * FROM public.customer_auth_mail_works WHERE id=%s FOR UPDATE", (work_id,))
        return customer, grant, work

    def _eligible(self, customer, grant):
        if not grant or grant["expires_at"] <= self.clock():
            return False
        if grant["purpose"] == "magic":
            return grant["used_at"] is None
        if (not customer or grant["purpose"] not in ("email_verification", "email_otp", "password_reset")
                or grant["consumed_at"] is not None or grant["attempts"] >= 5
                or grant["email"] != customer["email"] or grant["auth_version"] != customer["auth_version"]
                or grant["enrollment_version"] != customer["enrollment_version"]):
            return False
        if grant["purpose"] == "email_verification":
            return customer["email_verified_at"] is None and customer["password"] is None
        if grant["purpose"] == "email_otp":
            return (customer["email_verified_at"] is not None
                    and isinstance(grant.get("session_binding_hash"), str)
                    and re.fullmatch(r"[0-9a-f]{64}", grant["session_binding_hash"]) is not None)
        return customer["email_verified_at"] is not None

    def _terminal(self, work_id, status, error=None):
        self.db.execute("""UPDATE public.customer_auth_mail_works SET status=%s,payload=NULL,
            last_error_code=%s,updated_at=%s WHERE id=%s""", (status, error, self.clock(), work_id))

    def _claim(self, work_id):
        with transaction.atomic(using=self.db.using, durable=True):
            customer, grant, work = self._lock(work_id)
            if not work or work["status"] != "pending":
                return None
            at = self.clock()
            # next_attempt_at is both the original PHP lease and retry deadline.
            # An attempted row without our definite non-acceptance marker is
            # uncertain (including pre-migration failures). Never reclaim it.
            if work["attempts"] > 0 and work["last_error_code"] != "mail_definite_failure":
                if work["next_attempt_at"] <= at:
                    self._terminal(work_id, "failed", "mail_delivery_unknown")
                return None
            if work["expires_at"] <= at or not self._eligible(customer, grant):
                self._terminal(work_id, "cancelled")
                return None
            if work["next_attempt_at"] > at:
                return None
            if work["attempts"] >= 5:
                self._terminal(work_id, "failed", "attempt_limit")
                return None
            work.update(attempts=work["attempts"] + 1, next_attempt_at=at + timedelta(seconds=120))
            self.db.execute("""UPDATE public.customer_auth_mail_works SET attempts=%s,next_attempt_at=%s,
                last_error_code='mail_delivery_inflight',updated_at=%s WHERE id=%s""",
                (work["attempts"], work["next_attempt_at"], at, work_id))
            return work, grant

    def _payload(self, work, grant):
        payload = self.encrypter.decrypt_json(work["payload"])
        if (type(payload) is not dict or set(payload) != {"email", "token"}
                or payload["email"] != grant["email"] or not isinstance(payload["token"], str)):
            raise ValueError("Invalid auth mail")
        capability = payload["token"]
        if grant["purpose"] == "email_otp":
            valid = re.fullmatch(r"[0-9]{6}", capability) and hmac.compare_digest(
                grant["token_hash"], email_otp_digest(grant["email"], grant["challenge_hash"], capability, self.app_key))
        else:
            valid = re.fullmatch(r"[0-9a-f]{64}", capability) and hmac.compare_digest(grant["token_hash"], sha256_digest(capability))
        if not valid:
            raise ValueError("Invalid auth mail")
        return payload

    def _still_eligible(self, claim):
        with transaction.atomic(using=self.db.using, durable=True):
            customer, grant, work = self._lock(claim["id"])
            if (not work or work["status"] != "pending" or work["attempts"] != claim["attempts"]
                    or work["next_attempt_at"] != claim["next_attempt_at"]):
                return False
            if work["expires_at"] <= self.clock() or not self._eligible(customer, grant):
                self._terminal(work["id"], "cancelled")
                return False
            return work["next_attempt_at"] > self.clock()

    def _finish(self, claim, status, *, error=None, retry_at=None):
        return self.db.execute("""UPDATE public.customer_auth_mail_works SET status=%s,
            payload=CASE WHEN %s='pending' THEN payload ELSE NULL END,last_error_code=%s,
            next_attempt_at=COALESCE(%s,next_attempt_at),sent_at=CASE WHEN %s='sent' THEN %s ELSE sent_at END,
            updated_at=%s WHERE id=%s AND status='pending' AND attempts=%s AND next_attempt_at=%s
            AND next_attempt_at>%s""",
            (status, status, error, retry_at, status, self.clock(), self.clock(), claim["id"], claim["attempts"], claim["next_attempt_at"], self.clock()))

    def deliver(self, work_id):
        self._outside()
        claim = self._claim(work_id)
        if claim is None:
            return False
        work, grant = claim
        try:
            payload = self._payload(work, grant)
            TestRecipients().mail(payload["email"])
        except Exception:
            self._finish(work, "failed", error="invalid_payload")
            return False
        if not self._still_eligible(work):
            return False
        try:
            self.transport.send(payload["email"], grant["purpose"], payload["token"])
        except DefiniteMailFailure:
            terminal = work["attempts"] >= 5
            delay = 60 if work["attempts"] == 1 else 120 if work["attempts"] == 2 else 300
            self._finish(work, "failed" if terminal else "pending", error="mail_definite_failure",
                          retry_at=self.clock() + timedelta(seconds=delay))
            raise AuthError("mail_unavailable", 503) from None
        except Exception:
            self._finish(work, "failed", error="mail_delivery_unknown")
            raise AuthError("mail_unavailable", 503) from None
        return bool(self._finish(work, "sent"))

    def _outside(self):
        connection = connections[self.db.using]
        if connection.in_atomic_block or not connection.get_autocommit():
            raise RuntimeError("Auth mail delivery requires an outermost boundary")

    def recover(self, limit=100):
        self._outside()
        if type(limit) is not int or not 1 <= limit <= 1000:
            raise ValueError("Invalid auth mail batch")
        ids = self.db.rows("""SELECT w.id FROM public.customer_auth_mail_works w
            LEFT JOIN public.customer_auth_tokens t ON t.id=w.auth_token_id
            LEFT JOIN public.customer_login_links l ON l.id=w.login_link_id
            WHERE w.status='pending' AND (w.next_attempt_at<=%s OR w.expires_at<=%s
            OR t.consumed_at IS NOT NULL OR l.used_at IS NOT NULL) ORDER BY w.id LIMIT %s""", (self.clock(), self.clock(), limit))
        sent = 0
        for row in ids:
            try:
                sent += self.deliver(row["id"])
            except AuthError:
                pass
        return sent
