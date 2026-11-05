"""Watch-only BSC checkout verification against frozen payment details."""
from dataclasses import asdict, dataclass, replace
from datetime import datetime, timedelta, timezone
import http.client
import json
import re
import time
from urllib.parse import urlsplit
from zoneinfo import ZoneInfo

from django.db import connections

from ..checkout.db import Database
from ..checkout.errors import CheckoutError
from ..checkout.lifecycle import OrderLifecycle
from .lifecycle import LifecycleResult
from .receipts import claim_bsc_payment


CHAIN_ID = 56
USDT_CONTRACT = '0x55d398326f99059ff775485246999027b3197955'
TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef'
MAX_RESPONSE_BYTES = 1_048_576
MAX_LOGS = 100
MAX_SCAN_BLOCKS = 250_000
SCAN_CHUNK_BLOCKS = 1_000
_JAKARTA = ZoneInfo('Asia/Jakarta')


def _now():
    return datetime.now(_JAKARTA).replace(tzinfo=None)


def _date(value):
    if not isinstance(value, datetime):
        raise ValueError('Missing invoice timestamp')
    return value.astimezone(_JAKARTA).replace(tzinfo=None) if value.tzinfo else value


def _epoch(value):
    return int(_date(value).replace(tzinfo=_JAKARTA).timestamp())


def normalize_hash(value):
    if not isinstance(value, str):
        return None
    value = value.strip().lower()
    return value if re.fullmatch(r'0x[0-9a-f]{64}', value) else None


def _address(value):
    if not isinstance(value, str):
        return None
    value = value.lower()
    return value if re.fullmatch(r'0x[0-9a-f]{40}', value) else None


def _quantity(value):
    if not isinstance(value, str) or re.fullmatch(r'0x(?:0|[1-9a-fA-F][0-9a-fA-F]{0,15})', value) is None:
        return None
    return int(value, 16)


def _block(value):
    if type(value) is not dict:
        return None
    number, timestamp, block_hash = (_quantity(value.get('number')),
                                     _quantity(value.get('timestamp')), normalize_hash(value.get('hash')))
    if number is None or timestamp is None or block_hash is None:
        return None
    return number, timestamp, block_hash


class BscRpcError(Exception):
    """An observation is unavailable; it is not evidence of payment failure."""


class HttpJsonRpcTransport:
    """Explicit opt-in HTTPS transport. No redirects, compression, or unbounded reads."""

    def __call__(self, endpoint, payload, *, timeout, max_response_bytes):
        parsed = urlsplit(endpoint)
        if (parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password
                or parsed.fragment):
            raise BscRpcError('Invalid BSC RPC endpoint')
        client = http.client.HTTPSConnection(parsed.hostname, parsed.port or 443, timeout=timeout)
        try:
            path = (parsed.path or '/') + ('?' + parsed.query if parsed.query else '')
            client.request('POST', path, json.dumps(payload, separators=(',', ':')).encode(),
                           {'Content-Type': 'application/json', 'Accept': 'application/json',
                            'Accept-Encoding': 'identity'})
            response = client.getresponse()
            length = response.getheader('Content-Length')
            if (response.status != 200 or response.getheader('Content-Encoding', 'identity') != 'identity'
                    or (length is not None and (not length.isdigit() or int(length) > max_response_bytes))):
                raise BscRpcError('BSC RPC HTTP response unavailable')
            data = response.read(max_response_bytes + 1)
            if len(data) > max_response_bytes:
                raise BscRpcError('BSC RPC response exceeds byte budget')
            return data
        finally:
            client.close()


def _unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('Duplicate JSON key')
        result[key] = value
    return result


class BscRpcClient:
    """Injected JSON-RPC transport; construction and the default client never connect."""

    _METHODS = frozenset(('eth_chainId', 'eth_getTransactionReceipt', 'eth_getBlockByNumber',
                          'eth_getLogs', 'eth_getCode', 'eth_call'))

    def __init__(self, transport=None, *, endpoint=None, timeout=5, max_response_bytes=MAX_RESPONSE_BYTES):
        if (type(timeout) not in (int, float) or not 0 < timeout <= 30
                or type(max_response_bytes) is not int or not 1 <= max_response_bytes <= MAX_RESPONSE_BYTES):
            raise ValueError('Invalid BSC RPC bounds')
        self.transport, self.endpoint = transport, endpoint
        self.timeout, self.max_response_bytes = timeout, max_response_bytes
        self._request_id = 0

    def call(self, method, params=()):
        if method not in self._METHODS:
            raise BscRpcError('Unsupported watch-only RPC method')
        if self.transport is None:
            raise BscRpcError('BSC RPC is inactive')
        self._request_id += 1
        payload = {'jsonrpc': '2.0', 'id': self._request_id, 'method': method, 'params': list(params)}
        try:
            data = self.transport(self.endpoint, payload, timeout=self.timeout,
                                  max_response_bytes=self.max_response_bytes)
            if isinstance(data, (str, bytes)):
                if len(data.encode() if isinstance(data, str) else data) > self.max_response_bytes:
                    raise BscRpcError('BSC RPC response exceeds byte budget')
                data = json.loads(data, object_pairs_hook=_unique_object,
                                  parse_constant=lambda value: (_ for _ in ()).throw(ValueError(value)))
            elif type(data) is dict:
                if len(json.dumps(data, allow_nan=False).encode()) > self.max_response_bytes:
                    raise BscRpcError('BSC RPC response exceeds byte budget')
            if (type(data) is not dict or data.get('jsonrpc') != '2.0'
                    or type(data.get('id')) is not int or data['id'] != payload['id']
                    or 'error' in data or 'result' not in data):
                raise BscRpcError('Malformed BSC RPC envelope')
            return data['result']
        except BscRpcError:
            raise
        except Exception as error:
            raise BscRpcError('BSC RPC observation unavailable') from error

    def readiness(self):
        unavailable = {'ready': False}
        try:
            if _quantity(self.call('eth_chainId')) != CHAIN_ID:
                return unavailable
            code = self.call('eth_getCode', [USDT_CONTRACT, 'latest'])
            decimals = self.call('eth_call', [{'to': USDT_CONTRACT, 'data': '0x313ce567'}, 'latest'])
            if (not isinstance(code, str) or re.fullmatch(r'0x[0-9a-fA-F]+', code) is None
                    or len(code) % 2 != 0 or not code[2:].strip('0')
                    or not isinstance(decimals, str) or re.fullmatch(r'0x[0-9a-fA-F]{64}', decimals) is None
                    or int(decimals, 16) != 18):
                return unavailable
            head = _block(self.call('eth_getBlockByNumber', ['latest', False]))
            if head is None:
                return unavailable
            logs = self.call('eth_getLogs', [{'fromBlock': hex(head[0]), 'toBlock': hex(head[0]),
                'address': USDT_CONTRACT, 'topics': [TRANSFER_TOPIC]}])
            if type(logs) is not list or len(logs) > MAX_LOGS or any(type(log) is not dict for log in logs):
                return unavailable
            return {'ready': True, 'chain_id': CHAIN_ID, 'token_contract': USDT_CONTRACT,
                    'token_decimals': 18, 'transfer_logs': True}
        except Exception:
            return unavailable


@dataclass(frozen=True, slots=True)
class BscInvoice:
    chain_id: int
    token_contract: str
    receiver: str
    decimals: int
    amount_micro: int
    amount_units: str
    starts_at: int
    expires_at: int
    required_confirmations: int
    requires_finalized_block: bool
    grace_minutes: int
    mode: str

    @classmethod
    def from_rows(cls, order, payment, detail):
        if (detail is None or (payment['provider'], payment['method'], payment['currency']) !=
                ('manual', 'USDT_BEP20', 'USDT')):
            raise ValueError('Invalid BSC payment rail')
        decimals = detail['token_decimals']
        units = str(detail['expected_token_units'])
        amount = payment['amount']
        confirmations = detail['required_confirmations']
        grace = detail['confirmation_grace_minutes']
        finality = detail['requires_finalized_block']
        mode = detail['verification_mode']
        receiver, token = _address(detail['receiver_address']), _address(detail['token_contract'])
        if (detail['chain_id'] != CHAIN_ID or token != USDT_CONTRACT or receiver is None
                or type(decimals) is not int or decimals != 18
                or type(amount) is not int or amount <= 0 or detail['payable_amount_micro'] != amount
                or units != str(amount * 10 ** (decimals - 6))
                or type(confirmations) is not int or not 3 <= confirmations <= 100
                or type(grace) is not int or not 0 <= grace <= 60 or type(finality) is not bool
                or mode not in ('amount_unique', 'transaction_hash')):
            raise ValueError('Invalid frozen BSC policy')
        starts_at = _epoch(payment['created_at'])
        expires_at = min(_epoch(payment['expired_at']), _epoch(order['expires_at']))
        if starts_at >= expires_at:
            raise ValueError('Invalid frozen BSC window')
        return cls(CHAIN_ID, token, receiver, decimals, amount, units, starts_at, expires_at,
                   confirmations, finality, grace, mode)


@dataclass(frozen=True, slots=True)
class BscTransfer:
    tx_hash: str
    log_index: int
    token_contract: str
    receiver: str
    sender: str
    amount_units: str
    block_number: int
    block_hash: str
    block_timestamp: int | None = None
    confirmations: int = 0


def decode_transfer(log):
    if type(log) is not dict:
        return None
    topics = log.get('topics')
    if (type(topics) is not list or len(topics) != 3 or topics[0] != TRANSFER_TOPIC
            or any(not isinstance(topic, str) or re.fullmatch(r'0x0{24}[0-9a-fA-F]{40}', topic) is None
                   for topic in topics[1:])
            or not isinstance(log.get('data'), str)
            or re.fullmatch(r'0x[0-9a-fA-F]{64}', log['data']) is None
            or log.get('removed') is not False):
        return None
    tx, block_hash = normalize_hash(log.get('transactionHash')), normalize_hash(log.get('blockHash'))
    number, index, token = _quantity(log.get('blockNumber')), _quantity(log.get('logIndex')), _address(log.get('address'))
    if tx is None or block_hash is None or number is None or index is None or token is None:
        return None
    return BscTransfer(tx, index, token, '0x' + topics[2][-40:].lower(),
                       '0x' + topics[1][-40:].lower(), str(int(log['data'], 16)), number, block_hash)


@dataclass(frozen=True, slots=True)
class BscObservation:
    state: str
    reason: str
    transfer: BscTransfer | None = None
    window_matches: bool = False
    finality_verified: bool = False
    chain_facts: dict | None = None


def inspect_receipt(invoice, tx_hash, receipt, canonical, head, finalized=None, *, now_epoch):
    """Only exact, canonical, sufficiently final evidence can become verified."""
    if receipt is None:
        return BscObservation('pending', 'receipt_missing')
    if type(receipt) is not dict or receipt.get('status') != '0x1':
        return BscObservation('pending', 'receipt_failed_or_malformed')
    number, block_hash = _quantity(receipt.get('blockNumber')), normalize_hash(receipt.get('blockHash'))
    if number is None or block_hash is None or normalize_hash(receipt.get('transactionHash')) != tx_hash:
        return BscObservation('pending', 'receipt_identity_malformed')
    logs = receipt.get('logs')
    if type(logs) is not list or len(logs) > MAX_LOGS:
        return BscObservation('pending', 'receipt_logs_malformed_or_oversized')
    transfers, indices = [], set()
    for log in logs:
        if type(log) is not dict or type(log.get('topics')) is not list:
            return BscObservation('pending', 'log_malformed')
        if not log['topics'] or log['topics'][0] != TRANSFER_TOPIC:
            continue
        transfer = decode_transfer(log)
        if (transfer is None or transfer.tx_hash != tx_hash or transfer.block_number != number
                or transfer.block_hash != block_hash or transfer.log_index in indices):
            return BscObservation('pending', 'log_identity_or_encoding_malformed')
        indices.add(transfer.log_index)
        transfers.append(transfer)
    if len(transfers) != 1:
        return BscObservation('review', 'transfer_mismatch_or_ambiguous')
    transfer = transfers[0]
    if transfer.token_contract != invoice.token_contract or transfer.receiver != invoice.receiver:
        return BscObservation('review', 'token_or_receiver_mismatch')
    block, latest = _block(canonical), _block(head)
    if block is None or latest is None:
        return BscObservation('pending', 'canonical_block_unavailable')
    if (block[0] != number or block[2] != block_hash or latest[0] < number
            or (latest[0] == number and latest[2] != block_hash)):
        return BscObservation('review', 'canonical_block_changed')
    if block[1] > now_epoch or latest[1] > now_epoch or latest[1] < block[1]:
        return BscObservation('pending', 'chain_clock_uncertain')
    transfer = replace(transfer, block_timestamp=block[1], confirmations=latest[0] - number + 1)
    window_matches = invoice.starts_at <= block[1] <= invoice.expires_at
    facts = {'canonical': {'number': block[0], 'timestamp': block[1], 'hash': block[2]},
             'head': {'number': latest[0], 'timestamp': latest[1], 'hash': latest[2]},
             'required_confirmations': invoice.required_confirmations,
             'requires_finalized_block': invoice.requires_finalized_block}
    if transfer.amount_units != invoice.amount_units:
        return BscObservation('review', 'amount_mismatch', transfer, window_matches, chain_facts=facts)
    if transfer.confirmations < invoice.required_confirmations:
        return BscObservation('confirming', 'confirmation_threshold_not_reached', transfer,
                              window_matches, chain_facts=facts)
    if invoice.requires_finalized_block:
        final = _block(finalized)
        if final is None:
            return BscObservation('confirming', 'finality_unavailable', transfer, window_matches, chain_facts=facts)
        facts['finalized'] = {'number': final[0], 'timestamp': final[1], 'hash': final[2]}
        if final[0] >= latest[0] or final[0] < number or final[1] > latest[1]:
            return BscObservation('confirming', 'finality_not_reached', transfer, window_matches, chain_facts=facts)
        if final[1] < block[1] or (final[0] == number and final[2] != block_hash):
            return BscObservation('review', 'finalized_block_changed', transfer, window_matches, chain_facts=facts)
    return BscObservation('verified', 'canonical_final_transfer' if window_matches else 'outside_invoice_window',
                          transfer, window_matches, True, facts)


class BscLifecycle:
    def __init__(self, adapter=None, encrypter=None, *, using='default', clock=None):
        self.adapter = adapter if adapter is not None else BscRpcClient()
        self.using, self.clock = using, clock or _now
        self.db = Database(using)
        self.orders = OrderLifecycle(using=using, encrypter=encrypter, clock=self.clock)

    def _outside(self):
        if (connections[self.using].vendor != 'postgresql'
                or any(c.in_atomic_block or not c.get_autocommit()
                       for c in connections.all(initialized_only=True))):
            raise RuntimeError('BSC lifecycle owns its outer PostgreSQL transaction')

    def _locked(self, payment_id):
        self.db.require_transaction()
        self.db.execute('SET LOCAL search_path = pg_catalog, public, pg_temp')
        order, payment, payments, _ = self.orders.lock_payment(payment_id)
        if (payment['provider'], payment['method'], payment['currency']) != ('manual', 'USDT_BEP20', 'USDT'):
            raise ValueError('Invalid BSC payment rail')
        detail = self.db.one('SELECT * FROM public.payment_bsc_details WHERE payment_id=%s FOR UPDATE', (payment_id,))
        return order, payment, payments, detail

    @staticmethod
    def _fence(order, payment, detail):
        return (str(order['id']), order['expires_at'], str(payment['id']), str(payment['order_id']),
                payment['provider'], payment['method'], payment['currency'], payment['amount'],
                payment['created_at'], payment['expired_at'],
                tuple((key, detail.get(key)) for key in (
                    'receiver_id', 'receiver_address', 'chain_id', 'token_contract', 'token_decimals',
                    'base_amount_micro', 'payable_amount_micro', 'expected_token_units',
                    'required_confirmations', 'requires_finalized_block', 'confirmation_grace_minutes',
                    'verification_mode', 'current_tx_hash', 'tx_submission_version')) if detail else None)

    def _result(self, order, payment, detail):
        if self.db.one("SELECT id FROM public.manual_fulfillment_reviews WHERE order_id=%s AND state IN ('pending','refund_uncertain')", (order['id'],)):
            return LifecycleResult('review')
        if payment['status'] == 'paid':
            return LifecycleResult('review' if order['order_status'] in ('expired', 'cancelled') else 'paid')
        if payment['status'] != 'pending' or order['order_status'] != 'pending_payment':
            return LifecycleResult('not_payable')
        try:
            invoice = BscInvoice.from_rows(order, payment, detail)
        except (ValueError, TypeError, KeyError):
            return LifecycleResult('pending')
        status = detail['verification_status']
        if status in ('manual_review', 'transaction_already_used', 'mismatch'):
            return LifecycleResult('review')
        if _epoch(self.clock()) > invoice.expires_at:
            return LifecycleResult('pending')
        presentation = {'mode': 'bsc', 'verification_mode': invoice.mode, 'network': 'BSC (BEP20)',
                        'chain_id': invoice.chain_id, 'receiver_address': invoice.receiver,
                        'token_contract': invoice.token_contract, 'token_decimals': invoice.decimals,
                        'amount_micro': invoice.amount_micro, 'expected_token_units': invoice.amount_units,
                        'required_confirmations': invoice.required_confirmations,
                        'requires_finalized_block': invoice.requires_finalized_block,
                        'confirmation_grace_minutes': invoice.grace_minutes,
                        'ttl_minutes': max(1, (_epoch(payment['expired_at']) - invoice.starts_at + 59) // 60),
                        'effective_expires_at': datetime.fromtimestamp(invoice.expires_at, _JAKARTA).isoformat(),
                        'verification_status': status, 'transaction_hash': detail['current_tx_hash'],
                        'confirmations': detail['current_confirmations'],
                        'submission_version': detail['tx_submission_version']}
        return LifecycleResult('created', presentation, 0, payment['amount'], False)

    def classify(self, payment_id):
        self._outside()
        def operation():
            order, payment, _, detail = self._locked(payment_id)
            return self._result(order, payment, detail)
        return self.orders._run(operation)

    def create(self, payment_id):
        return self.classify(payment_id)

    def consume_callbacks(self, payment_id):
        return self.classify(payment_id)

    def submit_hash(self, payment_id, candidate, *, customer_id):
        self._outside()
        tx_hash = normalize_hash(candidate)
        def operation():
            order, payment, _, detail = self._locked(payment_id)
            if type(customer_id) is not int or customer_id <= 0 or order['customer_id'] != customer_id:
                raise CheckoutError('foreign_payment')
            if tx_hash is None:
                return LifecycleResult('invalid_hash')
            if detail is None or detail['verification_mode'] != 'transaction_hash':
                return LifecycleResult('not_configured')
            if payment['status'] != 'pending' or order['order_status'] != 'pending_payment':
                return self._result(order, payment, detail)
            deadline = min(_date(payment['expired_at']), _date(order['expires_at']))
            grace = detail['verification_grace_until']
            if _date(self.clock()) > deadline and (grace is None or _date(self.clock()) > _date(grace)):
                return LifecycleResult('expired')
            if detail['current_tx_hash'] == tx_hash:
                return LifecycleResult('accepted')
            setting = self.db.one('SELECT bsc_hash_attempt_limit FROM public.manual_payment_settings ORDER BY id LIMIT 1')
            limit = (setting or {}).get('bsc_hash_attempt_limit') or 5
            if detail['tx_attempts'] >= limit:
                return LifecycleResult('submission_limit')
            self.db.execute("""UPDATE public.payment_bsc_details SET current_tx_hash=%s,
                tx_submission_version=tx_submission_version+1,tx_attempts=tx_attempts+1,
                verification_status='tx_submitted',last_verified_at=NULL,updated_at=%s WHERE payment_id=%s""",
                (tx_hash, self.clock(), payment_id))
            detail.update(current_tx_hash=tx_hash, tx_submission_version=detail['tx_submission_version'] + 1,
                          tx_attempts=detail['tx_attempts'] + 1, verification_status='tx_submitted')
            return LifecycleResult('accepted')
        return self.orders._run(operation)

    def _call(self, method, params=()):
        if self._rpc_requests >= 64 or time.monotonic() - self._rpc_started >= 45:
            raise BscRpcError('BSC observation budget exhausted')
        self._rpc_requests += 1
        result = self.adapter.call(method, params)
        if time.monotonic() - self._rpc_started >= 45:
            raise BscRpcError('BSC observation budget exhausted')
        return result

    def _discover(self, invoice, now_epoch):
        head = _block(self._call('eth_getBlockByNumber', ['latest', False]))
        if head is None:
            raise BscRpcError('Latest block unavailable')
        required = max(0, now_epoch - invoice.starts_at + 120) * 3 + 12
        if required > MAX_SCAN_BLOCKS:
            raise BscRpcError('BSC discovery exceeds bounded history')
        matches, seen = [], set()
        for start in range(max(0, head[0] - required), head[0] + 1, SCAN_CHUNK_BLOCKS):
            end = min(head[0], start + SCAN_CHUNK_BLOCKS - 1)
            logs = self._call('eth_getLogs', [{'fromBlock': hex(start), 'toBlock': hex(end),
                'address': invoice.token_contract,
                'topics': [TRANSFER_TOPIC, None, '0x' + '0' * 24 + invoice.receiver[2:]]}])
            if type(logs) is not list or len(logs) > MAX_LOGS:
                raise BscRpcError('Malformed or oversized discovery logs')
            for log in logs:
                transfer = decode_transfer(log)
                if transfer is None or not start <= transfer.block_number <= end:
                    raise BscRpcError('Malformed discovery transfer')
                identity = (transfer.tx_hash, transfer.log_index)
                if identity in seen:
                    raise BscRpcError('Duplicate discovery transfer')
                seen.add(identity)
                if len(seen) > MAX_LOGS:
                    raise BscRpcError('BSC discovery exceeds evidence budget')
                if (transfer.token_contract != invoice.token_contract or transfer.receiver != invoice.receiver
                        or transfer.amount_units != invoice.amount_units):
                    continue
                block = _block(self._call('eth_getBlockByNumber', [hex(transfer.block_number), False]))
                if block is None or block[0] != transfer.block_number or block[2] != transfer.block_hash:
                    raise BscRpcError('Discovery canonical block unavailable')
                if block[1] >= invoice.starts_at:
                    matches.append(transfer)
        if not matches:
            return None, None
        if len(matches) != 1:
            return None, BscObservation('review', 'multiple_matching_transfer_logs')
        return matches[0].tx_hash, None

    def _observe(self, invoice, tx_hash, *, now_epoch):
        self._rpc_requests, self._rpc_started = 0, time.monotonic()
        if _quantity(self._call('eth_chainId')) != invoice.chain_id:
            return BscObservation('pending', 'wrong_chain')
        if tx_hash is None:
            if invoice.mode != 'amount_unique':
                return BscObservation('pending', 'awaiting_transaction_hash')
            tx_hash, problem = self._discover(invoice, now_epoch)
            if problem is not None:
                return problem
            if tx_hash is None:
                return BscObservation('pending', 'transfer_not_found')
        receipt = self._call('eth_getTransactionReceipt', [tx_hash])
        number = _quantity(receipt.get('blockNumber')) if type(receipt) is dict else None
        if number is None:
            return BscObservation('pending', 'receipt_missing_or_malformed')
        canonical = self._call('eth_getBlockByNumber', [hex(number), False])
        head = self._call('eth_getBlockByNumber', ['latest', False])
        finalized = None
        if invoice.requires_finalized_block:
            try:
                finalized = self._call('eth_getBlockByNumber', ['finalized', False])
                final = _block(finalized)
                if final is not None:
                    final_canonical = _block(self._call('eth_getBlockByNumber', [hex(final[0]), False]))
                    if final_canonical != final:
                        finalized = None
            except Exception:
                finalized = None
        return inspect_receipt(invoice, tx_hash, receipt, canonical, head, finalized, now_epoch=now_epoch)

    def refresh(self, payment_id):
        self._outside()
        def snapshot():
            order, payment, _, detail = self._locked(payment_id)
            if payment['status'] in ('paid', 'refunded') or detail is None:
                return self._result(order, payment, detail)
            if order['order_status'] in ('expired', 'cancelled') and not detail['current_tx_hash']:
                return self._result(order, payment, detail)
            try:
                invoice = BscInvoice.from_rows(order, payment, detail)
            except (ValueError, TypeError, KeyError):
                return LifecycleResult('pending')
            return invoice, normalize_hash(detail['current_tx_hash']), self._fence(order, payment, detail)
        initial = self.orders._run(snapshot)
        if isinstance(initial, LifecycleResult):
            return initial
        invoice, tx_hash, fence = initial
        try:
            observation = self._observe(invoice, tx_hash, now_epoch=_epoch(self.clock()))
        except Exception:
            observation = BscObservation('pending', 'rpc_unavailable')
        return self.orders._run(lambda: self._apply(payment_id, invoice, fence, observation))

    def recover(self, payment_id):
        return self.refresh(payment_id)

    def _event(self, payment, detail, observation):
        payload = {'version': 1, 'submission_version': detail['tx_submission_version'],
                   'reason': observation.reason, 'window_matches': observation.window_matches,
                   'finality_verified': observation.finality_verified, 'chain_facts': observation.chain_facts,
                   'transfer': asdict(observation.transfer) if observation.transfer else None}
        reference = observation.transfer.tx_hash if observation.transfer else detail['current_tx_hash']
        self.db.execute('''INSERT INTO public.payment_events
            (payment_id,provider,event_type,merchant_ref,provider_reference,provider_status,raw_payload,received_at,processed_at)
            VALUES (%s,'bsc','status_check',%s,%s,%s,%s::jsonb,%s,%s)''',
            (payment['id'], payment['merchant_ref'], reference, observation.state,
             json.dumps(payload), self.clock(), self.clock()))
        return payload

    def _persist_evidence(self, payment, transfer, status, payload):
        timestamp = datetime.fromtimestamp(transfer.block_timestamp, timezone.utc).astimezone(_JAKARTA).replace(tzinfo=None)
        self.db.execute('''INSERT INTO public.bsc_payment_transfer_evidence
            (payment_id,tx_hash,token_contract,receiver_address,from_address,amount_units,block_number,
             block_hash,block_timestamp,receipt_status,log_index,confirmations,verification_status,
             evidence_payload,first_seen_at,last_verified_at,created_at,updated_at)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,'success',%s,%s,%s,%s::jsonb,%s,%s,%s,%s)
            ON CONFLICT (tx_hash) DO NOTHING''',
            (payment['id'], transfer.tx_hash, transfer.token_contract, transfer.receiver, transfer.sender,
             transfer.amount_units, transfer.block_number, transfer.block_hash, timestamp, transfer.log_index,
             transfer.confirmations, status, json.dumps(payload), self.clock(), self.clock(), self.clock(), self.clock()))
        existing = self.db.one('''SELECT payment_id,token_contract,receiver_address,amount_units,block_number,
            block_hash,log_index FROM public.bsc_payment_transfer_evidence WHERE tx_hash=%s''', (transfer.tx_hash,))
        return (existing is not None and str(existing['payment_id']) == str(payment['id'])
                and existing['token_contract'] == transfer.token_contract and existing['receiver_address'] == transfer.receiver
                and str(existing['amount_units']) == transfer.amount_units and existing['log_index'] == transfer.log_index
                and existing['block_number'] == transfer.block_number and existing['block_hash'] == transfer.block_hash)

    def _apply(self, payment_id, invoice, fence, observation):
        order, payment, payments, detail = self._locked(payment_id)
        if self._fence(order, payment, detail) != fence:
            return LifecycleResult('stale_submission')
        if payment['status'] in ('paid', 'refunded'):
            return self._result(order, payment, detail)
        transfer = observation.transfer
        payload = self._event(payment, detail, observation)
        status = {'verified': 'confirmed', 'confirming': 'confirming', 'review': 'manual_review'}.get(observation.state, 'provider_error')
        if observation.reason in ('transfer_not_found', 'receipt_missing_or_malformed'):
            status = 'not_found'
        if observation.reason == 'awaiting_transaction_hash':
            status = 'awaiting_transfer'
        if transfer is not None:
            if not self._persist_evidence(payment, transfer, 'confirming' if observation.state == 'confirming' else status, payload):
                self.orders.review_locked(order, payment, 'bsc_transaction_already_used')
                status = 'transaction_already_used'
                observation = replace(observation, state='review', reason='transaction_already_used')
            if (observation.state in ('confirming', 'verified') and observation.window_matches
                    and transfer.amount_units == invoice.amount_units):
                grace = datetime.fromtimestamp(invoice.expires_at, _JAKARTA).replace(tzinfo=None) + timedelta(minutes=invoice.grace_minutes)
                self.db.execute('''UPDATE public.payment_bsc_details SET verification_grace_until=%s
                    WHERE payment_id=%s AND verification_grace_until IS NULL''', (grace, payment_id))
                detail['verification_grace_until'] = detail['verification_grace_until'] or grace
        self.db.execute('''UPDATE public.payment_bsc_details SET verification_status=%s,last_verified_at=%s,
            current_tx_hash=COALESCE(%s,current_tx_hash),current_confirmations=%s,current_block_number=%s,
            current_from_address=%s,evidence_payload=%s::jsonb,updated_at=%s WHERE payment_id=%s''',
            (status, self.clock(), transfer.tx_hash if transfer else None,
             transfer.confirmations if transfer else None, transfer.block_number if transfer else None,
             transfer.sender if transfer else None, json.dumps(payload), self.clock(), payment_id))
        if observation.state == 'review':
            self.orders.review_locked(order, payment, 'bsc_manual_review')
            return LifecycleResult('review')
        if observation.state != 'verified' or not observation.finality_verified or transfer is None:
            return LifecycleResult('pending')
        claim = claim_bsc_payment(self.db, payment=payment, chain_id=invoice.chain_id,
            tx_hash=transfer.tx_hash, log_index=transfer.log_index, token_contract=invoice.token_contract,
            receiver=invoice.receiver, amount_units=transfer.amount_units, decimals=invoice.decimals,
            now=self.clock(), using=self.using)
        if not claim.allowed:
            self.orders.review_locked(order, payment, 'receipt_conflict')
            return LifecycleResult('review')
        if (order['order_status'] in ('expired', 'cancelled')
                and payment['status'] in ('pending', 'expired', 'failed')):
            self.orders.paid_locked(order, payment, payments)
            self.db.execute("UPDATE public.payment_bsc_details SET verification_status='manual_review' WHERE payment_id=%s", (payment_id,))
            return LifecycleResult('review')
        grace = detail['verification_grace_until']
        eligible = (observation.window_matches and grace is not None and _date(self.clock()) <= _date(grace)
                    and order['order_status'] == 'pending_payment' and order['payment_status'] == 'pending'
                    and payment['status'] == 'pending' and self.orders._open_review(order['id']) is None)
        if not eligible:
            self.orders.review_locked(order, payment, 'late_payment')
            self.db.execute("UPDATE public.payment_bsc_details SET verification_status='manual_review' WHERE payment_id=%s", (payment_id,))
            return LifecycleResult('review')
        result = self.orders.paid_locked(order, payment, payments)
        return LifecycleResult('review' if result.review_id is not None else 'paid')
