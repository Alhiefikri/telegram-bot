"""Supplier notice recovery through the existing disposable P25 harness."""
import json
from pathlib import Path
from datetime import timedelta
from uuid import uuid4


def exercise_supplier_outcome(fixtures):
    from copy import deepcopy
    from django.db import connections
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.checkout.errors import CheckoutError
    from lhie_candidate.checkout.service import CheckoutRequest, CheckoutService
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.notifications.order_notices import OrderNotices, PAID
    from lhie_candidate.notifications.policy import ORDER_TYPES, TestRecipients
    from lhie_candidate.notifications.service import Notifications
    from lhie_candidate.notifications.transport import NotificationTransport
    from lhie_candidate.suppliers.acquisition import SupplierAcquisition
    from lhie_candidate.suppliers.capacity import refresh_products
    from lhie_candidate.suppliers.transport import OrderResult, SupplierConfig
    from lhie_candidate.suppliers.warranty import MissingFulfillmentRefunds
    from tests.test_checkout_postgres import admin_connection

    supplier_database = deepcopy(connections.databases['default'])
    supplier_database.update(USER='candidate_p25_supplier', PASSWORD=fixtures['p25_supplier_db_password'])
    connections.databases['p25_supplier'] = supplier_database
    admin, db = admin_connection(), Database()
    cipher = LaravelEncrypter('c' * 32)
    now_sql = "CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'"
    customer = fixtures['last_stock'][1]['customer_id']
    try:
        with admin.transaction():
            admin.execute(f"""INSERT INTO public.supplier_currency_rates(currency,exchange_rate_idr,created_at,updated_at)
                VALUES ('USD',15000,{now_sql},{now_sql}) ON CONFLICT (currency) DO NOTHING""")
            rate = admin.execute("SELECT exchange_rate_idr FROM public.supplier_currency_rates WHERE currency='USD'").fetchone()[0]
            product = admin.execute(f"""INSERT INTO public.products(name,description,price_amount,stock,reserved_stock,is_active,created_at,updated_at)
                VALUES ('Synthetic outcome product','Synthetic',20000,0,0,true,{now_sql},{now_sql}) RETURNING id""").fetchone()[0]
            supplier = admin.execute(f"""INSERT INTO public.suppliers(name,provider,api_key,currency,currency_confirmed,
                exchange_rate_idr,balance_micros,reserved_balance_micros,is_active,synced_at,sync_expires_at,created_at,updated_at)
                VALUES ('Synthetic outcome supplier','warzone',%s,'USD',true,%s,10000000,0,true,{now_sql},
                    {now_sql}+INTERVAL '5 minutes',{now_sql},{now_sql}) RETURNING id""",
                (cipher.encrypt_string('synthetic-never-live-key'), rate)).fetchone()[0]
            sku = admin.execute(f"""INSERT INTO public.supplier_catalog_items(supplier_id,external_id,name,currency,unit_price_micros,
                stock,orderable,requires_input,synced_at,created_at,updated_at)
                VALUES (%s,%s,'Synthetic SKU','USD',1000000,10,true,false,{now_sql},{now_sql},{now_sql}) RETURNING id""",
                (supplier, str(uuid4()))).fetchone()[0]
            admin.execute(f"""INSERT INTO public.product_supplier_sources(product_id,supplier_catalog_item_id,priority,is_active,created_at,updated_at)
                VALUES (%s,%s,1,true,{now_sql},{now_sql})""", (product, sku))
            admin.execute(f"""INSERT INTO public.supplier_price_policies(product_id,mode,created_at,updated_at)
                VALUES (%s,'manual',{now_sql},{now_sql})""", (product,))
            cart = admin.execute(f"""INSERT INTO public.carts(customer_id,status,expires_at,created_at,updated_at)
                VALUES (%s,'active',{now_sql}+INTERVAL '1 day',{now_sql},{now_sql}) RETURNING id""", (customer,)).fetchone()[0]
            admin.execute(f"""INSERT INTO public.cart_items(cart_id,product_id,quantity,created_at,updated_at)
                VALUES (%s,%s,1,{now_sql},{now_sql})""", (cart, product))
        clock = [admin.execute('SELECT ' + now_sql).fetchone()[0] + timedelta(seconds=1)]
        refresh_products([product], clock[0], using='p25_supplier')
        checkout = CheckoutService(using='p25_supplier', supplier_encrypter=cipher)
        order = checkout.checkout(CheckoutRequest(customer, cart, 'p25-supplier-outcome',
                                                   checkout.quote_cart(customer, cart)))
        assert (order.order_status, order.payment_status) == ('processing', 'paid')
        fulfillment, item = admin.execute('SELECT id,order_item_id FROM public.supplier_fulfillments WHERE order_id=%s',
                                          (order.order_id,)).fetchone()

        class FakeSupplier:
            config = SupplierConfig('warzone', 'USD', 'synthetic-never-live-key', True, True)
            def __init__(self):
                self.calls = 0
            def purchase(self, sku, quantity, reference):
                self.calls += 1
                return OrderResult('ambiguous', error_code='transport')

        fake = FakeSupplier()
        acquisition = SupplierAcquisition(cipher, lambda *_: fake, using='p25_supplier', clock=lambda: clock[0])
        assert acquisition.process(fulfillment)
        clock[0] += timedelta(minutes=3)
        assert not acquisition.process(fulfillment) and fake.calls == 1
        recipients = TestRecipients(frozenset({'1'}))
        owner = OrderNotices(recipients)
        captured = []
        class Capture:
            def execute(self, method, payload):
                captured.append(payload)
                return NotificationTransport().execute(method, payload)
        service = Notifications(Capture(), recipients=recipients, order_notices=owner, families=ORDER_TYPES)
        owner.discover(service.record, 100)
        effect = db.one('SELECT * FROM public.telegram_outbound_effects WHERE source_id=%s AND effect_type=%s',
                        (str(order.order_id), PAID))
        assert effect and service.deliver(effect['id']) == 'sent'
        assert 'hasil pembelian belum dapat dipastikan; jangan beli ulang' in captured[-1]['text']
        assert service.deliver(effect['id']) == 'not_ready'
        try:
            MissingFulfillmentRefunds(using='p25_supplier', encrypter=cipher).request(customer, item)
        except CheckoutError as error:
            assert error.code == 'supplier_purchase_unresolved'
        else:
            raise AssertionError('Uncertain supplier debit must not be refundable')
        purchase = admin.execute('SELECT current_purchase_id FROM public.supplier_fulfillments WHERE id=%s',
                                 (fulfillment,)).fetchone()[0]
        acquisition.confirm_owner_no_debit(fulfillment, actor_user_id=fixtures['p25_compensation']['owner_id'],
                                           expected_purchase_id=purchase)
        refund = MissingFulfillmentRefunds(using='p25_supplier', encrypter=cipher).request(customer, item)
        assert refund['state'] == 'confirmed' and refund['amount'] == 20000
        assert admin.execute('SELECT count(*) FROM public.store_balance_entries WHERE source_type=%s AND source_id=%s',
                             ('order_refund', str(refund['id']))).fetchone()[0] == 1
        owner.recover_results(service.record, 100)
        successor = db.one('SELECT * FROM public.telegram_outbound_effects WHERE supersedes_effect_id=%s', (effect['id'],))
        result = service.deliver(successor['id']) if successor else 'missing'
        if result != 'sent':
            source = owner._order(str(order.order_id))
            outcome = owner.outcome(source, owner._items(source['id']), owner._payments(source['id']))
            fulfillment_state = db.one('SELECT state,refunded_quantity FROM public.supplier_fulfillments WHERE id=%s', (fulfillment,))
            purchase_state = db.one('SELECT state,committed_at IS NOT NULL AS committed FROM public.supplier_purchases WHERE id=%s', (purchase,))
            Path('/evidence/p25-supplier-diagnostic.json').write_text(json.dumps({
                'order_status': source['order_status'], 'payment_status': source['payment_status'],
                'resolved': outcome['resolved'], 'successor_state': result,
                'fulfillment': fulfillment_state, 'purchase': purchase_state,
                'lines': outcome['lines']}))
        assert successor and result == 'sent'
        assert 'tidak ada debit menurut konfirmasi Owner' in captured[-1]['text']
        assert '1 unit refund tercatat' in captured[-1]['text']
        assert 'Status pembayaran: refunded' in captured[-1]['text']
        assert service.deliver(successor['id']) == 'not_ready' and fake.calls == 1
        source = owner._order(str(order.order_id))
        outcome = owner.outcome(source, owner._items(source['id']), owner._payments(source['id']))
        Path('/evidence/p25-supplier-outcome.json').write_text(json.dumps({'order_id': str(order.order_id), 'outcome': outcome}))
        with admin.transaction():
            handoff_cart = admin.execute(f"""INSERT INTO public.carts(customer_id,status,expires_at,created_at,updated_at)
                VALUES (%s,'active',{now_sql}+INTERVAL '1 day',{now_sql},{now_sql}) RETURNING id""", (customer,)).fetchone()[0]
            admin.execute(f"""INSERT INTO public.cart_items(cart_id,product_id,quantity,created_at,updated_at)
                VALUES (%s,%s,1,{now_sql},{now_sql})""", (handoff_cart, product))
        refresh_products([product], clock[0], using='p25_supplier')
        handoff_order = checkout.checkout(CheckoutRequest(customer, handoff_cart, 'p25-supplier-handoff',
            checkout.quote_cart(customer, handoff_cart)))
        handoff_fulfillment = admin.execute('SELECT id FROM public.supplier_fulfillments WHERE order_id=%s',
                                            (handoff_order.order_id,)).fetchone()[0]
        assert acquisition.process(handoff_fulfillment) and fake.calls == 2
        clock[0] += timedelta(minutes=3)
        assert not acquisition.process(handoff_fulfillment) and fake.calls == 2
        notice = None
        for _ in range(3):
            owner.discover(service.record, 100)
            notice = db.one('SELECT * FROM public.telegram_outbound_effects WHERE source_id=%s AND effect_type=%s',
                            (str(handoff_order.order_id), PAID))
            if notice:
                break
        assert notice and service.deliver(notice['id']) == 'sent'
        review = admin.execute("""SELECT id FROM public.manual_fulfillment_reviews WHERE order_id=%s
            AND reason='supplier_fulfillment' AND state='pending'""", (handoff_order.order_id,)).fetchone()[0]
        from lhie_candidate.suppliers.manual import ManualSupplierRecovery
        manual = ManualSupplierRecovery(cipher, using='p25_supplier', clock=lambda: clock[0])
        manual.record_external_handoff(review, handoff_fulfillment, 1, 'Synthetic handoff',
                                       actor_user_id=fixtures['p25_compensation']['owner_id'])
        owner.recover_results(service.record, 100)
        assert db.one('SELECT id FROM public.telegram_outbound_effects WHERE supersedes_effect_id=%s',
                      (notice['id'],)) is None
        purchase = admin.execute('SELECT current_purchase_id FROM public.supplier_fulfillments WHERE id=%s',
                                 (handoff_fulfillment,)).fetchone()[0]
        acquisition.confirm_owner_no_debit(handoff_fulfillment, actor_user_id=fixtures['p25_compensation']['owner_id'],
                                           expected_purchase_id=purchase)
        update = None
        for _ in range(3):
            owner.recover_results(service.record, 100)
            update = db.one('SELECT * FROM public.telegram_outbound_effects WHERE supersedes_effect_id=%s',
                            (notice['id'],))
            if update:
                break
        assert update and service.deliver(update['id']) == 'sent'
        assert '1/1 unit diserahkan di luar bot' in captured[-1]['text']
        assert 'tidak ada debit menurut konfirmasi Owner' in captured[-1]['text']
        assert service.deliver(update['id']) == 'not_ready' and fake.calls == 2
        return {'supplier_ambiguous_owner_no_debit_refund_notice_recovery': 'passed',
                'supplier_manual_handoff_independent_debit_recovery': 'passed'}
    finally:
        connections.close_all()
        admin.close()
