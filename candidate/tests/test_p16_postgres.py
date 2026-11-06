"""Disposable PostgreSQL proof of the P16 wallet and receipt authorities."""

from tests.test_checkout_postgres import admin_connection, race


def exercise_p16(fixtures):
    from django.db import connections
    from django.db import transaction
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.checkout.errors import CheckoutError
    from lhie_candidate.checkout.intents import UsdtBalancePreparation
    from lhie_candidate.checkout.lifecycle import OrderLifecycle
    from lhie_candidate.checkout.service import CheckoutRequest, CheckoutService
    from lhie_candidate.checkout.wallet_selection import BalanceCheckoutRequest
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.usdt.ledger import ApprovedUsdtReversal, UsdtLedger
    from lhie_candidate.usdt.reconciliation import UsdtAccountingAggregator, UsdtReconciliation
    from lhie_candidate.runtime import application_key
    from tests.test_usdt_receipt_receiver_registry import exercise_usdt_receipt_receiver_registry

    admin = admin_connection()
    service = CheckoutService()
    usdt = CheckoutService(preparation=UsdtBalancePreparation())
    cases = fixtures['p16']

    def request(name, key, selection=None):
        fixture = cases[name]
        return CheckoutRequest(fixture['customer_id'], fixture['cart_id'], key,
            service.quote_cart(fixture['customer_id'], fixture['cart_id']), balance_request=selection)

    def reject(operation, code):
        try:
            operation()
        except CheckoutError as error:
            assert error.code == code, (error.code, code)
        else:
            raise AssertionError('Expected rejection: ' + code)

    try:
        initial = request('purchase', 'p16-purchase')
        result = usdt.checkout(initial)
        assert (result.amount, result.payment_status, result.order_status) == (1000001, 'paid', 'completed')
        assert usdt.checkout(initial).order_id == result.order_id
        assert admin.execute('''SELECT o.currency,p.provider,p.currency,p.amount,i.usdt_unit_price,i.usdt_subtotal
            FROM public.orders o JOIN public.payments p ON p.order_id=o.id
            JOIN public.order_items i ON i.order_id=o.id WHERE o.id=%s''', (result.order_id,)).fetchone() == (
                'USDT', 'usdt_balance', 'USDT', 1000001, 1000001, 1000001)
        actor = admin.execute("""INSERT INTO public.users(name,email,password,role,created_at,updated_at)
            VALUES ('P16 owner','p16-owner@example.invalid','not-a-login-hash','owner',now(),now()) RETURNING id""").fetchone()[0]
        lifecycle = OrderLifecycle(encrypter=LaravelEncrypter(application_key()))
        refund = lifecycle.apply_payment_refunded(result.payment_id, actor_user_id=actor)
        assert refund.newly_applied
        lifecycle.apply_payment_refunded(result.payment_id, actor_user_id=actor)
        entries = admin.execute('''SELECT e.kind,e.amount_micro,e.balance_after_micro FROM public.customer_usdt_balance_entries e
            JOIN public.customer_usdt_balance_accounts a ON a.id=e.customer_usdt_balance_account_id
            WHERE a.customer_id=%s ORDER BY e.account_sequence''', (cases['purchase']['customer_id'],)).fetchall()
        assert entries == [('funding_credit',1500000,1500000),
                           ('purchase_debit',1000001,499999), ('refund_credit',1000001,1500000)]
        assert admin.execute("SELECT count(*) FROM public.store_balance_entries WHERE source_type='order' AND source_id=%s", (result.order_id,)).fetchone() == (0,)
        health = UsdtReconciliation().snapshot(cases['purchase']['customer_id'])
        assert health['available'] and health['balance_micro'] == 1500000 and not health['issues']
        aggregate = UsdtAccountingAggregator()
        with transaction.atomic():
            assert aggregate.catch_up('p16-proof', limit=100)['processed'] > 0
            assert aggregate.catch_up('p16-proof', limit=100)['processed'] == 0
            status = aggregate.status('p16-proof')
            assert status['consistent'] and status['pending_events'] == 0
        # Inject an uncommitted append without projection advancement, then roll it all back.
        with transaction.atomic():
            db = Database()
            db.one('SELECT id FROM public.customers WHERE id=%s FOR SHARE', (cases['purchase']['customer_id'],))
            account = db.one("""SELECT id,balance_micro,version FROM public.customer_usdt_balance_accounts
                WHERE customer_id=%s FOR UPDATE""", (cases['purchase']['customer_id'],))
            db.execute("""INSERT INTO public.customer_usdt_balance_entries
                (customer_usdt_balance_account_id,account_sequence,kind,amount_micro,balance_after_micro,
                 source_type,source_id,idempotency_key,request_fingerprint,created_at)
                VALUES (%s,%s,'funding_credit',1,%s,'funding_intent','p16-drift-fault',
                        'p16-drift-fault',%s,CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')""",
                (account['id'], account['version'] + 1, account['balance_micro'] + 1, 'f' * 64))
            reject(lambda: UsdtLedger().lock_for_purchase(cases['purchase']['customer_id']),
                   'usdt_balance_projection_mismatch')
            assert UsdtReconciliation().freeze_drift(account['id']) == 'frozen'
            transaction.set_rollback(True)
        assert UsdtReconciliation().snapshot(cases['purchase']['customer_id'])['available']
        reversal_customer = cases['reversal']['customer_id']
        original = admin.execute("""SELECT e.id FROM public.customer_usdt_balance_entries e
            JOIN public.customer_usdt_balance_accounts a ON a.id=e.customer_usdt_balance_account_id
            WHERE a.customer_id=%s AND e.kind='funding_credit'""", (reversal_customer,)).fetchone()[0]
        source = ApprovedUsdtReversal(reversal_customer, 1500000, original, 'p16-approved-reference')
        ledger = UsdtLedger()
        with transaction.atomic():
            first = ledger.reverse_approved_entry(source, 'p16-reversal')
            replay = ledger.reverse_approved_entry(source, 'p16-reversal')
            assert first['id'] == replay['id']
            reject(lambda: ledger.reverse_approved_entry(
                ApprovedUsdtReversal(reversal_customer, 1500000, original, 'different-reference'),
                'p16-reversal'), 'usdt_balance_identity_conflict')
        assert admin.execute("""SELECT balance_micro FROM public.customer_usdt_balance_accounts
            WHERE customer_id=%s""", (reversal_customer,)).fetchone() == (0,)

        selected = request('automatic', 'p16-auto', BalanceCheckoutRequest('idr_first', 15000, 1000001))
        chosen = service.checkout(selected)
        assert chosen.amount == 1000001
        assert admin.execute('SELECT provider,currency FROM public.payments WHERE id=%s', (chosen.payment_id,)).fetchone() == ('usdt_balance', 'USDT')
        stale = request('stale', 'p16-stale', BalanceCheckoutRequest('idr_first', 15000, 1000000))
        reject(lambda: service.checkout(stale), 'price_changed')
        frozen = cases['frozen']
        admin.execute("UPDATE public.customer_usdt_balance_accounts SET frozen_reason='incident' WHERE customer_id=%s", (frozen['customer_id'],))
        freeze_request = request('frozen', 'p16-frozen', BalanceCheckoutRequest('idr_first', 15000, 1000001))
        reject(lambda: service.checkout(freeze_request), 'usdt_balance_frozen')
        race_selection = BalanceCheckoutRequest('usd_only', None, 1000001)
        attempts = [request('race', 'p16-race-1', race_selection),
                    request('race_second', 'p16-race-2', race_selection)]
        outcomes = race(attempts)
        assert sorted(state for state, _ in outcomes) == ['accepted', 'rejected'], outcomes
        assert admin.execute("""SELECT count(*),sum(e.amount_micro) FROM public.customer_usdt_balance_entries e
            JOIN public.customer_usdt_balance_accounts a ON a.id=e.customer_usdt_balance_account_id
            WHERE a.customer_id=%s AND e.kind='purchase_debit'""", (cases['race']['customer_id'],)).fetchone() == (1,1000001)
        registry = exercise_usdt_receipt_receiver_registry()
        return {'usdt_purchase_refund_replay': 'passed', 'automatic_selection_freeze_stale': 'passed',
                'concurrent_wallet_debit': 'passed', 'receipt_receiver': registry}
    finally:
        admin.close()
        connections.close_all()
