from django.urls import include, path

from . import http
from .navigation.webhook import receive
from .payments.callback import receive as receive_sakurupiah
from .payments.ingress import receive_klikqris, receive_klikqris_sandbox, receive_pakasir
from .payments.ingress import receive_sakurupiah_production


urlpatterns = [
    path("", include("lhie_candidate.auth.urls")),
    path("health", http.health),
    path("ready", http.ready),
    path("webhook/telegram", receive),
    path("callback/sakurupiah", receive_sakurupiah),
    path("api/webhook/klikqris", receive_klikqris),
    path("api/webhook/klikqris/sandbox", receive_klikqris_sandbox),
    path("api/webhook/pakasir", receive_pakasir),
    path("api/webhook/sakurupiah", receive_sakurupiah_production),
]
handler400 = http.bad_request
handler403 = http.forbidden
handler404 = http.not_found
handler500 = http.server_error
