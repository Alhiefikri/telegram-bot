"""Post-commit sandbox payment advancement with fenced provider observations."""
from dataclasses import dataclass, replace
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo
import hashlib
import json

from django.db import connections, transaction

from ..checkout.db import Database
from ..checkout.lifecycle import OrderLifecycle
from .receipts import claim_provider_reference
from .sakurupiah import CreateRequest, InvoiceIdentity, OutcomeState, SakurupiahSandboxAdapter
from .sakurupiah_urls import (
    validate_sakurupiah_checkout_url, validate_sakurupiah_qr_image_url,
    validate_sakurupiah_qris_payload,
)


_JAKARTA = ZoneInfo('Asia/Jakarta')


def _now():
    return datetime.now(_JAKARTA).replace(tzinfo=None)


def _deadline(value):
    if isinstance(value, str):
        value = datetime.fromisoformat(value.replace('Z', '+00:00'))
    if not isinstance(value, datetime):
        raise ValueError('Invalid deadline')
    return value.astimezone(_JAKARTA).replace(tzinfo=None) if value.tzinfo else value




def _invoice_expiry(outcome, payment, merchant_fee):
    if (outcome.state is not OutcomeState.CREATED or not outcome.provider_ref
            or type(outcome.fee) is not int or outcome.fee < 0
            or type(outcome.total) is not int
            or outcome.total != payment['amount'] + (outcome.fee if merchant_fee == 2 else 0)):
        raise ValueError('Invalid provider invoice')
    return _deadline(outcome.expiry)


def _presentation(outcome, payment, order, merchant_fee, expiry, *, environment='sandbox'):
    effective = min(expiry, _deadline(payment['expired_at']), _deadline(order['expires_at']))
    payload, image, url = None, None, outcome.checkout_url
    if validate_sakurupiah_qris_payload(outcome.qr, getattr(outcome, 'total', None)):
        payload, url = outcome.qr, None
    elif validate_sakurupiah_qr_image_url(outcome.qr):
        image = outcome.qr
        if url is not None and not validate_sakurupiah_checkout_url(url, environment):
            url = None
    elif not validate_sakurupiah_checkout_url(url, environment):
        raise ValueError('Unsupported QR presentation')
    if url is not None and not validate_sakurupiah_checkout_url(url, environment):
        raise ValueError('Invalid checkout URL')
    snapshot = {'mode': 'qris' if payload else ('qris_image' if image else 'hosted'),
                'qris_payload': payload, 'qris_image_url': image, 'checkout_url': url,
                'environment': environment,
                'customer_pays_fee': merchant_fee == 2,
                'effective_expires_at': effective.replace(tzinfo=_JAKARTA).isoformat()}
    return expiry, snapshot


def _valid_presentation(presentation, total, environment='sandbox'):
    if type(presentation) is not dict:
        return False
    if presentation.get('environment', environment) != environment:
        return False
    mode = presentation.get('mode')
    payload, image, url = (presentation.get(key) for key in ('qris_payload', 'qris_image_url', 'checkout_url'))
    if mode == 'qris':
        return image is None and url is None and validate_sakurupiah_qris_payload(payload, total)
    if mode == 'qris_image':
        return (payload is None and validate_sakurupiah_qr_image_url(image)
                and (url is None or validate_sakurupiah_checkout_url(url, environment)))
    if mode == 'hosted':
        return payload is None and image is None and validate_sakurupiah_checkout_url(url, environment)
    return False


@dataclass(frozen=True, slots=True, repr=False)
class LifecycleResult:
    state: str
    presentation: dict | None = None
    fee: int | None = None
    total: int | None = None
    customer_pays_fee: bool | None = None
    retry_at: int | None = None


class SakurupiahLifecycle:
    def __init__(self, adapter, encrypter, *, using='default', retry_seconds=60):
        if type(retry_seconds) is not int or not 0 <= retry_seconds <= 3600:
            raise ValueError('Invalid create retry interval')
        self.adapter = adapter
        self.environment = getattr(adapter, 'environment', 'sandbox') if isinstance(adapter, SakurupiahSandboxAdapter) else 'sandbox'
        if isinstance(adapter, SakurupiahSandboxAdapter):
            adapter.enable_coordination(using=using)
        self.encrypter = encrypter
        self.using = using
        self.retry_seconds = retry_seconds
        self.db = Database(using)
        self.orders = OrderLifecycle(using=using, encrypter=encrypter, clock=_now)

    def _outside(self):
        if any(c.in_atomic_block or not c.get_autocommit()
               for c in connections.all(initialized_only=True)):
            raise RuntimeError('Lifecycle requires an outermost transaction boundary')
        if connections[self.using].vendor != 'postgresql':
            raise RuntimeError('Lifecycle requires PostgreSQL')

    def _locked(self, payment_id):
        order, payment, payments, _ = self.orders.lock_payment(payment_id)
        if (payment['provider'], payment['method'], payment['currency']) != ('sakurupiah', 'QRIS', 'IDR'):
            raise ValueError('Invalid payment rail')
        payment.update(self.db.one('''SELECT p.provider_create_attempted_at,
            (SELECT e.raw_payload->>'request' FROM public.payment_events e
             WHERE e.provider='sakurupiah' AND e.event_type='provider_create'
               AND e.provider_status='candidate_request' AND e.merchant_ref=p.merchant_ref
               AND e.idempotency_key=%s) AS provider_request_snapshot
            FROM public.payments p WHERE p.id=%s''', (self._request_key(payment_id), payment_id)))
        return order, payment, payments

    def _flight(self, payment_id, operation):
        self._outside()
        key = 'p08:sakurupiah:' + str(payment_id)
        acquired = self.db.one('SELECT pg_try_advisory_lock(hashtextextended(%s,0)) AS acquired', (key,))
        if not acquired['acquired']:
            return LifecycleResult('busy')
        try:
            return operation()
        finally:
            self.db.one('SELECT pg_advisory_unlock(hashtextextended(%s,0)) AS released', (key,))

    @staticmethod
    def _request_key(payment_id):
        return hashlib.sha256(('candidate:sakurupiah:request:' + str(payment_id)).encode()).hexdigest()

    def _policy(self, payment):
        if payment.get('provider_request_snapshot') is not None:
            snapshot = self.encrypter.decrypt_json(payment['provider_request_snapshot'])
            if (type(snapshot) is not dict or snapshot.get('payment_id') != str(payment['id'])
                    or snapshot.get('merchant_ref') != payment['merchant_ref']
                    or snapshot.get('amount') != payment['amount']
                    or snapshot.get('channel') != payment['sakurupiah_channel']
                    or snapshot.get('environment', 'sandbox') != self.environment):
                raise ValueError('Invalid policy snapshot')
            payer = snapshot.get('merchant_fee')
            if type(payer) is not int or payer not in (1, 2):
                raise ValueError('Invalid policy snapshot')
            return payer, snapshot.get('create_request')
        if payment['provider_presentation']:
            snapshot = self.encrypter.decrypt_json(payment['provider_presentation'])
            if type(snapshot) is not dict:
                raise ValueError('Invalid policy snapshot')
            if type(snapshot.get('customer_pays_fee')) is bool:
                return 2 if snapshot['customer_pays_fee'] else 1, None
            raise ValueError('Invalid policy snapshot')
        fee, total = payment['provider_fee'], payment['provider_total_payment']
        if type(fee) is int and fee >= 0 and type(total) is int:
            if total == payment['amount'] + fee:
                return 2, None
            if total == payment['amount']:
                return 1, None
        return None, None

    @staticmethod
    def _identity(payment):
        return InvoiceIdentity(payment['merchant_ref'], payment['amount'], payment['sakurupiah_channel'])

    @staticmethod
    def _snapshot(order, payment):
        return (str(order['id']), order['expires_at'], order['total_amount'], order['currency'],
                str(payment['id']), payment['order_id'], payment['provider'], payment['method'],
                payment['currency'], payment['amount'], payment['merchant_ref'],
                payment['sakurupiah_channel'], payment['expired_at'], payment['provider_reference'],
                payment['provider_total_payment'], payment['provider_fee'], payment['provider_expires_at'],
                payment['provider_presentation'], payment['provider_create_state'],
                payment['provider_payment_method'], payment.get('provider_request_snapshot'))

    def _result(self, order, payment):
        if self.db.one("SELECT id FROM public.manual_fulfillment_reviews WHERE order_id=%s AND state IN ('pending','refund_uncertain')", (order['id'],)):
            return LifecycleResult('review')
        if payment['status'] == 'paid':
            return LifecycleResult('paid')
        if payment['status'] in ('expired', 'failed', 'refunded'):
            return LifecycleResult(payment['status'])
        if order['order_status'] != 'pending_payment' or order['payment_status'] != 'pending':
            return LifecycleResult('not_payable')
        state = payment['provider_create_state'] or 'pending'
        if state == 'created':
            try:
                if not payment['provider_presentation']:
                    return LifecycleResult('provider_invalid_result')
                if min(_deadline(payment['expired_at']), _deadline(order['expires_at']),
                       _deadline(payment['provider_expires_at'])) <= _now():
                    return LifecycleResult('expired')
                presentation = self.encrypter.decrypt_json(payment['provider_presentation'])
                if (not _valid_presentation(presentation, payment['provider_total_payment'],
                                            self.environment)
                        or type(presentation.get('customer_pays_fee')) is not bool
                        or _deadline(presentation.get('effective_expires_at')) <= _now()
                        or _deadline(presentation.get('effective_expires_at')) > min(
                            _deadline(payment['expired_at']), _deadline(order['expires_at']),
                            _deadline(payment['provider_expires_at']))
                        or not payment['provider_reference']
                        or payment['provider_payment_method'] != payment['sakurupiah_channel']
                        or type(payment['provider_fee']) is not int or payment['provider_fee'] < 0
                        or payment['provider_total_payment'] != payment['amount'] + (
                            payment['provider_fee'] if presentation['customer_pays_fee'] else 0)):
                    return LifecycleResult('provider_invalid_result')
            except Exception:
                return LifecycleResult('provider_invalid_result')
            presentation = {**presentation, 'environment': self.environment}
            return LifecycleResult('created', presentation, payment['provider_fee'],
                                   payment['provider_total_payment'], presentation['customer_pays_fee'])
        return LifecycleResult(state)

    def classify(self, payment_id):
        self._outside()
        with transaction.atomic(using=self.using):
            order, payment, _ = self._locked(payment_id)
            return self._result(order, payment)

    def create(self, payment_id, *, phone, callback_url, return_url, merchant_fee=2):
        return self._flight(payment_id, lambda: self._create(
            payment_id, phone=phone, callback_url=callback_url, return_url=return_url, merchant_fee=merchant_fee))

    def _create(self, payment_id, *, phone, callback_url, return_url, merchant_fee=2):
        with transaction.atomic(using=self.using):
            order, payment, _ = self._locked(payment_id)
            state = payment['provider_create_state']
            request_fields = dict(phone=phone, callback_url=callback_url, return_url=return_url, merchant_fee=merchant_fee)
            if (state not in (None, 'create_failed') or payment['provider_reference'] is not None
                    or any(payment[k] is not None for k in ('provider_total_payment', 'provider_fee',
                           'provider_expires_at', 'provider_payment_method'))
                    or payment['status'] != 'pending' or order['order_status'] != 'pending_payment'
                    or order['payment_status'] != 'pending' or payment['amount'] != order['total_amount']
                    or order['currency'] != 'IDR'
                    or min(_deadline(payment['expired_at']), _deadline(order['expires_at'])) <= _now()):
                return self._result(order, payment)
            request = CreateRequest(self._identity(payment), **request_fields)
            if payment.get('provider_request_snapshot') is not None:
                try:
                    payer, original = self._policy(payment)
                except (ValueError, TypeError):
                    return LifecycleResult('provider_invalid_result')
                if payer != merchant_fee or original != request_fields:
                    return LifecycleResult('provider_invalid_result')
            elif state is None and payment['provider_presentation'] is None:
                encrypted = self.encrypter.encrypt_json({
                    'payment_id': str(payment_id), 'merchant_ref': payment['merchant_ref'],
                    'amount': payment['amount'], 'channel': payment['sakurupiah_channel'],
                    'environment': self.environment, 'merchant_fee': merchant_fee, 'create_request': request_fields})
                self.db.execute('''INSERT INTO public.payment_events
                    (provider,event_type,merchant_ref,provider_status,raw_payload,idempotency_key,
                     received_at,last_received_at,received_count)
                    VALUES ('sakurupiah','provider_create',%s,'candidate_request',%s::jsonb,%s,%s,%s,1)''',
                    (payment['merchant_ref'], json.dumps({'request': encrypted}),
                     self._request_key(payment_id), _now(), _now()))
                payment['provider_request_snapshot'] = encrypted
            else:
                return LifecycleResult('provider_invalid_result')
            attempted = payment.get('provider_create_attempted_at')
            if state == 'create_failed' and attempted is not None and _deadline(attempted) + timedelta(seconds=self.retry_seconds) > _now():
                return LifecycleResult('create_failed')
            self.db.execute("""UPDATE public.payments SET provider_create_state='outcome_unknown',
                provider_create_attempted_at=%s,provider_create_last_error=NULL,updated_at=%s WHERE id=%s""",
                (_now(), _now(), payment_id))
            payment['provider_create_state'] = 'outcome_unknown'
            before = self._snapshot(order, payment)
        outcome = self.adapter.create(request)
        with transaction.atomic(using=self.using):
            order, payment, _ = self._locked(payment_id)
            if self._snapshot(order, payment) != before or payment['status'] in ('paid', 'refunded'):
                return self._result(order, payment)
            if outcome.identity != request.identity:
                return LifecycleResult('recovery_unconfirmed')
            if outcome.state is OutcomeState.REJECTED:
                self.db.execute("""UPDATE public.payments SET provider_create_state='create_failed',
                    provider_create_last_error='create_rejected',updated_at=%s WHERE id=%s""", (_now(), payment_id))
                return LifecycleResult('create_failed', retry_at=outcome.retry_at)
            if outcome.state is not OutcomeState.CREATED:
                return self._result(order, payment)
            return self._persist_invoice(order, payment, outcome, merchant_fee)

    def _persist_invoice(self, order, payment, outcome, merchant_fee):
        try:
            expiry = _invoice_expiry(replace(outcome, state=OutcomeState.CREATED), payment, merchant_fee)
        except (ValueError, TypeError, OverflowError):
            self.db.execute("""UPDATE public.payments SET provider_create_state='provider_invalid_result',
                provider_create_last_error='invalid_invoice',updated_at=%s WHERE id=%s""", (_now(), payment['id']))
            return LifecycleResult('provider_invalid_result')
        try:
            _, presentation = _presentation(outcome, payment, order, merchant_fee, expiry,
                                            environment=self.environment)
            encrypted = self.encrypter.encrypt_json(presentation)
            state, error = 'created', None
        except (ValueError, TypeError, OverflowError):
            encrypted = None
            state, error = 'provider_qris_unusable', 'provider_presentation_unusable'
        self.db.execute('''UPDATE public.payments SET provider_reference=%s,provider_total_payment=%s,
            provider_fee=%s,provider_expires_at=%s,provider_payment_method=%s,provider_presentation=%s,
            provider_create_state=%s,provider_create_last_error=%s,updated_at=%s WHERE id=%s''',
            (outcome.provider_ref, outcome.total, outcome.fee, expiry, payment['sakurupiah_channel'], encrypted,
             state, error, _now(), payment['id']))
        payment.update(provider_reference=outcome.provider_ref, provider_total_payment=outcome.total,
                       provider_fee=outcome.fee, provider_expires_at=expiry,
                       provider_payment_method=payment['sakurupiah_channel'],
                       provider_presentation=encrypted, provider_create_state=state)
        return self._result(order, payment)

    def recover(self, payment_id, *, background=False):
        return self._flight(payment_id, lambda: self._recover(payment_id, background=background))

    def _recover(self, payment_id, *, background=False):
        with transaction.atomic(using=self.using):
            order, payment, _ = self._locked(payment_id)
            if payment['provider_create_state'] == 'create_failed' and not payment['provider_reference']:
                try:
                    _, request_fields = self._policy(payment)
                    if type(request_fields) is not dict:
                        raise ValueError
                except (ValueError, TypeError):
                    return LifecycleResult('provider_invalid_result')
            else:
                request_fields = None
        if request_fields is not None:
            return self._create(payment_id, **request_fields)
        return self._observe_locked(payment_id, recovery=True, background=background)

    def consume_callbacks(self, payment_id):
        return self._flight(payment_id, lambda: self._consume_callbacks(payment_id))

    def _consume_callbacks(self, payment_id):
        with transaction.atomic(using=self.using):
            order, payment, _ = self._locked(payment_id)
            hint = self.db.one('''SELECT id,provider_reference,provider_status FROM public.payment_events
                WHERE provider='sakurupiah' AND event_type='callback' AND merchant_ref=%s
                  AND (provider_reference=%s OR %s IS NULL) AND processed_at IS NULL
                ORDER BY CASE provider_status WHEN 'berhasil' THEN 0 WHEN 'expired' THEN 1 ELSE 2 END,id LIMIT 1''',
                (payment['merchant_ref'], payment['provider_reference'], payment['provider_reference']))
            if hint is None:
                return self._result(order, payment)
        return self._observe_locked(payment_id, recovery=True, hint=hint)

    def _mark_hint(self, hint, status):
        rank = {'pending': 0, 'expired': 1, 'berhasil': 2}
        if hint is not None and rank.get(hint['provider_status'], 3) <= rank[status]:
            self.db.execute('UPDATE public.payment_events SET processed_at=%s WHERE id=%s AND processed_at IS NULL',
                            (_now(), hint['id']))

    def refresh(self, payment_id, *, background=False):
        return self._flight(payment_id, lambda: self._observe_locked(payment_id, recovery=True, background=background))

    def _observe(self, payment_id, *, recovery, hint=None):
        return self._flight(payment_id, lambda: self._observe_locked(payment_id, recovery=recovery, hint=hint))

    def _observe_locked(self, payment_id, *, recovery, hint=None, background=False):
        with transaction.atomic(using=self.using):
            order, payment, _ = self._locked(payment_id)
            if payment['status'] in ('paid', 'refunded'):
                if payment['status'] == 'paid' and hint is not None:
                    self.db.execute('UPDATE public.payment_events SET processed_at=%s WHERE id=%s AND processed_at IS NULL',
                                    (_now(), hint['id']))
                return self._result(order, payment)
            total = payment['provider_total_payment']
            identity = self._identity(payment)
            reference = payment['provider_reference']
            try:
                merchant_fee, _ = self._policy(payment)
            except (ValueError, TypeError):
                return LifecycleResult('provider_invalid_result')
            before = self._snapshot(order, payment)
        options = {'provider_total': total}
        if isinstance(self.adapter, SakurupiahSandboxAdapter):
            options.update(merchant_fee=merchant_fee, background=background)
        lookup_ref = reference or (hint['provider_reference'] if hint is not None else None)
        if lookup_ref:
            outcome = self.adapter.status(identity, lookup_ref, **options)
        elif recovery:
            recovered = self.adapter.recover(identity, **options)
            if not recovered.provider_ref or recovered.state in (OutcomeState.UNKNOWN, OutcomeState.REJECTED):
                return LifecycleResult('recovery_unconfirmed')
            outcome = self.adapter.status(identity, recovered.provider_ref, **options)
            if (outcome.provider_ref != recovered.provider_ref or any(
                    getattr(recovered, key) is not None and getattr(outcome, key) is not None
                    and getattr(recovered, key) != getattr(outcome, key)
                    for key in ('total', 'fee', 'expiry', 'merchant_fee'))):
                return LifecycleResult('recovery_unconfirmed')
        else:
            return LifecycleResult('recovery_unconfirmed')
        with transaction.atomic(using=self.using):
            order, payment, payments = self._locked(payment_id)
            if self._snapshot(order, payment) != before:
                return self._result(order, payment)
            if (outcome.identity == identity and outcome.state is OutcomeState.UNKNOWN
                    and outcome.success_indicated and reference and outcome.provider_ref == reference):
                self.db.execute("""UPDATE public.payments SET provider_create_state='provider_berhasil',
                    updated_at=%s WHERE id=%s""", (_now(), payment_id))
                return LifecycleResult('recovery_unconfirmed')
            if (outcome.identity != identity or outcome.state in (OutcomeState.UNKNOWN, OutcomeState.REJECTED)
                    or not outcome.provider_ref or (lookup_ref and outcome.provider_ref != lookup_ref)
                    or not self._observation_matches(payment, outcome, merchant_fee)):
                return LifecycleResult('recovery_unconfirmed')
            if reference is None:
                self.db.execute('UPDATE public.payments SET provider_reference=%s,updated_at=%s WHERE id=%s',
                                (outcome.provider_ref, _now(), payment_id))
                payment['provider_reference'] = outcome.provider_ref
            self._persist_observation(payment, outcome)
            if outcome.state is OutcomeState.SUCCESS:
                self.db.execute("""UPDATE public.payments SET provider_create_state='provider_berhasil',
                    updated_at=%s WHERE id=%s""", (_now(), payment_id))
                result = self._settle(order, payment, payments)
                self._mark_hint(hint, 'berhasil')
                return result
            if outcome.state is OutcomeState.EXPIRED:
                success = self.db.one("""SELECT id FROM public.payment_events WHERE provider='sakurupiah'
                    AND merchant_ref=%s AND provider_reference=%s AND provider_status='berhasil'
                    AND processed_at IS NULL LIMIT 1""", (identity.merchant_ref, outcome.provider_ref))
                if success is not None or payment['provider_create_state'] == 'provider_berhasil':
                    return LifecycleResult('recovery_unconfirmed')
                self.db.execute("UPDATE public.payments SET provider_create_state='provider_expired',updated_at=%s WHERE id=%s",
                                (_now(), payment_id))
                if order['order_status'] == 'pending_payment' and payment['status'] == 'pending':
                    self.orders._release_locked(order, payments, status='expired')
                self._mark_hint(hint, 'expired')
                return LifecycleResult('provider_expired')
            if payment['provider_create_state'] == 'provider_berhasil':
                return LifecycleResult('recovery_unconfirmed')
            if payment['provider_create_state'] != 'created':
                if (outcome.total is not None and outcome.fee is not None and outcome.expiry is not None
                        and merchant_fee is not None):
                    result = self._persist_invoice(order, payment, outcome, merchant_fee)
                else:
                    self.db.execute("""UPDATE public.payments SET provider_create_state='provider_total_pending',
                        updated_at=%s WHERE id=%s""", (_now(), payment_id))
                    result = LifecycleResult('provider_total_pending')
            else:
                result = self._result(order, payment)
            self._mark_hint(hint, 'pending')
            return result

    def _persist_observation(self, payment, outcome):
        values = {}
        for key, observed in (('provider_total_payment', outcome.total), ('provider_fee', outcome.fee),
                              ('provider_expires_at', None if outcome.expiry is None else _deadline(outcome.expiry)),
                              ('provider_payment_method', payment['sakurupiah_channel'])):
            if payment[key] is None and observed is not None:
                values[key] = observed
        if values:
            self.db.execute('UPDATE public.payments SET ' + ','.join(key + '=%s' for key in values)
                            + ',updated_at=%s WHERE id=%s', (*values.values(), _now(), payment['id']))
            payment.update(values)

    @staticmethod
    def _observation_matches(payment, outcome, merchant_fee):
        if (outcome.state not in (OutcomeState.PENDING, OutcomeState.SUCCESS, OutcomeState.EXPIRED)
                or type(outcome.provider_ref) is not str or not outcome.provider_ref
                or outcome.provider_ref.strip() != outcome.provider_ref or len(outcome.provider_ref) > 255
                or (outcome.merchant_fee is not None and merchant_fee is not None
                    and outcome.merchant_fee != merchant_fee)):
            return False
        for key, observed in (('provider_total_payment', outcome.total), ('provider_fee', outcome.fee)):
            if observed is not None:
                if type(observed) is not int or observed < (0 if key == 'provider_fee' else 1):
                    return False
                if payment[key] is not None and observed != payment[key]:
                    return False
        total = payment['provider_total_payment'] if payment['provider_total_payment'] is not None else outcome.total
        fee = payment['provider_fee'] if payment['provider_fee'] is not None else outcome.fee
        if total is not None and merchant_fee is not None and fee is not None:
            if total != payment['amount'] + (fee if merchant_fee == 2 else 0):
                return False
        if outcome.observed_amount is not None and outcome.observed_amount not in (payment['amount'], total):
            return False
        if total is None and outcome.observed_amount != payment['amount']:
            return False
        if outcome.expiry is not None:
            try:
                expiry = _deadline(outcome.expiry)
                if payment['provider_expires_at'] is not None and expiry != _deadline(payment['provider_expires_at']):
                    return False
            except (ValueError, TypeError, OverflowError):
                return False
        return True

    def _review(self, order, payment, reason):
        self.orders.review_locked(order, payment, reason)
        return LifecycleResult('review')

    def _settle(self, order, payment, payments):
        if payment['amount'] != order['total_amount'] or order['currency'] != payment['currency']:
            return self._review(order, payment, 'receipt_conflict')
        deadlines = [_deadline(order['expires_at']), _deadline(payment['expired_at'])]
        if payment['provider_expires_at'] is not None:
            deadlines.append(_deadline(payment['provider_expires_at']))
        overdue = order['order_status'] == 'pending_payment' and min(deadlines) <= _now()
        ref = payment['provider_reference'].strip()
        claim = claim_provider_reference(
            self.db, provider='sakurupiah', reference=ref,
            beneficiary='order:' + str(order['id']), currency='IDR',
            amount=payment['amount'], target_type='order_payment',
            target_id=str(payment['id']), now=_now(), using=self.using,
        )
        if claim.held:
            return self._review(order, payment, 'receipt_conflict')
        if overdue:
            self.orders._release_locked(order, payments, status='expired')
        result = self.orders.paid_locked(order, payment, payments)
        return LifecycleResult('review' if result.review_id is not None else 'paid')
