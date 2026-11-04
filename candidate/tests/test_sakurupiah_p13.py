"""Synthetic Sakurupiah protocol/consumer contracts, not provider qualification."""
from contextlib import nullcontext
from dataclasses import replace
from datetime import datetime, timedelta
import json
from types import SimpleNamespace
from unittest import TestCase
from unittest.mock import Mock, patch

from lhie_candidate.checkout.errors import CheckoutError
from lhie_candidate.checkout.sakurupiah import SakurupiahPreparation
from lhie_candidate.compat.crypto import LaravelEncrypter
from lhie_candidate.payments.callback import _callback
from lhie_candidate.payments.lifecycle import (
    LifecycleResult, SakurupiahLifecycle, _now, _presentation, _valid_presentation,
)
from lhie_candidate.payments.sakurupiah import (
    Channel, CreateRequest, InvoiceIdentity, OutcomeState, ProviderOutcome, SakurupiahSandboxAdapter,
)
from lhie_candidate.payments.sakurupiah_coordination import CoordinationUnavailable, SakurupiahCoordination
from lhie_candidate.payments.sakurupiah_urls import validate_sakurupiah_qris_payload


def envelope(*rows):
    return {'status': '200', 'data': list(rows)}


class Relay:
    def __init__(self, *responses):
        self.responses = iter(responses)
        self.calls = []

    def post(self, url, **arguments):
        self.calls.append((url, arguments))
        result = next(self.responses)
        if isinstance(result, Exception):
            raise result
        if type(result) is tuple:
            return result
        return 200, json.dumps(result).encode()


def history(**changes):
    row = {'trx_id': 'trx-p13', 'merchant_ref': 'checkout-p13', 'payment_kode': 'QRIS',
           'status': 'pending', 'amount': '10000'}
    row.update(changes)
    return row


def native_payload(amount='10070', currency='360', extra=''):
    def tlv(tag, value):
        return tag + f'{len(value.encode()):02d}' + value
    data = tlv('00', '01') + tlv('53', currency) + tlv('54', amount) + extra + '6304'
    crc = 0xFFFF
    for byte in data.encode():
        crc ^= byte << 8
        for _ in range(8):
            crc = ((crc << 1) ^ 0x1021 if crc & 0x8000 else crc << 1) & 0xFFFF
    return data + f'{crc:04X}'


class SakurupiahP13ProtocolTests(TestCase):
    def setUp(self):
        self.identity = InvoiceIdentity('checkout-p13', 10000, 'QRIS')
        self.request = CreateRequest(self.identity, '0812345678', 'https://example.test/callback',
                                     'https://example.test/return')

    def test_documented_rejection_is_distinct_from_dispatched_uncertainty(self):
        for response in ({'status': '400', 'message': 'private merchant configuration'},
                         (400, b'{"status":"403","message":"private rejected identity"}')):
            adapter = SakurupiahSandboxAdapter(transport=Relay(response))
            result = adapter.create(self.request)
            self.assertIs(result.state, OutcomeState.REJECTED)
            self.assertEqual(result.reason, 'provider_rejected')
            self.assertNotIn('private', repr(result))
        for response in (TimeoutError('private token'), (503, b'{"status":"500"}'),
                         (429, b'{}'), (200, b'{"status":"200","status":"400"}')):
            relay = Relay(response)
            result = SakurupiahSandboxAdapter(transport=relay).create(self.request)
            self.assertIs(result.state, OutcomeState.UNKNOWN)
            self.assertEqual(len(relay.calls), 1)

    def test_pre_dispatch_deferral_is_retryable_without_a_create_call(self):
        coordinator = SimpleNamespace(request=Mock(side_effect=CoordinationUnavailable('rate_limit_cooldown', 1234)))
        relay = Relay()
        result = SakurupiahSandboxAdapter(transport=relay, coordination=coordinator).create(self.request)
        self.assertIs(result.state, OutcomeState.REJECTED)
        self.assertEqual(result.retry_at, 1234)
        self.assertEqual(relay.calls, [])

    def test_unknown_create_recovers_without_committed_total_from_exact_base_history(self):
        relay = Relay(envelope(history()), envelope({'status': 'pending'}), envelope(history()))
        adapter = SakurupiahSandboxAdapter(transport=relay)
        recovered = adapter.recover(self.identity)
        self.assertEqual((recovered.state, recovered.observed_amount), (OutcomeState.PENDING, 10000))
        verified = adapter.status(self.identity, recovered.provider_ref)
        self.assertIs(verified.state, OutcomeState.PENDING)
        self.assertIsNone(verified.total)
        self.assertFalse(any(url.endswith('/create.php') for url, _ in relay.calls))

    def test_missing_total_does_not_infer_an_arbitrary_buyer_fee(self):
        for row in (history(amount='10070'), history(amount='10070', total='10070'),
                    history(amount='10070', total='10070', fee='69'),
                    history(amount='10070', total=10070.0, fee='70')):
            result = SakurupiahSandboxAdapter(transport=Relay(envelope(row))).recover(self.identity, merchant_fee=2)
            self.assertIs(result.state, OutcomeState.UNKNOWN)
        row = history(amount='10070', total='10070', fee='70', merchant_fee='buyer')
        result = SakurupiahSandboxAdapter(transport=Relay(envelope(row))).recover(self.identity, merchant_fee=2)
        self.assertEqual((result.state, result.total, result.fee), (OutcomeState.PENDING, 10070, 70))

    def test_status_and_history_must_agree_on_reference_identity_and_status(self):
        invalid = (history(trx_id='foreign'), history(merchant_ref='foreign'), history(payment_kode='QRIS2'),
                   history(currency='USD'), history(status='unexpected'), history(amount='9999'))
        for row in invalid:
            adapter = SakurupiahSandboxAdapter(transport=Relay(envelope({'status': 'pending'}), envelope(row)))
            self.assertIs(adapter.status(self.identity, 'trx-p13').state, OutcomeState.UNKNOWN)
        adapter = SakurupiahSandboxAdapter(transport=Relay(envelope({'status': 'expired'}),
                                                         envelope(history(status='berhasil'))))
        self.assertIs(adapter.status(self.identity, 'trx-p13').state, OutcomeState.UNKNOWN)

    def test_authoritative_success_remains_a_hint_when_history_is_unavailable(self):
        adapter = SakurupiahSandboxAdapter(transport=Relay(envelope({'status': 'berhasil'}), TimeoutError()))
        result = adapter.status(self.identity, 'trx-p13', provider_total=10070)
        self.assertIs(result.state, OutcomeState.UNKNOWN)
        self.assertTrue(result.success_indicated)
        adapter = SakurupiahSandboxAdapter(transport=Relay(envelope({'status': 'berhasil', 'merchant_ref': 'foreign'})))
        self.assertFalse(adapter.status(self.identity, 'trx-p13').success_indicated)

    def test_callback_parser_accepts_only_exact_known_status_pairs(self):
        body = {'merchant_ref': 'checkout-p13', 'trx_id': 'trx-p13', 'status': 'berhasil', 'status_kode': 1}
        self.assertEqual(_callback(json.dumps(body).encode())[:3], ('checkout-p13', 'trx-p13', 'berhasil'))
        for changes in ({'status_kode': True}, {'status': 'unknown'}, {'trx_id': ' trx-p13'}, {'status_kode': 0}):
            with self.subTest(changes=changes), self.assertRaises(ValueError):
                _callback(json.dumps({**body, **changes}).encode())
        with self.assertRaises(ValueError):
            _callback(b'{"merchant_ref":"a","merchant_ref":"b"}')


class SakurupiahP13PresentationTests(TestCase):
    def test_native_integrity_requires_crc_unique_byte_tlv_currency_and_exact_idr(self):
        self.assertTrue(validate_sakurupiah_qris_payload(native_payload(), 10070))
        self.assertTrue(validate_sakurupiah_qris_payload(native_payload('10070.00'), 10070))
        values = (native_payload()[:-1] + 'X', native_payload(currency='840'), native_payload('10070.01'),
                  native_payload('0'), native_payload(extra='5303360'), native_payload() + 'tail',
                  native_payload(extra='5903é'), '00020163040000')
        for value in values:
            with self.subTest(value=value):
                self.assertFalse(validate_sakurupiah_qris_payload(value, 10070))
        self.assertFalse(validate_sakurupiah_qris_payload(native_payload(), 10000))

    def test_native_payload_wins_over_hosted_url_without_exposing_both(self):
        expiry = _now() + timedelta(minutes=5)
        outcome = SimpleNamespace(qr=native_payload(), total=10070,
                                  checkout_url='https://sakurupiah.id/sanbox-payout.php?synthetic=1')
        _, presentation = _presentation(outcome, {'expired_at': expiry}, {'expires_at': expiry}, 2, expiry)
        self.assertEqual(presentation['mode'], 'qris')
        self.assertIsNone(presentation['qris_image_url'])
        self.assertIsNone(presentation['checkout_url'])
        self.assertTrue(_valid_presentation(presentation, 10070))
        self.assertFalse(_valid_presentation({**presentation, 'qris_payload': native_payload('10000')}, 10070))
        self.assertFalse(_valid_presentation({**presentation, 'checkout_url': outcome.checkout_url}, 10070))

    def test_fresh_readiness_is_required_inside_checkout_without_provider_io(self):
        setting = SimpleNamespace(active=True, provider='sakurupiah', sakurupiah_channel='QRIS')
        adapter = SimpleNamespace(list_channels=Mock(return_value=(Channel('QRIS', True, 500, 20000, 1000),)))
        preparation = SakurupiahPreparation(adapter)
        preflight = preparation.preflight(setting)
        with patch('lhie_candidate.checkout.sakurupiah.time.time', return_value=1060):
            with self.assertRaises(CheckoutError):
                preparation.prepare(SimpleNamespace(idr_total=10000), setting, preflight, datetime.now())
        adapter.list_channels.assert_called_once()


class MemoryCoordinationDatabase:
    """Model just the disposable request fence/cooldown, never money or Order writes."""
    def __init__(self):
        self.locked = False
        self.retry_at = None
        self.evidence = []

    def one(self, sql, parameters):
        if 'pg_try_advisory_lock' in sql:
            if self.locked:
                return {'acquired': False}
            self.locked = True
            return {'acquired': True}
        if 'pg_advisory_unlock' in sql:
            self.locked = False
            return {'released': True}
        return {'retry_at': self.retry_at}

    def execute(self, sql, parameters):
        self.retry_at = parameters[1]
        self.evidence.append((sql, parameters))


class SakurupiahP13RedisLossTests(TestCase):
    def setUp(self):
        self.database = MemoryCoordinationDatabase()
        self.redis = Mock()
        self.redis.eval.side_effect = OSError('synthetic Redis outage')
        self.coordination = SakurupiahCoordination(db=self.database, client=self.redis, clock=lambda: 1000)

    def test_readiness_and_authoritative_lookup_continue_during_redis_loss(self):
        relay = Relay(envelope({'kode': 'QRIS', 'status': 'Aktif', 'minimal': '500', 'maksimal': '20000'}),
                      envelope({'status': 'berhasil'}), envelope(history(status='berhasil')))
        adapter = SakurupiahSandboxAdapter(transport=relay, coordination=self.coordination)
        channel, = adapter.list_channels()
        self.assertEqual(channel.code, 'QRIS')
        result = adapter.status(InvoiceIdentity('checkout-p13', 10000, 'QRIS'), 'trx-p13')
        self.assertIs(result.state, OutcomeState.SUCCESS)
        self.assertEqual(len(relay.calls), 3)
        self.assertFalse(self.database.locked)

    def test_new_consumer_honors_durable_throttle_while_redis_is_stopped(self):
        request = CreateRequest(InvoiceIdentity('checkout-p13', 10000, 'QRIS'), '0812345678',
                                'https://example.test/callback', 'https://example.test/return')
        relay = Relay((429, b'{}'))
        self.assertIs(SakurupiahSandboxAdapter(transport=relay, coordination=self.coordination).create(request).state,
                      OutcomeState.UNKNOWN)
        consumer = SakurupiahCoordination(db=self.database, client=self.redis, clock=lambda: 1001)
        second = Relay()
        result = SakurupiahSandboxAdapter(transport=second, coordination=consumer).create(request)
        self.assertIs(result.state, OutcomeState.REJECTED)
        self.assertEqual(result.retry_at, 1060)
        self.assertEqual(second.calls, [])
        self.assertEqual(len(self.database.evidence), 1)
        self.assertNotIn('0812345678', str(self.database.evidence))

    def test_concurrent_consumers_never_overlap_provider_requests_after_redis_loss(self):
        second = SakurupiahCoordination(db=self.database, client=self.redis, clock=lambda: 1000)
        with self.coordination.request():
            with self.assertRaises(CoordinationUnavailable):
                with second.request():
                    self.fail('Overlapping merchant request')
        with second.request():
            self.assertTrue(self.database.locked)
        self.assertFalse(self.database.locked)

    def test_corrupt_or_expired_readiness_cache_does_not_replace_fresh_observation(self):
        for rows in (['bad'], ['QRIS:1:500:20000:900'], ['QRIS:1:500:20000:1100']):
            redis = Mock()
            redis.eval.return_value = rows
            coordinator = SakurupiahCoordination(db=self.database, client=redis, clock=lambda: 1000)
            self.assertIsNone(coordinator.cached_channels())


class SakurupiahP13LifecycleTests(TestCase):
    def setUp(self):
        self.cipher = LaravelEncrypter(b'synthetic-p08-key-material-00000')
        self.adapter = Mock()
        self.lifecycle = SakurupiahLifecycle(self.adapter, self.cipher)
        self.lifecycle.db = Mock()
        self.lifecycle.db.one.return_value = None
        self.lifecycle.orders = Mock()
        self.at = _now()
        self.deadline = self.at + timedelta(minutes=15)
        self.order = {'id': 'order-p13', 'expires_at': self.deadline, 'total_amount': 10000,
                      'currency': 'IDR', 'order_status': 'pending_payment', 'payment_status': 'pending'}
        self.payment = {'id': 'payment-p13', 'order_id': 'order-p13', 'provider': 'sakurupiah',
                        'method': 'QRIS', 'currency': 'IDR', 'amount': 10000, 'merchant_ref': 'checkout-p13',
                        'sakurupiah_channel': 'QRIS', 'expired_at': self.deadline, 'status': 'pending',
                        'provider_reference': None, 'provider_total_payment': None, 'provider_fee': None,
                        'provider_expires_at': None, 'provider_presentation': None, 'provider_create_state': None,
                        'provider_payment_method': None, 'provider_create_attempted_at': None,
                        'provider_request_snapshot': None}
        self.lifecycle._locked = Mock(return_value=(self.order, self.payment, [self.payment]))
        self.atomic = patch('lhie_candidate.payments.lifecycle.transaction.atomic', side_effect=lambda **_: nullcontext())
        self.atomic.start()
        self.addCleanup(self.atomic.stop)
        self.request = dict(phone='0812345678', callback_url='https://example.test/callback',
                            return_url='https://example.test/return', merchant_fee=2)
        self.identity = InvoiceIdentity('checkout-p13', 10000, 'QRIS')

    def freeze_request(self):
        self.payment['provider_request_snapshot'] = self.cipher.encrypt_json({
            'payment_id': self.payment['id'], 'merchant_ref': 'checkout-p13', 'amount': 10000,
            'channel': 'QRIS', 'merchant_fee': 2, 'create_request': self.request})

    def test_rejected_create_retries_same_frozen_identity_after_interval(self):
        self.adapter.create.return_value = ProviderOutcome(self.identity, OutcomeState.REJECTED)
        self.freeze_request()
        self.payment.update(provider_create_state='create_failed', provider_create_attempted_at=self.at)
        result = self.lifecycle._create(self.payment['id'], **self.request)
        self.assertEqual(result.state, 'create_failed')
        self.adapter.create.assert_not_called()
        self.payment['provider_create_attempted_at'] = self.at - timedelta(seconds=61)
        self.assertEqual(self.lifecycle._recover(self.payment['id']).state, 'create_failed')
        sent = self.adapter.create.call_args.args[0]
        self.assertEqual(sent.identity, self.identity)
        self.assertEqual(sent.merchant_fee, 2)
        self.assertEqual(self.payment['id'], 'payment-p13')

    def test_policy_changes_cannot_retarget_retry(self):
        self.freeze_request()
        self.payment.update(provider_create_state='create_failed', provider_create_attempted_at=self.at - timedelta(seconds=61))
        for changes in ({'merchant_fee': 1}, {'phone': 'different'}, {'callback_url': 'https://other.test/callback'}):
            result = self.lifecycle._create(self.payment['id'], **{**self.request, **changes})
            self.assertEqual(result.state, 'provider_invalid_result')
        self.adapter.create.assert_not_called()

    def test_unknown_create_is_never_blindly_created_again(self):
        self.payment['provider_create_state'] = 'outcome_unknown'
        self.assertEqual(self.lifecycle._create(self.payment['id'], **self.request).state, 'outcome_unknown')
        self.adapter.create.assert_not_called()

    def test_pre_reference_success_hint_reaches_status_and_shared_settlement(self):
        self.freeze_request()
        self.payment['provider_create_state'] = 'outcome_unknown'
        self.adapter.status.return_value = ProviderOutcome(self.identity, OutcomeState.SUCCESS, 'trx-p13', observed_amount=10000)
        self.lifecycle._settle = Mock(return_value=LifecycleResult('paid'))
        hint = {'id': 5, 'provider_reference': 'trx-p13', 'provider_status': 'berhasil'}
        result = self.lifecycle._observe_locked(self.payment['id'], recovery=True, hint=hint)
        self.assertEqual(result.state, 'paid')
        self.adapter.status.assert_called_once_with(self.identity, 'trx-p13', provider_total=None)
        self.adapter.create.assert_not_called()
        self.assertEqual(self.payment['provider_reference'], 'trx-p13')
        self.lifecycle._settle.assert_called_once_with(self.order, self.payment, [self.payment])
        self.assertTrue(any('processed_at' in call.args[0] for call in self.lifecycle.db.execute.call_args_list))

    def test_unknown_status_retains_callback_obligation_without_local_mutation(self):
        self.freeze_request()
        self.payment['provider_create_state'] = 'outcome_unknown'
        self.adapter.status.return_value = ProviderOutcome(self.identity, OutcomeState.UNKNOWN, 'trx-p13')
        before = dict(self.payment)
        result = self.lifecycle._observe_locked(self.payment['id'], recovery=True,
                    hint={'id': 5, 'provider_reference': 'trx-p13', 'provider_status': 'berhasil'})
        self.assertEqual(result.state, 'recovery_unconfirmed')
        self.assertEqual(self.payment, before)
        self.lifecycle.db.execute.assert_not_called()

    def test_status_snapshot_mismatches_never_mutate_invoice_or_settle(self):
        self.freeze_request()
        self.payment.update(provider_reference='trx-p13', provider_total_payment=10070, provider_fee=70,
                            provider_expires_at=self.deadline, provider_payment_method='QRIS')
        original = ProviderOutcome(self.identity, OutcomeState.SUCCESS, 'trx-p13', 10070, 70,
                                  self.deadline.isoformat(), observed_amount=10070, merchant_fee=2)
        self.lifecycle._settle = Mock()
        for changes in ({'provider_ref': 'foreign'}, {'identity': InvoiceIdentity('foreign', 10000, 'QRIS')},
                        {'total': 10071}, {'fee': 71}, {'merchant_fee': 1},
                        {'expiry': (self.deadline + timedelta(seconds=1)).isoformat()}):
            self.adapter.status.return_value = replace(original, **changes)
            self.assertEqual(self.lifecycle._observe_locked(self.payment['id'], recovery=False).state,
                             'recovery_unconfirmed')
        self.lifecycle.db.execute.assert_not_called()
        self.lifecycle._settle.assert_not_called()

    def test_expired_observation_cannot_erase_outstanding_success_hint(self):
        self.payment.update(provider_reference='trx-p13', provider_total_payment=10000,
                            provider_fee=0, provider_payment_method='QRIS')
        self.adapter.status.return_value = ProviderOutcome(self.identity, OutcomeState.EXPIRED, 'trx-p13')
        self.lifecycle.db.one.return_value = {'id': 5}
        self.assertEqual(self.lifecycle._observe_locked(self.payment['id'], recovery=False).state,
                         'recovery_unconfirmed')
        self.lifecycle.orders._release_locked.assert_not_called()

    def test_cancelled_or_late_money_delegates_to_canonical_review(self):
        self.payment.update(provider_reference='trx-p13', provider_total_payment=10070, provider_fee=70,
                            provider_expires_at=self.deadline)
        self.lifecycle.orders.paid_locked.return_value = SimpleNamespace(review_id=7)
        self.order['order_status'] = 'cancelled'
        self.order['payment_status'] = 'failed'
        with patch('lhie_candidate.payments.lifecycle.claim_provider_reference', return_value=SimpleNamespace(held=False)):
            result = self.lifecycle._settle(self.order, self.payment, [self.payment])
        self.assertEqual(result.state, 'review')
        self.lifecycle.orders.paid_locked.assert_called_once_with(self.order, self.payment, [self.payment])
        self.lifecycle.orders._release_locked.assert_not_called()
        self.lifecycle.db.execute.assert_not_called()

    def test_uncreated_cancelled_payment_cannot_send_create(self):
        self.order['order_status'] = 'cancelled'
        self.payment['status'] = 'failed'
        self.assertEqual(self.lifecycle._create(self.payment['id'], **self.request).state, 'failed')
        self.adapter.create.assert_not_called()
