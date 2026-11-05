"""Customer-owned historical warranty and definitively missing-unit refunds.

The lifecycle owns resource locks, refund posting and terminal transitions. No
provider claim, account confirmation or replacement is dispatched here.
"""
import json
from uuid import uuid4

from lhie_candidate.checkout.errors import CheckoutError
from lhie_candidate.checkout.lifecycle import OrderLifecycle
from lhie_candidate.domain.money import require_integer

from .account_units import HistoricalAccountUnits


class _Entitlements:
    def __init__(self, using='default', *, encrypter=None, clock=None):
        self.lifecycle = OrderLifecycle(using=using, encrypter=encrypter, clock=clock)
        self.db = self.lifecycle.db

    def _lock(self, order_id, customer_id):
        require_integer(customer_id, minimum=1)
        candidates = self.db.rows('''SELECT id FROM public.payments WHERE order_id=%s
            AND status IN ('paid','refunded') AND paid_at IS NOT NULL ORDER BY id''', (order_id,))
        if len(candidates) != 1:
            raise CheckoutError('refund_payment_identity_invalid')
        order, payment, payments, account = self.lifecycle.lock_payment(candidates[0]['id'], refund=True)
        if (order['customer_id'] != customer_id or payment['paid_at'] is None
                or (order['payment_status'], payment['status']) not in (('paid', 'paid'), ('refunded', 'refunded'))
                or sum(p['status'] in ('paid', 'refunded') and p['paid_at'] is not None for p in payments) != 1):
            raise CheckoutError('refund_payment_identity_invalid')
        self.lifecycle.fulfillment.lock(order['id'])
        if self.db.one('''SELECT p.id FROM public.supplier_purchases p
            JOIN public.supplier_fulfillments f ON f.id=p.supplier_fulfillment_id
            WHERE f.order_id=%s AND (p.warranty_hold_active OR p.warranty_reserved_cost_micros<>0)
            LIMIT 1''', (order_id,)):
            raise CheckoutError('supplier_replacement_unresolved')
        return order, payment, account

    def _cap(self, order, payment, amount):
        amount = require_integer(amount, minimum=1)
        refunds = self.db.rows('''SELECT * FROM public.order_refunds WHERE payment_id=%s
            ORDER BY id FOR UPDATE''', (payment['id'],))
        if any(str(row['order_id']) != str(payment['order_id'])
               or row['currency'] != payment['currency']
               or row['customer_id'] != order['customer_id']
               or row['state'] not in ('requested', 'uncertain', 'confirmed') for row in refunds):
            raise CheckoutError('refund_identity_conflict')
        if sum(require_integer(row['amount'], minimum=1) for row in refunds) + amount > require_integer(payment['amount'], minimum=1):
            raise CheckoutError('refund_exceeds_original')

    def _insert(self, order, payment, amount, *, claim_id=None, unit_id=None,
                fulfillment_id=None, missing_quantity=None):
        return self.db.one('''INSERT INTO public.order_refunds
            (id,order_id,payment_id,customer_id,warranty_claim_id,supplier_account_unit_id,
             supplier_fulfillment_id,missing_quantity,amount,currency,state,created_at,updated_at)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'requested',%s,%s) RETURNING *''',
            (str(uuid4()), order['id'], payment['id'], order['customer_id'], claim_id, unit_id,
             fulfillment_id, missing_quantity, amount, payment['currency'],
             self.lifecycle.clock(), self.lifecycle.clock()))

    def _settle_internal(self, order, payment, account, refund):
        if payment['provider'] in ('store_balance', 'usdt_balance'):
            result = self.lifecycle._refund_locked(order, payment, account, actor=None, refund_id=refund['id'])
            return result.refund
        return refund

    def confirm_external(self, refund_id, *, actor_user_id, amount, currency, reference, result):
        """Bind operator transfer evidence to the locked historical entitlement."""
        amount = require_integer(amount, minimum=1)
        if currency not in ('IDR', 'USDT'):
            raise CheckoutError('refund_external_evidence_invalid')

        def operation():
            actor = self.lifecycle._authorize(actor_user_id)
            initial = self.db.one('SELECT payment_id FROM public.order_refunds WHERE id=%s', (refund_id,))
            if initial is None:
                raise CheckoutError('refund_entitlement_missing')
            order, payment, _, account = self.lifecycle.lock_payment(initial['payment_id'], refund=True)
            self.lifecycle.fulfillment.lock(order['id'])
            refund = self.db.one('SELECT * FROM public.order_refunds WHERE id=%s FOR UPDATE', (refund_id,))
            if (refund is None or str(refund['payment_id']) != str(payment['id'])
                    or str(refund['order_id']) != str(order['id'])
                    or refund['amount'] != amount or refund['currency'] != currency
                    or payment['provider'] in ('store_balance', 'usdt_balance')
                    or (refund['warranty_claim_id'] is None and refund['supplier_fulfillment_id'] is None)):
                raise CheckoutError('refund_entitlement_invalid')
            return self.lifecycle._refund_locked(order, payment, account, actor=actor,
                refund_id=refund_id, reference=reference, result=result)
        return self.lifecycle._run(operation)


class HistoricalWarranty(_Entitlements):
    def choose_refund(self, customer_id, claim_public_id):
        def operation():
            initial = self.db.one('''SELECT c.id,c.order_id FROM public.warranty_claims c
                JOIN public.orders o ON o.id=c.order_id WHERE c.public_id=%s AND o.customer_id=%s''',
                (claim_public_id, customer_id))
            if initial is None:
                raise CheckoutError('refund_entitlement_invalid')
            order, payment, account = self._lock(initial['order_id'], customer_id)
            initial_claim = self.db.one('SELECT supplier_account_unit_id FROM public.warranty_claims WHERE id=%s', (initial['id'],))
            unit, stock = HistoricalAccountUnits(self.db.using).lock(initial_claim['supplier_account_unit_id'], order, payment)
            claim = self.db.one('SELECT * FROM public.warranty_claims WHERE id=%s FOR UPDATE', (initial['id'],))
            if (claim is None or claim['supplier_account_unit_id'] != unit['id']
                    or str(claim['order_id']) != str(order['id'])):
                raise CheckoutError('refund_entitlement_invalid')
            existing = self.db.one('SELECT * FROM public.order_refunds WHERE warranty_claim_id=%s FOR UPDATE', (claim['id'],))
            if claim['settlement_choice'] == 'refund':
                if (existing is None or str(existing['order_id']) != str(order['id'])
                        or str(existing['payment_id']) != str(payment['id'])
                        or existing['customer_id'] != customer_id or existing['supplier_account_unit_id'] != unit['id']
                        or existing['amount'] != unit['retail_amount'] or existing['currency'] != unit['retail_currency']):
                    raise CheckoutError('refund_identity_conflict')
                return existing
            if payment['status'] != 'paid':
                raise CheckoutError('refund_payment_state_invalid')
            if claim['customer_id'] != customer_id:
                if payment['provider'] in ('store_balance', 'usdt_balance'):
                    raise CheckoutError('refund_original_debit_identity_invalid')
                self.db.execute('UPDATE public.warranty_claims SET customer_id=%s,updated_at=%s WHERE id=%s',
                    (customer_id, self.lifecycle.clock(), claim['id']))
                self.db.execute('''INSERT INTO public.audit_logs
                    (action,subject_type,subject_id,old_values,new_values,created_at)
                    VALUES ('warranty.guest_claim_linked','App\\Models\\WarrantyClaim',%s,%s,%s,%s)''',
                    (str(claim['id']), json.dumps({'customer_id': claim['customer_id']}),
                     json.dumps({'customer_id': customer_id}), self.lifecycle.clock()))
            switching = claim['settlement_choice'] == 'replacement'
            if switching:
                attempt = self.db.one('''SELECT state,warranty_hold_active,warranty_reserved_cost_micros,
                    lease_expires_at,observed_quantity,actual_cost_micros FROM public.supplier_purchases
                    WHERE warranty_claim_id=%s FOR UPDATE''', (claim['id'],))
                if (claim['settlement_state'] not in ('replacement_pending', 'replacement_failed')
                        or (attempt is not None and (attempt['warranty_hold_active']
                            or attempt['warranty_reserved_cost_micros'] != 0
                            or (attempt['lease_expires_at'] is not None and attempt['lease_expires_at'] > self.lifecycle.clock())
                            or not (attempt['state'] == 'rejected' or (attempt['state'] == 'settled'
                                and attempt['observed_quantity'] == 0 and attempt['actual_cost_micros'] == 0))))):
                    raise CheckoutError('supplier_replacement_unresolved')
            elif claim['settlement_choice'] is not None or claim['settlement_state'] != 'awaiting_choice':
                raise CheckoutError('refund_entitlement_state_invalid')
            if (existing is not None or order['order_status'] not in ('processing', 'completed')
                    or claim['state'] != 'approved' or claim['verified_at'] is None
                    or (stock['retired_at'] is not None and not switching)):
                raise CheckoutError('refund_entitlement_invalid')
            if switching:
                self.db.execute('''UPDATE public.warranty_claims SET settlement_choice=NULL,
                    settlement_state='awaiting_choice',replacement_next_attempt_at=NULL,updated_at=%s WHERE id=%s''',
                    (self.lifecycle.clock(), claim['id']))
            self.lifecycle.fulfillment.assert_refundable(order['id'], self.lifecycle.clock())
            self._cap(order, payment, unit['retail_amount'])
            self.db.execute('''UPDATE public.warranty_claims SET settlement_choice='refund',
                settlement_state='refund_pending',updated_at=%s WHERE id=%s''', (self.lifecycle.clock(), claim['id']))
            refund = self._insert(order, payment, unit['retail_amount'], claim_id=claim['id'], unit_id=unit['id'])
            self.lifecycle._audit('warranty.refund_selected', order, payment)
            return self._settle_internal(order, payment, account, refund)
        return self.lifecycle._run(operation)


class MissingFulfillmentRefunds(_Entitlements):
    def request(self, customer_id, order_item_id):
        require_integer(order_item_id, minimum=1)
        def operation():
            initial = self.db.one('''SELECT i.order_id FROM public.order_items i JOIN public.orders o
                ON o.id=i.order_id WHERE i.id=%s AND o.customer_id=%s''', (order_item_id, customer_id))
            if initial is None:
                raise CheckoutError('refund_entitlement_invalid')
            order, payment, account = self._lock(initial['order_id'], customer_id)
            f = self.db.one('SELECT * FROM public.supplier_fulfillments WHERE order_item_id=%s FOR UPDATE', (order_item_id,))
            if f is None or str(f['order_id']) != str(order['id']):
                raise CheckoutError('refund_entitlement_invalid')
            existing = self.db.one('SELECT * FROM public.order_refunds WHERE supplier_fulfillment_id=%s FOR UPDATE', (f['id'],))
            if existing is not None:
                if (str(existing['order_id']) != str(order['id']) or str(existing['payment_id']) != str(payment['id'])
                        or existing['customer_id'] != customer_id or existing['currency'] != payment['currency']):
                    raise CheckoutError('refund_identity_conflict')
                return existing
            if payment['status'] != 'paid':
                raise CheckoutError('refund_payment_state_invalid')
            self.lifecycle.fulfillment.assert_refundable(order['id'], self.lifecycle.clock())
            if (order['order_status'] != 'processing' or f['state'] != 'review'
                    or f['reserved_quantity'] != 0 or f['reserved_cost_micros'] != 0 or f['refunded_quantity'] != 0
                    or self.db.one("SELECT id FROM public.manual_fulfillment_reviews WHERE order_id=%s AND reason='late_payment' AND state IN ('pending','refund_uncertain') LIMIT 1", (order['id'],))):
                raise CheckoutError('refund_entitlement_invalid')
            item = self.db.one('''SELECT id,order_id,quantity,local_quantity,supplier_quantity,subtotal,usdt_subtotal
                FROM public.order_items WHERE id=%s''', (order_item_id,))
            acquired = self.db.one('''SELECT
                (SELECT COUNT(*) FROM public.product_stock_items s JOIN public.supplier_purchases p
                 ON p.id=s.supplier_purchase_id WHERE s.order_item_id=%s
                 AND p.supplier_fulfillment_id=%s AND p.warranty_claim_id IS NULL)
                + (SELECT COALESCE(SUM(quantity),0) FROM public.supplier_manual_handoffs WHERE supplier_fulfillment_id=%s) AS quantity,
                (SELECT COALESCE(SUM(observed_quantity),0) FROM public.supplier_purchases
                 WHERE supplier_fulfillment_id=%s AND warranty_claim_id IS NULL AND committed_at IS NOT NULL) AS proven''',
                (order_item_id, f['id'], f['id'], f['id']))
            count = int(acquired['quantity'])
            local = item['quantity'] if item['local_quantity'] is None else item['local_quantity']
            subtotal = item['usdt_subtotal'] if payment['currency'] == 'USDT' else item['subtotal']
            if (str(item['order_id']) != str(order['id']) or subtotal is None or count != acquired['proven']
                    or count >= f['quantity'] or local < 0 or local + f['quantity'] != item['quantity']):
                raise CheckoutError('refund_entitlement_invalid')
            quantity = require_integer(item['quantity'], minimum=1)
            subtotal = require_integer(subtotal)
            prefix = local + count
            base, remainder = divmod(subtotal, quantity)
            amount = subtotal - base * prefix - min(remainder, prefix)
            self._cap(order, payment, amount)
            refund = self._insert(order, payment, amount, fulfillment_id=f['id'], missing_quantity=f['quantity'] - count)
            self.db.execute('''UPDATE public.supplier_fulfillments SET state='refund_pending',next_attempt_at=NULL,
                last_error='missing_units_refund_pending',updated_at=%s WHERE id=%s''', (self.lifecycle.clock(), f['id']))
            self.lifecycle._audit('supplier.missing_units_refund_requested', order, payment)
            return self._settle_internal(order, payment, account, refund)
        return self.lifecycle._run(operation)
