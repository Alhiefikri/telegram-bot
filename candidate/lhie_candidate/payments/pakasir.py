"""Historical Pakasir verification only; this module cannot create a payment.

Pakasir's PHP callback has no cryptographic authenticator. A callback is an
untrusted hint until credential-authenticated transaction detail agrees. The
injected transport owns retained services.pakasir credentials, egress admission,
response bounds and redirect denial; no live transport is constructed here.
"""
from dataclasses import dataclass
from datetime import datetime, timedelta
import hashlib
import json
import re
from typing import Protocol
from zoneinfo import ZoneInfo

from django.conf import settings
from django.db import connections, transaction

from ..checkout.db import Database
from ..checkout.lifecycle import OrderLifecycle
from ..domain.money import MAX_UNITS
from .lifecycle import LifecycleResult
from .pakasir_qris import hosted_payment_url, validate_hosted_payment_url, validate_payment_number
from .receipts import claim_provider_reference


_JAKARTA = ZoneInfo('Asia/Jakarta')
_MAX_RESPONSE = 65_536
_FINAL_ATTEMPTS = frozenset(('succeeded', 'already_paid', 'unsupported', 'terminal_failure'))


def _now():
    return datetime.now(_JAKARTA).replace(tzinfo=None)


def _date(value):
    if isinstance(value, str):
        value = datetime.fromisoformat(value.replace('Z', '+00:00'))
    if not isinstance(value, datetime):
        raise ValueError('Invalid Pakasir deadline')
    return value.astimezone(_JAKARTA).replace(tzinfo=None) if value.tzinfo else value


def _text(value, maximum=255):
    return (type(value) is str and 0 < len(value.encode('utf-8')) <= maximum
            and value.strip() == value and not any(ord(c) < 32 for c in value))


def _integer(value, *, minimum=1):
    if type(value) is str and re.fullmatch(r'[0-9]{1,19}', value, re.ASCII):
        value = int(value)
    if type(value) is not int or not minimum <= value <= MAX_UNITS:
        raise ValueError('Invalid Pakasir integer')
    return value


def _unique(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('Duplicate Pakasir JSON key')
        result[key] = value
    return result


def _json(raw):
    if type(raw) is not bytes or not 0 < len(raw) <= _MAX_RESPONSE:
        raise ValueError('Invalid Pakasir body')
    value = json.loads(raw, object_pairs_hook=_unique, parse_constant=lambda _: None)
    if type(value) is not dict:
        raise ValueError('Invalid Pakasir body')
    return value


@dataclass(frozen=True, slots=True)
class PakasirIdentity:
    merchant_ref: str
    amount: int
    payment_method: str | None = None

    def __post_init__(self):
        if (not _text(self.merchant_ref) or type(self.amount) is not int
                or not 1 <= self.amount <= MAX_UNITS
                or (self.payment_method is not None and not _text(self.payment_method))):
            raise ValueError('Invalid Pakasir identity')


@dataclass(frozen=True, slots=True, repr=False)
class PakasirTransaction:
    project: str
    merchant_ref: str
    amount: int
    status: str
    payment_method: str | None = None
    expiry: datetime | None = None
    fee: int | None = None
    total: int | None = None

    @property
    def completed(self):
        return self.status.lower() == 'completed'

    def matches(self, identity, project):
        return (self.project == project and self.merchant_ref == identity.merchant_ref
                and self.amount == identity.amount
                and (identity.payment_method is None
                     or self.payment_method == identity.payment_method.lower()))

    def evidence(self):
        evidence = {'project': self.project, 'order_id': self.merchant_ref,
                    'amount': self.amount, 'status': self.status}
        for key, value in (('payment_method', self.payment_method), ('fee', self.fee),
                           ('total_payment', self.total)):
            if value is not None:
                evidence[key] = value
        if self.expiry is not None:
            evidence['expired_at'] = self.expiry.isoformat()
        return evidence


def parse_transaction(payload, *, callback=False):
    if type(payload) is not dict:
        raise ValueError('Invalid Pakasir transaction')
    row = payload
    if not callback:
        row = payload.get('transaction', payload.get('data', payload))
        if type(row) is dict and type(row.get('transaction')) is dict:
            row = row['transaction']
    if type(row) is not dict or any(not _text(row.get(k)) for k in ('project', 'order_id', 'status')):
        raise ValueError('Invalid Pakasir transaction')
    method = row.get('payment_method')
    if method is not None:
        if not _text(method):
            raise ValueError('Invalid Pakasir method')
        method = method.lower()
    expiry = row.get('expired_at')
    if expiry is not None:
        expiry = _date(expiry)
    return PakasirTransaction(row['project'], row['order_id'], _integer(row.get('amount')),
        row['status'], method, expiry,
        _integer(row['fee'], minimum=0) if row.get('fee') is not None else None,
        _integer(row['total_payment']) if row.get('total_payment') is not None else None)


@dataclass(frozen=True, slots=True, repr=False)
class PakasirCallback:
    transaction: PakasirTransaction

    def matches(self, identity, project):
        observed = self.transaction
        return (observed.matches(PakasirIdentity(identity.merchant_ref, identity.amount), project)
                and (observed.payment_method is None or identity.payment_method is None
                     or observed.payment_method == identity.payment_method.lower()))

    @property
    def merchant_ref(self):
        return self.transaction.merchant_ref

    @property
    def provider_status(self):
        return self.transaction.status

    @property
    def evidence(self):
        return self.transaction.evidence()

    @property
    def digest(self):
        raw = json.dumps(self.evidence, sort_keys=True, ensure_ascii=False, separators=(',', ':'))
        return hashlib.sha256(('pakasir|callback|' + raw).encode('utf-8')).hexdigest()


def parse_callback(body, *, project):
    """Parse an unsigned hint, not paid authority; caller must persist then recheck."""
    observed = parse_transaction(_json(body), callback=True)
    if observed.project != project or not observed.completed:
        raise ValueError('Invalid Pakasir callback project or status')
    return PakasirCallback(observed)


def valid_checkout_url(value):
    from urllib.parse import parse_qsl, unquote, urlsplit
    try:
        if type(value) is not str:
            return False
        url = urlsplit(value)
        parts = url.path.split('/')
        query = parse_qsl(url.query, keep_blank_values=True, strict_parsing=True)
        if (len(parts) != 4 or parts[1] != 'pay' or len(query) != 1
                or query[0][0] != 'order_id' or not _text(query[0][1])
                or not re.fullmatch(r'[1-9][0-9]{0,18}', parts[3], re.ASCII)):
            return False
        project = unquote(parts[2])
        if not _text(project) or '/' in project or '\\' in project:
            return False
        return validate_hosted_payment_url(value, project=project,
            merchant_ref=query[0][1], amount=_integer(parts[3]))
    except (ValueError, UnicodeError):
        return False


class PakasirTransport(Protocol):
    def request(self, method: str, url: str, *, fields: dict, timeout: int,
                max_response_bytes: int) -> tuple[int, bytes]:
        """Inject retained credentials; deny redirects and non-admitted destinations."""
        ...


class PakasirUnavailable(Exception):
    pass


class PakasirAdapter:
    def __init__(self, *, transport: PakasirTransport, project: str):
        if not _text(project) or '/' in project or '\\' in project:
            raise ValueError('Invalid retained Pakasir project')
        self.transport = transport
        self.project = project

    def _request(self, identity, *, cancel=False):
        if settings.configured and any(c.in_atomic_block or not c.get_autocommit()
                for c in connections.all(initialized_only=True)):
            raise RuntimeError('Pakasir I/O requires a committed transaction')
        if type(identity) is not PakasirIdentity:
            raise ValueError('Invalid Pakasir lookup')
        try:
            status, raw = self.transport.request('POST' if cancel else 'GET',
                'https://app.pakasir.com/api/' + ('transactioncancel' if cancel else 'transactiondetail'),
                fields={'project': self.project, 'order_id': identity.merchant_ref,
                        'amount': identity.amount}, timeout=10, max_response_bytes=_MAX_RESPONSE)
            if type(status) is not int or not 200 <= status < 300:
                raise ValueError
            if cancel and raw in (b'', b'null'):
                return {}
            return _json(raw)
        except Exception:
            raise PakasirUnavailable('Pakasir observation unavailable') from None

    def detail(self, identity):
        try:
            result = parse_transaction(self._request(identity))
            if not result.matches(identity, self.project):
                raise ValueError
            return result
        except (PakasirUnavailable, ValueError, TypeError, OverflowError, RecursionError):
            return None

    def authentic_callback(self, callback, identity):
        """Pakasir has no callback signature: independent detail supplies authenticity."""
        if (type(callback) is not PakasirCallback or not callback.transaction.completed
                or not callback.matches(identity, self.project)):
            return None
        result = self.detail(identity)
        return result if result is not None and result.completed else None

    def cancel(self, identity):
        payload = self._request(identity, cancel=True)
        row = payload.get('transaction', payload)
        status = row.get('status') if type(row) is dict else None
        status = status.strip().lower() if type(status) is str else ''
        # Cancel APIs may return only status; no arbitrary response text survives.
        evidence = {'status': status[:255]} if status in (
            'cancelled', 'canceled', 'paid', 'completed', 'success', 'pending') else {}
        if status in ('cancelled', 'canceled'):
            return 'definitive', evidence
        if status in ('paid', 'completed', 'success'):
            return 'already_paid', evidence
        return 'ambiguous', evidence


class PakasirLifecycle:
    def __init__(self, adapter, encrypter, *, using='default', clock=None):
        self.adapter = adapter
        self.encrypter = encrypter
        self.using = using
        self.clock = clock or _now
        self.db = Database(using)
        self.orders = OrderLifecycle(using=using, encrypter=encrypter, clock=self.clock)

    def _outside(self):
        if any(c.in_atomic_block or not c.get_autocommit()
                for c in connections.all(initialized_only=True)):
            raise RuntimeError('Pakasir lifecycle requires an outer transaction boundary')
        if connections[self.using].vendor != 'postgresql':
            raise RuntimeError('Pakasir lifecycle requires PostgreSQL')

    def _locked(self, payment_id):
        order, payment, payments, _ = self.orders.lock_payment(payment_id)
        if payment['provider'] != 'pakasir' or payment['currency'] != 'IDR':
            raise ValueError('Invalid historical Pakasir rail')
        extra = self.db.one('''SELECT provider_payment_number,provider_payment_number_hash
            FROM public.payments WHERE id=%s''', (payment_id,))
        payment.update(extra)
        return order, payment, payments

    @staticmethod
    def _identity(payment):
        return PakasirIdentity(payment['merchant_ref'], payment['amount'], payment['provider_payment_method'])

    @staticmethod
    def _snapshot(order, payment):
        return (tuple((key, order.get(key)) for key in ('id', 'currency', 'total_amount', 'expires_at')),
                tuple((key, payment.get(key)) for key in ('id', 'order_id', 'provider', 'method',
                    'currency', 'amount', 'merchant_ref', 'expired_at', 'provider_reference',
                    'provider_fee', 'provider_total_payment', 'provider_expires_at',
                    'provider_payment_method', 'provider_presentation', 'provider_create_state',
                    'provider_payment_number', 'provider_payment_number_hash')))

    def _result(self, order, payment):
        if self.db.one("SELECT id FROM public.manual_fulfillment_reviews WHERE order_id=%s AND state IN ('pending','refund_uncertain')", (order['id'],)):
            return LifecycleResult('review')
        if payment['status'] in ('paid', 'refunded', 'expired', 'failed'):
            return LifecycleResult(payment['status'])
        if order['order_status'] != 'pending_payment' or order['payment_status'] != 'pending':
            return LifecycleResult('not_payable')
        try:
            deadlines = [_date(order['expires_at']), _date(payment['expired_at'])]
            if payment['provider_expires_at'] is not None:
                deadlines.append(_date(payment['provider_expires_at']))
            effective = min(deadlines)
            if effective <= self.clock():
                return LifecycleResult('expired')
            number = payment.get('provider_payment_number')
            if number is not None:
                payload = self.encrypter.decrypt_string(number)
                total = _integer(payment['provider_total_payment'])
                fee = _integer(payment['provider_fee'], minimum=0)
                if (payment['provider_payment_method'] != 'qris'
                        or total not in (payment['amount'], payment['amount'] + fee)):
                    raise ValueError
                validate_payment_number(payload, payment['provider_payment_number_hash'], total)
                presentation = {'mode': 'qris', 'qris_payload': payload,
                    'qris_image_url': None, 'checkout_url': None,
                    'customer_pays_fee': total != payment['amount'],
                    'effective_expires_at': effective.replace(tzinfo=_JAKARTA).isoformat()}
                return LifecycleResult('created', presentation, fee, total, total != payment['amount'])
            if payment['provider_create_state'] not in (None, 'hosted'):
                return LifecycleResult('recovery_unconfirmed')
            identity = self._identity(payment)
            url = hosted_payment_url(self.adapter.project, identity.merchant_ref, identity.amount)
            if not validate_hosted_payment_url(url, project=self.adapter.project,
                    merchant_ref=identity.merchant_ref, amount=identity.amount):
                raise ValueError
            return LifecycleResult('created', {'mode': 'hosted', 'checkout_url': url,
                'qris_payload': None, 'qris_image_url': None,
                'effective_expires_at': effective.replace(tzinfo=_JAKARTA).isoformat()})
        except (ValueError, TypeError, UnicodeError, OverflowError):
            return LifecycleResult('provider_invalid_result')

    def classify(self, payment_id):
        self._outside()
        with transaction.atomic(using=self.using):
            order, payment, _ = self._locked(payment_id)
            return self._result(order, payment)

    def refresh(self, payment_id):
        return self._observe(payment_id)

    def recover(self, payment_id):
        # Historical recovery always looks up the existing merchant reference.
        return self._observe(payment_id)

    def consume_callbacks(self, payment_id):
        return self._observe(payment_id, callbacks=True)

    def _event(self, payment, observed, event_type='reconciliation'):
        evidence = observed.evidence()
        return self.db.one('''INSERT INTO public.payment_events
            (payment_id,provider,event_type,merchant_ref,provider_reference,provider_status,
             raw_payload,received_at,last_received_at,received_count)
            VALUES (%s,'pakasir',%s,%s,%s,%s,%s::jsonb,%s,%s,1) RETURNING id''',
            (payment['id'], event_type, payment['merchant_ref'], payment['provider_reference'],
             observed.status, json.dumps(evidence), self.clock(), self.clock()))

    def _observe(self, payment_id, *, callbacks=False):
        self._outside()
        with transaction.atomic(using=self.using):
            order, payment, _ = self._locked(payment_id)
            if payment['status'] == 'refunded':
                return self._result(order, payment)
            hint = None
            if callbacks:
                hint = self.db.one('''SELECT id,raw_payload FROM public.payment_events
                    WHERE provider='pakasir' AND event_type='callback' AND merchant_ref=%s
                    AND processed_at IS NULL AND lower(provider_status)='completed'
                    AND raw_payload->>'project'=%s
                    AND raw_payload->>'order_id'=%s
                    AND ltrim(raw_payload->>'amount','0')=%s
                    ORDER BY id LIMIT 1''', (payment['merchant_ref'], self.adapter.project,
                                           payment['merchant_ref'], str(payment['amount'])))
                if hint is None:
                    return self._result(order, payment)
                payload = hint['raw_payload']
                if type(payload) is str:
                    payload = json.loads(payload)
                try:
                    callback = PakasirCallback(parse_transaction(payload, callback=True))
                    if not callback.transaction.completed or not callback.matches(
                            self._identity(payment), self.adapter.project):
                        return LifecycleResult('recovery_unconfirmed')
                except (ValueError, TypeError):
                    return LifecycleResult('recovery_unconfirmed')
            before, identity = self._snapshot(order, payment), self._identity(payment)
        observed = (self.adapter.authentic_callback(callback, identity) if callbacks
                    else self.adapter.detail(identity))
        if observed is None:
            return LifecycleResult('recovery_unconfirmed')
        with transaction.atomic(using=self.using):
            order, payment, payments = self._locked(payment_id)
            if self._snapshot(order, payment) != before:
                return LifecycleResult('recovery_unconfirmed')
            if (not observed.matches(identity, self.adapter.project)
                    or (observed.fee is not None and payment['provider_fee'] is not None
                        and observed.fee != payment['provider_fee'])
                    or (observed.total is not None and payment['provider_total_payment'] is not None
                        and observed.total != payment['provider_total_payment'])):
                return LifecycleResult('recovery_unconfirmed')
            event = self._event(payment, observed)
            if not observed.completed:
                # A pending/unknown/cancelled provider observation is not evidence
                # of unpaid money, nor authority to mutate a historical deadline.
                return self._result(order, payment)
            result = self._settle(order, payment, payments, observed)
            if result.state in ('paid', 'review') and payment['status'] == 'paid':
                self.db.execute('UPDATE public.payment_events SET processed_at=%s WHERE id=%s',
                                (self.clock(), event['id']))
                if hint is not None:
                    self.db.execute('UPDATE public.payment_events SET processed_at=%s,payment_id=%s WHERE id=%s AND processed_at IS NULL',
                                    (self.clock(), payment_id, hint['id']))
            return result

    def _settle(self, order, payment, payments, observed):
        if payment['status'] == 'refunded':
            return LifecycleResult('refunded')
        if payment['amount'] != order['total_amount'] or payment['currency'] != order['currency']:
            self.orders.review_locked(order, payment, 'receipt_conflict')
            return LifecycleResult('review')
        reference = payment['provider_reference'] or payment['merchant_ref']
        claim = claim_provider_reference(self.db, provider='pakasir', reference=reference.strip(),
            beneficiary='order:' + str(order['id']), currency='IDR', amount=payment['amount'],
            target_type='order_payment', target_id=str(payment['id']), now=self.clock(), using=self.using)
        if claim.held:
            self.orders.review_locked(order, payment, 'receipt_conflict')
            return LifecycleResult('review')
        deadlines = [_date(order['expires_at']), _date(payment['expired_at'])]
        if payment['provider_expires_at'] is not None:
            deadlines.append(_date(payment['provider_expires_at']))
        if observed.expiry is not None:
            deadlines.append(observed.expiry)
        if (order['order_status'] == 'pending_payment' and payment['status'] != 'paid'
                and min(deadlines) <= self.clock()):
            self.orders._release_locked(order, payments, status='expired')
        result = self.orders.paid_locked(order, payment, payments)
        return LifecycleResult('review' if result.review_id is not None else 'paid')


class PakasirCancellationAttempts:
    """Existing cancellation rows, three attempts, 30/120-second retry boundaries.

    The session advisory lock fences live consumers across post-commit I/O. A
    two-minute recovery lease covers an abandoned processing row; status is
    always rechecked before reissuing a cancellation after an uncertain send.
    """
    lease_seconds = 120

    def __init__(self, lifecycle):
        self.lifecycle = lifecycle
        self.db = lifecycle.db
        self.using = lifecycle.using

    def process_due(self, *, limit=10):
        if type(limit) is not int or not 1 <= limit <= 100:
            raise ValueError('Invalid cancellation batch limit')
        self.lifecycle._outside()
        rows = self.db.rows('''SELECT id FROM public.provider_cancellation_attempts
            WHERE state IN ('pending','processing','retryable_failure')
            AND (state='pending' OR started_at IS NULL OR
                 (state='processing' AND started_at<=%s) OR
                 (state='retryable_failure' AND started_at<=%s -
                    CASE WHEN attempt_count=1 THEN interval '30 seconds' ELSE interval '120 seconds' END))
            ORDER BY id LIMIT %s''',
            (self.lifecycle.clock() - timedelta(seconds=self.lease_seconds), self.lifecycle.clock(), limit))
        return tuple(self.process(row['id']) for row in rows)

    def _finish(self, attempt, state, *, error=None, evidence=None):
        at = self.lifecycle.clock()
        self.db.execute('''UPDATE public.provider_cancellation_attempts SET state=%s,
            provider_response=%s::json,last_error=%s,finished_at=%s,updated_at=%s
            WHERE id=%s AND state='processing' AND attempt_count=%s AND started_at=%s''',
            (state, json.dumps(evidence) if evidence is not None else None, error,
             at if state in _FINAL_ATTEMPTS else None, at, attempt['id'],
             attempt['attempt_count'], attempt['started_at']))
        return state

    def process(self, attempt_id):
        self.lifecycle._outside()
        if type(attempt_id) is not int or attempt_id <= 0:
            raise ValueError('Invalid cancellation attempt identity')
        key = 'p13:provider-cancellation:' + str(attempt_id)
        if not self.db.one('SELECT pg_try_advisory_lock(hashtextextended(%s,0)) AS acquired', (key,))['acquired']:
            return 'busy'
        try:
            return self._process(attempt_id)
        finally:
            self.db.one('SELECT pg_advisory_unlock(hashtextextended(%s,0)) AS released', (key,))

    def _process(self, attempt_id):
        # The shared Laravel timestamp columns store whole seconds.
        at = self.lifecycle.clock().replace(microsecond=0)
        with transaction.atomic(using=self.using):
            attempt = self.db.one('SELECT * FROM public.provider_cancellation_attempts WHERE id=%s FOR UPDATE', (attempt_id,))
            if attempt is None:
                return 'missing'
            if attempt['state'] in _FINAL_ATTEMPTS:
                return attempt['state']
            previous = attempt['state']
            started = attempt['started_at']
            delay = self.lease_seconds if previous == 'processing' else (30 if attempt['attempt_count'] == 1 else 120)
            if previous != 'pending' and started is not None and _date(started) + timedelta(seconds=delay) > at:
                return 'busy'
            if attempt['attempt_count'] >= 3:
                self.db.execute("UPDATE public.provider_cancellation_attempts SET state='terminal_failure',finished_at=%s,updated_at=%s,last_error='cancellation_attempts_exhausted' WHERE id=%s", (at, at, attempt_id))
                return 'terminal_failure'
            self.db.execute("UPDATE public.provider_cancellation_attempts SET state='processing',attempt_count=attempt_count+1,started_at=%s,last_error=NULL,updated_at=%s WHERE id=%s", (at, at, attempt_id))
            attempt.update(state='processing', attempt_count=attempt['attempt_count'] + 1, started_at=at)
        if attempt['provider'] != 'pakasir':
            return self._finish(attempt, 'unsupported')
        # All provider operations follow the claim commit. Money transitions
        # below take OrderLifecycle's canonical locks, never the attempt lock.
        try:
            with transaction.atomic(using=self.using):
                order, payment, _ = self.lifecycle._locked(attempt['payment_id'])
                if str(order['id']) != str(attempt['order_id']):
                    return self._finish(attempt, 'terminal_failure', error='cancellation_snapshot_mismatch')
                identity = self.lifecycle._identity(payment)
                local_paid = payment['status'] == 'paid'
            if local_paid:
                return self._finish(attempt, 'already_paid')
            observed = self.lifecycle.adapter.detail(identity)
            if observed is None:
                return self._failure(attempt, 'cancellation_recheck_unconfirmed')
            if observed.completed:
                return self._paid(attempt, observed.evidence())
            if observed.status.lower() in ('cancelled', 'canceled', 'expired'):
                return self._finish(attempt, 'succeeded', evidence=observed.evidence())
            # Unknown statuses are not unpaid authority and must not trigger I/O.
            if observed.status.lower() != 'pending':
                return self._failure(attempt, 'cancellation_recheck_unknown', observed.evidence())
            outcome, evidence = self.lifecycle.adapter.cancel(identity)
            # Recheck even a definitive cancellation: a paid race must survive.
            verified = self.lifecycle.adapter.detail(identity)
            if verified is not None and verified.completed:
                return self._paid(attempt, evidence)
            if (outcome == 'definitive' and verified is not None
                    and verified.status.lower() in ('cancelled', 'canceled', 'expired')):
                return self._finish(attempt, 'succeeded', evidence=evidence)
            return self._failure(attempt, 'cancellation_ambiguous', evidence)
        except PakasirUnavailable:
            return self._failure(attempt, 'cancellation_provider_error')

    def _paid(self, attempt, evidence):
        # Refresh rechecks the immutable Payment snapshot and owns the global
        # receipt claim. A held receipt never finishes the attempt as paid.
        result = self.lifecycle.refresh(attempt['payment_id'])
        with transaction.atomic(using=self.using):
            _, payment, _ = self.lifecycle._locked(attempt['payment_id'])
            if result.state in ('paid', 'review') and payment['status'] == 'paid':
                return self._finish(attempt, 'already_paid', evidence=evidence)
        return self._failure(attempt, 'cancellation_paid_unconfirmed', evidence)

    def _failure(self, attempt, error, evidence=None):
        state = 'terminal_failure' if attempt['attempt_count'] >= 3 else 'retryable_failure'
        return self._finish(attempt, state, error=error, evidence=evidence)
