"""Admin browser guard over the existing P19 admin session authority."""

from functools import wraps

from django.http import HttpResponseRedirect

from ..auth.admin import AdminAuthentication
from ..auth.limits import RateLimits
from ..auth.types import AuthError
from ..checkout.db import Database
from ..runtime import application_key
from .errors import AdminForbidden


def _service():
    return AdminAuthentication(limits=RateLimits(application_key()), db=Database())


def wants_html(request):
    return "text/html" in request.headers.get("Accept", "")


def safe_next(value):
    if (not isinstance(value, str) or len(value) > 2048
            or not value.startswith("/") or value.startswith("//")):
        return "/admin"
    if "\\" in value or any(ord(char) <= 32 or ord(char) == 127 for char in value):
        return "/admin"
    if not (value == "/admin" or value.startswith("/admin?") or value.startswith("/admin/")):
        return "/admin"
    return value


def guard(view):
    """Require an owner/admin session for every HTML admin surface."""
    @wraps(view)
    def wrapped(request, *args, **kwargs):
        try:
            actor = _service().current(request)
        except AuthError as error:
            if wants_html(request):
                return HttpResponseRedirect(
                    "/admin/login?next=" + safe_next(request.get_full_path())
                )
            return _json_error(error)
        if actor.get("role") not in ("owner", "admin"):
            raise AdminForbidden()
        request.admin_user = actor
        return view(request, *args, **kwargs)
    return wrapped


def _json_error(error):
    from django.http import JsonResponse
    return JsonResponse({"status": error.code}, status=error.status)


def identity(request):
    actor = request.admin_user
    row = Database().one("SELECT id,name,email,role FROM public.users WHERE id=%s",
                         (actor["id"],))
    if row is None or row["role"] not in ("owner", "admin"):
        raise AdminForbidden()
    return {"id": int(row["id"]), "name": row["name"], "email": row["email"],
            "role": row["role"]}


def login(request, email, password):
    return _service().login(request, email, password)


def logout(request):
    _service().logout(request)
