"""Locked historical supplier account identities and retail allocations.

Provider account confirmation and replacement acquisition are retired.  This
module only reads retained identities; it never creates or releases stock.
"""
from lhie_candidate.checkout.db import Database
from lhie_candidate.checkout.errors import CheckoutError
from lhie_candidate.domain.money import require_integer


def retail_unit(total, quantity, number):
    total = require_integer(total)
    quantity = require_integer(quantity, minimum=1)
    number = require_integer(number, minimum=1)
    if number > quantity:
        raise CheckoutError('refund_entitlement_invalid')
    base, remainder = divmod(total, quantity)
    return base + int(number <= remainder)


class HistoricalAccountUnits:
    def __init__(self, using='default'):
        self.db = Database(using)

    def lock(self, unit_id, order, payment):
        """Lock an existing unit after the lifecycle supplier resource locks."""
        self.db.require_transaction()
        unit = self.db.one('SELECT * FROM public.supplier_account_units WHERE id=%s FOR UPDATE', (unit_id,))
        if unit is None or str(unit['order_id']) != str(order['id']):
            raise CheckoutError('refund_entitlement_invalid')
        stock = self.db.one('SELECT * FROM public.product_stock_items WHERE id=%s FOR UPDATE',
                            (unit['product_stock_item_id'],))
        item = self.db.one('''SELECT id,order_id,product_id,quantity,subtotal,usdt_subtotal
            FROM public.order_items WHERE id=%s''', (unit['order_item_id'],))
        purchase = self.db.one('''SELECT p.id,p.supplier_id,p.supplier_fulfillment_id,
                f.order_id,f.order_item_id FROM public.supplier_purchases p
            JOIN public.supplier_fulfillments f ON f.id=p.supplier_fulfillment_id
            WHERE p.id=%s''', (unit['supplier_purchase_id'],))
        if (item is None or stock is None or purchase is None
                or str(item['order_id']) != str(order['id'])
                or str(purchase['order_id']) != str(order['id'])
                or purchase['order_item_id'] != item['id']
                or purchase['supplier_id'] != unit['supplier_id']
                or stock['order_item_id'] != item['id']
                or stock['product_id'] != item['product_id']
                or stock['supplier_purchase_id'] != purchase['id']
                or stock['supplier_unit_index'] is None
                or unit['retail_currency'] != payment['currency']):
            raise CheckoutError('refund_entitlement_invalid')
        subtotal = item['usdt_subtotal'] if payment['currency'] == 'USDT' else item['subtotal']
        if (subtotal is None or unit['retail_amount'] <= 0
                or unit['retail_amount'] != retail_unit(subtotal, item['quantity'], unit['unit_number'])):
            raise CheckoutError('refund_entitlement_invalid')
        return unit, stock
