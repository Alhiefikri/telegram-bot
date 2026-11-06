"""Changed USDT funding flow against the admitted disposable PostgreSQL schema."""
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

from tests.test_checkout_postgres import admin_connection
from tests.test_binance import envelope


class SyntheticBinance:
    def __init__(self):
        self.rows = []

    def request(self, method, url, *, headers, timeout, max_response_bytes):
        from django.db import connections
        assert method == 'GET' and all(not c.in_atomic_block for c in connections.all(initialized_only=True))
        return envelope(self.rows)


def exercise_p17(fixtures):
    from django.db import connections
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.payments.binance import BinanceAdapter, _ms
    from lhie_candidate.usdt.funding import FundingService
    from lhie_candidate.usdt.funding_settlement import FundingSettlement
    from lhie_candidate.usdt.reconciliation import UsdtReconciliation
    from lhie_candidate.receipt.service import ReceiptService
    from lhie_candidate.receipt.source import ReceiptSource
    from lhie_candidate.receipt.transport import ReceiptResult
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.runtime import application_key

    admin = admin_connection()
    db = Database()
    now = datetime.now(ZoneInfo('Asia/Jakarta')).replace(tzinfo=None, microsecond=0)
    clock = [now]
    transport = SyntheticBinance()
    adapter = BinanceAdapter(transport=transport, api_key='synthetic-p17', api_secret='synthetic-only',
                             pay_id='987654321', clock_ms=lambda: _ms(clock[0]))
    service = FundingService(readiness={'binance_pay': True}, binance=adapter, clock=lambda: clock[0])
    settlement = FundingSettlement(binance=adapter, clock=lambda: clock[0])
    customer = fixtures['python']['customer_id']
    try:
        telegram = admin.execute('SELECT telegram_id FROM public.customers WHERE id=%s', (customer,)).fetchone()[0]
        admin.execute("UPDATE public.customer_usdt_funding_policies SET funding_enabled=true,version=1")
        admin.execute("UPDATE public.manual_payment_settings SET binance_pay_enabled=true")
        admin.execute('''INSERT INTO django_candidate.navigation_roots
            (bot_asset_scope,customer_id,chat_id,message_id,media_kind,screen,generation,rendered_at)
            VALUES ('synthetic',%s,%s,10,'text','balance',1,now())''', (customer, str(telegram)))
        assert not FundingService().availability('binance_pay')
        from lhie_candidate.workers.services import payment_handler
        worker = payment_handler()
        assert worker.usdt_funding is not None and worker.usdt_settlement is not None and worker.reorg_monitor is not None
        assert worker.usdt_settlement.recover(limit=1) == 0
        first = service.open_confirmed(customer, str(telegram), 'synthetic', 10, 1_000_000, 'binance_pay')
        assert first['status'] == 'confirmed', first
        first_id = str(first['target']['id'])
        second = service.open_confirmed(customer, str(telegram), 'synthetic', 10, 2_000_000, 'binance_pay')
        assert second['status'] == 'active_target' and str(second['target']['id']) == first_id
        hint = '445011487581044736'
        transport.rows = [{'orderId': hint, 'transactionId': 'P17-TRANSACTION-1',
                           'amount': '1.000000', 'currency': 'USDT', 'transactionTime': _ms(now),
                           'receiverInfo': {'accountId': '987654321'}, 'status': 'SUCCESS'}]
        settlement.submit(customer, first_id, hint)
        admin.execute("INSERT INTO public.customer_usdt_funding_pauses(scope,reason,paused_at,created_at,updated_at) VALUES ('funding','synthetic',now(),now(),now())")
        from concurrent.futures import ThreadPoolExecutor
        from threading import Barrier
        barrier = Barrier(2)
        def race_check():
            try:
                barrier.wait(timeout=10)
                return settlement.check(first_id)
            finally:
                connections.close_all()
        with ThreadPoolExecutor(max_workers=2) as pool:
            results = list(pool.map(lambda _: race_check(), range(2)))
        assert sorted(results) == [False, True], results
        assert service.status(customer, first_id)['state'] == 'settled'
        assert UsdtReconciliation().snapshot(customer)['balance_micro'] == 1_000_000
        assert admin.execute("SELECT count(*) FROM public.customer_usdt_balance_entries WHERE source_type='customer_usdt_top_up' AND source_id=%s", (first_id,)).fetchone() == (1,)
        admin.execute("DELETE FROM public.customer_usdt_funding_pauses WHERE scope='funding'")

        class Telegram:
            def __init__(self): self.calls = []
            def execute(self, method, payload):
                self.calls.append((method, payload))
                return ReceiptResult(42, 'success')
        relay = Telegram()
        delivery = ReceiptService(ReceiptSource(LaravelEncrypter(application_key())), relay, None)
        effect = db.one('SELECT id FROM public.telegram_outbound_effects WHERE effect_key=%s', ('usdt-top-up:' + first_id + ':receipt:v1',))
        delivery_barrier = Barrier(2)
        def race_delivery():
            try:
                delivery_barrier.wait(timeout=10)
                return delivery.deliver(effect['id'])
            finally:
                connections.close_all()
        with ThreadPoolExecutor(max_workers=2) as pool:
            delivered = list(pool.map(lambda _: race_delivery(), range(2)))
        assert sorted(delivered) == ['not_ready', 'sent'] and len(relay.calls) == 1, delivered

        cancelled = service.open_confirmed(customer, str(telegram), 'synthetic', 10, 2_000_000, 'binance_pay')
        assert cancelled['status'] == 'confirmed'
        cancelled_id = str(cancelled['target']['id'])
        assert service.cancel_open(customer)
        assert service.status(customer, cancelled_id)['state'] == 'cancelled_by_customer'
        late_hint = '445011487581044737'
        transport.rows = [{'orderId': late_hint, 'transactionId': 'P17-LATE-2',
                           'amount': '2.000000', 'currency': 'USDT', 'transactionTime': _ms(clock[0]),
                           'receiverInfo': {'accountId': '987654321'}, 'status': 'SUCCESS'}]
        settlement.submit(customer, cancelled_id, late_hint)
        assert settlement.check(cancelled_id)
        assert admin.execute('''SELECT w.state,count(r.id) FROM public.customer_usdt_top_up_settlement_work w
            LEFT JOIN public.external_money_receipts r ON r.target_type='customer_usdt_top_up'
              AND r.target_id=w.top_up_id::text WHERE w.top_up_id=%s GROUP BY w.state''',
            (cancelled_id,)).fetchone() == ('manual_review', 1)
        assert service.status(customer, cancelled_id)['state'] == 'manual_review'
        from lhie_candidate.navigation.repository import CatalogRepository
        assert any(row['target_id'] == cancelled_id for row in CatalogRepository().usdt_history(customer))
        ledger_history = CatalogRepository()._usdt_entry_history(customer, direction=None, cursor=None)
        assert any(row['kind'] == 'funding_credit' and row['target_id'] is None for row in ledger_history)
        assert UsdtReconciliation().snapshot(customer)['balance_micro'] == 1_000_000
        replacement = service.open_confirmed(customer, str(telegram), 'synthetic', 10, 3_000_000, 'binance_pay')
        assert replacement['status'] == 'confirmed'
        replacement_id = str(replacement['target']['id'])
        review_hint = '445011487581044738'
        transport.rows = [{'orderId': review_hint, 'transactionId': 'P17-REVIEW-3',
                           'amount': '4.000000', 'currency': 'USDT', 'transactionTime': _ms(clock[0]),
                           'receiverInfo': {'accountId': '987654321'}, 'status': 'SUCCESS'}]
        settlement.submit(customer, replacement_id, review_hint)
        assert settlement.check(replacement_id)
        assert service.status(customer, replacement_id)['state'] == 'manual_review'

        import bcrypt
        from types import SimpleNamespace
        from lhie_candidate.adjustments.password import AdjustmentRejected
        from lhie_candidate.auth.sessions import Session
        from lhie_candidate.compat.identity import sha256_digest
        from lhie_candidate.usdt.review import UsdtTopUpReview
        password = 'synthetic-p17-owner-password'
        password_hash = bcrypt.hashpw(password.encode(), bcrypt.gensalt(rounds=4)).decode()
        owner_id = admin.execute("""INSERT INTO public.users(name,email,password,role,created_at,updated_at)
            VALUES ('P17 owner','p17-owner@example.invalid',%s,'owner',now(),now()) RETURNING id""",
            (password_hash,)).fetchone()[0]
        session = Session()
        session.set('admin', {'id': owner_id, 'password_digest': sha256_digest(password_hash)})
        session.save()
        actor = SimpleNamespace(auth_session=session)
        review = UsdtTopUpReview(admin_chat_id=str(telegram), binance=adapter)
        preview = review.request(actor, replacement_id, 'settled', 4_000_000, review_hint, 'Synthetic verified mismatch')
        try:
            review.confirm(actor, preview['id'], preview['fingerprint'], preview['version'], 'wrong-password')
        except AdjustmentRejected as error:
            assert error.code == 'invalid_password'
        else:
            raise AssertionError('Wrong owner password authorized a reviewed credit')
        resolution = review.confirm(actor, preview['id'], preview['fingerprint'], preview['version'], password)
        assert resolution['kind'] == 'settled'
        assert UsdtReconciliation().snapshot(customer)['balance_micro'] == 5_000_000
        assert review.confirm(actor, preview['id'], preview['fingerprint'], preview['version'], password)['entry_id'] == resolution['entry_id']

        expired = service.open_confirmed(customer, str(telegram), 'synthetic', 10, 5_000_000, 'binance_pay')
        assert expired['status'] == 'confirmed'
        clock[0] = expired['target']['expires_at'] + timedelta(seconds=1)
        assert service.release_expired() == 0
        assert admin.execute('SELECT state FROM public.customer_usdt_funding_reservations WHERE id=%s',
                             (expired['target']['reservation_id'],)).fetchone() == ('expired_unresolved',)
        clock[0] += timedelta(minutes=60)
        assert service.release_expired() == 1
        assert service.status(customer, expired['target']['id'])['state'] == 'expired_unpaid'
        assert UsdtReconciliation().snapshot(customer)['balance_micro'] == 5_000_000
        from lhie_candidate.checkout.intents import UsdtBalancePreparation
        from lhie_candidate.checkout.service import CheckoutRequest, CheckoutService
        from lhie_candidate.usdt.chain import BscFundingRail
        from lhie_candidate.usdt.operations import ReorgIncidents
        from lhie_candidate.usdt.operations_restore import RestoreGate
        from tests.test_bsc import BscFixture, TX, block

        class WatchOnly(BscFixture):
            def call(self, method, params=()):
                if method == 'eth_call':
                    return '0x' + format(18, '064x')
                return super().call(method, params)

        chain_clock = datetime.now(ZoneInfo('Asia/Jakarta')).replace(tzinfo=None, microsecond=0)
        epoch = int(chain_clock.replace(tzinfo=ZoneInfo('Asia/Jakarta')).timestamp())
        rpc = WatchOnly()
        rpc.blocks.update({'0x64': block(100, epoch), 'latest': block(104, epoch, '0x' + 'c' * 64),
                           'finalized': block(102, epoch, '0x' + 'd' * 64),
                           '0x66': block(102, epoch, '0x' + 'd' * 64)})
        rail = BscFundingRail(rpc, clock=lambda: chain_clock)
        bsc_funding = FundingService(readiness={'bsc_usdt': True}, bsc=rail, clock=lambda: chain_clock)
        bsc_settlement = FundingSettlement(bsc=rail, clock=lambda: chain_clock)
        fixture = fixtures['p17_bsc']
        bsc_customer, bsc_telegram = fixture['customer_id'], fixture['telegram_id']
        admin.execute("UPDATE public.manual_payment_settings SET bsc_payment_enabled=true,bsc_min_confirmations=3")
        admin.execute('''INSERT INTO django_candidate.navigation_roots
            (bot_asset_scope,customer_id,chat_id,message_id,media_kind,screen,generation,rendered_at)
            VALUES ('synthetic',%s,%s,11,'text','balance',1,now())''', (bsc_customer, str(bsc_telegram)))
        opened = bsc_funding.open_confirmed(bsc_customer, str(bsc_telegram), 'synthetic', 11, 1_000_000, 'bsc_usdt')
        assert opened['status'] == 'confirmed'
        bsc_target = str(opened['target']['id'])
        from types import SimpleNamespace
        from lhie_candidate.navigation.presentation import CatalogPresenter
        from lhie_candidate.usdt.review import UsdtTopUpReview
        from tests.test_catalog_presentation import MemoryCatalog
        presenter = CatalogPresenter(MemoryCatalog(), signing_key='synthetic')
        root = SimpleNamespace(message_id=11, bot_asset_scope='synthetic')
        return_command = f'/usdt-return {bsc_target} 1.000000 0x' + '2' * 40
        surface = presenter.usdt_return({'id': bsc_customer, 'locale': 'en'}, return_command, root, str(bsc_telegram), 1)
        assert 'Authorization ID:' in surface.caption
        authorization = db.one('''SELECT * FROM public.usdt_top_up_external_return_authorizations
            WHERE customer_id=%s AND nonce=%s''', (bsc_customer, 'telegram-00000000000000000001'))
        review = UsdtTopUpReview(admin_chat_id=str(telegram), bsc=rail)
        replayed = review.authorize_return(bsc_customer, str(bsc_telegram), 11, 'synthetic',
            bsc_target, 1_000_000, '0x' + '2' * 40, 'telegram-00000000000000000001')
        assert authorization['id'] == replayed['id']
        try:
            review.authorize_return(customer, str(telegram), 10, 'synthetic',
                bsc_target, 1_000_000, '0x' + '2' * 40, 'telegram-00000000000000000002')
        except ValueError:
            pass
        else:
            raise AssertionError('Return authorization crossed customer boundary')
        assert bsc_settlement.prepare(bsc_target)
        if admin.execute('SELECT state FROM public.customer_usdt_top_up_work WHERE top_up_id=%s', (bsc_target,)).fetchone()[0] != 'ready':
            target = opened['target']
            rail.create_or_recover(bsc_customer, target['reference'], target['payable_micro'], target['expires_at'],
                receiver_id=target['receiver_id'], target_fingerprint=target['request_fingerprint'],
                minimum_confirmations=target['payment_snapshot']['minimum_confirmations'])
            raise AssertionError('BSC preparation did not become ready')
        bsc_settlement.submit(bsc_customer, bsc_target, TX)
        assert bsc_settlement.check(bsc_target)
        balance = UsdtReconciliation().snapshot(bsc_customer)['balance_micro']
        assert balance == 1_000_000, (balance, admin.execute('''SELECT w.state,w.last_error,e.status,e.reason,e.chain_evidence
            FROM public.customer_usdt_top_up_settlement_work w LEFT JOIN public.customer_usdt_top_up_evidence e
            ON e.top_up_id=w.top_up_id WHERE w.top_up_id=%s''', (bsc_target,)).fetchall())
        checkout = CheckoutService(preparation=UsdtBalancePreparation())
        purchase = CheckoutRequest(bsc_customer, fixture['cart_id'], 'p17-bsc-purchase',
                                   checkout.quote_cart(bsc_customer, fixture['cart_id']))
        checkout.checkout(purchase)
        assert UsdtReconciliation().snapshot(bsc_customer)['balance_micro'] == 400_000
        before = admin.execute('''SELECT e.id,e.kind,e.amount_micro,e.balance_after_micro
            FROM public.customer_usdt_balance_entries e JOIN public.customer_usdt_balance_accounts a
              ON a.id=e.customer_usdt_balance_account_id WHERE a.customer_id=%s ORDER BY e.id''', (bsc_customer,)).fetchall()
        rpc.blocks['0x64'] = block(100, epoch, '0x' + 'f' * 64)
        incidents = ReorgIncidents(rpc, admin_chat_id=str(telegram))
        assert incidents.check(bsc_target)
        incident = admin.execute('''SELECT id,exposure_micro,available_exposure_micro,spent_exposure_micro
            FROM public.usdt_reorg_incidents WHERE top_up_id=%s''', (bsc_target,)).fetchone()
        assert incident[1:] == (1_000_000, 400_000, 600_000)
        after = admin.execute('''SELECT e.id,e.kind,e.amount_micro,e.balance_after_micro
            FROM public.customer_usdt_balance_entries e JOIN public.customer_usdt_balance_accounts a
              ON a.id=e.customer_usdt_balance_account_id WHERE a.customer_id=%s ORDER BY e.id''', (bsc_customer,)).fetchall()
        assert before == after and UsdtReconciliation().snapshot(bsc_customer)['frozen_reason']
        from uuid import uuid4
        recovery = incidents.request_recovery(actor, incident[0], str(uuid4()), 'Synthetic reorg investigation')
        case = incidents.confirm_recovery(actor, recovery['id'], recovery['fingerprint'], password)
        assert case['status'] == 'authorized_unresolved'
        assert UsdtReconciliation().snapshot(bsc_customer)['frozen_reason']
        from lhie_candidate.usdt.operations_controls import OperationalControls
        from lhie_candidate.usdt.operations_reconciliation import OperationalReconciliation
        alerts = OperationalControls()
        assert alerts.unfreeze(actor, bsc_customer, 'Synthetic verification') == 'reorg_hold'
        admin.execute("UPDATE public.customer_usdt_balance_accounts SET frozen_reason='reconciliation_drift:balance_mismatch' WHERE customer_id=%s", (customer,))
        assert alerts.unfreeze(actor, customer, 'Synthetic reinspection', dry_run=True) == 'dry_run_eligible'
        assert alerts.unfreeze(actor, customer, 'Synthetic reinspection') == 'unfrozen'
        assert UsdtReconciliation().snapshot(customer)['frozen_reason'] is None
        signals = alerts.evaluate(admin_chat_id=str(telegram))
        assert signals['reorg_loss_unresolved'] >= 1 and signals['account_frozen'] >= 1
        before_alerts = admin.execute("SELECT count(*) FROM public.telegram_outbound_effects WHERE effect_type='admin.balance_operations_alert'").fetchone()[0]
        alerts.evaluate(admin_chat_id=str(telegram))
        assert admin.execute("SELECT count(*) FROM public.telegram_outbound_effects WHERE effect_type='admin.balance_operations_alert'").fetchone()[0] == before_alerts
        reconciliation = OperationalReconciliation().complete(limit=1)
        assert reconciliation['custody_state'] == 'missing' and reconciliation['verdict'] == 'findings'
        assert reconciliation['accounts_inspected'] > 1
        gate = RestoreGate()
        gate.open(chain_clock - timedelta(minutes=1), chain_clock, 'Synthetic recovery', 'local-operator')
        try:
            gate.close(actor='local-operator')
        except ValueError:
            pass
        else:
            raise AssertionError('Restore closed without replay and full reconciliation')
        assert not bsc_funding.availability('bsc_usdt')
        return {'funding_settlement_and_pause': 'passed', 'concurrent_settlement': 'passed', 'receipt_recovery': 'passed',
                'cancel_expiry_late_review': 'passed', 'owner_review_authorization': 'passed',
                'bsc_finality_spent_reorg': 'passed', 'alert_deduplication': 'passed',
                'multipage_reconciliation': 'passed', 'restore_barrier': 'passed'}
    finally:
        admin.close()
        connections.close_all()
