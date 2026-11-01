"""Explicit, post-commit sandbox payment advancement; no background worker is installed."""
import hashlib
import json
import re
from dataclasses import dataclass
from datetime import datetime
from urllib.parse import urlsplit
from zoneinfo import ZoneInfo

from django.db import connections, transaction

from ..checkout.db import Database
from ..checkout.errors import CheckoutError
from ..checkout.inventory import InventoryLedger
from ..receipt.jobs import wake_after_commit
from .sakurupiah import CreateRequest, InvoiceIdentity, OutcomeState


_JAKARTA = ZoneInfo('Asia/Jakarta')


def _now():
    return datetime.now(_JAKARTA).replace(tzinfo=None)


def _deadline(value):
    if isinstance(value, str):
        value = datetime.fromisoformat(value.replace('Z', '+00:00'))
    if not isinstance(value, datetime):
        raise ValueError('Invalid deadline')
    return value.astimezone(_JAKARTA).replace(tzinfo=None) if value.tzinfo else value


def _url(value):
    if not isinstance(value, str) or len(value) > 2048 or value.strip() != value:
        return False
    if any(ord(c) <= 32 or ord(c) >= 127 or c == '\\' for c in value):
        return False
    try:
        parts = urlsplit(value)
        return (parts.scheme == 'https' and parts.hostname == 'sakurupiah.id'
                and parts.netloc == 'sakurupiah.id' and not parts.fragment
                and parts.username is None and parts.password is None and parts.port is None)
    except ValueError:
        return False


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
    if outcome.qr is not None and not _url(outcome.qr):
        raise ValueError('Unsupported QR presentation')
    if outcome.checkout_url is not None and not _url(outcome.checkout_url):
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


class SakurupiahLifecycle:
    def __init__(self, adapter, encrypter, *, using='default'):
        self.adapter = adapter
        self.encrypter = encrypter
        self.using = using
        self.db = Database(using)
        self.inventory = InventoryLedger(using)

    def _outside(self):
        if any(c.in_atomic_block or not c.get_autocommit()
               for c in connections.all(initialized_only=True)):
            raise RuntimeError('Lifecycle requires an outermost transaction boundary')
        if connections[self.using].vendor != 'postgresql':
            raise RuntimeError('Lifecycle requires PostgreSQL')

    def _locked(self, payment_id):
        initial = self.db.one('SELECT order_id FROM public.payments WHERE id=%s', (payment_id,))
        if initial is None:
            raise ValueError('Payment not found')
        order = self.db.one('''SELECT id,expires_at,total_amount,currency,order_status,payment_status
            FROM public.orders WHERE id=%s FOR UPDATE''', (initial['order_id'],))
        payments = self.db.rows('''SELECT id,order_id,provider,method,currency,amount,merchant_ref,
            sakurupiah_channel,expired_at,status,provider_reference,provider_total_payment,provider_fee,
            provider_expires_at,provider_presentation,provider_create_state,provider_payment_method
            FROM public.payments WHERE order_id=%s ORDER BY id FOR UPDATE''', (initial['order_id'],))
        payment = next((p for p in payments if str(p['id']) == str(payment_id)), None)
        if order is None or payment is None:
            raise ValueError('Payment not found')
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
        if payment['status'] == 'paid':
            return LifecycleResult('paid')
        if self.db.one("SELECT id FROM public.manual_fulfillment_reviews WHERE order_id=%s AND state IN ('pending','refund_uncertain')", (order['id'],)):
            return LifecycleResult('review')
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
                        or (presentation.get('qris_image_url') is not None and not _url(presentation['qris_image_url']))
                        or (presentation.get('checkout_url') is not None and not _url(presentation['checkout_url']))
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
            return LifecycleResult('created', presentation)
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
                self._mark_hint(hint, 'expired')
                return LifecycleResult('provider_expired')
            self._mark_hint(hint, 'pending')
            return self._result(order, payment)

    def _review(self, order, payment, reason):
        self.db.execute('''INSERT INTO public.manual_fulfillment_reviews
            (order_id,payment_id,amount,currency,reason,state,provider_reference,created_at,updated_at)
            VALUES (%s,%s,%s,%s,%s,'pending',%s,%s,%s) ON CONFLICT (payment_id) DO NOTHING''',
            (order['id'], payment['id'], payment['provider_total_payment'] or payment['amount'],
             payment['currency'], reason, payment['provider_reference'], _now(), _now()))
        return LifecycleResult('review')

    def _settle(self, order, payment, payments):
        if payment['amount'] != order['total_amount'] or order['currency'] != payment['currency']:
            return self._review(order, payment, 'receipt_conflict')
        late = (order['order_status'] != 'pending_payment' or order['payment_status'] != 'pending'
                or payment['status'] != 'pending'
                or any(p['id'] != payment['id'] and p['status'] == 'paid' for p in payments)
                or min(_deadline(order['expires_at']), _deadline(payment['expired_at']), _deadline(payment['provider_expires_at'])) <= _now())
        review = self.db.one("SELECT id FROM public.manual_fulfillment_reviews WHERE order_id=%s AND state IN ('pending','refund_uncertain')", (order['id'],))
        ref = payment['provider_reference'].strip()
        segment = ref if re.fullmatch(r'[\x21-\x7e]{1,64}', ref) and '|' not in ref else hashlib.sha256(ref.encode()).hexdigest()
        key = 'v1|provider_reference|sakurupiah|default|' + segment
        payload = {'version': 1, 'identity': key, 'beneficiary': 'order:' + str(order['id']),
                   'currency': 'IDR', 'amount_units': str(payment['amount']), 'decimals': 0,
                   'target_type': 'order_payment', 'target_id': str(payment['id'])}
        fingerprint = hashlib.sha256(json.dumps(payload, ensure_ascii=True, separators=(',', ':')).encode()).hexdigest()
        now = _now()
        savepoint = transaction.savepoint(using=self.using)
        self.db.execute('''INSERT INTO public.external_money_receipts
            (canonical_key,provider,identity_kind,account_namespace,reference,beneficiary,currency,amount_units,
             decimals,status,target_type,target_id,fingerprint,evidence_payload,claimed_at,created_at,updated_at)
            VALUES (%s,'sakurupiah','provider_reference','default',%s,%s,'IDR',%s,0,'claimed',
                    'order_payment',%s,%s,%s,%s,%s,%s) ON CONFLICT (canonical_key) DO NOTHING''',
            (key, segment, payload['beneficiary'], payload['amount_units'], payload['target_id'], fingerprint,
             json.dumps({'provider': 'sakurupiah', 'identity': 'provider_reference'}), now, now, now))
        receipt = self.db.one('SELECT id,fingerprint,status FROM public.external_money_receipts WHERE canonical_key=%s FOR UPDATE', (key,))
        if receipt is None or receipt['fingerprint'] != fingerprint or receipt['status'] != 'claimed':
            transaction.savepoint_rollback(savepoint, using=self.using)
            return self._review(order, payment, 'receipt_conflict')
        self.db.execute('''INSERT INTO public.external_money_receipt_aliases
            (external_money_receipt_id,alias_key,alias_type,created_at) VALUES (%s,%s,'canonical',%s)
            ON CONFLICT (alias_key) DO NOTHING''', (receipt['id'], key, now))
        alias = self.db.one('SELECT external_money_receipt_id FROM public.external_money_receipt_aliases WHERE alias_key=%s', (key,))
        if alias is None or alias['external_money_receipt_id'] != receipt['id']:
            transaction.savepoint_rollback(savepoint, using=self.using)
            return self._review(order, payment, 'receipt_conflict')
        transaction.savepoint_commit(savepoint, using=self.using)
        if late:
            return self._review(order, payment, 'late_payment')
        if review:
            return LifecycleResult('review')
        savepoint = transaction.savepoint(using=self.using)
        try:
            self.inventory.record_sales(str(order['id']))
        except CheckoutError:
            transaction.savepoint_rollback(savepoint, using=self.using)
            return self._review(order, payment, 'local_credentials_missing')
        transaction.savepoint_commit(savepoint, using=self.using)
        self.db.execute("UPDATE public.payments SET status='paid',paid_at=%s,updated_at=%s WHERE id=%s", (now, now, payment['id']))
        self.db.execute("UPDATE public.orders SET payment_status='paid',order_status='completed',updated_at=%s WHERE id=%s", (now, order['id']))
        wake_after_commit('order', str(order['id']), using=self.using)
        return LifecycleResult('paid')
