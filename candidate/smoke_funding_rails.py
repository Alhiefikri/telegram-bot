"""Callable disposable P15 funding scenarios; fixture provisioning is caller-owned.

Invoke only after P01 admission on disposable synthetic data. The caller owns
provider settings, confirmed intents, the compensation fixture, and grants.
"""
from types import SimpleNamespace

from lhie_candidate.funding.service import FundingError
from lhie_candidate.payments.klikqris import KlikQrisObservation, KlikQrisTransaction


def _credits(db, customer_id):
    row = db.one('''SELECT COUNT(*) AS count,COALESCE(SUM(e.amount),0) AS amount
        FROM public.store_balance_entries e JOIN public.customer_balance_accounts a
        ON a.id=e.customer_balance_account_id WHERE a.customer_id=%s AND e.kind='top_up_credit' ''',
        (customer_id,))
    return row['count'], int(row['amount'])


def _confirmed_intent(funding, customer, root, intent_id):
    if intent_id is not None:
        return intent_id
    opened = funding.open_intent(customer, root, raw_amount='10000')
    assert opened.state in ('opened', 'replayed'), opened.state
    claimed = funding.claim(customer, root, nonce=opened.intent['nonce'], action='confirm')
    assert claimed.state in ('confirmed', 'already_confirmed'), claimed.state
    return claimed.intent['id']


def manual_scenario(manual, *, customer, root, intent_id, operator_customer_id,
                    operator_chat_id, foreign_customer_id):
    """Setting must select manual and the confirmed intent must be fresh."""
    intent_id = _confirmed_intent(manual.funding, customer, root, intent_id)
    before = _credits(manual.db, customer['id'])
    result = manual.funding.create_top_up(customer, root, intent_id)
    assert result.state == 'awaiting_confirmation'
    topup = result.topup
    assert manual.funding.create_top_up(customer, root, intent_id).topup['id'] == topup['id']
    assert manual.submit_proof(customer_id=customer['id'], topup_id=topup['id'],
                               photo_file_id='synthetic-file-id') == 'accepted'
    assert manual.submit_proof(customer_id=customer['id'], topup_id=topup['id'],
                               photo_file_id='synthetic-file-id') == 'duplicate'
    try:
        manual.decide(topup_id=topup['id'], operator_customer_id=foreign_customer_id,
                      operator_chat_id=operator_chat_id, approve=True)
    except FundingError as error:
        assert error.code == 'operator_forbidden'
    else:
        raise AssertionError('foreign operator approved manual top-up')
    assert _credits(manual.db, customer['id']) == before
    assert manual.decide(topup_id=topup['id'], operator_customer_id=operator_customer_id,
                         operator_chat_id=operator_chat_id, approve=True) == 'confirmed'
    assert manual.decide(topup_id=topup['id'], operator_customer_id=operator_customer_id,
                         operator_chat_id=operator_chat_id, approve=True) == 'already_confirmed'
    after = _credits(manual.db, customer['id'])
    assert after == (before[0] + 1, before[1] + topup['amount'])
    return {'topup_id': topup['id'], 'result': 'confirmed', 'foreign': 'forbidden'}


class SyntheticKlikAdapter:
    def __init__(self, *, clock, reference, signature='synthetic-signature'):
        self.clock = clock
        self.reference = reference
        self.signature = signature
        self.status_signature = signature
        self.config = SimpleNamespace(environment='sandbox', allowed_hosts=())

    def readiness(self):
        return ()

    def create(self, payment):
        from datetime import timedelta
        tx = KlikQrisTransaction(payment['merchant_ref'], payment['amount'], payment['amount'] + 200,
            'PENDING', 'sandbox', self.reference, self.clock() + timedelta(minutes=5), self.signature,
            'https://klikqris.com/storage/sandbox/sandbox_sample.png')
        tx.assert_matches(payment, 'sandbox')
        return KlikQrisObservation('observed', tx)

    def status(self, payment):
        tx = KlikQrisTransaction(payment['merchant_ref'], payment['amount'], payment['provider_total_payment'],
            'SUCCESS', 'sandbox', self.reference, payment['provider_expires_at'], self.status_signature)
        tx.assert_matches(payment, 'sandbox')
        return KlikQrisObservation('observed', tx)


def klik_scenario(klik, *, customer, root, intent_id):
    """Setting selects KlikQRIS; adapter is SyntheticKlikAdapter with unique reference."""
    intent_id = _confirmed_intent(klik.funding, customer, root, intent_id)
    before = _credits(klik.db, customer['id'])
    result = klik.funding.create_top_up(customer, root, intent_id)
    assert result.state == 'payable', result.state
    topup = result.topup
    klik.adapter.status_signature = 'mismatched-signature'
    assert klik.check(topup)['status'] == 'payable'
    assert _credits(klik.db, customer['id']) == before
    klik.adapter.status_signature = klik.adapter.signature
    paid = klik.check(topup)
    assert paid['status'] == 'paid'
    assert klik.check(paid)['status'] == 'paid'
    after = _credits(klik.db, customer['id'])
    assert after == (before[0] + 1, before[1] + topup['amount'])
    return {'topup_id': topup['id'], 'signature_mismatch': 'held', 'result': 'paid'}


def klik_late_scenario(klik, *, expired_topup, compensated_topup):
    """Caller supplies distinct paid-evidence fixtures, one expired, one compensated.

    The fake adapter must return each fixture's matching authoritative identity.
    Compensation must already have its unresolved-money reservation.
    """
    assert expired_topup['status'] == 'expired'
    assert compensated_topup['status'] == 'compensated'
    before = _credits(klik.db, expired_topup['customer_id'])
    klik.adapter.reference = expired_topup['provider_reference']
    late = klik.check(expired_topup)
    assert late['status'] == 'paid' and late['provider_create_state'] == 'reconciled_SUCCESS'
    assert _credits(klik.db, expired_topup['customer_id']) == (before[0] + 1, before[1] + expired_topup['amount'])
    before_compensation = _credits(klik.db, compensated_topup['customer_id'])
    klik.adapter.reference = compensated_topup['provider_reference']
    compensated = klik.check(compensated_topup)
    assert compensated['status'] == 'compensated'
    assert _credits(klik.db, compensated_topup['customer_id']) == before_compensation
    reservation = klik.db.one('''SELECT resolved_at FROM public.external_money_receipt_reservations
        WHERE legacy_type='store_balance_top_up' AND legacy_id=%s
        AND reason='compensated_unresolved_money' ''', (str(compensated_topup['id']),))
    assert reservation and reservation['resolved_at'] is not None
    return {'late': 'paid_once', 'compensated': 'not_recredited'}


def run():
    from datetime import timedelta
    import os
    import sys
    import bcrypt
    import psycopg
    from uuid import uuid4

    if os.environ.get('P719_FUNDING_SMOKE') != 'disposable-local-only':
        raise RuntimeError('Explicit disposable funding opt-in required')
    from lhie_candidate.startup import configure_django
    old_hook = sys.excepthook
    old_limit = getattr(sys, 'tracebacklimit', None)
    configure_django()
    sys.excepthook = old_hook
    if old_limit is None:
        if hasattr(sys, 'tracebacklimit'):
            del sys.tracebacklimit
    else:
        sys.tracebacklimit = old_limit
    from lhie_candidate.auth.sessions import Session
    from lhie_candidate.compat.identity import sha256_digest
    from lhie_candidate.adjustments.service import BalanceAdjustments
    from lhie_candidate.funding.manual import ManualFunding
    from lhie_candidate.funding.klikqris import KlikFunding
    from smoke_funding import customer, root, service, FakeProvider

    admin = psycopg.connect(host='127.0.0.1', hostaddr='127.0.0.1', port=5432,
                            dbname='disposable', user='harness', password='harness',
                            connect_timeout=3, autocommit=True)
    try:
        identity = admin.execute('SELECT current_database(),current_user,host(inet_server_addr()),inet_server_port()').fetchone()
        if identity != ('disposable', 'harness', '127.0.0.1', 5432):
            raise RuntimeError('Disposable funding identity mismatch')
        row = admin.execute('''SELECT id,payment_mode,payment_provider_version,admin_telegram_chat_id
            FROM public.manual_payment_settings WHERE is_active=true ORDER BY id LIMIT 1''').fetchone()
        if row is None:
            raise RuntimeError('Synthetic active payment setting required')
        setting_id, old_mode, old_version, old_admin_chat = row
        buyer, navigation_root = customer(), root()
        telegram = str(buyer['telegram_id'])
        suffix = uuid4().hex
        foreign = admin.execute('''INSERT INTO public.customers(first_name,telegram_id,locale,created_at,updated_at)
            VALUES ('P15 foreign',%s,'en',now(),now()) RETURNING id''',
            (str(100000000000000000 + int(suffix[:12], 16)),)).fetchone()[0]
        password = 'synthetic-p15-funding-password'
        stored = bcrypt.hashpw(password.encode(), bcrypt.gensalt(rounds=4)).decode()
        owner_id = admin.execute('''INSERT INTO public.users(name,email,password,role,created_at,updated_at)
            VALUES ('P15 owner',%s,%s,'owner',now(),now()) RETURNING id''',
            (f'p15-funding-{suffix}@example.invalid', stored)).fetchone()[0]
        funding = service(FakeProvider())
        manual = ManualFunding(funding, admin_user_id=telegram)
        adapter = SyntheticKlikAdapter(clock=funding.clock, reference='p15-klik-' + suffix)
        klik = KlikFunding(funding, adapter, funding.encrypter)
        funding.register_rail('manual', manual, ready=lambda: True)
        funding.register_rail('klikqris', klik, ready=lambda: True)
        try:
            admin.execute('''UPDATE public.manual_payment_settings SET payment_mode='manual',
                payment_provider_version=payment_provider_version+1,admin_telegram_chat_id=%s WHERE id=%s''',
                (telegram, setting_id))
            manual_result = manual_scenario(manual, customer=buyer, root=navigation_root, intent_id=None,
                operator_customer_id=buyer['id'], operator_chat_id=telegram, foreign_customer_id=foreign)
            rejected_intent = _confirmed_intent(funding, buyer, navigation_root, None)
            rejected = funding.create_top_up(buyer, navigation_root, rejected_intent).topup
            before_rejection = _credits(funding.db, buyer['id'])
            assert manual.decide(topup_id=rejected['id'], operator_customer_id=buyer['id'],
                                 operator_chat_id=telegram, approve=False) == 'rejected'
            assert manual.decide(topup_id=rejected['id'], operator_customer_id=buyer['id'],
                                 operator_chat_id=telegram, approve=True) == 'already_rejected'
            assert _credits(funding.db, buyer['id']) == before_rejection
            manual_result['rejected'] = 'not_credited'
            expired_intent = _confirmed_intent(funding, buyer, navigation_root, None)
            expired_manual = funding.create_top_up(buyer, navigation_root, expired_intent).topup
            real_clock = funding.clock
            funding.clock = lambda: expired_manual['expires_at'] + timedelta(seconds=1)
            assert funding.expire_if_due(expired_manual['id'])
            funding.clock = real_clock
            before_expiry = _credits(funding.db, buyer['id'])
            assert manual.decide(topup_id=expired_manual['id'], operator_customer_id=buyer['id'],
                                 operator_chat_id=telegram, approve=True) == 'expired'
            assert _credits(funding.db, buyer['id']) == before_expiry
            manual_result['expired'] = 'not_credited'
            admin.execute("""UPDATE public.manual_payment_settings SET payment_mode='klikqris',
                payment_provider_version=payment_provider_version+1 WHERE id=%s""", (setting_id,))
            klik_result = klik_scenario(klik, customer=buyer, root=navigation_root, intent_id=None)
            import json
            from unittest.mock import patch
            from django.test import RequestFactory
            from lhie_candidate.payments.ingress import receive_klikqris
            from lhie_candidate.workers.payment import PaymentHandler
            from lhie_candidate.workers.wakeup import encode

            adapter.reference = 'p15-callback-' + suffix
            callback_intent = _confirmed_intent(funding, buyer, navigation_root, None)
            callback_topup = funding.create_top_up(buyer, navigation_root, callback_intent).topup
            before_callback = _credits(funding.db, buyer['id'])
            payload = {'order_id': callback_topup['merchant_ref'], 'amount': callback_topup['amount'],
                       'total_amount': callback_topup['provider_total_payment'], 'status': 'SUCCESS',
                       'signature': adapter.signature, 'provider_reference': adapter.reference}
            request_factory = RequestFactory()
            rails = SimpleNamespace(db=funding.db, lifecycles={})
            limits = SimpleNamespace(reserve=lambda entries: None)
            def callback(body):
                request = request_factory.post('/api/webhook/klikqris/sandbox',
                    json.dumps(body), content_type='application/json')
                return receive_klikqris(request, environment='sandbox', payments=rails,
                                        limits=limits, funding_klik=klik)
            assert callback(dict(payload, signature='forged')).status_code == 403
            with patch('lhie_candidate.payments.ingress.wakeup.enqueue', return_value=False):
                assert callback(payload).status_code == 200
                assert callback(payload).status_code == 200
            events = admin.execute("""SELECT id,received_count,processed_at,raw_payload::text
                FROM public.store_balance_top_up_events WHERE store_balance_top_up_id=%s
                  AND provider='klikqris' AND event_type='callback'""", (callback_topup['id'],)).fetchall()
            assert len(events) == 1 and events[0][1] == 2 and events[0][2] is None
            assert adapter.signature not in events[0][3] and _credits(funding.db, buyer['id']) == before_callback
            lifecycle = SimpleNamespace(lifecycles={}, external_lifecycles={},
                recover=lambda identity: None, refresh=lambda identity: None,
                consume_callbacks=lambda identity: None,
                orders=SimpleNamespace(apply_pending_expiry=lambda identity: None))
            worker = PaymentHandler(lifecycle, funding.db, funding_klik=klik)
            assert events[0][0] in worker._funding_events(100)
            worker.sweep(100)
            assert worker.handle(encode('funding.klikqris', events[0][0])) == 'replayed'
            assert events[0][0] not in worker._funding_events(100)
            assert _credits(funding.db, buyer['id']) == (before_callback[0] + 1,
                                                          before_callback[1] + callback_topup['amount'])
            adapter.reference = 'p15-outage-' + suffix
            outage_intent = _confirmed_intent(funding, buyer, navigation_root, None)
            outage_topup = funding.create_top_up(buyer, navigation_root, outage_intent).topup
            admin.execute("""UPDATE public.store_balance_top_ups
                SET provider_create_attempted_at=(now() AT TIME ZONE 'Asia/Jakarta') - interval '1 minute'
                WHERE id=%s""", (outage_topup['id'],))
            calls = []
            real_status = adapter.status
            def unavailable(payment):
                if payment['id'] == outage_topup['id']:
                    calls.append(payment['id'])
                return KlikQrisObservation('unknown', None)
            adapter.status = unavailable
            try:
                worker.sweep(100)
                worker.sweep(100)
            finally:
                adapter.status = real_status
            assert calls == [outage_topup['id']]
            assert funding.top_up_by_id(outage_topup['id'])['status'] == 'payable'
            callback_result = {'forged': 'rejected', 'duplicate': 'deduplicated',
                               'lost_wakeup': 'recovered_once',
                               'provider_outage': 'rate_limited_and_retryable'}
            fixtures = []
            for index in range(2):
                adapter.reference = f'p15-late-{index}-{suffix}'
                intent = _confirmed_intent(funding, buyer, navigation_root, None)
                outcome = funding.create_top_up(buyer, navigation_root, intent)
                assert outcome.state == 'payable'
                real_clock = funding.clock
                funding.clock = lambda: outcome.topup['expires_at'] + timedelta(seconds=1)
                assert funding.expire_if_due(outcome.topup['id'])
                funding.clock = real_clock
                fixtures.append(funding.top_up_by_id(outcome.topup['id']))
            session = Session()
            session.set('admin', {'id': owner_id, 'password_digest': sha256_digest(stored)})
            session.save()
            request = SimpleNamespace(auth_session=session)
            adjustments = BalanceAdjustments(admin_chat_id=telegram)
            preview = adjustments.request(request, telegram, fixtures[1]['amount'], 'Synthetic P15 recovery',
                                          category='top_up_recovery', source_id=str(fixtures[1]['id']))
            adjustments.confirm(request, str(preview['id']), preview['fingerprint'], preview['version'], password)
            fixtures[1] = funding.top_up_by_id(fixtures[1]['id'])
            late_result = klik_late_scenario(klik, expired_topup=fixtures[0], compensated_topup=fixtures[1])
            return {'manual': manual_result, 'klik': klik_result, 'late': late_result,
                    'callback': callback_result}
        finally:
            admin.execute('''UPDATE public.manual_payment_settings SET payment_mode=%s,
                payment_provider_version=%s,admin_telegram_chat_id=%s WHERE id=%s''',
                (old_mode, old_version, old_admin_chat, setting_id))
    finally:
        admin.close()
