"""Shared P07 proof, invoked only by the admitted disposable checkout smoke."""
from dataclasses import asdict, replace
from datetime import timedelta
import multiprocessing
import os


def admin_connection():
    if os.environ.get('P07_CHECKOUT_SMOKE') != 'disposable-local-only':
        raise RuntimeError('Explicit disposable checkout opt-in required')
    import psycopg
    connection = psycopg.connect(host='127.0.0.1', hostaddr='127.0.0.1', port=5432,
        dbname='disposable', user='harness', password='harness', connect_timeout=3, autocommit=True)
    identity = connection.execute('SELECT current_database(),current_user,host(inet_server_addr()),inet_server_port()').fetchone()
    if identity != ('disposable', 'harness', '127.0.0.1', 5432):
        connection.close()
        raise RuntimeError('Disposable checkout identity mismatch')
    return connection


def competing_checkout(request, barrier, queue):
    try:
        from lhie_candidate.startup import configure_django
        configure_django()
        from lhie_candidate.checkout.service import CheckoutService
        from lhie_candidate.checkout.errors import CheckoutError
        from django.db import connections
        barrier.wait(timeout=15)
        try:
            result = CheckoutService().checkout(request)
            queue.put(('accepted', asdict(result)))
        except CheckoutError as error:
            queue.put(('rejected', error.code))
        finally:
            connections.close_all()
    except Exception as error:
        queue.put(('failed', type(error).__name__))


def race(requests):
    from django.db import connections
    connections.close_all()
    context = multiprocessing.get_context('spawn')
    barrier = context.Barrier(len(requests) + 1)
    queue = context.Queue()
    children = [context.Process(target=competing_checkout, args=(request, barrier, queue)) for request in requests]
    for child in children:
        child.start()
    try:
        barrier.wait(timeout=15)
        outcomes = [queue.get(timeout=20) for _ in children]
        for child in children:
            child.join(timeout=10)
            assert child.exitcode == 0, 'Checkout worker did not exit cleanly'
        assert not any(outcome[0] == 'failed' for outcome in outcomes), outcomes
        return outcomes
    finally:
        for child in children:
            if child.is_alive():
                child.kill()
                child.join()
        queue.close()


def exercise_checkout_protocol(fixtures):
    from django.db import DatabaseError, connection, transaction
    from lhie_candidate.checkout.balance import StoreBalanceLedger
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.checkout.errors import CheckoutError
    from lhie_candidate.checkout.intents import PaymentPlan, PaymentPreflight, StoreBalancePreparation, telegram_checkout_key
    from lhie_candidate.checkout.inventory import InventoryLedger
    from lhie_candidate.checkout.service import CheckoutRequest, CheckoutService
    from lhie_candidate.readiness import verify_dependencies
    db = Database()
    service = CheckoutService()
    verify_dependencies()
    assert db.one('SELECT current_database() AS db,current_user AS role') == {'db': 'disposable', 'role': 'candidate_runtime'}
    admin = admin_connection()
    def request(fixture, key):
        return CheckoutRequest(fixture['customer_id'], fixture['cart_id'], key,
                               service.quote_cart(fixture['customer_id'], fixture['cart_id']))
    def reject(callback, code):
        try:
            callback()
        except CheckoutError as error:
            assert error.code == code, (error.code, code)
        else:
            raise AssertionError('Expected checkout rejection: ' + code)
    def economics(fixture):
        return admin.execute('''SELECT
            (SELECT COUNT(*) FROM public.orders WHERE cart_id=%s),
            (SELECT COUNT(*) FROM public.payments p JOIN public.orders o ON o.id=p.order_id WHERE o.cart_id=%s),
            (SELECT COUNT(*) FROM public.store_balance_entries e JOIN public.customer_balance_accounts a ON a.id=e.customer_balance_account_id
             WHERE a.customer_id=%s AND e.kind='purchase_debit'),
            (SELECT balance FROM public.customer_balance_accounts WHERE customer_id=%s),
            (SELECT stock FROM public.products WHERE id=%s),
            (SELECT reserved_stock FROM public.products WHERE id=%s),
            (SELECT COUNT(*) FROM public.product_stock_items WHERE product_id=%s AND order_item_id IS NOT NULL),
            (SELECT COUNT(*) FROM public.inventory_movements WHERE product_id=%s AND order_id IS NOT NULL)''',
            (fixture['cart_id'], fixture['cart_id'], fixture['customer_id'], fixture['customer_id'],
             fixture['product_id'], fixture['product_id'], fixture['product_id'], fixture['product_id'])).fetchone()
    try:
        assert telegram_checkout_key('a'*64,42,700042,'700042',99,'checkout:balance') == fixtures['key']
        primary = fixtures['python']
        original = request(primary, 'b'*64)
        result = service.checkout(original)
        expected = fixtures['expected']
        assert (result.amount, result.order_status, result.payment_status) == (expected['amount'], expected['order_status'], expected['payment_status'])
        item = db.one('SELECT unit_price,subtotal,quantity_price_tier_minimum FROM public.order_items WHERE order_id=%s', (result.order_id,))
        # Inspect additional immutable commercial fields through the oracle, not widened runtime grants.
        assert tuple(item.values()) == (expected['unit_price'], expected['subtotal'], expected['tier_minimum'])
        assert economics(primary) == (1,1,1,expected['balance'],expected['stock'],expected['reserved_stock'],2,2)
        entry = db.one("SELECT * FROM public.store_balance_entries WHERE source_type='order' AND source_id=%s AND kind='purchase_debit'", (result.order_id,))
        assert (entry['amount'],entry['balance_after'],entry['idempotency_key']) == (24000,26000,f'order:{result.order_id}:debit')
        # Lost response/update replay must bypass current price, maintenance and spending gates.
        admin.execute('UPDATE public.products SET price_amount=17000 WHERE id=%s', (primary['product_id'],))
        admin.execute('UPDATE public.checkout_maintenance_settings SET is_active=true')
        replay = service.checkout(original)
        assert replay.order_id == result.order_id and replay.payment_id == result.payment_id and replay.replayed
        assert economics(primary) == (1,1,1,26000,2,0,2,2)
        admin.execute('UPDATE public.checkout_maintenance_settings SET is_active=false')
        # Same accepted Cart remains first-wins even when its key changes.
        assert service.checkout(replace(original,checkout_key='cart-replay')).order_id == result.order_id
        foreign = fixtures['foreign']
        reject(lambda: service.checkout(replace(original, customer_id=foreign['customer_id'],cart_id=foreign['cart_id'])), 'foreign_checkout')
        reject(lambda: service.quote_cart(foreign['customer_id'], primary['cart_id']), 'foreign_cart')
        for name, expected_code in (('wallet','insufficient_store_balance'),('last_stock','insufficient_stock')):
            requests = [request(fixture, f'{name}-{index}') for index,fixture in enumerate(fixtures[name])]
            outcomes = race(requests)
            assert sorted(outcome[0] for outcome in outcomes) == ['accepted','rejected'], outcomes
            assert next(value for status,value in outcomes if status=='rejected') == expected_code
            accepted = next(value for status,value in outcomes if status=='accepted')
            ledger = admin.execute("SELECT amount,balance_after,source_type,source_id,idempotency_key FROM public.store_balance_entries WHERE source_type='order' AND source_id=%s AND kind='purchase_debit'", (accepted['order_id'],)).fetchall()
            assert ledger == [(15000,5000 if name=='wallet' else 35000,'order',accepted['order_id'],f"order:{accepted['order_id']}:debit")]
            product_id = fixtures[name][0]['product_id']
            assert admin.execute('SELECT stock,reserved_stock FROM public.products WHERE id=%s',(product_id,)).fetchone() == ((1,0) if name=='wallet' else (0,0))
            assert admin.execute('SELECT COUNT(*) FROM public.product_stock_items WHERE product_id=%s AND order_item_id IS NOT NULL',(product_id,)).fetchone() == (1,)
            assert admin.execute('SELECT COUNT(*) FROM public.orders WHERE cart_id=ANY(%s)',([fixture['cart_id'] for fixture in fixtures[name]],)).fetchone() == (1,)
        duplicate = request(fixtures['duplicate'], 'duplicate-stable-key')
        outcomes = race([duplicate, duplicate])
        assert [status for status,_ in outcomes] == ['accepted','accepted'], outcomes
        assert outcomes[0][1]['order_id'] == outcomes[1][1]['order_id']
        assert sorted(value['replayed'] for _,value in outcomes) == [False, True]
        assert economics(fixtures['duplicate']) == (1,1,1,35000,3,0,1,2)
        for name in ('stale','maintenance','pause','restore','rollback','empty_balance'):
            fixture = fixtures[name]
            candidate = request(fixture,name+'-stable')
            before = economics(fixture)
            if name == 'stale':
                admin.execute('UPDATE public.products SET price_amount=16000 WHERE id=%s',(fixture['product_id'],))
                reject(lambda: service.checkout(candidate),'price_changed')
            elif name == 'maintenance':
                admin.execute('UPDATE public.checkout_maintenance_settings SET is_active=true')
                reject(lambda: service.checkout(candidate),'checkout_maintenance')
                admin.execute('UPDATE public.checkout_maintenance_settings SET is_active=false')
            elif name == 'pause':
                admin.execute("INSERT INTO public.customer_usdt_funding_pauses(scope,reason,paused_at,created_at,updated_at) VALUES ('spending','synthetic',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")
                reject(lambda: service.checkout(candidate),'spending_paused')
                admin.execute("DELETE FROM public.customer_usdt_funding_pauses WHERE scope='spending'")
            elif name == 'restore':
                admin.execute("UPDATE public.balance_restore_gates SET state='open',opened_at=CURRENT_TIMESTAMP,window_start=CURRENT_TIMESTAMP,window_end=CURRENT_TIMESTAMP")
                reject(lambda: service.checkout(candidate),'restore_gate_open')
                admin.execute("UPDATE public.balance_restore_gates SET state='cleared'")
            elif name == 'empty_balance':
                reject(lambda: service.checkout(candidate),'insufficient_store_balance')
            else:
                admin.execute('''CREATE FUNCTION public.p07_rollback_fault() RETURNS trigger LANGUAGE plpgsql AS $$
                    BEGIN IF NEW.id = %s AND NEW.status='checked_out' THEN
                    RAISE EXCEPTION 'synthetic final-step fault' USING ERRCODE='23514'; END IF; RETURN NEW; END $$''' % fixture['cart_id'])
                admin.execute('CREATE TRIGGER p07_rollback_fault BEFORE UPDATE ON public.carts FOR EACH ROW EXECUTE FUNCTION public.p07_rollback_fault()')
                try:
                    service.checkout(candidate)
                except DatabaseError as error:
                    assert error.__cause__.sqlstate == '23514'
                else:
                    raise AssertionError('Final-step fault did not roll back')
                finally:
                    admin.execute('DROP TRIGGER p07_rollback_fault ON public.carts')
                    admin.execute('DROP FUNCTION public.p07_rollback_fault()')
            assert economics(fixture) == before, name
        # Reservation-only path uses the shared prepare seam; release is exactly once.
        class PendingPreparation(StoreBalancePreparation):
            provider, method = 'manual', 'QR_MANUAL'
            def prepare(self, pricing, setting, preflight, now):
                return PaymentPlan(self.provider,self.method,pricing.idr_total,now+timedelta(minutes=15))
        pending_service = CheckoutService(preparation=PendingPreparation())
        pending = pending_service.checkout(request(fixtures['release'],'release-stable'))
        assert economics(fixtures['release']) == (1,1,0,50000,4,1,1,1)
        with transaction.atomic():
            InventoryLedger().release(pending.order_id)
            InventoryLedger().release(pending.order_id)
        assert economics(fixtures['release']) == (1,1,0,50000,4,0,0,2)
        with transaction.atomic():
            reject(lambda: InventoryLedger().release(result.order_id),'invalid_order_transition')
        # Refund prerequisite credits only the original debit and retains exposed credentials.
        with transaction.atomic():
            refund = StoreBalanceLedger().refund_order(primary['customer_id'],result.order_id)
            repeated = StoreBalanceLedger().refund_order(primary['customer_id'],result.order_id)
            assert refund['id'] == repeated['id'] and refund['amount'] == 24000
            order_item = db.one('SELECT id FROM public.order_items WHERE order_id=%s',(result.order_id,))
            InventoryLedger().retire(order_item['id'])
            InventoryLedger().retire(order_item['id'])
        assert economics(primary) == (1,1,1,50000,2,0,2,2)
        assert admin.execute('SELECT COUNT(*) FROM public.product_stock_items WHERE order_item_id=%s AND retired_at IS NOT NULL',(order_item['id'],)).fetchone() == (2,)
        # Existing database guards, not application checks, retain final authority.
        for sql, params, state in (
            ('UPDATE public.store_balance_entries SET amount=amount WHERE id=%s',(entry['id'],),'42501'),
            ('UPDATE public.orders SET total_amount=0 WHERE id=%s',(result.order_id,),'42501'),
            ("UPDATE public.balance_restore_gates SET state='cleared'",(),'42501'),
            ('DELETE FROM public.store_balance_entries WHERE id=%s',(entry['id'],),'42501'),
            ('CREATE TABLE public.p07_forbidden (id integer)',(),'42501'),
            ('SELECT password FROM public.customers',(),'42501'),
        ):
            try:
                db.execute(sql,params)
            except DatabaseError as error:
                assert error.__cause__.sqlstate == state
            else:
                raise AssertionError('Candidate exceeded mutation privileges')
        for sql, params, state in (
            ('''INSERT INTO public.store_balance_entries
                (customer_balance_account_id,kind,amount,balance_after,source_type,source_id,idempotency_key,created_at)
                VALUES (%s,'purchase_debit',24000,26000,'order',%s,'different-attempt',CURRENT_TIMESTAMP)''',
                (entry['customer_balance_account_id'],result.order_id),'23505'),
            ('UPDATE public.customer_balance_accounts SET balance=-1 WHERE customer_id=%s',(primary['customer_id'],),'23514'),
            ('''UPDATE public.orders SET balance_checkout_snapshot='{}' WHERE id=%s''',(result.order_id,),'P0001'),
        ):
            try:
                admin.execute(sql,params)
            except Exception as error:
                assert error.sqlstate == state, error.sqlstate
            else:
                raise AssertionError('Canonical database guard was bypassed')
        # Serialization failures roll back the whole closure. Preparation is local
        # on each attempt; preflight remains outside locks and executes only once.
        class CountingPreparation(StoreBalancePreparation):
            def __init__(self):
                self.preflights = 0
                self.prepares = 0
            def preflight(self, setting):
                assert not connection.in_atomic_block
                self.preflights += 1
                return super().preflight(setting)
            def prepare(self, pricing, setting, preflight, now):
                assert connection.in_atomic_block
                self.prepares += 1
                return super().prepare(pricing,setting,preflight,now)
        for name, failures, attempts in (('retry_success',2,3),('retry_exhausted',5,5)):
            fixture = fixtures[name]
            candidate = request(fixture,name)
            before = economics(fixture)
            preparation = CountingPreparation()
            retry_service = CheckoutService(preparation=preparation)
            admin.execute('CREATE SEQUENCE public.p07_retry_attempts')
            admin.execute('GRANT USAGE,SELECT ON SEQUENCE public.p07_retry_attempts TO candidate_runtime')
            admin.execute('''CREATE FUNCTION public.p07_retry_fault() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN IF NEW.id = %s AND NEW.status='checked_out'
                  AND nextval('public.p07_retry_attempts') <= %s THEN
                RAISE EXCEPTION 'synthetic retry fault' USING ERRCODE='40001'; END IF; RETURN NEW; END $$'''
                % (fixture['cart_id'],failures))
            admin.execute('CREATE TRIGGER p07_retry_fault BEFORE UPDATE ON public.carts FOR EACH ROW EXECUTE FUNCTION public.p07_retry_fault()')
            try:
                try:
                    recovered = retry_service.checkout(candidate)
                except DatabaseError as error:
                    assert name == 'retry_exhausted' and error.__cause__.sqlstate == '40001'
                else:
                    assert name == 'retry_success' and not recovered.replayed
                assert admin.execute('SELECT last_value FROM public.p07_retry_attempts').fetchone() == (attempts,)
                assert (preparation.preflights,preparation.prepares) == (1,attempts)
                assert economics(fixture) == (before if name=='retry_exhausted' else (1,1,1,35000,3,0,1,2))
            finally:
                admin.execute('DROP TRIGGER p07_retry_fault ON public.carts')
                admin.execute('DROP FUNCTION public.p07_retry_fault()')
                admin.execute('DROP SEQUENCE public.p07_retry_attempts')
        class StalePreparation(StoreBalancePreparation):
            def preflight(self, setting):
                assert not connection.in_atomic_block
                admin.execute('UPDATE public.manual_payment_settings SET payment_provider_version=payment_provider_version+1')
                return PaymentPreflight(self.provider,self.method,setting,'synthetic-observation')
        fixture = fixtures['preflight']
        candidate = request(fixture,'stale-preflight')
        before = economics(fixture)
        reject(lambda: CheckoutService(preparation=StalePreparation()).checkout(candidate),'stale_preflight')
        assert economics(fixture) == before
        verify_dependencies()
        return {'php_economic_parity':'passed','same_wallet_race':'one_debit_no_overspend',
            'last_stock_race':'one_allocation_one_sale','duplicate_replay':'same_order_payment_single_debit',
            'stale_prices_maintenance_spending_restore':'atomic_rejection',
            'final_step_fault':'ledger_projection_allocation_sale_cart_rolled_back',
            'release_retirement_refund':'idempotent_original_source_no_restock',
            'database_guards_privileges':'passed','bounded_retries':'three_attempt_recovery_five_attempt_ceiling_preflight_once',
            'stale_preflight':'atomic_rejection'}
    finally:
        admin.close()
        connection.close()
