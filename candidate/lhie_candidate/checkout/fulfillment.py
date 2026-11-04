"""Lifecycle-side historical supplier locks; acquisition remains supplier-owned."""
from .db import Database
from .errors import CheckoutError


class FulfillmentResources:
    def __init__(self, using='default', *, encrypter=None):
        self.db = Database(using)
        self.encrypter = encrypter

    def lock(self, order_id):
        self.db.require_transaction()
        items = self.db.rows('SELECT id,product_id FROM public.order_items WHERE order_id=%s ORDER BY product_id,id FOR UPDATE', (order_id,))
        products = [item['product_id'] for item in items]
        self.db.rows('SELECT id FROM public.products WHERE id=ANY(%s) ORDER BY id FOR NO KEY UPDATE', (products,))
        initial = self.db.rows('''SELECT id,sources_snapshot,reserved_supplier_id,reserved_catalog_item_id
            FROM public.supplier_fulfillments WHERE order_id=%s ORDER BY id''', (order_id,))
        catalog_ids = {row['supplier_catalog_item_id'] for row in self.db.rows(
            'SELECT supplier_catalog_item_id FROM public.product_supplier_sources WHERE product_id=ANY(%s)', (products,))}
        supplier_ids = set()
        for row in initial:
            if self.encrypter is None:
                raise CheckoutError('supplier_snapshot_unavailable')
            snapshot = self.encrypter.decrypt_json(row['sources_snapshot'])
            if type(snapshot) is not list:
                raise CheckoutError('supplier_snapshot_invalid')
            for source in snapshot:
                catalog_ids.add(source['catalog_item_id'])
                supplier_ids.add(source['supplier_id'])
            if row['reserved_catalog_item_id'] is not None:
                catalog_ids.add(row['reserved_catalog_item_id'])
            if row['reserved_supplier_id'] is not None:
                supplier_ids.add(row['reserved_supplier_id'])
        purchases = self.db.rows('''SELECT p.id,p.supplier_id,p.supplier_catalog_item_id
            FROM public.supplier_purchases p JOIN public.supplier_fulfillments f
            ON f.id=p.supplier_fulfillment_id WHERE f.order_id=%s ORDER BY p.id''', (order_id,))
        for row in purchases:
            catalog_ids.add(row['supplier_catalog_item_id'])
            supplier_ids.add(row['supplier_id'])
        for row in self.db.rows('SELECT supplier_id FROM public.supplier_catalog_items WHERE id=ANY(%s)', (sorted(catalog_ids),)):
            supplier_ids.add(row['supplier_id'])
        self.db.rows('SELECT id FROM public.suppliers WHERE id=ANY(%s) ORDER BY id FOR UPDATE', (sorted(supplier_ids),))
        self.db.rows('SELECT id FROM public.supplier_catalog_items WHERE id=ANY(%s) ORDER BY id FOR UPDATE', (sorted(catalog_ids),))
        fulfillments = self.db.rows('''SELECT id,order_id,state,reserved_supplier_id,reserved_catalog_item_id,
            reserved_cost_micros,reserved_quantity,current_purchase_id FROM public.supplier_fulfillments
            WHERE order_id=%s ORDER BY id FOR UPDATE''', (order_id,))
        self.db.rows('''SELECT p.id FROM public.supplier_purchases p JOIN public.supplier_fulfillments f
            ON f.id=p.supplier_fulfillment_id WHERE f.order_id=%s ORDER BY p.id FOR UPDATE OF p''', (order_id,))
        return fulfillments

    def assert_refundable(self, order_id, now):
        if self.db.one('''SELECT id FROM public.supplier_account_units WHERE order_id=%s
            AND confirmation_state IN ('requested','sending','unknown') LIMIT 1''', (order_id,)):
            raise CheckoutError('supplier_confirmation_unresolved')
        if self.db.one('''SELECT id FROM public.warranty_claims WHERE order_id=%s
            AND settlement_state IN ('replacement_pending','replacement_sending','replacement_unknown','replacement_review') LIMIT 1''', (order_id,)):
            raise CheckoutError('supplier_replacement_unresolved')
        if self.db.one('''SELECT p.id FROM public.supplier_purchases p JOIN public.supplier_fulfillments f
            ON f.id=p.supplier_fulfillment_id WHERE f.order_id=%s AND
            (p.state IN ('sending','awaiting_delivery','ambiguous','review') OR p.lease_expires_at>%s) LIMIT 1''', (order_id, now)):
            raise CheckoutError('supplier_purchase_unresolved')

    def release(self, fulfillments, now):
        for row in fulfillments:
            if row['state'] in ('delivered', 'cancelled'):
                continue
            if row['reserved_quantity']:
                changed = self.db.execute('''UPDATE public.suppliers SET
                    reserved_balance_micros=reserved_balance_micros-%s,version=version+1,updated_at=%s
                    WHERE id=%s AND reserved_balance_micros>=%s''',
                    (row['reserved_cost_micros'], now, row['reserved_supplier_id'], row['reserved_cost_micros']))
                if changed != 1:
                    raise CheckoutError('supplier_hold_invariant')
                changed = self.db.execute('''UPDATE public.supplier_catalog_items SET
                    reserved_quantity=reserved_quantity-%s,updated_at=%s WHERE id=%s AND reserved_quantity>=%s''',
                    (row['reserved_quantity'], now, row['reserved_catalog_item_id'], row['reserved_quantity']))
                if changed != 1:
                    raise CheckoutError('supplier_hold_invariant')
            self.db.execute('''UPDATE public.supplier_fulfillments SET state='cancelled',next_attempt_at=NULL,
                reserved_cost_micros=0,reserved_quantity=0,updated_at=%s WHERE id=%s''', (now, row['id']))
            if row['current_purchase_id'] is not None:
                changed = self.db.execute("""UPDATE public.supplier_purchases SET state='cancelled',updated_at=%s
                    WHERE id=%s AND supplier_fulfillment_id=%s""",
                    (now, row['current_purchase_id'], row['id']))
                if changed != 1:
                    raise CheckoutError('supplier_purchase_identity_invalid')

    def paid(self, order_id, now):
        # The durable pending row is the handoff. No provider call is made here.
        self.db.execute("UPDATE public.supplier_fulfillments SET state='pending',next_attempt_at=%s,updated_at=%s WHERE order_id=%s AND state='reserved'", (now, now, order_id))

    def pending(self, order_id):
        return self.db.one("SELECT id FROM public.supplier_fulfillments WHERE order_id=%s AND state<>'delivered' LIMIT 1", (order_id,)) is not None
