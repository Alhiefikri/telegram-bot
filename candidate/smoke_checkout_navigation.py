"""Disposable #720 proof: navigation detail -> checkout -> paid -> receipt.

Run only inside the admitted P01 candidate namespace:

    P720_CHECKOUT_SMOKE=disposable-local-only python smoke_checkout_navigation.py

The provider is an in-process fake that implements the existing P08 sandbox
contract. No network, real credential, real money, production provider or live
Telegram target is used. Neither schema nor grants are installed here.
"""

from datetime import datetime, timedelta
import json
import os
import sys
import threading
import time
from zoneinfo import ZoneInfo

from lhie_candidate.payments.sakurupiah import (
    Channel, OutcomeState, ProviderOutcome,
)


OPT_IN = 'disposable-local-only'
FEE = 200
QRIS = 'https://sakurupiah.id/qr/synthetic-navigation.png'


def _admin():
    if os.environ.get('P720_CHECKOUT_SMOKE') != OPT_IN:
        raise RuntimeError('Explicit disposable checkout-navigation opt-in required')
    import psycopg
    connection = psycopg.connect(
        host='127.0.0.1', hostaddr='127.0.0.1', port=5432, dbname='disposable',
        user='harness', password='harness', connect_timeout=3, autocommit=True)
    identity = connection.execute(
        'SELECT current_database(),current_user,host(inet_server_addr()),inet_server_port()'
    ).fetchone()
    if identity != ('disposable', 'harness', '127.0.0.1', 5432):
        connection.close()
        raise RuntimeError('Disposable checkout-navigation identity mismatch')
    return connection


class FakeProvider:
    """Sandbox-shaped adapter; no HTTP and no provider credential."""

    def __init__(self):
        self.reference = 'trx-navigation-1'
        self.state = OutcomeState.PENDING
        self.creates = 0
        self.checks = 0
        self.lock = threading.Lock()

    def list_channels(self):
        return (Channel('QRIS', True, 1, 10_000_000_000),)

    def create(self, request):
        with self.lock:
            self.creates += 1
        return ProviderOutcome(
            request.identity, OutcomeState.CREATED, self.reference,
            request.identity.amount + FEE, FEE,
            (datetime.now(ZoneInfo('Asia/Jakarta')) + timedelta(minutes=10)).isoformat(timespec='seconds'),
            QRIS)

    def status(self, identity, provider_ref, *, provider_total):
        with self.lock:
            self.checks += 1
        return ProviderOutcome(identity, self.state, provider_ref)

    def recover(self, identity, *, provider_total):
        return self.status(identity, self.reference, provider_total=provider_total)


class RootTransport:
    def __init__(self):
        self.message_id = 900

    def execute(self, method, payload):
        from lhie_candidate.navigation.types import TelegramResult
        if method in ('sendMessage', 'sendPhoto', 'sendAnimation'):
            self.message_id += 1
            return TelegramResult(self.message_id, 'success')
        if method == 'deleteMessage':
            return TelegramResult(None, 'success')
        return TelegramResult(None, 'success')


class FakeReceiptTransport:
    def __init__(self):
        self.calls = 0
        self.message_id = 2000

    def execute(self, method, payload):
        from lhie_candidate.receipt.transport import ReceiptResult
        self.calls += 1
        self.message_id += 1
        return ReceiptResult(self.message_id, 'success')


def _admin_counters(admin, customer_id):
    return admin.execute('''SELECT
        (SELECT count(*) FROM public.orders WHERE customer_id=%s),
        (SELECT count(*) FROM public.payments p JOIN public.orders o ON o.id=p.order_id
         WHERE o.customer_id=%s),
        (SELECT count(*) FROM public.inventory_movements m JOIN public.orders o ON o.id=m.order_id
         WHERE o.customer_id=%s AND m.type='reservation'),
        (SELECT count(*) FROM public.inventory_movements m JOIN public.orders o ON o.id=m.order_id
         WHERE o.customer_id=%s AND m.type='sale'),
        (SELECT count(*) FROM public.external_money_receipts r JOIN public.payments p ON p.id::text=r.target_id
         WHERE p.order_id IN (SELECT id FROM public.orders WHERE customer_id=%s))''',
        (customer_id, customer_id, customer_id, customer_id, customer_id)).fetchone()


def _service():
    from lhie_candidate.checkout.navigation import NavigationCheckout, sandbox_request
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.payments.lifecycle import SakurupiahLifecycle
    from lhie_candidate.runtime import application_key
    provider = FakeProvider()
    lifecycle = SakurupiahLifecycle(provider, LaravelEncrypter(application_key()))
    return provider, NavigationCheckout(
        lifecycle=lifecycle, request_factory=sandbox_request, key=application_key())


def _establish(roots, presenter, customer, chat_id, product_id, state):
    surface = presenter.detail(customer, product_id, state=state)
    assert surface is not None
    outcome = roots.establish(customer, chat_id, surface)
    assert outcome == 'established', outcome
    root = roots.current_for_chat(customer, chat_id)
    assert root is not None and root.screen == 'product'
    return root, surface, state


def _callback(surface, prefix):
    for row in surface.keyboard['inline_keyboard']:
        for button in row:
            if button.get('callback_data', '').startswith(prefix):
                return button['callback_data']
    raise AssertionError('Missing callback ' + prefix)


def _render(roots, presenter, customer, chat_id, state, data, root):
    started = time.monotonic_ns()
    surface = presenter.route(customer, data, state, root=root, chat_id=chat_id)
    elapsed = (time.monotonic_ns() - started) / 1_000_000
    assert surface is not None
    outcome = roots.render(customer, root, chat_id, surface)
    assert outcome in ('applied', 'replaced'), outcome
    return surface, roots.current_for_chat(customer, chat_id), elapsed


def _receipt_evidence(admin, order_id, transport):
    from lhie_candidate.receipt.runtime import receipt_service
    service = receipt_service()
    service.transport = transport
    started = time.monotonic_ns()
    first = service.materialize(str(order_id))
    queued_ms = (time.monotonic_ns() - started) / 1_000_000
    assert first is not None and first['state'] != 'failed'
    rows = admin.execute('''SELECT id,sequence,state FROM public.telegram_outbound_effects
        WHERE effect_type='receipt.credentials' AND source_id=%s ORDER BY sequence''',
        (str(order_id),)).fetchall()
    assert rows and all(row[2] != 'failed' for row in rows)
    delivery_started = time.monotonic_ns()
    assert service.deliver(first['id']) == 'sent'
    pickup_ms = (time.monotonic_ns() - delivery_started) / 1_000_000
    for effect_id, _, _ in rows[1:]:
        assert service.deliver(effect_id) == 'sent'
    group_ms = (time.monotonic_ns() - delivery_started) / 1_000_000
    assert admin.execute('SELECT telegram_receipt_sent_at IS NOT NULL FROM public.orders WHERE id=%s',
                         (str(order_id),)).fetchone() == (True,)
    assert service.deliver(rows[0][0]) == 'not_ready'
    return {
        'receipt_queued_ms': queued_ms,
        'receipt_pickup_ms': pickup_ms,
        'credential_ack_ms': pickup_ms,
        'group_complete_ms': group_ms,
        'receipt_effects': len(rows),
        'credential_ack': True,
        'group_complete': True,
        'duplicate_delivery_after_completion': 'not_ready',
    }


def _balance_flow(admin, roots, presenter, checkout, customer, chat_id, product_id):
    state = {}
    root, detail, state = _establish(roots, presenter, customer, chat_id, product_id, state)
    before = admin.execute('SELECT balance FROM public.customer_balance_accounts WHERE customer_id=%s',
                           (customer['id'],)).fetchone()[0]
    surface, root, elapsed = _render(
        roots, presenter, customer, chat_id, state, _callback(detail, 'detail:pay:balance:'), root)
    assert 'Pembayaran dengan Saldo berhasil' in surface.caption
    order = admin.execute('''SELECT id,order_status,payment_status,total_amount FROM public.orders
        WHERE customer_id=%s ORDER BY created_at DESC LIMIT 1''', (customer['id'],)).fetchone()
    assert order[1:] == ('completed', 'paid', 15000), order
    after = admin.execute('SELECT balance FROM public.customer_balance_accounts WHERE customer_id=%s',
                          (customer['id'],)).fetchone()[0]
    assert after == before - 15000, (before, after)
    counters = _admin_counters(admin, customer['id'])
    assert counters[:4] == (1, 1, 1, 1), counters
    receipt = _receipt_evidence(admin, order[0], FakeReceiptTransport())
    return {'order_id': str(order[0]), 'checkout_ready_ms': elapsed, **receipt}


def _qris_flow(admin, roots, presenter, checkout, provider, customer, chat_id, product_id):
    state = {}
    root, detail, state = _establish(roots, presenter, customer, chat_id, product_id, state)
    token = state['selection']['interaction_token']
    pay_callback = _callback(detail, 'detail:pay:qris:')
    started = time.monotonic_ns()
    surface = presenter.route(customer, pay_callback, state, root=root, chat_id=chat_id)
    checkout_ms = (time.monotonic_ns() - started) / 1_000_000
    assert surface is not None and 'QRIS berhasil dibuat' in surface.caption
    order = admin.execute('''SELECT o.id,p.id,p.merchant_ref,p.provider_reference,p.provider_presentation
        FROM public.orders o JOIN public.payments p ON p.order_id=o.id
        WHERE o.customer_id=%s''', (customer['id'],)).fetchone()
    assert order is not None and order[3] is not None
    order_id, payment_id = str(order[0]), str(order[1])
    assert provider.creates == 1
    # The same accepted selection/root replays the canonical Order and cannot
    # create a second provider invoice.
    replay = checkout.checkout(customer, root, product_id=product_id, quantity=1,
                               token=token, rail='qris')
    assert replay.order_id == order_id, replay
    assert provider.creates == 1
    outcome = roots.render(customer, root, chat_id, surface)
    assert outcome in ('applied', 'replaced'), outcome
    root = roots.current_for_chat(customer, chat_id)
    provider.state = OutcomeState.SUCCESS
    check_callback = _callback(surface, 'checkout:check:')
    surface, root, observed_ms = _render(
        roots, presenter, customer, chat_id, state, check_callback, root)
    assert 'Pembayaran sudah diterima' in surface.caption
    committed_started = time.monotonic_ns()
    row = admin.execute('''SELECT order_status,payment_status FROM public.orders o
        JOIN public.payments p ON p.order_id=o.id WHERE o.id=%s AND p.id=%s''',
        (order_id, payment_id)).fetchone()
    committed_ms = (time.monotonic_ns() - committed_started) / 1_000_000
    assert row == ('completed', 'paid'), row
    receipt = _receipt_evidence(admin, order_id, FakeReceiptTransport())
    counters = _admin_counters(admin, customer['id'])
    assert counters[:4] == (1, 1, 1, 1), counters
    return {
        'order_id': order_id, 'payment_id': payment_id,
        'checkout_ready_ms': checkout_ms, 'paid_observed_ms': observed_ms,
        'paid_committed_ms': committed_ms,
        'provider_creates': provider.creates, 'provider_checks': provider.checks,
        **receipt,
    }


def _race(admin, service, customer, root, product_id):
    barrier = threading.Barrier(3)
    outcomes = []
    errors = []

    def run():
        try:
            barrier.wait(timeout=15)
            outcomes.append(service.checkout(
                customer, root, product_id=product_id, quantity=1,
                token='r' * 16, rail='qris'))
        except Exception as error:
            errors.append(type(error).__name__)
        finally:
            from django.db import connections
            connections.close_all()

    workers = [threading.Thread(target=run) for _ in range(2)]
    for worker in workers:
        worker.start()
    barrier.wait(timeout=15)
    for worker in workers:
        worker.join(timeout=30)
    assert not errors, errors
    assert len(outcomes) == 2, outcomes
    assert all(view.order_id is not None for view in outcomes), outcomes
    assert len({view.order_id for view in outcomes}) == 1, outcomes
    counters = _admin_counters(admin, customer['id'])
    assert counters[:4] == (1, 1, 1, 0), counters
    return {'threads': 2, 'orders': 1, 'payments': 1,
            'same_order': True, 'errors': 0}


def run():
    if os.environ.get('P720_CHECKOUT_SMOKE') != OPT_IN:
        raise RuntimeError('Explicit disposable checkout-navigation opt-in required')
    from lhie_candidate.startup import configure_django
    configure_django()
    from django.db import DatabaseError
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.navigation.presentation import CatalogPresenter
    from lhie_candidate.navigation.repository import CatalogRepository
    from lhie_candidate.navigation.roots import NavigationRoots
    from lhie_candidate.readiness import verify_dependencies
    from lhie_candidate.runtime import application_key

    fixtures = json.load(sys.stdin)
    admin = _admin()
    verify_dependencies()
    data = Database()
    assert data.one('SELECT current_database() AS db,current_user AS role') == {
        'db': 'disposable', 'role': 'candidate_runtime'}
    admin.execute("UPDATE public.manual_payment_settings SET is_active=false")
    admin.execute('''INSERT INTO public.manual_payment_settings
        (is_active,payment_mode,payment_provider_version,sakurupiah_channel,created_at,updated_at)
        VALUES (true,'sakurupiah',1,'QRIS',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)''')

    repository = CatalogRepository()
    provider, checkout = _service()
    roots = NavigationRoots(RootTransport(), bot_asset_scope='synthetic')
    presenter = CatalogPresenter(repository, signing_key=application_key(), checkout=checkout)

    qris = fixtures['navigation_qris']
    qris_customer = repository.customer(qris['telegram_id'])
    qris_result = _qris_flow(admin, roots, presenter, checkout, provider, qris_customer,
                             str(qris['telegram_id']), qris['product_id'])
    balance = fixtures['navigation_balance']
    balance_customer = repository.customer(balance['telegram_id'])
    balance_result = _balance_flow(admin, roots, presenter, checkout, balance_customer,
                                   str(balance['telegram_id']), balance['product_id'])
    race = fixtures['navigation_race']
    race_customer = repository.customer(race['telegram_id'])
    race_state = {}
    race_root, _, _ = _establish(roots, presenter, race_customer, str(race['telegram_id']),
                                 race['product_id'], race_state)
    race_result = _race(admin, checkout, race_customer, race_root, race['product_id'])
    assert provider.creates == 2, provider.creates

    # The runtime role may read only the narrow projections it needs and may
    # never rewrite a commercial identity, provider amount or delivery ledger.
    denied = (
        'UPDATE public.orders SET total_amount=1 WHERE FALSE',
        'UPDATE public.payments SET amount=1 WHERE FALSE',
        'UPDATE public.products SET price_amount=1 WHERE FALSE',
        'UPDATE public.external_money_receipts SET status=status WHERE FALSE',
        'DELETE FROM public.store_balance_entries WHERE FALSE',
        'UPDATE public.cart_items SET quantity=quantity WHERE FALSE',
    )
    for sql in denied:
        try:
            data.execute(sql)
        except DatabaseError as error:
            assert error.__cause__.sqlstate == '42501', (sql, error.__cause__.sqlstate)
        else:
            raise AssertionError('runtime exceeded navigation-checkout grants: ' + sql)

    return {
        'status': 'passed',
        'qris': qris_result,
        'balance': balance_result,
        'race': race_result,
        'authority_denials': len(denied),
        'stage_samples': [{
            'rail': 'qris',
            'checkout_ready_ms': qris_result['checkout_ready_ms'],
            'paid_observed_ms': qris_result['paid_observed_ms'],
            'paid_committed_ms': qris_result['paid_committed_ms'],
            'receipt_queued_ms': qris_result['receipt_queued_ms'],
            'receipt_pickup_ms': qris_result['receipt_pickup_ms'],
            'credential_ack_ms': qris_result['credential_ack_ms'],
            'group_complete_ms': qris_result['group_complete_ms'],
            'credential_ack': 'sent',
            'group_complete': True,
            'target_status': 'proposed_unratified',
        }],
        'limits': [
            'In-process fake provider only; no network, real money or provider credential',
            'One local sample per boundary; not a P11 paired measurement or performance claim',
            'H1 must ratify any payment-stage budget after a qualified paired run',
        ],
    }


if __name__ == '__main__':
    print(json.dumps(run(), indent=2))
