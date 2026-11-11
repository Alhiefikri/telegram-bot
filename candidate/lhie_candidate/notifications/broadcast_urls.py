from django.urls import path

from . import broadcast_views

urlpatterns = [
    path('', broadcast_views.index, name='admin-broadcast'),
    path('publish', broadcast_views.publish, name='admin-broadcast-publish'),
]
