import copy
from dataclasses import replace
from datetime import datetime
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch
from zoneinfo import ZoneInfo

from lhie_candidate.checkout.errors import CheckoutError
from lhie_candidate.payments.bsc import (
    BscInvoice, BscLifecycle, BscRpcClient, BscRpcError, MAX_LOGS, MAX_RESPONSE_BYTES,
    TRANSFER_TOPIC, USDT_CONTRACT, inspect_receipt,
)


TX = '0x' + 'a' * 64
BLOCK = '0x' + 'b' * 64
RECEIVER = '0x' + '1' * 40
SENDER = '0x' + '2' * 40
AT = 1_800_000_000
JAKARTA = ZoneInfo('Asia/Jakarta')


def local_time(epoch):
    return datetime.fromtimestamp(epoch, JAKARTA).replace(tzinfo=None)


def invoice(**changes):
    value = BscInvoice(56, USDT_CONTRACT, RECEIVER, 18, 1_000_000, '1000000000000000000',
                       AT - 60, AT + 600, 3, True, 15, 'transaction_hash')
    return replace(value, **changes)


def block(number, timestamp=AT, block_hash=BLOCK):
    return {'number': hex(number), 'timestamp': hex(timestamp), 'hash': block_hash}


def transfer_log(*, tx_hash=TX, amount=10**18, receiver=RECEIVER, token=USDT_CONTRACT, index=0):
    return {'address': token, 'topics': [TRANSFER_TOPIC, '0x' + '0' * 24 + SENDER[2:],
            '0x' + '0' * 24 + receiver[2:]], 'data': '0x' + format(amount, '064x'),
            'transactionHash': tx_hash, 'blockNumber': '0x64', 'blockHash': BLOCK,
            'logIndex': hex(index), 'removed': False}


def receipt(**changes):
    return {'transactionHash': TX, 'blockNumber': '0x64', 'blockHash': BLOCK,
            'status': '0x1', 'logs': [transfer_log()]} | changes


class BscFixture:
    """Synthetic watch-only adapter usable by PostgreSQL checkout smoke fixtures."""

    def __init__(self):
        self.chain = '0x38'
        self.receipts = {TX: receipt()}
        self.blocks = {'0x64': block(100), 'latest': block(104, AT + 4, '0x' + 'c' * 64),
                       'finalized': block(102, AT + 2, '0x' + 'd' * 64),
                       '0x66': block(102, AT + 2, '0x' + 'd' * 64)}
        self.logs = []
        self.calls = []

    def readiness(self):
        return {'ready': True, 'chain_id': 56, 'token_contract': USDT_CONTRACT,
                'token_decimals': 18, 'transfer_logs': True}

    def call(self, method, params=()):
        self.calls.append((method, params))
        if method == 'eth_chainId':
            return self.chain
        if method == 'eth_getTransactionReceipt':
            return copy.deepcopy(self.receipts.get(params[0]))
        if method == 'eth_getBlockByNumber':
            return copy.deepcopy(self.blocks.get(params[0]))
        if method == 'eth_getLogs':
            return copy.deepcopy(self.logs)
        raise AssertionError(method)


class BscReceiptInspectionTests(unittest.TestCase):
    def inspect(self, *, policy=None, observed=None, canonical=None, head=None, finalized=None, now=AT + 10):
        return inspect_receipt(policy or invoice(), TX, observed or receipt(),
            canonical or block(100), head or block(104, AT + 4, '0x' + 'c' * 64),
            finalized or block(102, AT + 2, '0x' + 'd' * 64), now_epoch=now)

    def test_one_exact_transfer_with_canonical_finality_is_verified(self):
        result = self.inspect()
        self.assertEqual(result.state, 'verified')
        self.assertTrue(result.finality_verified)
        self.assertTrue(result.window_matches)
        self.assertEqual(result.transfer.amount_units, '1000000000000000000')
        self.assertEqual(result.transfer.confirmations, 5)
        self.assertEqual(result.chain_facts['finalized']['number'], 102)

    def test_wrong_token_receiver_and_amount_cannot_be_verified(self):
        for log in (transfer_log(token='0x' + '3' * 40), transfer_log(receiver=SENDER),
                    transfer_log(amount=10**18 + 1), transfer_log(amount=2 * 10**18)):
            with self.subTest(log=log):
                result = self.inspect(observed=receipt(logs=[log]))
                self.assertEqual(result.state, 'review')
                self.assertFalse(result.finality_verified)

    def test_malformed_missing_and_failed_rpc_facts_remain_unresolved(self):
        bad_logs = []
        for key, value in (('removed', True), ('logIndex', None), ('blockHash', None),
                           ('transactionHash', '0x' + 'e' * 64), ('data', '0x1')):
            bad_logs.append(transfer_log() | {key: value})
        bad_logs.append(transfer_log() | {'topics': [TRANSFER_TOPIC, '0x' + 'f' * 64, '0x' + '0' * 24 + RECEIVER[2:]]})
        for observed in (receipt(status='0x0'), receipt(status=None), receipt(blockHash=None),
                         receipt(logs=None), receipt(logs=[transfer_log()] * (MAX_LOGS + 1)),
                         *(receipt(logs=[log]) for log in bad_logs)):
            with self.subTest(observed=observed):
                self.assertEqual(self.inspect(observed=observed).state, 'pending')
        self.assertEqual(inspect_receipt(invoice(), TX, None, None, None, now_epoch=AT).state, 'pending')

    def test_duplicate_or_additional_transfer_is_never_summed_or_ignored(self):
        duplicate = self.inspect(observed=receipt(logs=[transfer_log(), transfer_log()]))
        self.assertEqual(duplicate.state, 'pending')
        multiple = self.inspect(observed=receipt(logs=[transfer_log(), transfer_log(index=1, receiver=SENDER)]))
        self.assertEqual(multiple.state, 'review')

    def test_confirmation_and_frozen_finality_requirements(self):
        result = self.inspect(head=block(101, AT + 1), finalized=block(99, AT - 1))
        self.assertEqual(result.state, 'confirming')
        result = self.inspect(finalized=block(99, AT - 1))
        self.assertEqual(result.state, 'confirming')
        result = inspect_receipt(invoice(), TX, receipt(), block(100), block(104, AT + 4), None,
                                 now_epoch=AT + 10)
        self.assertEqual(result.state, 'confirming')
        self.assertFalse(result.finality_verified)
        result = inspect_receipt(invoice(requires_finalized_block=False), TX, receipt(),
                                 block(100), block(104, AT + 4), None, now_epoch=AT + 10)
        self.assertEqual(result.state, 'verified')

    def test_reorg_or_inconsistent_finalized_hash_is_review(self):
        self.assertEqual(self.inspect(canonical=block(100, block_hash='0x' + 'f' * 64)).state, 'review')
        self.assertEqual(self.inspect(finalized=block(100, block_hash='0x' + 'f' * 64)).state, 'review')
        self.assertEqual(self.inspect(head=block(99)).state, 'review')

    def test_outside_window_money_is_authentic_but_not_invoice_eligible(self):
        for policy in (invoice(expires_at=AT - 1), invoice(starts_at=AT + 1)):
            result = self.inspect(policy=policy)
            self.assertEqual(result.state, 'verified')
            self.assertTrue(result.finality_verified)
            self.assertFalse(result.window_matches)

    def test_future_block_uncertainty_is_pending(self):
        self.assertEqual(self.inspect(canonical=block(100, AT + 20)).state, 'pending')


class BscRpcTransportTests(unittest.TestCase):
    def test_default_is_inactive_and_never_opens_http(self):
        with patch('lhie_candidate.payments.bsc.http.client.HTTPSConnection') as connect:
            client = BscRpcClient()
            self.assertEqual(client.readiness(), {'ready': False})
            with self.assertRaises(BscRpcError):
                client.call('eth_chainId')
            connect.assert_not_called()

    def test_injected_envelope_and_bounded_transport(self):
        transport = Mock(side_effect=lambda endpoint, payload, **bounds:
                         {'jsonrpc': '2.0', 'id': payload['id'], 'result': '0x38'})
        client = BscRpcClient(transport, endpoint='https://synthetic.invalid/rpc')
        self.assertEqual(client.call('eth_chainId'), '0x38')
        self.assertEqual(transport.call_args.kwargs, {'timeout': 5, 'max_response_bytes': MAX_RESPONSE_BYTES})
        with self.assertRaises(BscRpcError):
            client.call('eth_sendRawTransaction', ['not-a-private-key'])
        self.assertEqual(transport.call_count, 1)

    def test_envelope_errors_duplicates_and_oversized_data_are_unresolved(self):
        for response in ({'jsonrpc': '2.0', 'id': 99, 'result': '0x38'},
                         {'jsonrpc': '2.0', 'id': True, 'result': '0x38'},
                         {'jsonrpc': '2.0', 'id': 1, 'error': {}},
                         b'{"jsonrpc":"2.0","id":1,"result":"0x38","result":"0x1"}',
                         b' ' * (MAX_RESPONSE_BYTES + 1), []):
            with self.subTest(response_type=type(response)):
                with self.assertRaises(BscRpcError):
                    BscRpcClient(Mock(return_value=response)).call('eth_chainId')

    def test_readiness_requires_chain_code_decimals_and_log_probe(self):
        values = {'eth_chainId': '0x38', 'eth_getCode': '0x6080',
                  'eth_call': '0x' + format(18, '064x'),
                  'eth_getBlockByNumber': block(104), 'eth_getLogs': []}
        def transport(endpoint, payload, **bounds):
            return {'jsonrpc': '2.0', 'id': payload['id'], 'result': values[payload['method']]}
        client = BscRpcClient(transport)
        self.assertTrue(client.readiness()['ready'])
        for key, value in (('eth_chainId', '0x1'), ('eth_getCode', '0x'),
                           ('eth_call', '0x' + format(6, '064x')), ('eth_getLogs', None)):
            original = values[key]
            values[key] = value
            self.assertFalse(client.readiness()['ready'])
            values[key] = original


class BscLifecycleConsumerTests(unittest.TestCase):
    def setUp(self):
        self.adapter = BscFixture()
        self.service = BscLifecycle(self.adapter, clock=lambda: local_time(AT + 10))
        self.order = {'id': 'order', 'customer_id': 7, 'order_status': 'pending_payment',
                      'payment_status': 'pending', 'expires_at': local_time(AT + 600)}
        self.payment = {'id': 'payment', 'order_id': 'order', 'provider': 'manual', 'method': 'USDT_BEP20',
                        'currency': 'USDT', 'amount': 1_000_000, 'created_at': local_time(AT - 60),
                        'expired_at': local_time(AT + 600), 'status': 'pending', 'merchant_ref': 'merchant'}
        self.detail = {'receiver_id': 1, 'receiver_address': RECEIVER, 'chain_id': 56,
                       'token_contract': USDT_CONTRACT, 'token_decimals': 18, 'base_amount_micro': 1_000_000,
                       'payable_amount_micro': 1_000_000, 'expected_token_units': '1000000000000000000',
                       'required_confirmations': 3, 'requires_finalized_block': True,
                       'confirmation_grace_minutes': 15, 'verification_mode': 'transaction_hash',
                       'current_tx_hash': TX, 'tx_submission_version': 1, 'tx_attempts': 1,
                       'verification_grace_until': None, 'verification_status': 'tx_submitted',
                       'current_confirmations': None}
        self.service._outside = Mock()
        self.service._locked = Mock(side_effect=lambda payment_id:
            (self.order, self.payment, [self.payment], self.detail))
        self.service.orders = Mock()
        self.service.orders._run.side_effect = lambda operation: operation()
        self.service.orders._open_review.return_value = None
        self.service.orders.paid_locked.return_value = SimpleNamespace(review_id=None)
        self.service.db = Mock()
        self.service.db.one.return_value = None
        self.service._event = Mock(return_value={'reason': 'synthetic'})
        self.service._persist_evidence = Mock(return_value=True)

    def test_verified_money_is_claimed_before_shared_order_settlement(self):
        order_of_calls = []
        self.service.orders.paid_locked.side_effect = lambda *args: (
            order_of_calls.append('paid') or SimpleNamespace(review_id=None))
        with patch('lhie_candidate.payments.bsc.claim_bsc_payment', side_effect=lambda *args, **kwargs:
                   order_of_calls.append('claim') or SimpleNamespace(allowed=True)) as claim:
            self.assertEqual(self.service.refresh('payment').state, 'paid')
        self.assertEqual(order_of_calls, ['claim', 'paid'])
        self.assertEqual(claim.call_args.kwargs['tx_hash'], TX)
        self.assertEqual(claim.call_args.kwargs['log_index'], 0)
        self.assertEqual(claim.call_args.kwargs['receiver'], RECEIVER)
        self.assertEqual(claim.call_args.kwargs['amount_units'], '1000000000000000000')

    def test_wrong_network_outage_or_missing_receipt_never_claims_or_cancels(self):
        for mode in ('network', 'outage', 'missing'):
            with self.subTest(mode=mode), patch('lhie_candidate.payments.bsc.claim_bsc_payment') as claim:
                self.adapter = BscFixture()
                if mode == 'network':
                    self.adapter.chain = '0x1'
                elif mode == 'outage':
                    self.adapter.call = Mock(side_effect=TimeoutError())
                else:
                    self.adapter.receipts.clear()
                self.service.adapter = self.adapter
                self.assertEqual(self.service.refresh('payment').state, 'pending')
                claim.assert_not_called()
        self.service.orders.paid_locked.assert_not_called()
        self.service.orders._release_locked.assert_not_called()

    def test_on_time_confirming_evidence_gets_only_frozen_expiry_grace(self):
        self.adapter.blocks['finalized'] = None
        with patch('lhie_candidate.payments.bsc.claim_bsc_payment') as claim:
            self.assertEqual(self.service.refresh('payment').state, 'pending')
            claim.assert_not_called()
        self.assertEqual(self.detail['verification_grace_until'], local_time(AT + 1500))
        self.assertEqual(self.service._persist_evidence.call_args.args[2], 'confirming')
        self.service.orders.paid_locked.assert_not_called()

    def test_late_or_grace_expired_money_is_review_without_fulfillment(self):
        for mode in ('late', 'grace_expired'):
            self.setUp()
            if mode == 'late':
                self.order['expires_at'] = self.payment['expired_at'] = local_time(AT - 1)
            else:
                self.detail['verification_grace_until'] = local_time(AT + 5)
            with self.subTest(mode=mode), patch('lhie_candidate.payments.bsc.claim_bsc_payment', return_value=SimpleNamespace(allowed=True)):
                self.assertEqual(self.service.refresh('payment').state, 'review')
            self.service.orders.paid_locked.assert_not_called()
            self.service.orders.review_locked.assert_called()

    def test_terminal_verified_money_uses_shared_late_money_path(self):
        self.order['order_status'] = 'cancelled'
        self.payment['status'] = 'failed'
        with patch('lhie_candidate.payments.bsc.claim_bsc_payment', return_value=SimpleNamespace(allowed=True)):
            self.assertEqual(self.service.refresh('payment').state, 'review')
        self.service.orders.paid_locked.assert_called_once()

    def test_held_log_or_parent_claim_prevents_settlement(self):
        with patch('lhie_candidate.payments.bsc.claim_bsc_payment', return_value=SimpleNamespace(allowed=False)):
            self.assertEqual(self.service.refresh('payment').state, 'review')
        self.service.orders.paid_locked.assert_not_called()
        self.service.orders.review_locked.assert_called()

    def test_existing_review_never_automatically_fulfills_new_evidence(self):
        self.service.orders._open_review.return_value = {'id': 1}
        with patch('lhie_candidate.payments.bsc.claim_bsc_payment', return_value=SimpleNamespace(allowed=True)):
            self.assertEqual(self.service.refresh('payment').state, 'review')
        self.service.orders.paid_locked.assert_not_called()

    def test_receiver_rotation_does_not_change_frozen_verification_target(self):
        self.service.db.one.return_value = {'address_normalized': SENDER}
        with patch('lhie_candidate.payments.bsc.claim_bsc_payment', return_value=SimpleNamespace(allowed=True)) as claim:
            self.assertEqual(self.service.refresh('payment').state, 'paid')
        self.assertEqual(claim.call_args.kwargs['receiver'], RECEIVER)
        self.assertEqual(self.detail['receiver_address'], RECEIVER)

    def test_submission_ownership_and_hint_only_behavior(self):
        self.detail['current_tx_hash'] = None
        with self.assertRaises(CheckoutError):
            self.service.submit_hash('payment', TX, customer_id=99)
        self.assertEqual(self.service.submit_hash('payment', TX.upper().replace('0X', '0x'), customer_id=7).state, 'accepted')
        self.assertEqual(self.detail['tx_submission_version'], 2)
        self.assertEqual(self.service.submit_hash('payment', TX, customer_id=7).state, 'accepted')
        self.assertEqual(self.detail['tx_submission_version'], 2)
        self.assertEqual(self.adapter.calls, [])
        self.service.orders.paid_locked.assert_not_called()

    def test_submission_changed_during_network_read_is_fenced(self):
        original = self.adapter.call
        def call(method, params=()):
            result = original(method, params)
            if method == 'eth_getTransactionReceipt':
                self.detail['tx_submission_version'] += 1
            return result
        self.adapter.call = call
        with patch('lhie_candidate.payments.bsc.claim_bsc_payment') as claim:
            self.assertEqual(self.service.refresh('payment').state, 'stale_submission')
            claim.assert_not_called()
        self.service._event.assert_not_called()
        self.service._persist_evidence.assert_not_called()

    def test_amount_unique_logs_discover_hash_but_receipt_remains_authoritative(self):
        self.detail.update(verification_mode='amount_unique', current_tx_hash=None)
        self.adapter.logs = [transfer_log()]
        self.adapter.receipts[TX]['logs'][0] = transfer_log(amount=10**18 + 1)
        with patch('lhie_candidate.payments.bsc.claim_bsc_payment') as claim:
            self.assertEqual(self.service.refresh('payment').state, 'review')
            claim.assert_not_called()
        self.assertIn('eth_getTransactionReceipt', [method for method, _ in self.adapter.calls])
        discovery = next(params[0] for method, params in self.adapter.calls if method == 'eth_getLogs')
        self.assertEqual(discovery['address'], USDT_CONTRACT)
        self.assertEqual(discovery['topics'][2], '0x' + '0' * 24 + RECEIVER[2:])

    def test_amount_unique_discovery_is_bounded_and_ambiguous_money_reviewed(self):
        self.detail.update(verification_mode='amount_unique', current_tx_hash=None)
        self.service.clock = lambda: local_time(AT + 100_000)
        self.assertEqual(self.service.refresh('payment').state, 'pending')
        self.assertNotIn('eth_getLogs', [method for method, _ in self.adapter.calls])
        self.service.clock = lambda: local_time(AT + 10)
        self.adapter.logs = [transfer_log(), transfer_log(tx_hash='0x' + 'e' * 64, index=1)]
        self.assertEqual(self.service.refresh('payment').state, 'review')
        self.service.orders.paid_locked.assert_not_called()

    def test_discovery_exhaustion_stays_pending_without_unbounded_rpc(self):
        self.detail.update(verification_mode='amount_unique', current_tx_hash=None)
        self.service.clock = lambda: local_time(AT + 22_000)
        self.adapter.blocks['latest'] = block(1_000_000, AT + 21_999)
        with patch('lhie_candidate.payments.bsc.claim_bsc_payment') as claim:
            self.assertEqual(self.service.refresh('payment').state, 'pending')
        self.assertLessEqual(len(self.adapter.calls), 64)
        claim.assert_not_called()
        self.service.orders.paid_locked.assert_not_called()

    def test_snapshot_rejects_wrong_network_token_units_or_confirmation_policy(self):
        for key, value in (('chain_id', 1), ('token_contract', SENDER), ('token_decimals', 6),
                           ('expected_token_units', '1000000000000000001'), ('required_confirmations', 2)):
            original = self.detail[key]
            self.detail[key] = value
            with self.subTest(key=key), self.assertRaises(ValueError):
                BscInvoice.from_rows(self.order, self.payment, self.detail)
            self.detail[key] = original


    def test_expired_invoice_does_not_present_fresh_payment_instructions(self):
        self.service.clock = lambda: local_time(AT + 601)
        self.assertEqual(self.service.classify('payment').state, 'pending')
        self.assertIsNone(self.service.classify('payment').presentation)
        self.assertEqual(self.adapter.calls, [])

    def test_rpc_observation_runs_after_snapshot_transaction_and_before_settlement(self):
        active = False
        original_call = self.adapter.call
        def run(operation):
            nonlocal active
            self.assertFalse(active)
            active = True
            try:
                return operation()
            finally:
                active = False
        def call(method, params=()):
            self.assertFalse(active)
            return original_call(method, params)
        self.service.orders._run.side_effect = run
        self.adapter.call = call
        with patch('lhie_candidate.payments.bsc.claim_bsc_payment', return_value=SimpleNamespace(allowed=True)):
            self.assertEqual(self.service.refresh('payment').state, 'paid')

    def test_finalized_tag_must_match_its_canonical_numeric_block(self):
        self.adapter.blocks['0x66']['hash'] = '0x' + 'e' * 64
        with patch('lhie_candidate.payments.bsc.claim_bsc_payment') as claim:
            self.assertEqual(self.service.refresh('payment').state, 'pending')
            claim.assert_not_called()
        self.assertEqual(self.detail['verification_grace_until'], local_time(AT + 1500))
