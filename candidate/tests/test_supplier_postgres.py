"""P18 changed-flow proof, run only by the admitted disposable checkout smoke."""
from datetime import timedelta
import multiprocessing
from uuid import uuid4


def _competing_checkout(case, key, barrier, queue):
    from django.db import connections
    from lhie_candidate.startup import configure_django
    configure_django()
    from lhie_candidate.checkout.errors import CheckoutError
    from lhie_candidate.checkout.service import CheckoutRequest, CheckoutService
    from lhie_candidate.compat.crypto import LaravelEncrypter
    try:
        service = CheckoutService(supplier_encrypter=LaravelEncrypter('c' * 32))
        request = CheckoutRequest(case['customer'], case['cart'], key,
                                  service.quote_cart(case['customer'], case['cart']))
        barrier.wait(timeout=15)
        try:
            result = service.checkout(request)
            queue.put(('accepted', result.order_id))
        except CheckoutError as error:
            queue.put(('rejected', error.code))
    except Exception as error:
        queue.put(('error', type(error).__name__))
    finally:
        connections.close_all()


def _competing_purchase(fulfillment_id, barrier, queue):
    from django.db import connections
    from lhie_candidate.startup import configure_django
    configure_django()
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.suppliers.acquisition import SupplierAcquisition
    from lhie_candidate.suppliers.transport import OrderResult, SupplierConfig
    class Client:
        config = SupplierConfig('warzone', 'USD', 'synthetic-never-live-key', True, True)
        def purchase(self, product_id, quantity, reference):
            queue.put(('dispatch', reference))
            return OrderResult('delivered', 'synthetic-race-order', quantity, 1000000,
                               ('synthetic-race-credential',), None, True, True)
    try:
        barrier.wait(timeout=15)
        result = SupplierAcquisition(LaravelEncrypter('c' * 32), lambda *_: Client()).process(fulfillment_id)
        queue.put(('processed', result))
    except Exception as error:
        queue.put(('error', type(error).__name__))
    finally:
        connections.close_all()


def _competing_warranty(customer, public_id, barrier, queue):
    from django.db import connections
    from lhie_candidate.startup import configure_django
    configure_django()
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.suppliers.warranty import HistoricalWarranty
    try:
        barrier.wait(timeout=15)
        result = HistoricalWarranty(encrypter=LaravelEncrypter('c' * 32)).choose_refund(customer, public_id)
        queue.put(('refund', (str(result['id']), result['state'], result['amount'])))
    except Exception as error:
        queue.put(('error', type(error).__name__))
    finally:
        connections.close_all()


def _race(target, arguments, *, messages=None):
    from django.db import connections
    connections.close_all()
    context = multiprocessing.get_context('spawn')
    barrier, queue = context.Barrier(len(arguments) + 1), context.Queue()
    children = [context.Process(target=target, args=(*args, barrier, queue)) for args in arguments]
    for child in children:
        child.start()
    try:
        barrier.wait(timeout=15)
        outcomes = [queue.get(timeout=30) for _ in range(messages or len(children))]
        for child in children:
            child.join(timeout=15)
            assert child.exitcode == 0
        assert not any(status == 'error' for status, _ in outcomes), outcomes
        return outcomes
    finally:
        for child in children:
            if child.is_alive():
                child.kill()
                child.join()
        queue.close()


def exercise_supplier_protocol(fixtures):
    from lhie_candidate.checkout.errors import CheckoutError
    from lhie_candidate.checkout.service import CheckoutRequest, CheckoutService
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.receipt.source import ReceiptSource, ReceiptUnavailable
    from lhie_candidate.suppliers.acquisition import SupplierAcquisition
    from lhie_candidate.suppliers.capacity import refresh_products
    from lhie_candidate.suppliers.transport import OrderResult
    from tests.test_checkout_postgres import admin_connection
    from lhie_candidate.readiness import verify_dependencies

    admin = admin_connection()
    cipher = LaravelEncrypter('c' * 32)
    now_sql = "CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'"

    class Clock:
        def __init__(self):
            self.value = admin.execute('SELECT ' + now_sql).fetchone()[0]
        def __call__(self):
            return self.value

    clock = Clock()
    customers = [fixtures['last_stock'][0]['customer_id'], fixtures['last_stock'][1]['customer_id']]
    race_customers = [fixtures['maintenance']['customer_id'], fixtures['rollback']['customer_id']]
    retirement_customer = fixtures['foreign']['customer_id']

    def seed(customer, quantity, *, stock=10):
        with admin.transaction():
            admin.execute(f"""INSERT INTO public.supplier_currency_rates(currency,exchange_rate_idr,created_at,updated_at)
                VALUES ('USD',15000,{now_sql},{now_sql}) ON CONFLICT (currency) DO NOTHING""")
            rate = admin.execute("SELECT exchange_rate_idr FROM public.supplier_currency_rates WHERE currency='USD'").fetchone()[0]
            product = admin.execute(f"""INSERT INTO public.products(name,description,price_amount,stock,reserved_stock,is_active,created_at,updated_at)
                VALUES (%s,'Synthetic supplier fixture',20000,0,0,true,{now_sql},{now_sql}) RETURNING id""", ('P18 product ' + str(uuid4()),)).fetchone()[0]
            supplier = admin.execute(f"""INSERT INTO public.suppliers(name,provider,api_key,currency,currency_confirmed,
                exchange_rate_idr,balance_micros,reserved_balance_micros,is_active,synced_at,sync_expires_at,created_at,updated_at)
                VALUES ('P18 synthetic','warzone',%s,'USD',true,%s,10000000,0,true,{now_sql},{now_sql}+INTERVAL '5 minutes',{now_sql},{now_sql}) RETURNING id""", (cipher.encrypt_string('synthetic-never-live-key'), rate)).fetchone()[0]
            sku = admin.execute(f"""INSERT INTO public.supplier_catalog_items(supplier_id,external_id,name,currency,unit_price_micros,
                stock,orderable,requires_input,synced_at,created_at,updated_at)
                VALUES (%s,%s,'Synthetic SKU','USD',1000000,%s,true,false,{now_sql},{now_sql},{now_sql}) RETURNING id""", (supplier, str(uuid4()), stock)).fetchone()[0]
            admin.execute(f"""INSERT INTO public.product_supplier_sources(product_id,supplier_catalog_item_id,priority,is_active,created_at,updated_at)
                VALUES (%s,%s,1,true,{now_sql},{now_sql})""", (product, sku))
            admin.execute(f"""INSERT INTO public.supplier_price_policies(product_id,mode,created_at,updated_at)
                VALUES (%s,'manual',{now_sql},{now_sql})""", (product,))
            cart = admin.execute(f"""INSERT INTO public.carts(customer_id,status,expires_at,created_at,updated_at)
                VALUES (%s,'active',{now_sql}+INTERVAL '1 day',{now_sql},{now_sql}) RETURNING id""", (customer,)).fetchone()[0]
            admin.execute(f"""INSERT INTO public.cart_items(cart_id,product_id,quantity,created_at,updated_at)
                VALUES (%s,%s,%s,{now_sql},{now_sql})""", (cart, product, quantity))
        refresh_products([product], clock())
        return dict(customer=customer, product=product, supplier=supplier, sku=sku, cart=cart)

    def checkout(case, key):
        service = CheckoutService(supplier_encrypter=cipher)
        quote = service.quote_cart(case['customer'], case['cart'])
        return service.checkout(CheckoutRequest(case['customer'], case['cart'], key, quote))

    def expect_unavailable(call):
        try:
            call()
        except ReceiptUnavailable:
            return
        raise AssertionError('Partial, foreign or unfulfilled allocation was exposed')

    class FakeClient:
        def __init__(self, outcomes):
            from lhie_candidate.suppliers.transport import SupplierConfig
            self.config = SupplierConfig('warzone', 'USD', 'synthetic-never-live-key', True, True)
            self.outcomes = outcomes
            self.calls = []
        def purchase(self, sku, quantity, reference):
            self.calls.append(('post', sku, quantity, reference))
            return self.outcomes.pop(0)
        def order(self, order_id, sku, reference):
            self.calls.append(('get', order_id, sku, reference))
            return self.outcomes.pop(0)

    try:
        verify_dependencies()
        from copy import deepcopy
        from django.db import connections
        from lhie_candidate.suppliers.operator_privileges import OperatorPrivilegeError, verify_operator_privileges
        operator = deepcopy(connections.databases['default'])
        operator.update(USER='candidate_supplier_operator', PASSWORD='candidate-local-only')
        connections.databases['supplier_operator'] = operator
        assert verify_operator_privileges(using='supplier_operator', role='candidate_supplier_operator')
        admin.execute('GRANT SELECT (secret) ON public.product_stock_items TO candidate_supplier_operator')
        try:
            verify_operator_privileges(using='supplier_operator', role='candidate_supplier_operator')
        except OperatorPrivilegeError:
            pass
        else:
            raise AssertionError('Operator role unexpectedly admitted credential access')
        finally:
            admin.execute('REVOKE SELECT (secret) ON public.product_stock_items FROM candidate_supplier_operator')
        from django.urls import Resolver404, resolve
        from lhie_candidate.suppliers.transport import HttpResponse, SupplierClient, SupplierConfig
        class Budget:
            def eval(self, *args):
                return 1
        sent = []
        def redirect(**request):
            sent.append(request)
            return HttpResponse(302, b'', {'Location': 'http://127.0.0.1/private'})
        client = SupplierClient(SupplierConfig('miraiapi', 'VND', 'synthetic-secret', True, True), redirect, Budget())
        rejected = client.purchase('https://127.0.0.1/private', 1, str(uuid4()))
        assert rejected.status == 'rejected' and not sent
        outcome = client.purchase('product-1', 1, str(uuid4()))
        assert outcome.status == 'ambiguous' and len(sent) == 1
        assert sent[0]['url'] == 'https://api.miraiapi.com/api/reseller/v1/orders'
        assert sent[0]['allow_redirects'] is False and sent[0]['max_bytes'] > 0
        assert 'synthetic-secret' not in repr(outcome) and 'synthetic-secret' not in repr(client.config)
        rejected_client = SupplierClient(client.config, lambda **_: HttpResponse(422, b'{}'), Budget())
        conflict_client = SupplierClient(client.config, lambda **_: HttpResponse(409, b'{"message":"unknown conflict"}'), Budget())
        assert rejected_client.purchase('product-1', 1, str(uuid4())).status == 'rejected'
        assert conflict_client.purchase('product-1', 1, str(uuid4())).status == 'ambiguous'
        invalid_status = b'{"success":true,"data":{"order_id":"synthetic-order","package_code":"product-1","status":"cancelled","quantity":1,"total_vnd":"100","redeem_codes":[]}}'
        status_client = SupplierClient(client.config, lambda **_: HttpResponse(200, invalid_status), Budget())
        status_result = status_client.purchase('product-1', 1, str(uuid4()))
        assert status_result.identity_trusted and status_result.order_id == 'synthetic-order'
        assert status_result.quantity is None and status_result.cost_micros is None
        for path in ('/api/webhook/supplier', '/webhook/supplier'):
            try:
                resolve(path)
            except Resolver404:
                continue
            raise AssertionError('Retired supplier webhook unexpectedly active')
        case = seed(customers[0], 2)
        from lhie_candidate.suppliers.catalog import sync
        from lhie_candidate.suppliers.transport import CatalogSnapshot, Product
        external_id = admin.execute('SELECT external_id FROM public.supplier_catalog_items WHERE id=%s', (case['sku'],)).fetchone()[0]
        class Catalog:
            config = SupplierConfig('warzone', 'USD', 'synthetic-never-live-key', True, True)
            def snapshot(self):
                return CatalogSnapshot(10000000, 'USD',
                    (Product(external_id, 'Synthetic SKU', None, 1000000, 10, True, False),
                     Product('p18-import', 'Private catalog draft', None, 1000000, 5, True, False)))
        assert sync(case['supplier'], Catalog(), clock(), encrypter=cipher, using='supplier_operator')
        order = checkout(case, 'p18-complete')
        assert (order.order_status, order.payment_status) == ('processing', 'paid')
        fulfillment = admin.execute('SELECT id FROM public.supplier_fulfillments WHERE order_id=%s', (order.order_id,)).fetchone()[0]
        clock.value = admin.execute('SELECT ' + now_sql).fetchone()[0] + timedelta(seconds=1)
        source = ReceiptSource(cipher)
        chat = admin.execute('SELECT telegram_id FROM public.customers WHERE id=%s', (case['customer'],)).fetchone()[0]
        expect_unavailable(lambda: source.load(order.order_id, chat))
        expect_unavailable(lambda: source.load_customer(order.order_id, case['customer']))
        fake = FakeClient([
            OrderResult('pending', 'synthetic-order', 2, 2000000, ('synthetic-unit-a',), None, True, True),
            OrderResult('delivered', 'synthetic-order', 2, 2000000, ('synthetic-unit-a', 'synthetic-unit-b'), None, True, True),
        ])
        from lhie_candidate.suppliers.worker import SupplierRecoveryWorker
        acquisition = SupplierAcquisition(cipher, lambda supplier, key: fake, clock=clock)
        worker = SupplierRecoveryWorker(acquisition)
        assert fulfillment in acquisition.due_ids()
        assert worker.run(max_iterations=1)['counters']['sweep'] == 1
        assert admin.execute('SELECT state FROM public.supplier_fulfillments WHERE id=%s', (fulfillment,)).fetchone()[0] == 'awaiting_delivery'
        expect_unavailable(lambda: source.load_customer(order.order_id, case['customer']))
        expect_unavailable(lambda: source.load(order.order_id, chat))
        clock.value += timedelta(minutes=2)
        assert worker.run(max_iterations=1)['counters']['sweep'] == 2
        assert [call[0] for call in fake.calls] == ['post', 'get']
        assert admin.execute('SELECT state FROM public.supplier_fulfillments WHERE id=%s', (fulfillment,)).fetchone()[0] == 'delivered'
        assert admin.execute('SELECT order_status FROM public.orders WHERE id=%s', (order.order_id,)).fetchone()[0] == 'completed'
        assert admin.execute('SELECT balance_micros,reserved_balance_micros FROM public.suppliers WHERE id=%s', (case['supplier'],)).fetchone() == (8000000, 0)
        snapshot, expected_cost, actual_cost, supplier_id, catalog_id = admin.execute('''SELECT f.sources_snapshot,
            p.expected_cost_micros,p.actual_cost_micros,p.supplier_id,p.supplier_catalog_item_id
            FROM public.supplier_fulfillments f JOIN public.supplier_purchases p ON p.id=f.current_purchase_id
            WHERE f.id=%s''', (fulfillment,)).fetchone()
        assert (expected_cost, actual_cost, supplier_id, catalog_id) == (2000000, 2000000, case['supplier'], case['sku'])
        assert cipher.decrypt_json(snapshot)[0]['expected_cost_micros'] == 2000000
        secrets = admin.execute('''SELECT secret FROM public.product_stock_items WHERE supplier_purchase_id=(
            SELECT current_purchase_id FROM public.supplier_fulfillments WHERE id=%s) ORDER BY supplier_unit_index''', (fulfillment,)).fetchall()
        assert len(secrets) == 2 and all('synthetic-unit-' not in row[0] for row in secrets)
        telegram = source.load(order.order_id, chat)
        api = source.load_customer(order.order_id, case['customer'])
        assert telegram['items'] == api['items']
        expect_unavailable(lambda: source.load_customer(order.order_id, customers[1]))
        expect_unavailable(lambda: source.load(order.order_id, '0'))
        owner = admin.execute(f"""INSERT INTO public.users(name,email,password,role,created_at,updated_at)
            VALUES ('Synthetic Owner',%s,'synthetic','owner',{now_sql},{now_sql}) RETURNING id""",
            ('p18-' + str(uuid4()) + '@example.invalid',)).fetchone()[0]
        from lhie_candidate.suppliers.management import import_draft, preview_price
        import_sku = admin.execute("SELECT id FROM public.supplier_catalog_items WHERE supplier_id=%s AND external_id='p18-import'", (case['supplier'],)).fetchone()[0]
        version = admin.execute('SELECT version FROM public.suppliers WHERE id=%s', (case['supplier'],)).fetchone()[0]
        draft = import_draft(import_sku, version, 'Synthetic draft', 'Synthetic description', 20000,
                             owner, clock(), using='supplier_operator')
        assert admin.execute('SELECT is_active FROM public.products WHERE id=%s', (draft,)).fetchone() == (False,)
        assert admin.execute('SELECT is_active FROM public.product_supplier_sources WHERE product_id=%s', (draft,)).fetchone() == (False,)
        policy = {'mode': 'manual', 'rounding_idr': 1, 'markup_basis_points': 0,
                  'profit_idr': 0, 'minimum_profit_idr': 0}
        preview = preview_price(draft, import_sku, policy, 1, owner, using='supplier_operator')
        assert preview['retail_idr'] == 20000 and preview['cost_idr'] == 15000
        from lhie_candidate.suppliers.management import disable_connection
        version = admin.execute('SELECT version FROM public.suppliers WHERE id=%s', (case['supplier'],)).fetchone()[0]
        disable_connection(case['supplier'], version, owner, clock())
        from lhie_candidate.suppliers.warranty import HistoricalWarranty
        purchase = admin.execute('SELECT current_purchase_id FROM public.supplier_fulfillments WHERE id=%s', (fulfillment,)).fetchone()[0]
        stock = admin.execute('SELECT id,order_item_id FROM public.product_stock_items WHERE supplier_purchase_id=%s ORDER BY supplier_unit_index LIMIT 1', (purchase,)).fetchone()
        claim_public = str(uuid4())
        unit = admin.execute(f"""INSERT INTO public.supplier_account_units(public_id,supplier_id,supplier_purchase_id,
            product_stock_item_id,order_id,order_item_id,external_id,provider_state,refund_deadline,cost_idr,
            retail_amount,retail_currency,unit_number,created_at,updated_at)
            VALUES (%s,%s,%s,%s,%s,%s,%s,'active',{now_sql}+INTERVAL '1 day',15000,20000,'IDR',1,{now_sql},{now_sql}) RETURNING id""",
            (str(uuid4()), case['supplier'], purchase, stock[0], order.order_id, stock[1], str(uuid4()))).fetchone()[0]
        admin.execute(f"""INSERT INTO public.warranty_claims(public_id,supplier_account_unit_id,customer_id,order_id,
            proof_path,proof_digest,note,state,refund_deadline,verified_at,settlement_state,created_at,updated_at)
            VALUES (%s,%s,%s,%s,'synthetic-proof',%s,'Synthetic','approved',
                {now_sql}+INTERVAL '1 day',{now_sql},'awaiting_choice',{now_sql},{now_sql})""",
            (claim_public, unit, case['customer'], order.order_id, 'a' * 64))
        historical = HistoricalWarranty(encrypter=cipher)
        outcomes = _race(_competing_warranty, [(case['customer'], claim_public), (case['customer'], claim_public)])
        assert len({result for status, result in outcomes if status == 'refund'}) == 1
        refund_id, refund_state, refund_amount = outcomes[0][1]
        assert refund_state == 'confirmed' and refund_amount == 20000
        assert str(historical.choose_refund(case['customer'], claim_public)['id']) == refund_id
        assert admin.execute('SELECT retired_at IS NOT NULL,order_item_id FROM public.product_stock_items WHERE id=%s', (stock[0],)).fetchone() == (True, stock[1])
        assert admin.execute('SELECT balance FROM public.customer_balance_accounts WHERE customer_id=%s', (case['customer'],)).fetchone()[0] == 30000
        remaining = source.load_customer(order.order_id, case['customer'])
        assert len(remaining['groups'][0]['accounts']) == 1

        uncertain = seed(customers[1], 1)
        unknown_order = checkout(uncertain, 'p18-uncertain')
        unknown_id = admin.execute('SELECT id FROM public.supplier_fulfillments WHERE order_id=%s', (unknown_order.order_id,)).fetchone()[0]
        clock.value = admin.execute('SELECT ' + now_sql).fetchone()[0] + timedelta(seconds=1)
        unknown = FakeClient([OrderResult('ambiguous', error_code='transport')])
        recovery = SupplierAcquisition(cipher, lambda supplier, key: unknown, clock=clock)
        assert recovery.process(unknown_id)
        clock.value += timedelta(minutes=3)
        assert not recovery.process(unknown_id)
        assert len(unknown.calls) == 1
        assert admin.execute('''SELECT p.state,f.state,s.reserved_balance_micros,c.reserved_quantity
            FROM public.supplier_fulfillments f JOIN public.supplier_purchases p ON p.id=f.current_purchase_id
            JOIN public.suppliers s ON s.id=f.reserved_supplier_id JOIN public.supplier_catalog_items c ON c.id=f.reserved_catalog_item_id
            WHERE f.id=%s''', (unknown_id,)).fetchone() == ('ambiguous','review',1000000,1)
        expect_unavailable(lambda: source.load_customer(unknown_order.order_id, uncertain['customer']))
        from lhie_candidate.suppliers.warranty import MissingFulfillmentRefunds
        item_id = admin.execute('SELECT order_item_id FROM public.supplier_fulfillments WHERE id=%s', (unknown_id,)).fetchone()[0]
        try:
            MissingFulfillmentRefunds(encrypter=cipher).request(uncertain['customer'], item_id)
        except CheckoutError as error:
            assert error.code == 'supplier_purchase_unresolved', error.code
        else:
            raise AssertionError('Uncertain supplier debit must not be refundable')
        purchase_id = admin.execute('SELECT current_purchase_id FROM public.supplier_fulfillments WHERE id=%s', (unknown_id,)).fetchone()[0]
        recovery.confirm_owner_no_debit(unknown_id, actor_user_id=owner, expected_purchase_id=purchase_id)
        evidence = admin.execute('SELECT kind,evidence_source FROM public.supplier_purchase_evidence WHERE supplier_purchase_id=%s', (purchase_id,)).fetchone()
        assert evidence == ('no_debit', 'owner_attestation')
        refund = MissingFulfillmentRefunds(encrypter=cipher).request(uncertain['customer'], item_id)
        assert refund['state'] == 'confirmed' and refund['amount'] == 20000
        assert admin.execute('SELECT balance FROM public.customer_balance_accounts WHERE customer_id=%s', (uncertain['customer'],)).fetchone()[0] == 50000
        assert admin.execute('SELECT count(*) FROM public.store_balance_entries WHERE source_type=%s AND source_id=%s',
                             ('order_refund', str(refund['id']))).fetchone()[0] == 1

        history_case = seed(fixtures['stale']['customer_id'], 1)
        admin.execute("UPDATE public.suppliers SET provider='toolorax' WHERE id=%s", (history_case['supplier'],))
        history_order = checkout(history_case, 'p18-history')
        history_id = admin.execute('SELECT id FROM public.supplier_fulfillments WHERE order_id=%s', (history_order.order_id,)).fetchone()[0]
        clock.value = admin.execute('SELECT ' + now_sql).fetchone()[0] + timedelta(seconds=1)
        history_client = FakeClient([OrderResult('ambiguous', error_code='transport')])
        history_client.config = SupplierConfig('toolorax', 'USD', 'synthetic-never-live-key', True, True)
        history_acquisition = SupplierAcquisition(cipher, lambda *_: history_client, clock=clock)
        assert history_acquisition.process(history_id)
        history_purchase = admin.execute('SELECT current_purchase_id FROM public.supplier_fulfillments WHERE id=%s', (history_id,)).fetchone()[0]
        dispatch = admin.execute('SELECT dispatched_at FROM public.supplier_purchases WHERE id=%s', (history_purchase,)).fetchone()[0]
        clock.value += timedelta(seconds=2)
        from zoneinfo import ZoneInfo
        from lhie_candidate.suppliers.history import SupplierHistory
        from lhie_candidate.suppliers.transport import OrderHistorySnapshot
        zone = ZoneInfo('Asia/Jakarta')
        rows = tuple({'order_id': str(n), 'product_id': -1, 'created_at': (dispatch + timedelta(seconds=n)).replace(tzinfo=zone),
                      'timestamp_precision': 'second'} for n in (-1, 1))
        history_client.order_history = lambda _zone: OrderHistorySnapshot(100, 2, rows, 'Asia/Jakarta', 'a' * 64)
        assert history_id in history_acquisition.due_ids()
        assert history_acquisition.process(history_id)
        assert admin.execute('''SELECT outcome,error_code FROM public.supplier_order_history_reconciliations
            WHERE supplier_purchase_id=%s AND actor_user_id IS NULL''', (history_purchase,)).fetchone() == ('inconclusive', 'history_timezone_unverified')
        assert not history_acquisition.process(history_id)
        scan_id = SupplierHistory(history_acquisition).scan(history_id, actor_user_id=owner,
            timezone='Asia/Jakarta', expected_purchase_id=history_purchase)
        assert admin.execute('SELECT outcome FROM public.supplier_order_history_reconciliations WHERE id=%s', (scan_id,)).fetchone()[0] == 'no_order'
        history_acquisition.confirm_no_debit(history_id, actor_user_id=owner, expected_purchase_id=history_purchase,
            evidence_source='provider_history', provider_reference='', note='Synthetic complete history', history_reconciliation_id=scan_id)
        assert admin.execute('SELECT reserved_balance_micros FROM public.suppliers WHERE id=%s', (history_case['supplier'],)).fetchone()[0] == 0
        candidate_case = seed(fixtures['release']['customer_id'], 1)
        admin.execute("UPDATE public.suppliers SET provider='csxstore' WHERE id=%s", (candidate_case['supplier'],))
        candidate_order = checkout(candidate_case, 'p18-history-candidate')
        candidate_id = admin.execute('SELECT id FROM public.supplier_fulfillments WHERE order_id=%s', (candidate_order.order_id,)).fetchone()[0]
        candidate_sku = admin.execute('SELECT external_id FROM public.supplier_catalog_items WHERE id=%s', (candidate_case['sku'],)).fetchone()[0]
        clock.value = admin.execute('SELECT ' + now_sql).fetchone()[0] + timedelta(seconds=1)
        candidate_client = FakeClient([OrderResult('ambiguous', error_code='transport'),
            OrderResult('delivered', 'csx-candidate', 1, 1000000, ('synthetic-history-unit',), None, True, True)])
        candidate_client.config = SupplierConfig('csxstore', 'USD', 'synthetic-never-live-key', True, True)
        candidate_client.order_history = lambda _zone: OrderHistorySnapshot(100, 1, (
            {'order_id': 'csx-candidate', 'product_id': candidate_sku, 'quantity': 1,
             'total_micros': 1000000, 'currency': 'USD', 'created_at': None},), 'unknown', 'b' * 64)
        candidate_acquisition = SupplierAcquisition(cipher, lambda *_: candidate_client, clock=clock)
        assert candidate_acquisition.process(candidate_id)
        candidate_purchase = admin.execute('SELECT current_purchase_id FROM public.supplier_fulfillments WHERE id=%s', (candidate_id,)).fetchone()[0]
        candidate_history = SupplierHistory(candidate_acquisition)
        candidate_scan = candidate_history.scan(candidate_id, actor_user_id=owner, expected_purchase_id=candidate_purchase)
        version = admin.execute('SELECT purchase_version FROM public.supplier_order_history_reconciliations WHERE id=%s', (candidate_scan,)).fetchone()[0]
        try:
            candidate_history.confirm_candidate(candidate_id, candidate_scan, 'csx-candidate', 'Synthetic verified candidate',
                actor_user_id=owner, expected_purchase_id=candidate_purchase, expected_purchase_version='stale')
        except CheckoutError as error:
            assert error.code == 'supplier_history_candidate_stale'
        else:
            raise AssertionError('Stale history candidate was accepted')
        assert [call[0] for call in candidate_client.calls] == ['post']
        candidate_history.confirm_candidate(candidate_id, candidate_scan, 'csx-candidate', 'Synthetic verified candidate',
            actor_user_id=owner, expected_purchase_id=candidate_purchase, expected_purchase_version=version)
        candidate_history.confirm_candidate(candidate_id, candidate_scan, 'csx-candidate', 'Synthetic verified candidate',
            actor_user_id=owner, expected_purchase_id=candidate_purchase, expected_purchase_version=version)
        assert admin.execute('SELECT history_reconciliation_id FROM public.supplier_purchase_evidence WHERE supplier_purchase_id=%s', (candidate_purchase,)).fetchone()[0] == candidate_scan
        assert source.load_customer(candidate_order.order_id, candidate_case['customer'])['allocated_count'] == 1


        recover_case = seed(fixtures['preflight']['customer_id'], 1)
        recover_order = checkout(recover_case, 'p18-exact-recovery')
        recover_id = admin.execute('SELECT id FROM public.supplier_fulfillments WHERE order_id=%s', (recover_order.order_id,)).fetchone()[0]
        clock.value = admin.execute('SELECT ' + now_sql).fetchone()[0] + timedelta(seconds=1)
        exact = FakeClient([
            OrderResult('ambiguous', error_code='transport'),
            OrderResult('delivered', 'wrong-order', 1, 1000000, ('synthetic-recovered',), None, True, True),
            OrderResult('delivered', 'actual-order', 1, 1000000, ('synthetic-recovered',), None, True, True),
        ])
        exact_worker = SupplierAcquisition(cipher, lambda *_: exact, clock=clock)
        assert exact_worker.process(recover_id)
        recover_purchase = admin.execute('SELECT current_purchase_id FROM public.supplier_fulfillments WHERE id=%s', (recover_id,)).fetchone()[0]
        exact_worker.recover_order(recover_id, 'actual-order', actor_user_id=owner, expected_purchase_id=recover_purchase)
        assert admin.execute('SELECT state,committed_at FROM public.supplier_purchases WHERE id=%s', (recover_purchase,)).fetchone() == ('ambiguous', None)
        exact_worker.confirm_debit(recover_id, 'actual-order', 1, 1000000, 'Synthetic verified debit',
                                   actor_user_id=owner, expected_purchase_id=recover_purchase)
        exact_worker.confirm_debit(recover_id, 'actual-order', 1, 1000000, 'Synthetic verified debit',
                                   actor_user_id=owner, expected_purchase_id=recover_purchase)
        assert admin.execute('SELECT kind,evidence_source FROM public.supplier_purchase_evidence WHERE supplier_purchase_id=%s', (recover_purchase,)).fetchone() == ('debit_confirmed', 'provider_exact_order')
        assert admin.execute('SELECT state,supplier_order_id,actual_cost_micros FROM public.supplier_purchases WHERE id=%s', (recover_purchase,)).fetchone() == ('delivered', 'actual-order', 1000000)
        assert [call[0] for call in exact.calls] == ['post', 'get', 'get']

        from lhie_candidate.suppliers.manual import ManualSupplierRecovery
        manual = ManualSupplierRecovery(cipher, clock=clock)
        manual_case = seed(fixtures['retry_success']['customer_id'], 1)
        manual_order = checkout(manual_case, 'p18-manual-delivery')
        manual_id = admin.execute('SELECT id FROM public.supplier_fulfillments WHERE order_id=%s', (manual_order.order_id,)).fetchone()[0]
        clock.value = admin.execute('SELECT ' + now_sql).fetchone()[0] + timedelta(seconds=1)
        foreign_client = FakeClient([OrderResult('delivered', 'foreign-order', 1, 1000000, ('foreign-credential',), None, True, True)])
        foreign_client.config = SupplierConfig('warzone', 'USD', 'different-synthetic-key', True, True)
        assert SupplierAcquisition(cipher, lambda *_: foreign_client, clock=clock).process(manual_id)
        assert foreign_client.calls == []
        manual.provide_manual_credentials(manual_id, ['synthetic-manual-credential'], actor_user_id=owner)
        assert source.load_customer(manual_order.order_id, manual_case['customer'])['allocated_count'] == 1
        assert admin.execute('''SELECT p.state,s.reserved_balance_micros FROM public.supplier_fulfillments f
            JOIN public.supplier_purchases p ON p.id=f.current_purchase_id JOIN public.suppliers s ON s.id=f.reserved_supplier_id
            WHERE f.id=%s''', (manual_id,)).fetchone() == ('ambiguous', 1000000)
        manual_purchase = admin.execute('SELECT current_purchase_id FROM public.supplier_fulfillments WHERE id=%s', (manual_id,)).fetchone()[0]
        manual.confirm_no_debit(manual_id, actor_user_id=owner, expected_purchase_id=manual_purchase,
                                evidence_source='provider_case', provider_reference='synthetic-case', note='Synthetic case verified')
        manual.confirm_no_debit(manual_id, actor_user_id=owner, expected_purchase_id=manual_purchase,
                                evidence_source='provider_case', provider_reference='synthetic-case', note='Synthetic case verified')
        assert admin.execute('SELECT reserved_balance_micros FROM public.suppliers WHERE id=%s', (manual_case['supplier'],)).fetchone()[0] == 0
        assert source.load_customer(manual_order.order_id, manual_case['customer'])['allocated_count'] == 1
        handoff_case = seed(fixtures['retry_exhausted']['customer_id'], 2)
        handoff_order = checkout(handoff_case, 'p18-manual-handoff')
        handoff_id = admin.execute('SELECT id FROM public.supplier_fulfillments WHERE order_id=%s', (handoff_order.order_id,)).fetchone()[0]
        clock.value = admin.execute('SELECT ' + now_sql).fetchone()[0] + timedelta(seconds=1)
        assert SupplierAcquisition(cipher, lambda *_: FakeClient([OrderResult('ambiguous', error_code='transport')]), clock=clock).process(handoff_id)
        review_id = admin.execute('SELECT id FROM public.manual_fulfillment_reviews WHERE order_id=%s', (handoff_order.order_id,)).fetchone()[0]
        handoff_key = str(uuid4())
        manual.record_external_handoff(review_id, handoff_id, 1, 'Synthetic external handoff', actor_user_id=owner, idempotency_key=handoff_key)
        manual.record_external_handoff(review_id, handoff_id, 1, 'Synthetic external handoff', actor_user_id=owner, idempotency_key=handoff_key)
        assert admin.execute('SELECT count(*) FROM public.supplier_manual_handoffs WHERE supplier_fulfillment_id=%s', (handoff_id,)).fetchone()[0] == 1
        assert admin.execute('SELECT reserved_balance_micros FROM public.suppliers WHERE id=%s', (handoff_case['supplier'],)).fetchone()[0] == 2000000
        expect_unavailable(lambda: source.load_customer(handoff_order.order_id, handoff_case['customer']))
        manual.provide_manual_credentials(handoff_id, ['synthetic-remaining-unit'], actor_user_id=owner)
        handoff_chat = admin.execute('SELECT telegram_id FROM public.customers WHERE id=%s', (handoff_case['customer'],)).fetchone()[0]
        assert source.load_customer(handoff_order.order_id, handoff_case['customer'])['allocated_count'] == 1
        assert source.load(handoff_order.order_id, handoff_chat)['allocated_count'] == 1
        expect_unavailable(lambda: source.load(handoff_order.order_id, handoff_chat, receipt=True))
        retirement_race = seed(retirement_customer, 1)
        retirement_order = checkout(retirement_race, 'p18-disable-before-dispatch')
        retirement_id = admin.execute('SELECT id FROM public.supplier_fulfillments WHERE order_id=%s', (retirement_order.order_id,)).fetchone()[0]
        clock.value = admin.execute('SELECT ' + now_sql).fetchone()[0] + timedelta(seconds=1)
        acquisition = SupplierAcquisition(cipher, lambda *_: FakeClient([]), clock=clock)
        from django.db import transaction
        with transaction.atomic():
            claim = acquisition._claim(retirement_id)
        assert claim is not None
        with admin.transaction():
            admin.execute('UPDATE public.suppliers SET is_active=false WHERE id=%s', (retirement_race['supplier'],))
        with transaction.atomic():
            assert not acquisition._dispatch(retirement_id, claim[0]['id'], claim[3])
        assert admin.execute('SELECT count(*) FROM public.supplier_purchase_dispatches WHERE supplier_purchase_id=%s', (claim[0]['id'],)).fetchone()[0] == 0


        competition = seed(race_customers[0], 1, stock=1)
        with admin.transaction():
            other_cart = admin.execute(f"""INSERT INTO public.carts(customer_id,status,expires_at,created_at,updated_at)
                VALUES (%s,'active',{now_sql}+INTERVAL '1 day',{now_sql},{now_sql}) RETURNING id""", (race_customers[1],)).fetchone()[0]
            admin.execute(f"""INSERT INTO public.cart_items(cart_id,product_id,quantity,created_at,updated_at)
                VALUES (%s,%s,1,{now_sql},{now_sql})""", (other_cart, competition['product']))
        other = dict(competition, customer=race_customers[1], cart=other_cart)
        outcomes = _race(_competing_checkout, [(competition, 'p18-race-a'), (other, 'p18-race-b')])
        assert sorted(status for status, _ in outcomes) == ['accepted', 'rejected'], outcomes
        assert next(value for status, value in outcomes if status == 'rejected') == 'insufficient_stock'
        winner = next(value for status, value in outcomes if status == 'accepted')
        race_id = admin.execute('SELECT id FROM public.supplier_fulfillments WHERE order_id=%s', (winner,)).fetchone()[0]
        acquisition = _race(_competing_purchase, [(race_id,), (race_id,)], messages=3)
        assert sum(status == 'dispatch' for status, _ in acquisition) == 1, acquisition
        assert sorted(value for status, value in acquisition if status == 'processed') == [False, True]
        assert admin.execute('SELECT count(*) FROM public.supplier_purchases WHERE supplier_fulfillment_id=%s', (race_id,)).fetchone()[0] == 1
        duplicate_case = seed(fixtures['duplicate']['customer_id'], 2)
        duplicate_order = checkout(duplicate_case, 'p18-duplicate-credentials')
        duplicate_id = admin.execute('SELECT id FROM public.supplier_fulfillments WHERE order_id=%s', (duplicate_order.order_id,)).fetchone()[0]
        duplicate = FakeClient([OrderResult('delivered', 'duplicate-order', 2, 2000000,
                                            ('same-synthetic-unit', 'same-synthetic-unit'), None, True, True)])
        clock.value = admin.execute('SELECT ' + now_sql).fetchone()[0] + timedelta(seconds=1)
        assert SupplierAcquisition(cipher, lambda *_: duplicate, clock=clock).process(duplicate_id)
        assert admin.execute('SELECT state FROM public.supplier_fulfillments WHERE id=%s', (duplicate_id,)).fetchone()[0] == 'review'
        assert admin.execute('''SELECT count(*) FROM public.product_stock_items s
            JOIN public.order_items i ON i.id=s.order_item_id WHERE i.order_id=%s''', (duplicate_order.order_id,)).fetchone()[0] == 0
        expect_unavailable(lambda: source.load_customer(duplicate_order.order_id, duplicate_case['customer']))

        retire_case = seed(retirement_customer, 1)
        retire_order = checkout(retire_case, 'p18-retired')
        retire_id = admin.execute('SELECT id FROM public.supplier_fulfillments WHERE order_id=%s', (retire_order.order_id,)).fetchone()[0]
        from lhie_candidate.suppliers.management import disable_connection
        version = admin.execute('SELECT version FROM public.suppliers WHERE id=%s', (retire_case['supplier'],)).fetchone()[0]
        disable_connection(retire_case['supplier'], version, owner, clock())
        retired = FakeClient([])
        assert not SupplierAcquisition(cipher, lambda *_: retired).process(retire_id)
        assert retired.calls == []
        assert admin.execute('SELECT reserved_balance_micros FROM public.suppliers WHERE id=%s', (retire_case['supplier'],)).fetchone()[0] == 1000000
        from lhie_candidate.checkout.manual import ManualQrisPreparation
        from lhie_candidate.payments.routing import QrisPayments
        admin.execute("UPDATE public.manual_payment_settings SET payment_mode='manual' WHERE is_active")
        pending_service = CheckoutService(preparation=ManualQrisPreparation(ready=lambda: True), supplier_encrypter=cipher)
        payment_orders = QrisPayments(encrypter=cipher).orders
        for transition, customer in (('cancel', customers[0]), ('expire', customers[1])):
            pending_case = seed(customer, 1, stock=1)
            quote = pending_service.quote_cart(customer, pending_case['cart'])
            pending = pending_service.checkout(CheckoutRequest(customer, pending_case['cart'], 'p18-' + transition, quote))
            assert (pending.order_status, pending.payment_status) == ('pending_payment', 'pending')
            if transition == 'cancel':
                result = payment_orders.apply_pending_cancellation(pending.order_id, customer_id=customer)
            else:
                payment_orders.clock = lambda: clock() + timedelta(hours=1)
                result = payment_orders.apply_pending_expiry(pending.order_id)
            assert result.order['order_status'] == ('cancelled' if transition == 'cancel' else 'expired')
            assert admin.execute('SELECT reserved_balance_micros FROM public.suppliers WHERE id=%s', (pending_case['supplier'],)).fetchone()[0] == 0
            assert admin.execute('SELECT supplier_available_stock FROM public.products WHERE id=%s', (pending_case['product'],)).fetchone()[0] == 1

        return {'supplier_delivery': 'pending and complete verified', 'supplier_uncertainty': 'no blind repurchase',
                'supplier_authorization': 'shared customer and Telegram receipt source',
                'supplier_concurrency': 'one capacity winner, one acquisition dispatch, one credential refund',
                'supplier_retirement': 'new dispatch blocked, existing hold retained'}
    finally:
        admin.close()
