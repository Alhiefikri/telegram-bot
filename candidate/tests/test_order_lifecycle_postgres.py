"""P12 changed-flow smoke and competing transitions on admitted PostgreSQL."""
from dataclasses import asdict
from datetime import timedelta
import multiprocessing

from tests.test_checkout_postgres import admin_connection


def competing_transition(spec, barrier, queue):
    from lhie_candidate.startup import configure_django
    configure_django()
    from django.db import connections
    from lhie_candidate.checkout.lifecycle import OrderLifecycle
    from lhie_candidate.checkout.errors import CheckoutError
    from lhie_candidate.compat.crypto import LaravelEncrypter
    service = OrderLifecycle(encrypter=LaravelEncrypter(bytes(32)), clock=lambda: spec['at'])
    try:
        barrier.wait(timeout=15)
        action = spec['action']
        if action == 'paid':
            result = service.apply_payment_paid(spec['payment_id'])
        elif action == 'cancel':
            result = service.apply_pending_cancellation(spec['order_id'], customer_id=spec['customer'])
        elif action == 'expire':
            result = service.apply_pending_expiry(spec['order_id'])
        else:
            result = service.apply_payment_refunded(spec['payment_id'], actor_user_id=spec['actor'],
                reference=spec.get('reference'))
        queue.put(('applied', asdict(result)))
    except CheckoutError as error:
        queue.put(('rejected', error.code))
    except Exception as error:
        queue.put(('failed', type(error).__name__))
    finally:
        connections.close_all()


def race_transitions(specs):
    from django.db import connections
    connections.close_all()
    context = multiprocessing.get_context('spawn')
    barrier = context.Barrier(len(specs) + 1)
    queue = context.Queue()
    children = [context.Process(target=competing_transition, args=(spec, barrier, queue)) for spec in specs]
    try:
        for child in children:
            child.start()
        barrier.wait(timeout=15)
        results = [queue.get(timeout=30) for child in children]
        for child in children:
            child.join(timeout=10)
            assert child.exitcode == 0, 'Transition worker failed'
        assert not any(state == 'failed' for state, _ in results), results
        return results
    finally:
        for child in children:
            if child.is_alive():
                child.kill()
                child.join()
        queue.close()


def exercise_order_lifecycle(fixtures):
    from django.db import DatabaseError, connections
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.checkout.errors import CheckoutError
    from lhie_candidate.checkout.intents import PaymentPlan, StoreBalancePreparation
    from lhie_candidate.checkout.lifecycle import OrderLifecycle, now
    from lhie_candidate.checkout.service import CheckoutService, CheckoutRequest
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.readiness import verify_dependencies
    from lhie_candidate.receipt.source import ReceiptSource, ReceiptUnavailable
    from lhie_candidate.runtime import application_key
    from unittest.mock import patch

    class PendingPreparation(StoreBalancePreparation):
        provider, method = 'manual', 'QR_MANUAL'
        def prepare(self, pricing, setting, preflight, at):
            return PaymentPlan(self.provider, self.method, pricing.idr_total, at + timedelta(minutes=15))

    admin = admin_connection()
    db = Database()
    cipher = LaravelEncrypter(application_key())
    lifecycle = OrderLifecycle(encrypter=cipher)
    pending = CheckoutService(preparation=PendingPreparation())
    balance = CheckoutService()
    verify_dependencies()
    actor = admin.execute("""INSERT INTO public.users(name,email,password,role,created_at,updated_at)
        VALUES ('Synthetic P12','p12@example.invalid','not-a-login-hash','owner',now(),now()) RETURNING id""").fetchone()[0]
    support = admin.execute("""INSERT INTO public.users(name,email,password,role,created_at,updated_at)
        VALUES ('Synthetic Support','p12-support@example.invalid','not-a-login-hash','support',now(),now()) RETURNING id""").fetchone()[0]

    def begin(name, *, service=pending):
        fixture = fixtures[name]
        quote = service.quote_cart(fixture['customer_id'], fixture['cart_id'])
        return service.checkout(CheckoutRequest(fixture['customer_id'], fixture['cart_id'], 'p12-' + name, quote))

    def reject(operation, expected=None):
        try:
            operation()
        except CheckoutError as error:
            if expected is not None:
                assert error.code == expected, (error.code, expected)
        else:
            raise AssertionError('Expected lifecycle rejection')

    def state(order_id):
        return admin.execute('''SELECT o.order_status,o.payment_status,p.status,
            (SELECT count(*) FROM public.inventory_movements WHERE order_id=o.id AND type='sale'),
            (SELECT count(*) FROM public.inventory_movements WHERE order_id=o.id AND type='release')
            FROM public.orders o JOIN public.payments p ON p.order_id=o.id WHERE o.id=%s''', (order_id,)).fetchone()

    def snapshot(order_id):
        return admin.execute('''SELECT o.customer_id,o.total_amount,o.currency,o.terms_version,o.terms_accepted_at,
            p.id,p.amount,p.currency,p.provider,p.method,p.merchant_ref,p.provider_reference,p.provider_total_payment,
            p.provider_fee,p.provider_expires_at,p.provider_presentation FROM public.orders o JOIN public.payments p
            ON p.order_id=o.id WHERE o.id=%s''', (order_id,)).fetchone()

    try:
        paid = begin('python')
        original = snapshot(paid.order_id)
        reject(lambda: lifecycle.apply_pending_cancellation(paid.order_id, customer_id=fixtures['foreign']['customer_id']), 'foreign_order')
        reject(lambda: lifecycle.apply_manual_completion(paid.order_id, actor_user_id=support), 'operator_forbidden')
        reject(lambda: lifecycle.apply_payment_paid(paid.payment_id), 'payment_receipt_unclaimed')
        admin.execute('UPDATE public.payments SET payment_proof_submitted_at=now() WHERE id=%s', (paid.payment_id,))
        due = now() + timedelta(minutes=20)
        expired = OrderLifecycle(encrypter=cipher, clock=lambda: due)
        assert not expired.apply_pending_expiry(paid.order_id).newly_applied
        reject(lambda: lifecycle.apply_pending_cancellation(paid.order_id, customer_id=fixtures['python']['customer_id']), 'payment_proof_processing')
        assert lifecycle.apply_manual_completion(paid.order_id, actor_user_id=actor).receipt_eligible
        assert not lifecycle.apply_manual_completion(paid.order_id, actor_user_id=actor).newly_applied
        assert state(paid.order_id) == ('completed', 'paid', 'paid', 1, 0)
        assert snapshot(paid.order_id) == original
        rejected = begin('stale')
        lifecycle.apply_manual_rejection(rejected.order_id, actor_user_id=actor)
        assert state(rejected.order_id) == ('cancelled', 'failed', 'failed', 0, 1)
        assert admin.execute('SELECT checkout_key FROM public.orders WHERE id=%s', (rejected.order_id,)).fetchone() == (None,)

        # A real provider adapter observes synthetic HTTP facts after local cancellation.
        from p08_fixture import Sandbox
        from lhie_candidate.payments.sakurupiah import SakurupiahSandboxAdapter
        from lhie_candidate.payments.lifecycle import SakurupiahLifecycle
        from lhie_candidate.checkout.sakurupiah import SakurupiahPreparation
        sandbox = Sandbox()
        try:
            admin.execute("UPDATE public.manual_payment_settings SET payment_mode='sakurupiah',sakurupiah_channel='QRIS'")
            provider = SakurupiahSandboxAdapter(transport=sandbox)
            service = CheckoutService(preparation=SakurupiahPreparation(provider))
            late = begin('release', service=service)
            rail = SakurupiahLifecycle(provider, cipher)
            created = rail.create(late.payment_id, phone='08000000000', callback_url='https://synthetic.invalid/callback/sakurupiah', return_url='https://synthetic.invalid/return')
            assert created.state == 'created'
            immutable = snapshot(late.order_id)
            lifecycle.apply_pending_cancellation(late.order_id, customer_id=fixtures['release']['customer_id'])
            merchant = admin.execute('SELECT merchant_ref FROM public.payments WHERE id=%s', (late.payment_id,)).fetchone()[0]
            sandbox.rows[merchant]['status'] = 'berhasil'
            assert rail.refresh(late.payment_id).state == 'review'
            assert rail.refresh(late.payment_id).state == 'review'
            assert state(late.order_id) == ('cancelled', 'paid', 'paid', 0, 1)
            review_id = admin.execute('SELECT id FROM public.manual_fulfillment_reviews WHERE payment_id=%s', (late.payment_id,)).fetchone()[0]
            source = ReceiptSource(cipher)
            chat = admin.execute('SELECT telegram_id FROM public.customers WHERE id=%s', (fixtures['release']['customer_id'],)).fetchone()[0]
            try:
                source.authorize(late.order_id, chat)
            except ReceiptUnavailable:
                pass
            else:
                raise AssertionError('Late review exposed credentials')
            lifecycle.resolve_review(review_id, actor_user_id=actor, action='fulfill', reason='Synthetic verified attribution')
            assert state(late.order_id) == ('completed', 'paid', 'paid', 1, 1)
            assert source.load(late.order_id, chat)['order_id'] == late.order_id
            refund = lifecycle.apply_payment_refunded(late.payment_id, actor_user_id=actor, reference='p12-external-remainder')
            assert refund.fully_refunded
            assert not lifecycle.apply_payment_refunded(late.payment_id, actor_user_id=actor, reference='p12-external-remainder').newly_applied
            assert snapshot(late.order_id) == immutable
            assert admin.execute('''SELECT count(*) FROM public.product_stock_items s JOIN public.order_items i ON i.id=s.order_item_id
                WHERE i.order_id=%s AND s.retired_at IS NOT NULL''', (late.order_id,)).fetchone() == (1,)
            assert admin.execute("SELECT count(*) FROM public.store_balance_entries WHERE refund_order_id=%s OR (source_type='order' AND source_id=%s AND kind='refund_credit')", (late.order_id, late.order_id)).fetchone() == (0,)
        finally:
            sandbox.close()
            admin.execute("UPDATE public.manual_payment_settings SET payment_mode='manual'")

        outcomes = {}
        for name, action in (('maintenance', 'cancel'), ('pause', 'expire'), ('restore', 'refund')):
            order = begin(name)
            from django.db import transaction
            from lhie_candidate.payments.receipts import claim_owner_attestation
            with transaction.atomic():
                _, payment, _, _ = lifecycle.lock_payment(order.payment_id)
                assert claim_owner_attestation(db, payment=payment).allowed
            spec = {'order_id': order.order_id, 'payment_id': order.payment_id, 'actor': actor,
                    'customer': fixtures[name]['customer_id'], 'at': due, 'reference': 'p12-race-' + name}
            observed = race_transitions([{**spec, 'action': 'paid'}, {**spec, 'action': action}])
            final = state(order.order_id)
            assert final[3] + final[4] == 1, (observed, final)
            assert final[:3] in (('processing', 'paid', 'paid'), ('cancelled', 'refunded', 'refunded'),
                                 ('cancelled', 'paid', 'paid'), ('expired', 'paid', 'paid')), (observed, final)
            assert admin.execute('SELECT count(*) FROM public.external_money_receipts WHERE target_id=%s', (order.payment_id,)).fetchone() == (1,)
            outcomes['paid_vs_' + action] = {'results': [status for status, _ in observed], 'final': list(final)}

        original_balance = begin('duplicate', service=balance)
        before_balance = admin.execute('SELECT balance FROM public.customer_balance_accounts WHERE customer_id=%s', (fixtures['duplicate']['customer_id'],)).fetchone()[0]
        admin.execute('UPDATE public.checkout_maintenance_settings SET is_active=true')
        admin.execute("UPDATE public.balance_restore_gates SET state='open',opened_at=now(),window_start=now(),window_end=now()")
        spec = {'action': 'refund', 'order_id': original_balance.order_id, 'payment_id': original_balance.payment_id,
                'actor': actor, 'customer': fixtures['duplicate']['customer_id'], 'at': due}
        replay = race_transitions([spec, spec])
        assert [value for value, _ in replay] == ['applied', 'applied']
        assert sorted(row['newly_applied'] for _, row in replay) == [False, True]
        assert admin.execute('SELECT balance FROM public.customer_balance_accounts WHERE customer_id=%s', (fixtures['duplicate']['customer_id'],)).fetchone() == (before_balance + original_balance.amount,)
        assert admin.execute("SELECT count(*) FROM public.store_balance_entries WHERE source_type='order' AND source_id=%s AND kind='refund_credit'", (original_balance.order_id,)).fetchone() == (1,)
        assert state(original_balance.order_id) == ('completed', 'refunded', 'refunded', 1, 0)
        admin.execute('UPDATE public.checkout_maintenance_settings SET is_active=false')
        admin.execute("UPDATE public.balance_restore_gates SET state='cleared'")

        # Final audit failure must undo stock, status, receipt claim and review writes.
        rollback = begin('rollback')
        admin.execute('''CREATE FUNCTION public.p12_audit_fault() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN IF NEW.subject_id='%s' THEN RAISE EXCEPTION 'synthetic lifecycle fault' USING ERRCODE='23514'; END IF; RETURN NEW; END $$''' % rollback.order_id)
        admin.execute('CREATE TRIGGER p12_audit_fault BEFORE INSERT ON public.audit_logs FOR EACH ROW EXECUTE FUNCTION public.p12_audit_fault()')
        try:
            lifecycle.apply_manual_completion(rollback.order_id, actor_user_id=actor)
        except DatabaseError as error:
            assert error.__cause__.sqlstate == '23514'
        else:
            raise AssertionError('Final-step rollback fault not reached')
        finally:
            admin.execute('DROP TRIGGER p12_audit_fault ON public.audit_logs')
            admin.execute('DROP FUNCTION public.p12_audit_fault()')
        assert state(rollback.order_id) == ('pending_payment', 'pending', 'pending', 0, 0)
        assert admin.execute('SELECT count(*) FROM public.external_money_receipts WHERE target_id=%s', (rollback.payment_id,)).fetchone() == (0,)

        # Lost wakeup repair runs the same due transition under the worker budget.
        from lhie_candidate.workers.payment import PaymentHandler
        class Rail:
            orders = expired
            def consume_callbacks(self, identity):
                raise AssertionError('No hints should exist in this fixture')
            def recover(self, identity):
                raise AssertionError('No ambiguous create should exist in this fixture')
        with patch('lhie_candidate.workers.payment._now', return_value=due):
            sweep = PaymentHandler(Rail(), db).sweep(25)
        assert sweep['expiry'] > 0 and sweep['failures'] == 0, sweep
        assert state(rollback.order_id) == ('expired', 'expired', 'expired', 0, 1)
        from tests.test_order_refunds import exercise_order_refunds
        refunds = exercise_order_refunds(fixtures)
        return {'lifecycle_e2e': 'manual paid/proof/reject; provider late review/fulfill/external refund; original snapshots preserved',
                'postgres_competing_transitions': outcomes, 'refund_replay': 'one original IDR credit, no restock',
                'atomic_rollback': 'status/stock/claim/audit rolled back', 'expiry_worker': sweep,
                'refund_protocol': refunds}
    finally:
        admin.close()
        connections.close_all()
