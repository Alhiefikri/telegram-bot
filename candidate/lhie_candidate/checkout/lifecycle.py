"""Canonical Order transitions. Provider adapters verify/claim money before entry.

Public methods own the outer transaction. Authentication adapters supply an
already authenticated actor/Customer ID; role and object ownership are rechecked
from canonical rows. Maintenance/spending pauses affect new checkout, not recovery.
"""
from dataclasses import dataclass
from datetime import datetime, timedelta
import json
from zoneinfo import ZoneInfo

from django.db import DatabaseError, connections, transaction

from lhie_candidate.domain.money import require_integer
from lhie_candidate.receipt.jobs import wake_after_commit
from .db import Database
from .errors import CheckoutError
from .fulfillment import FulfillmentResources
from .inventory import InventoryLedger
from .projection import OrderLifecycleProjection, paid_order_status


_JAKARTA = ZoneInfo('Asia/Jakarta')
_PAYMENT_COLUMNS = '''id,order_id,provider,method,currency,amount,merchant_ref,sakurupiah_channel,
    expired_at,status,paid_at,created_at,provider_reference,provider_total_payment,provider_fee,
    provider_expires_at,provider_presentation,provider_create_state,provider_payment_method,
    payment_proof_submitted_at,binance_auto_verification_enabled,binance_verification_grace_until,
    binance_receiver_type,binance_receiver_value'''


def now():
    return datetime.now(_JAKARTA).replace(tzinfo=None)


@dataclass(frozen=True, slots=True)
class OrderLifecycleResult:
    order: dict
    payment: dict | None
    newly_applied: bool = False
    receipt_eligible: bool = False
    review_id: int | None = None

    @property
    def projection(self):
        return OrderLifecycleProjection.from_rows(self.order, self.payment)


class OrderLifecycle:
    def __init__(self, *, using='default', encrypter=None, clock=None):
        self.using = using
        self.db = Database(using)
        self.inventory = InventoryLedger(using)
        self.fulfillment = FulfillmentResources(using, encrypter=encrypter)
        self.encrypter = encrypter
        self.clock = clock or now

    def _run(self, operation):
        connection = connections[self.using]
        if connection.vendor != 'postgresql' or any(c.in_atomic_block or not c.get_autocommit()
                for c in connections.all(initialized_only=True)):
            raise RuntimeError('OrderLifecycle owns its outer PostgreSQL transaction')
        for attempt in range(5):
            try:
                with transaction.atomic(using=self.using):
                    return operation()
            except DatabaseError as error:
                if getattr(error.__cause__, 'sqlstate', None) not in ('40001', '40P01') or attempt == 4:
                    raise

    def lock_order(self, order_id, *, customer_id=None, refund_payment_id=None):
        """Customer -> original account -> Order -> ordered Payments; caller owns atomicity."""
        self.db.require_transaction()
        initial = self.db.one('SELECT id,customer_id FROM public.orders WHERE id=%s', (order_id,))
        if initial is None or (customer_id is not None and initial['customer_id'] != customer_id):
            raise CheckoutError('foreign_order')
        if initial['customer_id'] is not None:
            self.db.one('SELECT id FROM public.customers WHERE id=%s FOR SHARE', (initial['customer_id'],))
        account = None
        if refund_payment_id is not None:
            from .refunds import OrderRefunds
            payment = self.db.one('SELECT ' + _PAYMENT_COLUMNS + ' FROM public.payments WHERE id=%s AND order_id=%s', (refund_payment_id, order_id))
            if payment is None:
                raise CheckoutError('foreign_payment')
            account = OrderRefunds(using=self.using, encrypter=self.encrypter).lock_account(initial['customer_id'], payment)
        order = self.db.one('''SELECT id,customer_id,order_status,payment_status,total_amount,currency,
            expires_at,checkout_key FROM public.orders WHERE id=%s FOR UPDATE''', (order_id,))
        if order['customer_id'] != initial['customer_id']:
            raise CheckoutError('foreign_order')
        payments = self.db.rows('SELECT ' + _PAYMENT_COLUMNS + ' FROM public.payments WHERE order_id=%s ORDER BY id FOR UPDATE', (order_id,))
        return order, payments, account

    def lock_payment(self, payment_id, *, refund=False):
        initial = self.db.one('SELECT order_id FROM public.payments WHERE id=%s', (payment_id,))
        if initial is None:
            raise CheckoutError('foreign_payment')
        order, payments, account = self.lock_order(initial['order_id'], refund_payment_id=payment_id if refund else None)
        payment = next((row for row in payments if str(row['id']) == str(payment_id)), None)
        if payment is None:
            raise CheckoutError('foreign_payment')
        return order, payment, payments, account

    def _authorize(self, actor_user_id, *, review=False):
        actor_user_id = require_integer(actor_user_id, minimum=1)
        actor = self.db.one('SELECT id,role FROM public.users WHERE id=%s', (actor_user_id,))
        allowed = ('owner', 'admin') if review else ('owner', 'admin', 'finance')
        if actor is None or actor['role'] not in allowed:
            raise CheckoutError('operator_forbidden')
        return actor['id']

    def _audit(self, action, order, payment, *, actor=None, review=None):
        values = {'order_status': order['order_status'], 'payment_status': order['payment_status']}
        if payment is not None:
            values['payment_id'] = str(payment['id'])
        if review is not None:
            values['review_id'] = review
        self.db.execute('''INSERT INTO public.audit_logs
            (user_id,action,subject_type,subject_id,new_values,created_at)
            VALUES (%s,%s,'App\\Models\\Order',%s,%s,%s)''',
            (actor, action, str(order['id']), json.dumps(values), self.clock()))

    def _result(self, order, payment, *, changed=False, receipt=False, review=None):
        return OrderLifecycleResult(dict(order), None if payment is None else dict(payment), changed, receipt, review)

    def _statuses(self, order, payment, *, order_status=None, payment_status=None):
        at = self.clock()
        if payment_status is not None and payment is not None:
            self.db.execute('UPDATE public.payments SET status=%s,paid_at=%s,updated_at=%s WHERE id=%s',
                (payment_status, payment['paid_at'] or (at if payment_status == 'paid' else None), at, payment['id']))
            payment['status'] = payment_status
            if payment_status == 'paid' and payment['paid_at'] is None:
                payment['paid_at'] = at
        order_status = order_status or order['order_status']
        payment_status = payment_status or order['payment_status']
        self.db.execute('UPDATE public.orders SET order_status=%s,payment_status=%s,updated_at=%s WHERE id=%s',
            (order_status, payment_status, at, order['id']))
        order.update(order_status=order_status, payment_status=payment_status)

    def review_locked(self, order, payment, reason):
        self.db.require_transaction()
        review = self.db.one('''INSERT INTO public.manual_fulfillment_reviews
            (order_id,payment_id,amount,currency,reason,state,provider_reference,created_at,updated_at)
            VALUES (%s,%s,%s,%s,%s,'pending',%s,%s,%s) ON CONFLICT (payment_id) DO NOTHING RETURNING id''',
            (order['id'], payment['id'], payment['provider_total_payment'] or payment['amount'], payment['currency'],
             reason, payment['provider_reference'] or payment['merchant_ref'], self.clock(), self.clock()))
        if review is None:
            review = self.db.one('SELECT id FROM public.manual_fulfillment_reviews WHERE payment_id=%s', (payment['id'],))
        return review['id']

    def _open_review(self, order_id):
        return self.db.one("SELECT id FROM public.manual_fulfillment_reviews WHERE order_id=%s AND state IN ('pending','refund_uncertain') ORDER BY id LIMIT 1", (order_id,))

    def _receipt_claimed(self, order, payment):
        from ..payments.claimed_order import order_receipt_claimed
        return order_receipt_claimed(self.db, order, payment)

    def _complete_local(self, order_id):
        return self.db.one('''SELECT i.id FROM public.order_items i WHERE i.order_id=%s AND
            (i.quantity<=0 OR i.quantity<>(SELECT count(*) FROM public.product_stock_items s
              WHERE s.order_item_id=i.id AND s.supplier_purchase_id IS NULL AND s.retired_at IS NULL)) LIMIT 1''',
            (order_id,)) is None and self.db.one('SELECT id FROM public.order_items WHERE order_id=%s LIMIT 1', (order_id,)) is not None
    def balance_paid_locked(self, order_id, payment_id):
        self.db.require_transaction()
        order, payment, payments, _ = self.lock_payment(payment_id)
        if str(order['id']) != str(order_id) or len(payments) != 1:
            raise CheckoutError('payment_snapshot_mismatch')
        if (payment['provider'], payment['method'], payment['currency']) != ('store_balance', 'STORE_BALANCE', 'IDR'):
            raise CheckoutError('balance_payment_snapshot_invalid')
        if payment['status'] != 'pending' or order['order_status'] != 'pending_payment':
            raise CheckoutError('invalid_order_transition')
        debit = self.db.one("""SELECT e.id FROM public.store_balance_entries e
            JOIN public.customer_balance_accounts a ON a.id=e.customer_balance_account_id
            WHERE e.kind='purchase_debit' AND e.source_type='order' AND e.source_id=%s
              AND a.customer_id=%s AND e.amount=%s""", (str(order_id), order['customer_id'], payment['amount']))
        if debit is None:
            raise CheckoutError('balance_purchase_debit_missing')
        fulfillments = self.fulfillment.lock(order_id)
        if fulfillments:
            self.inventory.lifecycle_sales(str(order_id))
            self.fulfillment.paid(order_id, self.clock())
            self._statuses(order, payment, order_status='processing', payment_status='paid')
            return self._result(order, payment, changed=True)
        self.inventory.record_sales(str(order_id))
        self._statuses(order, payment, order_status='completed', payment_status='paid')
        wake_after_commit('order', str(order_id), using=self.using)
        return self._result(order, payment, changed=True, receipt=True)


    def apply_payment_paid(self, payment_id):
        def operation():
            order, payment, payments, _ = self.lock_payment(payment_id)
            return self.paid_locked(order, payment, payments)
        return self._run(operation)

    def paid_locked(self, order, payment, payments):
        self.db.require_transaction()
        same_currency = payment['currency'] == order['currency']
        external_usdt = (order['currency'] == 'IDR' and payment['currency'] == 'USDT'
                         and payment['provider'] == 'manual'
                         and payment['method'] in ('BINANCE_PAY_ID', 'USDT_BEP20'))
        if (not same_currency and not external_usdt) or (
                same_currency and payment['amount'] != order['total_amount']):
            raise CheckoutError('payment_snapshot_mismatch')
        if payment['status'] == 'refunded':
            return self._result(order, payment)
        if payment['status'] == 'paid' and order['order_status'] in ('processing', 'completed'):
            self.fulfillment.lock(order['id'])
            if not self.inventory.has_sales(str(order['id'])):
                raise CheckoutError('invalid_order_transition')
            review = self._open_review(order['id'])
            return self._result(order, payment, receipt=not review and not self.fulfillment.pending(order['id']), review=review['id'] if review else None)
        if payment['provider'] in ('store_balance', 'usdt_balance'):
            raise CheckoutError('balance_paid_requires_checkout')
        if payment['status'] != 'paid' and not self._receipt_claimed(order, payment):
            raise CheckoutError('payment_receipt_unclaimed')
        terminal = order['order_status'] in ('cancelled', 'expired')
        if terminal:
            if payment['status'] not in ('paid', 'failed', 'expired'):
                raise CheckoutError('invalid_order_transition')
            changed = payment['status'] != 'paid' or order['payment_status'] != 'paid'
            self._statuses(order, payment, payment_status='paid')
            review = self.review_locked(order, payment, 'late_payment')
            if changed:
                self._audit('order.late_payment_review', order, payment, review=review)
            return self._result(order, payment, changed=changed, review=review)
        if payment['status'] != 'pending' or order['order_status'] != 'pending_payment' or order['payment_status'] != 'pending':
            raise CheckoutError('invalid_order_transition')
        if any(row['id'] != payment['id'] and row['status'] == 'paid' for row in payments):
            review = self.review_locked(order, payment, 'receipt_conflict')
            return self._result(order, payment, review=review)
        fulfillments = self.fulfillment.lock(order['id'])
        self.inventory.lifecycle_sales(str(order['id']))
        self.fulfillment.paid(order['id'], self.clock())
        complete = not fulfillments and self._complete_local(order['id'])
        review = self._open_review(order['id'])
        state = paid_order_status(payment, supplier_pending=self.fulfillment.pending(order['id']),
                                  has_open_review=review is not None, local_credentials_complete=complete)
        self._statuses(order, payment, order_status=state, payment_status='paid')
        if not fulfillments and not complete:
            review_id = self.review_locked(order, payment, 'local_credentials_missing')
        else:
            review_id = review['id'] if review else None
        self._audit('order.payment_confirmed', order, payment, review=review_id)
        receipt = review_id is None and not self.fulfillment.pending(order['id'])
        if receipt:
            wake_after_commit('order', str(order['id']), using=self.using)
        return self._result(order, payment, changed=True, receipt=receipt, review=review_id)

    def apply_manual_completion(self, order_id, *, actor_user_id):
        def operation():
            actor = self._authorize(actor_user_id)
            order, payments, _ = self.lock_order(order_id)
            payment = next((row for row in payments if row['provider'] == 'manual'), None)
            if payment is None:
                raise CheckoutError('manual_payment_required')
            if payment['status'] == 'pending':
                from lhie_candidate.payments.receipts import claim_owner_attestation
                if not claim_owner_attestation(self.db, payment=payment, now=self.clock(), using=self.using).allowed:
                    raise CheckoutError('payment_receipt_held')
            result = self.paid_locked(order, payment, payments)
            if (result.newly_applied and order['order_status'] == 'processing'
                    and result.receipt_eligible and self._complete_local(order['id'])):
                self._statuses(order, payment, order_status='completed')
                result = self._result(order, payment, changed=True, receipt=True)
            if result.newly_applied:
                self._audit('order.manual_completed', order, payment, actor=actor)
            return result
        return self._run(operation)

    def _expiry_held(self, order, payments):
        at = self.clock()
        for payment in payments:
            if payment['provider'] != 'manual' or payment['status'] != 'pending':
                continue
            if payment['method'] == 'QR_MANUAL' and payment['payment_proof_submitted_at'] is not None:
                return True
            if (payment['method'] == 'BINANCE_PAY_ID' and payment['binance_auto_verification_enabled']
                    and payment['binance_verification_grace_until'] is not None and payment['binance_verification_grace_until'] > at):
                return True
            if payment['method'] != 'USDT_BEP20':
                continue
            detail = self.db.one('''SELECT verification_grace_until,token_contract,receiver_address,expected_token_units
                FROM public.payment_bsc_details WHERE payment_id=%s''', (payment['id'],))
            deadline = payment['expired_at'] or order['expires_at']
            if detail is None or deadline is None or detail['verification_grace_until'] is None:
                continue
            if not deadline < detail['verification_grace_until'] <= deadline + timedelta(minutes=60) or detail['verification_grace_until'] <= at:
                continue
            if self.db.one('''SELECT id FROM public.bsc_payment_transfer_evidence WHERE payment_id=%s
                AND token_contract=%s AND receiver_address=%s AND amount_units=%s AND receipt_status='success'
                AND verification_status='confirming' AND block_timestamp>=%s AND block_timestamp<=%s AND block_timestamp<=%s LIMIT 1''',
                (payment['id'], detail['token_contract'].lower(), detail['receiver_address'].lower(), detail['expected_token_units'], payment['created_at'], deadline, at)):
                return True
        return False

    def _release_locked(self, order, payments, *, status, due=False, customer=False):
        payment = payments[0] if payments else None
        if order['order_status'] == status:
            return self._result(order, payment)
        if order['order_status'] != 'pending_payment' or order['payment_status'] != 'pending' or any(row['status'] == 'paid' or row['paid_at'] is not None for row in payments):
            raise CheckoutError('invalid_order_transition')
        if customer and any(row['payment_proof_submitted_at'] is not None for row in payments):
            raise CheckoutError('payment_proof_processing')
        if due and ((order['expires_at'] is not None and order['expires_at'] > self.clock()) or self._expiry_held(order, payments)):
            return self._result(order, payment)
        fulfillments = self.fulfillment.lock(order['id'])
        self.fulfillment.assert_refundable(order['id'], self.clock())
        self.inventory.lifecycle_release(str(order['id']))
        self.fulfillment.release(fulfillments, self.clock())
        payment_status = 'expired' if status == 'expired' else 'failed'
        for row in payments:
            if row['status'] != 'pending':
                continue
            self.db.execute('UPDATE public.payments SET status=%s,updated_at=%s WHERE id=%s', (payment_status, self.clock(), row['id']))
            row['status'] = payment_status
            if row['method'] == 'USDT_BEP20':
                self.db.execute('UPDATE public.payment_bsc_details SET verification_status=%s,last_verified_at=%s WHERE payment_id=%s',
                    ('expired' if status == 'expired' else 'manual_review', self.clock(), row['id']))
            if row['provider'] in ('pakasir', 'klikqris') and not (row['provider'] == 'pakasir' and row['provider_expires_at'] is not None and row['provider_expires_at'] <= self.clock()):
                self.db.execute('''INSERT INTO public.provider_cancellation_attempts
                    (order_id,payment_id,provider,state,finished_at,created_at,updated_at)
                    VALUES (%s,%s,%s,%s,%s,%s,%s) ON CONFLICT (payment_id) DO NOTHING''',
                    (order['id'], row['id'], row['provider'], 'unsupported' if row['provider'] == 'klikqris' else 'pending',
                     self.clock() if row['provider'] == 'klikqris' else None, self.clock(), self.clock()))
        self._statuses(order, None, order_status=status, payment_status=payment_status)
        if status == 'cancelled':
            self.db.execute('UPDATE public.orders SET checkout_key=NULL WHERE id=%s', (order['id'],))
            order['checkout_key'] = None
        self._audit('order.' + status, order, payment)
        return self._result(order, payment, changed=True)

    def apply_pending_cancellation(self, order_id, *, customer_id=None, actor_user_id=None):
        def operation():
            if customer_id is None and actor_user_id is None:
                raise CheckoutError('cancellation_authority_required')
            actor = self._authorize(actor_user_id) if actor_user_id is not None else None
            order, payments, _ = self.lock_order(order_id, customer_id=customer_id)
            result = self._release_locked(order, payments, status='cancelled', customer=customer_id is not None)
            if result.newly_applied and actor is not None:
                self._audit('order.pending_cancelled_released', order, result.payment, actor=actor)
            return result
        return self._run(operation)

    def apply_pending_expiry(self, order_id):
        def operation():
            order, payments, _ = self.lock_order(order_id)
            return self._release_locked(order, payments, status='expired', due=True)
        return self._run(operation)

    def apply_payment_expired(self, payment_id):
        def operation():
            order, _, payments, _ = self.lock_payment(payment_id)
            return self._release_locked(order, payments, status='expired')
        return self._run(operation)

    def apply_manual_rejection(self, order_id, *, actor_user_id):
        def operation():
            actor = self._authorize(actor_user_id)
            order, payments, _ = self.lock_order(order_id)
            if not any(row['provider'] == 'manual' and row['status'] == 'pending' for row in payments):
                raise CheckoutError('manual_payment_required')
            result = self._release_locked(order, payments, status='cancelled')
            self._audit('order.manual_rejected', order, result.payment, actor=actor)
            return result
        return self._run(operation)

    def _refund_locked(self, order, payment, account, *, actor, refund_id=None, reference=None, result='success'):
        from .refunds import OrderRefunds
        fulfillments = self.fulfillment.lock(order['id'])
        self.fulfillment.assert_refundable(order['id'], self.clock())
        refund = OrderRefunds(using=self.using, encrypter=self.encrypter).apply_locked(
            order, payment, account=account, refund_id=refund_id, reference=reference, result=result, actor_user_id=actor)
        if refund.fully_refunded:
            if self.db.one("SELECT id FROM public.inventory_movements WHERE order_id=%s AND type='sale' LIMIT 1", (order['id'],)):
                self.inventory.retire_order(str(order['id']))
            elif not self.db.one("SELECT id FROM public.inventory_movements WHERE order_id=%s AND type='release' LIMIT 1", (order['id'],)):
                self.inventory.lifecycle_release(str(order['id']))
            self.fulfillment.release(fulfillments, self.clock())
            self._statuses(order, payment, order_status='completed' if order['order_status'] == 'completed' else 'cancelled', payment_status='refunded')
        elif (refund.newly_applied and refund.refund is not None
              and refund.refund.get('supplier_fulfillment_id') is not None
              and refund.refund['state'] == 'confirmed'
              and not self.fulfillment.pending(order['id'])):
            self._statuses(order, payment, order_status='completed')
            self.db.execute("""UPDATE public.manual_fulfillment_reviews SET state='fulfilled',
                resolved_at=%s,actor_user_id=%s,updated_at=%s WHERE order_id=%s AND state='pending'
                AND reason IN ('supplier_fulfillment','late_payment')""",
                (self.clock(), actor, self.clock(), order['id']))
            wake_after_commit('order', str(order['id']), using=self.using)
        if refund.newly_applied:
            self._audit('order.refund_recorded', order, payment, actor=actor)
        return refund

    def apply_payment_refunded(self, payment_id, *, actor_user_id, refund_id=None, reference=None, result='success'):
        def operation():
            actor = self._authorize(actor_user_id, review=True)
            order, payment, _, account = self.lock_payment(payment_id, refund=True)
            return self._refund_locked(order, payment, account, actor=actor, refund_id=refund_id, reference=reference, result=result)
        return self._run(operation)

    def resolve_review(self, review_id, *, actor_user_id, action, reason, reference=None, result='success'):
        if action not in ('fulfill', 'refund', 'reject') or type(reason) is not str or not reason.strip():
            raise CheckoutError('review_resolution_invalid')
        def operation():
            actor = self._authorize(actor_user_id, review=True)
            initial = self.db.one('SELECT order_id,payment_id FROM public.manual_fulfillment_reviews WHERE id=%s', (review_id,))
            if initial is None:
                raise CheckoutError('review_not_found')
            order, payment, payments, account = self.lock_payment(initial['payment_id'], refund=action == 'refund')
            self.fulfillment.lock(order['id'])
            review = self.db.one('SELECT id,reason,state,refund_provider_reference FROM public.manual_fulfillment_reviews WHERE id=%s FOR UPDATE', (review_id,))
            if review['state'] not in ('pending', 'refund_uncertain'):
                return self._result(order, payment, review=review_id)
            if review['state'] == 'refund_uncertain' and (action != 'refund' or result != 'success' or reference != review['refund_provider_reference']):
                raise CheckoutError('uncertain_refund_requires_confirmation')
            state = {'fulfill': 'fulfilled', 'refund': 'refunded', 'reject': 'rejected'}[action]
            pending_bsc = (payment['provider'] == 'manual' and payment['method'] == 'USDT_BEP20'
                           and payment['status'] == 'pending' and order['order_status'] == 'pending_payment')
            if action == 'fulfill':
                if review['state'] != 'pending':
                    raise CheckoutError('paid_review_required')
                if pending_bsc:
                    # P14 claims persisted physical chain evidence. Historical reviews
                    # without such evidence retain the explicit Owner attestation path.
                    if not self.db.one('SELECT id FROM public.bsc_payment_transfer_evidence WHERE payment_id=%s LIMIT 1', (payment['id'],)):
                        from lhie_candidate.payments.receipts import claim_owner_attestation
                        if not claim_owner_attestation(self.db, payment=payment, now=self.clock(), using=self.using).allowed:
                            raise CheckoutError('payment_receipt_held')
                    self.paid_locked(order, payment, payments)
                if payment['status'] != 'paid' or order['payment_status'] != 'paid':
                    raise CheckoutError('paid_review_required')
                self.inventory.fulfill_review(str(order['id']))
                self._statuses(order, payment, order_status='completed')
            elif action == 'refund':
                refund = self._refund_locked(order, payment, account, actor=actor, reference=reference, result=result)
                if not refund.fully_refunded:
                    if result != 'uncertain':
                        raise CheckoutError('refund_not_confirmed')
                    state = 'refund_uncertain'
            elif action == 'reject' and pending_bsc:
                self._release_locked(order, payments, status='cancelled')
            self.db.execute('''UPDATE public.manual_fulfillment_reviews SET state=%s,resolution_reason=%s,
                actor_user_id=%s,resolved_at=%s,refund_provider_reference=%s,refund_provider_result=%s,
                refund_requested_at=%s,updated_at=%s WHERE id=%s''',
                (state, reason.strip(), actor, self.clock(), reference, result if action == 'refund' else None,
                 self.clock() if reference is not None else None, self.clock(), review_id))
            self._audit('manual_fulfillment_review.' + state, order, payment, actor=actor, review=review_id)
            if action == 'fulfill':
                wake_after_commit('order', str(order['id']), using=self.using)
            return self._result(order, payment, changed=True, receipt=action == 'fulfill', review=review_id)
        return self._run(operation)
