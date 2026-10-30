from django.urls import path

from . import http


urlpatterns = [path("health", http.health), path("ready", http.ready)]
handler400 = http.bad_request
handler403 = http.forbidden
handler404 = http.not_found
handler500 = http.server_error
