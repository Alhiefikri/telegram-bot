"""Browser cart and checkout over the canonical pricing, inventory and payment owners."""
import hashlib
import hmac
import re
import secrets
import time
from dataclasses import asdict
from datetime import datetime, timedelta
from urllib.parse import urlsplit
from zoneinfo import ZoneInfo

import bcrypt
from django.conf import settings
from django.core import signing
from django.core.exceptions import ValidationError
from django.core.validators import validate_email
from django.db import transaction
from django.http import Http404, HttpResponseNotAllowed, QueryDict
from django.shortcuts import redirect, render
from django.utils import timezone
from django.utils.http import url_has_allowed_host_and_scheme

from lhie_candidate.auth.limits import RateLimits
from lhie_candidate.auth.types import AuthError
from lhie_candidate.checkout.db import Database
from lhie_candidate.checkout.errors import CheckoutError
from lhie_candidate.checkout.navigation import NavigationCheckout, sandbox_request
from lhie_candidate.checkout.pricing import PriceQuote, PricingSnapshot
from lhie_candidate.checkout.service import CheckoutRequest, CheckoutService
from lhie_candidate.compat.crypto import LaravelEncrypter
from lhie_candidate.compat.identity import signed_url_digest
from lhie_candidate.payments.runtime import payment_services
from lhie_candidate.runtime import application_key

_CART = 'storefront_cart'
_SALT = 'storefront.checkout.review.v1'
_TERMS = '2026-07-20'


def _number(value, minimum=1, maximum=20):
    if not isinstance(value, str) or not re.fullmatch(r'[0-9]{1,10}', value):
        raise ValueError
    result = int(value)
    if not minimum <= result <= maximum:
        raise ValueError
    return result


def _items(request):
    cart = request.auth_session.get(_CART, {})
    if not isinstance(cart, dict) or len(cart) > 50:
        return []
    try:
        return [{'product_id': _number(k, maximum=2147483647), 'quantity': _number(str(v))}
                for k, v in sorted(cart.items(), key=lambda pair: int(pair[0]))]
    except (ValueError, TypeError):
        return []


def _quote(items):
    db = Database()
    ids = [item['product_id'] for item in items]
    if not ids:
        raise CheckoutError('empty_cart')
    rows = db.rows('''SELECT p.*, (p.is_active AND p.deleted_at IS NULL AND
        (p.category_id IS NULL OR EXISTS (SELECT 1 FROM public.categories c
        WHERE c.id=p.category_id AND c.is_active AND c.deleted_at IS NULL))) AS public_visible,
        (SELECT count(*) FROM public.product_stock_items s WHERE s.product_id=p.id
         AND s.supplier_purchase_id IS NULL AND s.supplier_unit_index IS NULL
         AND s.order_item_id IS NULL AND s.retired_at IS NULL) AS credential_stock,
        CASE WHEN p.supplier_available_until > CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
             THEN GREATEST(0,p.supplier_available_stock) ELSE 0 END AS supplier_stock
        FROM public.products p WHERE p.id=ANY(%s)''', (ids,))
    products = {row['id']: row for row in rows}
    if len(products) != len(ids) or any(not row['public_visible'] for row in rows):
        raise CheckoutError('unavailable_product')
    quote = CheckoutService()._pricing(items, products)
    displayed = []
    conflict = False
    supplier_enabled = _supplier_encrypter() is not None
    for q in quote.quotes:
        product = products[q.product_id]
        available = max(0, min(product['stock'] - product['reserved_stock'], product['credential_stock']))
        if supplier_enabled:
            available += product['supplier_stock']
        conflict |= q.quantity > available
        displayed.append({'id': q.product_id, 'name': product['name'], 'quantity': q.quantity,
                          'unit': q.unit_price, 'subtotal': q.subtotal, 'stock': available})
    return quote, displayed, conflict


def _payment_owner():
    factory = getattr(settings, 'CANDIDATE_STOREFRONT_PAYMENT_SERVICES', None)
    return factory() if callable(factory) else payment_services()

def _supplier_encrypter():
    factory = getattr(settings, 'CANDIDATE_STOREFRONT_SUPPLIER_ENCRYPTER', None)
    return factory() if callable(factory) else None



def _rails(owner, quote):
    result = []
    try:
        owner.preparation(CheckoutService()._setting(lock=False))
        result.append(('qris', 'QRIS'))
    except CheckoutError:
        pass
    if quote.usdt_total is not None:
        for rail, label in (('binance_pay', 'Binance Pay'), ('bsc', 'USDT BEP20')):
            try:
                owner.preparation_for_rail(rail)
                result.append((rail, label))
            except (CheckoutError, KeyError):
                pass
    return result


def _binding(request):
    return hashlib.sha256(request.auth_session.token.encode()).hexdigest()


def _return_url(request, source, items, candidate=None):
    if source == 'cart':
        return '/shop/cart'
    fallback = f"/produk/{items[0]['product_id']}" if items else '/shop'
    if (not isinstance(candidate, str) or not candidate or len(candidate) > 2048
            or '\\' in candidate or any(ord(char) < 32 for char in candidate)
            or not url_has_allowed_host_and_scheme(candidate, allowed_hosts={request.get_host()},
                                                   require_https=request.is_secure())):
        return fallback
    try:
        parts = urlsplit(candidate)
        origin = urlsplit(request.build_absolute_uri('/'))
        if parts.netloc and (parts.scheme.lower() != origin.scheme.lower()
                              or parts.hostname != origin.hostname or parts.port != origin.port):
            return fallback
        if not parts.path.startswith('/') or parts.path.startswith('/produk-keluarga/'):
            return fallback
    except ValueError:
        return fallback
    return candidate


def _snapshot(request, items, quote, source, return_url):
    return signing.dumps({'binding': _binding(request), 'items': items, 'pricing': asdict(quote),
                          'source': source, 'return_url': return_url, 'nonce': secrets.token_hex(16)}, salt=_SALT, compress=True)


def _read_snapshot(request, token):
    data = signing.loads(token, salt=_SALT, max_age=7200)
    if data.get('binding') != _binding(request) or data.get('source') not in ('cart', 'direct'):
        raise signing.BadSignature('Invalid review')
    p = data['pricing']
    pricing = PricingSnapshot(tuple(PriceQuote(**q) for q in p['quotes']), p['idr_total'], p['usdt_total'], p['reseller'])
    return data, pricing


def _context(request, items, *, source, values=None, error=None, status=200, snapshot=None, error_target=None, return_url=None):
    return_url = _return_url(request, source, items, return_url)
    try:
        quote, displayed, conflict = _quote(items)
    except CheckoutError:
        return render(request, 'storefront/checkout_review.html', {'title': 'Checkout', 'error': error or 'Produk atau keranjang tidak lagi tersedia.',
                      'return_url': return_url}, status=status)
    owner = _payment_owner()
    rails = _rails(owner, quote)
    usdt_display = None if quote.usdt_total is None else f'{quote.usdt_total // 1000000}.{quote.usdt_total % 1000000:06d}'
    return render(request, 'storefront/checkout_review.html', {
        'title': 'Checkout', 'items': displayed, 'total': quote.idr_total, 'usdt_total': usdt_display,
        'source': source, 'snapshot': snapshot or _snapshot(request, items, quote, source, return_url), 'rails': rails,
        'conflict': conflict, 'error': error, 'error_target': error_target, 'values': values or {},
        'return_url': return_url,
    }, status=status)


def cart(request):
    if request.method != 'GET':
        return HttpResponseNotAllowed(['GET'])
    items = _items(request)
    try:
        quote, displayed, conflict = _quote(items)
    except CheckoutError:
        quote, conflict = None, bool(items)
        displayed = [{'id': item['product_id'], 'name': 'Produk tidak tersedia',
                      'quantity': item['quantity'], 'unit': None, 'subtotal': None, 'stock': 0}
                     for item in items]
    return render(request, 'storefront/cart.html', {'title': 'Keranjang', 'items': displayed, 'total': quote.idr_total if quote else 0,
                  'conflict': conflict, 'has_items': bool(items)})


def cart_add(request):
    if request.method != 'POST':
        return HttpResponseNotAllowed(['POST'])
    try:
        product = _number(request.POST.get('product_id'), maximum=2147483647)
        quantity = _number(request.POST.get('quantity', '1'))
        cart = {str(i['product_id']): i['quantity'] for i in _items(request)}
        quantity += cart.get(str(product), 0)
        if quantity > 20 or (len(cart) >= 50 and str(product) not in cart):
            raise ValueError
        _, _, conflict = _quote([{'product_id': product, 'quantity': quantity}])
        if conflict:
            raise CheckoutError('insufficient_stock')
        cart[str(product)] = quantity
        request.auth_session.set(_CART, cart)
    except (ValueError, CheckoutError):
        return render(request, 'storefront/cart.html', {'error': 'Produk atau jumlah tidak tersedia.', 'has_items': False}, status=422)
    return redirect('/shop/cart')


def cart_item(request, product_id):
    if request.method not in ('POST', 'PATCH', 'DELETE'):
        return HttpResponseNotAllowed(['POST', 'PATCH', 'DELETE'])
    cart = {str(i['product_id']): i['quantity'] for i in _items(request)}
    key = str(product_id)
    if key not in cart:
        raise Http404
    try:
        fields = request.POST if request.method == 'POST' else QueryDict(request.body)
        method = fields.get('_method', 'PATCH').upper() if request.method == 'POST' else request.method
        if method == 'DELETE':
            quantity = 0
        elif method == 'PATCH':
            quantity = _number(fields.get('quantity'), minimum=0)
        else:
            raise ValueError
        if quantity:
            cart[key] = quantity
        else:
            del cart[key]
        request.auth_session.set(_CART, cart)
    except ValueError:
        return render(request, 'storefront/cart.html', {'error': 'Jumlah harus antara 0 dan 20.', 'has_items': True}, status=422)
    return redirect('/shop/cart')


def review(request, product_id=None):
    if request.method != 'GET':
        return HttpResponseNotAllowed(['GET'])
    if product_id is not None:
        try:
            quantity = _number(request.GET.get('quantity', '1'))
        except ValueError:
            quantity = 1
        return _context(request, [{'product_id': product_id, 'quantity': quantity}], source='direct',
                        return_url=request.GET.get('from'))
    items = _items(request)
    return _context(request, items, source='cart') if items else redirect('/shop/cart')


def _key(request, token, purpose):
    message = (purpose + '\0' + request.auth_session.token + '\0' + token).encode()
    return hmac.new(settings.SECRET_KEY.encode(), message, hashlib.sha256).hexdigest()


def _guest_cart(items, name):
    db = Database()
    now = datetime.now(ZoneInfo('Asia/Jakarta')).replace(tzinfo=None)
    with transaction.atomic():
        row = db.one('''INSERT INTO public.customers (first_name,created_at,updated_at)
                      VALUES (%s,%s,%s) RETURNING id''', (name, now, now))
        cart = db.one('''INSERT INTO public.carts (customer_id,origin,status,expires_at,created_at,updated_at)
                      VALUES (%s,'customer','active',%s,%s,%s) RETURNING id''',
                      (row['id'], now + timedelta(hours=24), now, now))
        for item in items:
            db.execute('''INSERT INTO public.cart_items (cart_id,product_id,quantity,created_at,updated_at)
                       VALUES (%s,%s,%s,%s,%s)''',
                       (cart['id'], item['product_id'], item['quantity'], now, now))
    return row['id'], cart['id']
def _invoice_url(order_id):
    row = Database().one('SELECT created_at FROM public.orders WHERE id=%s', (order_id,))
    created = row['created_at']
    if created.tzinfo is None:
        created = created.replace(tzinfo=ZoneInfo('Asia/Jakarta'))
    days = max(1, int(getattr(settings, 'CANDIDATE_INVOICE_URL_TTL_DAYS', 7)))
    expiry = int((created + timedelta(days=days)).timestamp())
    unsigned = f'/shop/orders/{order_id}?expires={expiry}'
    return unsigned + '&signature=' + signed_url_digest(unsigned, application_key())

def _capture_contact(order_id, email, channel, value):
    db = Database()
    cipher = LaravelEncrypter(application_key())
    now = datetime.now(ZoneInfo('Asia/Jakarta')).replace(tzinfo=None)
    with transaction.atomic():
        order = db.one('SELECT order_status,payment_status FROM public.orders WHERE id=%s FOR UPDATE', (order_id,))
        if order is None or order['order_status'] != 'pending_payment' or order['payment_status'] != 'pending':
            return
        db.execute('''UPDATE public.orders SET support_contact_channel=%s,support_contact_value=%s,
                      notification_email=COALESCE(%s,notification_email),updated_at=%s WHERE id=%s''',
                   (channel or None, cipher.encrypt_string(value) if value else None,
                    cipher.encrypt_string(email) if email else None, now, order_id))
        if email:
            db.execute('''INSERT INTO public.order_notification_events
                          (order_id,event_type,status,created_at,updated_at)
                          VALUES (%s,'order_created','pending',%s,%s)
                          ON CONFLICT (order_id,event_type) DO NOTHING''', (order_id, now, now))




def submit(request):
    if request.method != 'POST':
        return HttpResponseNotAllowed(['POST'])
    try:
        RateLimits(application_key()).reserve([
            ('storefront-checkout-ip', request.META.get('REMOTE_ADDR') or 'unknown', 5, 60),
            ('storefront-checkout-session', request.auth_session.token, 5, 60),
        ])
    except AuthError as error:
        return render(request, 'storefront/checkout_review.html', {
            'error': ('Terlalu banyak percobaan. Coba lagi sebentar lagi.' if error.status == 429
                      else 'Checkout sementara tidak tersedia. Coba lagi sebentar lagi.'),
            'return_url': '/shop/cart',
        }, status=error.status)
    token = request.POST.get('snapshot', '')
    try:
        if len(token) > 16384:
            raise signing.BadSignature('Invalid review')
        data, expected = _read_snapshot(request, token)
    except (signing.BadSignature, KeyError, TypeError, ValueError):
        return render(request, 'storefront/checkout_review.html', {'error': 'Review kedaluwarsa. Buka checkout kembali.',
                      'return_url': '/shop/cart'}, status=400)
    items, source = data['items'], data['source']
    return_url = _return_url(request, source, items, data.get('return_url'))
    values = {key: request.POST.get(key, '') for key in
              ('buyer_name', 'payment_method', 'email', 'support_contact_channel', 'support_contact_value', 'terms_accepted')}
    name = values['buyer_name'].strip()
    rail = values['payment_method']
    email = values['email'].strip().lower()
    channel = values['support_contact_channel'].strip()
    contact = values['support_contact_value'].strip()
    error = None
    if not re.fullmatch(r'[A-Za-z0-9_ .-]{2,40}', name):
        error = 'Nama pembeli harus 2 sampai 40 karakter.'
    elif request.POST.get('terms_accepted') not in ('1', 'on', 'yes', 'true'):
        error = 'Setujui syarat penggunaan dan kebijakan refund sebelum checkout.'
    elif rail not in ('qris', 'binance_pay', 'bsc'):
        error = 'Pilih metode pembayaran yang tersedia.'
    elif email:
        try:
            if len(email) > 254:
                raise ValidationError('Invalid email')
            validate_email(email)
        except ValidationError:
            error = 'Masukkan alamat email yang valid.'
    if not error and (channel not in ('', 'telegram', 'whatsapp') or bool(channel) != bool(contact) or len(contact) > 120):
        error = 'Pilih jenis kontak dan isi kontak bantuan dengan benar.'
    setting = CheckoutService()._setting(lock=False)
    if (not error and rail == 'qris' and setting is not None and setting.provider == 'sakurupiah'
            and (channel != 'whatsapp' or not re.fullmatch(r'\+?[0-9][0-9 .-]{7,19}', contact))):
        error = 'Nomor WhatsApp diperlukan untuk pembayaran ini.'
    if error:
        target = ('checkout-buyer-name' if error.startswith('Nama') else
                  'checkout-terms-accepted' if error.startswith('Setujui') else
                  'checkout-payment-method' if error.startswith('Pilih metode') else
                  'checkout-email' if error.startswith('Masukkan') else 'checkout-support-contact-value')
        return _context(request, items, source=source, values=values, error=error,
                        error_target=target, status=422, return_url=return_url)
    if channel == 'telegram':
        contact = '@' + contact.lstrip('@')
    elif channel == 'whatsapp':
        contact = re.sub(r'[^0-9+]', '', contact)
    checkout_key = _key(request, token, 'checkout')
    recovery = 'LHIE-' + _key(request, token, 'recovery')[:20].upper()
    db = Database()
    lock = 'storefront:checkout:' + checkout_key
    if not db.one('SELECT pg_try_advisory_lock(hashtextextended(%s,0)) AS acquired', (lock,))['acquired']:
        return _context(request, items, source=source, values=values, snapshot=token,
                        error='Checkout sedang diproses. Coba lagi dengan review ini.', status=409, return_url=return_url)
    try:
        existing = db.one('''SELECT id,customer_id,invoice_number,order_status,payment_status,
                            total_amount,currency FROM public.orders WHERE checkout_key=%s''', (checkout_key,))
        owner = _payment_owner()
        from lhie_candidate.payments.sakurupiah import SakurupiahSandboxAdapter
        lifecycle = owner.lifecycles.get('sakurupiah')
        configured_factory = getattr(settings, 'CANDIDATE_STOREFRONT_PAYMENT_REQUEST_FACTORY', None)
        if callable(configured_factory):
            request_factory = lambda payment: configured_factory(payment, contact)
        elif isinstance(getattr(lifecycle, 'adapter', None), SakurupiahSandboxAdapter):
            request_factory = lambda payment: {**sandbox_request(payment), 'phone': contact}
        else:
            request_factory = None
        supplier_encrypter = _supplier_encrypter()
        navigator = NavigationCheckout(lifecycle=owner, request_factory=request_factory,
                                       supplier_encrypter=supplier_encrypter)
        if existing:
            order_id = str(existing['id'])
            _capture_contact(order_id, email, channel, contact)
            navigator._replay_view(existing, rail)
        else:
            if source == 'cart' and items != _items(request):
                raise CheckoutError('cart_changed')
            preparation = (owner.preparation(CheckoutService()._setting(lock=False)) if rail == 'qris'
                           else owner.preparation_for_rail(rail))
            customer_id, cart_id = _guest_cart(items, name)
            hashed = bcrypt.hashpw(recovery.encode(), bcrypt.gensalt(12)).decode().replace('$2b$', '$2y$', 1)
            service = CheckoutService(preparation=preparation, supplier_encrypter=supplier_encrypter)
            result = service.checkout(CheckoutRequest(customer_id, cart_id, checkout_key, expected,
                terms_version=_TERMS, terms_accepted_at=timezone.now(), credential_access_key_hash=hashed))
            order_id = result.order_id
            _capture_contact(order_id, email, channel, contact)
            # The provider lifecycle owns creation and recovery on the same payment.
            navigator._present_payment(result, rail=rail)
        grants = dict(request.auth_session.get('storefront_orders', {}))
        grants[order_id] = time.time() + 7200
        request.auth_session.set('storefront_orders', grants)
        keys = dict(request.auth_session.get('storefront_recovery_keys', {}))
        keys[order_id] = LaravelEncrypter(application_key()).encrypt_string(recovery)
        request.auth_session.set('storefront_recovery_keys', keys)
        if source == 'cart' and items == _items(request):
            request.auth_session.discard(_CART)
        return redirect(_invoice_url(order_id))
    except CheckoutError:
        return _context(request, items, source=source, values=values,
                        error='Harga, stok, atau metode pembayaran berubah. Periksa kembali sebelum checkout.', status=409, return_url=return_url)
    finally:
        db.one('SELECT pg_advisory_unlock(hashtextextended(%s,0)) AS released', (lock,))
