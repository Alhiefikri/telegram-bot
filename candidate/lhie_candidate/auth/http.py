"""Local HTTP adapters: CSRF-protected POST mutations and non-consuming GET forms."""
from functools import wraps
from types import SimpleNamespace

from django.http import HttpResponse, HttpResponseRedirect, JsonResponse
from django.middleware.csrf import get_token
from django.utils.html import format_html, format_html_join

from .. import runtime
from ..compat.crypto import LaravelEncrypter
from .admin import AdminAuthentication
from .limits import RateLimits
from .ownership import OrderAccess
from .sessions import customer
from .types import AuthError


def services():
    from .service import AuthService
    key = runtime.application_key()
    limits = RateLimits(key)
    return SimpleNamespace(core=AuthService(app_key=key, encrypter=LaravelEncrypter(key), limits=limits),
                           orders=OrderAccess(app_key=key, limits=limits),
                           admin=AdminAuthentication(limits=limits), key=key, limits=limits)


def google_service(bundle):
    from .google import GoogleAuthentication
    from .google_provider import GoogleOAuthProvider
    return GoogleAuthentication(app_key=bundle.key, limits=bundle.limits,
                                provider=GoogleOAuthProvider(app_key=bundle.key))


def telegram_service(bundle):
    from .telegram import TelegramAuthentication
    return TelegramAuthentication(app_key=bundle.key, limits=bundle.limits)


def json_result(status="ok", **values):
    return JsonResponse({"status": status, **values})


def endpoint(methods):
    def decorate(view):
        @wraps(view)
        def call(request, *args, **kwargs):
            if request.method not in methods and not (request.method == "HEAD" and "GET" in methods):
                response = JsonResponse({"status": "method_not_allowed"}, status=405)
                response["Allow"] = ", ".join(methods)
                return response
            try:
                return view(request, *args, **kwargs)
            except AuthError as error:
                return JsonResponse({"status": error.code}, status=error.status)
        return call
    return decorate


def field(request, name, *, optional=False, maximum=4096):
    values = request.POST.getlist(name)
    if len(values) != 1 or len(values[0]) > maximum or (not values[0] and not optional):
        if optional and not values:
            return ""
        raise AuthError("invalid", 400)
    return values[0]


def password(request):
    value = field(request, "password", maximum=128)
    if value != field(request, "password_confirmation", maximum=128):
        raise AuthError("invalid", 400)
    return value


def confirmation(request, *, title, action, fields, token_link=False):
    inputs = format_html_join("", '<p><label>{} <input name="{}" type="{}" required autocomplete="{}"></label></p>',
                              [(label, name, kind, autocomplete) for name, label, kind, autocomplete in fields])
    body = format_html('<!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" '
                       'content="width=device-width, initial-scale=1"><title>{}</title><main><h1>{}</h1>'
                       '<form method="post" action="{}"><input type="hidden" name="csrfmiddlewaretoken" value="{}">'
                       '{}<button type="submit">{}</button></form></main></html>',
                       title, title, action, get_token(request), inputs, title)
    response = HttpResponse(body)
    # Chromium sends Origin:null for native POSTs from no-referrer documents.
    response["Referrer-Policy"] = "same-origin"
    response["Content-Security-Policy"] = "default-src 'none'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"
    if token_link:
        import secrets
        nonce = secrets.token_urlsafe(24)
        # The fragment never reaches the server. POST remains an explicit user action.
        script = (
            "const input=document.querySelector('input[name=token]');"
            "const token=new URLSearchParams(location.hash.slice(1)).get('token');"
            "if(token){input.value=token;history.replaceState(null,'',location.pathname);}"
        )
        response.content += ('<script nonce="' + nonce + '">' + script + '</script>').encode()
        response["Content-Security-Policy"] += "; script-src 'nonce-" + nonce + "'"
    return response


@endpoint(("GET",))
def landing(request):
    actor = customer(request, required=False)
    enabled = services().core.enabled()
    return json_result(customer={"id": actor["id"]} if actor else None, csrf_token=get_token(request),
                       new_methods_enabled=enabled, magic_link_available=True,
                       routes={"login": "/akun/login", "register": "/akun/register", "magic": "/akun/login-link",
                               "recovery": "/akses-pesanan"})


@endpoint(("POST",))
def auth_action(request, action):
    session = request.auth_session
    bundle = services()
    browser = session.browser(request)
    result = None
    if action == "register":
        bundle.core.register(field(request, "email", maximum=320), password(request),
                             field(request, "name", optional=True, maximum=255), browser)
    elif action == "login":
        result = bundle.core.login(field(request, "email", maximum=320), field(request, "password"), browser)
    elif action == "verify":
        result = bundle.core.verify(field(request, "token"), browser)
    elif action == "reset-request":
        bundle.core.request_reset(field(request, "email", maximum=320), browser)
    elif action == "reset":
        bundle.core.reset(field(request, "token"), password(request), browser)
        session.logout_customer(request)
    elif action == "otp-request":
        challenge = bundle.core.request_otp(field(request, "email", maximum=320), browser)
        # Unknown accounts get the same response and a non-authorizing opaque placeholder.
        import secrets
        session.set("otp", challenge or secrets.token_urlsafe(32))
    elif action == "otp-consume":
        result = bundle.core.consume_otp(session.data.get("otp", ""), field(request, "code", maximum=6), browser)
    elif action == "magic-request":
        bundle.core.request_magic(field(request, "email", maximum=320), browser)
    elif action == "magic-consume":
        result = bundle.core.consume_magic(field(request, "token"), browser)
    elif action == "password":
        customer(request)
        result = bundle.core.set_password(password(request), browser)
    elif action == "email":
        customer(request)
        bundle.core.enroll_email(field(request, "email", maximum=320), browser)
    elif action == "logout":
        session.logout_customer(request)
    else:
        raise AuthError("not_found", 404)
    if result:
        session.authenticate(request, result)
    return json_result("ok" if result or action in ("logout", "reset", "password") else "requested")


@endpoint(("GET",))
def token_form(request, action, token=None):
    # Never reflect or consume query/path secrets. A user explicitly supplies the mailed token.
    routes = {"verify": ("Verifikasi email", "/akun/verifikasi-email"),
              "reset": ("Atur ulang password", "/akun/password-reset/confirm"),
              "magic-consume": ("Masuk ke akun", "/akun/login-link/consume")}
    title, destination = routes[action]
    fields = [("token", "Token", "password", "off")]
    if action == "reset":
        fields.extend([("password", "Password baru", "password", "new-password"),
                       ("password_confirmation", "Konfirmasi password", "password", "new-password")])
    return confirmation(request, title=title, action=destination, fields=fields, token_link=True)


@endpoint(("GET",))
def security(request):
    actor = customer(request)
    return json_result(customer={"id": actor["id"]}, csrf_token=get_token(request))


@endpoint(("GET", "POST"))
def recovery(request):
    if request.method != "POST":
        return confirmation(request, title="Akses Pesanan", action="/akses-pesanan", fields=[
            ("invoice_number", "Nomor invoice", "text", "off"), ("access_key", "Recovery Key", "password", "off")])
    order = services().orders.recovery(request, field(request, "invoice_number", maximum=100), field(request, "access_key"))
    return json_result(order=order)


@endpoint(("GET", "POST"))
def claim(request):
    customer(request)
    if request.method != "POST":
        return confirmation(request, title="Klaim pesanan", action="/akun/klaim-pesanan", fields=[
            ("invoice_number", "Nomor invoice", "text", "off"), ("access_key", "Recovery Key", "password", "off")])
    order = services().orders.claim(request, field(request, "invoice_number", maximum=100), field(request, "access_key"))
    return json_result(order=order)


@endpoint(("GET",))
def order_read(request, order_id, mode):
    access = services().orders
    order = getattr(access, mode)(request, order_id)
    return json_result(order=order)


@endpoint(("GET",))
def orders(request):
    return json_result(orders=services().orders.owned_list(request))


@endpoint(("GET", "POST"))
def admin_login(request):
    if request.method != "POST":
        return confirmation(request, title="Masuk Admin", action="/admin/login", fields=[
            ("email", "Email atau nama admin", "text", "username"), ("password", "Password", "password", "current-password")])
    actor = services().admin.login(request, field(request, "email", maximum=320), field(request, "password"))
    return json_result(admin=actor)


@endpoint(("GET",))
def admin_current(request):
    return json_result(admin=services().admin.current(request), csrf_token=get_token(request))


@endpoint(("POST",))
def admin_logout(request):
    services().admin.logout(request)
    return json_result()


@endpoint(("GET", "POST"))
def google(request, operation):
    if operation in ("link", "unlink"):
        if request.method != "POST":
            raise AuthError("method_not_allowed", 405)
        customer(request)
    elif request.method != "GET":
        raise AuthError("method_not_allowed", 405)
    session, bundle = request.auth_session, services()
    service = google_service(bundle)
    browser = session.browser(request)
    if operation == "callback":
        state = session.data.get("google") or {}
        session.discard("google")
        result = service.callback(browser, request.GET.get("state", ""), request.GET.get("code", ""),
                                  state.get("nonce", ""), error=request.GET.get("error"))
        session.authenticate(request, result)
        return json_result()
    if operation == "unlink":
        session.authenticate(request, service.unlink(browser))
        return json_result()
    operation = "link" if operation == "link" else request.GET.get("operation", "login")
    if operation not in ("login", "register", "link"):
        raise AuthError("invalid", 400)
    result = service.start(browser, operation)
    session.set("google", {"nonce": result["nonce"]})
    return HttpResponseRedirect(result["authorization_url"])


@endpoint(("GET", "POST"))
def telegram(request, operation):
    if (operation == "status") != (request.method in ("GET", "HEAD")):
        raise AuthError("method_not_allowed", 405)
    if operation == "link":
        customer(request)
    session, bundle = request.auth_session, services()
    service = telegram_service(bundle)
    browser = session.browser(request)
    if operation in ("login", "link"):
        result = service.start(browser, link=operation == "link")
        session.set("telegram", {"claim_id": result["claim_id"], "nonce": result["nonce"]})
        return json_result(deep_link=result["deep_link"], expires_in=result["expires_in"])
    state = session.data.get("telegram") or {}
    if operation == "status":
        return json_result(service.status(browser, state.get("claim_id", ""), state.get("nonce", "")))
    if operation == "consume":
        principal = service.consume(browser, state.get("claim_id", ""), state.get("nonce", ""))
        session.authenticate(request, principal)
        return json_result()
    raise AuthError("not_found", 404)


def csrf_failure(request, reason=""):
    return JsonResponse({"status": "csrf_failed"}, status=403)
