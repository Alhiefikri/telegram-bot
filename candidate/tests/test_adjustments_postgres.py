"""Callable P15 proof; caller must admit and configure the disposable database."""
import os
from concurrent.futures import ThreadPoolExecutor
from threading import Barrier
from types import SimpleNamespace
from uuid import uuid4


def exercise_adjustments(admin):
    if os.environ.get('P719_FUNDING_SMOKE') != 'disposable-local-only':
        raise RuntimeError('Explicit disposable funding opt-in required')
    identity = admin.execute('SELECT current_database(),current_user,host(inet_server_addr()),inet_server_port()').fetchone()
    if identity != ('disposable', 'harness', '127.0.0.1', 5432):
        raise RuntimeError('Disposable funding identity mismatch')
    import bcrypt
    from django.db import connections, transaction
    from lhie_candidate.adjustments.password import AdjustmentRejected
    from lhie_candidate.adjustments.service import BalanceAdjustments
    from lhie_candidate.auth.sessions import Session
    from lhie_candidate.checkout.balance import StoreBalanceLedger
    from lhie_candidate.checkout.errors import CheckoutError
    from lhie_candidate.compat.identity import sha256_digest

    suffix = uuid4().hex
    password = 'synthetic-adjustment-password'
    stored = bcrypt.hashpw(password.encode(), bcrypt.gensalt(rounds=4)).decode()
    telegram = str(100000000000000000 + int(suffix[:12], 16))
    customer = admin.execute("""INSERT INTO public.customers(first_name,telegram_id,locale,created_at,updated_at)
        VALUES ('Synthetic P15',%s,'en',now(),now()) RETURNING id""", (telegram,)).fetchone()[0]
    actors = {}
    for role in ('owner', 'admin'):
        actor = admin.execute('''INSERT INTO public.users(name,email,password,role,created_at,updated_at)
            VALUES (%s,%s,%s,%s,now(),now()) RETURNING id''',
            ('P15 ' + role, f'p15-{role}-{suffix}@example.invalid', stored, role)).fetchone()[0]
        session = Session()
        session.set('admin', {'id': actor, 'password_digest': sha256_digest(stored)})
        session.save()
        actors[role] = SimpleNamespace(auth_session=session)
    service = BalanceAdjustments(admin_chat_id='123456789')
    owner, admin_request = actors['owner'], actors['admin']
    revoked_session = Session()
    revoked_session.set('admin', dict(owner.auth_session.data['admin']))
    revoked_session.save()
    revoked = SimpleNamespace(auth_session=Session(revoked_session.token))
    revoked_preview = service.request(revoked, telegram, 17, 'synthetic revoked session')
    admin.execute('DELETE FROM django_candidate.browser_sessions WHERE session_digest=%s',
                  (sha256_digest(revoked_session.token),))
    for action in (
        lambda: service.request(revoked, telegram, 17, 'revoked request'),
        lambda: service.confirm(revoked, str(revoked_preview['id']), revoked_preview['fingerprint'],
                                revoked_preview['version'], password),
    ):
        try:
            action()
        except AdjustmentRejected as error:
            assert error.code == 'authenticated_session_required'
        else:
            raise AssertionError('Revoked owner session accepted')
    assert admin.execute('SELECT count(*) FROM public.store_balance_entries WHERE source_id=%s',
                         (str(revoked_preview['id']),)).fetchone()[0] == 0

    lock_owner_id = admin.execute('''INSERT INTO public.users(name,email,password,role,created_at,updated_at)
        VALUES (%s,%s,%s,'owner',now(),now()) RETURNING id''',
        ('P15 lockout owner', f'p15-lockout-{suffix}@example.invalid', stored)).fetchone()[0]
    lock_session = Session()
    lock_session.set('admin', {'id': lock_owner_id, 'password_digest': sha256_digest(stored)})
    lock_session.save()
    lock_owner = SimpleNamespace(auth_session=lock_session)
    locked_preview = service.request(lock_owner, telegram, 19, 'synthetic lockout')
    locked_args = (lock_owner, str(locked_preview['id']), locked_preview['fingerprint'], locked_preview['version'])
    for _ in range(5):
        try:
            service.confirm(*locked_args, 'wrong synthetic password')
        except AdjustmentRejected as error:
            assert error.code == 'invalid_password'
        else:
            raise AssertionError('Incorrect password accepted')
    state = admin.execute("""SELECT jsonb_array_length(failed_at::jsonb), episode,
        locked_until > (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta') + interval '14 minutes',
        locked_until <= (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta') + interval '15 minutes'
        FROM public.balance_adjustment_auth_states WHERE user_id=%s""", (lock_owner_id,)).fetchone()
    assert state[0] == 5 and state[1] is not None and state[2] and state[3]
    assert admin.execute("""SELECT count(*) FROM public.telegram_outbound_effects
        WHERE effect_key=%s AND state='pending'""",
        ('balance-adjustment:lockout:' + str(state[1]) + ':owner:v1',)).fetchone()[0] == 1
    try:
        service.confirm(*locked_args, password)
    except AdjustmentRejected as error:
        assert error.code == 'owner_locked'
    else:
        raise AssertionError('Locked owner confirmed an adjustment')
    assert admin.execute('SELECT count(*) FROM public.store_balance_entries WHERE source_id=%s',
                         (str(locked_preview['id']),)).fetchone()[0] == 0
    try:
        service.request(admin_request, telegram, 100, 'synthetic denied')
    except AdjustmentRejected as error:
        assert error.code == 'owner_required'
    else:
        raise AssertionError('Non-owner created an adjustment')
    preview = service.request(owner, telegram, 100, 'synthetic owner adjustment')
    args = (owner, str(preview['id']), preview['fingerprint'], preview['version'])
    try:
        service.confirm(*args, 'wrong synthetic password')
    except AdjustmentRejected as error:
        assert error.code == 'invalid_password'
    else:
        raise AssertionError('Incorrect password posted money')
    assert admin.execute('SELECT count(*) FROM public.store_balance_entries WHERE source_id=%s', (str(preview['id']),)).fetchone()[0] == 0
    entry = service.confirm(*args, password)
    assert entry['kind'] == 'admin_adjustment_credit' and entry['amount'] == 100
    assert service.confirm(*args, password)['id'] == entry['id']
    reversal = service.request_reversal(owner, telegram, str(preview['id']))
    reversed_entry = service.confirm(owner, str(reversal['id']), reversal['fingerprint'], reversal['version'], password)
    assert reversed_entry['kind'] == 'admin_adjustment_reversal' and reversed_entry['amount'] == 100
    assert admin.execute('SELECT balance FROM public.customer_balance_accounts WHERE customer_id=%s', (customer,)).fetchone()[0] == 0

    original = service.request(owner, telegram, 100, 'synthetic concurrent spend')
    service.confirm(owner, str(original['id']), original['fingerprint'], original['version'], password)
    pending = service.request_reversal(owner, telegram, str(original['id']))
    order_id, payment_id = str(uuid4()), str(uuid4())
    admin.execute("""INSERT INTO public.orders(id,customer_id,invoice_number,order_status,payment_status,total_amount,currency,created_at,updated_at)
        VALUES (%s,%s,%s,'pending_payment','pending',80,'IDR',now(),now())""", (order_id, customer, 'P15-' + suffix))
    admin.execute("""INSERT INTO public.payments(id,order_id,provider,merchant_ref,method,amount,currency,status,created_at,updated_at)
        VALUES (%s,%s,'store_balance',%s,'STORE_BALANCE',80,'IDR','pending',now(),now())""", (payment_id, order_id, 'P15-' + suffix))

    gate = Barrier(2)
    def reverse():
        try:
            gate.wait(timeout=10)
            result = BalanceAdjustments(admin_chat_id='123456789').confirm(owner, str(pending['id']), pending['fingerprint'], pending['version'], password)
            return ('reversed', result['id'])
        except AdjustmentRejected as error:
            return (error.code, None)
        finally:
            connections.close_all()

    def spend():
        try:
            gate.wait(timeout=10)
            with transaction.atomic():
                entry = StoreBalanceLedger().debit_purchase(customer, order_id)
            return ('spent', entry['id'])
        except CheckoutError as error:
            return (error.code, None)
        finally:
            connections.close_all()

    with ThreadPoolExecutor(max_workers=2) as pool:
        first, second = pool.submit(reverse), pool.submit(spend)
        reversed_result, spend_result = first.result(), second.result()
    assert (reversed_result[0], spend_result[0]) in {
        ('reversed', 'insufficient_store_balance'), ('reversal_insufficient_funds', 'spent')}
    balance = admin.execute('SELECT balance FROM public.customer_balance_accounts WHERE customer_id=%s', (customer,)).fetchone()[0]
    assert balance == (0 if reversed_result[0] == 'reversed' else 20)
    if reversed_result[0] == 'reversal_insufficient_funds':
        assert admin.execute("SELECT count(*) FROM public.audit_logs WHERE action='balance.adjustment.reversal_review' AND subject_id=%s", (str(pending['id']),)).fetchone()[0] == 1
    another = service.request(owner, telegram, 100, 'synthetic insufficient funds')
    service.confirm(owner, str(another['id']), another['fingerprint'], another['version'], password)
    review = service.request_reversal(owner, telegram, str(another['id']))
    second_order, second_payment = str(uuid4()), str(uuid4())
    admin.execute("""INSERT INTO public.orders(id,customer_id,invoice_number,order_status,payment_status,total_amount,currency,created_at,updated_at)
        VALUES (%s,%s,%s,'pending_payment','pending',80,'IDR',now(),now())""", (second_order, customer, 'P15-review-' + suffix))
    admin.execute("""INSERT INTO public.payments(id,order_id,provider,merchant_ref,method,amount,currency,status,created_at,updated_at)
        VALUES (%s,%s,'store_balance',%s,'STORE_BALANCE',80,'IDR','pending',now(),now())""", (second_payment, second_order, 'P15-review-' + suffix))
    with transaction.atomic():
        StoreBalanceLedger().debit_purchase(customer, second_order)
    try:
        service.confirm(owner, str(review['id']), review['fingerprint'], review['version'], password)
    except AdjustmentRejected as error:
        assert error.code == 'reversal_insufficient_funds'
    else:
        raise AssertionError('Insufficient reversal succeeded')
    assert admin.execute("SELECT count(*) FROM public.audit_logs WHERE action='balance.adjustment.reversal_review' AND subject_id=%s", (str(review['id']),)).fetchone()[0] == 1
    from lhie_candidate.compat.crypto import LaravelEncrypter
    crypto = LaravelEncrypter('c' * 32)
    refund_service = BalanceAdjustments(admin_chat_id='123456789', encrypter=crypto)
    refund_order, refund_payment, refund_id = str(uuid4()), str(uuid4()), str(uuid4())
    product = admin.execute("""INSERT INTO public.products(name,description,price_amount,stock,reserved_stock,is_active,created_at,updated_at)
        VALUES (%s,'Synthetic refund',50,0,0,true,now(),now()) RETURNING id""", ('P15 refund ' + suffix,)).fetchone()[0]
    admin.execute("""INSERT INTO public.orders(id,customer_id,invoice_number,order_status,payment_status,total_amount,currency,created_at,updated_at)
        VALUES (%s,%s,%s,'processing','paid',101,'IDR',now(),now())""", (refund_order, customer, 'P15-refund-' + suffix))
    item = admin.execute("""INSERT INTO public.order_items(order_id,product_id,product_name,quantity,local_quantity,supplier_quantity,unit_price,subtotal,created_at,updated_at)
        VALUES (%s,%s,'Synthetic refund',2,1,1,50,101,now(),now()) RETURNING id""", (refund_order, product)).fetchone()[0]
    admin.execute("""INSERT INTO public.payments(id,order_id,provider,merchant_ref,method,amount,currency,status,paid_at,created_at,updated_at)
        VALUES (%s,%s,'manual',%s,'QR_MANUAL',101,'IDR','paid',now(),now(),now())""", (refund_payment, refund_order, 'P15-refund-' + suffix))
    fulfillment = admin.execute("""INSERT INTO public.supplier_fulfillments(order_id,order_item_id,quantity,state,maximum_cost_idr,sources_snapshot,created_at,updated_at)
        VALUES (%s,%s,1,'refund_pending',1,%s,now(),now()) RETURNING id""", (refund_order, item, crypto.encrypt_json([]))).fetchone()[0]
    admin.execute("""INSERT INTO public.order_refunds(id,order_id,payment_id,customer_id,supplier_fulfillment_id,missing_quantity,amount,currency,state,created_at,updated_at)
        VALUES (%s,%s,%s,%s,%s,1,50,'IDR','requested',now(),now())""", (refund_id, refund_order, refund_payment, customer, fulfillment))
    recovery = refund_service.request(owner, telegram, 50, 'synthetic registered refund', category='refund_recovery', source_id=refund_id)
    recovery_args = (owner, str(recovery['id']), recovery['fingerprint'], recovery['version'], password)
    recovered = refund_service.confirm(*recovery_args)
    assert recovered['kind'] == 'refund_credit' and recovered['source_id'] == refund_id and recovered['amount'] == 50
    assert refund_service.confirm(*recovery_args)['id'] == recovered['id']
    assert admin.execute("SELECT state FROM public.order_refunds WHERE id=%s", (refund_id,)).fetchone()[0] == 'confirmed'
    try:
        refund_service.request(owner, telegram, 50, 'duplicate recovery', category='refund_recovery', source_id=refund_id)
    except AdjustmentRejected as error:
        assert error.code == 'invalid_original_refund'
    else:
        raise AssertionError('Resolved refund accepted twice')
    excess_id = str(uuid4())
    admin.execute("""INSERT INTO public.order_refunds(id,order_id,payment_id,customer_id,amount,currency,state,created_at,updated_at)
        VALUES (%s,%s,%s,%s,52,'IDR','requested',now(),now())""", (excess_id, refund_order, refund_payment, customer))
    excess = refund_service.request(owner, telegram, 52, 'synthetic excess refund', category='refund_recovery', source_id=excess_id)
    try:
        refund_service.confirm(owner, str(excess['id']), excess['fingerprint'], excess['version'], password)
    except AdjustmentRejected as error:
        assert error.code == 'refund_resolution_unavailable'
    else:
        raise AssertionError('Refund above original payment accepted')
    assert admin.execute("SELECT count(*) FROM public.store_balance_entries WHERE source_type='order_refund' AND source_id=%s", (excess_id,)).fetchone()[0] == 0
    internal_seed = service.request(owner, telegram, 100, 'synthetic internal refund funding')
    service.confirm(owner, str(internal_seed['id']), internal_seed['fingerprint'], internal_seed['version'], password)
    internal_order, internal_payment = str(uuid4()), str(uuid4())
    internal_refund, internal_excess = str(uuid4()), str(uuid4())
    admin.execute("""INSERT INTO public.orders(id,customer_id,invoice_number,order_status,payment_status,total_amount,currency,created_at,updated_at)
        VALUES (%s,%s,%s,'pending_payment','pending',100,'IDR',now(),now())""",
        (internal_order, customer, 'P15-internal-' + suffix))
    admin.execute("""INSERT INTO public.payments(id,order_id,provider,merchant_ref,method,amount,currency,status,created_at,updated_at)
        VALUES (%s,%s,'store_balance',%s,'STORE_BALANCE',100,'IDR','pending',now(),now())""",
        (internal_payment, internal_order, 'P15-internal-' + suffix))
    with transaction.atomic():
        StoreBalanceLedger().debit_purchase(customer, internal_order)
    admin.execute("UPDATE public.orders SET order_status='processing',payment_status='paid' WHERE id=%s", (internal_order,))
    admin.execute("UPDATE public.payments SET status='paid',paid_at=now() WHERE id=%s", (internal_payment,))
    internal_item = admin.execute("""INSERT INTO public.order_items
        (order_id,product_id,product_name,quantity,local_quantity,supplier_quantity,unit_price,subtotal,created_at,updated_at)
        VALUES (%s,%s,'Synthetic internal refund',2,1,1,50,100,now(),now()) RETURNING id""",
        (internal_order, product)).fetchone()[0]
    internal_fulfillment = admin.execute("""INSERT INTO public.supplier_fulfillments
        (order_id,order_item_id,quantity,state,maximum_cost_idr,sources_snapshot,created_at,updated_at)
        VALUES (%s,%s,1,'refund_pending',1,%s,now(),now()) RETURNING id""",
        (internal_order, internal_item, crypto.encrypt_json([]))).fetchone()[0]
    admin.execute("""INSERT INTO public.order_refunds(id,order_id,payment_id,customer_id,amount,currency,state,created_at,updated_at)
        VALUES (%s,%s,%s,%s,60,'IDR','requested',now(),now())""",
        (internal_refund, internal_order, internal_payment, customer))
    admin.execute("""INSERT INTO public.order_refunds
        (id,order_id,payment_id,customer_id,supplier_fulfillment_id,missing_quantity,amount,currency,state,created_at,updated_at)
        VALUES (%s,%s,%s,%s,%s,1,41,'IDR','requested',now(),now())""",
        (internal_excess, internal_order, internal_payment, customer, internal_fulfillment))
    internal_preview = refund_service.request(owner, telegram, 60, 'synthetic internal refund cap',
                                              category='refund_recovery', source_id=internal_refund)
    try:
        refund_service.confirm(owner, str(internal_preview['id']), internal_preview['fingerprint'],
                               internal_preview['version'], password)
    except AdjustmentRejected as error:
        assert error.code == 'refund_resolution_unavailable'
    else:
        raise AssertionError('Internal refunds above original debit accepted')
    assert admin.execute("""SELECT count(*) FROM public.store_balance_entries
        WHERE source_type='order_refund' AND source_id IN (%s,%s)""",
        (internal_refund, internal_excess)).fetchone()[0] == 0
    intent = admin.execute("""INSERT INTO public.store_balance_funding_intents
        (customer_id,chat_id,canonical_message_id,policy_version,amount,nonce,status,expires_at,created_at,updated_at)
        VALUES (%s,%s,1,1,100,%s,'claimed',now(),now(),now()) RETURNING id""",
        (customer, telegram, suffix)).fetchone()[0]
    topup = admin.execute("""INSERT INTO public.store_balance_top_ups
        (customer_id,store_balance_funding_intent_id,chat_id,canonical_message_id,locale,provider,merchant_ref,
         idempotency_key,currency,amount,status,provider_reference,provider_created_at,provider_total_payment,
         provider_fee,provider_payment_method,sakurupiah_channel,created_at,updated_at)
        VALUES (%s,%s,%s,1,'en','sakurupiah',%s,%s,'IDR',100,'expired',%s,now(),100,0,'QRIS','QRIS',now(),now()) RETURNING id""",
        (customer, intent, telegram, 'P15-topup-' + suffix, 'P15-topup-' + suffix, 'P15-ref-' + suffix)).fetchone()[0]
    compensation = service.request(owner, telegram, 100, 'synthetic top-up recovery', category='top_up_recovery', source_id=str(topup))
    compensation_args = (owner, str(compensation['id']), compensation['fingerprint'], compensation['version'], password)
    compensated = service.confirm(*compensation_args)
    assert compensated['kind'] == 'owner_compensation_credit' and compensated['amount'] == 100
    assert service.confirm(*compensation_args)['id'] == compensated['id']
    assert admin.execute("SELECT status FROM public.store_balance_top_ups WHERE id=%s", (topup,)).fetchone()[0] == 'compensated'
    assert admin.execute("""SELECT count(*) FROM public.external_money_receipt_reservations WHERE legacy_type='store_balance_top_up'
        AND legacy_id=%s AND reason='compensated_unresolved_money' AND resolved_at IS NULL""", (str(topup),)).fetchone()[0] == 1
    import json
    from lhie_candidate.funding.service import FundingService
    from lhie_candidate.payments.sakurupiah import SakurupiahSandboxAdapter
    class SyntheticRelay:
        def post(self, url, **kwargs):
            row = {'trx_id': 'P15-ref-' + suffix, 'merchant_ref': 'P15-topup-' + suffix,
                   'payment_kode': 'QRIS', 'status': 'berhasil', 'payment_status': 'berhasil',
                   'amount': '100', 'total': '100', 'fee': '0'}
            return 200, json.dumps({'status': '200', 'data': [row]}).encode()
    funding = FundingService(provider=SakurupiahSandboxAdapter(transport=SyntheticRelay()))
    observed = funding.check(funding.top_up_by_id(topup))
    assert observed['status'] == 'compensated'
    assert admin.execute("""SELECT count(*) FROM public.store_balance_entries
        WHERE (source_type='balance_top_up' AND source_id=%s AND kind='top_up_credit')
           OR (source_type='owner_compensation' AND source_id=%s AND kind='owner_compensation_credit')""",
        (str(topup), 'top_up:' + str(topup))).fetchone()[0] == 1
    assert admin.execute("""SELECT resolved_at IS NOT NULL FROM public.external_money_receipt_reservations
        WHERE legacy_type='store_balance_top_up' AND legacy_id=%s AND reason='compensated_unresolved_money'""",
        (str(topup),)).fetchone()[0]
    try:
        service.request(owner, telegram, 100, 'duplicate recovery', category='top_up_recovery', source_id=str(topup))
    except AdjustmentRejected as error:
        assert error.code == 'invalid_original_top_up'
    else:
        raise AssertionError('Resolved top-up accepted twice')
    race_intent = admin.execute("""INSERT INTO public.store_balance_funding_intents
        (customer_id,chat_id,canonical_message_id,policy_version,amount,nonce,status,expires_at,created_at,updated_at)
        VALUES (%s,%s,1,1,100,%s,'claimed',now(),now(),now()) RETURNING id""",
        (customer, telegram, uuid4().hex)).fetchone()[0]
    race_ref, race_merchant = 'P15-race-ref-' + suffix, 'P15-race-' + suffix
    race_topup = admin.execute("""INSERT INTO public.store_balance_top_ups
        (customer_id,store_balance_funding_intent_id,chat_id,canonical_message_id,locale,provider,merchant_ref,
         idempotency_key,currency,amount,status,provider_reference,provider_created_at,provider_total_payment,
         provider_fee,provider_payment_method,sakurupiah_channel,created_at,updated_at)
        VALUES (%s,%s,%s,1,'en','sakurupiah',%s,%s,'IDR',100,'expired',%s,now(),100,0,'QRIS','QRIS',now(),now()) RETURNING id""",
        (customer, race_intent, telegram, race_merchant, race_merchant, race_ref)).fetchone()[0]
    race_preview = service.request(owner, telegram, 100, 'synthetic competing recovery',
                                   category='top_up_recovery', source_id=str(race_topup))
    race_gate = Barrier(2)
    class RaceRelay:
        def post(self, url, **kwargs):
            row = {'trx_id': race_ref, 'merchant_ref': race_merchant,
                   'payment_kode': 'QRIS', 'status': 'berhasil', 'payment_status': 'berhasil',
                   'amount': '100', 'total': '100', 'fee': '0'}
            return 200, json.dumps({'status': '200', 'data': [row]}).encode()

    def compete_compensation():
        try:
            race_gate.wait(timeout=10)
            entry = BalanceAdjustments(admin_chat_id='123456789').confirm(
                owner, str(race_preview['id']), race_preview['fingerprint'], race_preview['version'], password)
            return entry['kind']
        except AdjustmentRejected as error:
            return error.code
        finally:
            connections.close_all()

    def compete_settlement():
        try:
            race_funding = FundingService(provider=SakurupiahSandboxAdapter(transport=RaceRelay()))
            snapshot = race_funding.top_up_by_id(race_topup)
            race_gate.wait(timeout=10)
            return race_funding.check(snapshot)['status']
        finally:
            connections.close_all()

    before_race = admin.execute('SELECT balance FROM public.customer_balance_accounts WHERE customer_id=%s',
                                (customer,)).fetchone()[0]
    with ThreadPoolExecutor(max_workers=2) as pool:
        owner_future, provider_future = pool.submit(compete_compensation), pool.submit(compete_settlement)
        owner_result, provider_result = owner_future.result(), provider_future.result()
    assert (owner_result, provider_result) in {
        ('owner_compensation_credit', 'compensated'), ('original_source_already_resolved', 'paid')}
    entries_query = """SELECT kind,amount FROM public.store_balance_entries
        WHERE (source_type='balance_top_up' AND source_id=%s AND kind='top_up_credit')
           OR (source_type='owner_compensation' AND source_id=%s AND kind='owner_compensation_credit')"""
    entries_args = (str(race_topup), 'top_up:' + str(race_topup))
    entries = admin.execute(entries_query, entries_args).fetchall()
    assert entries == [(owner_result if owner_result == 'owner_compensation_credit' else 'top_up_credit', 100)]
    late_funding = FundingService(provider=SakurupiahSandboxAdapter(transport=RaceRelay()))
    assert late_funding.check(late_funding.top_up_by_id(race_topup))['status'] == provider_result
    assert admin.execute(entries_query, entries_args).fetchall() == entries
    assert admin.execute('SELECT balance FROM public.customer_balance_accounts WHERE customer_id=%s',
                         (customer,)).fetchone()[0] == before_race + 100
    audit_intent = admin.execute("""INSERT INTO public.store_balance_funding_intents
        (customer_id,chat_id,canonical_message_id,policy_version,amount,nonce,status,expires_at,created_at,updated_at)
        VALUES (%s,%s,1,1,100,%s,'claimed',(now() AT TIME ZONE 'Asia/Jakarta') + interval '1 hour',now(),now()) RETURNING id""",
        (customer, telegram, uuid4().hex)).fetchone()[0]
    audit_ref, audit_merchant = 'P15-audit-ref-' + suffix, 'P15-audit-' + suffix
    audit_topup = admin.execute("""INSERT INTO public.store_balance_top_ups
        (customer_id,store_balance_funding_intent_id,chat_id,canonical_message_id,locale,provider,merchant_ref,
         idempotency_key,currency,amount,status,provider_reference,provider_created_at,provider_total_payment,
         provider_fee,provider_payment_method,sakurupiah_channel,expires_at,provider_expires_at,created_at,updated_at)
        VALUES (%s,%s,%s,1,'en','sakurupiah',%s,%s,'IDR',100,'pending',%s,now(),100,0,'QRIS','QRIS',
                (now() AT TIME ZONE 'Asia/Jakarta') + interval '1 hour',(now() AT TIME ZONE 'Asia/Jakarta') + interval '1 hour',now(),now()) RETURNING id""",
        (customer, audit_intent, telegram, audit_merchant, audit_merchant, audit_ref)).fetchone()[0]
    class AuditRelay:
        def post(self, url, **kwargs):
            row = {'trx_id': audit_ref, 'merchant_ref': audit_merchant,
                   'payment_kode': 'QRIS', 'status': 'berhasil', 'payment_status': 'berhasil',
                   'amount': '100', 'total': '100', 'fee': '0'}
            return 200, json.dumps({'status': '200', 'data': [row]}).encode()

    trigger_name = 'p15_audit_reject_' + suffix
    admin.execute(f"""CREATE FUNCTION public.{trigger_name}() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            IF NEW.action = 'store_balance.top_up.settled' AND NEW.subject_id = '{audit_topup}' THEN
                RAISE EXCEPTION 'synthetic P15 settlement audit failure';
            END IF;
            RETURN NEW;
        END $$""")
    try:
        admin.execute(f"""CREATE TRIGGER {trigger_name} BEFORE INSERT ON public.audit_logs
            FOR EACH ROW EXECUTE FUNCTION public.{trigger_name}()""")
        try:
            from django.db import DatabaseError
            audit_funding = FundingService(provider=SakurupiahSandboxAdapter(transport=AuditRelay()))
            audit_funding.check(audit_funding.top_up_by_id(audit_topup))
        except DatabaseError as error:
            assert 'synthetic P15 settlement audit failure' in str(error)
        else:
            raise AssertionError('Settlement succeeded despite audit failure')
    finally:
        admin.execute(f"DROP TRIGGER IF EXISTS {trigger_name} ON public.audit_logs")
        admin.execute(f"DROP FUNCTION public.{trigger_name}()")
    assert admin.execute("SELECT status,paid_at,settled_at FROM public.store_balance_top_ups WHERE id=%s",
                         (audit_topup,)).fetchone() == ('pending', None, None)
    assert admin.execute("""SELECT count(*) FROM public.store_balance_entries
        WHERE source_type='balance_top_up' AND source_id=%s""", (str(audit_topup),)).fetchone()[0] == 0
    assert admin.execute("""SELECT count(*) FROM public.external_money_receipts
        WHERE target_type='balance_top_up' AND target_id=%s""", (str(audit_topup),)).fetchone()[0] == 0
    assert admin.execute("""SELECT count(*) FROM public.telegram_outbound_effects
        WHERE source_type='App\\Models\\StoreBalanceTopUp' AND source_id=%s""", (str(audit_topup),)).fetchone()[0] == 0
    unknown_intent = admin.execute("""INSERT INTO public.store_balance_funding_intents
        (customer_id,chat_id,canonical_message_id,policy_version,amount,nonce,status,expires_at,created_at,updated_at)
        VALUES (%s,%s,1,1,100,%s,'claimed',(now() AT TIME ZONE 'Asia/Jakarta') + interval '1 hour',now(),now()) RETURNING id""",
        (customer, telegram, uuid4().hex)).fetchone()[0]
    unknown_ref, unknown_merchant = 'P15-unknown-ref-' + suffix, 'P15-unknown-' + suffix
    unknown_topup = admin.execute("""INSERT INTO public.store_balance_top_ups
        (customer_id,store_balance_funding_intent_id,chat_id,canonical_message_id,locale,provider,merchant_ref,
         idempotency_key,currency,amount,status,provider_create_state,provider_create_attempted_at,
         sakurupiah_channel,expires_at,created_at,updated_at)
        VALUES (%s,%s,%s,1,'en','sakurupiah',%s,%s,'IDR',100,'pending','outcome_unknown',
                now() - interval '1 minute','QRIS',(now() AT TIME ZONE 'Asia/Jakarta') + interval '1 hour',now(),now()) RETURNING id""",
        (customer, unknown_intent, telegram, unknown_merchant, unknown_merchant)).fetchone()[0]
    class UnknownRelay:
        def post(self, url, **kwargs):
            row = {'trx_id': unknown_ref, 'merchant_ref': unknown_merchant,
                   'payment_kode': 'QRIS', 'status': 'berhasil', 'payment_status': 'berhasil',
                   'amount': '100', 'total': '100', 'fee': '0'}
            return 200, json.dumps({'status': '200', 'data': [row]}).encode()

    unknown_funding = FundingService(provider=SakurupiahSandboxAdapter(transport=UnknownRelay()))
    unknown_funding.check(unknown_funding.top_up_by_id(unknown_topup))
    assert admin.execute("""SELECT status,provider_create_state,provider_create_last_error
        FROM public.store_balance_top_ups WHERE id=%s""", (unknown_topup,)).fetchone() == (
            'invalid_provider_result', 'reconciliation_review', 'quote_conflict')
    assert admin.execute("""SELECT count(*) FROM public.store_balance_entries
        WHERE source_type='balance_top_up' AND source_id=%s""", (str(unknown_topup),)).fetchone()[0] == 0
    from lhie_candidate.adjustments.receipt import VerifiedAdjustmentReceipt
    verified = VerifiedAdjustmentReceipt(provider='sakurupiah', account_namespace='default',
        reference='P15-receipt-' + suffix, beneficiary='customer:' + str(customer),
        amount_units='30', customer_id=customer, ownership_verified=True)
    funded = service.request(owner, telegram, 30, 'synthetic verified receipt', category='funded_transfer', receipt=verified)
    funded_args = (owner, str(funded['id']), funded['fingerprint'], funded['version'], password)
    funded_entry = service.confirm(*funded_args, receipt=verified)
    assert service.confirm(*funded_args, receipt=verified)['id'] == funded_entry['id']
    collision = service.request(owner, telegram, 30, 'duplicate synthetic receipt', category='funded_transfer', receipt=verified)
    try:
        service.confirm(owner, str(collision['id']), collision['fingerprint'], collision['version'], password, receipt=verified)
    except AdjustmentRejected as error:
        assert error.code == 'receipt_unavailable'
    else:
        raise AssertionError('Reused receipt credited twice')
    assert admin.execute("""SELECT count(*) FROM public.external_money_receipt_reservations
        WHERE legacy_type='balance_adjustment' AND legacy_id=%s AND reason='registry_conflict' AND resolved_at IS NULL""",
        (str(collision['id']),)).fetchone()[0] == 1
    from lhie_candidate.adjustments.receipt import VerifiedBscAdjustmentReceipt
    tx_hash = '0x' + uuid4().hex + uuid4().hex
    bsc_receipts = [
        VerifiedBscAdjustmentReceipt(chain_id=56, tx_hash=tx_hash, log_index=index,
            beneficiary='customer:' + str(customer), amount_units='200',
            customer_id=customer, ownership_verified=True)
        for index in (0, 1)
    ]
    bsc_first = service.request(owner, telegram, 200, 'synthetic BSC receipt',
                                category='funded_transfer', receipt=bsc_receipts[0])
    bsc_entry = service.confirm(owner, str(bsc_first['id']), bsc_first['fingerprint'],
                                bsc_first['version'], password, receipt=bsc_receipts[0])
    assert bsc_entry['amount'] == 200 and bsc_entry['kind'] == 'admin_adjustment_credit'
    bsc_second = service.request(owner, telegram, 200, 'synthetic BSC parent conflict',
                                 category='funded_transfer', receipt=bsc_receipts[1])
    try:
        service.confirm(owner, str(bsc_second['id']), bsc_second['fingerprint'],
                        bsc_second['version'], password, receipt=bsc_receipts[1])
    except AdjustmentRejected as error:
        assert error.code == 'receipt_unavailable'
    else:
        raise AssertionError('BSC transaction credited to two adjustments')
    assert admin.execute("""SELECT status FROM public.external_money_receipt_parent_claims
        WHERE chain_id=56 AND tx_hash=%s""", (tx_hash,)).fetchone() == ('review',)
    assert admin.execute("""SELECT count(*) FROM public.external_money_receipt_reservations
        WHERE legacy_type='balance_adjustment' AND legacy_id=%s
          AND reason IN ('registry_conflict','registry_review') AND resolved_at IS NULL""",
        (str(bsc_second['id']),)).fetchone()[0] == 1
    assert admin.execute("""SELECT count(*), COALESCE(sum(amount),0) FROM public.store_balance_entries
        WHERE source_type='balance_adjustment' AND source_id IN (%s,%s)
          AND kind='admin_adjustment_credit'""",
        (str(bsc_first['id']), str(bsc_second['id']))).fetchone() == (1, 200)
    return {'owner_confirm_replay': True, 'non_owner_and_wrong_password_rejected': True,
            'revoked_session_and_password_lockout': True,
            'full_linked_reversal': True, 'reversal_spend_race': True,
            'refund_cap_and_recovery': True, 'compensation_settlement_race': True,
            'audit_failure_rollback': True, 'receipt_conflict_and_bsc_parent': True}
