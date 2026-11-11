"""Public waitlist entry points; consent links remain Laravel absolute signatures."""
from django.core.exceptions import ValidationError
from django.core.validators import validate_email
from django.http import Http404, HttpResponse, HttpResponseRedirect
from django.shortcuts import render
from django.views.decorators.http import require_GET, require_POST

from ..auth.limits import RateLimits
from ..auth.types import AuthError
from ..compat.crypto import LaravelEncrypter
from ..compat.identity import normalize_customer_email, waitlist_email_hash
from ..navigation.repository import CatalogRepository
from ..notifications.policy import NotEligible, TestRecipients
from ..notifications.waitlist import WaitlistMail
from ..runtime import application_key, load_runtime


PENDING = 'Jika alamat email valid, tautan konfirmasi akan dikirim. Periksa inbox dan folder spam Anda.'
RESULTS = {
    'success': ('Permintaan dikonfirmasi', 'Permintaan notifikasi sudah dikonfirmasi.'),
    'already_requested': ('Permintaan sudah tercatat', 'Permintaan notifikasi ini sudah dikonfirmasi sebelumnya.'),
    'unsubscribed': ('Notifikasi dihentikan', 'Anda tidak akan menerima notifikasi lagi untuk produk ini.'),
    'expired_link': ('Tautan tidak tersedia', 'Tautan ini tidak valid, sudah digunakan, atau sudah kedaluwarsa.'),
}


def service(request):
    key = application_key()
    runtime = load_runtime()
    recipients = TestRecipients(frozenset({'1'}) if runtime.notifications else frozenset())
    return WaitlistMail(LaravelEncrypter(key), signing_key=key,
                        base_url=request.build_absolute_uri('/').rstrip('/'), recipients=recipients)


def private(response):
    response['Cache-Control'] = 'no-store, private'
    response['Referrer-Policy'] = 'no-referrer'
    return response


@require_POST
def subscribe(request, product_id):
    if CatalogRepository().product(product_id) is None:
        raise Http404
    key = application_key()
    email = normalize_customer_email(request.POST.get('email', ''))
    error = ('Isi alamat email untuk menerima notifikasi.' if not email else
             'Email maksimal 254 karakter.' if len(email) > 254 else None)
    if not error:
        try:
            validate_email(email)
        except ValidationError:
            error = 'Masukkan alamat email yang valid.'
    try:
        RateLimits(key).reserve([
            ('storefront-waitlist-ip', (request.META.get('REMOTE_ADDR') or 'unknown') + ':product:' + str(product_id), 30, 3600),
            ('storefront-waitlist-email', (waitlist_email_hash(email, key) if email else 'unknown') + ':product:' + str(product_id), 5, 3600),
            ('storefront-waitlist-product', str(product_id), 100, 3600)])
    except AuthError as exc:
        return private(HttpResponse('Terlalu banyak permintaan. Coba lagi nanti.', status=exc.status))
    if error:
        request.auth_session.set('waitlist_error', error)
        request.auth_session.set('waitlist_email', email[:254])
    else:
        try:
            service(request).subscribe(product_id, email)
        except NotEligible:
            # Local admission never reveals whether an address already exists.
            pass
        request.auth_session.set('waitlist_notice', PENDING)
    return private(HttpResponseRedirect(f'/produk/{product_id}#waitlist'))


def _result(request, subscription_id, action):
    mail = service(request)
    try:
        status = getattr(mail, action)(subscription_id, request.build_absolute_uri())
    except (NotEligible, ValueError):
        status = 'expired_link'
    title, message = RESULTS[status]
    return private(render(request, 'storefront/waitlist_result.html', {
        'title': title, 'message': message, 'description': message, 'robots': 'noindex, nofollow',
        'og_image': request.build_absolute_uri('/static/storefront/product-placeholder.svg')}))


@require_GET
def confirm(request, subscription_id):
    return _result(request, subscription_id, 'confirm')


@require_GET
def unsubscribe(request, subscription_id):
    return _result(request, subscription_id, 'unsubscribe')


def flash(request):
    session = getattr(request, 'auth_session', None)
    if session is None:
        return {}
    result = {name: session.get(name) for name in ('waitlist_notice', 'waitlist_error', 'waitlist_email')}
    for name in result:
        session.discard(name)
    return result
