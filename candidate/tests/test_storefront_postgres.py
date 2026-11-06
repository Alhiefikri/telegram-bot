"""P21 storefront proof inside the existing disposable P01 checkout harness."""
from html import unescape
import hashlib
import hmac
from html.parser import HTMLParser
import json
import os
from pathlib import Path
import re
import threading
import time
from tempfile import TemporaryDirectory
from urllib.parse import urlsplit

from tests.test_checkout_postgres import admin_connection


class Inputs(HTMLParser):
    def __init__(self):
        super().__init__()
        self.values = {}

    def handle_starttag(self, tag, attrs):
        if tag == 'input':
            fields = dict(attrs)
            if fields.get('name') and fields.get('value') is not None:
                self.values[fields['name']] = fields['value']


def inputs(response):
    parser = Inputs()
    parser.feed(response.content.decode('utf-8'))
    return parser.values


def exercise_boundaries(fixtures, admin, fields, location, invoice, guest, recovery_form):
    from django.test import Client
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.runtime import application_key

    def browser(ip):
        return Client(enforce_csrf_checks=True, HTTP_HOST='localhost', HTTP_ORIGIN='https://localhost',
                      REMOTE_ADDR=ip, raise_request_exception=False)

    def form(client, path):
        response = client.get(path, secure=True)
        assert response.status_code == 200, ('form', response.status_code)
        return inputs(response)

    def economics():
        return tuple(admin.execute('SELECT count(*) FROM public.' + table).fetchone()[0]
                     for table in ('orders', 'payments'))

    def checkout_fields(form):
        return {**fields, 'snapshot': form['snapshot'], 'csrfmiddlewaretoken': form['csrfmiddlewaretoken']}

    # A review is bound to its browser, even when another browser has its own CSRF token.
    other = browser('127.0.10.1')
    other_form = form(other, '/akses-pesanan')
    before = economics()
    response = other.post('/shop/checkout', {**fields, 'csrfmiddlewaretoken': other_form['csrfmiddlewaretoken']},
                          secure=True)
    assert response.status_code == 400 and economics() == before, 'cross-session review accepted'

    stock_client = browser('127.0.10.2')
    product = fixtures['stale']['product_id']
    stock_form = form(stock_client, f'/shop/checkout/products/{product}?quantity=1')
    original = admin.execute('SELECT reserved_stock,stock FROM public.products WHERE id=%s', (product,)).fetchone()
    admin.execute('UPDATE public.products SET reserved_stock=stock WHERE id=%s', (product,))
    try:
        response = stock_client.post('/shop/checkout', checkout_fields(stock_form), secure=True)
        assert response.status_code == 409 and economics() == before, 'stale stock accepted'
    finally:
        admin.execute('UPDATE public.products SET reserved_stock=%s WHERE id=%s', (original[0], product))

    cart_client = browser('127.0.10.3')
    cart_form = form(cart_client, f'/shop/checkout/products/{product}?quantity=1')
    csrf = cart_form['csrfmiddlewaretoken']
    assert cart_client.post('/shop/cart/items', {'product_id': product, 'quantity': 1}, secure=True).status_code == 403
    assert cart_client.post('/shop/cart/items', {'csrfmiddlewaretoken': csrf, 'product_id': product, 'quantity': 1},
                            secure=True).status_code == 302
    cart_review = form(cart_client, '/shop/checkout')
    assert cart_client.post(f'/shop/cart/items/{product}', {'csrfmiddlewaretoken': csrf, 'quantity': 2},
                            secure=True).status_code == 302
    response = cart_client.post('/shop/checkout', checkout_fields(cart_review), secure=True)
    assert response.status_code == 409 and economics() == before, 'changed cart accepted'

    order_path = urlsplit(location).path
    for action in ('status', 'cancel', 'binance-order-id', 'bsc-tx-hash'):
        match = re.search(rb'action="([^"]+/' + action.encode() + rb'\?[^"]+)"', invoice.content)
        if action in ('status', 'cancel'):
            assert match is not None, 'signed action missing'
        path = unescape(match.group(1).decode()) if match else order_path + '/' + action + '?' + urlsplit(location).query
        assert other.post(path, secure=True).status_code == 403, 'action without CSRF accepted'
        for invalid in (order_path + '/' + action, path + '&tampered=1'):
            result = other.post(invalid, {'csrfmiddlewaretoken': other_form['csrfmiddlewaretoken']}, secure=True)
            assert result.status_code in (403, 404), ('unsigned action accepted', action, result.status_code)
    qr_match = re.search(rb'<img src="([^"]+/qris\?[^"]+)"', invoice.content)
    qr_path = unescape(qr_match.group(1).decode())
    assert other.get(order_path + '/qris', secure=True).status_code in (403, 404)
    assert other.get(qr_path + '&tampered=1', secure=True).status_code in (403, 404)
    assert economics() == before, 'unauthorized action changed checkout records'

    data = fixtures['auth']
    paid_id = data['guest']['order_id']
    credential = b'Synthetic P07 p19-guest credential 1'
    recovered = guest.get('/akses-pesanan/' + paid_id, secure=True)
    assert recovered.status_code == 200 and credential in recovered.content, 'paid recovery omitted allocation'
    assert credential not in other.get('/akses-pesanan/' + paid_id, secure=True).content
    assert other.get('/akun/pesanan/' + paid_id, secure=True).status_code in (302, 403, 404)

    cipher = LaravelEncrypter(application_key())
    recipient = cipher.encrypt_string('p21-snapshot@example.invalid')
    admin.execute("""INSERT INTO public.order_notification_events
        (order_id,event_type,status,recipient_email,attempts,created_at,updated_at)
        VALUES (%s,'credentials_ready','sent',%s,1,now(),now())
        ON CONFLICT (order_id,event_type) DO UPDATE SET status='sent',recipient_email=EXCLUDED.recipient_email,
        attempts=1,delivery_started_at=NULL,last_error=NULL""", (paid_id, recipient))
    resend_path = '/shop/orders/' + paid_id + '/credential-email'
    csrf = recovery_form['csrfmiddlewaretoken']
    assert guest.post(resend_path, secure=True).status_code == 403
    assert other.post(resend_path, {'csrfmiddlewaretoken': other_form['csrfmiddlewaretoken']},
                      secure=True).status_code in (403, 404)
    response = guest.post(resend_path, {'csrfmiddlewaretoken': csrf, 'email': 'attacker@example.invalid'}, secure=True)
    assert response.status_code == 200, ('safe resend', response.status_code)
    event = admin.execute("""SELECT status,recipient_email,attempts FROM public.order_notification_events
        WHERE order_id=%s AND event_type='credentials_ready'""", (paid_id,)).fetchone()
    assert event == ('pending', recipient, 0), 'resend changed recipient snapshot or failed to queue'
    assert guest.post(resend_path, {'csrfmiddlewaretoken': csrf}, secure=True).status_code == 200
    assert admin.execute("SELECT status,recipient_email,attempts FROM public.order_notification_events "
                         "WHERE order_id=%s AND event_type='credentials_ready'", (paid_id,)).fetchone() == event
    admin.execute("""UPDATE public.order_notification_events SET status='failed',last_error='outcome_unknown',
        delivery_started_at=NULL WHERE order_id=%s AND event_type='credentials_ready'""", (paid_id,))
    response = guest.post(resend_path, {'csrfmiddlewaretoken': csrf}, secure=True)
    assert response.status_code == 409, 'unknown delivery outcome was retried'
    assert admin.execute("SELECT status,last_error,recipient_email FROM public.order_notification_events "
                         "WHERE order_id=%s AND event_type='credentials_ready'", (paid_id,)).fetchone() == (
                             'failed', 'outcome_unknown', recipient)

    def login(ip, principal):
        client = browser(ip)
        token = form(client, '/akun')['csrfmiddlewaretoken']
        response = client.post('/akun/login', {'csrfmiddlewaretoken': token, 'email': principal['email'],
                                              'password': data['password']}, secure=True)
        assert response.status_code == 302, ('login', response.status_code)
        return client

    claimant = login('127.0.10.4', data['raw'])
    rival = login('127.0.10.5', data['sha384-v1'])
    claimant_account = claimant.get('/akun/pesanan', secure=True)
    rival_account = rival.get('/akun/pesanan', secure=True)
    assert claimant_account.status_code == rival_account.status_code == 200
    assert b'Rp 27.378' in claimant_account.content and b'Rp 27.378' not in rival_account.content, 'account sidebar leaked another customer balance'
    from datetime import datetime, timezone
    from lhie_candidate.storefront import balance_cursor
    expected = datetime(2026, 1, 2, 2, 10, 11, 123456, tzinfo=timezone.utc)
    delta = expected - datetime(1970, 1, 1, tzinfo=timezone.utc)
    timestamp = (delta.days * 86400 + delta.seconds) * 1000000 + delta.microseconds
    assert balance_cursor.decode(data['balance_cursor'], data['raw']['id'], 'IDR') == (timestamp, 2, 42)
    assert balance_cursor.decode(data['balance_cursor'], data['sha384-v1']['id'], 'IDR') is None
    for currency in ('IDR', 'USDT'):
        first = claimant.get('/akun/saldo?currency=' + currency, secure=True)
        assert first.status_code == 200 and first.content.count(b'class="account-activity-row"') == 25, ('activity first page', currency, first.status_code)
        older = re.search(rb'href="([^"]+direction=older[^"]+)"', first.content)
        assert older is not None, ('activity older link', currency)
        older_url = unescape(older.group(1).decode())
        second = claimant.get(older_url, secure=True)
        assert second.status_code == 200 and second.content.count(b'class="account-activity-row"') == 2, ('activity older page', currency, second.status_code)
        newer = re.search(rb'href="([^"]+direction=newer[^"]+)"', second.content)
        assert newer is not None and claimant.get(unescape(newer.group(1).decode()), secure=True).content.count(b'class="account-activity-row"') == 25
        assert rival.get(older_url, secure=True).status_code == 404, 'foreign activity cursor accepted'
        assert claimant.get('/akun/saldo?currency=' + currency + '&direction=older&cursor=invalid', secure=True).status_code == 404
    claim_form = form(claimant, '/akun/klaim-pesanan')
    claim = {'csrfmiddlewaretoken': claim_form['csrfmiddlewaretoken'], 'invoice_number': data['guest']['invoice'],
             'access_key': data['guest']['key']}
    assert claimant.get('/akun/pesanan/' + paid_id, secure=True).status_code in (403, 404)
    assert claimant.post('/akun/klaim-pesanan', {k: v for k, v in claim.items() if k != 'csrfmiddlewaretoken'},
                         secure=True).status_code == 403
    claimed = claimant.post('/akun/klaim-pesanan', claim, secure=True)
    assert claimed.status_code == 302 and data['guest']['key'] not in claimed['Location']
    assert credential in claimant.get(claimed['Location'], secure=True).content
    completed = claimant.get('/akun/pesanan?status=completed', secure=True)
    waiting = claimant.get('/akun/pesanan?status=waiting', secure=True)
    foreign_list = rival.get('/akun/pesanan?status=completed', secure=True)
    assert completed.status_code == 200 and data['guest']['invoice'].encode() in completed.content
    assert data['guest']['invoice'].encode() not in waiting.content
    assert data['guest']['invoice'].encode() not in foreign_list.content
    assert claimant.post('/akun/klaim-pesanan', claim, secure=True).status_code == 302, 'same owner replay failed'
    rival_form = form(rival, '/akun/klaim-pesanan')
    rival_claim = rival.post('/akun/klaim-pesanan', {**claim, **rival_form}, secure=True)
    assert rival_claim.status_code in (400, 403, 404), 'another customer claimed owned order'
    assert rival.get('/akun/pesanan/' + paid_id, secure=True).status_code in (403, 404)
    assert admin.execute('SELECT customer_id FROM public.orders WHERE id=%s', (paid_id,)).fetchone()[0] == data['raw']['id']

    from django.conf import settings
    lock_client = browser('127.0.10.6')
    lock_product = fixtures['maintenance']['product_id']
    lock_form = form(lock_client, f'/shop/checkout/products/{lock_product}?quantity=1')
    token = lock_client.cookies['candidate_browser'].value
    message = ('checkout\0' + token + '\0' + lock_form['snapshot']).encode()
    checkout_key = hmac.new(settings.SECRET_KEY.encode(), message, hashlib.sha256).hexdigest()
    lock = 'storefront:checkout:' + checkout_key
    admin.execute('SELECT pg_advisory_lock(hashtextextended(%s,0))', (lock,))
    try:
        response = lock_client.post('/shop/checkout', checkout_fields(lock_form), secure=True)
        assert response.status_code == 409 and economics() == before, 'busy checkout crossed lock'
    finally:
        admin.execute('SELECT pg_advisory_unlock(hashtextextended(%s,0))', (lock,))
    accepted = lock_client.post('/shop/checkout', checkout_fields(lock_form), secure=True)
    assert accepted.status_code == 302, ('checkout after lock release', accepted.status_code)
    replay = lock_client.post('/shop/checkout', checkout_fields(lock_form), secure=True)
    assert replay.status_code == 302 and urlsplit(replay['Location']).path == urlsplit(accepted['Location']).path
    assert economics() == (before[0] + 1, before[1] + 1), 'lock retry created duplicate records'


def exercise_storefront(fixtures):
    from django.db import connections
    from django.test import Client, override_settings
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.payments.presentation import QrPresentation, render_payload
    from lhie_candidate.payments.routing import QrisPayments
    from lhie_candidate.readiness import verify_dependencies
    from lhie_candidate.runtime import application_key

    admin = admin_connection()
    verify_dependencies()
    directory = TemporaryDirectory(prefix='p21-synthetic-public-')
    (Path(directory.name) / 'qr-lhie.jpg').write_bytes(render_payload('Synthetic P21 non-payment image'))
    presentation = QrPresentation(public_storage=directory.name)
    owner = QrisPayments(presentation=presentation, encrypter=LaravelEncrypter(application_key()))
    client = Client(enforce_csrf_checks=True, HTTP_HOST='localhost', HTTP_ORIGIN='https://localhost',
                    raise_request_exception=False)
    fixture = fixtures['duplicate']
    review_path = f"/shop/checkout/products/{fixture['product_id']}?quantity=1&from=%2Fpilihan%2Fsynthetic-family"

    def count(table):
        return admin.execute('SELECT COUNT(*) FROM public.' + table).fetchone()[0]

    def review(path):
        response = client.get(path, secure=True)
        assert response.status_code == 200, ('review', response.status_code)
        data = inputs(response)
        assert data.get('snapshot') and data.get('csrfmiddlewaretoken'), 'Review form missing security fields'
        return data

    try:
        with override_settings(CANDIDATE_STOREFRONT_PAYMENT_SERVICES=lambda: owner):
            before = tuple(count(table) for table in ('customers', 'carts', 'orders', 'payments'))
            form = review(review_path)
            assert tuple(count(table) for table in ('customers', 'carts', 'orders', 'payments')) == before, 'GET review created business records'
            assert b'href="/pilihan/synthetic-family"' in client.get(review_path, secure=True).content
            unsafe = client.get(f"/shop/checkout/products/{fixture['product_id']}?from=%2F%2Fevil.example", secure=True)
            assert b'href="//evil.example"' not in unsafe.content
            assert ('href="/produk/' + str(fixture['product_id']) + '"').encode() in unsafe.content
            fields = {'snapshot': form['snapshot'], 'csrfmiddlewaretoken': form['csrfmiddlewaretoken'],
                      'buyer_name': 'Synthetic Buyer', 'payment_method': 'qris', 'terms_accepted': '1',
                      'email': 'p21-buyer@example.invalid', 'support_contact_channel': 'telegram',
                      'support_contact_value': 'p21buyer'}
            missing = client.post('/shop/checkout', {k: v for k, v in fields.items() if k != 'csrfmiddlewaretoken'}, secure=True)
            assert missing.status_code == 403, ('csrf', missing.status_code)
            invalid = client.post('/shop/checkout', {**fields, 'buyer_name': '!'}, secure=True)
            assert invalid.status_code == 422 and b'checkout-error-summary' in invalid.content, ('validation', invalid.status_code)
            assert b'href="/pilihan/synthetic-family"' in invalid.content, 'validation lost review return context'
            assert b'p21-buyer@example.invalid' in invalid.content and b'p21buyer' in invalid.content
            form = inputs(invalid)
            fields['snapshot'], fields['csrfmiddlewaretoken'] = form['snapshot'], form['csrfmiddlewaretoken']
            accepted = client.post('/shop/checkout', fields, secure=True)
            assert accepted.status_code == 302, ('checkout', accepted.status_code)
            location = accepted['Location']
            assert 'signature=' in location and 'p21-buyer' not in location and 'LHIE-' not in location
            invoice = client.get(location, secure=True)
            assert invoice.status_code == 200 and b'LHIE-' in invoice.content, ('invoice', invoice.status_code)
            qr_match = re.search(rb'<img src="([^"]+/qris\?[^"]+)"', invoice.content)
            assert qr_match is not None, 'Payable manual QR missing from invoice'
            qr = client.get(unescape(qr_match.group(1).decode()), secure=True)
            assert qr.status_code == 200 and qr['Content-Type'] in ('image/png', 'image/jpeg'), ('qris', qr.status_code)
            order_id = urlsplit(location).path.rsplit('/', 1)[1]
            row = admin.execute('SELECT credential_access_key_hash,notification_email,support_contact_value FROM public.orders WHERE id=%s', (order_id,)).fetchone()
            cipher = LaravelEncrypter(application_key())
            assert row[0] and cipher.decrypt_string(row[1]) == 'p21-buyer@example.invalid'
            assert cipher.decrypt_string(row[2]) == '@p21buyer'
            assert count('orders') == before[2] + 1 and count('payments') == before[3] + 1
            replay = client.post('/shop/checkout', fields, secure=True)
            assert replay.status_code == 302 and urlsplit(replay['Location']).path == urlsplit(location).path, ('replay', replay.status_code)
            assert count('orders') == before[2] + 1 and count('payments') == before[3] + 1
            assert client.get('/shop/orders/' + order_id, secure=True).status_code in (403, 404), 'unsigned invoice accepted'
            assert client.get(location + '&tampered=1', secure=True).status_code in (403, 404), 'tampered invoice accepted'
            pending_guest = Client(enforce_csrf_checks=True, HTTP_HOST='localhost', HTTP_ORIGIN='https://localhost',
                                   REMOTE_ADDR='127.0.20.1', raise_request_exception=False)
            key = re.search(rb'LHIE-[A-F0-9]{20}', invoice.content)
            assert key is not None, 'Recovery Key missing'
            access_form = inputs(pending_guest.get('/akses-pesanan', secure=True))
            invoice_number = admin.execute('SELECT invoice_number FROM public.orders WHERE id=%s', (order_id,)).fetchone()[0]
            unlocked = pending_guest.post('/akses-pesanan', {'csrfmiddlewaretoken': access_form['csrfmiddlewaretoken'],
                'invoice_number': invoice_number, 'access_key': key.group().decode()}, secure=True)
            assert unlocked.status_code in (400, 403), 'unpaid order accepted by paid-only Recovery Key access'

            stale_id = fixtures['stale']['product_id']
            stale = review(f'/shop/checkout/products/{stale_id}?quantity=1')
            old_price = admin.execute('SELECT price_amount FROM public.products WHERE id=%s', (stale_id,)).fetchone()[0]
            admin.execute('UPDATE public.products SET price_amount=%s WHERE id=%s', (old_price + 1, stale_id))
            try:
                rejected = client.post('/shop/checkout', {**fields, 'snapshot': stale['snapshot'],
                                       'csrfmiddlewaretoken': stale['csrfmiddlewaretoken']}, secure=True)
                assert rejected.status_code == 409 and b'checkout-error-summary' in rejected.content, ('stale price', rejected.status_code)
                assert count('orders') == before[2] + 1 and count('payments') == before[3] + 1
            finally:
                admin.execute('UPDATE public.products SET price_amount=%s WHERE id=%s', (old_price, stale_id))

            guest = Client(enforce_csrf_checks=True, HTTP_HOST='localhost', HTTP_ORIGIN='https://localhost',
                           raise_request_exception=False)
            recovery = guest.get('/akses-pesanan', secure=True)
            assert recovery.status_code == 200
            recovery_form = inputs(recovery)
            owner_data = fixtures['auth']['guest']
            unlock = guest.post('/akses-pesanan', {'csrfmiddlewaretoken': recovery_form['csrfmiddlewaretoken'],
                'invoice_number': owner_data['invoice'], 'access_key': owner_data['key']}, secure=True)
            assert unlock.status_code == 302 and owner_data['key'] not in unlock['Location'], ('recovery', unlock.status_code)
            owned = guest.get(unlock['Location'], secure=True)
            assert owned.status_code == 200, ('recovered invoice', owned.status_code)
            stranger = Client(HTTP_HOST='localhost', raise_request_exception=False)
            assert stranger.get(unlock['Location'], secure=True).status_code in (403, 404), 'foreign guest accessed order'
            assert guest.get('/akses-pesanan/' + order_id, secure=True).status_code in (403, 404), 'grant crossed orders'
            exercise_boundaries(fixtures, admin, fields, location, invoice, guest, recovery_form)
            cancel_match = re.search(rb'action="([^"]+/cancel\?[^"]+)"', invoice.content)
            cancel_url = unescape(cancel_match.group(1).decode())
            reserved = admin.execute('SELECT reserved_stock FROM public.products WHERE id=%s',
                                     (fixture['product_id'],)).fetchone()[0]
            cancelled = client.post(cancel_url, {'csrfmiddlewaretoken': fields['csrfmiddlewaretoken']}, secure=True)
            assert cancelled.status_code == 302 and urlsplit(cancelled['Location']).path == urlsplit(location).path
            assert admin.execute('SELECT order_status,payment_status FROM public.orders WHERE id=%s',
                                 (order_id,)).fetchone() == ('cancelled', 'failed')
            assert admin.execute('SELECT reserved_stock FROM public.products WHERE id=%s',
                                 (fixture['product_id'],)).fetchone()[0] == reserved - 1
            assert b'Pesanan dibatalkan' in client.get(cancelled['Location'], secure=True).content
            assert client.post(cancel_url, {'csrfmiddlewaretoken': fields['csrfmiddlewaretoken']}, secure=True).status_code == 409
            if os.environ.get('P21_BROWSER_SMOKE') == '1':
                browser_wait(fixtures['foreign']['product_id'], fixtures['stale']['product_id'])
        return {'review': 'no Customer, Cart, Order or Payment creation',
                'checkout': 'CSRF, session binding, stale price/stock/cart and locked retry/replay boundaries',
                'ownership': 'signed actions, paid recovery and claim isolation, safe resend snapshot and unknown refusal'}
    finally:
        admin.close()
        connections.close_all()
        directory.cleanup()


def browser_wait(product_id, second_product_id):
    from wsgiref.simple_server import make_server
    from django.contrib.staticfiles.handlers import StaticFilesHandler
    from django.core.wsgi import get_wsgi_application
    from django.db import connections
    from serve import BoundedServer, QuietHandler
    from PIL import Image
    from lhie_candidate.storefront import public_media
    public = TemporaryDirectory(prefix='p21-banner-')
    Image.new('RGB', (800, 400), '#edf4ff').save(Path(public.name) / 'banner.png')
    previous_root = public_media.PUBLIC_ROOT
    public_media.PUBLIC_ROOT = Path(public.name)
    admin = admin_connection()
    try:
        family_id = admin.execute("INSERT INTO public.product_families (name,slug,created_at,updated_at) "
                                  "VALUES ('Synthetic P21 family','synthetic-p21-family',now(),now()) RETURNING id").fetchone()[0]
        admin.execute('UPDATE public.products SET product_family_id=%s WHERE id IN (%s,%s)',
                      (family_id, product_id, second_product_id))
        admin.execute("UPDATE public.storefront_settings SET homepage_banners=%s::json "
                      "WHERE singleton_key='storefront'",
                      (json.dumps([{'path': 'banner.png', 'title': 'Synthetic P21 banner', 'link': '/shop'}]),))
    finally:
        admin.close()

    evidence = Path('/evidence')
    discard = open(os.devnull, 'w')
    server = make_server('0.0.0.0', 8080, StaticFilesHandler(get_wsgi_application()),
                         server_class=BoundedServer, handler_class=QuietHandler)
    server.discard = discard

    def serve():
        try:
            server.serve_forever(poll_interval=0.1)
        finally:
            connections.close_all()

    thread = threading.Thread(target=serve, daemon=True)
    thread.start()
    try:
        (evidence / 'browser-fixture.json').write_text(json.dumps({'product_id': product_id}))
        deadline = time.monotonic() + 900
        while not (evidence / 'browser-finished').exists():
            if time.monotonic() >= deadline:
                raise RuntimeError('Browser smoke did not finish within the disposable window')
            time.sleep(0.2)
        if (evidence / 'browser-finished').read_text().strip() != 'PASS':
            raise RuntimeError('Browser verification was not completed')
    finally:
        server.shutdown()
        thread.join(timeout=5)
        server.server_close()
        discard.close()
        public_media.PUBLIC_ROOT = previous_root
        public.cleanup()
