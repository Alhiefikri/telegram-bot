"""Disposable P08 integration proof; all provider traffic is synthetic Unix HTTP."""
import json
import multiprocessing
import os
import sys
import time


def competing_payment(payment_id, socket_path, action, arguments, results):
    try:
        from lhie_candidate.startup import configure_django
        configure_django()
        from lhie_candidate.compat.crypto import LaravelEncrypter
        from lhie_candidate.payments.lifecycle import SakurupiahLifecycle
        from lhie_candidate.payments.sakurupiah import SakurupiahSandboxAdapter
        from p08_fixture import FixtureClient
        adapter = SakurupiahSandboxAdapter(transport=FixtureClient(socket_path))
        lifecycle = SakurupiahLifecycle(adapter, LaravelEncrypter(b'synthetic-p08-key-material-00000'))
        result = lifecycle.create(payment_id, **arguments) if action == 'create' else lifecycle.refresh(payment_id)
        results.put(result.state)
    except Exception as error:
        results.put(type(error).__name__)
    finally:
        from django.db import connections
        connections.close_all()


def run():
    if os.environ.get('P08_QRIS_SMOKE') != 'disposable-local-only':
        raise RuntimeError('Explicit disposable QRIS opt-in required')
    from lhie_candidate.startup import configure_django
    configure_django()
    from django.db import connections, transaction
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.checkout.sakurupiah import SakurupiahPreparation
    from lhie_candidate.checkout.service import CheckoutRequest, CheckoutService
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.payments.lifecycle import SakurupiahLifecycle
    from lhie_candidate.payments.sakurupiah import SakurupiahSandboxAdapter
    from p08_callback_probe import CallbackProbe
    from p08_fixture import Sandbox
    from tests.test_checkout_postgres import admin_connection

    fixtures = json.load(sys.stdin)
    if os.environ.get('P13_QRIS_RAILS') == '1':
        from tests.test_qris_postgres import exercise_qris_rails
        return exercise_qris_rails(fixtures)
    integrated = os.environ.get('P08_INTEGRATED_SMOKE') == '1'
    if integrated:
        fixtures['duplicate'] = fixtures['receipt_lost']
    admin = admin_connection()
    sandbox = Sandbox()
    callback = CallbackProbe()
    adapter = SakurupiahSandboxAdapter(transport=sandbox)
    cipher = LaravelEncrypter(b'synthetic-p08-key-material-00000')
    lifecycle = SakurupiahLifecycle(adapter, cipher)
    checkout = CheckoutService(preparation=SakurupiahPreparation(adapter))
    db = Database()
    if integrated:
        from lhie_candidate.workers.payment import PaymentHandler
        from lhie_candidate.workers.receipt import ReceiptHandler
        from lhie_candidate.workers.wakeup import encode
        payment_worker = PaymentHandler(lifecycle, db)
    args = {'phone': '08000000000', 'callback_url': 'https://synthetic.invalid/callback/sakurupiah',
            'return_url': 'https://synthetic.invalid/return'}

    def begin(name):
        fixture = fixtures[name]
        request = CheckoutRequest(fixture['customer_id'], fixture['cart_id'], 'p08-' + name,
            checkout.quote_cart(fixture['customer_id'], fixture['cart_id']))
        result = checkout.checkout(request)
        duplicate = checkout.checkout(request)
        assert duplicate.replayed and (duplicate.order_id, duplicate.payment_id, duplicate.invoice_number) == (
            result.order_id, result.payment_id, result.invoice_number)
        expected_amount = 30000 if integrated and name == 'duplicate' else 15000
        assert (result.order_status, result.payment_status, result.amount) == ('pending_payment', 'pending', expected_amount)
        return fixture, result

    def payment(payment_id):
        return admin.execute('''SELECT merchant_ref,provider_reference,provider_total_payment,
            provider_fee,provider_presentation,provider_create_state,status FROM public.payments WHERE id=%s''',
            (payment_id,)).fetchone()

    def economics(fixture, result):
        return admin.execute('''SELECT o.order_status,o.payment_status,p.status,
            pr.stock,pr.reserved_stock,
            (SELECT count(*) FROM public.product_stock_items s WHERE s.product_id=pr.id AND s.order_item_id IS NOT NULL),
            (SELECT count(*) FROM public.external_money_receipts r WHERE r.target_id=p.id::text),
            (SELECT count(*) FROM public.external_money_receipt_aliases a JOIN public.external_money_receipts r
                ON r.id=a.external_money_receipt_id WHERE r.target_id=p.id::text)
            FROM public.orders o JOIN public.payments p ON p.order_id=o.id
            JOIN public.products pr ON pr.id=%s WHERE o.id=%s''',
            (fixture['product_id'], result.order_id)).fetchone()

    def event(ref, trx, status='berhasil', code=1):
        return {'merchant_ref': ref, 'trx_id': trx, 'status': status, 'status_kode': code}

    try:
        assert db.one('SELECT current_database() AS db,current_user AS role') == {
            'db': 'disposable', 'role': 'candidate_runtime'}
        admin.execute("UPDATE public.manual_payment_settings SET payment_mode='sakurupiah',sakurupiah_channel='QRIS'")
        fixture, result = begin('duplicate')
        with transaction.atomic():
            try:
                lifecycle.create(result.payment_id, **args)
            except RuntimeError:
                pass
            else:
                raise AssertionError('Provider operation accepted an open transaction')
        created = lifecycle.create(result.payment_id, **args)
        assert created.state == 'created' and created.presentation
        snapshot = payment(result.payment_id)
        assert snapshot[0] and snapshot[1] and snapshot[2:4] == (30000 if integrated else 15000, 0)
        assert snapshot[4] and snapshot[5:] == ('created', 'pending')
        assert lifecycle.create(result.payment_id, **args).presentation == created.presentation
        assert payment(result.payment_id) == snapshot
        assert sum(path.endswith('/create.php') for path, _ in sandbox.calls) == 1
        assert economics(fixture, result) == ('pending_payment', 'pending', 'pending', 4, 2 if integrated else 1, 2 if integrated else 1, 0, 0)
        ref, trx = snapshot[:2]
        assert callback.send(event(ref, trx), valid=False) == 403
        if integrated:
            try:
                oversized = callback.send({**event(ref, trx), 'padding': 'x' * 16384})
            except ConnectionResetError:
                oversized = None
            assert oversized in (403, None)
        assert admin.execute("SELECT count(*) FROM public.payment_events WHERE merchant_ref=%s AND event_type='callback'", (ref,)).fetchone() == (0,)
        assert callback.send(event(ref, trx + '-mismatch')) == 200
        assert lifecycle.consume_callbacks(result.payment_id).state == 'created'
        assert economics(fixture, result) == ('pending_payment', 'pending', 'pending', 4, 2 if integrated else 1, 2 if integrated else 1, 0, 0)
        assert callback.send(event(ref, trx)) == 200
        assert callback.send(event(ref, trx)) == 200
        assert admin.execute('SELECT received_count FROM public.payment_events WHERE merchant_ref=%s AND provider_reference=%s',
                             (ref, trx)).fetchone() == (2,)
        if integrated:
            event_id = admin.execute('''SELECT id FROM public.payment_events
                WHERE merchant_ref=%s AND provider_reference=%s''', (ref, trx)).fetchone()[0]
            assert payment_worker.handle(encode('payment.event', event_id)) == 'created'
            assert callback.send(event(ref, trx, 'pending', 0)) == 200
        else:
            assert lifecycle.consume_callbacks(result.payment_id).state == 'created'
        assert economics(fixture, result) == ('pending_payment', 'pending', 'pending', 4, 2 if integrated else 1, 2 if integrated else 1, 0, 0)
        sandbox.rows[ref]['status'] = 'berhasil'
        if integrated:
            sandbox.rows[ref]['total'] = '30001'
            assert payment_worker.handle(encode('payment.event', event_id)) == 'recovery_unconfirmed'
            assert economics(fixture, result) == ('pending_payment', 'pending', 'pending', 4, 2, 2, 0, 0)
            sandbox.rows[ref]['total'] = '30000'
            sandbox.rows[ref]['amount'] = '29999'
            assert payment_worker.handle(encode('payment.event', event_id)) == 'recovery_unconfirmed'
            sandbox.rows[ref]['amount'] = '30000'
            sandbox.rows[ref]['payment_kode'] = 'QRIS2'
            assert payment_worker.handle(encode('payment.event', event_id)) == 'recovery_unconfirmed'
            sandbox.rows[ref]['payment_kode'] = 'QRIS'
            from unittest.mock import patch
            with patch.object(sandbox, 'post', side_effect=TimeoutError):
                assert payment_worker.handle(encode('payment.event', event_id)) == 'recovery_unconfirmed'
            assert economics(fixture, result) == ('pending_payment', 'pending', 'pending', 4, 2, 2, 0, 0)
        if integrated:
            from lhie_candidate.workers.consumer import BoundedConsumer
            from lhie_candidate.workers.metrics import WorkerMetrics
            observed_calls = len(sandbox.calls)
            consumer = BoundedConsumer(payment_worker.spec, payment_worker, WorkerMetrics('payment'))
            consumer.run(max_iterations=1)
            paths = [path for path, _ in sandbox.calls[observed_calls:]]
            assert any(path.endswith('/status-transaction.php') for path in paths)
            assert any(path.endswith('/transaction.php') for path in paths)
            assert payment(result.payment_id)[-1] == 'paid'
            assert payment_worker.handle(encode('payment.event', event_id)) == 'paid'
        else:
            assert lifecycle.consume_callbacks(result.payment_id).state == 'paid'
            assert lifecycle.consume_callbacks(result.payment_id).state == 'paid'
        assert economics(fixture, result) == ('completed', 'paid', 'paid', 2 if integrated else 3, 0, 2 if integrated else 1, 1, 1)
        receipt = admin.execute('''SELECT canonical_key,provider,reference,status,target_type,target_id,amount_units
            FROM public.external_money_receipts WHERE target_id=%s''', (result.payment_id,)).fetchone()
        assert receipt == ('v1|provider_reference|sakurupiah|default|' + trx, 'sakurupiah', trx,
                           'claimed', 'order_payment', result.payment_id, '30000' if integrated else '15000')
        if integrated:
            from lhie_candidate.receipt.runtime import receipt_service
            from lhie_candidate.receipt.transport import ReceiptResult

            class SyntheticTelegram:
                def __init__(self, unknown=False):
                    self.methods = []
                    self.credential_seen = False
                    self.unknown = unknown

                def execute(self, method, payload):
                    self.methods.append(method)
                    content = payload.get('document', b'')
                    if isinstance(content, bytes):
                        self.credential_seen |= b'Synthetic P07 receipt-lost-ack credential' in content
                    self.credential_seen |= 'Synthetic P07 receipt-lost-ack credential' in payload.get('text', '')
                    return ReceiptResult(None, 'ambiguous') if self.unknown else ReceiptResult(2000 + len(self.methods), 'success')

            service = receipt_service()
            transport = SyntheticTelegram()
            service.transport = transport
            receipt_worker = ReceiptHandler(service)
            receipt_consumer = BoundedConsumer(receipt_worker.spec, receipt_worker, WorkerMetrics('receipt'))
            receipt_consumer.run(max_iterations=1)
            group = admin.execute('''SELECT id,sequence,state,outcome_metadata->>'contains_credentials',telegram_method,sent_at
                FROM public.telegram_outbound_effects WHERE source_id=%s AND effect_type='receipt.credentials'
                ORDER BY sequence''', (str(result.order_id),)).fetchall()
            assert len(group) >= 2 and sum(row[3] == 'true' for row in group) == 1
            assert all(row[2] == 'sent' for row in group)
            assert all(row[5] is not None for row in group)
            assert [row[5] for row in group] == sorted(row[5] for row in group)
            assert transport.methods[:len(group)] == [row[4] for row in group]
            assert transport.credential_seen
            assert admin.execute('SELECT telegram_receipt_sent_at IS NOT NULL FROM public.orders WHERE id=%s',
                                 (result.order_id,)).fetchone() == (True,)
            assert receipt_worker.handle(encode('effect', group[-1][0])) == 'not_ready'
            assert admin.execute('SELECT count(*) FROM public.payments WHERE order_id=%s',
                                 (result.order_id,)).fetchone() == (1,)

        ambiguous_fixture, ambiguous = begin('stale')
        sandbox.ambiguous = True
        assert lifecycle.create(ambiguous.payment_id, **args).state == 'outcome_unknown'
        assert sandbox.timeouts == 1
        unknown = payment(ambiguous.payment_id)

        conflict_fixture, conflict = begin('maintenance')
        assert lifecycle.create(conflict.payment_id, **args).state == 'created'
        conflict_ref, conflict_trx = payment(conflict.payment_id)[:2]
        conflict_key = 'v1|provider_reference|sakurupiah|default|' + conflict_trx
        admin.execute('''INSERT INTO public.external_money_receipts
            (canonical_key,provider,identity_kind,account_namespace,reference,beneficiary,currency,amount_units,
             decimals,status,target_type,target_id,fingerprint,claimed_at,created_at,updated_at)
            VALUES (%s,'sakurupiah','provider_reference','default',%s,'order:synthetic-other','IDR',
                    '15000',0,'claimed','order_payment','synthetic-other',%s,now(),now(),now())''',
            (conflict_key, conflict_trx, '0' * 64))
        sandbox.rows[conflict_ref]['status'] = 'berhasil'
        assert lifecycle.refresh(conflict.payment_id).state == 'review'
        assert economics(conflict_fixture, conflict) == ('pending_payment', 'pending', 'pending', 4, 1, 1, 0, 0)
        assert admin.execute('SELECT count(*) FROM public.manual_fulfillment_reviews WHERE payment_id=%s AND reason=%s',
                             (conflict.payment_id, 'receipt_conflict')).fetchone() == (1,)
        assert unknown[0] and unknown[1:5] == (None, None, None, None)
        assert lifecycle.create(ambiguous.payment_id, **args).state == 'outcome_unknown'
        assert lifecycle.recover(ambiguous.payment_id).state == 'created'
        recovered = payment(ambiguous.payment_id)
        assert recovered[0] == unknown[0] and recovered[1] and recovered[2] == 15000
        assert economics(ambiguous_fixture, ambiguous) == ('pending_payment', 'pending', 'pending', 4, 1, 1, 0, 0)
        assert sum(path.endswith('/create.php') and fields['merchant_ref'] == unknown[0]
                   for path, fields in sandbox.calls) == 1

        missing_fixture, missing = begin('pause')
        sandbox.omit_presentation = True
        missing_result = lifecycle.create(missing.payment_id, **args)
        sandbox.omit_presentation = False
        assert missing_result.state == 'provider_qris_unusable' and missing_result.presentation is None
        missing_ref, missing_trx, missing_total, _, missing_presentation, _, _ = payment(missing.payment_id)
        assert missing_ref and missing_trx and missing_total == 15000 and missing_presentation is None
        sandbox.rows[missing_ref]['status'] = 'berhasil'
        assert lifecycle.refresh(missing.payment_id).state == 'paid'
        assert economics(missing_fixture, missing) == ('completed', 'paid', 'paid', 3, 0, 1, 1, 1)
        if integrated:
            lost_transport = SyntheticTelegram(unknown=True)
            service.transport = lost_transport
            lost = receipt_worker.handle(encode('order', missing.order_id))
            assert lost and lost['state'] != 'failed'
            assert receipt_worker.handle(encode('effect', lost['id'])) == 'unknown'
            assert receipt_worker.handle(encode('effect', lost['id'])) == 'not_ready'
            receipt_worker.sweep(50)
            assert len(lost_transport.methods) == 1
            assert admin.execute('SELECT state FROM public.telegram_outbound_effects WHERE id=%s',
                                 (lost['id'],)).fetchone() == ('unknown',)
            candidates = []
            for name in ('restore', 'foreign'):
                pending_fixture, pending = begin(name)
                assert lifecycle.create(pending.payment_id, **args).state == 'created'
                pending_ref, pending_trx = payment(pending.payment_id)[:2]
                candidates.append((str(pending.payment_id), pending_fixture, pending, pending_ref, pending_trx))
            candidates.sort(key=lambda item: item[0])
            _, earlier_fixture, earlier, earlier_ref, earlier_trx = candidates[0]
            _, late_fixture, late, late_ref, late_trx = candidates[1]
            lifecycle.orders.apply_payment_expired(late.payment_id)
            sandbox.rows[late_ref]['status'] = 'berhasil'
            assert callback.send(event(earlier_ref, earlier_trx)) == 200
            assert callback.send(event(late_ref, late_trx)) == 200
            for _ in range(3):
                assert payment_worker.sweep(1)['failures'] == 0
            assert economics(earlier_fixture, earlier) == ('pending_payment', 'pending', 'pending', 4, 1, 1, 0, 0)
            assert economics(late_fixture, late) == ('expired', 'paid', 'paid', 4, 0, 0, 1, 1)
            assert admin.execute("SELECT count(*) FROM public.manual_fulfillment_reviews WHERE payment_id=%s AND reason='late_payment'",
                                 (late.payment_id,)).fetchone() == (1,)
            assert admin.execute("SELECT count(*) FROM public.telegram_outbound_effects WHERE source_id=%s",
                                 (str(late.order_id),)).fetchone() == (0,)
            assert admin.execute('''SELECT processed_at IS NULL FROM public.payment_events
                WHERE merchant_ref=%s AND provider_reference=%s''', (earlier_ref, earlier_trx)).fetchone() == (True,)
            recovery_refs = []
            for name in ('retry_success', 'retry_exhausted'):
                recovery_fixture, recovery = begin(name)
                assert lifecycle.create(recovery.payment_id, **args).state == 'created'
                recovery_refs.append(payment(recovery.payment_id)[1])
                admin.execute("""UPDATE public.payments SET provider_create_state='outcome_unknown',
                    provider_create_attempted_at=CURRENT_TIMESTAMP - INTERVAL '2 minutes' WHERE id=%s""",
                    (recovery.payment_id,))
                assert economics(recovery_fixture, recovery) == ('pending_payment', 'pending', 'pending', 4, 1, 1, 0, 0)
            calls_before = len(sandbox.calls)
            for _ in range(3):
                assert payment_worker.sweep(1)['failures'] == 0
            recovery_calls = sandbox.calls[calls_before:]
            observed_refs = {fields['trx_id'] for path, fields in recovery_calls
                             if path.endswith('/status-transaction.php')}
            assert set(recovery_refs) <= observed_refs
            assert not any(path.endswith('/create.php') for path, _ in recovery_calls)

        race_fixture, race = begin('release')
        assert lifecycle.create(race.payment_id, **args).state == 'created'
        race_ref, race_trx = payment(race.payment_id)[:2]
        sandbox.rows[race_ref]['status'] = 'berhasil'
        context = multiprocessing.get_context('spawn')
        results = context.Queue()
        worker = context.Process(target=competing_payment,
                                 args=(race.payment_id, sandbox.path, 'refresh', {}, results))
        connections.close_all()
        with transaction.atomic():
            db.one('SELECT id FROM public.orders WHERE id=%s FOR UPDATE', (race.order_id,))
            worker.start()
            deadline = time.monotonic() + 5
            blocked = False
            while time.monotonic() < deadline:
                blocked = admin.execute("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE application_name='lhie_candidate' AND wait_event_type='Lock')").fetchone()[0]
                if blocked:
                    break
                time.sleep(0.01)
            assert blocked, 'settlement did not contend on a real PostgreSQL lock'
            locked_order, _, locked_payments = lifecycle._locked(race.payment_id)
            lifecycle.orders._release_locked(locked_order, locked_payments, status='expired')
        worker.join(timeout=5)
        outcome = results.get(timeout=5)
        results.close()
        assert worker.exitcode == 0 and outcome == 'review', outcome
        assert economics(race_fixture, race) == ('expired', 'paid', 'paid', 4, 0, 0, 1, 1)
        assert callback.send(event(race_ref, race_trx)) == 200
        assert lifecycle.consume_callbacks(race.payment_id).state == 'review'
        assert economics(race_fixture, race) == ('expired', 'paid', 'paid', 4, 0, 0, 1, 1)
        assert admin.execute('SELECT count(*) FROM public.manual_fulfillment_reviews WHERE payment_id=%s',
                             (race.payment_id,)).fetchone() == (1,)
        inflight_fixture, inflight = begin('rollback')
        sandbox.block_create = True
        create_results = context.Queue()
        creator = context.Process(target=competing_payment,
                                  args=(inflight.payment_id, sandbox.path, 'create', args, create_results))
        connections.close_all()
        creator.start()
        try:
            assert sandbox.create_received.wait(timeout=5), 'create request did not reach synthetic provider'
            lifecycle.orders.apply_pending_cancellation(inflight.order_id, customer_id=inflight_fixture['customer_id'])
        finally:
            sandbox.release_create.set()
            creator.join(timeout=7)
            sandbox.block_create = False
        create_outcome = create_results.get(timeout=5)
        create_results.close()
        assert creator.exitcode == 0 and create_outcome == 'failed', create_outcome
        inflight_ref, inflight_trx, total = payment(inflight.payment_id)[:3]
        assert inflight_ref and inflight_trx and total == 15000
        assert lifecycle.classify(inflight.payment_id).presentation is None
        sandbox.rows[inflight_ref]['status'] = 'berhasil'
        assert lifecycle.refresh(inflight.payment_id).state == 'review'
        assert economics(inflight_fixture, inflight) == ('cancelled', 'paid', 'paid', 4, 0, 0, 1, 1)
        return {'status': 'passed', 'dataset': 'synthetic-p08-qris',
                'scope': ['P07 checkout and idempotent invoice snapshot', 'ambiguous create fails closed',
                          'authenticated callback replay and identity mismatch', 'provider-confirmed settlement and receipt',
                          'PostgreSQL lock contention, terminal release and late-payment review']
                         + (['Payment and receipt bounded consumers, ordered synthetic credential delivery',
                             'history total mismatch, unknown delivery and bounded lost-wakeup fairness'] if integrated else []),
                'limits': ['Synthetic Unix HTTP only; no production credentials or network targets',
                           'Callback signature uses synthetic HMAC; no live provider certification',
                           'Worker handlers invoked in-process; no long-lived worker scheduling claim']}
    finally:
        callback.close()
        sandbox.close()
        admin.close()
        connections.close_all()


if __name__ == '__main__':
    try:
        print(json.dumps(run(), indent=2))
    except Exception as error:
        from pathlib import Path
        import traceback
        sys.tracebacklimit = 100
        diagnostic = Path('/evidence/probe-failure.txt')
        diagnostic.write_text(traceback.format_exc())
        diagnostic.chmod(0o600)
        frames = traceback.extract_tb(error.__traceback__)
        location = {'file': frames[-1].filename.rsplit('/', 1)[-1], 'line': frames[-1].lineno} if frames else {}
        print(json.dumps({'status': 'failed', 'error_type': type(error).__name__, **location}), file=sys.stderr)
        raise SystemExit(1)
