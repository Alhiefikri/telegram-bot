from django.urls import path

from . import checkout


def _browser(view):
    def serve(request, *args, **kwargs):
        response = view(request, *args, **kwargs)
        response['Referrer-Policy'] = 'same-origin'
        return response
    return serve


def _checkout(request):
    return checkout.submit(request) if request.method == 'POST' else checkout.review(request)


urlpatterns = [
    path('shop/cart', _browser(checkout.cart)),
    path('shop/cart/items', _browser(checkout.cart_add)),
    path('shop/cart/items/<int:product_id>', _browser(checkout.cart_item)),
    path('shop/checkout', _browser(_checkout)),
    path('shop/checkout/products/<int:product_id>', _browser(checkout.review)),
]
