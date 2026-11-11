"""Existing auth-mail ledger, attempt-fenced leases, and a disposable SMTP sink."""
from datetime import timedelta
from email.message import EmailMessage
import html
import hmac
import re
import smtplib

from django.db import transaction

from ..checkout.db import Database
from ..compat.identity import email_otp_digest, sha256_digest
from ..branding import APP_NAME
from .types import AuthError, legacy_now
class DefiniteMailFailure(Exception):
    """The SMTP peer explicitly rejected delivery before accepting message data."""


class AmbiguousMailOutcome(Exception):
    """The transport failed after acceptance could no longer be ruled out."""


def _definite_mail_failure(error):
    return isinstance(error, (DefiniteMailFailure, ConnectionRefusedError, smtplib.SMTPConnectError,
                              smtplib.SMTPRecipientsRefused, smtplib.SMTPSenderRefused,
                              smtplib.SMTPDataError, smtplib.SMTPHeloError,
                              smtplib.SMTPNotSupportedError, smtplib.SMTPAuthenticationError))


 


class LocalMailSink:
    """Fixed loopback SMTP sink; no user-selected host or logging transport."""
    def __init__(self, base_url=None, app_name=APP_NAME):
        if base_url is None:
            from django.conf import settings
            base_url = getattr(settings, "CANDIDATE_LEGACY_APP_URL", "")
        self.base_url = base_url.rstrip("/")
        self.app_name = app_name

    def send(self, email, purpose, capability):
        if "\r" in email or "\n" in email:
            raise AuthError("invalid_mail")
        message = EmailMessage()
        message["From"] = "LhieStore <test@example.invalid>"
        message["To"] = email
        if purpose == "email_otp":
            subject = "Kode login " + self.app_name
            greeting = "Login ke " + self.app_name
            before_action = [
                "Masukkan kode berikut pada halaman akun di browser tempat Anda meminta kode:",
                capability,
                "Kode ini berlaku selama 10 menit dan hanya dapat digunakan sekali.",
                "Jika Anda tidak meminta kode ini, abaikan email ini.",
            ]
            after_action = []
            action = None
        elif purpose in ("email_verification", "password_reset", "magic"):
            route, subject, action, description, expiry = {
                "email_verification": (
                    "/akun/verifikasi-email", "Verifikasi email", "Verifikasi email saya",
                    "Konfirmasikan alamat email Anda untuk menyelesaikan pendaftaran akun pelanggan.",
                    "Link ini berlaku selama 30 menit dan hanya dapat digunakan sekali."),
                "password_reset": (
                    "/akun/password-reset/confirm", "Atur ulang password", "Atur ulang password saya",
                    "Gunakan tombol berikut untuk memilih password baru akun pelanggan Anda.",
                    "Link ini berlaku selama 30 menit dan hanya dapat digunakan sekali."),
                "magic": (
                    "/akun/login-link", "Link login", "Masuk ke akun",
                    "Gunakan tombol di bawah untuk masuk ke akun pelanggan Anda. Link berlaku selama 15 menit dan hanya dapat digunakan sekali.",
                    None),
            }[purpose]
            subject += " " + self.app_name
            greeting = "Login ke " + self.app_name if purpose == "magic" else subject.removesuffix(" " + self.app_name)
            before_action = [description]
            after_action = (["Jika Anda tidak meminta link ini, abaikan email ini."]
                            if purpose == "magic"
                            else [expiry, "Jika Anda tidak meminta email ini, abaikan pesan ini."])
            action = (action, self.base_url + route + "#token=" + capability)
        else:
            raise AuthError("invalid_mail")

        message["Subject"] = subject
        text = [greeting, *before_action]
        if action is not None:
            text.append(action[0] + ": " + action[1])
        text.extend(after_action)
        message.set_content("\n\n".join(text))
        html_parts = ["<h1>" + html.escape(greeting) + "</h1>"]
        html_parts.extend("<p>" + html.escape(line) + "</p>" for line in before_action)
        if action is not None:
            label, url = action
            html_parts.append('<p><a href="' + html.escape(url, quote=True) + '">' + html.escape(label) + "</a></p>")
        html_parts.extend("<p>" + html.escape(line) + "</p>" for line in after_action)
        body_html = "<!doctype html><html><body>" + "".join(html_parts) + "</body></html>"
        message.add_alternative(body_html, subtype="html")
        try:
            with smtplib.SMTP("127.0.0.1", 1025, timeout=5) as smtp:
                refused = smtp.send_message(message)
        except Exception as error:
            if _definite_mail_failure(error):
                raise DefiniteMailFailure() from None
            raise AmbiguousMailOutcome() from None
        if refused:
            raise DefiniteMailFailure()


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
                    and isinstance(grant["session_binding_hash"], str)
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
            if work["expires_at"] <= at or not self._eligible(customer, grant):
                self._terminal(work_id, "cancelled")
                return None
            if work["next_attempt_at"] > at:
                return None
            if work["attempts"] >= 5:
                self._terminal(work_id, "failed", "attempt_limit")
                return None
            work.update(attempts=work["attempts"] + 1, next_attempt_at=at + timedelta(seconds=120))
            self.db.execute("""UPDATE public.customer_auth_mail_works SET status='sending',attempts=%s,next_attempt_at=%s,
                updated_at=%s WHERE id=%s""", (work["attempts"], work["next_attempt_at"], at, work_id))
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
            if (not work or work["status"] != "sending" or work["attempts"] != claim["attempts"]
                    or work["next_attempt_at"] != claim["next_attempt_at"]):
                return False
            if work["expires_at"] <= self.clock() or not self._eligible(customer, grant):
                self._terminal(work["id"], "cancelled")
                return False
            return work["next_attempt_at"] > self.clock()

    def _finish(self, claim, status, *, error=None, retry_at=None):
        self.db.execute("""UPDATE public.customer_auth_mail_works SET status=%s,
            payload=CASE WHEN %s IN ('pending','unknown') THEN payload ELSE NULL END,last_error_code=%s,
            next_attempt_at=COALESCE(%s,next_attempt_at),sent_at=CASE WHEN %s='sent' THEN %s ELSE sent_at END,
            updated_at=%s WHERE id=%s AND status='sending' AND attempts=%s AND next_attempt_at=%s""",
            (status, status, error, retry_at, status, self.clock(), self.clock(), claim["id"], claim["attempts"], claim["next_attempt_at"]))

    def deliver(self, work_id):
        claim = self._claim(work_id)
        if claim is None:
            return False
        work, grant = claim
        try:
            payload = self._payload(work, grant)
        except Exception:
            self._finish(work, "failed", error="invalid_payload")
            return False
        if not self._still_eligible(work):
            return False
        try:
            self.transport.send(payload["email"], grant["purpose"], payload["token"])
        except Exception as error:
            if _definite_mail_failure(error):
                terminal = work["attempts"] >= 5
                delay = 60 if work["attempts"] == 1 else 120 if work["attempts"] == 2 else 300
                self._finish(work, "failed" if terminal else "pending", error="mail_delivery_failed",
                             retry_at=self.clock() + timedelta(seconds=delay))
                raise AuthError("mail_unavailable", 503) from None
            self._finish(work, "unknown", error="mail_delivery_unknown")
            raise AuthError("mail_unavailable", 503) from None
        self._finish(work, "sent")
        return True

    def recover(self, limit=100):
        if type(limit) is not int or not 1 <= limit <= 1000:
            raise ValueError("Invalid auth mail batch")
        # A process may die after SMTP accepted DATA. Any claimed row left
        # sending is therefore ambiguous, and must be held for manual review.
        self.db.execute("""UPDATE public.customer_auth_mail_works SET status='unknown',
            last_error_code='mail_delivery_unknown',updated_at=%s
            WHERE status='sending' AND next_attempt_at<=%s""", (self.clock(), self.clock()))
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

