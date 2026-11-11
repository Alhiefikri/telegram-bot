"""Public proof reads identifiers/economics only, never credential columns."""
import re
from zoneinfo import ZoneInfo

from ..checkout.db import Database
from .policy import NotEligible


def mask(first_name, username):
    def clean(value):
        return re.sub(r'[\x00-\x1f\x7f]+', ' ', str(value or '')).strip()
    name = clean(first_name)
    if not name or name.lower() == 'telegram customer':
        name = clean(username)
    name = name.lstrip('@')
    return name[:3] + '***' if name else 'Buyer'


class SalesProofEligibility:
    """Public-safe paid presentation with the retained API completion gate."""
    def __init__(self, *, using='default'):
        self.db = Database(using)

    def load(self, order_id):
        order = self.db.one('''SELECT o.id,o.invoice_number,o.customer_id,o.order_status,o.payment_status,
            o.api_order_claim_id,o.currency,o.total_amount,c.first_name,c.username FROM public.orders o
            JOIN public.customers c ON c.id=o.customer_id WHERE o.id=%s''', (order_id,))
        if (not order or order['payment_status'] != 'paid'
                or order['order_status'] not in ('processing', 'completed')):
            raise NotEligible()
        payments = self.db.rows('''SELECT id,status,paid_at,created_at FROM public.payments
            WHERE order_id=%s ORDER BY date_trunc('second',created_at) DESC,id DESC''', (order_id,))
        if (not payments or not any(row['status'] == 'paid' for row in payments)
                or (order['api_order_claim_id'] is not None
                    and (payments[0]['status'] != 'paid' or payments[0]['paid_at'] is None))):
            raise NotEligible()
        # PHP presents the canonical paid payment, while API eligibility also
        # requires the latest payment attempt to be paid.
        payment = self.db.one('''SELECT id,method,currency,amount,provider_fee,provider_total_payment,status,
            paid_at,created_at FROM public.payments WHERE order_id=%s
              AND status='paid' AND paid_at IS NOT NULL ORDER BY paid_at ASC,id ASC LIMIT 1''', (order_id,))
        if not payment:
            raise NotEligible()
        items = self.db.rows('''SELECT i.id,i.product_id,i.product_name,i.product_icon,
            i.product_custom_emoji_id,i.quantity,
            i.quantity_price_tier_minimum,i.unit_price,i.subtotal,i.usdt_unit_price,i.usdt_subtotal,
            (SELECT COUNT(*) FROM public.product_stock_items s WHERE s.order_item_id=i.id
              AND s.product_id=i.product_id AND s.retired_at IS NULL) AS allocated,
            (SELECT COUNT(*) FROM public.inventory_movements m WHERE m.order_id=i.order_id
              AND m.product_id=i.product_id AND m.type='sale' AND m.quantity=-i.quantity) AS sales
            FROM public.order_items i WHERE i.order_id=%s ORDER BY i.id''', (order_id,))
        if not items or any(i['quantity'] < 1 or i['sales'] != 1 for i in items):
            raise NotEligible()
        if self.db.one('''SELECT id FROM public.supplier_fulfillments
            WHERE order_id=%s AND state<>'delivered' LIMIT 1''', (order_id,)):
            raise NotEligible()
        if order['api_order_claim_id'] is not None:
            if (any(i['allocated'] != i['quantity'] for i in items)
                    or self.db.one('''SELECT id FROM public.manual_fulfillment_reviews
                        WHERE order_id=%s AND state IN ('pending','refund_uncertain') LIMIT 1''', (order_id,))):
                raise NotEligible()
        currency = payment['currency']
        if currency not in ('IDR', 'USDT'):
            raise NotEligible()
        public_items = [{'product_name': i['product_name'], 'icon': i['product_icon'],
                         'product_icon': i['product_icon'],
                         'product_custom_emoji_id': i['product_custom_emoji_id'],
                         'quantity': i['quantity'], 'quantity_price_tier_minimum': i['quantity_price_tier_minimum'],
                         'unit_price': i['usdt_unit_price'] if currency == 'USDT' else i['unit_price'],
                         'subtotal': i['usdt_subtotal'] if currency == 'USDT' else i['subtotal']} for i in items]
        paid = payment['paid_at']
        if paid.tzinfo is None:
            paid = paid.replace(tzinfo=ZoneInfo('Asia/Jakarta'))
        method = {'api_qris': 'QRIS', 'qris': 'QRIS', 'binance_pay_id': 'Binance Pay',
                  'usdt_bep20': 'USDT_BEP20', 'usdt_balance': 'USDT Balance',
                  'store_balance': 'STORE_BALANCE', 'hosted': 'Pakasir',
                  'qr_manual': 'QR Manual'}.get(str(payment['method']).lower(), payment['method'])
        if order['api_order_claim_id'] is not None:
            method += ' · API'
        return {'invoice_number': order['invoice_number'], 'buyer_name': mask(order['first_name'], order['username']),
                'chat_id': None, 'paid_at': paid, 'payment_method': method, 'currency': currency,
                'item_currency': currency if currency == 'USDT' else order['currency'],
                'items': public_items, 'provider_fee': payment['provider_fee'] or 0,
                'payment_subtotal': (self.db.one('''SELECT base_amount_micro FROM public.payment_bsc_details
                    WHERE payment_id=%s''', (payment['id'],)) or {}).get('base_amount_micro', payment['amount'])
                    if currency == 'USDT' and payment['method'] == 'USDT_BEP20' else
                    payment['amount'] if currency == 'USDT' else None,
                'total_paid': payment['amount'] if currency == 'USDT' else
                              payment['provider_total_payment'] if payment['provider_total_payment'] is not None else
                              order['total_amount'],
                'api': order['api_order_claim_id'] is not None}
