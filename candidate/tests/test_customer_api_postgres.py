"""Request-to-ledger Customer API proof on admitted disposable PostgreSQL/Redis."""
import json
import multiprocessing
import subprocess
import sys
from pathlib import Path
import time
import threading
from urllib.request import Request, urlopen
from wsgiref.simple_server import WSGIRequestHandler, make_server
from uuid import uuid4

from tests.test_checkout_postgres import admin_connection


def _request_process(secret, product_id, barrier, queue):
    from lhie_candidate.startup import configure_django
    configure_django()
    from django.db import connections
    from django.test import Client
    try:
        client = Client(HTTP_HOST='localhost', HTTP_AUTHORIZATION='Bearer ' + secret)
        payload = {'product_id': str(product_id), 'quantity': 2,
                   'preference': 'idr_only', 'expected_totals': {'IDR': '24000'}}
        barrier.wait(timeout=15)
        response = client.post('/api/v1/orders', data=json.dumps(payload), content_type='application/json',
                               HTTP_IDEMPOTENCY_KEY='p20-race')
        queue.put((response.status_code, response.json()))
    except Exception as exc:
        queue.put(('error', type(exc).__name__))
    finally:
        connections.close_all()

def _rotate_process(customer, nonce, start, queue):
    from lhie_candidate.startup import configure_django
    configure_django()
    from django.db import connection, connections
    from lhie_candidate.customer_api.credentials import rotate
    try:
        with connection.cursor() as cursor:
            cursor.execute('SELECT pg_backend_pid()')
            queue.put(('pid', cursor.fetchone()[0]))
        if not start.wait(timeout=15):
            raise RuntimeError('Missing rotation start')
        queue.put(('rotated', rotate(customer, 1234, nonce).take_secret()))
    except Exception as exc:
        queue.put(('error', type(exc).__name__))
    finally:
        connections.close_all()


def _http_balance(secret):
    from django.core.wsgi import get_wsgi_application
    from django.db import connections

    class QuietHandler(WSGIRequestHandler):
        def log_message(self, format, *args):
            pass

    server = make_server('127.0.0.1', 8080, get_wsgi_application(), handler_class=QuietHandler)
    server.timeout = 10

    def serve_one():
        try:
            server.handle_request()
        finally:
            connections.close_all()

    thread = threading.Thread(target=serve_one)
    thread.start()
    try:
        request = Request(f'http://127.0.0.1:{server.server_port}/api/v1/balance',
                          headers={'Authorization': 'Bearer ' + secret})
        with urlopen(request, timeout=5) as response:
            assert response.status == 200
            assert json.load(response)['data']['wallets'][0]['amount'] == '50000'
    finally:
        thread.join(timeout=10)
        server.server_close()
        assert not thread.is_alive()


def _supplier_pending(admin, customer):
    from django.test import Client, RequestFactory
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.customer_api import credentials
    from lhie_candidate.customer_api.http import endpoint
    from lhie_candidate.suppliers.capacity import refresh_products

    cipher = LaravelEncrypter('c' * 32)
    now = "CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'"
    with admin.transaction():
        admin.execute(f"""INSERT INTO public.supplier_currency_rates(currency,exchange_rate_idr,created_at,updated_at)
            VALUES ('USD',15000,{now},{now}) ON CONFLICT (currency) DO NOTHING""")
        rate = admin.execute("SELECT exchange_rate_idr FROM public.supplier_currency_rates WHERE currency='USD'").fetchone()[0]
        product = admin.execute(f"""INSERT INTO public.products(name,description,price_amount,stock,reserved_stock,is_active,created_at,updated_at)
            VALUES ('P20 synthetic supplier','Synthetic fixture',20000,0,0,true,{now},{now}) RETURNING id""").fetchone()[0]
        supplier = admin.execute(f"""INSERT INTO public.suppliers(name,provider,api_key,currency,currency_confirmed,
            exchange_rate_idr,balance_micros,reserved_balance_micros,is_active,synced_at,sync_expires_at,created_at,updated_at)
            VALUES ('P20 synthetic','warzone',%s,'USD',true,%s,10000000,0,true,{now},{now}+INTERVAL '5 minutes',{now},{now}) RETURNING id""",
            (cipher.encrypt_string('synthetic-never-live-key'), rate)).fetchone()[0]
        sku = admin.execute(f"""INSERT INTO public.supplier_catalog_items(supplier_id,external_id,name,currency,unit_price_micros,
            stock,orderable,requires_input,synced_at,created_at,updated_at)
            VALUES (%s,%s,'Synthetic SKU','USD',1000000,5,true,false,{now},{now},{now}) RETURNING id""", (supplier, str(uuid4()))).fetchone()[0]
        admin.execute(f"""INSERT INTO public.product_supplier_sources(product_id,supplier_catalog_item_id,priority,is_active,created_at,updated_at)
            VALUES (%s,%s,1,true,{now},{now})""", (product, sku))
        admin.execute(f"""INSERT INTO public.supplier_price_policies(product_id,mode,created_at,updated_at)
            VALUES (%s,'manual',{now},{now})""", (product,))
    clock = admin.execute('SELECT ' + now).fetchone()[0]
    refresh_products([product], clock)
    secret = credentials.generate(customer).take_secret()
    payload = {'product_id': str(product), 'quantity': 1, 'preference': 'idr_only', 'expected_totals': {'IDR': '20000'}}
    request = RequestFactory().post('/api/v1/orders', data=json.dumps(payload), content_type='application/json',
        HTTP_HOST='localhost', HTTP_AUTHORIZATION='Bearer ' + secret, HTTP_IDEMPOTENCY_KEY='p20-supplier')
    response = endpoint(request, 'orders', supplier_encrypter=cipher)
    assert response.status_code == 201, (response.status_code, json.loads(response.content))
    order = json.loads(response.content)['data']['id']
    assert admin.execute('SELECT state FROM public.supplier_fulfillments WHERE order_id=%s', (order,)).fetchone()[0] != 'delivered'
    client = Client(HTTP_HOST='localhost', HTTP_AUTHORIZATION='Bearer ' + secret)
    delivery = client.get(f'/api/v1/orders/{order}/delivery')
    assert delivery.status_code == 200, (delivery.status_code, delivery.json())
    assert delivery.json()['data']['state'] == 'not_ready' and delivery.json()['data']['units'] == []


def _large_delivery(admin, fixture):
    from django.test import Client
    from lhie_candidate.customer_api import credentials

    secret = credentials.generate(fixture['customer_id']).take_secret()
    client = Client(HTTP_HOST='localhost', HTTP_AUTHORIZATION='Bearer ' + secret)
    response = client.post('/api/v1/orders', data=json.dumps({
        'product_id': str(fixture['product_id']), 'quantity': 100, 'preference': 'idr_only',
        'expected_totals': {'IDR': '100'}}), content_type='application/json', HTTP_IDEMPOTENCY_KEY='p20-large')
    assert response.status_code == 201, (response.status_code, response.json())
    order_id = response.json()['data']['id']
    units, numbers, cursor, pages = [], [], None, 0
    while True:
        response = client.get(f'/api/v1/orders/{order_id}/delivery', {'cursor': cursor} if cursor else {})
        assert response.status_code == 200, (response.status_code, response.json())
        assert len(response.content) <= 1_048_576
        page = response.json()['data']
        assert page['state'] == 'ready' and page['progress']['released_units'] == 100
        assert all(unit['format'] == 'structured_account' and len(unit['password']) == 1024
                   and len(unit['access_value']) == 3500 and len(unit['tutorial']) == 2000
                   for unit in page['units'])
        units.extend(unit['id'] for unit in page['units'])
        numbers.extend(unit['number'] for unit in page['units'])
        pages += 1
        cursor = page['pagination']['next_cursor']
        assert page['pagination']['has_more'] == (cursor is not None)
        if cursor is None:
            break
        assert pages < 10
    assert pages > 1 and len(set(units)) == 100 and numbers == list(range(1, 101))
    assert admin.execute("SELECT count(*),sum(amount) FROM public.store_balance_entries WHERE source_type='order' AND source_id=%s AND kind='purchase_debit'", (order_id,)).fetchone() == (1, 100)
    no_token = client.post('/api/v1/orders', data=json.dumps({
        'product_id': str(fixture['product_id']), 'quantity': 1, 'preference': 'idr_only',
        'expected_totals': {'IDR': '1'}}), content_type='application/json', HTTP_IDEMPOTENCY_KEY='p20-no-token')
    assert no_token.status_code == 201, (no_token.status_code, no_token.json())
    no_token_id = no_token.json()['data']['id']
    no_token_delivery = client.get(f'/api/v1/orders/{no_token_id}/delivery')
    assert no_token_delivery.status_code == 200
    account = no_token_delivery.json()['data']['units'][0]
    assert account['email'] == 'p20-no-token@example.invalid' and account['access_method'] == 'none'
    assert admin.execute('SELECT count(*) FROM public.credential_identities WHERE product_stock_item_id=%s',
                         (int(account['id']),)).fetchone() == (0,)


def exercise_api(fixtures):
    from django.db import connections, transaction
    from django.test import Client
    from lhie_candidate.customer_api import credentials
    from lhie_candidate.customer_api.admission import Admission, ApiFailure
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.customer_api.delivery import _decode
    from lhie_candidate.runtime import application_key
    from lhie_candidate.readiness import verify_dependencies

    admin = admin_connection()
    case = fixtures['python']
    customer, product = case['customer_id'], case['product_id']
    try:
        verify_dependencies()
        assert _decode(fixtures['p20_legacy_cursor'], LaravelEncrypter(application_key())) == 12345
        admin.execute("UPDATE public.customer_api_settings SET enabled=true WHERE singleton_key='customer_api'")
        secret = credentials.generate(customer).take_secret()
        permit_customer = fixtures['maintenance']['customer_id']
        permit_secret = credentials.generate(permit_customer).take_secret()
        permit_principal = credentials.authenticate('Bearer ' + permit_secret)
        admission = Admission(application_key())
        first = admission.authenticated(permit_principal, mutation=True)['token']
        second = admission.authenticated(permit_principal, mutation=True)['token']
        admission.release(permit_principal, 'not-the-permit')
        try:
            admission.authenticated(permit_principal, mutation=True)
        except ApiFailure as error:
            assert (error.status, error.code) == (429, 'rate_limited')
        else:
            raise AssertionError('A foreign permit release increased concurrency')
        permit_key = admission._key('inflight', str(permit_customer))
        admission._eval("return redis.call('ZADD',KEYS[1],0,ARGV[1])", [permit_key], [first])
        replacement = admission.authenticated(permit_principal, mutation=True)['token']
        admission.release(permit_principal, first)
        try:
            admission.authenticated(permit_principal, mutation=True)
        except ApiFailure as error:
            assert (error.status, error.code) == (429, 'rate_limited')
        else:
            raise AssertionError('An expired permit released a replacement')
        admission.release(permit_principal, second)
        admission.release(permit_principal, replacement)
        _http_balance(secret)
        foreign_secret = fixtures['p20_legacy_key']
        client = Client(HTTP_HOST='localhost', HTTP_AUTHORIZATION='Bearer ' + secret)
        foreign = Client(HTTP_HOST='localhost', HTTP_AUTHORIZATION='Bearer ' + foreign_secret)
        assert foreign.get('/api/v1/balance').status_code == 200
        response = client.get('/api/v1/balance')
        assert response.status_code == 200, (response.status_code, response.json())
        wallets = response.json()['data']['wallets']
        assert wallets[0]['currency'] == 'IDR' and wallets[0]['amount'] == '50000', wallets
        response = client.get(f'/api/v1/products/{product}?quantity=2')
        assert response.status_code == 200, (response.status_code, response.json())
        assert response.json()['data']['quotes'][0]['total'] == '24000', response.json()
        assert client.get('/api/v1/products?limit=2').status_code == 200
        assert client.get('/api/v1/products?limit=bad').status_code == 422
        before = admin.execute('SELECT count(*) FROM public.customer_api_order_claims').fetchone()[0]
        admin.execute("UPDATE public.customer_api_settings SET enabled=false WHERE singleton_key='customer_api'")
        response = client.post('/api/v1/orders', data='{}', content_type='application/json', HTTP_IDEMPOTENCY_KEY='disabled')
        assert (response.status_code, response.json()['error']['code']) == (503, 'api_maintenance')
        assert admin.execute('SELECT count(*) FROM public.customer_api_order_claims').fetchone()[0] == before
        try:
            credentials.prepare_rotation(customer, 1234)
        except ApiFailure as error:
            assert (error.status, error.code) == (503, 'api_maintenance')
        else:
            raise AssertionError('Disabled API allowed a key action')
        admin.execute("UPDATE public.customer_api_settings SET enabled=true WHERE singleton_key='customer_api'")
        connections.close_all()
        context = multiprocessing.get_context('spawn')
        barrier, queue = context.Barrier(3), context.Queue()
        workers = [context.Process(target=_request_process, args=(secret, product, barrier, queue)) for _ in range(3)]
        for worker in workers:
            worker.start()
        try:
            responses = [queue.get(timeout=30) for _ in workers]
            for worker in workers:
                worker.join(timeout=10)
                assert worker.exitcode == 0
        finally:
            for worker in workers:
                if worker.is_alive():
                    worker.kill()
                    worker.join()
            queue.close()
        assert all(status in (200, 201, 409, 429) for status, _ in responses), responses
        accepted = [body for status, body in responses if status in (200, 201)]
        assert accepted, responses
        order_id = accepted[0]['data']['id']
        assert all(body['data']['id'] == order_id for body in accepted)
        assert admin.execute("SELECT count(*) FROM public.orders WHERE api_order_claim_id IS NOT NULL AND customer_id=%s", (customer,)).fetchone() == (1,)
        assert admin.execute("SELECT count(*),sum(amount) FROM public.store_balance_entries WHERE source_type='order' AND source_id=%s AND kind='purchase_debit'", (order_id,)).fetchone() == (1, 24000)
        assert admin.execute('SELECT count(*) FROM public.product_stock_items s JOIN public.order_items i ON i.id=s.order_item_id WHERE i.order_id=%s', (order_id,)).fetchone() == (2,)
        payload = {'product_id': str(product), 'quantity': 2, 'preference': 'idr_only', 'expected_totals': {'IDR': '24000'}}
        response = client.post('/api/v1/orders', data=json.dumps(payload), content_type='application/json', HTTP_IDEMPOTENCY_KEY='p20-race')
        assert response.status_code == 200 and response.json()['data']['id'] == order_id, (response.status_code, response.json())
        payload['quantity'] = 1
        response = client.post('/api/v1/orders', data=json.dumps(payload), content_type='application/json', HTTP_IDEMPOTENCY_KEY='p20-race')
        assert (response.status_code, response.json()['error']['code']) == (409, 'idempotency_conflict')
        assert foreign.get(f'/api/v1/orders/{order_id}/delivery').status_code == 404
        response = client.get(f'/api/v1/orders/{order_id}/delivery?limit=1')
        assert response.status_code == 200, (response.status_code, response.json())
        page = response.json()['data']
        assert page['state'] == 'ready' and len(page['units']) == 1 and page['pagination']['has_more'], page
        cursor = page['pagination']['next_cursor']
        second = client.get(f'/api/v1/orders/{order_id}/delivery', {'limit': '1', 'cursor': cursor})
        assert second.status_code == 200, (second.status_code, second.json())
        following = second.json()['data']
        assert following['state'] == 'ready' and len(following['units']) == 1 and not following['pagination']['has_more']
        assert page['units'][0]['id'] != following['units'][0]['id']
        repeat = client.get(f'/api/v1/orders/{order_id}/delivery', {'limit': '1', 'cursor': cursor})
        assert repeat.json()['data']['units'] == following['units']
        assert client.get(f'/api/v1/orders/{order_id}/delivery', {'cursor': cursor[:-1] + ('A' if cursor[-1] != 'A' else 'B')}).status_code == 422
        from lhie_candidate.receipt.source import ReceiptSource, ReceiptUnavailable
        source = ReceiptSource(LaravelEncrypter(application_key()))
        chat = admin.execute('SELECT telegram_id FROM public.customers WHERE id=%s', (customer,)).fetchone()[0]
        payment_id = admin.execute('SELECT id FROM public.payments WHERE order_id=%s', (order_id,)).fetchone()[0]
        review_id = admin.execute('''INSERT INTO public.manual_fulfillment_reviews(order_id,payment_id,amount,currency,reason,state,created_at,updated_at)
            VALUES (%s,%s,24000,'IDR','local_credentials_missing','pending',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) RETURNING id''',
            (order_id, payment_id)).fetchone()[0]
        reviewed = client.get(f'/api/v1/orders/{order_id}/delivery', {'limit': '1', 'cursor': cursor})
        assert reviewed.status_code == 200 and reviewed.json()['data']['state'] == 'ready'
        assert reviewed.json()['data']['units'] == following['units']
        assert len(source.load(order_id, chat)['groups'][0]['accounts']) == 2
        try:
            source.load(order_id, chat, receipt=True)
        except ReceiptUnavailable:
            pass
        else:
            raise AssertionError('Open review allowed automatic receipt delivery')
        admin.execute("UPDATE public.manual_fulfillment_reviews SET state='fulfilled' WHERE id=%s", (review_id,))
        # Historical retirement is a stock-item state, not a change to the stock projection or financial history.
        admin.execute("UPDATE public.product_stock_items SET retired_at=CURRENT_TIMESTAMP,retired_reason='refunded_after_delivery' WHERE id=%s",
                      (int(page['units'][0]['id']),))
        remaining = client.get(f'/api/v1/orders/{order_id}/delivery', {'limit': '1', 'cursor': cursor})
        assert remaining.status_code == 200, (remaining.status_code, remaining.json())
        remaining_page = remaining.json()['data']
        assert remaining_page['state'] == 'partial' and remaining_page['units'][0]['number'] == 1
        assert remaining_page['units'][0]['id'] == following['units'][0]['id']
        assert len(source.load(order_id, chat)['groups'][0]['accounts']) == 1
        try:
            source.load(order_id, chat, receipt=True)
        except ReceiptUnavailable:
            pass
        else:
            raise AssertionError('Partial allocation allowed automatic receipt delivery')
        other_product = fixtures['foreign']['product_id']
        foreign_order = foreign.post('/api/v1/orders', data=json.dumps({
            'product_id': str(other_product), 'quantity': 2, 'preference': 'idr_only',
            'expected_totals': {'IDR': '30000'}}), content_type='application/json',
            HTTP_IDEMPOTENCY_KEY='p20-foreign')
        assert foreign_order.status_code == 201, (foreign_order.status_code, foreign_order.json())
        foreign_order_id = foreign_order.json()['data']['id']
        assert foreign.get(f'/api/v1/orders/{foreign_order_id}/delivery', {'cursor': cursor}).status_code == 422
        first_foreign_page = foreign.get(f'/api/v1/orders/{foreign_order_id}/delivery?limit=1')
        assert first_foreign_page.status_code == 200
        foreign_cursor = first_foreign_page.json()['data']['pagination']['next_cursor']
        assert foreign_cursor
        foreign_payment = admin.execute('SELECT id FROM public.payments WHERE order_id=%s', (foreign_order_id,)).fetchone()[0]
        admin.execute('''INSERT INTO public.manual_fulfillment_reviews(order_id,payment_id,amount,currency,reason,state,created_at,updated_at)
            VALUES (%s,%s,30000,'IDR','late_payment','pending',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)''', (foreign_order_id, foreign_payment))
        reviewed = foreign.get(f'/api/v1/orders/{foreign_order_id}/delivery', {'cursor': foreign_cursor})
        assert reviewed.status_code == 200 and reviewed.json()['data']['state'] == 'not_ready'
        assert reviewed.json()['data']['units'] == []
        principal = credentials.authenticate('Bearer ' + secret)
        confirmation = credentials.prepare_rotation(customer, 1234)
        start, rotation_results = context.Event(), context.Queue()
        connections.close_all()
        rotator = context.Process(target=_rotate_process, args=(customer, confirmation, start, rotation_results))
        rotator.start()
        try:
            label, pid = rotation_results.get(timeout=15)
            assert label == 'pid'
            with transaction.atomic():
                principal.revalidate_for_mutation()
                start.set()
                deadline = time.monotonic() + 0.8
                blocked = False
                while time.monotonic() < deadline:
                    state = admin.execute("SELECT wait_event_type FROM pg_stat_activity WHERE pid=%s", (pid,)).fetchone()
                    if state and state[0] == 'Lock':
                        blocked = True
                        break
                    time.sleep(0.01)
                assert blocked, 'Rotation did not wait on the mutation fence'
            label, rotated = rotation_results.get(timeout=15)
            assert label == 'rotated', (label, rotated)
            rotator.join(timeout=10)
            assert rotator.exitcode == 0
        finally:
            if rotator.is_alive():
                rotator.kill()
                rotator.join()
            rotation_results.close()
        with transaction.atomic():
            try:
                principal.revalidate_for_mutation()
            except ApiFailure as exc:
                assert (exc.status, exc.code) == (401, 'unauthenticated')
            else:
                raise AssertionError('Revoked principal passed mutation fence')
        assert client.get('/api/v1/balance').status_code == 401
        new_client = Client(HTTP_HOST='localhost', HTTP_AUTHORIZATION='Bearer ' + rotated)
        payload['quantity'] = 2
        response = new_client.post('/api/v1/orders', data=json.dumps(payload), content_type='application/json', HTTP_IDEMPOTENCY_KEY='p20-race')
        assert response.status_code == 200 and response.json()['data']['id'] == order_id
        _supplier_pending(admin, fixtures['last_stock'][0]['customer_id'])
        _large_delivery(admin, fixtures['p20_large'])
        usd_case = fixtures['p16']['purchase']
        usd_secret = credentials.generate(usd_case['customer_id']).take_secret()
        usd_client = Client(HTTP_HOST='localhost', HTTP_AUTHORIZATION='Bearer ' + usd_secret)
        usd_response = usd_client.post('/api/v1/orders', data=json.dumps({
            'product_id': str(usd_case['product_id']), 'quantity': 1, 'preference': 'usd_only',
            'expected_totals': {'USD': '1.000001'}}), content_type='application/json',
            HTTP_IDEMPOTENCY_KEY='p20-usd')
        assert usd_response.status_code == 201, (usd_response.status_code, usd_response.json())
        assert (usd_response.json()['data']['currency'], usd_response.json()['data']['amount']) == ('USD', '1.000001')
        wallets = usd_client.get('/api/v1/balance').json()['data']['wallets']
        assert wallets[1]['currency'] == 'USD' and wallets[1]['amount'] == '0.499999'
        assert admin.execute("""SELECT count(*) FROM public.customer_usdt_balance_entries e
            JOIN public.customer_usdt_balance_accounts a ON a.id=e.customer_usdt_balance_account_id
            WHERE a.customer_id=%s AND e.kind='purchase_debit'""", (usd_case['customer_id'],)).fetchone() == (1,)
        command = subprocess.run([sys.executable, str(Path(__file__).parents[1] / 'customer_api_usage.py'), '--once'],
                                 capture_output=True, text=True, timeout=30, check=True)
        assert json.loads(command.stdout)['processed'] > 0
        assert int(credentials.summary(customer)['account_request_count']) > 0
        return {'http_catalog_balance': 'passed', 'api_order_race_and_replay': 'passed',
                'delivery_owned_pagination': 'passed', 'rotation_and_disabled_gate': 'passed',
                'qualified_synthetic_supplier_pending': 'passed', 'large_paged_delivery': 'passed',
                'usd_purchase': 'passed', 'usage_drain': 'passed'}
    finally:
        connections.close_all()
        admin.close()
