"""Load the paid Customer's existing allocation, never a caller-provided secret."""
from datetime import timedelta
from urllib.parse import urlsplit
from zoneinfo import ZoneInfo

from lhie_candidate.checkout.db import Database
from lhie_candidate.compat.identity import normalize_credential_identity, signed_url_digest
from lhie_candidate.domain.money import Currency, Money
from .render import _tr


class ReceiptUnavailable(ValueError):
    def __init__(self):
        super().__init__('Receipt is not available')


def _https(value):
    if not isinstance(value, str) or not value or len(value) > 2048:
        return False
    try:
        url = urlsplit(value)
        return (url.scheme == 'https' and bool(url.hostname) and not url.username and not url.password
                and not any(ord(c) <= 32 or ord(c) == 127 for c in value))
    except ValueError:
        return False


def _account(row, cipher, number, tutorial):
    if row['credential_format'] in (None, 'legacy'):
        value = cipher.decrypt_string(row['secret'])
        if not isinstance(value, str) or not value or row['credential_payload'] is not None:
            raise ReceiptUnavailable()
        return {'format': 'legacy', 'secret': value, 'number': number, 'tutorial': tutorial}
    if row['credential_format'] != 'structured_account' or row['secret'] is not None:
        raise ReceiptUnavailable()
    data = cipher.decrypt_json(row['credential_payload'])
    if not isinstance(data, dict):
        raise ReceiptUnavailable()
    version = data.get('version', data.get('payload_version'))
    if type(version) is not int or version != 1 or ('version' in data and 'payload_version' in data and data['version'] != data['payload_version']):
        raise ReceiptUnavailable()
    email = normalize_credential_identity('email', data.get('email'))
    password = data.get('password')
    method, access = data.get('access_method'), data.get('access_value')
    override, reader = data.get('tutorial_override'), data.get('reader_mail_link')
    if (len(email) > 254 or not isinstance(password, str) or not password.strip() or len(password) > 1024
            or method not in ('none', 'otp_mail_link', 'auth_token')
            or (access is not None and not isinstance(access, str))
            or (override is not None and (not isinstance(override, str) or len(override) > 2000))
            or (reader is not None and not isinstance(reader, str))):
        raise ReceiptUnavailable()
    access = access if access and access.strip() else None
    reader = reader if reader and reader.strip() else None
    if ((method == 'none' and access is not None)
            or (method == 'otp_mail_link' and not _https(access))
            or (method == 'auth_token' and (access is None or len(access) > 3500))
            or (reader is not None and not _https(reader))):
        raise ReceiptUnavailable()
    return {'format': 'structured_account', 'email': email, 'password': password,
            'access_method': method, 'access_value': access, 'reader_mail_link': reader,
            'number': number, 'tutorial': override if override and override.strip() else tutorial}


class ReceiptSource:
    def __init__(self, encrypter, *, using='default', invoice_base_url='', signing_key=None, custom_emoji=False):
        self.db = Database(using)
        self.encrypter = encrypter
        self.invoice_base_url = invoice_base_url.rstrip('/')
        self.signing_key = signing_key
        self.custom_emoji = custom_emoji

    def icons(self):
        return self.db.rows('''SELECT key,fallback_emoji,source_emoji,custom_emoji_id
            FROM public.telegram_ui_icons ORDER BY sort_order,label,id''')

    def support_url(self):
        setting = self.db.one('''SELECT admin_contact_url,admin_username FROM public.manual_payment_settings
            ORDER BY is_active DESC,id LIMIT 1''')
        if not setting:
            return ''
        url = (setting['admin_contact_url'] or '').strip()
        username = (setting['admin_username'] or '').strip().lstrip('@')
        if url:
            return url if _https(url) else ''
        if username and all(c.isascii() and (c.isalnum() or c == '_') for c in username):
            return 'https://t.me/' + username
        return ''

    def allocation_signature(self, order_id):
        rows = self.db.rows('''SELECT s.id,s.order_item_id,s.product_id,s.retired_at,s.credential_format,
            s.secret,s.credential_payload FROM public.product_stock_items s
            JOIN public.order_items i ON i.id=s.order_item_id WHERE i.order_id=%s ORDER BY i.id,s.id''', (order_id,))
        return tuple(tuple(row.values()) for row in rows)

    def authorize(self, order_id, chat_id, *, lock=False, receipt=False):
        """Authorize a Telegram chat against the canonical Customer and Order."""
        return self._authorize(order_id, chat_id=chat_id, lock=lock, receipt=receipt)

    def authorize_customer(self, order_id, customer_id, *, lock=False, receipt=False):
        """Share the same paid-delivery gate with customer-owned API readers."""
        if type(customer_id) is not int or customer_id < 1:
            raise ReceiptUnavailable()
        return self._authorize(order_id, customer_id=customer_id, lock=lock, receipt=receipt)

    def _authorize(self, order_id, *, chat_id=None, customer_id=None, lock=False, receipt=False):
        suffix = ' FOR UPDATE OF o' if lock else ''
        order = self.db.one('''SELECT o.id,o.customer_id,o.invoice_number,o.order_status,o.payment_status,
            o.telegram_receipt_sent_at,o.total_amount,o.currency,o.created_at,c.telegram_id,c.locale,c.first_name
            FROM public.orders o JOIN public.customers c ON c.id=o.customer_id WHERE o.id=%s''' + suffix, (order_id,))
        if (not order or (customer_id is not None and order['customer_id'] != customer_id)
                or (customer_id is None and (order['telegram_id'] is None or str(order['telegram_id']) != str(chat_id)))
                or order['payment_status'] != 'paid' or order['order_status'] not in ('processing', 'completed')):
            raise ReceiptUnavailable()
        payment = self.db.one('''SELECT id,status,paid_at,method,amount,currency,provider_total_payment,provider_fee
            FROM public.payments WHERE order_id=%s ORDER BY created_at DESC,id DESC LIMIT 1''', (order_id,))
        if not payment or payment['status'] != 'paid' or payment['paid_at'] is None:
            raise ReceiptUnavailable()
        review = "SELECT id FROM public.manual_fulfillment_reviews WHERE order_id=%s AND state IN ('pending','refund_uncertain')"
        if not receipt:
            review += " AND reason='late_payment'"
        if self.db.one(review + ' LIMIT 1', (order_id,)):
            raise ReceiptUnavailable()
        if self.db.one("SELECT id FROM public.supplier_fulfillments WHERE order_id=%s AND state <> 'delivered' LIMIT 1", (order_id,)):
            raise ReceiptUnavailable()
        if receipt and self.db.one('''SELECT h.id FROM public.supplier_manual_handoffs h
            JOIN public.supplier_fulfillments f ON f.id=h.supplier_fulfillment_id WHERE f.order_id=%s LIMIT 1''', (order_id,)):
            raise ReceiptUnavailable()
        return order, payment

    def load(self, order_id, chat_id, *, locale=None, lock=False, receipt=False):
        """Authorization precedes credential selection and decryption."""
        order, payment = self.authorize(order_id, chat_id, lock=lock, receipt=receipt)
        return self._load(order_id, order, payment, locale=locale, receipt=receipt)

    def load_customer(self, order_id, customer_id, *, locale=None, lock=False):
        order, payment = self.authorize_customer(order_id, customer_id, lock=lock)
        return self._load(order_id, order, payment, locale=locale, receipt=False)

    def validate_allocation(self, order, payment, items, allocated, *, require_complete=False):
        """Validate retained ownership and return only non-retired allocated rows."""
        order_id = order['id']
        refunded = self.db.rows('''SELECT f.order_item_id,r.missing_quantity AS quantity,
                NULL::bigint AS stock_id FROM public.order_refunds r
            JOIN public.supplier_fulfillments f ON f.id=r.supplier_fulfillment_id AND f.order_id=r.order_id
            WHERE r.order_id=%s AND r.payment_id=%s AND r.customer_id=%s AND r.currency=%s
              AND r.state='confirmed' AND f.refunded_quantity=r.missing_quantity
            UNION ALL SELECT u.order_item_id,1 AS quantity,u.product_stock_item_id AS stock_id
            FROM public.order_refunds r JOIN public.supplier_account_units u
              ON u.id=r.supplier_account_unit_id AND u.order_id=r.order_id
            WHERE r.order_id=%s AND r.payment_id=%s AND r.customer_id=%s AND r.currency=%s
              AND r.state='confirmed' AND r.warranty_claim_id IS NOT NULL''',
            (order_id, payment['id'], order['customer_id'], payment['currency'],
             order_id, payment['id'], order['customer_id'], payment['currency']))
        handoffs = self.db.rows('''SELECT f.order_item_id,COALESCE(SUM(h.quantity),0) AS quantity
            FROM public.supplier_fulfillments f JOIN public.supplier_manual_handoffs h
              ON h.supplier_fulfillment_id=f.id
            WHERE f.order_id=%s AND f.state='delivered' GROUP BY f.order_item_id''', (order_id,))
        item_by_id = {item['id']: item for item in items}
        stock_by_id = {row['id']: row for row in allocated}
        if len(item_by_id) != len(items) or len(stock_by_id) != len(allocated):
            raise ReceiptUnavailable()
        for row in allocated:
            item = item_by_id.get(row['order_item_id'])
            if item is None or row['product_id'] != item['product_id']:
                raise ReceiptUnavailable()
        refunded_ids = set()
        for row in refunded:
            if row['order_item_id'] not in item_by_id or type(row['quantity']) is not int or row['quantity'] < 1:
                raise ReceiptUnavailable()
            if row['stock_id'] is not None:
                stock = stock_by_id.get(row['stock_id'])
                if stock is None or stock['order_item_id'] != row['order_item_id'] or stock['retired_at'] is None:
                    raise ReceiptUnavailable()
                refunded_ids.add(row['stock_id'])
        for row in handoffs:
            if row['order_item_id'] not in item_by_id:
                raise ReceiptUnavailable()
        active = [row for row in allocated if row['retired_at'] is None]
        if require_complete and any(row['retired_at'] is not None and row['id'] not in refunded_ids for row in allocated):
            raise ReceiptUnavailable()
        for item in items:
            rows = [row for row in allocated if row['order_item_id'] == item['id']]
            retained = [row for row in rows if row['retired_at'] is None]
            item_refunds = [row for row in refunded if row['order_item_id'] == item['id']]
            returned = sum(row['quantity'] for row in item_refunds)
            missing = sum(row['quantity'] for row in item_refunds if row['stock_id'] is None)
            handed = sum(row['quantity'] for row in handoffs if row['order_item_id'] == item['id'])
            quantity = item['quantity']
            if (not 0 <= returned + handed <= quantity
                    or len(rows) > quantity - missing - handed
                    or len(retained) > quantity - returned - handed
                    or (require_complete and len(retained) != quantity - returned - handed)):
                raise ReceiptUnavailable()
        return active

    def _load(self, order_id, order, payment, *, locale, receipt):
        items = self.db.rows('''SELECT i.id,i.product_id,i.product_name,i.product_icon,i.product_custom_emoji_id,
            i.quantity,i.unit_price,i.subtotal,i.usdt_unit_price,i.usdt_subtotal,i.delivery_note,i.delivery_note_snapshotted,
            i.usage_guide_title,i.usage_guide_url,i.usage_guide_snapshotted,
            p.delivery_note AS product_delivery_note,p.usage_guide_title AS product_guide_title,
            p.usage_guide_url AS product_guide_url
            FROM public.order_items i LEFT JOIN public.products p ON p.id=i.product_id
            WHERE i.order_id=%s ORDER BY i.id''', (order_id,))
        if not items:
            raise ReceiptUnavailable()
        allocated = self.db.rows('''SELECT s.id,s.order_item_id,s.product_id,s.retired_at,s.credential_format,
            s.secret,s.credential_payload FROM public.product_stock_items s
            JOIN public.order_items i ON i.id=s.order_item_id WHERE i.order_id=%s ORDER BY i.id,s.id''', (order_id,))
        allocation_signature = tuple(tuple(row.values()) for row in allocated)
        allocated = self.validate_allocation(order, payment, items, allocated, require_complete=receipt)
        if not allocated:
            raise ReceiptUnavailable()
        groups = []
        try:
            for item in items:
                rows = [row for row in allocated if row['order_item_id'] == item['id']]
                if not rows:
                    continue
                tutorial = item['delivery_note'] if item['delivery_note_snapshotted'] else item['product_delivery_note']
                tutorial = tutorial.strip() if isinstance(tutorial, str) and tutorial.strip() else None
                title = item['usage_guide_title'] if item['usage_guide_snapshotted'] else item['product_guide_title']
                url = item['usage_guide_url'] if item['usage_guide_snapshotted'] else item['product_guide_url']
                guide = None
                if title and url:
                    if url.startswith('/') and self.invoice_base_url:
                        url = self.invoice_base_url + url
                    if not (_https(url) or (self.invoice_base_url == 'http://127.0.0.1:18801'
                            and url.startswith(self.invoice_base_url + '/')
                            and not any(ord(c) <= 32 or ord(c) == 127 for c in url))):
                        raise ReceiptUnavailable()
                    guide = {'title': title.strip(), 'url': url}
                groups.append({'product_name': item['product_name'], 'icon': item['product_icon'],
                    'custom_emoji_id': item['product_custom_emoji_id'], 'default_tutorial': tutorial,
                    'guide': guide, 'accounts': [_account(row, self.encrypter, n, tutorial) for n, row in enumerate(rows, 1)]})
        except Exception:
            raise ReceiptUnavailable() from None
        amount = payment['provider_total_payment'] if payment['provider_total_payment'] is not None else order['total_amount']
        if payment['currency'] == 'USDT':
            amount = payment['amount']
        elif payment['currency'] != 'IDR':
            raise ReceiptUnavailable()
        display_locale = locale or order['locale']
        method = payment['method'].lower()
        method = {'qris': 'QRIS', 'api_qris': 'QRIS', 'hosted': 'QRIS',
                  'binance_pay_id': 'Binance Pay', 'usdt_bep20': 'USDT BEP20 (BSC)',
                  'store_balance': _tr(display_locale, 'payment.method.store_balance'),
                  'usdt_balance': _tr(display_locale, 'payment.method.usdt_balance'),
                  'qr_manual': _tr(display_locale, 'payment.method.manual'),
                  'bank_transfer': _tr(display_locale, 'payment.method.bank_transfer')}.get(method, payment['method'])
        paid = payment['paid_at']
        paid_label = f"{paid:%d} {_tr(display_locale, 'date.month.' + str(paid.month))} {paid:%Y %H:%M} WIB"
        invoice_url = ''
        if self.signing_key and self.invoice_base_url and order['created_at'] is not None:
            created = order['created_at']
            if created.tzinfo is None:
                created = created.replace(tzinfo=ZoneInfo('Asia/Jakarta'))
            expires = int((created + timedelta(days=7)).timestamp())
            unsigned = f"/shop/orders/{order['id']}?expires={expires}"
            invoice_url = self.invoice_base_url + unsigned + '&signature=' + signed_url_digest(unsigned, self.signing_key)
        invoice = order['invoice_number'] or str(order['id'])
        return {'order_id': str(order['id']), 'customer_id': order['customer_id'],
            'chat_id': str(order['telegram_id']) if order['telegram_id'] is not None else None,
            'invoice_number': invoice, 'invoice_number_raw': order['invoice_number'], 'locale': display_locale, 'items': [
                {'product_name': i['product_name'], 'icon': i['product_icon'], 'custom_emoji_id': i['product_custom_emoji_id'],
                 'quantity': i['quantity'],
                 'unit_price': i['usdt_unit_price'] if payment['currency'] == 'USDT' else i['unit_price'],
                 'subtotal': i['usdt_subtotal'] if payment['currency'] == 'USDT' else i['subtotal']} for i in items],
            'groups': groups, 'allocated_count': len(allocated),
            '_allocation_signature': allocation_signature,
            'amount_paid': (Money(Currency.USDT, amount).format_decimal() + ' USDT' if payment['currency'] == 'USDT'
                            else 'Rp ' + f'{amount:,}'.replace(',', '.')),
            'payment_method': method, 'paid_at': paid_label,
            'invoice_url': invoice_url,
            'custom_emoji': self.custom_emoji, 'ui_icons': self.icons(),
            'telegram_receipt_sent_at': order['telegram_receipt_sent_at'],
            'buyer_name': order['first_name'], 'payment': payment}
