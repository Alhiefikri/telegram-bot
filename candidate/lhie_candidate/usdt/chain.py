"""Watch-only BSC funding invoices and bounded, durable transfer discovery."""
import hashlib
import json
import re
from dataclasses import replace
from datetime import datetime
from uuid import uuid4

from django.db import connections, transaction

from ..checkout.db import Database
from ..domain.money import MAX_UNITS
from ..payments.bsc import (BscInvoice, BscObservation, BscRpcClient, BscRpcError,
    CHAIN_ID, MAX_LOGS, TRANSFER_TOPIC, USDT_CONTRACT, _address, _block, _epoch,
    _now, _quantity, decode_transfer, inspect_receipt, normalize_hash)


class BscFundingRail:
    def __init__(self, adapter=None, *, using='default', clock=None):
        self.adapter = adapter if adapter is not None else BscRpcClient()
        self.using = using
        self.db = Database(using)
        self.clock = clock or _now

    def _outside(self):
        if connections[self.using].in_atomic_block:
            raise RuntimeError('BSC RPC cannot run inside a transaction')

    def _receiver(self, customer_id, receiver_id=None, *, lock=False):
        return self.db.one('''SELECT r.* FROM public.customer_usdt_deposit_receivers r
            JOIN public.usdt_receiver_address_claims c ON c.id=r.address_claim_id
            WHERE r.customer_id=%s AND (%s IS NULL OR r.id=%s)
              AND r.retired_at IS NULL AND r.verified_at IS NOT NULL
              AND r.verification_status IN ('verified','manual_verified') AND c.channel='customer_deposit'
              AND c.chain_id=r.chain_id AND c.address_normalized=r.address_normalized
            ORDER BY r.id LIMIT 1''' + (' FOR UPDATE OF r' if lock else ''),
            (customer_id, receiver_id, receiver_id))

    @staticmethod
    def _valid_receiver(receiver):
        return (receiver is not None and receiver['chain_id'] == CHAIN_ID
                and receiver['token_contract'] == USDT_CONTRACT
                and receiver['token_decimals'] == 18
                and _address(receiver['address_normalized']) == receiver['address_normalized']
                and bool(receiver['custody_namespace']))

    def create_or_recover(self, customer_id, reference, amount_micro, deadline, *, receiver_id=None,
                          target_fingerprint=None, minimum_confirmations=None):
        self._outside()
        if (type(customer_id) is not int or customer_id < 1 or type(reference) is not str
                or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9:_-]{0,95}', reference)
                or type(amount_micro) is not int or not 0 < amount_micro <= MAX_UNITS
                or not isinstance(deadline, datetime)):
            raise ValueError('Invalid BSC invoice request')
        expiry = _epoch(deadline)
        payload = {'v': 1, 'customer': customer_id, 'reference': reference, 'currency': 'USDT',
                   'amount_micro': str(amount_micro), 'expires_at_epoch': expiry}
        if target_fingerprint is not None:
            payload['target_fingerprint'] = target_fingerprint
        fingerprint = hashlib.sha256(json.dumps(payload, separators=(',', ':'), ensure_ascii=False).encode()).hexdigest()

        def replay(row):
            if row['customer_id'] != customer_id or row['request_fingerprint'] != fingerprint:
                raise ValueError('BSC invoice reference conflict')
            if receiver_id is not None and row['receiver_id'] != receiver_id:
                raise ValueError('BSC receiver changed')
            return row

        existing = self.db.one('SELECT * FROM public.usdt_bsc_rail_invoices WHERE reference=%s', (reference,))
        if existing:
            return replay(existing)
        now = self.clock()
        if expiry <= _epoch(now):
            raise ValueError('BSC invoice deadline elapsed')
        receiver = self._receiver(customer_id, receiver_id)
        if not self._valid_receiver(receiver):
            raise ValueError('Verified BSC receiver unavailable')
        if not self.adapter.readiness().get('ready'):
            raise BscRpcError('BSC capabilities unavailable')
        head = _block(self.adapter.call('eth_getBlockByNumber', ['latest', False]))
        setting = self.db.one('''SELECT bsc_min_confirmations FROM public.manual_payment_settings
            WHERE is_active=true ORDER BY id LIMIT 1''')
        confirmations = minimum_confirmations if minimum_confirmations is not None else (setting or {}).get('bsc_min_confirmations')
        if head is None or setting is None or type(confirmations) is not int or not 3 <= confirmations <= 100:
            raise ValueError('BSC finality policy unavailable')
        with transaction.atomic(using=self.using):
            self.db.require_transaction()
            # The legacy receiver-ownership trigger resolves its relations by name.
            self.db.execute('SET LOCAL search_path = pg_catalog, public, pg_temp')
            customer = self.db.one('SELECT id FROM public.customers WHERE id=%s FOR UPDATE', (customer_id,))
            if customer is None:
                raise ValueError('Customer unavailable')
            existing = self.db.one('SELECT * FROM public.usdt_bsc_rail_invoices WHERE reference=%s', (reference,))
            if existing:
                return replay(existing)
            if expiry <= _epoch(self.clock()):
                raise ValueError('BSC invoice deadline elapsed')
            locked = self._receiver(customer_id, receiver['id'], lock=True)
            if locked != receiver:
                raise ValueError('BSC receiver changed during preparation')
            overlap = self.db.one('''SELECT i.id FROM public.usdt_bsc_rail_invoices i
                WHERE i.receiver_id=%s AND i.expires_at_epoch >= %s AND NOT EXISTS
                (SELECT 1 FROM public.customer_usdt_top_ups t JOIN public.customer_usdt_top_up_resolutions r
                 ON r.top_up_id=t.id WHERE t.reference=i.reference) LIMIT 1''', (receiver['id'], _epoch(now)))
            if overlap:
                raise ValueError('Overlapping BSC invoice')
            start = max(0, head[0] - 12)
            self.db.execute('''INSERT INTO public.usdt_bsc_scan_checkpoints
                (receiver_id,first_block,version,updated_at) VALUES (%s,%s,0,%s)
                ON CONFLICT (receiver_id) DO NOTHING''', (receiver['id'], start, now))
            checkpoint = self.db.one('SELECT * FROM public.usdt_bsc_scan_checkpoints WHERE receiver_id=%s FOR UPDATE', (receiver['id'],))
            if checkpoint['review_reason'] is not None:
                raise ValueError('BSC receiver ingestion on review')
            return self.db.one('''INSERT INTO public.usdt_bsc_rail_invoices
                (id,reference,customer_id,receiver_id,request_fingerprint,currency,amount_micro,fee_micro,
                 payable_micro,chain_id,token_contract,token_decimals,receiver_address,custody_namespace,
                 minimum_confirmations,requires_finalized_block,start_block,starts_at_epoch,expires_at_epoch,created_at)
                VALUES (%s,%s,%s,%s,%s,'USDT',%s,0,%s,56,%s,18,%s,%s,%s,true,%s,%s,%s,%s) RETURNING *''',
                (str(uuid4()), reference, customer_id, receiver['id'], fingerprint, amount_micro, amount_micro,
                 USDT_CONTRACT, receiver['address_normalized'], receiver['custody_namespace'], confirmations,
                 start, _epoch(now), expiry, now))

    def inspect(self, customer_id, reference, tx_hash, *, allow_late=False):
        self._outside()
        invoice = self.db.one('SELECT * FROM public.usdt_bsc_rail_invoices WHERE reference=%s', (reference,))
        if invoice is None or invoice['customer_id'] != customer_id:
            raise ValueError('BSC invoice unavailable')
        normalized = normalize_hash(tx_hash)
        if normalized is None or len(tx_hash) != 66:
            return BscObservation('review', 'transaction_hash_invalid')
        receiver = self.db.one('SELECT * FROM public.customer_usdt_deposit_receivers WHERE id=%s', (invoice['receiver_id'],))
        if (receiver is None or receiver['customer_id'] != customer_id or receiver['verified_at'] is None
                or any(receiver[k] != invoice[k] for k in ('chain_id', 'token_contract', 'token_decimals', 'custody_namespace'))
                or receiver['address_normalized'] != invoice['receiver_address']):
            return BscObservation('review', 'receiver_binding_changed')
        if (invoice['chain_id'] != CHAIN_ID or invoice['token_contract'] != USDT_CONTRACT
                or invoice['token_decimals'] != 18 or not invoice['requires_finalized_block']
                or not 3 <= invoice['minimum_confirmations'] <= 100):
            return BscObservation('review', 'invoice_network_or_policy_invalid')
        checkpoint = self.db.one('SELECT review_reason FROM public.usdt_bsc_scan_checkpoints WHERE receiver_id=%s', (receiver['id'],))
        if checkpoint is None or checkpoint['review_reason'] is not None:
            return BscObservation('review', 'receiver_ingestion_on_review')
        try:
            if _quantity(self.adapter.call('eth_chainId')) != CHAIN_ID:
                return BscObservation('review', 'rpc_network_or_token_mismatch')
            decimals = self.adapter.call('eth_call', [{'to': USDT_CONTRACT, 'data': '0x313ce567'}, 'latest'])
            if not isinstance(decimals, str) or re.fullmatch(r'0x[0-9a-fA-F]{64}', decimals) is None or int(decimals, 16) != 18:
                return BscObservation('review', 'rpc_network_or_token_mismatch')
            receipt = self.adapter.call('eth_getTransactionReceipt', [normalized])
            if receipt is None:
                return BscObservation('pending', 'receipt_missing')
            number = _quantity(receipt.get('blockNumber')) if isinstance(receipt, dict) else None
            if number is None:
                return BscObservation('review', 'receipt_identity_malformed')
            block = self.adapter.call('eth_getBlockByNumber', [hex(number), False])
            head = self.adapter.call('eth_getBlockByNumber', ['latest', False])
            final = self.adapter.call('eth_getBlockByNumber', ['finalized', False])
            final_facts = _block(final)
            if final_facts is None:
                return BscObservation('pending', 'finality_unavailable')
            canonical_final = _block(self.adapter.call('eth_getBlockByNumber', [hex(final_facts[0]), False]))
            if canonical_final is None:
                return BscObservation('pending', 'canonical_finality_unavailable')
            if canonical_final != final_facts:
                return BscObservation('review', 'finalized_block_not_canonical')
            policy = BscInvoice(CHAIN_ID, USDT_CONTRACT, invoice['receiver_address'], 18,
                invoice['payable_micro'], str(invoice['payable_micro'] * 10**12), invoice['starts_at_epoch'],
                2**63-1 if allow_late else invoice['expires_at_epoch'], invoice['minimum_confirmations'], True, 0, 'transaction_hash')
            result = inspect_receipt(policy, normalized, receipt, block, head, final, now_epoch=_epoch(self.clock()))
            if result.reason == 'amount_mismatch' and result.transfer:
                units = int(result.transfer.amount_units)
                if units <= 0 or units % 10**12 or units // 10**12 > MAX_UNITS:
                    return BscObservation('review', 'amount_not_representable')
                result = inspect_receipt(replace(policy, amount_units=str(units)), normalized, receipt, block, head, final, now_epoch=_epoch(self.clock()))
                if result.state == 'verified' and result.window_matches:
                    return replace(result, state='review', reason='canonical_final_amount_mismatch')
            if result.state == 'verified' and not result.window_matches:
                return replace(result, state='review', reason='outside_invoice_window')
            if result.reason in ('receipt_failed_or_malformed', 'receipt_identity_malformed',
                    'receipt_logs_malformed_or_oversized', 'log_malformed', 'log_identity_or_encoding_malformed'):
                return replace(result, state='review')
            return result
        except BscRpcError:
            return BscObservation('pending', 'rpc_unavailable')

    def inspect_return(self, customer_id, reference, tx_hash, destination, amount_micro, starts_at):
        """Verify a distinct outgoing transfer; authorization remains with the caller."""
        self._outside()
        invoice = self.db.one('SELECT * FROM public.usdt_bsc_rail_invoices WHERE reference=%s', (reference,))
        normalized, address = normalize_hash(tx_hash), _address(destination)
        if (not invoice or invoice['customer_id'] != customer_id or normalized is None
                or address is None or address == invoice['receiver_address']
                or type(amount_micro) is not int or not 0 < amount_micro <= MAX_UNITS
                or type(starts_at) is not int or starts_at < invoice['starts_at_epoch']
                or invoice['chain_id'] != CHAIN_ID or invoice['token_contract'] != USDT_CONTRACT
                or invoice['token_decimals'] != 18 or not invoice['requires_finalized_block']):
            return BscObservation('review', 'invalid_return_snapshot')
        try:
            if _quantity(self.adapter.call('eth_chainId')) != CHAIN_ID:
                return BscObservation('review', 'rpc_network_or_token_mismatch')
            decimals = self.adapter.call('eth_call', [{'to': USDT_CONTRACT, 'data': '0x313ce567'}, 'latest'])
            if not isinstance(decimals, str) or re.fullmatch(r'0x[0-9a-fA-F]{64}', decimals) is None or int(decimals, 16) != 18:
                return BscObservation('review', 'rpc_network_or_token_mismatch')
            receipt = self.adapter.call('eth_getTransactionReceipt', [normalized])
            number = _quantity(receipt.get('blockNumber')) if isinstance(receipt, dict) else None
            if number is None:
                return BscObservation('pending', 'receipt_missing')
            block = self.adapter.call('eth_getBlockByNumber', [hex(number), False])
            head = self.adapter.call('eth_getBlockByNumber', ['latest', False])
            final = self.adapter.call('eth_getBlockByNumber', ['finalized', False])
            final_facts = _block(final)
            if final_facts is None or _block(self.adapter.call('eth_getBlockByNumber', [hex(final_facts[0]), False])) != final_facts:
                return BscObservation('pending', 'canonical_finality_unavailable')
            policy = BscInvoice(CHAIN_ID, USDT_CONTRACT, address, 18, amount_micro,
                str(amount_micro * 10**12), starts_at, 2**63-1, invoice['minimum_confirmations'],
                True, 0, 'transaction_hash')
            result = inspect_receipt(policy, normalized, receipt, block, head, final, now_epoch=_epoch(self.clock()))
            if result.state != 'verified' or not result.window_matches or not result.transfer or result.transfer.sender != invoice['receiver_address']:
                return BscObservation('review', 'return_not_verified')
            return result
        except BscRpcError:
            return BscObservation('pending', 'rpc_unavailable')

    def _hold(self, receiver, version, reason, evidence):
        now = self.clock()
        with transaction.atomic(using=self.using):
            self.db.require_transaction()
            self.db.one('SELECT id FROM public.customers WHERE id=%s FOR UPDATE', (receiver['customer_id'],))
            account = self.db.one('''SELECT id FROM public.customer_usdt_balance_accounts
                WHERE customer_id=%s FOR UPDATE''', (receiver['customer_id'],))
            self.db.one('SELECT id FROM public.customer_usdt_deposit_receivers WHERE id=%s FOR UPDATE', (receiver['id'],))
            checkpoint = self.db.one('SELECT * FROM public.usdt_bsc_scan_checkpoints WHERE receiver_id=%s FOR UPDATE', (receiver['id'],))
            if checkpoint is None or checkpoint['version'] != version or checkpoint['review_reason'] is not None:
                return False
            self.db.execute('''INSERT INTO public.usdt_reorg_receiver_evidence
                (receiver_id,checkpoint_version,reason,evidence,created_at) VALUES (%s,%s,%s,%s,%s)''',
                (receiver['id'], version + 1, reason, json.dumps(evidence), now))
            self.db.execute('''UPDATE public.usdt_bsc_scan_checkpoints SET review_reason=%s,
                version=version+1,updated_at=%s WHERE receiver_id=%s''', (reason, now, receiver['id']))
            if account and self.db.one('''SELECT r.top_up_id FROM public.customer_usdt_top_up_resolutions r
                JOIN public.customer_usdt_top_ups t ON t.id=r.top_up_id
                WHERE t.receiver_id=%s AND r.entry_id IS NOT NULL LIMIT 1''', (receiver['id'],)):
                self.db.execute('''UPDATE public.customer_usdt_balance_accounts SET frozen_reason=%s,
                    updated_at=%s WHERE id=%s AND frozen_reason IS NULL''',
                    (f"usdt_reorg_receiver:{receiver['id']}", now, account['id']))
            return True

    def scan(self, receiver_id, first_block, *, max_pages=2, request_budget=32):
        self._outside()
        if (type(receiver_id) is not int or receiver_id < 1 or type(first_block) is not int or first_block < 0
                or type(max_pages) is not int or not 1 <= max_pages <= 10
                or type(request_budget) is not int or not 1 <= request_budget <= 100):
            raise ValueError('Invalid BSC scan bounds')
        receiver = self.db.one('SELECT * FROM public.customer_usdt_deposit_receivers WHERE id=%s', (receiver_id,))
        if receiver is None:
            raise ValueError('BSC receiver unavailable')
        requests = pages = 0
        last_block = None

        def report(status, reason):
            return dict(status=status, reason=reason, requests=requests, pages=pages, last_block=last_block)

        if not self._valid_receiver(receiver) or receiver['verified_at'] is None:
            return report('review', 'receiver_unqualified')
        now = self.clock()
        self.db.execute('''INSERT INTO public.usdt_bsc_scan_checkpoints (receiver_id,first_block,version,updated_at)
            VALUES (%s,%s,0,%s) ON CONFLICT (receiver_id) DO NOTHING''', (receiver_id, first_block, now))
        checkpoint = self.db.one('SELECT * FROM public.usdt_bsc_scan_checkpoints WHERE receiver_id=%s', (receiver_id,))
        if checkpoint['first_block'] != first_block:
            raise ValueError('BSC scan first block cannot change')
        last_block = checkpoint['last_block']
        if checkpoint['review_reason'] is not None:
            return report('review', checkpoint['review_reason'])

        def call(method, params=()):
            nonlocal requests
            if requests >= request_budget:
                raise OverflowError()
            requests += 1
            return self.adapter.call(method, params)

        def block(number):
            return _block(call('eth_getBlockByNumber', [hex(number) if type(number) is int else number, False]))

        def hold(reason, evidence):
            if not self._hold(receiver, checkpoint['version'], reason, evidence):
                return report('pending', 'stale_scan_version')
            return report('review', reason)

        try:
            chain = _quantity(call('eth_chainId'))
            decimals = call('eth_call', [{'to': USDT_CONTRACT, 'data': '0x313ce567'}, 'latest'])
            if (chain != CHAIN_ID or not isinstance(decimals, str)
                    or re.fullmatch(r'0x[0-9a-fA-F]{64}', decimals) is None or int(decimals, 16) != 18):
                return report('review', 'rpc_network_or_token_mismatch')
            head, finalized = block('latest'), block('finalized')
            if head is None or finalized is None or finalized[0] >= head[0] or finalized[1] > head[1]:
                return report('pending', 'finality_unavailable')
            canonical = block(finalized[0])
            if canonical is None:
                return report('pending', 'canonical_finality_unavailable')
            if canonical != finalized:
                return hold('finalized_block_changed', {'expected': finalized, 'observed': canonical})
            for _ in range(max_pages):
                anchor = None
                if checkpoint['last_block'] is not None:
                    anchor = block(checkpoint['last_block'])
                    if anchor is None:
                        return report('pending', 'checkpoint_block_unavailable')
                    if anchor[0] != checkpoint['last_block'] or anchor[2] != checkpoint['last_block_hash']:
                        return hold('checkpoint_reorg', {'expected_number': checkpoint['last_block'],
                            'expected_hash': checkpoint['last_block_hash'], 'observed': anchor})
                start = first_block if checkpoint['last_block'] is None else checkpoint['last_block'] + 1
                if start > finalized[0]:
                    return report('complete', 'caught_up_to_finalized')
                end = min(start + 999, finalized[0])
                boundary = block(end)
                if boundary is None or boundary[0] != end:
                    return report('pending', 'page_boundary_unavailable')
                topic_receiver = '0x' + '0' * 24 + receiver['address_normalized'][2:]
                logs = call('eth_getLogs', [{'fromBlock': hex(start), 'toBlock': hex(end),
                    'address': USDT_CONTRACT, 'topics': [TRANSFER_TOPIC, None, topic_receiver]}])
                if type(logs) is not list or len(logs) > MAX_LOGS:
                    return report('pending', 'log_page_malformed_or_oversized')
                transfers, identities, blocks = [], set(), {end: boundary}
                for log in logs:
                    transfer = decode_transfer(log)
                    if (transfer is None or not start <= transfer.block_number <= end
                            or transfer.token_contract != USDT_CONTRACT
                            or transfer.receiver != receiver['address_normalized']
                            or (transfer.tx_hash, transfer.log_index) in identities):
                        return report('pending', 'log_page_identity_mismatch')
                    identities.add((transfer.tx_hash, transfer.log_index))
                    if transfer.block_number not in blocks:
                        facts = block(transfer.block_number)
                        if facts is None or facts[0] != transfer.block_number:
                            return report('pending', 'log_block_unavailable')
                        blocks[transfer.block_number] = facts
                    if blocks[transfer.block_number][2] != transfer.block_hash:
                        return hold('log_block_reorg', {'expected_hash': transfer.block_hash,
                            'observed': blocks[transfer.block_number]})
                    transfers.append(transfer)
                after = block(end)
                if after is None:
                    return report('pending', 'page_boundary_unavailable')
                if after != boundary:
                    return hold('page_boundary_reorg', {'expected': boundary, 'observed': after})
                if anchor is not None:
                    after_anchor = block(checkpoint['last_block'])
                    if after_anchor is None:
                        return report('pending', 'checkpoint_block_unavailable')
                    if after_anchor != anchor:
                        return hold('checkpoint_reorg', {'expected': anchor, 'observed': after_anchor})
                with transaction.atomic(using=self.using):
                    self.db.require_transaction()
                    locked = self.db.one('SELECT * FROM public.usdt_bsc_scan_checkpoints WHERE receiver_id=%s FOR UPDATE', (receiver_id,))
                    if locked['version'] != checkpoint['version'] or locked['review_reason'] is not None:
                        return report('pending', 'stale_scan_version')
                    for transfer in transfers:
                        self.db.execute('''INSERT INTO public.usdt_bsc_transfer_observations
                            (receiver_id,chain_id,tx_hash,log_index,block_number,block_hash,token_contract,
                             receiver_address,amount_units,observed_at) VALUES (%s,56,%s,%s,%s,%s,%s,%s,%s,%s)
                            ON CONFLICT (chain_id,tx_hash,log_index,block_hash) DO NOTHING''',
                            (receiver_id, transfer.tx_hash, transfer.log_index, transfer.block_number,
                             transfer.block_hash, transfer.token_contract, transfer.receiver, transfer.amount_units, self.clock()))
                    self.db.execute('''UPDATE public.usdt_bsc_scan_checkpoints SET last_block=%s,last_block_hash=%s,
                        version=version+1,updated_at=%s WHERE receiver_id=%s''', (end, boundary[2], self.clock(), receiver_id))
                pages += 1
                last_block = end
                checkpoint = self.db.one('SELECT * FROM public.usdt_bsc_scan_checkpoints WHERE receiver_id=%s', (receiver_id,))
                if checkpoint['review_reason'] is not None:
                    return report('review', checkpoint['review_reason'])
                if end == finalized[0]:
                    return report('complete', 'caught_up_to_finalized')
            return report('pending', 'page_budget_reached')
        except OverflowError:
            return report('pending', 'request_budget_reached')
        except BscRpcError:
            return report('pending', 'rpc_unavailable')
