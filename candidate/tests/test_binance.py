"""Retained Binance history boundaries, without live requests or activation."""
from contextlib import nullcontext
from copy import deepcopy
from datetime import datetime, timedelta
import hashlib
import hmac
import json
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch
from urllib.parse import parse_qs, urlsplit
from zoneinfo import ZoneInfo

from lhie_candidate.payments.binance import (
    BinanceAdapter, BinanceHistory, BinanceLifecycle, BinanceUnavailable,
    amount_micro, normalize_order_id, parse_transaction,
)


AT = datetime(2026, 11, 4, 12)
STAMP = int(AT.replace(tzinfo=ZoneInfo('Asia/Jakarta')).timestamp()) * 1000


def payload(**overrides):
    row = {'orderId': '445011487581044736', 'transactionId': 'P_TRANSACTION',
           'amount': '5.000001', 'currency': 'USDT', 'transactionTime': STAMP,
           'receiverInfo': {'accountId': '987654321'}}
    row.update(overrides)
    return row


def envelope(rows):
    return 200, json.dumps({'code': '000000', 'success': True, 'data': rows}).encode()


def adapter(transport=None, **kwargs):
    transport = transport or Mock()
    transport.request.return_value = envelope([payload()])
    return BinanceAdapter(transport=transport, api_key='retained-key', api_secret='retained-secret',
                          clock_ms=lambda: STAMP, **kwargs)


def rows():
    order = {'id': 'order-id', 'customer_id': 7, 'total_amount': 50000, 'currency': 'IDR',
             'expires_at': AT + timedelta(minutes=10), 'order_status': 'pending_payment',
             'payment_status': 'pending'}
    payment = {'id': 'payment-id', 'order_id': order['id'], 'provider': 'manual',
        'method': 'BINANCE_PAY_ID', 'currency': 'USDT', 'amount': 5_000_001,
        'merchant_ref': 'PAY-EXISTING', 'provider_reference': None, 'status': 'pending',
        'created_at': AT - timedelta(minutes=1), 'expired_at': order['expires_at'],
        'binance_receiver_type': 'pay_id', 'binance_receiver_value': '987654321',
        'binance_auto_verification_enabled': True, 'binance_verification_mode': 'order_id',
        'binance_order_id': '445011487581044736', 'binance_order_id_attempts': 1,
        'binance_order_id_submission_version': 1, 'binance_order_id_submitted_at': AT,
        'binance_verification_grace_until': order['expires_at'] + timedelta(minutes=3),
        'binance_verification_status': 'checking', 'binance_last_verified_at': None}
    return order, payment, [payment]


def lifecycle():
    value = BinanceLifecycle(adapter(), clock=lambda: AT)
    value.db = Mock()
    value.db.one.return_value = None
    value.orders = Mock()
    value.orders._run.side_effect = lambda operation: operation()
    value.orders.paid_locked.return_value = SimpleNamespace(review_id=None)
    value.orders._open_review.return_value = None
    value._outside = Mock()
    return value


class BinanceContractTests(unittest.TestCase):
    def test_exact_signed_micro_money_rejects_float_exponent_and_overflow(self):
        self.assertEqual(amount_micro('5.000001'), 5_000_001)
        self.assertEqual(amount_micro('-1.5'), -1_500_000)
        self.assertEqual(amount_micro('9223372036854.775807'), 2**63 - 1)
        for amount in (True, 5.0, '5e0', 'NaN', '+5', ' 5', '1.0000001',
                       '9223372036854.775808', [], None):
            with self.subTest(amount=amount), self.assertRaises(ValueError):
                parse_transaction(payload(amount=amount))

    def test_identifiers_are_lookup_aliases_and_receiver_namespaces_remain_distinct(self):
        observed = parse_transaction(payload())
        self.assertEqual(observed.matches_hint('445011487581044736'), 'order_id')
        self.assertEqual(observed.matches_hint('P_TRANSACTION'), 'transaction_id_compatibility')
        self.assertIsNone(observed.matches_hint('OTHER_TRANSACTION'))
        self.assertTrue(observed.incoming('pay_id', '987654321'))
        self.assertFalse(observed.incoming('uid', '987654321'))
        uid = parse_transaction(payload(receiverInfo={'binanceId': '987654321'}))
        self.assertTrue(uid.incoming('pay_id', '987654321'))
        self.assertTrue(uid.incoming('uid', '987654321'))
        explicit_other = parse_transaction(payload(receiverInfo={'accountId': 'other', 'binanceId': '987654321'}))
        self.assertFalse(explicit_other.incoming('pay_id', '987654321'))
        for amount in ('0', '-5.000001'):
            self.assertFalse(parse_transaction(payload(amount=amount)).incoming('pay_id', '987654321'))

    def test_success_status_fallback_and_malformed_rows_fail_closed(self):
        self.assertTrue(parse_transaction(payload()).successful)
        self.assertTrue(parse_transaction(payload(transactionStatus='completed')).successful)
        for status in ('PENDING', 'FAILED', 'CANCELLED', 'REFUNDED', 'UNKNOWN'):
            self.assertFalse(parse_transaction(payload(status=status)).successful)
        for override in ({'transactionId': False}, {'transactionTime': 1.5}, {'transactionTime': '1e3'},
                         {'transactionTime': True}, {'transactionId': []}, {'currency': []},
                         {'receiverInfo': []}, {'status': False}, {'status': 'SUCCESS', 'transactionStatus': 'FAILED'}):
            with self.subTest(override=override), self.assertRaises(ValueError):
                parse_transaction(payload(**override))

    def test_order_hint_normalization_matches_retained_provider_contract(self):
        self.assertEqual(normalize_order_id(' 445011487581044736 '), '445011487581044736')
        self.assertEqual(normalize_order_id('P_386362580992'), 'P_386362580992')
        for value in ('123', '445011 487581044736', '445011487581044736<script>', '4' * 129, True):
            self.assertIsNone(normalize_order_id(value))

    def test_receiver_snapshot_wins_and_missing_history_requires_explicit_permission(self):
        _, payment, _ = rows()
        verifier = adapter(pay_id='new-pay-id', pay_uid='new-uid', allow_historical_receiver_fallback=True)
        self.assertEqual(verifier.receivers(payment), (('pay_id', '987654321'),))
        payment.update(binance_receiver_type=None, binance_receiver_value=None)
        with self.assertRaises(ValueError):
            adapter(pay_id='new-pay-id').receivers(payment)
        self.assertEqual(verifier.receivers(payment), (('pay_id', 'new-pay-id'), ('uid', 'new-uid')))
        payment['binance_receiver_type'] = 'unknown'
        with self.assertRaises(ValueError):
            verifier.receivers(payment)

    def test_persisted_evidence_is_bounded_sanitized_and_not_provider_payload(self):
        observed = parse_transaction(payload(api_key='secret', note='private', phone='private'))
        evidence = observed.evidence()
        self.assertNotIn('secret', json.dumps(evidence))
        self.assertNotIn('private', json.dumps(evidence))
        self.assertNotIn(observed.transaction_id, json.dumps(evidence))
        self.assertEqual(evidence['amount_micro'], 5_000_001)


class BinanceAdapterTests(unittest.TestCase):
    def test_fixed_get_endpoint_signed_exact_query_and_transport_limits(self):
        verifier = adapter()
        result = verifier.history(STAMP - 1000, STAMP + 1000)
        self.assertTrue(result.complete)
        self.assertEqual(result.transactions[0].amount, 5_000_001)
        args, kwargs = verifier.transport.request.call_args
        self.assertEqual(args[0], 'GET')
        url = urlsplit(args[1])
        self.assertEqual((url.scheme, url.netloc, url.path),
            ('https', 'api.binance.com', '/sapi/v1/pay/transactions'))
        query, signature = url.query.rsplit('&signature=', 1)
        self.assertEqual(signature, hmac.new(b'retained-secret', query.encode(), hashlib.sha256).hexdigest())
        self.assertEqual(parse_qs(query), {'startTime': [str(STAMP - 1000)], 'endTime': [str(STAMP + 1000)],
            'limit': ['100'], 'recvWindow': ['10000'], 'timestamp': [str(STAMP)]})
        self.assertEqual(kwargs['headers'], {'X-MBX-APIKEY': 'retained-key'})
        self.assertEqual(kwargs['max_response_bytes'], 262144)
        self.assertLessEqual(kwargs['timeout'], 10)
        self.assertEqual(verifier.readiness(), {'ready': True})

    def test_saturated_window_splits_and_budget_exhaustion_is_incomplete(self):
        verifier = adapter()
        verifier.transport.request.side_effect = [envelope([payload()] * 100), envelope([payload()]), envelope([payload()])]
        result = verifier.history(STAMP - 100, STAMP)
        self.assertTrue(result.complete)
        self.assertEqual((result.requests, len(result.transactions)), (3, 1))
        bounded = adapter(max_pages=1)
        bounded.transport.request.return_value = envelope([payload()] * 100)
        self.assertFalse(bounded.history(STAMP - 100, STAMP).complete)

    def test_bad_envelope_any_malformed_row_or_conflicting_duplicate_is_unresolved(self):
        verifier = adapter()
        for response in ((500, b'{}'), (429, b'{}'), (401, b'{}'), (200, b'[]'), (200, b'bad'),
                         (200, b'x' * 262145), (200, b'{"code":"000000","success":true,"data":null}'),
                         (200, b'{"code":"000000","success":true,"success":false,"data":[]}'),
                         envelope([payload(), {'transactionId': 'malformed'}]),
                         envelope([payload(), payload(amount='6')])):
            verifier.transport.request.return_value = response
            with self.subTest(response=response[0]), self.assertRaises(BinanceUnavailable):
                verifier.history(STAMP - 100, STAMP)
        verifier.transport.request.side_effect = OSError('secret-do-not-leak')
        with self.assertRaisesRegex(BinanceUnavailable, '^Binance history unavailable$'):
            verifier.history(STAMP - 100, STAMP)

    def test_no_history_request_can_happen_under_database_locks(self):
        verifier = adapter()
        with patch('lhie_candidate.payments.binance.settings', SimpleNamespace(configured=True)), \
             patch('lhie_candidate.payments.binance.connections') as connections:
            connections.all.return_value = [SimpleNamespace(in_atomic_block=True)]
            with self.assertRaises(RuntimeError):
                verifier.history(STAMP - 100, STAMP)
        verifier.transport.request.assert_not_called()


class BinanceLifecycleTests(unittest.TestCase):
    def _observe(self, value, locked_rows):
        value._locked = Mock(return_value=locked_rows)
        with patch('lhie_candidate.payments.binance.transaction.atomic', side_effect=lambda **_: nullcontext()), \
             patch('lhie_candidate.payments.binance.claim_binance_payment', return_value=SimpleNamespace(held=False)) as claim:
            result = value.refresh('payment-id')
        return result, claim

    def test_authoritative_exact_transfer_claims_before_lifecycle_for_each_lookup_mode(self):
        for mode, hint in (('amount_unique', None), ('order_id', '445011487581044736'),
                           ('order_id', 'P_TRANSACTION')):
            value = lifecycle()
            order, payment, payments = rows()
            payment.update(binance_verification_mode=mode, binance_order_id=hint)
            sequence = []
            value.orders.paid_locked.side_effect = lambda *args: (sequence.append('paid') or SimpleNamespace(review_id=None))
            value._locked = Mock(return_value=(order, payment, payments))
            with patch('lhie_candidate.payments.binance.transaction.atomic', side_effect=lambda **_: nullcontext()), \
                 patch('lhie_candidate.payments.binance.claim_binance_payment', side_effect=lambda *a, **kw:
                       (sequence.append('claim') or SimpleNamespace(held=False))) as claim:
                result = value.refresh('payment-id')
            self.assertEqual(result.state, 'paid')
            self.assertEqual(sequence, ['claim', 'paid'])
            self.assertEqual(claim.call_args.kwargs['transaction_id'], 'P_TRANSACTION')
            self.assertEqual(claim.call_args.kwargs['order_id'], '445011487581044736')

    def test_history_incomplete_outage_and_wrong_evidence_never_claim_or_cancel(self):
        cases = [(500, b'{}', 'pending'), (*envelope([payload(amount='5')]), 'review'),
                 (*envelope([payload(status='FAILED')]), 'pending'),
                 (*envelope([payload(currency='BTC')]), 'review'),
                 (*envelope([payload(receiverInfo={'accountId': 'wrong'})]), 'review'),
                 (*envelope([payload(transactionTime=STAMP + 121000)]), 'review')]
        for status, body, expected in cases:
            value = lifecycle()
            locked = rows()
            locked[1]['binance_verification_grace_until'] = None
            value.adapter.transport.request.return_value = status, body
            result, claim = self._observe(value, locked)
            self.assertEqual(result.state, expected)
            claim.assert_not_called()
            value.orders.paid_locked.assert_not_called()
            value.orders._release_locked.assert_not_called()
        value = lifecycle()
        locked = rows()
        locked[1]['binance_verification_grace_until'] = None
        value.adapter = adapter(max_pages=1)
        value.adapter.transport.request.return_value = envelope([payload()] * 100)
        result, claim = self._observe(value, locked)
        self.assertEqual(result.state, 'pending')
        claim.assert_not_called()

    def test_revised_submission_during_network_cannot_settle_or_overwrite_outcome(self):
        value = lifecycle()
        initial = rows()
        changed = deepcopy(initial)
        changed[1].update(binance_order_id='OTHER_TRANSACTION', binance_order_id_submission_version=2)
        value._locked = Mock(side_effect=[initial, changed])
        with patch('lhie_candidate.payments.binance.transaction.atomic', side_effect=lambda **_: nullcontext()), \
             patch('lhie_candidate.payments.binance.claim_binance_payment') as claim:
            self.assertEqual(value.refresh('payment-id').state, 'pending')
        claim.assert_not_called()
        value.orders.paid_locked.assert_not_called()
        value.db.execute.assert_not_called()

    def test_held_receipt_never_reaches_paid_lifecycle(self):
        value = lifecycle()
        value._locked = Mock(return_value=rows())
        with patch('lhie_candidate.payments.binance.transaction.atomic', side_effect=lambda **_: nullcontext()), \
             patch('lhie_candidate.payments.binance.claim_binance_payment', return_value=SimpleNamespace(held=True)):
            self.assertEqual(value.refresh('payment-id').state, 'review')
        value.orders.paid_locked.assert_not_called()
        value.orders.review_locked.assert_called_once()

    def test_late_money_releases_to_terminal_review_instead_of_selling(self):
        value = lifecycle()
        order, payment, payments = rows()
        order['expires_at'] = payment['expired_at'] = AT - timedelta(seconds=1)
        payment['binance_verification_grace_until'] = AT + timedelta(minutes=3)
        sequence = []
        def expire(*args, **kwargs):
            sequence.append('expire')
            order['order_status'], payment['status'] = 'expired', 'expired'
        value.orders._release_locked.side_effect = expire
        value.orders.paid_locked.side_effect = lambda *args: (sequence.append('paid_review') or SimpleNamespace(review_id=1))
        result, claim = self._observe(value, (order, payment, payments))
        self.assertEqual(result.state, 'review')
        claim.assert_called_once()
        self.assertEqual(sequence, ['expire', 'paid_review'])

    def test_terminal_on_time_money_is_reviewed_without_reopening_order(self):
        value = lifecycle()
        order, payment, payments = rows()
        order.update(order_status='cancelled', payment_status='failed')
        payment['status'] = 'failed'
        value.orders.paid_locked.return_value = SimpleNamespace(review_id=1)
        result, claim = self._observe(value, (order, payment, payments))
        self.assertEqual(result.state, 'review')
        claim.assert_called_once()
        value.orders._release_locked.assert_not_called()

    def test_submission_ownership_attempt_limit_and_idempotent_existing_hint(self):
        value = lifecycle()
        locked = rows()
        value._locked = Mock(return_value=locked)
        self.assertEqual(value.submit_order_id('payment-id', '445011487581044736', customer_id=8).state, 'not_owned')
        self.assertEqual(value.submit_order_id('payment-id', '445011487581044736', customer_id=7).state, 'accepted')
        value.db.execute.assert_not_called()
        locked[1]['binance_order_id_attempts'] = 3
        self.assertEqual(value.submit_order_id('payment-id', 'OTHER_TRANSACTION', customer_id=7).state, 'limit')
        locked[1]['binance_order_id_attempts'] = 1
        self.assertEqual(value.submit_order_id('payment-id', 'OTHER_TRANSACTION', customer_id=7).state, 'accepted')
        sql, parameters = value.db.execute.call_args.args
        self.assertIn('binance_order_id_submission_version+1', sql)
        self.assertIn('binance_last_verified_at=NULL', sql)
        self.assertEqual(parameters[0], 'OTHER_TRANSACTION')

    def test_retry_delay_and_count_are_durable_per_submission_and_bound_to_grace(self):
        value = lifecycle()
        _, payment, _ = rows()
        payment['binance_last_verified_at'] = AT
        value.db.one.return_value = {'attempts': 1}
        self.assertEqual(value._retry(payment), STAMP // 1000 + 30)
        value.db.one.return_value = {'attempts': 2}
        self.assertEqual(value._retry(payment), STAMP // 1000 + 60)
        value.db.one.return_value = {'attempts': 3}
        self.assertIsNone(value._retry(payment))
        payment['binance_verification_grace_until'] = AT + timedelta(seconds=20)
        value.db.one.return_value = {'attempts': 1}
        self.assertIsNone(value._retry(payment))
        payment['binance_verification_grace_until'] = AT + timedelta(minutes=3)
        value.db.one.return_value = {'attempts': 1, 'blocked': True}
        self.assertIsNone(value._retry(payment))

    def test_already_paid_recheck_never_reclaims_or_sells_again(self):
        value = lifecycle()
        order, payment, payments = rows()
        order.update(order_status='completed', payment_status='paid')
        payment['status'] = 'paid'
        result, claim = self._observe(value, (order, payment, payments))
        self.assertEqual(result.state, 'paid')
        claim.assert_not_called()
        value.orders.paid_locked.assert_not_called()
        value.adapter.transport.request.assert_not_called()

    def test_no_caller_history_argument_is_accepted_as_paid_authority(self):
        value = lifecycle()
        with self.assertRaises(TypeError):
            value.refresh('payment-id', [payload()])
