"""Native-form waitlist adapter; the notification owner alone changes state."""
from django.core.exceptions import ValidationError
from django.core.validators import validate_email
from django.http import Http404, HttpResponseRedirect, JsonResponse
from django.shortcuts import render
from django.views.decorators.http import require_GET, require_POST

from ..auth.limits import RateLimits
from ..auth.types import AuthError
from ..checkout.db import Database
from ..compat.crypto import LaravelEncrypter
from ..compat.identity import normalize_customer_email
from ..navigation.repository import CatalogRepository
from ..notifications.waitlist import Waitlist
from ..runtime import application_key


NOTICES = {
    'pending_confirmation': 'Jika alamat email valid, tautan konfirmasi akan dikirim. Periksa inbox dan folder spam Anda.',
    'success': 'Permintaan notifikasi sudah dikonfirmasi.',
    'already_requested': 'Permintaan notifikasi ini sudah dikonfirmasi sebelumnya.',
    'unsubscribed': 'Anda tidak akan menerima notifikasi lagi untuk produk ini.',
    'expired_link': 'Tautan ini tidak valid, sudah digunakan, atau sudah kedaluwarsa.',
}
TITLES = {'success': 'Permintaan dikonfirmasi', 'already_requested': 'Permintaan sudah tercatat',
          'unsubscribed': 'Notifikasi dihentikan', 'expired_link': 'Tautan tidak tersedia'}


def service():
    key = application_key()
    # Privilege is granted only by the explicit disposable provisioning file;
    # unprovisioned navigation/storefront compositions remain disabled.
    admitted = Database().one("SELECT has_column_privilege(current_user,"
        "'public.product_waitlist_subscriptions','email','INSERT') AS enabled")
    return Waitlist(LaravelEncrypter(key), key, enabled=admitted['enabled'])


def private(response):
    response['Cache-Control'] = 'no-store, private'
    response['Referrer-Policy'] = 'no-referrer'
    response['X-Robots-Tag'] = 'noindex, nofollow'
    return response


@require_POST
def subscribe(request, product_id):
    if CatalogRepository().product(product_id) is None:
        raise Http404
    address = normalize_customer_email(request.POST.get('email', ''))
    try:
        validate_email(address)
        if len(address) > 254:
            raise ValidationError('Invalid email')
    except ValidationError:
        return private(JsonResponse({'status': 'invalid_email'}, status=422))
    try:
        # Do not trust forwarded headers for the throttle identity.
        RateLimits(application_key()).reserve([
            ('waitlist-email', address, 5, 3600),
            ('waitlist-ip', request.META.get('REMOTE_ADDR', ''), 30, 3600),
            ('waitlist-product', str(product_id), 100, 3600),
        ])
        service().subscribe(product_id, address)
    except AuthError as error:
        return private(JsonResponse({'status': error.code}, status=error.status))
    # Identical feedback whether new, duplicate, disabled, stocked or not in
    # the local recipient allowlist. The email is never echoed into the URL.
    request.auth_session.data['waitlist_notice'] = NOTICES['pending_confirmation']
    request.auth_session.dirty = True
    return private(HttpResponseRedirect(f'/produk/{product_id}#waitlist'))


@require_GET
def resolve(request, subscription_id, purpose):
    result = service().resolve(subscription_id, purpose, request.build_absolute_uri())
    return private(render(request, 'storefront/waitlist_result.html', {
        'title': TITLES[result], 'message': NOTICES[result], 'robots': 'noindex, nofollow',
    }))
