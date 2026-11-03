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
QRIS = 'https://sakurupiah.id/assets/qr/synthetic-navigation.png'


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
        self.block_preflight = False
        self.preflight_entered = threading.Event()
        self.release_preflight = threading.Event()
        self.block_status = False
        self.status_entered = threading.Event()
        self.release_status = threading.Event()

    @staticmethod
    def _outside_locks():
        from django.db import connection
        assert connection.get_autocommit() and not connection.in_atomic_block
        with connection.cursor() as cursor:
            cursor.execute("SELECT count(*) FROM pg_locks WHERE pid=pg_backend_pid() "
                           "AND locktype='advisory' AND granted")
            assert cursor.fetchone()[0] == 0, 'Provider I/O held an advisory lock'

    def list_channels(self):
        self._outside_locks()
        if self.block_preflight:
            self.preflight_entered.set()
            if not self.release_preflight.wait(15):
                raise RuntimeError('Synthetic preflight was not released')
        return (Channel('QRIS', True, 1, 10_000_000_000),)

    def create(self, request):
        self._outside_locks()
        with self.lock:
            self.creates += 1
        return ProviderOutcome(
            request.identity, OutcomeState.CREATED, self.reference,
            request.identity.amount + FEE, FEE,
            (datetime.now(ZoneInfo('Asia/Jakarta')) + timedelta(minutes=10)).isoformat(timespec='seconds'),
            QRIS)

    def status(self, identity, provider_ref, *, provider_total):
        self._outside_locks()
        if self.block_status:
            self.status_entered.set()
            if not self.release_status.wait(15):
                raise RuntimeError('Synthetic status was not released')
        with self.lock:
            self.checks += 1
        return ProviderOutcome(identity, self.state, provider_ref)

    def recover(self, identity, *, provider_total):
        return self.status(identity, self.reference, provider_total=provider_total)


class RootTransport:
    def __init__(self):
        self.message_id = 900
        self.calls = []

    def execute(self, method, payload):
        self.calls.append((method, dict(payload)))
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


def _qris_flow(admin, roots, presenter, checkout, provider, customer, chat_id, product_id,
               other_customer):
    from django.db import connection, connections
    from unittest.mock import patch
    from lhie_candidate.navigation.ingress import UpdateInbox
    from lhie_candidate.navigation.processor import NavigationProcessor
    from lhie_candidate.navigation.presentation import CatalogPresenter

    state = {}
    root, detail, state = _establish(roots, presenter, customer, chat_id, product_id, state)
    admin.execute("UPDATE public.storefront_settings SET telegram_welcome_banner_path=%s "
                  "WHERE singleton_key='storefront'", ('synthetic/navigation-banner',))
    admin.execute("INSERT INTO django_candidate.navigation_assets (bot_scope,source_path,file_id,version) "
                  "VALUES ('synthetic',%s,%s,1) ON CONFLICT (bot_scope,source_path) "
                  "DO UPDATE SET file_id=EXCLUDED.file_id,version=EXCLUDED.version",
                  ('synthetic/navigation-banner', 'old-banner'))
    roots.banner_reference, roots.banner_version = 'old-banner', 1
    token = state['selection']['interaction_token']
    pay_callback = _callback(detail, 'detail:pay:qris:')
    with connection.cursor() as cursor:
        cursor.execute("INSERT INTO django_candidate.navigation_sessions (customer_id,state) VALUES (%s,%s::jsonb) "
                       "ON CONFLICT (customer_id) DO UPDATE SET state=EXCLUDED.state",
                       [customer['id'], json.dumps(state)])
    base = time.time_ns() // 1000
    actor = {'id': int(chat_id), 'is_bot': False, 'first_name': 'Synthetic'}
    pay = {'update_id': base, 'callback_query': {'id': 'synthetic-pay', 'from': actor,
           'message': {'message_id': root.message_id, 'chat': {'id': int(chat_id), 'type': 'private'}},
           'data': pay_callback}}
    def message(identity, sender, text):
        return {'update_id': identity, 'message': {'message_id': identity % 1000000 + 1,
                'chat': {'id': sender, 'type': 'private'},
                'from': {'id': sender, 'is_bot': False, 'first_name': 'Synthetic'}, 'text': text}}
    inbox = UpdateInbox()
    with patch('lhie_candidate.workers.wakeup.enqueue', return_value=False):
        inbox.receive(pay)
        for offset in range(1, 31):
            inbox.receive(message(base + offset, int(chat_id), '/unsupported'))
        inbox.receive(message(base + 31, other_customer['telegram_id'], '/start'))
    payment_processor = NavigationProcessor(presenter.repository, presenter, roots, roots.transport,
                                            family='payment')
    interaction_roots = type(roots)(RootTransport(), bot_asset_scope=roots.bot_asset_scope)
    interaction = NavigationProcessor(presenter.repository,
        CatalogPresenter(presenter.repository, signing_key=checkout.key),
        interaction_roots, interaction_roots.transport, family='interaction')
    captured = {}
    original_route = presenter.route
    def capture(*args, **kwargs):
        surface = original_route(*args, **kwargs)
        captured['surface'] = surface
        if args[1] == pay_callback and surface is not None:
            captured['replay'] = checkout.checkout(
                customer, kwargs['root'], product_id=product_id, quantity=1,
                token=token, rail='qris')
        return surface
    presenter.route = capture
    provider.block_preflight = True
    def process_payment():
        try:
            captured['outcomes'] = payment_processor.drain()
        except Exception as error:
            captured['error'] = error
        finally:
            connections.close_all()
    started = time.monotonic_ns()
    worker = threading.Thread(target=process_payment)
    worker.start()
    try:
        assert provider.preflight_entered.wait(10), f'Payment preflight did not start: {captured}'
        assert interaction.process(base + 1) == 'waiting'
        assert admin.execute("SELECT pg_try_advisory_lock(%s)", (int(chat_id),)).fetchone()[0]
        admin.execute("SELECT pg_advisory_unlock(%s)", (int(chat_id),))
        admin.execute("UPDATE django_candidate.navigation_assets SET file_id=%s,version=2 "
                      "WHERE bot_scope='synthetic' AND source_path=%s",
                      ('fresh-banner', 'synthetic/navigation-banner'))
        interaction.drain(25)
        rows = admin.execute(
            "SELECT update_id,state FROM django_candidate.navigation_updates "
            "WHERE update_id BETWEEN %s AND %s ORDER BY update_id",
            (base + 1, base + 31)).fetchall()
        assert len(rows) == 31 and all(state == 'pending' for _, state in rows[:30]), rows
        assert rows[30] == (base + 31, 'processed'), rows
    finally:
        provider.release_preflight.set()
        worker.join(20)
        presenter.route = original_route
        provider.block_preflight = False
    assert not worker.is_alive() and 'error' not in captured, captured
    assert any(method == 'sendPhoto' and payload.get('photo') == 'fresh-banner'
               for method, payload in roots.transport.calls), roots.transport.calls
    assert len(captured['outcomes']) == 1 and captured['outcomes'][0] in ('applied', 'replaced'), captured
    assert interaction.drain(25) == ['unsupported'] * 25
    backlog = admin.execute(
        "SELECT update_id,state FROM django_candidate.navigation_updates "
        "WHERE update_id BETWEEN %s AND %s ORDER BY update_id",
        (base + 1, base + 30)).fetchall()
    assert [state for _, state in backlog] == ['processed'] * 25 + ['pending'] * 5, backlog
    assert interaction.drain(25) == ['unsupported'] * 5
    surface = captured['surface']
    checkout_ms = (time.monotonic_ns() - started) / 1_000_000
    assert surface is not None and 'QRIS berhasil dibuat' in surface.caption
    assert 'Rp 200' in surface.caption
    order = admin.execute('''SELECT o.id,p.id,p.merchant_ref,p.provider_reference,p.provider_presentation
        FROM public.orders o JOIN public.payments p ON p.order_id=o.id
        WHERE o.customer_id=%s''', (customer['id'],)).fetchone()
    assert order is not None and order[3] is not None
    order_id, payment_id = str(order[0]), str(order[1])
    assert provider.creates == 1
    # The replay runs before a product-to-checkout transition replaces the root.
    replay = captured['replay']
    assert replay.order_id == order_id, replay
    assert provider.creates == 1
    assert replay.total == replay.amount + FEE and replay.fee == FEE
    with patch.object(provider, 'status', side_effect=RuntimeError('synthetic failure')):
        pending = checkout.check(customer, roots.current_for_chat(customer, chat_id),
                                 payment_id=payment_id)
    assert pending.state == 'pending' and pending.fee == FEE
    assert pending.total == pending.amount + FEE
    root = roots.current_for_chat(customer, chat_id)
    check_callback = _callback(surface, 'checkout:check:')
    pay['update_id'] = base + 32
    pay['callback_query']['id'] = 'synthetic-stale-check'
    pay['callback_query']['message']['message_id'] = root.message_id
    pay['callback_query']['data'] = check_callback
    with patch('lhie_candidate.workers.wakeup.enqueue', return_value=False):
        inbox.receive(pay)
    provider.block_status = True
    captured.pop('outcomes', None)
    calls_before = len(roots.transport.calls)
    worker = threading.Thread(target=process_payment)
    worker.start()
    try:
        assert provider.status_entered.wait(10), captured
        admin.execute("UPDATE django_candidate.navigation_roots SET generation=generation+1 "
                      "WHERE bot_asset_scope=%s AND customer_id=%s AND chat_id=%s",
                      (roots.bot_asset_scope, customer['id'], chat_id))
    finally:
        provider.release_status.set()
        worker.join(20)
        provider.block_status = False
    assert not worker.is_alive() and 'error' not in captured, captured
    assert captured['outcomes'] == ['superseded'], captured
    assert len(roots.transport.calls) == calls_before
    assert provider.creates == 1
    pay['update_id'] = base + 33
    pay['callback_query']['id'] = 'synthetic-lost-worker'
    with patch('lhie_candidate.workers.wakeup.enqueue', return_value=False):
        inbox.receive(pay)
        inbox.receive(message(base + 34, int(chat_id), '/unsupported'))
    provider.status_entered.clear()
    provider.release_status.clear()
    provider.block_status = True
    captured.pop('outcomes', None)
    calls_before = len(roots.transport.calls)
    worker = threading.Thread(target=process_payment)
    worker.start()
    try:
        assert provider.status_entered.wait(10), captured
        assert interaction.process(base + 34) == 'waiting'
        admin.execute("UPDATE django_candidate.navigation_updates "
                      "SET claimed_at=clock_timestamp()-interval '2 minutes' WHERE update_id=%s",
                      (base + 33,))
        assert payment_processor.recover(stale_seconds=1) == 1
        assert interaction.process(base + 34) == 'unsupported'
    finally:
        provider.release_status.set()
        worker.join(20)
        provider.block_status = False
    assert not worker.is_alive() and 'error' not in captured, captured
    assert captured['outcomes'] == ['superseded'], captured
    assert len(roots.transport.calls) == calls_before
    assert admin.execute("SELECT state,outcome FROM django_candidate.navigation_updates "
                         "WHERE update_id=%s", (base + 33,)).fetchone() == ('unknown', 'worker_lost')
    assert provider.creates == 1
    root = roots.current_for_chat(customer, chat_id)
    provider.state = OutcomeState.SUCCESS
    check_callback = _callback(surface, 'checkout:check:')
    pay['update_id'] = base + 35
    pay['callback_query']['id'] = 'synthetic-check'
    pay['callback_query']['message']['message_id'] = root.message_id
    pay['callback_query']['data'] = check_callback
    with patch('lhie_candidate.workers.wakeup.enqueue', return_value=False):
        inbox.receive(pay)
    presenter.route = capture
    started = time.monotonic_ns()
    try:
        assert payment_processor.drain() == ['applied']
    finally:
        presenter.route = original_route
    observed_ms = (time.monotonic_ns() - started) / 1_000_000
    surface = captured['surface']
    root = roots.current_for_chat(customer, chat_id)
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
    balance = fixtures['navigation_balance']
    qris_customer = repository.customer(qris['telegram_id'])
    balance_customer = repository.customer(balance['telegram_id'])
    qris_result = _qris_flow(admin, roots, presenter, checkout, provider, qris_customer,
                             str(qris['telegram_id']), qris['product_id'], balance_customer)
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
