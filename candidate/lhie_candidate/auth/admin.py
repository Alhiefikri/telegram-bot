"""The admin guard binds public.users only; Django User is not an authority."""
from django.conf import settings

from ..checkout.db import Database
from ..compat.identity import PHP_TRIM, sha256_digest, verify_legacy_password
from .service import hash_algorithm
from .types import AuthError


def allowed(user):
    if user is None or user.get("role") not in ("owner", "admin"):
        return False
    logins = getattr(settings, "CANDIDATE_ADMIN_ALLOWED_LOGINS", ())
    if isinstance(logins, str):
        logins = logins.split(",")
    logins = {value.strip().lower() for value in logins if value.strip()}
    return not logins or user["email"].lower() in logins or user["name"].lower() in logins


class AdminAuthentication:
    def __init__(self, *, limits, db=None):
        self.limits, self.db = limits, db or Database()

    def login(self, request, login, password):
        login = login.strip(PHP_TRIM)
        ip = request.META.get("REMOTE_ADDR") or "unknown"
        reservation = self.limits.reserve([("admin-login-pair", login.lower() + "\0" + ip, 5, 60),
                                           ("admin-login-ip", ip, 10, 60)])
        user = self.db.one("SELECT id,email,name,password,role FROM public.users WHERE email=%s OR name=%s ORDER BY id LIMIT 1",
                           (login, login))
        stored = user["password"] if user else ""
        algorithm = hash_algorithm(stored)
        if not allowed(user) or not algorithm or not verify_legacy_password(password, stored, algorithm=algorithm):
            raise AuthError()
        request.auth_session.set("admin", {"id": user["id"], "password_digest": sha256_digest(stored)})
        request.auth_session.rotate(request)
        self.limits.release(reservation, clear_identity=True)
        return {"id": user["id"], "role": user["role"]}

    def current(self, request):
        marker = request.auth_session.data.get("admin")
        user = None
        if isinstance(marker, dict) and type(marker.get("id")) is int:
            user = self.db.one("SELECT id,email,name,password,role FROM public.users WHERE id=%s", (marker["id"],))
        if not allowed(user) or marker.get("password_digest") != sha256_digest(user["password"]):
            request.auth_session.discard("admin")
            raise AuthError("authentication_required", 401)
        return {"id": user["id"], "role": user["role"]}

    def logout(self, request):
        request.auth_session.discard("admin")
        request.auth_session.rotate(request)
