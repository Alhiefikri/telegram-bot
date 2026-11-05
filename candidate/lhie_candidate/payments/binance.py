"""Read-only Binance C2C history authority for existing checkout snapshots.

The injected transport must deny redirects and enforce the supplied response
bound. No live transport, credentials discovery, or receiver mutation is enabled.
"""
from collections import deque
from dataclasses import dataclass
from datetime import datetime, timedelta
import hashlib
import hmac
import json
import re
import time
from typing import Protocol
from urllib.parse import urlencode
from zoneinfo import ZoneInfo

from django.conf import settings
from django.db import connections, transaction

from ..checkout.db import Database
from ..checkout.lifecycle import OrderLifecycle
from ..domain.money import MAX_UNITS
from .lifecycle import LifecycleResult
from .receipts import claim_binance_payment


_JAKARTA = ZoneInfo('Asia/Jakarta')
_MAX_RESPONSE = 262_144
_SKEW_MS = 120_000
_EXTRA_COLUMNS = '''binance_verification_mode,binance_order_id,binance_order_id_attempts,
    binance_order_id_submission_version,binance_order_id_submitted_at,
    binance_verification_status,binance_last_verified_at'''


def _now():
    return datetime.now(_JAKARTA).replace(tzinfo=None)


def _date(value):
    if type(value) is str:
        value = datetime.fromisoformat(value.replace('Z', '+00:00'))
    if not isinstance(value, datetime):
        raise ValueError('Invalid Binance timestamp')
    return value.astimezone(_JAKARTA).replace(tzinfo=None) if value.tzinfo else value


def _ms(value):
    value = _date(value).replace(tzinfo=_JAKARTA)
    epoch = datetime(1970, 1, 1, tzinfo=ZoneInfo('UTC'))
    delta = value - epoch
    return delta.days * 86_400_000 + delta.seconds * 1000 + delta.microseconds // 1000


def _identifier(value, *, optional=False, maximum=255):
    if type(value) is int and 0 <= value <= MAX_UNITS:
        value = str(value)
    if optional and value in (None, ''):
        return ''
    if (type(value) is not str or not 1 <= len(value) <= maximum
            or re.fullmatch(r'[A-Za-z0-9](?:[A-Za-z0-9._:-]*[A-Za-z0-9])?', value, re.ASCII) is None):
        raise ValueError('Invalid Binance identifier')
    return value


def normalize_order_id(value):
    if type(value) is not str:
        return None
    value = value.strip()
    try:
        return _identifier(value, maximum=128) if len(value) >= 10 else None
    except ValueError:
        return None


def amount_micro(value):
    if type(value) is int:
        value = str(value)
    if type(value) is not str or re.fullmatch(r'-?[0-9]{1,19}(?:\.[0-9]{1,6})?', value, re.ASCII) is None:
        raise ValueError('Invalid Binance amount')
    negative = value.startswith('-')
    whole, _, fraction = value.lstrip('-').partition('.')
    units = int(whole) * 1_000_000 + int(fraction.ljust(6, '0') or '0')
    if units > MAX_UNITS:
        raise ValueError('Invalid Binance amount')
    return -units if negative else units


def _integer(value):
    if type(value) is str and re.fullmatch(r'[0-9]{1,19}', value, re.ASCII):
        value = int(value)
    if type(value) is not int or not 0 <= value <= MAX_UNITS:
        raise ValueError('Invalid Binance timestamp')
    return value


def _mask(value):
    return '•' * len(value) if len(value) <= 8 else value[:4] + '••••' + value[-4:]


@dataclass(frozen=True, slots=True, repr=False)
class BinanceTransaction:
    order_id: str
    transaction_id: str
    amount: int
    currency: str
    time_ms: int
    account_id: str
    binance_id: str
    status: str

    @property
    def successful(self):
        # Legacy C2C history omits status for completed transfers.
        return self.status in ('', 'SUCCESS', 'COMPLETED', 'PAID')

    def matches_hint(self, hint):
        if self.order_id and hmac.compare_digest(self.order_id, hint):
            return 'order_id'
        if hmac.compare_digest(self.transaction_id, hint):
            return 'transaction_id_compatibility'
        return None

    def incoming(self, receiver_type, receiver_value):
        if self.amount <= 0:
            return False
        if receiver_type == 'pay_id':
            observed = self.account_id or self.binance_id
        elif receiver_type == 'uid':
            observed = self.binance_id
        else:
            return False
        return bool(observed) and hmac.compare_digest(observed, receiver_value)

    def evidence(self):
        return {'orderId': _mask(self.order_id), 'transactionId': _mask(self.transaction_id),
                'amount_micro': self.amount, 'currency': self.currency,
                'transactionTime': self.time_ms, 'status': self.status,
                'receiverAccountId': _mask(self.account_id), 'receiverBinanceId': _mask(self.binance_id)}


def parse_transaction(payload):
    if type(payload) is not dict:
        raise ValueError('Invalid Binance transaction')
    receiver = payload.get('receiverInfo', {})
    if type(receiver) is not dict:
        raise ValueError('Invalid Binance receiver')
    statuses = []
    for key in ('status', 'transactionStatus'):
        status = payload.get(key)
        if status is not None:
            if type(status) is not str or len(status) > 32 or status.strip() != status:
                raise ValueError('Invalid Binance status')
            statuses.append(status.upper())
    if len(set(statuses)) > 1:
        raise ValueError('Conflicting Binance status')
    currency = payload.get('currency')
    if type(currency) is not str or re.fullmatch(r'[A-Za-z]{2,10}', currency, re.ASCII) is None:
        raise ValueError('Invalid Binance currency')
    return BinanceTransaction(_identifier(payload.get('orderId'), optional=True),
        _identifier(payload.get('transactionId')), amount_micro(payload.get('amount')),
        currency.upper(), _integer(payload.get('transactionTime')),
        _identifier(receiver.get('accountId'), optional=True, maximum=128),
        _identifier(receiver.get('binanceId'), optional=True, maximum=128),
        statuses[0] if statuses else '')


def _pairs(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('Duplicate Binance JSON field')
        result[key] = value
    return result


def _invalid_constant(value):
    raise ValueError('Invalid Binance JSON constant')


class BinanceTransport(Protocol):
    def request(self, method: str, url: str, *, headers: dict, timeout: float,
                max_response_bytes: int) -> tuple[int, bytes]:
        """Deny redirects; enforce byte and timeout bounds before returning."""
        ...


class BinanceUnavailable(Exception):
    def __init__(self, *, retryable=True):
        super().__init__('Binance history unavailable')
        self.retryable = retryable


@dataclass(frozen=True, slots=True)
class BinanceHistory:
    transactions: tuple[BinanceTransaction, ...]
    complete: bool
    requests: int


class BinanceAdapter:
    def __init__(self, *, transport: BinanceTransport, api_key, api_secret,
                 pay_id=None, pay_uid=None, allow_historical_receiver_fallback=False,
                 clock_ms=None, max_pages=8, max_splits=8, max_duration_seconds=45):
        if not callable(getattr(transport, 'request', None)):
            raise ValueError('Missing injected Binance transport')
        if (type(api_key) is not str or not api_key or len(api_key) > 512
                or not api_key.isascii() or any(ord(c) < 33 or ord(c) > 126 for c in api_key)
                or type(api_secret) is not str or not 1 <= len(api_secret) <= 1024):
            raise ValueError('Invalid retained Binance credentials')
        for value, low, high in ((max_pages, 1, 64), (max_splits, 0, 16), (max_duration_seconds, 1, 120)):
            if type(value) is not int or not low <= value <= high:
                raise ValueError('Invalid Binance history budget')
        if type(allow_historical_receiver_fallback) is not bool:
            raise ValueError('Invalid historical Binance receiver policy')
        self.transport, self.api_key, self._secret = transport, api_key, api_secret.encode()
        self.pay_id = _identifier(pay_id, maximum=128) if pay_id is not None else None
        self.pay_uid = _identifier(pay_uid, maximum=128) if pay_uid is not None else None
        self.allow_historical_receiver_fallback = allow_historical_receiver_fallback
        self.clock_ms = clock_ms or (lambda: time.time_ns() // 1_000_000)
        self.max_pages, self.max_splits = max_pages, max_splits
        self.max_duration_seconds = max_duration_seconds

    def receivers(self, payment):
        kind, value = payment.get('binance_receiver_type'), payment.get('binance_receiver_value')
        if kind in ('pay_id', 'uid'):
            return ((kind, _identifier(value, maximum=128)),)
        if kind is not None or value is not None or not self.allow_historical_receiver_fallback:
            raise ValueError('Missing Binance receiver snapshot')
        receivers = tuple((kind, value) for kind, value in
                          (('pay_id', self.pay_id), ('uid', self.pay_uid)) if value is not None)
        if not receivers:
            raise ValueError('Missing retained Binance receiver')
        return receivers

    def readiness(self):
        return {'ready': True}

    def _page(self, start_ms, end_ms, *, timeout):
        query = urlencode({'startTime': start_ms, 'endTime': end_ms, 'limit': 100,
                           'recvWindow': 10000, 'timestamp': _integer(self.clock_ms())})
        signature = hmac.new(self._secret, query.encode('ascii'), hashlib.sha256).hexdigest()
        try:
            status, raw = self.transport.request('GET',
                'https://api.binance.com/sapi/v1/pay/transactions?' + query + '&signature=' + signature,
                headers={'X-MBX-APIKEY': self.api_key}, timeout=timeout,
                max_response_bytes=_MAX_RESPONSE)
        except Exception:
            raise BinanceUnavailable() from None
        if type(status) is not int or not 200 <= status < 300:
            raise BinanceUnavailable(retryable=type(status) is int and
                                     (status in (408, 429) or 500 <= status <= 599))
        try:
            if type(raw) is not bytes or not 0 < len(raw) <= _MAX_RESPONSE:
                raise ValueError
            result = json.loads(raw, object_pairs_hook=_pairs, parse_constant=_invalid_constant)
            if (type(result) is not dict or result.get('code') != '000000'
                    or result.get('success') is not True or type(result.get('data')) is not list
                    or len(result['data']) > 100):
                raise ValueError
            return tuple(parse_transaction(row) for row in result['data'])
        except (ValueError, TypeError, UnicodeError, RecursionError, OverflowError):
            raise BinanceUnavailable(retryable=False) from None

    def history(self, start_ms, end_ms):
        if settings.configured and any(c.in_atomic_block or not c.get_autocommit()
                for c in connections.all(initialized_only=True)):
            raise RuntimeError('Binance I/O requires a committed transaction')
        start_ms, end_ms = _integer(start_ms), _integer(end_ms)
        if end_ms < start_ms:
            raise ValueError('Invalid Binance history window')
        windows = deque([(start_ms, end_ms, 0)])
        seen, requests, complete = {}, 0, True
        started = time.monotonic()
        while windows:
            remaining = self.max_duration_seconds - (time.monotonic() - started)
            if requests >= self.max_pages or remaining <= 0:
                complete = False
                break
            start, end, depth = windows.popleft()
            page = self._page(start, end, timeout=min(10, remaining))
            requests += 1
            for row in page:
                previous = seen.get(row.transaction_id)
                if previous is not None and previous != row:
                    raise BinanceUnavailable(retryable=False)
                seen[row.transaction_id] = row
            if len(page) == 100:
                if depth >= self.max_splits or end - start <= 1:
                    complete = False
                else:
                    midpoint = start + (end - start) // 2
                    windows.extend(((start, midpoint, depth + 1), (midpoint + 1, end, depth + 1)))
        if time.monotonic() - started > self.max_duration_seconds:
            complete = False
        return BinanceHistory(tuple(seen.values()), complete, requests)


class BinanceLifecycle:
    def __init__(self, adapter, encrypter=None, *, using='default', clock=None):
        self.adapter, self.encrypter, self.using = adapter, encrypter, using
        self.clock = clock or _now
        self.db = Database(using)
        self.orders = OrderLifecycle(using=using, encrypter=encrypter, clock=self.clock)

    def _outside(self):
        if any(c.in_atomic_block or not c.get_autocommit() for c in connections.all(initialized_only=True)):
            raise RuntimeError('Binance lifecycle requires an outer transaction boundary')
        if connections[self.using].vendor != 'postgresql':
            raise RuntimeError('Binance lifecycle requires PostgreSQL')

    def _locked(self, payment_id):
        order, payment, payments, _ = self.orders.lock_payment(payment_id)
        if (payment['provider'], payment['method'], payment['currency']) != ('manual', 'BINANCE_PAY_ID', 'USDT'):
            raise ValueError('Invalid Binance rail')
        payment.update(self.db.one('SELECT ' + _EXTRA_COLUMNS + ' FROM public.payments WHERE id=%s', (payment_id,)))
        return order, payment, payments

    @staticmethod
    def _deadline(order, payment):
        deadlines = [_date(v) for v in (payment['expired_at'], order['expires_at']) if v is not None]
        if not deadlines:
            raise ValueError('Missing Binance deadline')
        return min(deadlines)

    @staticmethod
    def _snapshot(order, payment):
        return (tuple((key, order.get(key)) for key in ('id', 'customer_id', 'total_amount', 'currency', 'expires_at')),
                tuple((key, payment.get(key)) for key in ('id', 'order_id', 'provider', 'method', 'currency',
                    'amount', 'created_at', 'expired_at', 'merchant_ref', 'provider_reference',
                    'binance_receiver_type', 'binance_receiver_value',
                    'binance_auto_verification_enabled', 'binance_verification_mode', 'binance_order_id',
                    'binance_order_id_attempts', 'binance_order_id_submission_version',
                    'binance_verification_grace_until')))

    def _result(self, order, payment):
        if self.db.one("SELECT id FROM public.manual_fulfillment_reviews WHERE order_id=%s AND state IN ('pending','refund_uncertain')", (order['id'],)):
            return LifecycleResult('review')
        if payment['status'] in ('paid', 'refunded', 'expired', 'failed'):
            return LifecycleResult(payment['status'])
        if order['order_status'] != 'pending_payment' or order['payment_status'] != 'pending':
            return LifecycleResult('not_payable')
        try:
            deadline = self._deadline(order, payment)
            if deadline <= self.clock():
                grace = payment['binance_verification_grace_until']
                if grace is not None and _date(grace) > self.clock() and payment['binance_auto_verification_enabled']:
                    return LifecycleResult('pending')
                return LifecycleResult('expired')
            receivers = self.adapter.receivers(payment) if self.adapter is not None else (
                (payment['binance_receiver_type'], _identifier(payment['binance_receiver_value'], maximum=128)),)
            kind, receiver = receivers[0]
            if kind not in ('pay_id', 'uid') or type(payment['amount']) is not int or not 1 <= payment['amount'] <= MAX_UNITS:
                raise ValueError
            return LifecycleResult('created', {'mode': 'binance', 'receiver_type': kind,
                'receiver_value': receiver, 'amount_micro': payment['amount'], 'currency': 'USDT',
                'verification_mode': payment['binance_verification_mode'],
                'auto_verification_enabled': payment['binance_auto_verification_enabled'] and self.adapter is not None,
                'verification_status': payment['binance_verification_status'],
                'order_id_submitted': bool(payment['binance_order_id']),
                'effective_expires_at': deadline.replace(tzinfo=_JAKARTA).isoformat()})
        except (ValueError, TypeError, OverflowError):
            return LifecycleResult('pending')

    def classify(self, payment_id):
        self._outside()
        with transaction.atomic(using=self.using):
            order, payment, _ = self._locked(payment_id)
            return self._result(order, payment)

    def create(self, payment_id):
        return self.classify(payment_id)

    def submit_order_id(self, payment_id, candidate, *, customer_id):
        self._outside()
        normalized = normalize_order_id(candidate)
        if normalized is None:
            return LifecycleResult('invalid')
        def operation():
            order, payment, _ = self._locked(payment_id)
            if customer_id is None or order['customer_id'] != customer_id:
                return LifecycleResult('not_owned')
            if (self.adapter is None or not payment['binance_auto_verification_enabled']
                    or payment['binance_verification_mode'] != 'order_id'):
                return LifecycleResult('manual')
            if (payment['status'] != 'pending' or order['order_status'] != 'pending_payment'
                    or order['payment_status'] != 'pending'):
                return LifecycleResult('expired')
            deadline = self._deadline(order, payment)
            if deadline <= self.clock():
                return LifecycleResult('expired')
            grace = payment['binance_verification_grace_until']
            if grace is not None and _date(grace) <= self.clock():
                return LifecycleResult('grace_expired')
            if (payment['binance_order_id'] == normalized and payment['binance_order_id_submission_version'] > 0
                    and payment['binance_verification_status'] in ('checking', 'not_found', 'provider_error')
                    and grace is not None):
                return LifecycleResult('accepted')
            if payment['binance_order_id_attempts'] >= 3:
                return LifecycleResult('limit')
            grace = _date(grace) if grace is not None else deadline + timedelta(minutes=3)
            self.db.execute('''UPDATE public.payments SET binance_order_id=%s,
                binance_order_id_attempts=binance_order_id_attempts+1,
                binance_order_id_submission_version=binance_order_id_submission_version+1,
                binance_order_id_submitted_at=%s,binance_verification_grace_until=%s,
                binance_verification_status='checking',binance_last_verified_at=NULL,
                expired_at=COALESCE(expired_at,%s),updated_at=%s
                WHERE id=%s''', (normalized, self.clock(), grace, deadline, self.clock(), payment_id))
            return LifecycleResult('accepted')
        return self.orders._run(operation)

    def refresh(self, payment_id):
        return self._observe(payment_id)

    def recover(self, payment_id):
        return self._observe(payment_id)

    def consume_callbacks(self, payment_id):
        # Binance has no authenticated callback in this retained integration.
        return self._observe(payment_id)

    def _record(self, payment, status, observed=None, *, retryable=None):
        at = self.clock()
        evidence = observed.evidence() if observed is not None else {'outcome': status}
        evidence['submission_version'] = payment['binance_order_id_submission_version']
        if retryable is not None:
            evidence['retryable'] = retryable
        event = self.db.one('''INSERT INTO public.payment_events
            (payment_id,provider,event_type,merchant_ref,provider_reference,provider_status,
             raw_payload,received_at,last_received_at,received_count,processed_at)
            VALUES (%s,'binance','reconciliation',%s,%s,%s,%s::jsonb,%s,%s,1,%s) RETURNING id''',
            (payment['id'], payment['merchant_ref'], _mask(observed.transaction_id) if observed else None,
             status, json.dumps(evidence), at, at, at))
        self.db.execute('''UPDATE public.payments SET binance_verification_status=%s,
            binance_last_verified_at=%s,updated_at=%s WHERE id=%s''', (status, at, at, payment['id']))
        payment.update(binance_verification_status=status, binance_last_verified_at=at)
        if observed is not None:
            self.db.execute('UPDATE public.payments SET provider_payload=%s::jsonb WHERE id=%s',
                            (json.dumps(evidence), payment['id']))
        return event

    def _retry(self, payment):
        grace = payment['binance_verification_grace_until']
        if grace is None or _date(grace) <= self.clock():
            return None
        retry = self.db.one('''SELECT count(*) AS attempts,
            bool_or(raw_payload->>'retryable'='false') AS blocked FROM public.payment_events
            WHERE payment_id=%s AND provider='binance' AND event_type='reconciliation'
              AND raw_payload->>'submission_version'=%s
              AND provider_status IN ('checking','not_found','provider_error','mismatch')''',
            (payment['id'], str(payment['binance_order_id_submission_version'])))
        attempts = retry['attempts']
        if attempts >= 3 or retry.get('blocked'):
            return None
        delay = 30 * (2 ** max(0, attempts - 1))
        last = payment['binance_last_verified_at']
        next_at = _date(last) + timedelta(seconds=delay) if last is not None else self.clock()
        if next_at + timedelta(seconds=5) > _date(grace):
            return None
        return _ms(next_at) // 1000

    def _observe(self, payment_id):
        self._outside()
        with transaction.atomic(using=self.using):
            order, payment, _ = self._locked(payment_id)
            if payment['status'] in ('paid', 'refunded'):
                return self._result(order, payment)
            if (self.adapter is None or not payment['binance_auto_verification_enabled']
                    or payment['binance_verification_mode'] not in ('order_id', 'amount_unique')):
                return self._result(order, payment)
            if payment['binance_verification_mode'] == 'order_id' and not payment['binance_order_id']:
                return self._result(order, payment)
            try:
                receivers = self.adapter.receivers(payment)
                start = max(0, _ms(payment['created_at']) - _SKEW_MS)
                end = max(_ms(self._deadline(order, payment)), _ms(self.clock()))
                if _date(payment['created_at']) >= self._deadline(order, payment) or start > end:
                    raise ValueError('Invalid Binance payment window')
            except (ValueError, TypeError, OverflowError):
                return LifecycleResult('pending')
            before = self._snapshot(order, payment)
            if payment['binance_verification_mode'] == 'order_id' and payment['binance_last_verified_at'] is not None:
                retry_at = self._retry(payment)
                grace = payment['binance_verification_grace_until']
                if grace is not None and _date(grace) > self.clock():
                    if retry_at is None or retry_at > _ms(self.clock()) // 1000:
                        return LifecycleResult('pending', retry_at=retry_at)
        failure = None
        retryable = True
        try:
            history = self.adapter.history(start, end)
            if not history.complete:
                failure = 'checking'
        except BinanceUnavailable as error:
            failure, history, retryable = 'provider_error', None, error.retryable
        except (ValueError, TypeError, OverflowError):
            failure, history, retryable = 'provider_error', None, False
        def operation():
            order, payment, payments = self._locked(payment_id)
            if self._snapshot(order, payment) != before:
                return LifecycleResult('pending')
            if payment['status'] in ('paid', 'refunded'):
                return self._result(order, payment)
            if self.orders._open_review(order['id']) is not None:
                return LifecycleResult('review')
            if failure is not None:
                self._record(payment, failure, retryable=retryable)
                return LifecycleResult('pending', retry_at=self._retry(payment))
            rows = history.transactions
            if payment['binance_verification_mode'] == 'order_id':
                candidates = [row for row in rows if row.matches_hint(payment['binance_order_id'])]
            else:
                candidates = [row for row in rows if row.currency == 'USDT' and row.amount == payment['amount']
                              and any(row.incoming(*receiver) for receiver in receivers)]
            if not candidates:
                self._record(payment, 'not_found')
                return LifecycleResult('pending', retry_at=self._retry(payment))
            valid = [row for row in candidates if row.successful and row.currency == 'USDT'
                     and row.amount == payment['amount'] and row.time_ms >= start
                     and row.time_ms <= _ms(self.clock())
                     and any(row.incoming(*receiver) for receiver in receivers)]
            if not valid:
                if all(row.successful for row in candidates):
                    self._record(payment, 'mismatch', candidates[0])
                    self.orders.review_locked(order, payment, 'binance_payment_mismatch')
                    return LifecycleResult('review')
                self._record(payment, 'checking', candidates[0])
                return LifecycleResult('pending', retry_at=self._retry(payment))
            if len(valid) != 1:
                self._record(payment, 'mismatch', valid[0])
                self.orders.review_locked(order, payment, 'receipt_conflict')
                return LifecycleResult('review')
            observed = valid[0]
            self._record(payment, 'checking', observed)
            receiver = next(receiver for receiver in receivers if observed.incoming(*receiver))
            claim_payment = dict(payment, binance_receiver_type=receiver[0], binance_receiver_value=receiver[1])
            claim = claim_binance_payment(self.db, payment=claim_payment, transaction_id=observed.transaction_id,
                order_id=observed.order_id or None, now=self.clock(), using=self.using)
            if claim.held:
                self._record(payment, 'mismatch', observed)
                self.orders.review_locked(order, payment, 'receipt_conflict')
                return LifecycleResult('review')
            self.db.execute('UPDATE public.payments SET provider_reference=%s WHERE id=%s',
                            (observed.transaction_id, payment['id']))
            payment['provider_reference'] = observed.transaction_id
            deadline = self._deadline(order, payment)
            grace = payment['binance_verification_grace_until']
            within_grace = grace is not None and deadline < _date(grace) <= deadline + timedelta(minutes=3) and self.clock() < _date(grace)
            late = observed.time_ms > _ms(deadline) or (self.clock() >= deadline and not within_grace)
            if late and order['order_status'] == 'pending_payment' and order['payment_status'] == 'pending':
                self.orders._release_locked(order, payments, status='expired')
            if (order['order_status'] not in ('pending_payment', 'expired', 'cancelled')
                    or (order['order_status'] == 'pending_payment'
                        and (payment['status'] != 'pending' or order['payment_status'] != 'pending'))):
                self.orders.review_locked(order, payment, 'late_payment')
                return LifecycleResult('review')
            result = self.orders.paid_locked(order, payment, payments)
            self._record(payment, 'confirmed', observed)
            return LifecycleResult('review' if result.review_id is not None else 'paid')
        return self.orders._run(operation)
