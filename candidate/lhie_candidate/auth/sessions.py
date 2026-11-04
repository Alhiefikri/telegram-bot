"""Independent guard metadata; legacy PHP session payloads are never loaded."""
import json
import re
import secrets
from dataclasses import asdict
from datetime import timedelta

from django.conf import settings
from django.db import transaction
from django.http import JsonResponse
from django.middleware.csrf import CsrfViewMiddleware, rotate_token
from django.utils import timezone

from ..checkout.db import Database
from ..compat.identity import sha256_digest
from .types import AuthError, Browser

_COOKIE = "candidate_browser"
_SESSION_ID = re.compile(r"[a-f0-9]{64}\Z")
_AUTH_PATHS = ("/akun", "/auth/google", "/admin", "/akses-pesanan", "/shop/orders/")


def handles(path):
    return any(path == prefix or path.startswith(prefix.rstrip("/") + "/") for prefix in _AUTH_PATHS)


class Session:
    def __init__(self, token=None, *, db=None, clock=timezone.now):
        self.db, self.clock = db or Database(), clock
        self.token = token if isinstance(token, str) and _SESSION_ID.fullmatch(token) else None
        self.data = {}
        self.exists = False
        self.dirty = False
        self.rotated_from = None
        if self.token:
            row = self.db.one("SELECT metadata FROM django_candidate.browser_sessions "
                              "WHERE session_digest=%s AND expires_at>%s", (sha256_digest(self.token), self.clock()))
            if row:
                metadata = row["metadata"]
                self.data = json.loads(metadata) if isinstance(metadata, str) else metadata
                if type(self.data) is not dict:
                    raise AuthError("invalid_session", 403)
                self.exists = True
        if not self.exists:
            self.token = secrets.token_hex(32)
            self.dirty = True

    def get(self, key, default=None):
        return self.data.get(key, default)

    def __setitem__(self, key, value):
        self.set(key, value)

    def __getitem__(self, key):
        return self.data[key]

    def set(self, key, value):
        self.data[key] = value
        self.dirty = True

    def discard(self, key):
        if key in self.data:
            del self.data[key]
            self.dirty = True

    def rotate(self, request):
        if self.exists and self.rotated_from is None:
            self.rotated_from = sha256_digest(self.token)
        self.token = secrets.token_hex(32)
        self.exists = False
        self.dirty = True
        rotate_token(request)

    def authenticate(self, request, principal):
        self.set("customer", asdict(principal))
        for key in ("google", "telegram", "otp"):
            self.discard(key)
        self.rotate(request)

    def logout_customer(self, request):
        for key in ("customer", "google", "telegram", "otp"):
            self.discard(key)
        self.rotate(request)

    def browser(self, request):
        customer = self.data.get("customer") or {}
        return Browser(self.token, request.META.get("REMOTE_ADDR") or "unknown",
                       customer.get("customer_id"), customer.get("auth_version"),
                       customer.get("authenticated_at"), customer.get("method"))

    def save(self):
        if not self.dirty:
            return
        payload = json.dumps(self.data, separators=(",", ":"), allow_nan=False)
        if len(payload.encode()) > 16384:
            raise AuthError("unavailable", 503)
        expires = self.clock() + timedelta(hours=2)
        with transaction.atomic(using=self.db.using):
            if self.rotated_from:
                deleted = self.db.execute("DELETE FROM django_candidate.browser_sessions WHERE session_digest=%s",
                                          (self.rotated_from,))
                if not deleted:
                    raise AuthError("invalid_session", 403)
            if self.exists:
                changed = self.db.execute("UPDATE django_candidate.browser_sessions SET metadata=%s::jsonb "
                                          "WHERE session_digest=%s AND expires_at>%s",
                                          (payload, sha256_digest(self.token), self.clock()))
                if not changed:
                    raise AuthError("invalid_session", 403)
            else:
                self.db.execute("INSERT INTO django_candidate.browser_sessions(session_digest,metadata,expires_at) "
                                "VALUES(%s,%s::jsonb,%s)", (sha256_digest(self.token), payload, expires))
        self.exists, self.dirty, self.rotated_from = True, False, None


def customer(request, *, required=True, db=None):
    session = request.auth_session
    marker = session.data.get("customer")
    row = None
    db = db or Database()
    if isinstance(marker, dict) and type(marker.get("customer_id")) is int:
        row = db.one("SELECT id,email,email_verified_at,telegram_id,auth_version FROM public.customers WHERE id=%s",
                     (marker["customer_id"],))
        valid = row is not None and type(marker.get("auth_version")) is int and marker["auth_version"] == row["auth_version"]
        if valid and row["email_verified_at"] is None:
            method = marker.get("method", "")
            if method == "telegram" and row["telegram_id"] is not None:
                valid = db.one("SELECT id FROM public.customer_identities WHERE customer_id=%s AND provider='telegram' "
                               "AND provider_subject=%s", (row["id"], str(row["telegram_id"]))) is not None
            elif isinstance(method, str) and re.fullmatch(r"google:[1-9][0-9]*", method):
                valid = db.one("SELECT id FROM public.customer_identities WHERE id=%s AND customer_id=%s "
                               "AND provider='google'", (int(method.split(":")[1]), row["id"])) is not None
            else:
                valid = False
        if not valid:
            row = None
            session.logout_customer(request)
    if row is None and required:
        raise AuthError("authentication_required", 401)
    return row


class SessionMiddleware:
    def __init__(self, get_response):
        self.get_response = get_response

    def __call__(self, request):
        if not handles(request.path):
            return self.get_response(request)
        try:
            request.auth_session = Session(request.COOKIES.get(_COOKIE))
            request.session = request.auth_session
            # Optional validation also fences public auth starts and clears stale markers.
            customer(request, required=False)
            result = self.get_response(request)
            if result.status_code < 500:
                changed = request.auth_session.dirty
                request.auth_session.save()
                if changed:
                    result.set_cookie(_COOKIE, request.auth_session.token, max_age=7200,
                                      secure=True, httponly=True, samesite="Lax", path="/")
        except AuthError as error:
            result = JsonResponse({"status": error.code}, status=error.status)
        except Exception:
            result = JsonResponse({"status": "unavailable"}, status=503)
        result["Cache-Control"] = "no-store, private"
        result.setdefault("Referrer-Policy", "no-referrer")
        return result


class CsrfMiddleware(CsrfViewMiddleware):
    """Browser CSRF only; Bot/provider callbacks retain their own admission."""

    def process_request(self, request):
        if handles(request.path):
            return super().process_request(request)

    def process_view(self, request, callback, callback_args, callback_kwargs):
        if handles(request.path):
            return super().process_view(request, callback, callback_args, callback_kwargs)

    def process_response(self, request, response):
        if handles(request.path):
            return super().process_response(request, response)
        return response
