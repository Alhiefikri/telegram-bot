"""Operator delivery without resolving the supplier's independent debit evidence."""
import json
from uuid import UUID, uuid4

from lhie_candidate.checkout.errors import CheckoutError
from lhie_candidate.checkout.inventory import _STOCK_COLUMNS
from lhie_candidate.domain.money import require_integer
from lhie_candidate.receipt.jobs import wake_after_commit

from .acquisition import SupplierAcquisition


class ManualSupplierRecovery(SupplierAcquisition):
    def __init__(self, encrypter, *, using='default', clock=None):
        super().__init__(encrypter, None, using=using, clock=clock)

    def _locked(self, fulfillment_id, actor_user_id):
        actor = self.lifecycle._authorize(actor_user_id, review=True)
        locked = self._lock(fulfillment_id)
        if locked is None:
            raise CheckoutError('supplier_fulfillment_missing')
        order, payments, fulfillment = locked
        return actor, order, payments, fulfillment

    def _outstanding(self, fulfillment):
        allocated = self.db.one('''SELECT count(*) AS n FROM public.product_stock_items s
            JOIN public.supplier_purchases p ON p.id=s.supplier_purchase_id
            WHERE s.order_item_id=%s AND p.supplier_fulfillment_id=%s AND p.warranty_claim_id IS NULL''',
            (fulfillment['order_item_id'], fulfillment['id']))['n']
        handed = self.db.one('''SELECT COALESCE(sum(quantity),0) AS n FROM public.supplier_manual_handoffs
            WHERE supplier_fulfillment_id=%s''', (fulfillment['id'],))['n']
        return max(0, fulfillment['quantity'] - fulfillment['refunded_quantity'] - allocated - handed)

    def _no_lease(self, fulfillment_id, at):
        if self.db.one('''SELECT id FROM public.supplier_purchases WHERE supplier_fulfillment_id=%s
            AND lease_expires_at>%s LIMIT 1''', (fulfillment_id, at)):
            raise CheckoutError('supplier_purchase_in_flight')

    def _audit_event(self, actor, action, subject, subject_id, values, ip=None):
        self.db.execute('''INSERT INTO public.audit_logs
            (user_id,action,subject_type,subject_id,new_values,ip_address,created_at)
            VALUES (%s,%s,%s,%s,%s,%s,%s)''',
            (actor, action, subject, str(subject_id), json.dumps(values), ip, self.clock()))

    def _finish(self, order, actor, reason=None, *, receipt=True):
        if self.lifecycle.fulfillment.pending(order['id']):
            return
        at = self.clock()
        self.db.execute('''UPDATE public.manual_fulfillment_reviews SET state='fulfilled',resolved_at=%s,
            actor_user_id=%s,resolution_reason=%s,updated_at=%s WHERE order_id=%s AND state='pending'
            AND reason IN ('supplier_fulfillment','late_payment')''', (at, actor, reason, at, order['id']))
        self.db.execute("UPDATE public.orders SET order_status='completed',updated_at=%s WHERE id=%s", (at, order['id']))
        has_handoff = self.db.one('''SELECT h.id FROM public.supplier_manual_handoffs h
            JOIN public.supplier_fulfillments f ON f.id=h.supplier_fulfillment_id
            WHERE f.order_id=%s LIMIT 1''', (order['id'],))
        if receipt and not has_handoff:
            wake_after_commit('order', str(order['id']), using=self.using)

    def _late_inventory(self, order):
        ledger = self.lifecycle.inventory
        _, items, products, proofs, allocations = ledger._state(order['id'], local_only=False)
        sold = ledger._transition_proofs(items, proofs, 'sale')
        if sold:
            return
        if not ledger._transition_proofs(items, proofs, 'release'):
            raise CheckoutError('inventory_invariant')
        for item in items:
            ledger._require_reservation(item, proofs)
            product = products[item['product_id']]
            local = item['quantity'] if item['local_quantity'] is None else item['local_quantity']
            existing = [row for row in allocations[item['id']] if row['supplier_purchase_id'] is None]
            ledger._assert_credentials(existing, product['id'])
            if any(row['retired_at'] is not None for row in existing) or len(existing) > local:
                raise CheckoutError('inventory_invariant')
            if not product['public_visible'] or product['stock'] - product['reserved_stock'] < local:
                raise CheckoutError('insufficient_stock')
            missing = local - len(existing)
            if missing:
                available = self.db.rows(f'''SELECT {_STOCK_COLUMNS} FROM public.product_stock_items
                    WHERE product_id=%s AND order_item_id IS NULL AND retired_at IS NULL
                    AND supplier_purchase_id IS NULL AND supplier_unit_index IS NULL
                    ORDER BY id LIMIT %s FOR UPDATE''', (product['id'], missing))
                if len(available) != missing:
                    raise CheckoutError('insufficient_stock')
                ledger._assert_credentials(available, product['id'])
                self.db.execute('UPDATE public.product_stock_items SET order_item_id=%s,updated_at=%s WHERE id=ANY(%s)',
                    (item['id'], self.clock(), [row['id'] for row in available]))
            if local:
                ledger._change_product(product['id'], -local, 0)
            ledger._movement(order['id'], product['id'], 'sale', -item['quantity'],
                'Late payment manual fulfillment', stock_before=product['stock'],
                became_unavailable=product['stock'] <= local)

    def provide_manual_credentials(self, fulfillment_id, credentials, *, actor_user_id):
        if (not isinstance(credentials, (list, tuple)) or not credentials
                or any(not isinstance(secret, str) or not secret.strip() or len(secret.encode('utf-8')) > 16384 for secret in credentials)
                or len(set(credentials)) != len(credentials)):
            raise CheckoutError('manual_delivery_invalid')

        def operation():
            actor, order, payments, f = self._locked(fulfillment_id, actor_user_id)
            at = self.clock()
            reviews = self.db.rows('SELECT * FROM public.manual_fulfillment_reviews WHERE order_id=%s ORDER BY id FOR UPDATE', (order['id'],))
            late = (order['payment_status'] == 'paid' and f['state'] == 'cancelled'
                and any(r['reason'] == 'late_payment' and r['state'] == 'pending' for r in reviews))
            if not late and (order['payment_status'] != 'paid' or order['order_status'] not in ('processing', 'completed') or f['state'] != 'review'):
                raise CheckoutError('manual_delivery_state_invalid')
            if not any(p['status'] == 'paid' and p['paid_at'] is not None for p in payments):
                raise CheckoutError('manual_delivery_state_invalid')
            if self._outstanding(f) != len(credentials):
                raise CheckoutError('manual_delivery_quantity_invalid')
            self._no_lease(f['id'], at)
            if late:
                self._late_inventory(order)
                self.db.execute("UPDATE public.orders SET order_status='processing',updated_at=%s WHERE id=%s", (at, order['id']))
            source = next((row for row in self._snapshots(f) if row.get('catalog_item_id') == f['reserved_catalog_item_id']), None)
            if source is None:
                raise CheckoutError('supplier_snapshot_invalid')
            purchase = self.db.one('SELECT * FROM public.supplier_purchases WHERE id=%s',
                                   (f['current_purchase_id'],)) if f['current_purchase_id'] else None
            if purchase is None or purchase['state'] == 'rejected' or (len(credentials) < f['quantity'] and purchase['committed_at'] is not None):
                purchase = self.db.one('''INSERT INTO public.supplier_purchases
                    (supplier_fulfillment_id,supplier_id,supplier_catalog_item_id,reference,quantity,
                     expected_cost_micros,exchange_rate_idr,state,delivery_payload,last_error,created_at,updated_at)
                    VALUES (%s,%s,%s,%s,%s,0,%s,'delivered',%s,'manual_delivery',%s,%s) RETURNING *''',
                    (f['id'], source['supplier_id'], source['catalog_item_id'], str(uuid4()), len(credentials),
                     source['exchange_rate_idr'], self.cipher.encrypt_json(list(credentials)), at, at))
                self.db.execute('UPDATE public.supplier_fulfillments SET current_purchase_id=%s,updated_at=%s WHERE id=%s',
                                (purchase['id'], at, f['id']))
            if (purchase['committed_at'] is not None and purchase['supplier_order_id'] is not None
                    and purchase['observed_quantity'] == purchase['quantity'] and purchase['actual_cost_micros'] is not None):
                self.db.execute("UPDATE public.supplier_purchases SET state='settled',next_attempt_at=NULL,updated_at=%s WHERE id=%s",
                                (at, purchase['id']))
            item = self.db.one('SELECT product_id FROM public.order_items WHERE id=%s AND order_id=%s', (f['order_item_id'], order['id']))
            if item is None:
                raise CheckoutError('supplier_fulfillment_identity_invalid')
            for index, secret in enumerate(credentials, 1):
                self.db.execute('''INSERT INTO public.product_stock_items
                    (product_id,order_item_id,secret,supplier_purchase_id,supplier_unit_index,created_at,updated_at)
                    VALUES (%s,%s,%s,%s,%s,%s,%s)''',
                    (item['product_id'], f['order_item_id'], self.cipher.encrypt_string(secret), purchase['id'], index, at, at))
            self.db.execute("UPDATE public.supplier_fulfillments SET state='delivered',delivered_at=%s,next_attempt_at=NULL,updated_at=%s WHERE id=%s", (at, at, f['id']))
            self._audit_event(actor, 'supplier_fulfillment.manual_credentials_provided', 'App\\Models\\SupplierFulfillment', f['id'], {'quantity': len(credentials)})
            self._finish(order, actor)
        return self.lifecycle._run(operation)

    def record_external_handoff(self, review_id, fulfillment_id, quantity, reason, *, actor_user_id, idempotency_key=None, ip=None):
        require_integer(quantity, minimum=1)
        if not isinstance(reason, str) or not reason.strip():
            raise CheckoutError('manual_handoff_invalid')
        reason = reason.strip()
        try:
            key = str(UUID(str(idempotency_key))) if idempotency_key is not None else str(uuid4())
        except (ValueError, AttributeError, TypeError):
            raise CheckoutError('manual_handoff_invalid') from None

        def operation():
            actor, order, payments, f = self._locked(fulfillment_id, actor_user_id)
            review = self.db.one('SELECT * FROM public.manual_fulfillment_reviews WHERE id=%s AND order_id=%s FOR UPDATE', (review_id, order['id']))
            if review is None or review['reason'] != 'supplier_fulfillment':
                raise CheckoutError('manual_handoff_review_invalid')
            if (order['payment_status'] != 'paid' or order['order_status'] not in ('processing', 'completed')
                    or not any(p['status'] == 'paid' and p['paid_at'] is not None for p in payments)):
                raise CheckoutError('manual_handoff_state_invalid')
            previous = self.db.one('''SELECT * FROM public.supplier_manual_handoffs
                WHERE supplier_fulfillment_id=%s AND idempotency_key=%s''', (f['id'], key))
            if previous:
                if previous['actor_user_id'] != actor or previous['quantity'] != quantity or previous['note'] != reason:
                    raise CheckoutError('manual_handoff_key_conflict')
                return review
            if review['state'] != 'pending' or f['state'] != 'review':
                raise CheckoutError('manual_handoff_state_invalid')
            at = self.clock()
            self._no_lease(f['id'], at)
            if quantity > self._outstanding(f):
                raise CheckoutError('manual_handoff_quantity_invalid')
            handoff = self.db.one('''INSERT INTO public.supplier_manual_handoffs
                (supplier_fulfillment_id,actor_user_id,idempotency_key,quantity,note,handed_off_at,created_at,updated_at)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s) RETURNING id''', (f['id'], actor, key, quantity, reason, at, at, at))
            self._audit_event(actor, 'supplier_fulfillment.external_handoff_recorded', 'App\\Models\\SupplierManualHandoff', handoff['id'], {'fulfillment_id': f['id'], 'quantity': quantity, 'note': reason}, ip)
            if self._outstanding(f) == 0:
                self.db.execute("""UPDATE public.supplier_fulfillments SET state='delivered',delivered_at=%s,
                    next_attempt_at=NULL,last_error='external_handoff_completed',updated_at=%s WHERE id=%s""", (at, at, f['id']))
                self._finish(order, actor, reason, receipt=False)
                if not self.lifecycle.fulfillment.pending(order['id']):
                    self._audit_event(actor, 'manual_fulfillment_review.external_handoff_completed', 'App\\Models\\ManualFulfillmentReview', review['id'], {'fulfillment_id': f['id'], 'quantity': quantity, 'order_status': 'completed'}, ip)
            return self.db.one('SELECT * FROM public.manual_fulfillment_reviews WHERE id=%s', (review['id'],))
        return self.lifecycle._run(operation)
