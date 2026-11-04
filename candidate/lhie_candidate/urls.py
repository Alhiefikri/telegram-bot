from django.urls import include, path

from . import http
from .navigation.webhook import receive
from .payments.callback import receive as receive_sakurupiah


urlpatterns = [
    path("", include("lhie_candidate.auth.urls")),
    path("health", http.health),
    path("ready", http.ready),
    path("webhook/telegram", receive),
    path("callback/sakurupiah", receive_sakurupiah),
]
handler400 = http.bad_request
handler403 = http.forbidden
handler404 = http.not_found
handler500 = http.server_error
