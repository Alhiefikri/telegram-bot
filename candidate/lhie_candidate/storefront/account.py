"""Storefront account pages backed by the existing authentication and receipt services."""
import time
from urllib.parse import urlencode

from django.conf import settings
from django.db import transaction
from django.http import HttpResponse, HttpResponseRedirect, JsonResponse
from django.middleware.csrf import get_token
from django.shortcuts import render

from .. import runtime
from ..auth import http as auth
from ..auth.sessions import customer
from ..auth.types import AuthError
from ..checkout.db import Database
from ..compat.crypto import CryptoError, LaravelEncrypter
from ..compat.identity import signed_url_digest
from ..checkout.errors import CheckoutError
from ..payments.runtime import payment_services
from ..navigation.repository import CatalogRepository
from ..receipt.source import ReceiptSource, ReceiptUnavailable

def _account_summary(actor, context):
    db = Database()
    profile = db.one('SELECT first_name,username,email,created_at FROM public.customers WHERE id=%s', (actor['id'],))
    if profile is None:
        raise AuthError('authentication_required', 401)
    name = (profile['first_name'] or '').strip() or (profile['username'] or '').strip() or 'Pelanggan'
    counts = db.one("""SELECT count(*) AS total,
        count(*) FILTER (WHERE order_status IN ('draft','pending_payment')) AS waiting,
        count(*) FILTER (WHERE order_status='processing') AS processing,
        count(*) FILTER (WHERE order_status='completed') AS completed,
        count(*) FILTER (WHERE order_status IN ('cancelled','expired')) AS issue
        FROM public.orders WHERE customer_id=%s""", (actor['id'],))
    repo = CatalogRepository()
    idr = context.get('idr') or repo.balance_snapshot(actor['id'])
    usdt = context.get('usdt') or repo.usdt_balance_snapshot(actor['id'])
    return {'name': name, 'initial': name[0].upper(), 'username': (profile['username'] or '').lstrip('@'),
            'email': profile['email'], 'joined': profile['created_at'], 'counts': counts,
            'idr': 'Rp ' + format(idr['balance'], ',').replace(',', '.') if idr['available'] else None,
            'usdt': _usdt(usdt['balance_micro']) + ' USDT' if usdt['available'] else None,
            'frozen': usdt['available'] and usdt['frozen']}



def page(request, title, **context):
    if context.get('actor'):
        context['account_summary'] = _account_summary(context['actor'], context)
    response = render(request, 'storefront/account.html', {'title': title, 'csrf_token': get_token(request), **context})
    response['Cache-Control'] = 'no-store, private'
    response['Referrer-Policy'] = 'same-origin'
    response['X-Robots-Tag'] = 'noindex, nofollow'
    return response


def error(request, message='Permintaan tidak dapat diproses.', status=400):
    response = page(request, 'Akses pesanan', error=message)
    response.status_code = status
    return response


def guard(view):
    def wrapped(request, *args, **kwargs):
        try:
            return view(request, *args, **kwargs)
        except AuthError as exc:
            if exc.code == 'authentication_required':
                return HttpResponseRedirect('/akun')
            return error(request, 'Nomor invoice atau Recovery Key tidak cocok.' if exc.status in (400, 403) else 'Akses tidak tersedia.', exc.status)
    return wrapped


def only(request, *methods):
    if request.method not in methods and not (request.method == 'HEAD' and 'GET' in methods):
        response = HttpResponse(status=405)
        response['Allow'] = ', '.join(methods)
        return response
    return None


@guard
def identity(request):
    if response := only(request, 'GET'):
        return response
    actor = customer(request, required=False)
    name = None
    if actor:
        row = Database().one('SELECT first_name,username FROM public.customers WHERE id=%s', (actor['id'],))
        if row:
            name = (row['first_name'] or '').strip() or (row['username'] or '').strip() or 'Pelanggan'
        else:
            actor = None
    result = JsonResponse({'authenticated': actor is not None, 'name': name,
                           'initial': name[0].upper() if name else None})
    result['Cache-Control'] = 'no-store, private'
    result['Referrer-Policy'] = 'no-referrer'
    return result




@guard
def security(request):
    if response := only(request, 'GET'):
        return response
    from .account_auth import security_context
    actor = customer(request)
    return page(request, 'Keamanan akun', screen='security', actor=actor, **security_context(request, actor))


@guard
def recovery(request, claim=False):
    if response := only(request, 'GET', 'POST'):
        return response
    actor = customer(request) if claim else None
    if request.method == 'POST':
        access = auth.services().orders
        operation = access.claim if claim else access.recovery
        order = operation(request, auth.field(request, 'invoice_number', maximum=100), auth.field(request, 'access_key'))
        return HttpResponseRedirect(('/akun/pesanan/' if claim else '/akses-pesanan/') + order['id'])
    return page(request, 'Klaim pesanan' if claim else 'Akses Pesanan', screen='claim' if claim else 'recovery', actor=actor)


@guard
def orders(request):
    if response := only(request, 'GET'):
        return response
    actor = customer(request)
    choices = {'all': ('Semua', None), 'waiting': ('Menunggu', ('draft', 'pending_payment')),
               'processing': ('Diproses', ('processing',)), 'completed': ('Selesai', ('completed',)),
               'issue': ('Masalah', ('cancelled', 'expired'))}
    selected = request.GET.get('status', 'all')
    if selected not in choices:
        selected = 'all'
    raw_page = request.GET.get('page', '1')
    number = int(raw_page) if raw_page.isascii() and raw_page.isdecimal() and len(raw_page) <= 8 else 1
    number = max(1, number)
    db = Database()
    where, params = 'customer_id=%s', [actor['id']]
    if choices[selected][1]:
        where += ' AND order_status=ANY(%s)'
        params.append(list(choices[selected][1]))
    count = db.one('SELECT count(*) AS total FROM public.orders WHERE ' + where, params)['total']
    last = max(1, (count + 19) // 20)
    number = min(number, last)
    orders = db.rows('SELECT id,invoice_number,order_status AS status,payment_status,total_amount,currency,created_at '
                     'FROM public.orders WHERE ' + where + ' ORDER BY created_at DESC,id DESC LIMIT 20 OFFSET %s',
                     [*params, (number - 1) * 20])
    filters = [{'label': label, 'url': '/akun/pesanan?' + urlencode({'status': key}), 'active': key == selected}
               for key, (label, _) in choices.items()]
    def link(page_number):
        return '/akun/pesanan?' + urlencode({'status': selected, 'page': page_number})
    return page(request, 'Pesanan Saya', screen='orders', orders=orders, actor=actor, filters=filters,
                active_filter=selected, previous_url=link(number - 1) if number > 1 else None,
                next_url=link(number + 1) if number < last else None)


def _usdt(micro):
    amount = int(micro)
    sign = '-' if amount < 0 else ''
    whole, fraction = divmod(abs(amount), 1_000_000)
    return f'{sign}{whole}' + ('.' + f'{fraction:06d}'.rstrip('0') if fraction else '')


@guard
def balance(request):
    if response := only(request, 'GET'):
        return response
    actor = customer(request)
    repo = CatalogRepository()
    idr, usdt = repo.balance_snapshot(actor['id']), repo.usdt_balance_snapshot(actor['id'])
    currency = request.GET.get('currency', 'IDR')
    direction, token = request.GET.get('direction'), request.GET.get('cursor')
    if (currency not in ('IDR', 'USDT') or direction not in (None, 'older', 'newer')
            or (direction is None) != (token is None)):
        raise AuthError('not_found', 404)
    from . import balance_cursor, balance_idr, balance_usdt
    cursor = balance_cursor.decode(token, actor['id'], currency) if token is not None else None
    if token is not None and cursor is None:
        raise AuthError('not_found', 404)
    activity = (balance_idr if currency == 'IDR' else balance_usdt).read_page(
        actor['id'], direction=direction, cursor=cursor)
    if activity is None:
        raise AuthError('not_found', 404)
    links = {}
    for name in ('older', 'newer'):
        position = activity[name]
        links[name] = ('/akun/saldo?' + urlencode({'currency': currency, 'direction': name,
                        'cursor': balance_cursor.encode(actor['id'], currency, position)})) if position else None
    return page(request, 'Saldo saya', screen='balance', actor=actor, idr=idr, usdt=usdt,
                idr_display='Rp ' + format(idr['balance'], ',').replace(',', '.'),
                usdt_display=_usdt(usdt['balance_micro']), currency=currency,
                entries=activity['entries'], page_links=links)


def _grant(request, order_id):
    expiry = (request.auth_session.data.get('storefront_orders') or {}).get(str(order_id), 0)
    return type(expiry) in (int, float) and expiry > time.time()


def _authorize(request, order_id, mode):
    access = auth.services().orders
    if mode == 'signed':
        access.signed(request, order_id)
    elif mode == 'owned':
        access.owned(request, order_id)
    else:
        access.recovered(request, order_id)
    return access._order(order_id)


def _can_reveal(request, row, mode):
    if mode in ('owned', 'recovered') or _grant(request, row['id']):
        return True
    expiry = (request.auth_session.data.get('orders') or {}).get(str(row['id']), 0)
    if type(expiry) in (int, float) and expiry > time.time():
        return True
    actor = customer(request, required=False)
    return bool(actor and actor['id'] == row['customer_id'])


def _signed_path(order_id, action, expires):
    path = f'/shop/orders/{order_id}' + ('/' + action if action else '')
    unsigned = path + '?' + urlencode({'expires': expires})
    return unsigned + '&signature=' + signed_url_digest(unsigned, runtime.application_key())


def _payment_owner():
    factory = getattr(settings, 'CANDIDATE_STOREFRONT_PAYMENT_SERVICES', None)
    return factory() if callable(factory) else payment_services()


@guard
def invoice(request, order_id, mode='signed'):
    if response := only(request, 'GET'):
        return response
    row = _authorize(request, order_id, mode)
    db = Database()
    details = db.one('SELECT total_amount,currency,created_at FROM public.orders WHERE id=%s', (str(order_id),))
    items = db.rows('SELECT product_name,quantity,unit_price,subtotal FROM public.order_items WHERE order_id=%s ORDER BY id', (str(order_id),))
    payment = db.one('SELECT id,status,method,provider,amount,currency FROM public.payments WHERE order_id=%s ORDER BY created_at DESC,id DESC LIMIT 1', (str(order_id),))
    payment_info = None
    if payment and payment['status'] == 'pending' and row['status'] == 'pending_payment':
        outcome = _payment_owner().classify(payment['id'])
        if outcome.state == 'created' and isinstance(outcome.presentation, dict):
            presentation = outcome.presentation
            amount = presentation.get('amount_micro')
            payment_info = {
                'mode': presentation.get('mode'),
                'receiver_type': presentation.get('receiver_type'),
                'receiver_value': presentation.get('receiver_value'),
                'receiver_address': presentation.get('receiver_address'),
                'network': presentation.get('network'),
                'verification_mode': presentation.get('verification_mode'),
                'deadline': presentation.get('effective_expires_at'),
                'total': outcome.total if outcome.total is not None else payment['amount'],
                'currency': payment['currency'],
                'usdt_amount': (f'{amount // 1000000}.{amount % 1000000:06d}'
                                if type(amount) is int and amount >= 0 else None),
            }
            if presentation.get('mode') == 'hosted':
                from ..checkout.navigation import _presentation_values
                _, payment_info['checkout_url'], _ = _presentation_values(presentation, payment['provider'])
    can_reveal = _can_reveal(request, row, mode)
    groups = []
    if can_reveal and row['payment_status'] == 'paid':
        source = ReceiptSource(LaravelEncrypter(runtime.application_key()), invoice_base_url=getattr(settings, 'CANDIDATE_LEGACY_APP_URL', ''))
        try:
            with transaction.atomic():
                snapshot = source.load_customer(str(order_id), row['customer_id'], lock=True)
                groups = snapshot['groups']
        except ReceiptUnavailable:
            pass
    key = None
    keys = dict(request.auth_session.data.get('storefront_recovery_keys') or {})
    encrypted = keys.get(str(order_id)) if _grant(request, order_id) else None
    if encrypted:
        try:
            key = LaravelEncrypter(runtime.application_key()).decrypt_string(encrypted)
            if not auth.services().orders._key(row, key):
                key = None
        except CryptoError:
            pass
    expires = int(request.GET['expires']) if mode == 'signed' else int(time.time()) + 1800
    status_url = _signed_path(order_id, 'status', expires)
    actions = {}
    if row['status'] == 'pending_payment' and payment and payment['status'] == 'pending':
        actions = {'qris': _signed_path(order_id, 'qris', expires),
                   'cancel': _signed_path(order_id, 'cancel', expires),
                   'binance': _signed_path(order_id, 'binance-order-id', expires),
                   'bsc': _signed_path(order_id, 'bsc-tx-hash', expires)}
    flash = request.auth_session.get('invoice_notice') or {}
    notice = flash.get('text') if isinstance(flash, dict) and flash.get('order_id') == str(order_id) else None
    if notice:
        request.auth_session.discard('invoice_notice')
    from .catalog import _support
    from .support import order_choices
    support_choices = order_choices(row['invoice_number'], whatsapp=_support(CatalogRepository()),
                                    telegram=ReceiptSource(LaravelEncrypter(runtime.application_key())).support_url())
    result = page(request, 'Invoice ' + (row['invoice_number'] or ''), screen='invoice', order=row,
                  details=details, items=items, payment=payment, payment_info=payment_info, mode=mode,
                  groups=groups, can_reveal=can_reveal, recovery_key=key, status_url=status_url,
                  actions=actions, notice=notice, support_choices=support_choices)
    if key:
        keys.pop(str(order_id), None)
        request.auth_session.set('storefront_recovery_keys', keys)
    return result


@guard
def status(request, order_id, mode='signed'):
    if response := only(request, 'POST'):
        return response
    row = _authorize(request, order_id, mode)
    browser = request.auth_session.browser(request)
    auth.services().limits.reserve([('invoice-status-ip', browser.ip, 3, 60),
                                    ('invoice-status-order', str(order_id), 3, 60)])
    payment = _payment_for_order(order_id)
    if payment and payment['status'] == 'pending' and row['status'] == 'pending_payment':
        configured = callable(getattr(settings, 'CANDIDATE_STOREFRONT_PAYMENT_SERVICES', None))
        if not configured and (payment['provider'] != 'manual' or payment['method'] != 'QR_MANUAL'):
            return error(request, 'Status pembayaran belum dapat diperiksa. Coba lagi atau hubungi bantuan.', 503)
        try:
            outcome = _payment_owner().refresh(payment['id'])
        except Exception:
            return error(request, 'Status pembayaran belum dapat diperiksa. Coba lagi atau hubungi bantuan.', 503)
        if outcome.state in ('provider_unavailable', 'recovery_unconfirmed', 'outcome_unknown', 'provider_error', 'busy', 'status_deferred'):
            return error(request, 'Status pembayaran belum dapat dipastikan. Coba lagi atau hubungi bantuan.', 503)
        row = _authorize(request, order_id, mode)
    if 'application/json' not in request.headers.get('Accept', ''):
        target = (_signed_path(order_id, '', int(request.GET['expires'])) if mode == 'signed'
                  else '/akun/pesanan/' + str(order_id))
        return HttpResponseRedirect(target)
    response = JsonResponse({'order_status': row['status'], 'payment_status': row['payment_status']})
    response['Cache-Control'] = 'no-store, private'
    response['Referrer-Policy'] = 'no-referrer'
    return response


@guard
def resend(request, order_id):
    if response := only(request, 'POST'):
        return response
    bundle = auth.services()
    browser = request.auth_session.browser(request)
    bundle.limits.reserve([('credential-mail-ip', browser.ip, 5, 900),
                           ('credential-mail-order', str(order_id), 5, 900)])
    db = Database()
    source = ReceiptSource(LaravelEncrypter(bundle.key), invoice_base_url=getattr(settings, 'CANDIDATE_LEGACY_APP_URL', ''))
    with transaction.atomic():
        row = bundle.orders._order(order_id)
        if not _can_reveal(request, row, 'signed'):
            raise AuthError('not_found', 404)
        try:
            source.load_customer(str(order_id), row['customer_id'], lock=True)
        except ReceiptUnavailable:
            return error(request, 'Data akun belum tersedia untuk dikirim.', 409)
        event = db.one("SELECT id,recipient_email,status,last_error,delivery_started_at FROM public.order_notification_events WHERE order_id=%s AND event_type='credentials_ready' FOR UPDATE", (str(order_id),))
        if not event or not event['recipient_email']:
            return error(request, 'Email pesanan tidak tersedia. Hubungi bantuan.', 409)
        try:
            recipient = LaravelEncrypter(bundle.key).decrypt_string(event['recipient_email'])
        except CryptoError:
            return error(request, 'Email pesanan tidak tersedia. Hubungi bantuan.', 409)
        if not recipient:
            return error(request, 'Email pesanan tidak tersedia. Hubungi bantuan.', 409)
        if event['status'] in ('pending', 'sending') or event['delivery_started_at'] is not None:
            return page(request, 'Email data akun', notice='Pengiriman masih menunggu atau sedang diproses. Hubungi bantuan jika status tidak berubah.')
        safe_failure = event['status'] == 'failed' and event['last_error'] in ('mailer_unsafe', 'mailer_unavailable', 'order_not_eligible', 'recipient_missing', 'fulfillment_processing', 'credentials_unavailable')
        if event['status'] != 'sent' and not safe_failure:
            return error(request, 'Status pengiriman perlu diperiksa. Hubungi bantuan sebelum mencoba lagi.', 409)
        db.execute("UPDATE public.order_notification_events SET status='pending',attempts=0,next_attempt_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',delivery_started_at=NULL,failed_at=NULL,last_error=NULL,updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta' WHERE id=%s", (event['id'],))
    return page(request, 'Email data akun', notice='Permintaan pengiriman dicatat dan menunggu pemrosesan. Email belum tentu telah terkirim.')


def _payment_for_order(order_id):
    return Database().one('SELECT id,provider,method,status FROM public.payments WHERE order_id=%s ORDER BY created_at DESC,id DESC LIMIT 1', (str(order_id),))


@guard
def qris(request, order_id):
    if response := only(request, 'GET'):
        return response
    row = _authorize(request, order_id, 'signed')
    payment = _payment_for_order(order_id)
    if row['status'] != 'pending_payment' or not payment or payment['status'] != 'pending':
        raise AuthError('not_found', 404)
    services = _payment_owner()
    result = services.classify(payment['id'])
    presentation = result.presentation or {}
    if result.state != 'created':
        raise AuthError('not_found', 404)
    try:
        mode = presentation.get('mode')
        if mode == 'manual':
            contents = presentation['photo']
        elif mode == 'qris':
            contents = services.presentation.payload(presentation['qris_payload'])
        elif mode == 'qris_image':
            contents = services.presentation.provider_image(
                presentation['qris_image_url'], payment['provider'],
                environment=presentation.get('environment', 'sandbox'),
                allowed_hosts=presentation.get('allowed_hosts', ()))
        else:
            contents = None
        if not isinstance(contents, bytes) or not contents:
            raise ValueError('Image unavailable')
    except (KeyError, ValueError, OSError):
        raise AuthError('not_found', 404) from None
    response = HttpResponse(contents, content_type='image/jpeg' if contents.startswith(b'\xff\xd8\xff') else 'image/png')
    response['Cache-Control'] = 'no-store, private'
    response['Referrer-Policy'] = 'no-referrer'
    return response


@guard
def payment_action(request, order_id, action):
    if response := only(request, 'POST'):
        return response
    row = _authorize(request, order_id, 'signed')
    bundle = auth.services()
    bundle.limits.reserve([('invoice-action-ip', request.auth_session.browser(request).ip, 10, 60),
                           ('invoice-action-order', str(order_id), 10, 60)])
    payment = _payment_for_order(order_id)
    if not payment or payment['status'] != 'pending' or row['status'] != 'pending_payment':
        return error(request, 'Pesanan tidak lagi menunggu pembayaran.', 409)
    services = _payment_owner()
    if action == 'cancel':
        if payment['provider'] != 'manual':
            if not callable(getattr(settings, 'CANDIDATE_STOREFRONT_PAYMENT_SERVICES', None)):
                return error(request, 'Status pembayaran belum dapat diverifikasi. Pesanan tidak dibatalkan; hubungi bantuan.', 409)
            try:
                outcome = services.refresh(payment['id'])
            except Exception:
                return error(request, 'Status pembayaran belum dapat diverifikasi. Pesanan tidak dibatalkan; hubungi bantuan.', 409)
            if outcome.state != 'created':
                return error(request, 'Status pembayaran belum dapat dipastikan. Pesanan tidak dibatalkan.', 409)
        try:
            services.orders.apply_pending_cancellation(str(order_id), customer_id=row['customer_id'])
        except CheckoutError:
            return error(request, 'Pesanan tidak dapat dibatalkan saat ini.', 409)
        request.auth_session.set('invoice_notice', {'order_id': str(order_id), 'text': 'Pesanan dibatalkan. Stok dan data akun sudah dilepas.'})
        return HttpResponseRedirect(_signed_path(order_id, '', int(request.GET['expires'])))
    expected = {'binance-order-id': ('BINANCE_PAY_ID', 'binance_order_id', 'submit_order_id'),
                'bsc-tx-hash': ('USDT_BEP20', 'bsc_tx_hash', 'submit_hash')}
    method, field_name, operation = expected[action]
    if payment['method'] != method or payment['provider'] != 'manual':
        raise AuthError('not_found', 404)
    lifecycle = services.external_lifecycles.get(method)
    if lifecycle is None:
        return error(request, 'Verifikasi pembayaran belum tersedia. Hubungi bantuan.', 503)
    try:
        result = getattr(lifecycle, operation)(payment['id'], auth.field(request, field_name, maximum=128), customer_id=row['customer_id'])
    except CheckoutError:
        return error(request, 'Bukti pembayaran tidak dapat diterima.', 409)
    if result.state != 'accepted':
        return error(request, 'Bukti pembayaran tidak dapat diterima. Periksa kembali data atau hubungi bantuan.', 409)
    request.auth_session.set('invoice_notice', {'order_id': str(order_id), 'text': 'Bukti diterima untuk diperiksa. Pembayaran belum terverifikasi.'})
    return HttpResponseRedirect(_signed_path(order_id, '', int(request.GET['expires'])))
