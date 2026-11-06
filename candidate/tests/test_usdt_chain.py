import hashlib
import json
from datetime import timedelta
from contextlib import nullcontext
from copy import deepcopy
import unittest
from unittest.mock import patch

from lhie_candidate.payments.bsc import USDT_CONTRACT
from lhie_candidate.usdt.chain import BscFundingRail
from tests.test_bsc import AT, BLOCK, RECEIVER, TX, BscFixture, block, local_time, receipt, transfer_log


class Rpc(BscFixture):
    def call(self, method, params=()):
        if method == 'eth_call':
            return '0x' + format(18, '064x')
        return super().call(method, params)


class InspectionDatabase:
    def __init__(self):
        self.invoice = dict(customer_id=7, receiver_id=9, receiver_address=RECEIVER,
            chain_id=56, token_contract=USDT_CONTRACT, token_decimals=18,
            custody_namespace='operator', requires_finalized_block=True, minimum_confirmations=3,
            payable_micro=1_000_000, starts_at_epoch=AT-60, expires_at_epoch=AT+60)
        self.receiver = dict(id=9, customer_id=7, address_normalized=RECEIVER, chain_id=56,
            token_contract=USDT_CONTRACT, token_decimals=18, custody_namespace='operator',
            verified_at=local_time(AT-100), retired_at=local_time(AT-1))
        self.review = None

    def one(self, sql, params=()):
        if 'usdt_bsc_rail_invoices' in sql:
            return self.invoice
        if 'customer_usdt_deposit_receivers' in sql:
            return self.receiver
        if 'usdt_bsc_scan_checkpoints' in sql:
            return {'review_reason': self.review}
        raise AssertionError(sql)


class BscFundingReplayTests(unittest.TestCase):
    def test_replay_uses_immutable_invoice_without_receiver_or_rpc(self):
        deadline = local_time(AT+60)
        payload = {'v': 1, 'customer': 7, 'reference': 'funding:7', 'currency': 'USDT',
                   'amount_micro': '1000000', 'expires_at_epoch': AT+60}
        fingerprint = hashlib.sha256(json.dumps(payload, separators=(',', ':')).encode()).hexdigest()
        invoice = {'customer_id': 7, 'receiver_id': 9, 'request_fingerprint': fingerprint}
        db = InspectionDatabase()
        db.invoice = invoice
        rail = BscFundingRail(clock=lambda: deadline + timedelta(days=1))
        rail.db = db
        rail._outside = lambda: None
        self.assertIs(rail.create_or_recover(7, 'funding:7', 1_000_000, deadline, receiver_id=9), invoice)
        with self.assertRaises(ValueError):
            rail.create_or_recover(7, 'funding:7', 2_000_000, deadline, receiver_id=9)


class BscFundingInspectionTests(unittest.TestCase):
    def setUp(self):
        self.rpc = Rpc()
        self.rail = BscFundingRail(self.rpc, clock=lambda: local_time(AT+10))
        self.rail.db = InspectionDatabase()
        self.rail._outside = lambda: None

    def inspect(self):
        return self.rail.inspect(7, 'funding:7', TX)

    def test_historical_retired_receiver_and_canonical_finality(self):
        self.assertEqual(self.inspect().reason, 'canonical_final_transfer')
        self.rpc.blocks['0x66'] = block(102, AT+2, '0x'+'f'*64)
        self.assertEqual(self.inspect().reason, 'finalized_block_not_canonical')
        self.rpc.blocks['0x66'] = None
        self.assertEqual(self.inspect().reason, 'canonical_finality_unavailable')

    def test_exact_amount_review_requires_finality_and_representable_value(self):
        self.rpc.receipts[TX] = receipt(logs=[transfer_log(amount=2*10**18)])
        result = self.inspect()
        self.assertEqual(result.reason, 'canonical_final_amount_mismatch')
        self.assertTrue(result.finality_verified)
        self.rpc.blocks['finalized'] = None
        self.assertNotEqual(self.inspect().reason, 'canonical_final_amount_mismatch')
        self.rpc.blocks['finalized'] = block(102, AT+2, '0x'+'d'*64)
        self.rpc.receipts[TX] = receipt(logs=[transfer_log(amount=10**18+1)])
        self.assertEqual(self.inspect().reason, 'amount_not_representable')

    def test_wrong_receiver_multiple_logs_and_receiver_review_never_verify(self):
        self.rpc.receipts[TX] = receipt(logs=[transfer_log(receiver='0x'+'2'*40)])
        self.assertEqual(self.inspect().state, 'review')
        self.rpc.receipts[TX] = receipt(logs=[transfer_log(), transfer_log(index=1)])
        self.assertEqual(self.inspect().state, 'review')
        self.rail.db.review = 'checkpoint_reorg'
        self.assertEqual(self.inspect().reason, 'receiver_ingestion_on_review')


class ScanDatabase:
    def __init__(self):
        self.receiver = dict(id=9, customer_id=7, address_normalized=RECEIVER, chain_id=56,
            token_contract=USDT_CONTRACT, token_decimals=18, custody_namespace='operator',
            verified_at=local_time(AT), retired_at=local_time(AT+1))
        self.checkpoint = None
        self.observations = []
        self.evidence = []
        self.frozen_reason = None

    def require_transaction(self):
        pass

    def one(self, sql, params=()):
        if 'FROM public.customers' in sql:
            return {'id': 7}
        if 'FROM public.customer_usdt_balance_accounts' in sql:
            return {'id': 8}
        if 'FROM public.customer_usdt_top_up_resolutions' in sql:
            return {'id': 'resolution'}
        if 'customer_usdt_deposit_receivers' in sql:
            return deepcopy(self.receiver)
        if 'usdt_bsc_scan_checkpoints' in sql:
            return deepcopy(self.checkpoint)
        raise AssertionError(sql)

    def execute(self, sql, params=()):
        if 'INSERT INTO public.usdt_bsc_scan_checkpoints' in sql:
            if self.checkpoint is None:
                self.checkpoint = dict(receiver_id=params[0], first_block=params[1], last_block=None,
                    last_block_hash=None, review_reason=None, version=0)
        elif 'INSERT INTO public.usdt_bsc_transfer_observations' in sql:
            self.observations.append(params)
        elif 'INSERT INTO public.usdt_reorg_receiver_evidence' in sql:
            self.evidence.append(params)
        elif 'UPDATE public.customer_usdt_balance_accounts' in sql:
            self.frozen_reason = params[0]
        elif 'UPDATE public.usdt_bsc_scan_checkpoints SET review_reason' in sql:
            self.checkpoint.update(review_reason=params[0], version=self.checkpoint['version']+1)
        elif 'UPDATE public.usdt_bsc_scan_checkpoints' in sql:
            self.checkpoint.update(last_block=params[0], last_block_hash=params[1],
                                   version=self.checkpoint['version']+1)
        else:
            raise AssertionError(sql)


class ScanRpc:
    def __init__(self):
        self.malformed_second = True
        self.reorg = False

    def call(self, method, params=()):
        if method == 'eth_chainId':
            return '0x38'
        if method == 'eth_call':
            return '0x'+format(18, '064x')
        if method == 'eth_getBlockByNumber':
            number = {'latest': 1003, 'finalized': 1001}.get(params[0])
            number = int(params[0], 16) if number is None else number
            h = '0x'+('f' if self.reorg and number == 999 else 'b')*64
            return block(number, AT, h)
        if method == 'eth_getLogs':
            start = int(params[0]['fromBlock'], 16)
            if start == 0:
                return [transfer_log() | {'blockNumber': hex(100), 'blockHash': BLOCK}]
            return [{'bad': True}] if self.malformed_second else []
        raise AssertionError(method)


class BscFundingScanTests(unittest.TestCase):
    def test_incomplete_later_page_preserves_completed_checkpoint_and_reorg_evidence(self):
        rpc, db = ScanRpc(), ScanDatabase()
        rail = BscFundingRail(rpc, clock=lambda: local_time(AT+10))
        rail._outside = lambda: None
        rail.db = db
        with patch('lhie_candidate.usdt.chain.transaction.atomic', return_value=nullcontext()):
            result = rail.scan(9, 0, max_pages=2)
            self.assertEqual((result['reason'], result['last_block']), ('log_page_identity_mismatch', 999))
            self.assertEqual(len(db.observations), 1)
            rpc.reorg = True
            result = rail.scan(9, 0)
            self.assertEqual(result['reason'], 'checkpoint_reorg')
            self.assertEqual(db.checkpoint['review_reason'], 'checkpoint_reorg')
            self.assertEqual(db.frozen_reason, 'usdt_reorg_receiver:9')
            self.assertEqual(db.evidence[0][2], 'checkpoint_reorg')
            self.assertEqual(db.checkpoint['last_block'], 999)
            self.assertEqual(len(db.observations), 1)
