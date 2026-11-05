"""P14 changed-flow proof, admitted only by the disposable PostgreSQL harness."""
from copy import deepcopy
from dataclasses import dataclass, replace
from datetime import datetime, timedelta
import hashlib
import json
import multiprocessing
import os
from unittest.mock import patch
from zoneinfo import ZoneInfo

from tests.test_checkout_postgres import admin_connection


JAKARTA = ZoneInfo('Asia/Jakarta')
RECEIVER = '987654321'


class BinanceTransport:
    def __init__(self, rows=(), *, barrier=None):
        self.rows = list(rows)
        self.failure = False
        self.on_request = None
        self.barrier = barrier
        self.calls = []

    def request(self, method, url, *, headers, timeout, max_response_bytes):
        from django.db import connections
        from tests.test_binance import envelope
        assert all(not c.in_atomic_block and c.get_autocommit()
                   for c in connections.all(initialized_only=True))
        assert method == 'GET' and headers['X-MBX-APIKEY'] == 'synthetic-p14'
        assert 0 < timeout <= 45 and max_response_bytes <= 262144
        self.calls.append((method, url))
        observed = deepcopy(self.rows)
        if self.on_request:
            hook, self.on_request = self.on_request, None
            hook()
        if self.barrier:
            self.barrier.wait(timeout=20)
        return (503, b'{}') if self.failure else envelope(observed)


def bsc_fixture(*, tx_hash, amount, stamp, receiver='0x' + '1' * 40, index=0):
    from tests.test_bsc import BscFixture, block, receipt, transfer_log
    value = BscFixture()
    log = transfer_log(tx_hash=tx_hash, amount=amount, receiver=receiver, index=index)
    value.logs = [log]
    value.receipts = {tx_hash: receipt(transactionHash=tx_hash, logs=[log])}
    value.blocks = {'0x64': block(100, stamp),
                    'latest': block(104, stamp + 4, '0x' + 'c' * 64),
                    'finalized': block(102, stamp + 2, '0x' + 'd' * 64),
                    '0x66': block(102, stamp + 2, '0x' + 'd' * 64)}
    original = value.call

    def outside(method, params=()):
        from django.db import connections
        assert all(not c.in_atomic_block and c.get_autocommit()
                   for c in connections.all(initialized_only=True))
        return original(method, params)
    value.call = outside
    return value


@dataclass(frozen=True)
class HistoricalBscSnapshot:
    """Insert the retained hash-mode contract; never rewrite an accepted snapshot."""
    snapshot: object

    def persist(self, db, payment_id, now):
        from lhie_candidate.checkout.external_usdt import APPROVED_USDT
        s = self.snapshot
        db.require_transaction()
        db.execute('''INSERT INTO public.payment_bsc_details
            (payment_id,receiver_id,receiver_address,chain_id,token_contract,token_decimals,
             base_amount_micro,payable_amount_micro,expected_token_units,verification_status,
             verification_mode,required_confirmations,requires_finalized_block,confirmation_grace_minutes,
             created_at,updated_at) VALUES (%s,%s,%s,56,%s,%s,%s,%s,%s,'awaiting_transfer',
             'transaction_hash',%s,%s,%s,%s,%s)''',
            (payment_id, s.receiver_id, s.receiver_address, APPROVED_USDT, s.token_decimals,
             s.base_amount_micro, s.payable_amount_micro,
             str(s.payable_amount_micro * 10 ** (s.token_decimals - 6)), s.required_confirmations,
             s.requires_finalized_block, s.confirmation_grace_minutes, now, now))


def competing_settlement(rail, payment_id, rows, at, barrier, queue):
    try:
        from lhie_candidate.startup import configure_django
        configure_django()
        from django.db import connections
        from lhie_candidate.checkout.db import Database
        from lhie_candidate.compat.crypto import LaravelEncrypter
        from lhie_candidate.payments.binance import BinanceAdapter, BinanceLifecycle
        from lhie_candidate.payments.bsc import BscLifecycle
        from lhie_candidate.runtime import application_key
        db = Database()
        assert db.one('SELECT current_user AS role')['role'] == 'candidate_runtime'
        cipher = LaravelEncrypter(application_key())
        if rail == 'binance_pay':
            adapter = BinanceAdapter(transport=BinanceTransport(rows, barrier=barrier),
                api_key='synthetic-p14', api_secret='synthetic-only', clock_ms=lambda: int(at.replace(tzinfo=JAKARTA).timestamp()) * 1000)
            lifecycle = BinanceLifecycle(adapter, cipher, clock=lambda: at)
        else:
            adapter = bsc_fixture(**rows)
            original = adapter.call
            def synchronized(method, params=()):
                result = original(method, params)
                if method == 'eth_getTransactionReceipt':
                    barrier.wait(timeout=20)
                return result
            adapter.call = synchronized
            lifecycle = BscLifecycle(adapter, cipher, clock=lambda: at)
        queue.put(('settled', lifecycle.refresh(payment_id).state))
    except Exception as error:
        queue.put(('error', type(error).__name__, str(error)))
    finally:
        from django.db import connections
        connections.close_all()


def settlement_race(rail, payment_id, rows, at):
    from django.db import connections
    connections.close_all()
    context = multiprocessing.get_context('spawn')
    barrier, queue = context.Barrier(3), context.Queue()
    children = [context.Process(target=competing_settlement,
                args=(rail, payment_id, rows, at, barrier, queue)) for _ in range(2)]
    for child in children:
        child.start()
    try:
        # Each child has completed its unlocked authoritative read before either can apply.
        barrier.wait(timeout=30)
        results = [queue.get(timeout=40) for _ in children]
        for child in children:
            child.join(timeout=10)
            assert child.exitcode == 0, 'Settlement process did not exit cleanly'
        assert all(result[0] == 'settled' and result[1] in ('paid', 'pending', 'stale_submission')
                   for result in results) and any(result == ('settled', 'paid') for result in results), results
        return results
    finally:
        for child in children:
            if child.is_alive():
                child.kill()
                child.join()
        queue.close()


def competing_alias_claim(payment_id, transaction_id, order_id, at, barrier, queue):
    try:
        from lhie_candidate.startup import configure_django
        configure_django()
        from lhie_candidate.checkout.lifecycle import OrderLifecycle
        from lhie_candidate.payments.receipts import claim_binance_payment
        orders = OrderLifecycle(clock=lambda: at)
        assert orders.db.one('SELECT current_user AS role')['role'] == 'candidate_runtime'
        synchronized = False

        def operation():
            nonlocal synchronized
            _, payment, _, _ = orders.lock_payment(payment_id)
            if not synchronized:
                synchronized = True
                barrier.wait(timeout=20)
            return claim_binance_payment(orders.db, payment=payment, transaction_id=transaction_id,
                                         order_id=order_id, now=at)

        # A deadlock retries only this database operation, never provider I/O or the barrier.
        claim = orders._run(operation)
        queue.put(('claimed', payment_id, claim.allowed, claim.held))
    except Exception as error:
        queue.put(('error', payment_id, type(error).__name__, str(error)))
    finally:
        from django.db import connections
        connections.close_all()


def reversed_alias_race(first, second, at):
    from django.db import connections
    connections.close_all()
    context = multiprocessing.get_context('spawn')
    barrier, queue = context.Barrier(3), context.Queue()
    left, right = 'P_P14_CROSS_TARGET_TRANSACTION', 'P_P14_CROSS_TARGET_ORDER'
    children = [context.Process(target=competing_alias_claim,
        args=(identity, transaction_id, order_id, at, barrier, queue))
        for identity, transaction_id, order_id in (
            (first.payment_id, left, right), (second.payment_id, right, left))]
    for child in children:
        child.start()
    try:
        barrier.wait(timeout=30)
        outcomes = [queue.get(timeout=40) for _ in children]
        for child in children:
            child.join(timeout=10)
            assert child.exitcode == 0, 'Alias claim process did not exit cleanly'
        assert all(row[0] == 'claimed' for row in outcomes), outcomes
        assert sorted((row[2], row[3]) for row in outcomes) == [(False, True), (True, False)], outcomes
        owner = next(row[1] for row in outcomes if row[2])
        loser = next(row[1] for row in outcomes if row[3])
        return owner, loser, left, right
    finally:
        for child in children:
            if child.is_alive():
                child.kill()
                child.join()
        queue.close()


def exercise_external_usdt(fixtures):
    if os.environ.get('P14_EXTERNAL_SMOKE') != '1':
        raise RuntimeError('Explicit disposable external USDT opt-in required')
    from django.db import DatabaseError, connections, transaction
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.checkout.errors import CheckoutError
    from lhie_candidate.checkout.external_usdt import BscPreparation
    from lhie_candidate.checkout.navigation import NavigationCheckout
    from lhie_candidate.checkout.service import CheckoutRequest, CheckoutService
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.navigation.ingress import UpdateInbox
    from lhie_candidate.navigation.presentation import CatalogPresenter
    from lhie_candidate.navigation.processor import NavigationProcessor, payment_update
    from lhie_candidate.navigation.repository import CatalogRepository
    from lhie_candidate.navigation.roots import NavigationRoots
    from lhie_candidate.navigation.types import Surface, TelegramResult
    from lhie_candidate.payments.binance import BinanceAdapter, BinanceLifecycle
    from lhie_candidate.payments.bsc import BscLifecycle, BscRpcClient
    from lhie_candidate.payments.routing import QrisPayments
    from lhie_candidate.readiness import verify_dependencies
    from lhie_candidate.runtime import application_key
    from lhie_candidate.workers.payment import PaymentHandler
    from psycopg.rows import dict_row
    from tests.test_binance import payload
    from tests.test_bsc import block

    admin, db = admin_connection(), Database()
    tick = [datetime.now(JAKARTA).replace(tzinfo=None) + timedelta(seconds=15)]
    cipher = LaravelEncrypter(application_key())
    transport = BinanceTransport()
    binance = BinanceLifecycle(BinanceAdapter(transport=transport, api_key='synthetic-p14',
        api_secret='synthetic-only', pay_id='111111111',
        clock_ms=lambda: int(tick[0].replace(tzinfo=JAKARTA).timestamp()) * 1000), cipher, clock=lambda: tick[0])
    bsc = BscLifecycle(BscRpcClient(), cipher, clock=lambda: tick[0])
    rails = QrisPayments(external_lifecycles={'BINANCE_PAY_ID': binance, 'USDT_BEP20': bsc})
    worker = PaymentHandler(rails, db)
    navigation = NavigationCheckout(lifecycle=rails, key=application_key())
    repository = CatalogRepository()
    from lhie_candidate.workers.services import _external_checkout_selectable
    presenter = CatalogPresenter(repository, checkout=navigation,
                                 dollar_rail_selectable=_external_checkout_selectable)
    delivered = []

    class NavigationTransport:
        def execute(self, method, payload):
            assert not connections['default'].in_atomic_block
            delivered.append((method, payload))
            return TelegramResult(10000 + len(delivered), 'success')

    telegram = NavigationTransport()
    roots = NavigationRoots(telegram)
    external = fixtures['external']

    def payment(result):
        with admin.cursor(row_factory=dict_row) as cursor:
            return cursor.execute('SELECT * FROM public.payments WHERE id=%s', (result.payment_id,)).fetchone()

    def detail(result):
        return db.one('SELECT * FROM public.payment_bsc_details WHERE payment_id=%s', (result.payment_id,))

    def economic(result):
        return admin.execute('''SELECT o.order_status,o.payment_status,p.status,
            (SELECT count(*) FROM public.inventory_movements WHERE order_id=o.id AND type='sale'),
            (SELECT count(*) FROM public.external_money_receipts WHERE target_id=p.id::text),
            (SELECT count(*) FROM public.manual_fulfillment_reviews WHERE payment_id=p.id)
            FROM public.orders o JOIN public.payments p ON p.order_id=o.id WHERE o.id=%s''', (result.order_id,)).fetchone()

    def pending(result):
        assert economic(result) == ('pending_payment', 'pending', 'pending', 0, 0, 0), economic(result)

    def begin(name, rail='binance_pay', *, historical=False):
        fixture = external[name]
        preparation = rails.preparation_for_rail(rail)
        if historical:
            class HistoricalPreparation(BscPreparation):
                def prepare(self, pricing, setting, preflight, now):
                    plan = super().prepare(pricing, setting, preflight, now)
                    return replace(plan, rail_snapshot=HistoricalBscSnapshot(plan.rail_snapshot))
            preparation = HistoricalPreparation(bsc.adapter)
        service = CheckoutService(preparation=preparation)
        request = CheckoutRequest(fixture['customer_id'], fixture['cart_id'], 'p14-' + name,
                                  service.quote_cart(fixture['customer_id'], fixture['cart_id']))
        result = service.checkout(request)
        assert service.checkout(request).replayed
        row = payment(result)
        assert (row['provider'], row['currency'], row['method']) == ('manual', 'USDT',
            'BINANCE_PAY_ID' if rail == 'binance_pay' else 'USDT_BEP20')
        assert admin.execute('SELECT currency,total_amount FROM public.orders WHERE id=%s', (result.order_id,)).fetchone() == ('IDR', 15000)
        assert admin.execute('SELECT unit_price,subtotal,usdt_unit_price,usdt_subtotal FROM public.order_items WHERE order_id=%s', (result.order_id,)).fetchall() == [(15000, 15000, 1000000, 1000000)]
        pending(result)
        return result

    def binance_row(result, serial, **changes):
        tick[0] = max(tick[0], datetime.now(JAKARTA).replace(tzinfo=None) + timedelta(seconds=15))
        p = payment(result)
        micro = p['amount']
        stamp = int(p['created_at'].replace(tzinfo=JAKARTA).timestamp()) * 1000 + 1000
        return payload(orderId=str(445011487581044736 + serial), transactionId='P_P14_TRANSACTION_' + str(serial),
            amount=f'{micro // 1000000}.{micro % 1000000:06}', transactionTime=stamp,
            receiverInfo={'accountId': p['binance_receiver_value']}, **changes)

    def bsc_rows(result, serial, **changes):
        tick[0] = max(tick[0], datetime.now(JAKARTA).replace(tzinfo=None) + timedelta(seconds=15))
        d, p = detail(result), payment(result)
        return dict(tx_hash='0x' + format(serial, '064x'), amount=int(d['expected_token_units']),
                    stamp=int(p['created_at'].replace(tzinfo=JAKARTA).timestamp()) + 1,
                    receiver=d['receiver_address']) | changes

    def root_for(name):
        customer = db.one('SELECT id,telegram_id,first_name,locale FROM public.customers WHERE id=%s',
                          (external[name]['customer_id'],))
        assert roots.establish(customer, str(customer['telegram_id']),
            Surface('Synthetic P14 catalog', {'inline_keyboard': []}, 'catalog')) == 'established'
        return customer, roots.current_for_chat(customer, str(customer['telegram_id']))

    try:
        verify_dependencies()
        assert db.one('SELECT current_database() AS db,current_user AS role') == {'db': 'disposable', 'role': 'candidate_runtime'}
        defaults = admin.execute('SELECT binance_pay_enabled,bsc_payment_enabled FROM public.manual_payment_settings').fetchone()
        assert defaults == (False, False)
        assert BscRpcClient().readiness() == {'ready': False}
        funding_before = admin.execute('SELECT row_to_json(p)::text FROM public.customer_usdt_funding_policies p ORDER BY id').fetchall()
        assert admin.execute('SELECT bool_or(funding_enabled) FROM public.customer_usdt_funding_policies').fetchone() == (False,)
        # Only the disposable owner enables synthetic receiving, never the runtime role.
        admin.execute('''UPDATE public.manual_payment_settings SET binance_pay_enabled=true,
            bsc_payment_enabled=true,binance_verification_mode='amount_unique',
            binance_receiver_type='pay_id',binance_receiver_value=%s,bsc_min_confirmations=3,
            bsc_require_finalized_block=true,bsc_confirmation_grace_minutes=3''', (RECEIVER,))
        bsc.adapter = bsc_fixture(tx_hash='0x' + 'a' * 64, amount=10**18,
                                  stamp=int(tick[0].replace(tzinfo=JAKARTA).timestamp()) - 10)

        unique = begin('binance_unique')
        snapshot = payment(unique)
        valid = binance_row(unique, 1)
        for changes in ({'amount': '0.999999'}, {'currency': 'BTC'},
                        {'receiverInfo': {'accountId': '999999999'}}, {'status': 'FAILED'}):
            transport.rows = [valid | changes]
            assert binance.refresh(unique.payment_id).state == 'pending'
            pending(unique)
        transport.failure = True
        assert binance.refresh(unique.payment_id).state == 'pending'
        pending(unique)
        transport.failure = False
        # Current receiving config cannot retarget an already-accepted Payment.
        admin.execute("UPDATE public.manual_payment_settings SET binance_receiver_value='222222222'")
        rotated_binance = begin('binance_rotation')
        assert payment(rotated_binance)['binance_receiver_value'] == '222222222'
        assert rotated_binance.amount == unique.amount + 1000
        transport.rows = [valid]
        assert binance.classify(unique.payment_id).presentation['receiver_value'] == RECEIVER
        assert binance.refresh(unique.payment_id).state == 'paid'
        assert binance.refresh(unique.payment_id).state == 'paid'
        assert economic(unique) == ('processing', 'paid', 'paid', 1, 1, 0), economic(unique)
        assert payment(unique)['binance_receiver_value'] == snapshot['binance_receiver_value']
        assert admin.execute('SELECT count(*) FROM public.external_money_receipt_aliases a JOIN public.external_money_receipts r ON r.id=a.external_money_receipt_id WHERE r.target_id=%s', (unique.payment_id,)).fetchone() == (2,)
        admin.execute("UPDATE public.manual_payment_settings SET binance_receiver_value=%s,binance_verification_mode='order_id'", (RECEIVER,))

        ordered = begin('binance_order')
        transport.rows = [binance_row(ordered, 2)]
        assert binance.refresh(ordered.payment_id).state == 'created'
        pending(ordered)
        assert binance.submit_order_id(ordered.payment_id, transport.rows[0]['orderId'], customer_id=external['binance_order']['customer_id']).state == 'accepted'
        assert ordered.payment_id in set(map(str, worker._external_payments(100)))
        assert worker._external_reconcile(ordered.payment_id).state == 'paid'
        assert ordered.payment_id not in set(map(str, worker._external_payments(100)))
        compatibility = begin('binance_compat')
        transport.rows = [binance_row(compatibility, 3)]
        assert binance.submit_order_id(compatibility.payment_id, transport.rows[0]['transactionId'], customer_id=external['binance_compat']['customer_id']).state == 'accepted'
        assert binance.refresh(compatibility.payment_id).state == 'paid'
        for name, row in (('binance_duplicate', valid), ('binance_reversed', valid | {
                'transactionId': valid['orderId'], 'orderId': valid['transactionId']})):
            duplicate = begin(name)
            transport.rows = [row]
            assert binance.submit_order_id(duplicate.payment_id, row['transactionId'], customer_id=external[name]['customer_id']).state == 'accepted'
            assert binance.refresh(duplicate.payment_id).state == 'review'
            assert economic(duplicate)[3:] == (0, 0, 1)

        stale = begin('binance_stale')
        old = binance_row(stale, 4)
        transport.rows = [old]
        assert binance.submit_order_id(stale.payment_id, old['orderId'], customer_id=external['binance_stale']['customer_id']).state == 'accepted'
        transport.on_request = lambda: binance.submit_order_id(stale.payment_id, '445011487581099999', customer_id=external['binance_stale']['customer_id'])
        assert binance.refresh(stale.payment_id).state == 'pending'
        pending(stale)
        assert payment(stale)['binance_order_id_submission_version'] == 2
        assert admin.execute('SELECT count(*) FROM public.payment_events WHERE payment_id=%s', (stale.payment_id,)).fetchone() == (0,)

        late = begin('binance_late')
        late_row = binance_row(late, 5)
        assert binance.submit_order_id(late.payment_id, late_row['orderId'], customer_id=external['binance_late']['customer_id']).state == 'accepted'
        binance.orders.apply_pending_cancellation(late.order_id, customer_id=external['binance_late']['customer_id'])
        transport.rows = [late_row]
        assert binance.refresh(late.payment_id).state == 'review'
        assert economic(late) == ('cancelled', 'paid', 'paid', 0, 1, 1)

        discovery = begin('bsc_discovery', 'bsc')
        observed = bsc_rows(discovery, 101)
        bsc.adapter = bsc_fixture(**observed)
        good_log = deepcopy(bsc.adapter.logs[0])
        for altered in (dict(good_log, address='0x' + '4' * 40),
                        dict(good_log, topics=good_log['topics'][:2] + ['0x' + '0' * 24 + '2' * 40]),
                        dict(good_log, data='0x' + format(observed['amount'] + 1, '064x'))):
            bsc.adapter.logs = [altered]
            assert bsc.refresh(discovery.payment_id).state == 'pending'
            pending(discovery)
        bsc.adapter.logs = [good_log]
        bsc.adapter.chain = '0x1'
        assert bsc.refresh(discovery.payment_id).state == 'pending'
        pending(discovery)
        bsc.adapter.chain = '0x38'
        with patch.object(bsc.adapter, 'call', side_effect=TimeoutError('synthetic outage')):
            assert bsc.refresh(discovery.payment_id).state == 'pending'
        pending(discovery)
        bsc.adapter.blocks['latest'] = block(101, observed['stamp'] + 1, '0x' + 'c' * 64)
        assert bsc.refresh(discovery.payment_id).state == 'pending'
        assert detail(discovery)['verification_status'] == 'confirming'
        pending(discovery)
        bsc.adapter.blocks['latest'] = block(104, observed['stamp'] + 4, '0x' + 'c' * 64)
        bsc.adapter.blocks['finalized'] = None
        assert bsc.refresh(discovery.payment_id).state == 'pending'
        pending(discovery)
        bsc.adapter = bsc_fixture(**observed)
        assert bsc.refresh(discovery.payment_id).state == 'paid'
        assert bsc.refresh(discovery.payment_id).state == 'paid'
        assert economic(discovery) == ('processing', 'paid', 'paid', 1, 1, 0), economic(discovery)
        assert admin.execute('SELECT count(*) FROM public.external_money_receipt_parent_claims WHERE target_id=%s', (discovery.payment_id,)).fetchone() == (1,)

        historical = begin('bsc_hash', 'bsc', historical=True)
        historical_rows = bsc_rows(historical, 102)
        bsc.adapter = bsc_fixture(**historical_rows)
        assert bsc.refresh(historical.payment_id).state == 'pending'
        pending(historical)
        hash_customer, hash_root = root_for('bsc_hash')
        hash_view = navigation.check(hash_customer, hash_root, payment_id=historical.payment_id)
        assert hash_view.state == 'pending' and hash_view.external['verification_mode'] == 'transaction_hash'
        hash_surface = presenter._checkout_surface(hash_customer, hash_view)
        assert any(button.get('callback_data') == 'checkout:hint:hash:' + historical.payment_id
                   for row in hash_surface.keyboard['inline_keyboard'] for button in row)
        assert navigation.hint_prompt(hash_customer, hash_root,
            payment_id=historical.payment_id, kind='hash').state == 'hint_hash'
        assert navigation.submit_hint(hash_customer, hash_root, payment_id=historical.payment_id,
            kind='hash', candidate=historical_rows['tx_hash']).state == 'paid'
        assert bsc.refresh(historical.payment_id).state == 'paid'
        duplicate = begin('bsc_duplicate', 'bsc', historical=True)
        assert bsc.submit_hash(duplicate.payment_id, historical_rows['tx_hash'], customer_id=external['bsc_duplicate']['customer_id']).state == 'accepted'
        assert bsc.refresh(duplicate.payment_id).state == 'review'
        assert economic(duplicate)[3:] == (0, 0, 1)

        for serial, name, changes in ((103, 'bsc_bad_token', {'address': '0x' + '4' * 40}),
                (104, 'bsc_bad_receiver', {'topics': good_log['topics'][:2] + ['0x' + '0' * 24 + '2' * 40]}),
                (105, 'bsc_bad_amount', {'data': '0x' + format(10**18 + 1, '064x')})):
            wrong = begin(name, 'bsc', historical=True)
            rows = bsc_rows(wrong, serial)
            bsc.adapter = bsc_fixture(**rows)
            bsc.adapter.receipts[rows['tx_hash']]['logs'][0].update(changes)
            assert bsc.submit_hash(wrong.payment_id, rows['tx_hash'], customer_id=external[name]['customer_id']).state == 'accepted'
            assert bsc.refresh(wrong.payment_id).state == 'review'
            assert economic(wrong)[3] == 0 and economic(wrong)[5] >= 1

        parent = begin('bsc_parent', 'bsc', historical=True)
        parent_rows = bsc_rows(parent, 106)
        tx_hash = parent_rows['tx_hash']
        # Historical global funding identity exists without a candidate self-alias.
        prior_key = 'v1|bsc_log|chain:56|tx:' + tx_hash + '|log:7'
        prior_units = str(parent_rows['amount'])
        prior_fingerprint = hashlib.sha256(json.dumps({
            'version': 1, 'identity': prior_key, 'beneficiary': '0x' + '1' * 40,
            'currency': 'USDT', 'amount_units': prior_units, 'decimals': 18,
            'target_type': 'customer_usdt_top_up', 'target_id': 'synthetic-p14-prior-funding'},
            separators=(',', ':')).encode()).hexdigest()
        receipt_id = admin.execute('''INSERT INTO public.external_money_receipts
            (canonical_key,provider,identity_kind,chain_id,tx_hash,log_index,beneficiary,currency,
             amount_units,decimals,status,target_type,target_id,fingerprint,claimed_at,created_at,updated_at)
            VALUES (%s,'bsc','bsc_log',56,%s,7,%s,'USDT',%s,18,'claimed',
             'customer_usdt_top_up','synthetic-p14-prior-funding',%s,now(),now(),now()) RETURNING id''',
            (prior_key, tx_hash, '0x' + '1' * 40, prior_units, prior_fingerprint)).fetchone()[0]
        admin.execute('''INSERT INTO public.external_money_receipt_parent_claims
            (chain_id,tx_hash,external_money_receipt_id,status,target_type,target_id,created_at,updated_at)
            VALUES (56,%s,%s,'claimed','customer_usdt_top_up','synthetic-p14-prior-funding',now(),now())''', (tx_hash, receipt_id))
        bsc.adapter = bsc_fixture(**parent_rows)
        assert bsc.submit_hash(parent.payment_id, tx_hash, customer_id=external['bsc_parent']['customer_id']).state == 'accepted'
        assert bsc.refresh(parent.payment_id).state == 'review'
        assert economic(parent)[3] == 0
        assert admin.execute('SELECT status FROM public.external_money_receipt_parent_claims WHERE chain_id=56 AND tx_hash=%s', (tx_hash,)).fetchone() == ('review',)

        stale_bsc = begin('bsc_stale', 'bsc', historical=True)
        stale_rows = bsc_rows(stale_bsc, 107)
        bsc.adapter = bsc_fixture(**stale_rows)
        assert bsc.submit_hash(stale_bsc.payment_id, stale_rows['tx_hash'], customer_id=external['bsc_stale']['customer_id']).state == 'accepted'
        original = bsc.adapter.call
        revised = [False]
        def revise_hash(method, params=()):
            value = original(method, params)
            if method == 'eth_getTransactionReceipt' and not revised[0]:
                revised[0] = True
                assert bsc.submit_hash(stale_bsc.payment_id, '0x' + 'e' * 64, customer_id=external['bsc_stale']['customer_id']).state == 'accepted'
            return value
        bsc.adapter.call = revise_hash
        assert bsc.refresh(stale_bsc.payment_id).state == 'stale_submission'
        pending(stale_bsc)
        assert detail(stale_bsc)['tx_submission_version'] == 2
        assert admin.execute('SELECT count(*) FROM public.payment_events WHERE payment_id=%s', (stale_bsc.payment_id,)).fetchone() == (0,)

        late_bsc = begin('bsc_late', 'bsc', historical=True)
        late_rows = bsc_rows(late_bsc, 108)
        bsc.adapter = bsc_fixture(**late_rows)
        assert bsc.submit_hash(late_bsc.payment_id, late_rows['tx_hash'], customer_id=external['bsc_late']['customer_id']).state == 'accepted'
        bsc.orders.apply_pending_cancellation(late_bsc.order_id, customer_id=external['bsc_late']['customer_id'])
        assert bsc.refresh(late_bsc.payment_id).state == 'review'
        assert economic(late_bsc) == ('cancelled', 'paid', 'paid', 0, 1, 1)

        rotation = begin('bsc_rotation', 'bsc')
        immutable = detail(rotation)
        admin.execute('UPDATE public.bsc_payment_receivers SET is_active=(id=%s) WHERE id IN (%s,%s)',
                      (fixtures['external_receivers'][1], *fixtures['external_receivers']))
        assert bsc.classify(rotation.payment_id).presentation['receiver_address'] == immutable['receiver_address']
        assert detail(rotation) == immutable
        rotated_customer, rotated_root = root_for('navigation_bsc')
        rotated_view = navigation.checkout(rotated_customer, rotated_root,
            product_id=external['navigation_bsc']['product_id'], quantity=1, token='p14-bsc', rail='bsc')
        assert rotated_view.state == 'pending' and rotated_view.external['receiver_address'] == '0x' + '3' * 40
        assert rotated_view.amount != rotation.amount
        assert 'BEP20' in presenter._checkout_surface(rotated_customer, rotated_view).caption
        bsc.adapter = bsc_fixture(**bsc_rows(rotated_view, 111))
        assert rotated_view.payment_id in set(map(str, worker._external_payments(100)))
        assert worker._external_reconcile(rotated_view.payment_id).state == 'paid'
        assert rotated_view.payment_id not in set(map(str, worker._external_payments(100)))

        nav_customer, nav_root = root_for('navigation_binance')
        detail_surface = presenter.route(nav_customer, 'product:' + str(external['navigation_binance']['product_id']),
                                         {}, root=nav_root, chat_id=str(nav_customer['telegram_id']))
        assert any(button.get('callback_data', '').startswith('detail:pay:binance:')
                   for row in detail_surface.keyboard['inline_keyboard'] for button in row)
        selection = {'selection': {'mode': 'browsing', 'product_id': external['navigation_binance']['product_id'],
            'interaction_token': 'p14-choice', 'locale': 'id', 'quantity': 1}}
        choice = presenter.route(nav_customer, 'detail:pay:binance:' + str(external['navigation_binance']['product_id']) + ':p14-choice', selection,
                                 root=nav_root, chat_id=str(nav_customer['telegram_id']))
        choices = [b['callback_data'] for row in choice.keyboard['inline_keyboard'] for b in row]
        assert any(x.startswith('detail:pay:binance_pay:') for x in choices)
        assert any(x.startswith('detail:pay:bsc:') for x in choices)
        view = navigation.checkout(nav_customer, nav_root, product_id=external['navigation_binance']['product_id'],
            quantity=1, token='p14-choice', rail='binance_pay')
        assert view.state == 'pending' and view.rail == 'binance_pay'
        assert 'BINANCE PAY ID' in presenter._checkout_surface(nav_customer, view).caption
        assert navigation.checkout(nav_customer, nav_root, product_id=external['navigation_binance']['product_id'],
            quantity=1, token='p14-choice', rail='binance_pay').payment_id == view.payment_id
        assert navigation.hint_prompt(rotated_customer, rotated_root, payment_id=view.payment_id, kind='order').state == 'stale'
        transport.rows = []
        processor = NavigationProcessor(repository, presenter, roots, telegram, family='payment')
        def update(number, *, data=None, text=None):
            current = roots.current_for_chat(nav_customer, str(nav_customer['telegram_id']))
            sender = {'id': nav_customer['telegram_id'], 'first_name': nav_customer['first_name']}
            message = {'message_id': current.message_id, 'chat': {'id': nav_customer['telegram_id'], 'type': 'private'}}
            value = ({'callback_query': {'id': 'p14-' + str(number), 'from': sender, 'message': message, 'data': data}}
                     if data else {'message': dict(message, message_id=number, **{'from': sender}, text=text)})
            value['update_id'] = number
            with patch('lhie_candidate.workers.wakeup.enqueue'):
                received = UpdateInbox().receive(value)
            return value, received.update_id
        _, prompt_id = update(14001, data='checkout:hint:order:' + view.payment_id)
        processor.process(prompt_id)
        prompt_state = db.one('SELECT state FROM django_candidate.navigation_sessions WHERE customer_id=%s',
                              (nav_customer['id'],))['state']
        if isinstance(prompt_state, str):
            prompt_state = json.loads(prompt_state)
        bound = roots.current_for_chat(nav_customer, str(nav_customer['telegram_id']))
        assert prompt_state['payment_hint'] == {
            'payment_id': view.payment_id, 'kind': 'order', 'chat_id': bound.chat_id,
            'message_id': bound.message_id, 'generation': bound.generation}
        hint_payload, hint_id = update(14002, text='445011487581012345')
        assert payment_update(hint_payload)
        interaction = NavigationProcessor(repository, presenter, roots, telegram, family='interaction')
        assert interaction.process(hint_id) == 'duplicate'
        processor.process(hint_id)
        assert db.one('SELECT state FROM django_candidate.navigation_updates WHERE update_id=%s',
                      (hint_id,)) == {'state': 'processed'}
        assert payment(view)['binance_order_id'] == '445011487581012345'
        pending(view)
        # A root generation change rejects a prompt captured on the prior screen.
        current = roots.current_for_chat(nav_customer, str(nav_customer['telegram_id']))
        state = {}
        presenter.route(nav_customer, 'checkout:hint:order:' + view.payment_id, state, root=current, chat_id=current.chat_id)
        changed = replace(current, generation=current.generation + 1)
        assert presenter.payment_hint(nav_customer, '445011487581012346', state, changed) is None
        assert 'payment_hint' not in state

        admin.execute("UPDATE public.manual_payment_settings SET binance_verification_mode='amount_unique'")
        racing = begin('binance_race')
        race_row = binance_row(racing, 109)
        settlement_race('binance_pay', racing.payment_id, [race_row], tick[0])
        assert economic(racing) == ('processing', 'paid', 'paid', 1, 1, 0), economic(racing)
        racing_bsc = begin('bsc_race', 'bsc')
        race_rows = bsc_rows(racing_bsc, 110)
        settlement_race('bsc', racing_bsc.payment_id, race_rows, tick[0])
        assert economic(racing_bsc) == ('processing', 'paid', 'paid', 1, 1, 0), economic(racing_bsc)

        admin.execute("UPDATE public.manual_payment_settings SET binance_verification_mode='order_id'")
        alias_first, alias_second = begin('binance_alias_first'), begin('binance_alias_second')
        assert payment(alias_first)['binance_receiver_value'] == payment(alias_second)['binance_receiver_value']
        owner, loser, left, right = reversed_alias_race(alias_first, alias_second, tick[0])
        claims = admin.execute('''SELECT id,target_id FROM public.external_money_receipts
            WHERE target_id=ANY(%s)''', ([alias_first.payment_id, alias_second.payment_id],)).fetchall()
        assert len(claims) == 1 and claims[0][1] == owner, claims
        aliases = admin.execute('''SELECT alias_key,external_money_receipt_id
            FROM public.external_money_receipt_aliases WHERE alias_key=ANY(%s) ORDER BY alias_key''',
            (['v1|provider_reference|binance|pay_id:' + RECEIVER + '|' + value
              for value in (left, right)],)).fetchall()
        assert len(aliases) == 2 and {row[1] for row in aliases} == {claims[0][0]}, aliases
        reservations = admin.execute('''SELECT legacy_id FROM public.external_money_receipt_reservations
            WHERE legacy_type='payment' AND legacy_id=ANY(%s)''',
            ([alias_first.payment_id, alias_second.payment_id],)).fetchall()
        assert reservations == [(loser,)], reservations
        assert economic(alias_first)[3] == economic(alias_second)[3] == 0

        from lhie_candidate.receipt.runtime import receipt_service
        from lhie_candidate.receipt.transport import ReceiptResult
        receipts = receipt_service()
        receipt_calls = []
        class ReceiptTransport:
            def execute(self, method, payload):
                assert not connections['default'].in_atomic_block
                receipt_calls.append((method, payload))
                return ReceiptResult(15000 + len(receipt_calls), 'success')
        receipts.transport = ReceiptTransport()
        effect = receipts.materialize(unique.order_id)
        assert effect is not None
        while effect is not None:
            assert receipts.deliver(str(effect['id'])) in ('sent', 'text_fallback')
            effect = receipts.db.one("SELECT * FROM public.telegram_outbound_effects WHERE source_id=%s AND state='pending' AND effect_type='receipt.credentials' ORDER BY sequence LIMIT 1", (unique.order_id,))
        needle = 'Synthetic P07 p14-binance_unique credential'
        assert any(needle.encode() in data.get('document', b'') or needle in data.get('text', '')
                   or needle in data.get('caption', '') for _, data in receipt_calls)
        assert admin.execute('SELECT telegram_receipt_sent_at IS NOT NULL FROM public.orders WHERE id=%s', (unique.order_id,)).fetchone() == (True,)

        from tests.test_public_receiving import exercise_public_receiving_settings
        receiving = exercise_public_receiving_settings(fixtures)
        denied = []
        for name, sql, params in (
                ('payment_amount', 'UPDATE public.payments SET amount=amount+1 WHERE id=%s', (unique.payment_id,)),
                ('binance_receiver_snapshot', 'UPDATE public.payments SET binance_receiver_value=%s WHERE id=%s', ('777777777', unique.payment_id)),
                ('bsc_snapshot', 'UPDATE public.payment_bsc_details SET payable_amount_micro=payable_amount_micro+1 WHERE payment_id=%s', (discovery.payment_id,)),
                ('funding_activation', 'UPDATE public.customer_usdt_funding_policies SET funding_enabled=true', ())):
            try:
                with transaction.atomic():
                    db.execute(sql, params)
                    raise AssertionError('Runtime privilege unexpectedly allowed ' + name)
            except DatabaseError as error:
                assert getattr(error.__cause__, 'sqlstate', None) == '42501', (name, error)
                denied.append(name)
        assert admin.execute('SELECT row_to_json(p)::text FROM public.customer_usdt_funding_policies p ORDER BY id').fetchall() == funding_before
        admin.execute('UPDATE public.manual_payment_settings SET binance_pay_enabled=false,bsc_payment_enabled=false')
        assert admin.execute('SELECT binance_pay_enabled,bsc_payment_enabled FROM public.manual_payment_settings').fetchone() == defaults
        hidden = presenter.route(nav_customer, 'product:' + str(external['navigation_binance']['product_id']),
                                 {}, root=nav_root, chat_id=str(nav_customer['telegram_id']))
        assert not any(button.get('callback_data', '').startswith('detail:pay:binance:')
                       for row in hidden.keyboard['inline_keyboard'] for button in row)
        verify_dependencies()
        return {'status': 'passed', 'dataset': 'synthetic-p14-external-usdt',
            'scope': ['Actual checkout and navigation Binance/BSC choices, immutable receiver instructions and owner-bound hints',
                'Binance authoritative exact money, amount_unique/orderId/transactionId compatibility and reversed global aliases',
                'BSC discovery and retained hash mode, chain/token/receiver/amount/finality/outage and confirming-to-paid',
                'Global BSC funding parent identity, duplicate references, stale network fences, late-terminal no-sale',
                'Synchronized multiprocess settlement on each rail as candidate_runtime, one sale and receipt',
                'Cross-target reversed Binance alias race: one canonical owner, two aliases, one held-target reservation',
                'Authorized receipt credential delivery, owner public receiving settings, runtime SQL privilege denial'],
            'privilege_denials': denied, 'receiving_settings': receiving,
            'limits': ['Disposable PostgreSQL and authoritative synthetic transports only; no live rail qualification or funding activation',
                       'Shared cart/stock/wallet invariants reuse P07/P12 proof; no broad regression matrix']}
    finally:
        admin.close()
        connections.close_all()
