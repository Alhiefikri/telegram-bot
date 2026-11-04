"""Source-bound historical Pakasir contracts; no provider requests or activation."""
import binascii
from contextlib import nullcontext
from datetime import datetime, timedelta
import hashlib
import json
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

from lhie_candidate.checkout.projection import paid_order_status
from lhie_candidate.payments.lifecycle import LifecycleResult
from lhie_candidate.payments.pakasir import (
    PakasirAdapter, PakasirCallback, PakasirCancellationAttempts, PakasirIdentity,
    PakasirLifecycle, PakasirUnavailable, parse_callback, parse_transaction, valid_checkout_url,
)
from lhie_candidate.payments.pakasir_qris import (
    hosted_payment_url, validate_hosted_payment_url, validate_payment_number,
    validate_qris_payload,
)


AT = datetime(2026, 11, 4, 12)


def payload(**overrides):
    value = {'project': 'retained-project', 'order_id': 'OLD-PAYMENT',
             'amount': 50000, 'status': 'completed', 'payment_method': 'qris'}
    value.update(overrides)
    return value


def observed(**overrides):
    return parse_transaction({'transaction': payload(**overrides)})


def qr(total=50660, currency='360', extra=''):
    text = f'0002015303{currency}54{len(str(total)):02d}{total}{extra}6304'
    return text + f'{binascii.crc_hqx(text.encode(), 0xffff):04X}'


def rows():
    order = {'id': 'order-id', 'total_amount': 50000, 'currency': 'IDR',
             'expires_at': AT + timedelta(minutes=10), 'order_status': 'pending_payment',
             'payment_status': 'pending'}
    payment = {'id': 'payment-id', 'order_id': order['id'], 'provider': 'pakasir',
        'method': 'QRIS', 'currency': 'IDR', 'amount': 50000, 'merchant_ref': 'OLD-PAYMENT',
        'provider_reference': None, 'status': 'pending', 'expired_at': order['expires_at'],
        'provider_expires_at': None, 'provider_fee': None, 'provider_total_payment': None,
        'provider_payment_method': 'qris', 'provider_create_state': None,
        'provider_payment_number': None, 'provider_payment_number_hash': None,
        'provider_presentation': None}
    return order, payment, [payment]


def lifecycle():
    value = PakasirLifecycle(Mock(project='retained-project'), Mock(), clock=lambda: AT)
    value.db = Mock()
    value.db.one.return_value = None
    value.orders = Mock()
    value._outside = Mock()
    return value


class PakasirParserTests(unittest.TestCase):
    def test_historical_detail_wrappers_and_exact_integer_amounts(self):
        for wrapper in (lambda p: p, lambda p: {'transaction': p},
                        lambda p: {'data': p}, lambda p: {'data': {'transaction': p}}):
            result = parse_transaction(wrapper(payload(amount='50000', status='COMPLETED')))
            self.assertEqual(result.amount, 50000)
            self.assertTrue(result.completed)
        for amount in (True, 50000.0, '50000.0', '5e4', '-1', 0, 2**63):
            with self.subTest(amount=amount), self.assertRaises(ValueError):
                observed(amount=amount)

    def test_callback_is_unsigned_hint_and_requires_matching_completed_shape(self):
        callback = parse_callback(json.dumps(payload()).encode(), project='retained-project')
        self.assertEqual(callback.merchant_ref, 'OLD-PAYMENT')
        self.assertEqual(callback.provider_status, 'completed')
        for overrides in ({'project': 'other'}, {'status': 'paid'}, {'status': 'pending'},
                          {'amount': 50000.1}, {'payment_method': []}, {'order_id': ''}):
            with self.subTest(overrides=overrides), self.assertRaises(ValueError):
                parse_callback(json.dumps(payload(**overrides)).encode(), project='retained-project')
        for raw in (b'{"order_id":"a","order_id":"b"}', b'[]', b'x' * 65537):
            with self.assertRaises(ValueError):
                parse_callback(raw, project='retained-project')

    def test_callback_evidence_drops_secrets_and_payment_number(self):
        secret = payload(api_key='never-persist', payment_number='qr-secret',
                         phone='private', arbitrary={'secret': 'private'})
        result = parse_callback(json.dumps(secret).encode(), project='retained-project')
        self.assertEqual(result.evidence, payload())
        clean = parse_callback(json.dumps(payload()).encode(), project='retained-project')
        self.assertEqual(result.digest, clean.digest)
        self.assertNotIn('never-persist', repr(result))

    def test_identity_method_and_reference_must_match(self):
        identity = PakasirIdentity('OLD-PAYMENT', 50000, 'qris')
        for changes in ({'project': 'other'}, {'amount': 49999}, {'order_id': 'OTHER'},
                        {'payment_method': 'bank'}):
            self.assertFalse(observed(**changes).matches(identity, 'retained-project'))
        self.assertTrue(observed().matches(identity, 'retained-project'))
        self.assertTrue(observed(payment_method=None).matches(
            PakasirIdentity('OLD-PAYMENT', 50000), 'retained-project'))


class PakasirAdapterTests(unittest.TestCase):
    def setUp(self):
        self.transport = Mock()
        self.adapter = PakasirAdapter(transport=self.transport, project='retained-project')
        self.identity = PakasirIdentity('OLD-PAYMENT', 50000, 'qris')
        self.transport.request.return_value = (200, json.dumps({'transaction': payload()}).encode())

    def test_only_injected_allowlisted_historical_detail_and_cancel_endpoints(self):
        self.assertTrue(self.adapter.detail(self.identity).completed)
        args, kwargs = self.transport.request.call_args
        self.assertEqual(args, ('GET', 'https://app.pakasir.com/api/transactiondetail'))
        self.assertEqual(kwargs['fields'], {'project': 'retained-project',
            'order_id': 'OLD-PAYMENT', 'amount': 50000})
        self.assertEqual(kwargs['timeout'], 10)
        self.assertEqual(kwargs['max_response_bytes'], 65536)
        self.assertFalse(hasattr(self.adapter, 'create'))
        self.transport.request.return_value = (200, b'{"status":"cancelled","api_key":"secret"}')
        self.assertEqual(self.adapter.cancel(self.identity), ('definitive', {'status': 'cancelled'}))
        self.assertEqual(self.transport.request.call_args.args,
                         ('POST', 'https://app.pakasir.com/api/transactioncancel'))

    def test_callback_cannot_authenticate_itself(self):
        callback = PakasirCallback(observed())
        self.transport.request.return_value = (200, json.dumps({'transaction': payload(status='pending')}).encode())
        self.assertIsNone(self.adapter.authentic_callback(callback, self.identity))
        self.transport.request.return_value = (200, json.dumps({'transaction': payload()}).encode())
        self.assertTrue(self.adapter.authentic_callback(callback, self.identity).completed)
        self.assertEqual(self.transport.request.call_count, 2)
        self.assertIsNone(self.adapter.authentic_callback(PakasirCallback(observed(amount=1)), self.identity))
        self.assertEqual(self.transport.request.call_count, 2)
        missing_method = PakasirCallback(observed(payment_method=None))
        self.assertTrue(self.adapter.authentic_callback(missing_method, self.identity).completed)
        self.assertIsNone(self.adapter.authentic_callback(
            PakasirCallback(observed(payment_method='bank')), self.identity))

    def test_status_transport_and_parse_failures_are_not_unpaid(self):
        for response in ((500, b'{}'), (429, b'{}'), (200, b'bad'), (200, b'[]'),
                         (200, b'x' * 65537), (200, b'{"transaction":null}'),
                         (200, json.dumps({'transaction': payload(order_id='OTHER')}).encode())):
            self.transport.request.return_value = response
            self.assertIsNone(self.adapter.detail(self.identity))
        self.transport.request.side_effect = OSError('api_key=do-not-leak')
        self.assertIsNone(self.adapter.detail(self.identity))
        with self.assertRaisesRegex(PakasirUnavailable, '^Pakasir observation unavailable$'):
            self.adapter.cancel(self.identity)

    def test_unknown_and_cancel_aliases_are_not_paid_status(self):
        for status in ('paid', 'success', 'cancelled', 'unknown', 'expired'):
            self.transport.request.return_value = (200, json.dumps({'transaction': payload(status=status)}).encode())
            self.assertFalse(self.adapter.detail(self.identity).completed)
        for raw in (b'null', b'', b'{}', b'{"status":"pending"}'):
            self.transport.request.return_value = (200, raw)
            self.assertEqual(self.adapter.cancel(self.identity)[0], 'ambiguous')

    def test_provider_io_rejects_open_transactions(self):
        with patch('lhie_candidate.payments.pakasir.settings', SimpleNamespace(configured=True)), \
             patch('lhie_candidate.payments.pakasir.connections') as connections:
            connections.all.return_value = [SimpleNamespace(in_atomic_block=True)]
            with self.assertRaises(RuntimeError):
                self.adapter.detail(self.identity)
        self.transport.request.assert_not_called()


class PakasirPresentationTests(unittest.TestCase):
    def test_emv_crc_currency_amount_duplicate_tag_and_hash(self):
        value = qr()
        self.assertEqual(validate_qris_payload(value, 50660), value)
        self.assertEqual(validate_payment_number(value, hashlib.sha256(value.encode()).hexdigest(), 50660), value)
        for bad, total in ((value[:-1] + ('1' if value[-1] != '1' else '2'), 50660),
                           (qr(currency='840'), 50660), (qr(total=50000), 50660),
                           (qr(extra='5303360'), 50660), (value, 50660.0)):
            with self.assertRaises(ValueError):
                validate_qris_payload(bad, total)
        with self.assertRaises(ValueError):
            validate_payment_number(value, 'wrong', 50660)

    def test_hosted_link_is_existing_merchant_identity_not_arbitrary_image(self):
        kwargs = {'project': 'retained-project', 'merchant_ref': 'OLD-PAYMENT', 'amount': 50000}
        value = hosted_payment_url(**kwargs)
        self.assertTrue(validate_hosted_payment_url(value, **kwargs))
        self.assertTrue(valid_checkout_url(value))
        self.assertFalse(validate_hosted_payment_url(value.replace('50000?', '50001?'), **kwargs))
        for bad in (value.replace('https:', 'http:'), value.replace('app.pakasir.com', 'evil.test'),
                    value.replace('app.pakasir.com', 'app.pakasir.com.evil.test'),
                    value.replace('app.pakasir.com', 'user@app.pakasir.com'),
                    value + '&redirect=https://evil.test',
                    value + '#fragment', value + '&order_id=OTHER', value.replace('/pay/', '/pay%2f')):
            self.assertFalse(validate_hosted_payment_url(bad, **kwargs), bad)
            self.assertFalse(valid_checkout_url(bad), bad)

    def test_native_historical_snapshot_not_repriced_or_replaced(self):
        service = lifecycle()
        order, payment, _ = rows()
        value = qr()
        payment.update(provider_payment_number='encrypted', provider_payment_number_hash=hashlib.sha256(value.encode()).hexdigest(),
                       provider_fee=660, provider_total_payment=50660, provider_create_state='created')
        service.encrypter.decrypt_string.return_value = value
        result = service._result(order, payment)
        self.assertEqual(result.state, 'created')
        self.assertEqual(result.presentation['mode'], 'qris')
        self.assertEqual(result.total, 50660)
        payment['provider_payment_number_hash'] = 'bad'
        self.assertEqual(service._result(order, payment).state, 'provider_invalid_result')
        service.db.execute.assert_not_called()

    def test_hosted_historical_link_and_terminal_states(self):
        service = lifecycle()
        order, payment, _ = rows()
        self.assertEqual(service._result(order, payment).presentation['mode'], 'hosted')
        for state in ('paid', 'refunded', 'expired', 'failed'):
            payment['status'] = state
            self.assertEqual(service._result(order, payment).state, state)
        payment['status'] = 'pending'
        payment['expired_at'] = AT - timedelta(seconds=1)
        self.assertEqual(service._result(order, payment).state, 'expired')
        self.assertEqual(paid_order_status({'provider': 'pakasir'}), 'completed')


class PakasirLifecycleTests(unittest.TestCase):
    def test_unavailable_and_unknown_status_do_not_mutate_payment(self):
        for outcome in (None, observed(status='unrecognized')):
            service = lifecycle()
            state = rows()
            service._locked = Mock(return_value=state)
            service.adapter.detail.return_value = outcome
            service._event = Mock(return_value={'id': 1})
            with patch('lhie_candidate.payments.pakasir.transaction.atomic', return_value=nullcontext()):
                result = service.refresh('payment-id')
            self.assertIn(result.state, ('created', 'recovery_unconfirmed'))
            service.orders.paid_locked.assert_not_called()
            service.orders._release_locked.assert_not_called()
            service.db.execute.assert_not_called()

    def test_changed_reference_or_financial_snapshot_discards_provider_observation(self):
        for key, value in (('provider_reference', 'new-ref'), ('amount', 50001),
                           ('expired_at', AT + timedelta(minutes=30))):
            service = lifecycle()
            first = rows()
            second = rows()
            second[1][key] = value
            service._locked = Mock(side_effect=[first, second])
            service.adapter.detail.return_value = observed()
            with patch('lhie_candidate.payments.pakasir.transaction.atomic', return_value=nullcontext()):
                self.assertEqual(service.refresh('payment-id').state, 'recovery_unconfirmed')
            service.orders.paid_locked.assert_not_called()
            service.db.execute.assert_not_called()

    def test_fee_total_and_method_mismatches_do_not_settle(self):
        for changes in ({'fee': 999}, {'total_payment': 59999}, {'payment_method': 'bank'}):
            service = lifecycle()
            state = rows()
            state[1].update(provider_fee=660, provider_total_payment=50660)
            service._locked = Mock(return_value=state)
            service.adapter.detail.return_value = observed(**changes)
            with patch('lhie_candidate.payments.pakasir.transaction.atomic', return_value=nullcontext()):
                self.assertEqual(service.refresh('payment-id').state, 'recovery_unconfirmed')
            service.orders.paid_locked.assert_not_called()

    def test_receipt_claim_is_shared_and_held_money_stays_review(self):
        service = lifecycle()
        order, payment, payments = rows()
        with patch('lhie_candidate.payments.pakasir.claim_provider_reference', return_value=SimpleNamespace(held=True)) as claim:
            result = service._settle(order, payment, payments, observed())
        self.assertEqual(result.state, 'review')
        self.assertEqual(claim.call_args.kwargs['reference'], 'OLD-PAYMENT')
        self.assertEqual(claim.call_args.kwargs['provider'], 'pakasir')
        service.orders.paid_locked.assert_not_called()
        service.orders.review_locked.assert_called_once_with(order, payment, 'receipt_conflict')

    def test_late_paid_release_precedes_canonical_paid_and_stays_reviewable(self):
        service = lifecycle()
        order, payment, payments = rows()
        service.orders.paid_locked.return_value = SimpleNamespace(review_id=1)
        events = []
        service.orders._release_locked.side_effect = lambda *a, **kw: events.append('release')
        service.orders.paid_locked.side_effect = lambda *a: (events.append('paid') or SimpleNamespace(review_id=1))
        with patch('lhie_candidate.payments.pakasir.claim_provider_reference', return_value=SimpleNamespace(held=False)):
            result = service._settle(order, payment, payments,
                                     observed(expired_at=(AT - timedelta(seconds=1)).isoformat()))
        self.assertEqual(result.state, 'review')
        self.assertEqual(events, ['release', 'paid'])
        self.assertEqual(payment['expired_at'], AT + timedelta(minutes=10))
        service.db.execute.assert_not_called()

    def test_already_released_paid_goes_directly_to_shared_lifecycle(self):
        service = lifecycle()
        order, payment, payments = rows()
        order['order_status'] = 'cancelled'
        payment['status'] = 'failed'
        service.orders.paid_locked.return_value = SimpleNamespace(review_id=1)
        with patch('lhie_candidate.payments.pakasir.claim_provider_reference', return_value=SimpleNamespace(held=False)):
            self.assertEqual(service._settle(order, payment, payments, observed()).state, 'review')
        service.orders._release_locked.assert_not_called()
        service.orders.paid_locked.assert_called_once_with(order, payment, payments)

    def test_callback_is_only_marked_processed_after_actual_paid_transition(self):
        service = lifecycle()
        state = rows()
        service._locked = Mock(return_value=state)
        service.db.one.return_value = {'id': 1, 'raw_payload': payload()}
        service.adapter.authentic_callback.return_value = observed()
        service._event = Mock(return_value={'id': 2})
        service._settle = Mock(return_value=LifecycleResult('review'))
        with patch('lhie_candidate.payments.pakasir.transaction.atomic', return_value=nullcontext()):
            self.assertEqual(service.consume_callbacks('payment-id').state, 'review')
        service.db.execute.assert_not_called()
        service.adapter.authentic_callback.assert_called_once()


class CancellationDatabase:
    def __init__(self, attempt):
        self.attempt = attempt
        self.updates = []

    def one(self, sql, params):
        if 'pg_try_advisory_lock' in sql:
            return {'acquired': True}
        if 'pg_advisory_unlock' in sql:
            return {'released': True}
        return dict(self.attempt)

    def execute(self, sql, params):
        self.updates.append((sql, params))


class PakasirCancellationTests(unittest.TestCase):
    def service(self, **overrides):
        life = lifecycle()
        attempt = {'id': 7, 'payment_id': 'payment-id', 'order_id': 'order-id',
                   'provider': 'pakasir', 'state': 'pending', 'attempt_count': 0,
                   'started_at': None}
        attempt.update(overrides)
        life.db = CancellationDatabase(attempt)
        life._locked = Mock(return_value=rows())
        return PakasirCancellationAttempts(life), life

    def process(self, service):
        with patch('lhie_candidate.payments.pakasir.transaction.atomic', return_value=nullcontext()):
            return service.process(7)

    def test_final_replays_and_live_processing_lease_do_not_call_provider(self):
        for state in ('succeeded', 'already_paid', 'unsupported', 'terminal_failure'):
            service, life = self.service(state=state)
            self.assertEqual(self.process(service), state)
            life.adapter.detail.assert_not_called()
        service, life = self.service(state='processing', started_at=AT - timedelta(seconds=119), attempt_count=1)
        self.assertEqual(self.process(service), 'busy')
        life.adapter.detail.assert_not_called()

    def test_backoff_and_exhaustion_boundaries(self):
        for count, delay in ((1, 30), (2, 120)):
            service, life = self.service(state='retryable_failure', attempt_count=count,
                                         started_at=AT - timedelta(seconds=delay - 1))
            self.assertEqual(self.process(service), 'busy')
            life.adapter.detail.assert_not_called()
        service, life = self.service(state='retryable_failure', attempt_count=3,
                                     started_at=AT - timedelta(seconds=121))
        self.assertEqual(self.process(service), 'terminal_failure')
        life.adapter.detail.assert_not_called()

    def test_unsupported_has_no_provider_io(self):
        service, life = self.service(provider='klikqris')
        self.assertEqual(self.process(service), 'unsupported')
        life.adapter.detail.assert_not_called()
        life.adapter.cancel.assert_not_called()

    def test_failure_or_unknown_status_does_not_cancel_as_if_unpaid(self):
        for result in (None, observed(status='unknown')):
            service, life = self.service()
            life.adapter.detail.return_value = result
            self.assertEqual(self.process(service), 'retryable_failure')
            life.adapter.cancel.assert_not_called()

    def test_definitive_cancel_requires_authoritative_recheck(self):
        for final, expected in ((observed(status='cancelled'), 'succeeded'),
                                (None, 'retryable_failure'),
                                (observed(status='pending'), 'retryable_failure')):
            service, life = self.service()
            life.adapter.detail.side_effect = [observed(status='pending'), final]
            life.adapter.cancel.return_value = ('definitive', {'status': 'cancelled'})
            self.assertEqual(self.process(service), expected)
            self.assertEqual(life.adapter.cancel.call_count, 1)

    def test_paid_race_delegates_refresh_and_held_receipt_is_not_finished_paid(self):
        for paid, expected in ((True, 'already_paid'), (False, 'retryable_failure')):
            service, life = self.service()
            life.adapter.detail.side_effect = [observed(status='pending'), observed()]
            life.adapter.cancel.return_value = ('definitive', {'status': 'cancelled'})
            state = rows()
            life._locked.side_effect = [rows(), state]
            def refresh(_):
                if paid:
                    state[1]['status'] = 'paid'
                return LifecycleResult('review')
            life.refresh = Mock(side_effect=refresh)
            self.assertEqual(self.process(service), expected)
            life.refresh.assert_called_once_with('payment-id')

    def test_abandoned_processing_rechecks_before_new_send_and_terminal_failure_sanitizes(self):
        service, life = self.service(state='processing', started_at=AT - timedelta(seconds=121), attempt_count=2)
        life.adapter.detail.return_value = observed(status='pending')
        life.adapter.cancel.side_effect = PakasirUnavailable('provider-internal-secret')
        self.assertEqual(self.process(service), 'terminal_failure')
        life.adapter.detail.assert_called_once()
        self.assertNotIn('provider-internal-secret', repr(life.db.updates))
        self.assertIn('cancellation_provider_error', repr(life.db.updates))

    def test_batch_limit_is_bounded(self):
        service, _ = self.service()
        for limit in (0, 101, True):
            with self.assertRaises(ValueError):
                service.process_due(limit=limit)
