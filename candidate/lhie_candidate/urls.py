from django.urls import include, path, re_path

from . import http
from .customer_api.http import endpoint as customer_api
from .navigation.webhook import receive
from .payments.callback import receive as receive_sakurupiah
from .payments.ingress import receive_klikqris, receive_klikqris_sandbox, receive_pakasir
from .payments.ingress import receive_sakurupiah_production
from .storefront.public_media import image as public_image


urlpatterns = [
    path("", include("lhie_candidate.storefront.account_urls")),
    path("", include("lhie_candidate.storefront.checkout_urls")),
    path("", include("lhie_candidate.auth.urls")),
    path("api/v1", customer_api),
    re_path(r"^api/v1/(?P<route>.*)$", customer_api),
    path("health", http.health),
    path("ready", http.ready),
    path("webhook/telegram", receive),
    path("callback/sakurupiah", receive_sakurupiah),
    path("api/webhook/klikqris", receive_klikqris),
    path("api/webhook/klikqris/sandbox", receive_klikqris_sandbox),
    path("api/webhook/pakasir", receive_pakasir),
    path("api/webhook/sakurupiah", receive_sakurupiah_production),
    path("storage/<path:path>", public_image),
    path("", include("lhie_candidate.storefront.catalog_urls")),
]
handler400 = http.bad_request
handler403 = http.forbidden
handler404 = http.not_found
handler500 = http.server_error
