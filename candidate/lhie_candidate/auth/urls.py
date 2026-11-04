from django.urls import path

from . import http


def token_route(action):
    def route(request, token=None):
        if request.method in ("GET", "HEAD"):
            return http.token_form(request, action=action, token=token)
        return http.auth_action(request, action=action)
    return route


urlpatterns = [
    path("akun", http.landing),
    path("akun/register", http.auth_action, {"action": "register"}),
    path("akun/login", http.auth_action, {"action": "login"}),
    path("akun/verifikasi-email", token_route("verify")),
    path("akun/password-reset", lambda request: http.token_form(request, action="reset")
         if request.method in ("GET", "HEAD") else http.auth_action(request, action="reset-request")),
    path("akun/password-reset/confirm", token_route("reset")),
    path("akun/password-reset/<str:token>", token_route("reset")),
    path("akun/login-code", http.auth_action, {"action": "otp-request"}),
    path("akun/login-code/consume", http.auth_action, {"action": "otp-consume"}),
    path("akun/login-link", lambda request: http.token_form(request, action="magic-consume")
         if request.method in ("GET", "HEAD") else http.auth_action(request, action="magic-request")),
    path("akun/login-link/consume", http.auth_action, {"action": "magic-consume"}),
    path("akun/logout", http.auth_action, {"action": "logout"}),
    path("akun/keamanan", http.security),
    path("akun/keamanan/password", http.auth_action, {"action": "password"}),
    path("akun/telegram/email", http.auth_action, {"action": "email"}),
    path("akun/pesanan", http.orders),
    path("akun/pesanan/<uuid:order_id>", http.order_read, {"mode": "owned"}),
    path("akun/klaim-pesanan", http.claim),
    path("akses-pesanan", http.recovery),
    path("akses-pesanan/<uuid:order_id>", http.order_read, {"mode": "recovered"}),
    path("shop/orders/<uuid:order_id>", http.order_read, {"mode": "signed"}),
    path("admin/login", http.admin_login),
    path("admin/logout", http.admin_logout),
    path("admin", http.admin_current),
    path("auth/google/redirect", http.google, {"operation": "start"}),
    path("auth/google/callback", http.google, {"operation": "callback"}),
    path("akun/google/link", http.google, {"operation": "link"}),
    path("akun/google/unlink", http.google, {"operation": "unlink"}),
    path("akun/telegram/login", http.telegram, {"operation": "login"}),
    path("akun/telegram/link", http.telegram, {"operation": "link"}),
    path("akun/telegram/status", http.telegram, {"operation": "status"}),
    path("akun/telegram/consume", http.telegram, {"operation": "consume"}),
]
