"""KlikQRIS native QRIS: injected transport, immutable invoices and verified recovery.

Callbacks are hints authenticated against the encrypted transaction signature.
Only the independent status endpoint can authorize the shared Order lifecycle.
"""
from dataclasses import dataclass
from datetime import datetime, timedelta
import binascii
import hashlib
import hmac
import json
import re
from urllib.parse import quote, unquote, urlsplit
from zoneinfo import ZoneInfo

from django.db import connections, transaction

from ..checkout.db import Database
from ..checkout.lifecycle import OrderLifecycle
from .lifecycle import LifecycleResult
from .receipts import claim_provider_reference


_JAKARTA = ZoneInfo('Asia/Jakarta')
_MAX_BYTES = 65536
_REFERENCE = re.compile(r'[A-Za-z0-9][A-Za-z0-9._:~-]{0,254}\Z')
_STATUS = re.compile(r'[A-Z][A-Z0-9_ -]{0,39}\Z')


class KlikQrisError(ValueError):
    def __init__(self, code='invalid_provider_result'):
        self.code = code
        super().__init__(code)


def now():
    return datetime.now(_JAKARTA).replace(tzinfo=None)


def deadline(value):
    try:
        if type(value) is str:
            value = datetime.fromisoformat(value.replace('Z', '+00:00'))
        if not isinstance(value, datetime):
            raise ValueError
        normalized = value.astimezone(_JAKARTA).replace(tzinfo=None) if value.tzinfo else value
        return normalized.replace(microsecond=0)
    except (ValueError, TypeError, OverflowError):
        raise KlikQrisError('invalid_deadline') from None


def amount(value, *, zero=False):
    if type(value) not in (str, int):
        raise KlikQrisError('invalid_amount')
    pattern = r'(0|[1-9][0-9]*)(?:\.0+)?' if zero else r'([1-9][0-9]*)(?:\.0+)?'
    match = re.fullmatch(pattern, str(value))
    if match is None or len(match[1]) > 19 or int(match[1]) > 2**63 - 1:
        raise KlikQrisError('invalid_amount')
    return int(match[1])


def _reference(value):
    if type(value) is not str or _REFERENCE.fullmatch(value) is None:
        raise KlikQrisError('invalid_reference')
    return value


def _unique(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise KlikQrisError('invalid_json')
        result[key] = value
    return result


def _json(body):
    try:
        if type(body) not in (bytes, str) or not 0 < len(body) <= _MAX_BYTES:
            raise ValueError
        value = json.loads(body, object_pairs_hook=_unique,
                           parse_constant=lambda _: (_ for _ in ()).throw(ValueError()))
        if type(value) is not dict:
            raise ValueError
        def bounded(node, depth=0):
            if depth > 32:
                raise ValueError
            if type(node) is dict:
                for child in node.values():
                    bounded(child, depth + 1)
            elif type(node) is list:
                for child in node:
                    bounded(child, depth + 1)
        bounded(value)
        return value
    except (ValueError, TypeError, UnicodeError, RecursionError):
        raise KlikQrisError('invalid_json') from None


def _url(value, hosts, *, path=None):
    if type(value) is not str or not value or len(value) > 2048 or any(ord(c) < 33 or ord(c) == 127 for c in value) or '\\' in value:
        return False
    try:
        parts = urlsplit(value)
        return (parts.scheme == 'https' and parts.hostname in hosts and parts.netloc == parts.hostname
                and not parts.fragment and not parts.query and parts.path == unquote(parts.path)
                and not any(p in ('.', '..') for p in parts.path.split('/'))
                and (path is None or parts.path == path))
    except ValueError:
        return False


def valid_qr_url(value, environment='sandbox', allowed_hosts=()):
    hosts = ('klikqris.com',) if environment == 'sandbox' else allowed_hosts
    if environment not in ('sandbox', 'production') or not _url(value, hosts):
        return False
    path = urlsplit(value).path
    if environment == 'sandbox':
        return re.fullmatch(r'/storage/sandbox/(?:qris_[A-Za-z0-9._~-]+|sandbox_sample)\.png', path) is not None
    return path.lower().endswith('.png')


def validate_emv(payload, total):
    """Validate optional provider EMV data; never synthesize or repair its amount."""
    try:
        if type(payload) is not str or not 16 <= len(payload) <= 4096 or not payload.isascii():
            raise ValueError
        fields, position = {}, 0
        while position < len(payload):
            tag, size = payload[position:position + 2], payload[position + 2:position + 4]
            if not tag.isdigit() or not size.isdigit() or tag in fields:
                raise ValueError
            end = position + 4 + int(size)
            if end > len(payload):
                raise ValueError
            fields[tag] = payload[position + 4:end]
            position = end
        if (fields.get('00') != '01' or fields.get('01') != '12' or fields.get('53') != '360'
                or fields.get('58') != 'ID' or amount(fields.get('54')) != total
                or payload[-8:-4] != '6304' or len(fields.get('63', '')) != 4
                or fields['63'].upper() != f'{binascii.crc_hqx(payload[:-4].encode(), 0xffff):04X}'):
            raise ValueError
    except (ValueError, TypeError):
        raise KlikQrisError('invalid_emv') from None
    return payload


@dataclass(frozen=True, slots=True, repr=False)
class KlikQrisConfig:
    environment: str = 'sandbox'
    enabled: bool = False
    base_url: str = 'https://klikqris.com/api/sandbox'
    api_key: str = ''
    merchant_id: str = ''
    callback_url: str = ''
    allowed_hosts: tuple = ()
    callback_allowed_hosts: tuple = ()
    runtime_environment: str = 'local'
    other_base_url: str = ''
    other_api_key: str = ''
    other_merchant_id: str = ''
    other_callback_url: str = ''
    operational_ready: bool = False
    timeout_seconds: int = 10
    connect_timeout_seconds: int = 3
    ttl_minutes: int = 15

    def readiness(self):
        failures = []
        if not self.enabled:
            failures.append('disabled')
        sandbox = self.environment == 'sandbox'
        if self.environment not in ('sandbox', 'production') or (sandbox and self.runtime_environment not in ('local', 'testing')) or (not sandbox and self.runtime_environment != 'production'):
            failures.append('environment')
        hosts = ('klikqris.com',) if sandbox else self.allowed_hosts
        if not _url(self.base_url, hosts, path='/api/sandbox' if sandbox else None) or (not sandbox and '/sandbox' in self.base_url):
            failures.append('base_url')
        if not self.api_key or not self.merchant_id or any(c in self.api_key + self.merchant_id for c in '\r\n'):
            failures.append('credentials')
        path = '/api/webhook/klikqris/sandbox' if sandbox else '/api/webhook/klikqris'
        if (self.callback_url or not sandbox) and not _url(self.callback_url, self.callback_allowed_hosts, path=path):
            failures.append('callback_url')
        if any(current and current == other for current, other in (
                (self.base_url, self.other_base_url), (self.api_key, self.other_api_key),
                (self.merchant_id, self.other_merchant_id), (self.callback_url, self.other_callback_url))):
            failures.append('cross_environment')
        if not self.operational_ready:
            failures.append('operations')
        if (type(self.timeout_seconds) is not int or type(self.connect_timeout_seconds) is not int
                or not 0 < self.connect_timeout_seconds <= self.timeout_seconds <= 60
                or type(self.ttl_minutes) is not int or not 5 <= self.ttl_minutes <= 60):
            failures.append('budget')
        return tuple(failures)


@dataclass(frozen=True, slots=True, repr=False)
class KlikQrisTransaction:
    merchant_ref: str
    amount: int
    total: int
    status: str
    environment: str
    reference: str | None = None
    expiry: datetime | None = None
    signature: str | None = None
    qr_url: str | None = None
    emv: str | None = None
    transient_image: bool = False

    @classmethod
    def parse(cls, payload, *, environment='sandbox', creating=False):
        if type(payload) is not dict:
            raise KlikQrisError()
        data = payload.get('data', payload)
        if type(data) is not dict:
            raise KlikQrisError()
        status = data.get('status')
        if type(status) is not str or _STATUS.fullmatch(status.upper()) is None:
            raise KlikQrisError('invalid_status')
        status = status.upper()
        base, total = amount(data.get('amount')), amount(data.get('total_amount'))
        if total < base:
            raise KlikQrisError('invalid_total')
        fees = [amount(data[key], zero=True) for key in ('fee', 'provider_fee', 'fee_amount', 'qris_fee') if data.get(key) is not None]
        if fees and (len(set(fees)) != 1 or fees[0] != total - base):
            raise KlikQrisError('invalid_fee')
        references = [_reference(data[key]) for key in ('provider_reference', 'transaction_id', 'transaction_ref', 'reference') if data.get(key) is not None]
        if len(set(references)) > 1:
            raise KlikQrisError('invalid_reference')
        signature = data.get('signature')
        if signature is not None and (type(signature) is not str or not 0 < len(signature) <= 4096 or signature.strip() != signature):
            raise KlikQrisError('invalid_signature')
        qr_url = data.get('qris_url')
        if qr_url is not None and type(qr_url) is not str:
            raise KlikQrisError('invalid_qr')
        emv = data.get('qris_payload')
        if emv is not None:
            validate_emv(emv, total)
        if creating and status == 'PENDING' and not qr_url and not data.get('qris_image'):
            raise KlikQrisError('missing_qr')
        return cls(_reference(data.get('order_id')), base, total, status, environment,
                   references[0] if references else None,
                   deadline(data['expired_at']) if data.get('expired_at') is not None else None,
                   signature, qr_url, emv, bool(data.get('qris_image')))

    def facts(self):
        return {'order_id': self.merchant_ref, 'amount': self.amount, 'total_amount': self.total,
                'status': self.status, 'provider_reference': self.reference,
                'expired_at': self.expiry.isoformat() if self.expiry else None}

    def assert_matches(self, payment, environment):
        if ((payment['provider'], payment['method'], payment['currency']) != ('klikqris', 'QRIS', 'IDR')
                or self.environment != environment or self.merchant_ref != payment['merchant_ref']
                or self.amount != payment['amount']
                or (payment.get('provider_total_payment') is not None and self.total != payment['provider_total_payment'])
                or (payment.get('provider_fee') is not None and self.total - self.amount != payment['provider_fee'])
                or (payment.get('provider_reference') is not None and self.reference is not None and self.reference != payment['provider_reference'])
                or (payment.get('provider_expires_at') is not None and self.expiry is not None and deadline(payment['provider_expires_at']) != self.expiry)):
            raise KlikQrisError('snapshot_mismatch')


@dataclass(frozen=True, slots=True, repr=False)
class KlikQrisObservation:
    state: str
    transaction: KlikQrisTransaction | None = None
    http_status: int | None = None


class KlikQrisAdapter:
    """Transport.request must enforce its timeout/byte/redirect/host arguments.

    No transport is constructed here. Admission and real provider qualification
    remain external; injected test transport is not an activation attestation.
    """
    def __init__(self, *, transport, config):
        self.transport = transport
        self.config = config

    def readiness(self):
        return self.config.readiness()

    def create(self, payment):
        return self._request(payment, creating=True)

    def status(self, payment):
        return self._request(payment, creating=False)

    def _request(self, payment, *, creating):
        if self.readiness():
            return KlikQrisObservation('create_failed' if creating else 'status_unavailable')
        try:
            merchant_ref = _reference(payment['merchant_ref'])
            base = amount(payment['amount'])
            body = {'order_id': merchant_ref, 'amount': base, 'id_merchant': self.config.merchant_id,
                    'keterangan': 'Payment ' + merchant_ref} if creating else None
            if creating and self.config.callback_url:
                body['callback_url'] = self.config.callback_url
        except (ValueError, KeyError):
            return KlikQrisObservation('create_failed' if creating else 'status_unavailable')
        endpoint = '/qris/create' if creating else '/qris/status/' + quote(merchant_ref, safe='')
        try:
            code, raw = self.transport.request(
                'POST' if creating else 'GET', self.config.base_url.rstrip('/') + endpoint,
                headers={'x-api-key': self.config.api_key, 'id_merchant': self.config.merchant_id},
                json=body, timeout=self.config.timeout_seconds,
                connect_timeout=self.config.connect_timeout_seconds, max_bytes=_MAX_BYTES,
                allow_redirects=False, allowed_hosts=(urlsplit(self.config.base_url).hostname,))
            if type(code) is not int or not 200 <= code < 300:
                # PHP treats even create 4xx as ambiguous; never re-create it.
                return KlikQrisObservation('outcome_unknown' if creating else 'status_unavailable', http_status=code if type(code) is int else None)
            result = KlikQrisTransaction.parse(_json(raw), environment=self.config.environment, creating=creating)
            result.assert_matches(payment, self.config.environment)
            if result.status not in ('PENDING', 'SUCCESS', 'EXPIRED'):
                return KlikQrisObservation('unknown_status')
            return KlikQrisObservation('observed', result)
        except Exception:
            return KlikQrisObservation('outcome_unknown' if creating else 'status_unavailable')


def callback_identity(body):
    """Bounded ingress lookup key; this alone authenticates nothing."""
    return _reference(_json(body).get('order_id'))


def authenticate_callback(body, *, environment, payment, encrypter):
    """Return only minimized facts after environment, signature and quote checks."""
    payload = _json(body)
    try:
        ref = _reference(payload.get('order_id'))
        status = payload.get('status')
        if type(status) is not str or _STATUS.fullmatch(status.upper()) is None:
            raise KlikQrisError('invalid_callback')
        supplied = payload.get('signature')
        persisted = encrypter.decrypt_string(payment.get('klikqris_transaction_signature'))
        if (environment not in ('sandbox', 'production') or environment != payment.get('klikqris_environment', environment)
                or payment.get('provider') != 'klikqris' or ref != payment.get('merchant_ref')
                or type(supplied) is not str or not supplied or type(persisted) is not str or not persisted
                or not hmac.compare_digest(supplied.encode(), persisted.encode())
                or amount(payload.get('amount')) != payment.get('amount')
                or amount(payload.get('total_amount')) < payment['amount']
                or (payment.get('provider_total_payment') is not None and amount(payload.get('total_amount')) != payment['provider_total_payment'])):
            raise KlikQrisError('unauthenticated_callback')
        reference = next((_reference(payload[k]) for k in ('provider_reference', 'transaction_id', 'reference') if payload.get(k) is not None), None)
        if reference and payment.get('provider_reference') and reference != payment['provider_reference']:
            raise KlikQrisError('unauthenticated_callback')
        return {'order_id': ref, 'amount': payment['amount'], 'total_amount': amount(payload['total_amount']),
                'status': status.upper(), 'provider_reference': reference,
                'merchant_ref_hash': hashlib.sha256(ref.encode()).hexdigest()}
    except (ValueError, TypeError, KeyError, UnicodeError):
        raise KlikQrisError('unauthenticated_callback') from None


class KlikQrisLifecycle:
    def __init__(self, adapter, encrypter, *, using='default', clock=None):
        self.adapter, self.encrypter, self.using = adapter, encrypter, using
        self.clock = clock or now
        self.db = Database(using)
        self.orders = OrderLifecycle(using=using, encrypter=encrypter, clock=self.clock)

    def _outside(self):
        if any(c.in_atomic_block or not c.get_autocommit() for c in connections.all(initialized_only=True)) or connections[self.using].vendor != 'postgresql':
            raise RuntimeError('KlikQRIS requires an outermost PostgreSQL boundary')

    def _locked(self, payment_id):
        order, payment, payments, _ = self.orders.lock_payment(payment_id)
        if (payment['provider'], payment['method'], payment['currency']) != ('klikqris', 'QRIS', 'IDR'):
            raise KlikQrisError('invalid_rail')
        extra = self.db.one('''SELECT klikqris_transaction_signature,provider_payload,provider_qris_url,
            provider_create_attempted_at,klikqris_status_failure_count,klikqris_status_next_check_at,
            klikqris_status_last_error FROM public.payments WHERE id=%s''', (payment_id,))
        payment.update(extra)
        return order, payment, payments

    @staticmethod
    def _snapshot(order, payment):
        return ({key: value for key, value in order.items() if key not in ('order_status', 'payment_status', 'checkout_key')},
                {key: value for key, value in payment.items() if key not in ('status', 'paid_at')})

    def _signature(self, payment):
        return self.encrypter.decrypt_string(payment['klikqris_transaction_signature'])

    def _presentation(self, order, payment):
        expiry = min(deadline(payment['provider_expires_at']), deadline(payment['expired_at']), deadline(order['expires_at']))
        url = self.encrypter.decrypt_string(payment['provider_qris_url'])
        if (not valid_qr_url(url, self.adapter.config.environment, self.adapter.config.allowed_hosts)
                or type(payment['provider_total_payment']) is not int or payment['provider_total_payment'] < payment['amount']
                or payment['provider_fee'] != payment['provider_total_payment'] - payment['amount']
                or payment['provider_payment_method'] != 'qris'
                or (self.adapter.config.environment == 'production' and not self._signature(payment))):
            raise KlikQrisError('invalid_presentation')
        payload = payment['provider_payload']
        if type(payload) is str:
            payload = json.loads(payload)
        protected = dict(payload.get('data', payload))
        protected['qris_url'] = url
        observation = KlikQrisTransaction.parse(protected, environment=self.adapter.config.environment)
        observation.assert_matches(payment, self.adapter.config.environment)
        if observation.expiry is None or observation.expiry != deadline(payment['provider_expires_at']):
            raise KlikQrisError('invalid_presentation')
        return {'mode': 'qris_image', 'qris_image_url': url, 'checkout_url': None,
                'environment': self.adapter.config.environment, 'allowed_hosts': self.adapter.config.allowed_hosts,
                'effective_expires_at': expiry.isoformat(), 'customer_pays_fee': True}

    def _result(self, order, payment):
        if self.db.one("SELECT id FROM public.manual_fulfillment_reviews WHERE order_id=%s AND state IN ('pending','refund_uncertain')", (order['id'],)):
            return LifecycleResult('review')
        if payment['status'] in ('paid', 'refunded', 'expired', 'failed'):
            return LifecycleResult(payment['status'])
        if order['order_status'] != 'pending_payment' or order['payment_status'] != 'pending':
            return LifecycleResult('not_payable')
        if min(deadline(order['expires_at']), deadline(payment['expired_at'])) <= self.clock():
            return LifecycleResult('expired')
        if payment['provider_create_state'] == 'created' or (payment['provider_create_state'] is None and payment['provider_payload']):
            try:
                presentation = self._presentation(order, payment)
                if deadline(presentation['effective_expires_at']) <= self.clock():
                    return LifecycleResult('expired')
                return LifecycleResult('created', presentation, payment['provider_fee'], payment['provider_total_payment'], True)
            except Exception:
                return LifecycleResult('provider_qris_unusable')
        return LifecycleResult(payment['provider_create_state'] or 'pending')

    def classify(self, payment_id):
        self._outside()
        with transaction.atomic(using=self.using):
            order, payment, _ = self._locked(payment_id)
            return self._result(order, payment)

    def _single(self, payment_id, operation):
        self._outside()
        key = 'klikqris:payment:' + str(payment_id)
        if not self.db.one('SELECT pg_try_advisory_lock(hashtextextended(%s,0)) AS acquired', (key,))['acquired']:
            return LifecycleResult('busy')
        try:
            return operation()
        finally:
            self.db.one('SELECT pg_advisory_unlock(hashtextextended(%s,0)) AS released', (key,))

    def create(self, payment_id):
        return self._single(payment_id, lambda: self._work(payment_id, creating=True))

    def refresh(self, payment_id):
        return self._single(payment_id, lambda: self._work(payment_id))

    def recover(self, payment_id):
        return self.refresh(payment_id)

    def consume_callbacks(self, payment_id):
        def consume():
            with transaction.atomic(using=self.using):
                order, payment, _ = self._locked(payment_id)
                hint = self.db.one("""SELECT id,provider_status FROM public.payment_events
                    WHERE provider='klikqris' AND event_type='callback' AND merchant_ref=%s
                    AND processed_at IS NULL ORDER BY id LIMIT 1""", (payment['merchant_ref'],))
                if hint is None:
                    return self._result(order, payment)
                if hint['provider_status'] not in ('PAID', 'SUCCESS'):
                    self.db.execute('UPDATE public.payment_events SET processed_at=%s WHERE id=%s', (self.clock(), hint['id']))
                    return self._result(order, payment)
            return self._work(payment_id, hint=hint)
        return self._single(payment_id, consume)

    def _work(self, payment_id, *, creating=False, hint=None):
        with transaction.atomic(using=self.using):
            order, payment, _ = self._locked(payment_id)
            if payment['status'] in ('paid', 'refunded'):
                if hint and payment['status'] == 'paid':
                    self.db.execute('UPDATE public.payment_events SET processed_at=%s WHERE id=%s AND processed_at IS NULL', (self.clock(), hint['id']))
                return self._result(order, payment)
            if creating:
                result = self._result(order, payment)
                if result.state in ('created', 'expired', 'not_payable', 'review', 'failed'):
                    return result
                creating = payment['provider_create_state'] in (None, 'create_failed') and not payment['provider_payload']
            if not creating and not (payment['provider_create_state'] or payment['provider_payload']):
                return self._result(order, payment)
            paid_hint = hint is not None and hint.get('provider_status') in ('PAID', 'SUCCESS')
            if (payment['klikqris_status_next_check_at']
                    and deadline(payment['klikqris_status_next_check_at']) > self.clock()
                    and not (paid_hint and payment['klikqris_status_last_error'] == 'status_interval')):
                return LifecycleResult('status_deferred')
            if creating:
                if self.adapter.readiness():
                    return LifecycleResult('create_failed')
                if payment['amount'] != order['total_amount'] or order['currency'] != 'IDR':
                    return LifecycleResult('provider_invalid_result')
                attempted_at = self.clock()
                self.db.execute("""UPDATE public.payments SET provider_create_state='creating',
                    provider_create_attempted_at=%s,provider_create_last_error=NULL,updated_at=%s WHERE id=%s""", (attempted_at, attempted_at, payment_id))
                payment['provider_create_state'] = 'creating'
                payment['provider_create_attempted_at'] = attempted_at
            dispatched_at = self.clock()
            next_check = dispatched_at.replace(microsecond=0) + timedelta(
                seconds=20 + bool(dispatched_at.microsecond))
            self.db.execute("""UPDATE public.payments SET klikqris_status_next_check_at=%s,
                klikqris_status_last_error='status_interval' WHERE id=%s""", (next_check, payment_id))
            payment.update(klikqris_status_next_check_at=next_check, klikqris_status_last_error='status_interval')
            before = self._snapshot(order, payment)
        outcome = self.adapter.create(payment) if creating else self.adapter.status(payment)
        with transaction.atomic(using=self.using):
            order, payment, payments = self._locked(payment_id)
            # Attempt timestamp is a database precision value, not a money identity.
            original_order, original_payment = before
            original_payment['provider_create_attempted_at'] = payment['provider_create_attempted_at']
            if self._snapshot(order, payment) != (original_order, original_payment):
                return self._result(order, payment)
            observed = outcome.transaction
            if observed is None:
                if outcome.state == 'unknown_status':
                    return self._result(order, payment)
                if outcome.http_status == 429:
                    self.db.execute("""UPDATE public.payments SET klikqris_status_next_check_at=%s,
                        klikqris_status_last_error='status_http_429' WHERE id=%s""",
                        (self.clock() + timedelta(seconds=30), payment_id))
                if creating:
                    state = 'create_failed' if outcome.state == 'create_failed' else 'outcome_unknown'
                    self.db.execute('UPDATE public.payments SET provider_create_state=%s,provider_create_last_error=%s,updated_at=%s WHERE id=%s', (state, state, self.clock(), payment_id))
                    return LifecycleResult(state)
                if outcome.http_status == 404:
                    failures = min(3, (payment['klikqris_status_failure_count'] or 0) + 1)
                    wait = (300, 1800, 7200)[failures - 1]
                    self.db.execute("""UPDATE public.payments SET klikqris_status_failure_count=%s,
                        klikqris_status_next_check_at=%s,klikqris_status_last_error='status_http_404' WHERE id=%s""", (failures, self.clock() + timedelta(seconds=wait), payment_id))
                return LifecycleResult('recovery_unconfirmed')
            try:
                observed.assert_matches(payment, self.adapter.config.environment)
                signature = self._signature(payment)
                if signature and observed.signature and not hmac.compare_digest(signature.encode(), observed.signature.encode()):
                    raise KlikQrisError('signature_mismatch')
                if not signature and not observed.signature and self.adapter.config.environment == 'production':
                    raise KlikQrisError('signature_missing')
            except Exception:
                return LifecycleResult('recovery_unconfirmed')
            if observed.status not in ('PENDING', 'SUCCESS', 'EXPIRED'):
                return self._result(order, payment)
            if not signature and observed.signature:
                self.db.execute('UPDATE public.payments SET klikqris_transaction_signature=%s WHERE id=%s', (self.encrypter.encrypt_string(observed.signature), payment_id))
            self.db.execute('''UPDATE public.payments SET klikqris_status_failure_count=0,
                klikqris_status_next_check_at=%s,klikqris_status_last_error='status_interval' WHERE id=%s''',
                (next_check, payment_id))
            persisted = (payment['provider_total_payment'] is None
                         or payment['provider_create_state'] in ('creating', 'outcome_unknown',
                             'recovery_unconfirmed', 'transient_qris_image', 'provider_total_pending'))
            if persisted:
                self._persist_invoice(order, payment, observed)
            if observed.status == 'SUCCESS' and not creating:
                result = self._settle(order, payment, payments)
            elif observed.status == 'EXPIRED':
                if order['order_status'] == 'pending_payment':
                    self.orders._release_locked(order, payments, status='expired')
                result = LifecycleResult('provider_expired')
            else:
                order, payment, _ = self._locked(payment_id)
                result = self._result(order, payment)
            # Receipt/inventory authority precedes event rows in the lock graph.
            if persisted:
                self._record_observation(payment, observed.facts(), 'provider_create')
            if not creating:
                self._record_observation(payment, observed.facts(), 'reconciliation')
            if hint and result.state in ('paid', 'review'):
                self.db.execute('UPDATE public.payment_events SET processed_at=%s WHERE id=%s AND processed_at IS NULL', (self.clock(), hint['id']))
            return result

    def _persist_invoice(self, order, payment, observed):
        url = observed.qr_url if valid_qr_url(observed.qr_url, self.adapter.config.environment, self.adapter.config.allowed_hosts) else None
        expiry = payment['provider_expires_at'] or observed.expiry
        total = payment['provider_total_payment'] if payment['provider_total_payment'] is not None else observed.total
        fee = payment['provider_fee'] if payment['provider_fee'] is not None else total - payment['amount']
        state = 'created'
        if observed.status != 'PENDING':
            state = 'provider_' + observed.status
        elif not url:
            state = 'transient_qris_image' if observed.transient_image else 'provider_qris_unusable'
        elif expiry is None or deadline(expiry) <= self.clock():
            state = 'provider_expired'
        facts = observed.facts()
        facts.update(total_amount=total, expired_at=deadline(expiry).isoformat() if expiry else None)
        # URL/signature are protected separately; no arbitrary provider payload survives.
        self.db.execute('''UPDATE public.payments SET provider_payload=%s::jsonb,provider_qris_url=%s,
            provider_reference=COALESCE(provider_reference,%s),provider_total_payment=COALESCE(provider_total_payment,%s),
            provider_fee=COALESCE(provider_fee,%s),provider_payment_method='qris',
            provider_expires_at=COALESCE(provider_expires_at,%s),provider_created_at=COALESCE(provider_created_at,%s),
            provider_create_state=%s,provider_create_last_error=%s,updated_at=%s WHERE id=%s''',
            (json.dumps(facts), self.encrypter.encrypt_string(url), observed.reference,
             total, fee, expiry, self.clock(), state,
             None if state == 'created' else state, self.clock(), payment['id']))
        payment.update(provider_total_payment=total, provider_fee=fee,
                       provider_expires_at=expiry, provider_reference=payment['provider_reference'] or observed.reference)
        # Effective payability intersects local and immutable provider deadlines.

    def _record_observation(self, payment, facts, event_type):
        encoded = json.dumps(facts, sort_keys=True, separators=(',', ':'))
        identity = str(payment['id']) if event_type == 'provider_create' else str(payment['id']) + encoded
        event_key = hashlib.sha256(('klikqris:' + event_type + ':' + identity).encode()).hexdigest()
        self.db.execute('''INSERT INTO public.payment_events
            (provider,event_type,payment_id,merchant_ref,provider_reference,provider_status,
             raw_payload,idempotency_key,received_at,last_received_at,received_count)
            VALUES ('klikqris',%s,%s,%s,%s,%s,%s::jsonb,%s,%s,%s,1)
            ON CONFLICT (provider,event_type,idempotency_key) DO NOTHING''',
            (event_type, payment['id'], payment['merchant_ref'], payment['provider_reference'],
             facts['status'], encoded, event_key, self.clock(), self.clock()))

    def _settle(self, order, payment, payments):
        if payment['amount'] != order['total_amount'] or order['currency'] != 'IDR':
            self.orders.review_locked(order, payment, 'receipt_conflict')
            return LifecycleResult('review')
        reference = payment['provider_reference'] or payment['merchant_ref']
        claim = claim_provider_reference(self.db, provider='klikqris', reference=reference,
            beneficiary='order:' + str(order['id']), currency='IDR', amount=payment['amount'],
            target_type='order_payment', target_id=str(payment['id']), now=self.clock(), using=self.using)
        if claim.held:
            self.orders.review_locked(order, payment, 'receipt_conflict')
            return LifecycleResult('review')
        deadlines = [deadline(order['expires_at']), deadline(payment['expired_at'])]
        if payment['provider_expires_at']:
            deadlines.append(deadline(payment['provider_expires_at']))
        if order['order_status'] == 'pending_payment' and min(deadlines) <= self.clock():
            self.orders._release_locked(order, payments, status='expired')
        result = self.orders.paid_locked(order, payment, payments)
        return LifecycleResult('review' if result.review_id is not None else 'paid')

    def cancel(self, payment_id):
        """Record unsupported remote cancellation; never call a provider endpoint."""
        self._outside()
        with transaction.atomic(using=self.using):
            order, payment, _ = self._locked(payment_id)
            if order['order_status'] not in ('cancelled', 'expired'):
                return LifecycleResult('not_cancelled')
            at = self.clock()
            self.db.execute("""INSERT INTO public.provider_cancellation_attempts
                (order_id,payment_id,provider,state,finished_at,created_at,updated_at)
                VALUES (%s,%s,'klikqris','unsupported',%s,%s,%s)
                ON CONFLICT (payment_id) DO NOTHING""", (order['id'], payment_id, at, at, at))
            return LifecycleResult('unsupported')
