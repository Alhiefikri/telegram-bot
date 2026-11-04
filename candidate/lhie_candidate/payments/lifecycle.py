"""Post-commit sandbox payment advancement with fenced provider observations."""
from dataclasses import dataclass
from datetime import datetime
from zoneinfo import ZoneInfo

from django.db import connections, transaction

from ..checkout.db import Database
from ..checkout.lifecycle import OrderLifecycle
from .receipts import claim_provider_reference
from .sakurupiah import CreateRequest, InvoiceIdentity, OutcomeState
from .sakurupiah_urls import validate_sakurupiah_checkout_url, validate_sakurupiah_qr_image_url


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


def _presentation(outcome, payment, order, merchant_fee, expiry):
    effective = min(expiry, _deadline(payment['expired_at']), _deadline(order['expires_at']))
    # Only hosted and provider-hosted QR images are supported; arbitrary EMV
    # strings are not accepted without the legacy parser's integrity checks.
    if outcome.qr is not None and not validate_sakurupiah_qr_image_url(outcome.qr):
        raise ValueError('Unsupported QR presentation')
    if outcome.checkout_url is not None and not validate_sakurupiah_checkout_url(outcome.checkout_url):
        raise ValueError('Invalid checkout URL')
    if outcome.qr is None and outcome.checkout_url is None:
        raise ValueError('Missing presentation')
    snapshot = {'mode': 'qris_image' if outcome.qr else 'hosted', 'qris_payload': None,
                'qris_image_url': outcome.qr, 'checkout_url': outcome.checkout_url,
                'customer_pays_fee': merchant_fee == 2,
                'effective_expires_at': effective.replace(tzinfo=_JAKARTA).isoformat()}
    return expiry, snapshot


@dataclass(frozen=True, slots=True, repr=False)
class LifecycleResult:
    state: str
    presentation: dict | None = None
    fee: int | None = None
    total: int | None = None
    customer_pays_fee: bool | None = None


class SakurupiahLifecycle:
    def __init__(self, adapter, encrypter, *, using='default'):
        self.adapter = adapter
        self.encrypter = encrypter
        self.using = using
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
        return order, payment, payments

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
                payment['provider_presentation'], payment['provider_create_state'])

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
                if (type(presentation) is not dict
                        or presentation.get('mode') not in ('qris_image', 'hosted')
                        or presentation.get('qris_payload') is not None
                        or type(presentation.get('customer_pays_fee')) is not bool
                        or (presentation.get('qris_image_url') is not None
                            and not validate_sakurupiah_qr_image_url(presentation['qris_image_url']))
                        or (presentation.get('checkout_url') is not None
                            and not validate_sakurupiah_checkout_url(presentation['checkout_url']))
                        or (presentation['mode'] == 'qris_image' and not presentation.get('qris_image_url'))
                        or (presentation['mode'] == 'hosted' and not presentation.get('checkout_url'))
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
            return LifecycleResult('created', presentation, payment['provider_fee'],
                                   payment['provider_total_payment'], presentation['customer_pays_fee'])
        return LifecycleResult(state)

    def classify(self, payment_id):
        self._outside()
        with transaction.atomic(using=self.using):
            order, payment, _ = self._locked(payment_id)
            return self._result(order, payment)

    def create(self, payment_id, *, phone, callback_url, return_url, merchant_fee=2):
        self._outside()
        with transaction.atomic(using=self.using):
            order, payment, _ = self._locked(payment_id)
            if (payment['provider_create_state'] is not None or payment['provider_reference'] is not None
                    or any(payment[k] is not None for k in ('provider_presentation', 'provider_total_payment',
                           'provider_fee', 'provider_expires_at', 'provider_payment_method'))
                    or payment['status'] != 'pending' or order['order_status'] != 'pending_payment'
                    or order['payment_status'] != 'pending' or payment['amount'] != order['total_amount']
                    or order['currency'] != 'IDR' or min(_deadline(payment['expired_at']), _deadline(order['expires_at'])) <= _now()):
                return self._result(order, payment)
            request = CreateRequest(self._identity(payment), phone, callback_url, return_url, merchant_fee)
            self.db.execute("UPDATE public.payments SET provider_create_state='outcome_unknown', provider_create_attempted_at=%s, updated_at=%s WHERE id=%s", (_now(), _now(), payment_id))
            payment['provider_create_state'] = 'outcome_unknown'
            before = self._snapshot(order, payment)
        outcome = self.adapter.create(request)
        with transaction.atomic(using=self.using):
            order, payment, _ = self._locked(payment_id)
            if (self._snapshot(order, payment) != before
                    or payment['status'] in ('paid', 'refunded')):
                return self._result(order, payment)
            if outcome.identity != request.identity or outcome.state is not OutcomeState.CREATED:
                return self._result(order, payment)
            try:
                expiry = _invoice_expiry(outcome, payment, merchant_fee)
            except (ValueError, TypeError, OverflowError):
                self.db.execute("UPDATE public.payments SET provider_create_state='provider_invalid_result',provider_create_last_error='invalid_invoice',updated_at=%s WHERE id=%s", (_now(), payment_id))
                return LifecycleResult('provider_invalid_result')
            try:
                _, presentation = _presentation(outcome, payment, order, merchant_fee, expiry)
                encrypted = self.encrypter.encrypt_json(presentation)
                state, error = 'created', None
            except (ValueError, TypeError, OverflowError):
                encrypted = None
                state, error = 'provider_qris_unusable', 'provider_presentation_unusable'
            self.db.execute('''UPDATE public.payments SET provider_reference=%s,provider_total_payment=%s,
                provider_fee=%s,provider_expires_at=%s,provider_payment_method=%s,provider_presentation=%s,
                provider_create_state=%s,provider_create_last_error=%s,updated_at=%s WHERE id=%s''',
                (outcome.provider_ref, outcome.total, outcome.fee, expiry, payment['sakurupiah_channel'], encrypted,
                 state, error, _now(), payment_id))
            payment.update(provider_reference=outcome.provider_ref, provider_total_payment=outcome.total,
                           provider_fee=outcome.fee, provider_expires_at=expiry,
                           provider_payment_method=payment['sakurupiah_channel'],
                           provider_presentation=encrypted, provider_create_state=state)
            return self._result(order, payment)

    def recover(self, payment_id):
        return self._observe(payment_id, recovery=True)

    def consume_callbacks(self, payment_id):
        self._outside()
        key = 'p08:sakurupiah:' + str(payment_id)
        acquired = self.db.one('SELECT pg_try_advisory_lock(hashtextextended(%s,0)) AS acquired', (key,))
        if not acquired['acquired']:
            return LifecycleResult('busy')
        try:
            with transaction.atomic(using=self.using):
                order, payment, _ = self._locked(payment_id)
                hint = self.db.one('''SELECT id,provider_status FROM public.payment_events
                    WHERE provider='sakurupiah' AND event_type='callback'
                      AND merchant_ref=%s AND provider_reference=%s AND processed_at IS NULL
                    ORDER BY id LIMIT 1''', (payment['merchant_ref'], payment['provider_reference']))
                if hint is None:
                    return self._result(order, payment)
            return self._observe(payment_id, recovery=False, hint=hint)
        finally:
            self.db.one('SELECT pg_advisory_unlock(hashtextextended(%s,0)) AS released', (key,))

    def _mark_hint(self, hint, status):
        rank = {'pending': 0, 'expired': 1, 'berhasil': 2}
        if hint is not None and rank.get(hint['provider_status'], 3) <= rank[status]:
            self.db.execute('UPDATE public.payment_events SET processed_at=%s WHERE id=%s AND processed_at IS NULL',
                            (_now(), hint['id']))

    def refresh(self, payment_id):
        return self._observe(payment_id, recovery=False)

    def _observe(self, payment_id, *, recovery, hint=None):
        self._outside()
        with transaction.atomic(using=self.using):
            order, payment, _ = self._locked(payment_id)
            if payment['status'] in ('paid', 'refunded'):
                if payment['status'] == 'paid' and hint is not None:
                    self.db.execute('UPDATE public.payment_events SET processed_at=%s WHERE id=%s AND processed_at IS NULL',
                                    (_now(), hint['id']))
                return self._result(order, payment)
            total = payment['provider_total_payment']
            if type(total) is not int or total <= 0:
                return LifecycleResult('recovery_unconfirmed')
            identity = self._identity(payment)
            reference = payment['provider_reference']
            before = self._snapshot(order, payment)
        if reference:
            outcome = self.adapter.status(identity, reference, provider_total=total)
        elif recovery:
            recovered = self.adapter.recover(identity, provider_total=total)
            if not recovered.provider_ref or recovered.state is OutcomeState.UNKNOWN:
                return LifecycleResult('recovery_unconfirmed')
            outcome = self.adapter.status(identity, recovered.provider_ref, provider_total=total)
            if outcome.provider_ref != recovered.provider_ref:
                return LifecycleResult('recovery_unconfirmed')
        else:
            return LifecycleResult('recovery_unconfirmed')
        with transaction.atomic(using=self.using):
            order, payment, payments = self._locked(payment_id)
            if self._snapshot(order, payment) != before:
                return self._result(order, payment)
            if (outcome.identity != identity or outcome.state is OutcomeState.UNKNOWN
                    or not outcome.provider_ref or (reference and outcome.provider_ref != reference)):
                return LifecycleResult('recovery_unconfirmed')
            # A recovered reference is usable only if the total was independently
            # committed before lookup. It never reconstructs missing presentation.
            if reference is None:
                self.db.execute('UPDATE public.payments SET provider_reference=%s,updated_at=%s WHERE id=%s', (outcome.provider_ref, _now(), payment_id))
                payment['provider_reference'] = outcome.provider_ref
            if outcome.state is OutcomeState.SUCCESS:
                if payment['provider_expires_at'] is None:
                    return LifecycleResult('recovery_unconfirmed')
                result = self._settle(order, payment, payments)
                self._mark_hint(hint, 'berhasil')
                return result
            if outcome.state is OutcomeState.EXPIRED:
                self.db.execute("UPDATE public.payments SET provider_create_state='provider_expired',updated_at=%s WHERE id=%s",
                                (_now(), payment_id))
                if order['order_status'] == 'pending_payment':
                    self.orders._release_locked(order, payments, status='expired')
                self._mark_hint(hint, 'expired')
                return LifecycleResult('provider_expired')
            self._mark_hint(hint, 'pending')
            return self._result(order, payment)

    def _review(self, order, payment, reason):
        self.orders.review_locked(order, payment, reason)
        return LifecycleResult('review')

    def _settle(self, order, payment, payments):
        if payment['amount'] != order['total_amount'] or order['currency'] != payment['currency']:
            return self._review(order, payment, 'receipt_conflict')
        overdue = (order['order_status'] == 'pending_payment'
                   and min(_deadline(order['expires_at']), _deadline(payment['expired_at']),
                           _deadline(payment['provider_expires_at'])) <= _now())
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
