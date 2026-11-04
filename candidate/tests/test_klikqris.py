import binascii
from contextlib import nullcontext
from dataclasses import replace
from datetime import datetime, timedelta
import json
from unittest import TestCase
from unittest.mock import Mock, patch

from lhie_candidate.compat.crypto import LaravelEncrypter
from lhie_candidate.payments.klikqris import (
    KlikQrisAdapter, KlikQrisConfig, KlikQrisError, KlikQrisLifecycle,
    KlikQrisObservation, KlikQrisTransaction, amount, authenticate_callback,
    callback_identity, deadline, valid_qr_url, validate_emv,
)


AT = datetime(2030, 1, 1, 10)
URL = 'https://klikqris.com/storage/sandbox/qris_P13.png'


def provider_payload(**changes):
    data = {'order_id': 'KLIK-P13-1', 'amount': '10000.00', 'total_amount': '10016.0',
            'status': 'PENDING', 'signature': 'synthetic-signature',
            'expired_at': '2030-01-01 10:15:00', 'qris_url': URL,
            'provider_reference': 'klik-receipt-1'}
    data.update(changes)
    return {'data': data}


def payment(**changes):
    row = {'id': 'payment-1', 'order_id': 'order-1', 'provider': 'klikqris', 'method': 'QRIS',
           'currency': 'IDR', 'amount': 10000, 'merchant_ref': 'KLIK-P13-1',
           'status': 'pending', 'expired_at': AT + timedelta(minutes=15), 'paid_at': None,
           'provider_reference': None, 'provider_total_payment': None, 'provider_fee': None,
           'provider_expires_at': None, 'provider_payment_method': None, 'provider_payload': None,
           'provider_qris_url': None, 'provider_create_state': None,
           'provider_create_attempted_at': None, 'klikqris_transaction_signature': None,
           'klikqris_status_failure_count': 0, 'klikqris_status_next_check_at': None,
           'klikqris_status_last_error': None}
    row.update(changes)
    return row


def order(**changes):
    row = {'id': 'order-1', 'order_status': 'pending_payment', 'payment_status': 'pending',
           'total_amount': 10000, 'currency': 'IDR', 'expires_at': AT + timedelta(minutes=15)}
    row.update(changes)
    return row


class Transport:
    def __init__(self, result):
        self.result, self.calls = result, []

    def request(self, *args, **kwargs):
        self.calls.append((args, kwargs))
        if isinstance(self.result, Exception):
            raise self.result
        return self.result


def adapter(result=None, **config):
    transport = Transport(result or (200, json.dumps(provider_payload()).encode()))
    settings = KlikQrisConfig(enabled=True, api_key='synthetic-api', merchant_id='synthetic-merchant',
                              operational_ready=True, **config)
    return KlikQrisAdapter(transport=transport, config=settings), transport


class KlikQrisContracts(TestCase):
    def setUp(self):
        self.encrypter = LaravelEncrypter(b'k' * 32)

    def test_exact_positive_whole_rupiah(self):
        for value in (1, '10000', '10000.000', 2**63 - 1):
            self.assertEqual(amount(value), int(str(value).split('.')[0]))
        for value in (True, 1.0, '1.01', '01', '+1', '1e3', ' 1', '0', 2**63):
            with self.subTest(value=value), self.assertRaises(KlikQrisError):
                amount(value)

    def test_sandbox_urls_are_exact_storage_png_only(self):
        self.assertTrue(valid_qr_url(URL))
        for url in ('http://klikqris.com/storage/sandbox/qris_P13.png', URL + '?key=secret',
                    URL + '#fragment', URL.replace('klikqris.com', 'klikqris.com.evil.test'),
                    URL.replace('klikqris.com', 'user@klikqris.com'),
                    URL.replace('klikqris.com', 'klikqris.com:443'),
                    URL.replace('qris_P13', '../qris_P13'), URL.replace('qris_P13', '%71ris_P13'),
                    URL.replace('.png', '.svg'), URL.replace('sandbox/', ''), URL + '\n'):
            with self.subTest(url=url):
                self.assertFalse(valid_qr_url(url))

    def test_production_urls_require_explicit_hosts(self):
        self.assertFalse(valid_qr_url('https://media.example.test/qr.png', 'production'))
        self.assertTrue(valid_qr_url('https://media.example.test/qr.png', 'production', ('media.example.test',)))

    def test_naive_timestamp_is_jakarta_and_offset_normalizes(self):
        self.assertEqual(deadline('2030-01-01 10:15:00'), AT + timedelta(minutes=15))
        self.assertEqual(deadline('2030-01-01T03:15:00Z'), AT + timedelta(minutes=15))

    def test_transaction_rejects_fee_alias_and_reference_disagreement(self):
        for changes in ({'fee': '15'}, {'fee': '16', 'provider_fee': '17'},
                        {'transaction_id': 'other'}, {'total_amount': '9999'}, {'amount': 10000.0}):
            with self.subTest(changes=changes), self.assertRaises(KlikQrisError):
                KlikQrisTransaction.parse(provider_payload(**changes))

    def test_unknown_status_is_parseable_but_not_authority(self):
        result = KlikQrisTransaction.parse(provider_payload(status='AWAITING_REVIEW'))
        self.assertEqual(result.status, 'AWAITING_REVIEW')
        client, _ = adapter((200, json.dumps(provider_payload(status='AWAITING_REVIEW')).encode()))
        self.assertEqual(client.status(payment()).state, 'unknown_status')

    def test_transaction_matches_immutable_quote(self):
        result = KlikQrisTransaction.parse(provider_payload())
        committed = payment(provider_total_payment=10016, provider_fee=16,
                            provider_reference='klik-receipt-1', provider_expires_at=AT + timedelta(minutes=15))
        result.assert_matches(committed, 'sandbox')
        for key, value in (('provider', 'pakasir'), ('merchant_ref', 'other'), ('amount', 9999),
                           ('provider_total_payment', 10017), ('provider_fee', 17),
                           ('provider_reference', 'other'), ('provider_expires_at', AT)):
            with self.subTest(key=key), self.assertRaises(KlikQrisError):
                result.assert_matches({**committed, key: value}, 'sandbox')
        with self.assertRaises(KlikQrisError):
            result.assert_matches(committed, 'production')

    def test_emv_crc_currency_country_and_exact_final_amount(self):
        payload = '00020101021253033605405100165802ID6304'
        emv = payload + f'{binascii.crc_hqx(payload.encode(), 0xffff):04X}'
        self.assertEqual(validate_emv(emv, 10016), emv)
        for broken, total in ((emv[:-1] + 'X', 10016), (emv, 10015),
                              (emv.replace('360', '840'), 10016), (emv + '000201', 10016)):
            with self.subTest(broken=broken), self.assertRaises(KlikQrisError):
                validate_emv(broken, total)

    def test_transport_is_injected_bounded_and_forbids_redirects(self):
        client, transport = adapter()
        self.assertEqual(client.create(payment()).state, 'observed')
        args, options = transport.calls[0]
        self.assertEqual(args, ('POST', 'https://klikqris.com/api/sandbox/qris/create'))
        self.assertEqual(options['json']['amount'], 10000)
        self.assertEqual(options['max_bytes'], 65536)
        self.assertEqual(options['allowed_hosts'], ('klikqris.com',))
        self.assertIs(options['allow_redirects'], False)
        self.assertLessEqual(options['connect_timeout'], options['timeout'])

    def test_pre_request_readiness_failure_has_no_io(self):
        client, transport = adapter()
        client.config = replace(client.config, enabled=False)
        self.assertEqual(client.create(payment()).state, 'create_failed')
        self.assertEqual(transport.calls, [])
        self.assertIn('disabled', KlikQrisConfig().readiness())

    def test_readiness_rejects_cross_environment_and_bad_callback(self):
        client, _ = adapter(other_api_key='synthetic-api', callback_url='https://evil.test/hook')
        self.assertEqual(set(client.readiness()), {'cross_environment', 'callback_url'})
        client.config = replace(client.config, environment='production', runtime_environment='local')
        self.assertIn('environment', client.readiness())
        self.assertIn('base_url', client.readiness())

    def test_all_dispatched_http_failures_are_uncertain_not_rejected(self):
        for code in (301, 400, 401, 404, 408, 422, 429, 500, 503):
            with self.subTest(code=code):
                client, transport = adapter((code, b'{"secret":"untrusted"}'))
                result = client.create(payment())
                self.assertEqual(result.state, 'outcome_unknown')
                self.assertIsNone(result.transaction)
                self.assertEqual(len(transport.calls), 1)
                self.assertNotIn('untrusted', repr(result))

    def test_timeout_and_malformed_json_never_retry_create(self):
        for response in (TimeoutError('SECRET'), (200, b'{'), (200, b'[]'),
                         (200, b'{"data":{},"data":{}}'), (200, b'x' * 65537)):
            client, transport = adapter(response)
            result = client.create(payment())
            self.assertEqual(result.state, 'outcome_unknown')
            self.assertEqual(len(transport.calls), 1)
            self.assertNotIn('SECRET', repr(result))

    def test_status_does_not_promote_paid_callback_alias(self):
        client, _ = adapter((200, json.dumps(provider_payload(status='PAID')).encode()))
        self.assertEqual(client.status(payment()).state, 'unknown_status')

    def test_status_identity_mismatch_has_payload_free_failure(self):
        client, _ = adapter((200, json.dumps(provider_payload(order_id='SECRET')).encode()))
        result = client.status(payment())
        self.assertEqual(result.state, 'status_unavailable')
        self.assertNotIn('SECRET', repr(result))

    def callback(self, **changes):
        data = provider_payload()['data']
        data.update(status='PAID', debug={'secret': 'NEVER_PERSIST'})
        data.update(changes)
        return json.dumps(data).encode()

    def authenticated_payment(self, **changes):
        return payment(klikqris_transaction_signature=self.encrypter.encrypt_string('synthetic-signature'),
                       provider_total_payment=10016, **changes)

    def test_callback_requires_persisted_signature_and_returns_minimized_facts(self):
        facts = authenticate_callback(self.callback(), environment='sandbox',
                                      payment=self.authenticated_payment(), encrypter=self.encrypter)
        self.assertEqual(facts['status'], 'PAID')
        self.assertEqual(facts['total_amount'], 10016)
        self.assertNotIn('signature', facts)
        self.assertNotIn('NEVER_PERSIST', json.dumps(facts))
        self.assertEqual(callback_identity(self.callback()), 'KLIK-P13-1')

    def test_callback_rejects_identity_quote_reference_signature_and_environment_mismatch(self):
        cases = ({'signature': 'wrong'}, {'order_id': 'other'}, {'amount': '9999'},
                 {'total_amount': '10017'}, {'signature': None})
        for changes in cases:
            with self.subTest(changes=changes), self.assertRaises(KlikQrisError):
                authenticate_callback(self.callback(**changes), environment='sandbox',
                                      payment=self.authenticated_payment(), encrypter=self.encrypter)
        for row in (payment(), self.authenticated_payment(provider='pakasir'),
                    self.authenticated_payment(provider_reference='wrong'),
                    self.authenticated_payment(klikqris_environment='production')):
            with self.assertRaises(KlikQrisError):
                authenticate_callback(self.callback(), environment='sandbox', payment=row, encrypter=self.encrypter)

    def test_callback_unknown_status_is_safe_hint_not_paid_authority(self):
        facts = authenticate_callback(self.callback(status='REVIEW'), environment='sandbox',
                                      payment=self.authenticated_payment(), encrypter=self.encrypter)
        self.assertEqual(facts['status'], 'REVIEW')

    def test_callback_rejects_duplicate_keys_and_oversize(self):
        for raw in (b'{"order_id":"one","order_id":"two"}', b'x' * 65537):
            with self.assertRaises(KlikQrisError):
                callback_identity(raw)

    def lifecycle(self, *, row=None, root=None):
        client, transport = adapter()
        life = KlikQrisLifecycle(client, self.encrypter, clock=lambda: AT)
        life.db = Mock()
        life.db.one.return_value = None
        life.orders = Mock()
        life.orders.paid_locked.return_value = Mock(review_id=None)
        row = row or payment()
        root = root or order()
        life._outside = Mock()
        life._locked = Mock(side_effect=lambda _: (root, row, [row]))
        return life, row, root, transport

    @patch('lhie_candidate.payments.klikqris.transaction.atomic', side_effect=lambda **_: nullcontext())
    def test_unknown_status_does_not_change_persisted_state(self, _):
        life, row, _, _ = self.lifecycle(row=payment(provider_create_state='outcome_unknown'))
        life.adapter.status = Mock(return_value=KlikQrisObservation('unknown_status'))
        result = life._work(row['id'])
        self.assertEqual(result.state, 'outcome_unknown')
        life.db.execute.assert_called_once()
        self.assertIn("klikqris_status_last_error='status_interval'", life.db.execute.call_args.args[0])
        life.orders.paid_locked.assert_not_called()

    @patch('lhie_candidate.payments.klikqris.transaction.atomic', side_effect=lambda **_: nullcontext())
    def test_ambiguous_create_recovers_same_payment_without_recreate(self, _):
        life, row, _, _ = self.lifecycle(row=payment(provider_create_state='outcome_unknown'))
        life.adapter.status = Mock(return_value=KlikQrisObservation('status_unavailable'))
        life.adapter.create = Mock()
        self.assertEqual(life._work(row['id'], creating=True).state, 'recovery_unconfirmed')
        life.adapter.create.assert_not_called()
        life.adapter.status.assert_called_once_with(row)

    @patch('lhie_candidate.payments.klikqris.transaction.atomic', side_effect=lambda **_: nullcontext())
    def test_missing_status_is_backed_off_not_cancelled_or_expired(self, _):
        life, row, _, _ = self.lifecycle(row=payment(provider_create_state='outcome_unknown'))
        life.adapter.status = Mock(return_value=KlikQrisObservation('status_unavailable', http_status=404))
        self.assertEqual(life._work(row['id']).state, 'recovery_unconfirmed')
        parameters = life.db.execute.call_args.args[1]
        self.assertEqual(parameters, (1, AT + timedelta(seconds=300), row['id']))
        life.orders._release_locked.assert_not_called()
        life.orders.paid_locked.assert_not_called()

    @patch('lhie_candidate.payments.klikqris.transaction.atomic', side_effect=lambda **_: nullcontext())
    def test_quote_changed_during_io_is_not_applied(self, _):
        life, row, _, _ = self.lifecycle(row=payment(provider_create_state='outcome_unknown'))
        observed = KlikQrisTransaction.parse(provider_payload(status='SUCCESS'))
        def status(_):
            row['amount'] = 9999
            return KlikQrisObservation('observed', observed)
        life.adapter.status = status
        life._work(row['id'])
        life.db.execute.assert_called_once()
        self.assertIn("klikqris_status_last_error='status_interval'", life.db.execute.call_args.args[0])
        life.orders.paid_locked.assert_not_called()

    @patch('lhie_candidate.payments.klikqris.claim_provider_reference')
    def test_late_success_is_claimed_then_canonical_release_and_review(self, claim):
        life, row, root, _ = self.lifecycle(row=payment(provider_reference='klik-receipt-1',
            provider_expires_at=AT - timedelta(seconds=1)))
        claim.return_value = Mock(held=False)
        life.orders.paid_locked.return_value = Mock(review_id=77)
        self.assertEqual(life._settle(root, row, [row]).state, 'review')
        life.orders._release_locked.assert_called_once_with(root, [row], status='expired')
        life.orders.paid_locked.assert_called_once_with(root, row, [row])
        self.assertEqual(claim.call_args.kwargs['provider'], 'klikqris')
        self.assertEqual(claim.call_args.kwargs['reference'], 'klik-receipt-1')

    @patch('lhie_candidate.payments.klikqris.claim_provider_reference')
    def test_receipt_conflict_cannot_mark_paid(self, claim):
        life, row, root, _ = self.lifecycle()
        claim.return_value = Mock(held=True)
        self.assertEqual(life._settle(root, row, [row]).state, 'review')
        life.orders.review_locked.assert_called_once_with(root, row, 'receipt_conflict')
        life.orders.paid_locked.assert_not_called()

    @patch('lhie_candidate.payments.klikqris.transaction.atomic', side_effect=lambda **_: nullcontext())
    def test_cancel_records_unsupported_without_remote_io(self, _):
        life, row, _, transport = self.lifecycle(root=order(order_status='cancelled'))
        self.assertEqual(life.cancel(row['id']).state, 'unsupported')
        sql, values = life.db.execute.call_args.args
        self.assertIn("'unsupported'", sql)
        self.assertIn('ON CONFLICT (payment_id) DO NOTHING', sql)
        self.assertEqual(values[1], row['id'])
        self.assertEqual(transport.calls, [])

    def test_presentation_intersects_deadlines_and_never_exposes_signature(self):
        life, row, root, _ = self.lifecycle()
        facts = KlikQrisTransaction.parse(provider_payload()).facts()
        row.update(provider_total_payment=10016, provider_fee=16, provider_expires_at=AT + timedelta(minutes=15),
                   provider_payment_method='qris', provider_payload=facts,
                   provider_qris_url=self.encrypter.encrypt_string(URL), provider_create_state='created')
        root['expires_at'] = AT + timedelta(minutes=5)
        result = life._result(root, row)
        self.assertEqual(result.state, 'created')
        self.assertEqual(result.presentation['effective_expires_at'], (AT + timedelta(minutes=5)).isoformat())
        self.assertNotIn('signature', result.presentation)
        self.assertEqual((result.total, result.fee), (10016, 16))

    @patch('lhie_candidate.payments.klikqris.transaction.atomic', side_effect=lambda **_: nullcontext())
    def test_throttle_waits_on_same_payment(self, _):
        life, row, _, _ = self.lifecycle(row=payment(
            provider_create_state='outcome_unknown',
            klikqris_status_next_check_at=AT + timedelta(seconds=30)))
        life.adapter.status = Mock()
        self.assertEqual(life._work(row['id']).state, 'status_deferred')
        life.adapter.status.assert_not_called()
        life.db.execute.assert_not_called()

    @patch('lhie_candidate.payments.klikqris.transaction.atomic', side_effect=lambda **_: nullcontext())
    def test_create_429_keeps_uncertainty_and_defers_status(self, _):
        life, row, _, _ = self.lifecycle()
        life.adapter.create = Mock(return_value=KlikQrisObservation('outcome_unknown', http_status=429))
        self.assertEqual(life._work(row['id'], creating=True).state, 'outcome_unknown')
        statements = [call.args[0] for call in life.db.execute.call_args_list]
        self.assertTrue(any('status_http_429' in statement for statement in statements))
        life.orders.paid_locked.assert_not_called()

    @patch('lhie_candidate.payments.klikqris.transaction.atomic', side_effect=lambda **_: nullcontext())
    def test_status_signature_change_never_replaces_signature_or_settles(self, _):
        life, row, _, _ = self.lifecycle(row=self.authenticated_payment(provider_create_state='created'))
        observed = KlikQrisTransaction.parse(provider_payload(status='SUCCESS', signature='rotated-secret'))
        life.adapter.status = Mock(return_value=KlikQrisObservation('observed', observed))
        self.assertEqual(life._work(row['id']).state, 'recovery_unconfirmed')
        life.db.execute.assert_called_once()
        self.assertIn("klikqris_status_last_error='status_interval'", life.db.execute.call_args.args[0])
        life.orders.paid_locked.assert_not_called()

    @patch('lhie_candidate.payments.klikqris.transaction.atomic', side_effect=lambda **_: nullcontext())
    def test_paid_replay_consumes_hint_without_provider_io(self, _):
        life, row, _, transport = self.lifecycle(row=payment(status='paid'))
        self.assertEqual(life._work(row['id'], hint={'id': 41}).state, 'paid')
        self.assertEqual(life.db.execute.call_args.args[1], (AT, 41))
        life.orders.paid_locked.assert_not_called()
        self.assertEqual(transport.calls, [])

    @patch('lhie_candidate.payments.klikqris.transaction.atomic', side_effect=lambda **_: nullcontext())
    def test_pending_callback_is_not_payment_authority(self, _):
        life, row, _, transport = self.lifecycle()
        life.db.one.side_effect = ({'acquired': True}, {'id': 41, 'provider_status': 'PENDING'},
                                   None, {'released': True})
        result = life.consume_callbacks(row['id'])
        self.assertEqual(result.state, 'pending')
        self.assertEqual(transport.calls, [])
        life.orders.paid_locked.assert_not_called()

    def test_payment_single_flight_does_not_dispatch_while_busy(self):
        life, row, _, transport = self.lifecycle()
        life.db.one.return_value = {'acquired': False}
        self.assertEqual(life.refresh(row['id']).state, 'busy')
        life._locked.assert_not_called()
        self.assertEqual(transport.calls, [])

    @patch('lhie_candidate.payments.klikqris.transaction.atomic', side_effect=lambda **_: nullcontext())
    def test_normal_status_reserves_twenty_seconds_before_transport(self, _):
        life, row, _, _ = self.lifecycle(row=payment(provider_create_state='outcome_unknown'))
        def status(current):
            self.assertEqual(current['klikqris_status_next_check_at'], AT + timedelta(seconds=20))
            self.assertEqual(current['klikqris_status_last_error'], 'status_interval')
            self.assertIn('status_interval', life.db.execute.call_args.args[0])
            return KlikQrisObservation('status_unavailable')
        life.adapter.status = Mock(side_effect=status)
        self.assertEqual(life._work(row['id']).state, 'recovery_unconfirmed')
        self.assertEqual(life._work(row['id']).state, 'status_deferred')
        life.adapter.status.assert_called_once()

    @patch('lhie_candidate.payments.klikqris.transaction.atomic', side_effect=lambda **_: nullcontext())
    def test_paid_callback_bypasses_only_normal_status_interval(self, _):
        for reason, dispatched in (('status_interval', True), ('status_http_404', False), ('status_http_429', False)):
            with self.subTest(reason=reason):
                life, row, _, _ = self.lifecycle(row=payment(
                    provider_create_state='outcome_unknown',
                    klikqris_status_next_check_at=AT + timedelta(seconds=20),
                    klikqris_status_last_error=reason))
                life.adapter.status = Mock(return_value=KlikQrisObservation('status_unavailable'))
                result = life._work(row['id'], hint={'id': 41, 'provider_status': 'PAID'})
                self.assertEqual(result.state, 'recovery_unconfirmed' if dispatched else 'status_deferred')
                self.assertEqual(life.adapter.status.call_count, int(dispatched))
