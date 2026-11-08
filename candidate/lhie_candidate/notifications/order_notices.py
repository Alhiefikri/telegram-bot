"""Read-only lifecycle presentation and bounded handoff/projection recovery.

Order, receipt, review and supplier owners retain their authority. A paid notice
reports proven delivery outcomes, never infers a send from paid/completed alone.
"""
from datetime import timedelta
from html import escape
import json

from django.db import transaction

from ..checkout.db import Database
from ..navigation.transport import RelayTransport
from ..receipt.protocol import ReceiptProtocol, _legacy_resolved, _now
from .policy import NotEligible
from .html import bounded_html
from .proof_publication import money

PENDING = 'admin.order_pending_notification'
PAID = 'admin.order_paid_notification'
EXPIRED = 'admin.order_expired_notification'
REVIEW = 'admin.order_review_notification'
ORDER_TYPES = (PENDING, PAID, EXPIRED, REVIEW)
COLUMNS = {PENDING: 'admin_pending_order_notified_at', PAID: 'admin_paid_order_notified_at',
           EXPIRED: 'admin_expired_order_notified_at'}
TEMPLATES = {PENDING: 'admin-order-pending-v1', PAID: 'admin-order-paid-v1',
             EXPIRED: 'admin-order-expired-v1', REVIEW: 'admin-order-review-v1'}
_OWNER_REVIEW = """(o.api_order_claim_id IS NOT NULL OR r.reason='local_credentials_missing'
    OR (r.reason='late_payment' AND EXISTS (SELECT 1 FROM public.order_items i WHERE i.order_id=o.id AND i.quantity>0)
        AND NOT EXISTS (SELECT 1 FROM public.product_stock_items s JOIN public.order_items i ON i.id=s.order_item_id WHERE i.order_id=o.id)
        AND NOT EXISTS (SELECT 1 FROM public.supplier_fulfillments f WHERE f.order_id=o.id)))"""


def metadata(value):
    return json.loads(value) if isinstance(value, str) else value or {}


def time_label(value):
    return value.strftime('%d %b %Y, %H:%M') if value else ''


def delivery_label(state):
    return {'completed': 'Selesai', 'delivered': 'Selesai', 'review': 'Perlu review operator',
            'cancelled': 'Dibatalkan', 'processing': 'Sedang diproses', 'pending': 'Sedang diproses',
            'purchasing': 'Sedang diproses', 'reserved': 'Sedang diproses',
            'awaiting_delivery': 'Sedang diproses'}.get(state, 'Belum dipastikan')


class OrderNotices:
    def __init__(self, recipients, *, using='default', cleanup_transport=None):
        self.db = Database(using)
        self.protocol = ReceiptProtocol(using)
        self.recipients = recipients
        self.cleanup_transport = cleanup_transport if cleanup_transport is not None else RelayTransport()
        self._cursors = {}

    def _order(self, order_id):
        return self.db.one('''SELECT o.id,o.invoice_number,o.order_status,o.payment_status,o.total_amount,
            o.expires_at,o.updated_at,o.api_order_claim_id,o.telegram_receipt_sent_at,
            c.telegram_id,c.first_name,c.username FROM public.orders o
            JOIN public.customers c ON c.id=o.customer_id WHERE o.id=%s''', (order_id,))

    def _items(self, order_id):
        return self.db.rows('''SELECT i.id,i.product_id,i.product_name,i.product_icon,i.product_custom_emoji_id,i.quantity,
            (SELECT count(*) FROM public.product_stock_items s WHERE s.order_item_id=i.id) AS allocated,
            p.id AS existing_product,
            CASE WHEN EXISTS (SELECT 1 FROM public.product_stock_items s WHERE s.product_id=p.id AND s.supplier_purchase_id IS NULL)
                THEN LEAST(GREATEST(0,p.stock-p.reserved_stock),(SELECT count(*) FROM public.product_stock_items s
                    WHERE s.product_id=p.id AND s.supplier_purchase_id IS NULL AND s.order_item_id IS NULL AND s.retired_at IS NULL))
                ELSE GREATEST(0,p.stock-p.reserved_stock) END
              + CASE WHEN p.supplier_available_until>CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
                THEN GREATEST(0,p.supplier_available_stock) ELSE 0 END AS available_stock
            FROM public.order_items i LEFT JOIN public.products p ON p.id=i.product_id
            WHERE i.order_id=%s ORDER BY i.id''', (order_id,))

    def _payments(self, order_id):
        return self.db.rows('''SELECT id,status,method,provider,amount,currency,provider_fee,provider_total_payment,
            provider_payment_method,paid_at,expired_at FROM public.payments WHERE order_id=%s ORDER BY created_at,id''', (order_id,))

    def _review(self, review_id):
        return self.db.one('''SELECT r.id,r.order_id FROM public.manual_fulfillment_reviews r
            JOIN public.orders o ON o.id=r.order_id WHERE r.id=%s AND ''' + _OWNER_REVIEW, (review_id,))

    def _eligible(self, order, kind):
        if not order:
            return False
        return ((kind == PENDING and order['order_status'] == 'pending_payment' and order['payment_status'] == 'pending')
                or (kind == EXPIRED and order['order_status'] == order['payment_status'] == 'expired')
                or (kind == PAID and order['payment_status'] == 'paid' and order['order_status'] in ('processing', 'completed'))
                or kind == REVIEW)

    def outcome(self, order, items, payments):
        """Port AdminPaidOrderOutcome without changing any delivery state."""
        effects = self.db.rows('''SELECT * FROM public.telegram_outbound_effects WHERE source_type='App\\Models\\Order'
            AND source_id=%s AND effect_type='receipt.credentials' ''', (str(order['id']),))
        groups = {effect['effect_group_key'] for effect in effects}
        complete = any(self.protocol.is_receipt_group_complete(group) and all(
            _legacy_resolved(effect) or (effect['state'] == 'sent' and str(effect['telegram_message_id'] or '').isdigit()
                                       and int(effect['telegram_message_id']) > 0)
            for effect in effects if effect['effect_group_key'] == group) for group in groups)
        credentials = [effect for effect in effects if metadata(effect['outcome_metadata']).get('contains_credentials') is not False
                       and not (effect['sequence'] == 1 and effect['template_version'] == 'receipt-invoice-v2')]
        acknowledged = sum(effect['state'] == 'sent' and str(effect['telegram_message_id'] or '').isdigit()
                           and int(effect['telegram_message_id']) > 0 for effect in credentials)
        unknown = any(effect['state'] in ('unknown', 'manual_review') for effect in credentials)
        failures = [effect for effect in credentials if effect['state'] == 'failed'
                    and (effect['attempts'] >= 3 or effect['last_error_code'] in self.protocol.terminal_codes)]
        failed = bool(failures)
        reason = {'telegram_http_403': 'Telegram menolak akses bot ke buyer',
                  'telegram_http_400': 'Telegram menolak permintaan pengiriman'}.get(
                      failures[0]['last_error_code'] if failures else None,
                      'pengiriman berhenti pada kegagalan pasti; periksa bukti effect')
        review = self.db.one("SELECT id FROM public.manual_fulfillment_reviews WHERE order_id=%s AND state IN ('pending','refund_uncertain') LIMIT 1", (order['id'],))
        fulfillments = self.db.rows('''SELECT f.id,f.order_item_id,f.state,f.refunded_quantity,
            (SELECT COALESCE(sum(h.quantity),0) FROM public.supplier_manual_handoffs h WHERE h.supplier_fulfillment_id=f.id) AS handed
            FROM public.supplier_fulfillments f WHERE f.order_id=%s ORDER BY f.id''', (order['id'],))
        by_item = {f['order_item_id']: f for f in fulfillments}
        purchases = self.db.rows('''SELECT p.supplier_fulfillment_id,p.state,p.committed_at,p.supplier_order_id,
            p.actual_cost_micros,p.observed_quantity,p.quantity,s.name AS supplier_name FROM public.supplier_purchases p
            JOIN public.supplier_fulfillments f ON f.id=p.supplier_fulfillment_id
            JOIN public.suppliers s ON s.id=p.supplier_id WHERE f.order_id=%s ORDER BY p.id''', (order['id'],))
        resolved, intervention, lines = bool(items), bool(review) or unknown or failed, []
        for item in items:
            fulfillment = by_item.get(item['id'])
            relevant = [p for p in purchases if fulfillment and p['supplier_fulfillment_id'] == fulfillment['id']]
            all_rejected = bool(fulfillment and fulfillment['state'] == 'review' and relevant
                                and all(p['state'] == 'rejected' and p['committed_at'] is None for p in relevant))
            supplier_resolved, summary = True, []
            for purchase in relevant:
                acquired = (purchase['committed_at'] is not None and bool(purchase['supplier_order_id'])
                            and purchase['actual_cost_micros'] is not None and purchase['observed_quantity'] is not None)
                rejected = purchase['state'] == 'rejected' and purchase['committed_at'] is None
                supplier_resolved &= (acquired and purchase['state'] not in ('ambiguous', 'review')) or rejected
                intervention |= purchase['state'] in ('ambiguous', 'review') or fulfillment['state'] == 'review'
                label = (f"pembelian berhasil ({purchase['observed_quantity']}/{purchase['quantity']} unit)"
                         + ('; perlu diperiksa' if purchase['state'] in ('ambiguous', 'review') else '') if acquired
                         else 'pembelian ditolak; tidak ada debit' if rejected
                         else 'hasil pembelian belum dapat dipastikan; jangan beli ulang')
                summary.append(purchase['supplier_name'] + ' · ' + label)
            if fulfillment and not summary:
                supplier_resolved = False
                summary.append('belum terkonfirmasi; jangan beli ulang')
            handed = fulfillment['handed'] if fulfillment else 0
            refunded = fulfillment['refunded_quantity'] if fulfillment else 0
            delivered = item['allocated'] if complete else 0
            buyer_resolved = ((complete and delivered + refunded == item['quantity'])
                              or handed + refunded >= item['quantity'] or failed
                              or (all_rejected and item['allocated'] == 0))
            buyer = (f"{delivered}/{item['quantity']} unit terkirim" if complete else
                     f"{handed}/{item['quantity']} unit diserahkan di luar bot" if handed > 0 else
                     'hasil pengiriman Telegram belum dapat dipastikan; jangan kirim ulang' if unknown else
                     ('pengiriman sebagian; jumlah unit belum pasti' if acknowledged > 0 else 'akun belum terkirim') + '; ' + reason if failed else
                     f"0/{item['quantity']} unit terkirim; pembelian ditolak" if all_rejected and item['allocated'] == 0 else
                     'pengiriman belum terkonfirmasi; jangan kirim ulang')
            resolved &= supplier_resolved and buyer_resolved and (not unknown or complete)
            lines.append(f"{item['product_name']} x {item['quantity']}: {buyer}" + (f'; {refunded} unit refund tercatat' if refunded else ''))
            lines.append('Supplier: ' + ('; '.join(summary) if summary else 'tidak digunakan (stok lokal)'))
        if complete and review:
            resolved = False
        paid = [p['paid_at'] for p in payments if p['status'] == 'paid' and p['paid_at']]
        paid_at = min(paid) if paid else None
        deadline = bool(paid_at and paid_at + timedelta(seconds=120) <= _now())
        if not resolved:
            lines.append('Alasan: ' + ('waktu pembayaran belum terkonfirmasi' if paid_at is None else
                                      'belum selesai setelah 120 detik' if deadline else 'perlu pemeriksaan'))
        return {'ready': bool(resolved or intervention or deadline or paid_at is None), 'resolved': bool(resolved),
                'lines': lines, 'action': 'Order selesai. Tidak perlu proses ulang.' if resolved and complete else
                'Periksa dashboard / Manual Delivery Review. Jangan beli ulang, refund, atau kirim ulang sebelum diperiksa.'}

    def prepare(self, effect):
        if effect['effect_type'] != PAID:
            return True
        with transaction.atomic(using=self.db.using):
            self.db.one('SELECT id FROM public.orders WHERE id=%s FOR UPDATE', (effect['source_id'],))
            locked = self.db.one('SELECT * FROM public.telegram_outbound_effects WHERE id=%s FOR UPDATE', (effect['id'],))
            order = self._order(effect['source_id'])
            if not self._eligible(order, PAID) or not order['telegram_id'] or order['api_order_claim_id'] is not None:
                return True
            if metadata(locked['outcome_metadata']).get('paid_outcome') is not None:
                return True
            outcome = self.outcome(order, self._items(order['id']), self._payments(order['id']))
            if not outcome['ready'] or (locked['supersedes_effect_id'] and not outcome['resolved']):
                self.db.execute('''UPDATE public.telegram_outbound_effects SET next_attempt_at=%s,updated_at=%s
                    WHERE id=%s AND state IN ('pending','failed')''', (_now() + timedelta(seconds=5), _now(), effect['id']))
                return False
            if locked['state'] in ('pending', 'failed'):
                self.db.execute('''UPDATE public.telegram_outbound_effects SET outcome_metadata=
                    COALESCE(outcome_metadata::jsonb,'{}'::jsonb) || %s::jsonb,updated_at=%s WHERE id=%s''',
                    (json.dumps({'delivery_context': 'order_lifecycle', 'paid_outcome': outcome}), _now(), effect['id']))
            return True

    def payload(self, effect):
        kind = effect['effect_type']
        review = self._review(effect['source_id']) if kind == REVIEW else None
        order = self._order(review['order_id'] if review else effect['source_id']) if kind != REVIEW or review else None
        if effect['target_type'] != 'admin' or not self._eligible(order, kind):
            raise NotEligible()
        items, payments = self._items(order['id']), self._payments(order['id'])
        payment = next((p for status in ('paid', 'pending', 'expired') for p in payments if p['status'] == status), payments[0] if payments else None)
        method = (payment['method'] or payment['provider'] or 'Pembayaran') if payment else 'Pembayaran'
        method = 'QRIS' if payment and payment['provider'] == 'pakasir' else {
            'QR_MANUAL': 'QR Manual', 'api_qris': 'QRIS', 'hosted': 'QRIS', 'USDT_BEP20': 'USDT BEP20 (BSC)',
            **({'BINANCE_PAY_ID': 'Binance Pay'} if kind in (PENDING, EXPIRED) else {})}.get(method, method)
        name = str(order['username'] or order['first_name'] or 'Pembeli').strip()
        if order['username']:
            name = '@' + name.lstrip('@')
        if order['telegram_id']:
            name += f" ({order['telegram_id']})"
        origin = 'Customer API' if order['api_order_claim_id'] is not None and kind in (PAID, REVIEW) else 'Telegram Bot' if order['telegram_id'] else 'Web Shop'
        def item_name(item):
            glyph = item['product_icon'] or '🛍️'
            identifier = item['product_custom_emoji_id']
            return (f'<tg-emoji emoji-id="{escape(str(identifier))}">{escape(glyph)}</tg-emoji>' if identifier else escape(glyph)) + ' ' + escape(item['product_name'])
        item_lines = ['- ' + item_name(item) + ' x ' + str(item['quantity']) for item in items[:6]] or ['- Produk digital x 1']
        if len(items) > 6:
            item_lines.append('+ ' + str(len(items) - 6) + ' produk lainnya')
        outcome = metadata(effect['outcome_metadata']).get('paid_outcome')
        if kind == REVIEW and order['telegram_id'] and order['api_order_claim_id'] is None:
            outcome = self.outcome(order, items, payments)
        title = ('🧾 Checkout belum bayar' if kind == PENDING else '⌛ Checkout expired belum bayar' if kind == EXPIRED else
                 '🔔 Review fulfillment API' if kind == REVIEW and order['api_order_claim_id'] is not None else
                 '🔔 Review fulfillment' if kind == REVIEW else
                 '🔔 Hasil order' if outcome and outcome['resolved'] else '🔔 Order perlu ditinjau' if outcome else '🔔 Pembayaran diterima')
        icons = self.db.rows('SELECT source_emoji,fallback_emoji,custom_emoji_id FROM public.telegram_ui_icons WHERE source_emoji IS NOT NULL')
        def static(value):
            for icon in sorted(icons, key=lambda row: len(row['source_emoji']), reverse=True):
                glyph = escape(icon['fallback_emoji'] or '')
                rendered = f'<tg-emoji emoji-id="{escape(str(icon["custom_emoji_id"]))}">{glyph}</tg-emoji>' if icon['custom_emoji_id'] else glyph
                value = value.replace(icon['source_emoji'], rendered)
            return value
        lines = [static(title), '━━━━━━━━━━━━━━', 'Invoice: ' + escape(order['invoice_number']),
                 'Pembeli: ' + escape(name), 'Sumber: ' + origin]
        if kind in (PENDING, EXPIRED):
            amount = money(payment['amount'], 'USDT') if payment and payment['currency'] == 'USDT' else 'Rp' + f"{order['total_amount']:,}".replace(',', '.')
            lines.extend(('Status: Menunggu pembayaran' if kind == PENDING else 'Status: Expired / belum bayar',
                          'Metode: ' + escape(method), 'Total: ' + amount, '', 'Produk:', *item_lines))
            date = order['expires_at'] if kind == PENDING else (payment['expired_at'] if payment else None) or order['expires_at'] or order['updated_at']
            if date:
                lines.extend(('', ('Batas bayar: ' if kind == PENDING else 'Expired at: ') + time_label(date)))
            if kind == EXPIRED:
                lines.extend(('', 'Stok sudah dilepas kembali.'))
            lines.extend(('', 'Cek dashboard untuk follow up pembeli ini.'))
        else:
            review_open = bool(self.db.one("SELECT id FROM public.manual_fulfillment_reviews WHERE order_id=%s AND state IN ('pending','refund_uncertain') LIMIT 1", (order['id'],)))
            lines.append('Status pembayaran: ' + (order['payment_status'] if order['api_order_claim_id'] is not None else 'Paid'))
            if outcome:
                lines.extend(escape(line) for line in outcome['lines'])
            else:
                lines.extend(self._fulfillment_lines(order, items))
            if kind == REVIEW or order['api_order_claim_id'] is not None:
                lines.extend(('Status order: ' + order['order_status'], 'Review operator: ' + ('diperlukan; buka dashboard' if review_open else 'tidak ada review terbuka')))
            amount = payment['amount'] if payment else order['total_amount']
            fee = payment['provider_fee'] or 0 if payment else 0
            total = payment['provider_total_payment'] if payment else None
            historical = payment and (payment['provider'] or '').lower() == 'pakasir' and ((payment['method'] or '').lower() == 'api_qris' or (payment['provider_payment_method'] or '').lower() == 'qris')
            net = max(0, total - fee if total is not None else amount if historical else amount - fee)
            earning = money(net, 'USDT') if payment and payment['currency'] == 'USDT' else 'Rp' + f'{net:,}'.replace(',', '.')
            if fee > 0 and net < amount:
                earning += ' (setelah fee)'
            lines.extend(('Metode: ' + escape(method), 'Earning: ' + earning, '', 'Produk:', *item_lines, '', static('📦 Sisa Stok:')))
            lines.extend('- ' + item_name(item) + ': ' + (str(item['available_stock']) if item['existing_product'] else '(Produk dihapus)') for item in items)
            if not items:
                lines.append('- Produk digital: ∞')
            lines.extend(('', 'Paid at: ' + time_label(payment['paid_at'] if payment and payment['paid_at'] else order['updated_at']), '',
                          escape(outcome['action']) if outcome else 'Cek dashboard untuk proses order ini.'))
        text = bounded_html('\n'.join(lines))
        return {'chat_id': effect['telegram_chat_id'], 'text': text, 'parse_mode': 'HTML',
                'reply_markup': {'inline_keyboard': [[{'text': 'Buka Dashboard', 'style': 'primary',
                    'url': 'https://localhost/admin/orders/' + str(order['id'])}]]}}

    def _fulfillment_lines(self, order, items):
        fulfillments = self.db.rows('''SELECT f.id,f.order_item_id,f.state,
            EXISTS (SELECT 1 FROM public.supplier_manual_handoffs h WHERE h.supplier_fulfillment_id=f.id) AS handed
            FROM public.supplier_fulfillments f WHERE f.order_id=%s ORDER BY f.id''', (order['id'],))
        if not fulfillments:
            if order['api_order_claim_id'] is not None:
                from .proof import SalesProofEligibility
                try:
                    SalesProofEligibility(using=self.db.using).load(order['id'])
                    label = 'Fulfillment API selesai · hasil tersedia melalui API'
                except NotEligible:
                    label = 'Belum selesai; periksa dashboard'
            elif order['order_status'] == 'completed':
                label = 'Receipt Telegram tercatat terkirim' if order['telegram_receipt_sent_at'] else 'Fulfillment selesai · receipt Telegram belum terkonfirmasi; periksa Manual Delivery Review'
            else:
                label = delivery_label(order['order_status'])
            return ['Pengiriman pelanggan: ' + label, 'Pembelian supplier: tidak digunakan']
        deliveries, purchases = [], []
        by_id = {item['id']: item for item in items}
        for fulfillment in fulfillments:
            label = delivery_label(fulfillment['state'])
            if fulfillment['state'] == 'delivered':
                label = ('Selesai · handoff luar bot dicatat' if fulfillment['handed'] else
                         'Fulfillment API selesai · hasil tersedia melalui API' if order['api_order_claim_id'] is not None else
                         'Receipt Telegram tercatat terkirim' if order['telegram_receipt_sent_at'] else
                         'Supplier fulfillment selesai · receipt Telegram belum terkonfirmasi; periksa Manual Delivery Review')
            deliveries.append((by_id.get(fulfillment['order_item_id']) or {}).get('product_name', 'Produk') + ': ' + label)
            for purchase in self.db.rows('''SELECT p.state,s.name FROM public.supplier_purchases p
                JOIN public.suppliers s ON s.id=p.supplier_id WHERE p.supplier_fulfillment_id=%s ORDER BY p.id''', (fulfillment['id'],)):
                label = {'delivered': 'pembelian berhasil', 'settled': 'pembelian berhasil', 'rejected': 'pembelian ditolak',
                         'cancelled': 'dibatalkan', 'ambiguous': 'belum dapat dipastikan; periksa dashboard, jangan beli ulang',
                         'review': 'belum dapat dipastikan; periksa dashboard, jangan beli ulang'}.get(purchase['state'], 'sedang diproses')
                purchases.append(purchase['name'] + ' · ' + label)
        return ['Pengiriman pelanggan: ' + escape('; '.join(deliveries[:3])), 'Supplier: ' + (escape('; '.join(purchases[:3])) if purchases else 'belum ada pembelian')]

    def project(self, effect):
        kind = effect['effect_type']
        if (kind not in COLUMNS or effect['state'] != 'sent'
                or not str(effect['telegram_message_id'] or '').isdigit() or int(effect['telegram_message_id']) < 1):
            return 0
        if self.db.one('''SELECT id FROM public.telegram_outbound_effects WHERE source_type=%s AND source_id=%s
            AND effect_type=%s AND state='sent' AND sequence>%s LIMIT 1''',
            (effect['source_type'], effect['source_id'], kind, effect['sequence'])):
            return 0
        column, at = COLUMNS[kind], _now()
        if kind == PENDING:
            return self.db.execute(f'''UPDATE public.orders SET {column}=%s,admin_pending_order_notification_chat_id=%s,
                admin_pending_order_notification_message_id=%s,updated_at=%s WHERE id=%s AND
                ({column},admin_pending_order_notification_chat_id,admin_pending_order_notification_message_id)
                IS DISTINCT FROM (%s,%s,%s)''',
                (effect['sent_at'], effect['telegram_chat_id'], effect['telegram_message_id'], at, effect['source_id'],
                 effect['sent_at'], effect['telegram_chat_id'], effect['telegram_message_id']))
        return self.db.execute(f'UPDATE public.orders SET {column}=%s,updated_at=%s WHERE id=%s AND {column} IS NULL',
                               (effect['sent_at'], at, effect['source_id']))

    def cleanup(self, order_id):
        order = self.db.one('''SELECT order_status,payment_status,admin_pending_order_notification_deleted_at,
            admin_pending_order_notification_chat_id,admin_pending_order_notification_message_id FROM public.orders WHERE id=%s''', (order_id,))
        if not order or (order['order_status'] == 'pending_payment' and order['payment_status'] == 'pending') or order['admin_pending_order_notification_deleted_at']:
            return
        row = self.db.one('''SELECT telegram_chat_id,telegram_message_id FROM public.telegram_outbound_effects
            WHERE source_type='App\\Models\\Order' AND source_id=%s AND effect_type=%s AND state='sent'
            AND telegram_message_id IS NOT NULL ORDER BY sent_at DESC LIMIT 1''', (str(order_id), PENDING))
        chat = row['telegram_chat_id'] if row else order['admin_pending_order_notification_chat_id']
        message = row['telegram_message_id'] if row else order['admin_pending_order_notification_message_id']
        if not chat or not message or not self.recipients.permits(chat):
            return
        try:
            result = self.cleanup_transport.execute('deleteMessage', {'chat_id': str(chat), 'message_id': int(message)})
        except Exception:
            return
        if result.outcome == 'success':
            self.db.execute('''UPDATE public.orders SET admin_pending_order_notification_deleted_at=%s
                WHERE id=%s AND admin_pending_order_notification_message_id=%s
                  AND admin_pending_order_notification_deleted_at IS NULL''', (_now(), order_id, message))

    def discover(self, record, limit):
        setting = self.db.one('SELECT admin_telegram_chat_id FROM public.manual_payment_settings WHERE is_active=true ORDER BY id LIMIT 1')
        chat = str((setting or {}).get('admin_telegram_chat_id') or '').strip()
        if not self.recipients.permits(chat):
            return
        for kind, column in COLUMNS.items():
            cursor = self._cursors.get(kind)
            rows = self.db.rows(f'''SELECT o.id FROM public.orders o WHERE o.{column} IS NULL
                AND (%s::uuid IS NULL OR o.id>%s::uuid) AND NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects e
                    WHERE e.source_type='App\\Models\\Order' AND e.source_id=o.id::text AND e.effect_type=%s)
                ORDER BY o.id LIMIT %s''', (cursor, cursor, kind, limit))
            self._cursors[kind] = str(rows[-1]['id']) if rows else None
            for row in rows:
                with transaction.atomic(using=self.db.using):
                    self.db.one('SELECT id FROM public.orders WHERE id=%s FOR UPDATE', (row['id'],))
                    order = self._order(row['id'])
                    if self._eligible(order, kind):
                        record(key=f"order:{row['id']}:{kind}:v1", kind=kind, model='Order', source=row['id'],
                               chat=chat, target='admin', locale='id', template=TEMPLATES[kind])
        cursor = self._cursors.get(REVIEW, 0)
        rows = self.db.rows('''SELECT r.id,r.order_id FROM public.manual_fulfillment_reviews r
            JOIN public.orders o ON o.id=r.order_id WHERE r.id>%s AND ''' + _OWNER_REVIEW + '''
            AND NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects e WHERE e.source_type='App\\Models\\ManualFulfillmentReview'
                AND e.source_id=r.id::text AND e.effect_type=%s) ORDER BY r.id LIMIT %s''', (cursor, REVIEW, limit))
        self._cursors[REVIEW] = rows[-1]['id'] if rows else 0
        for row in rows:
            with transaction.atomic(using=self.db.using):
                record(key=f"order-review:{row['id']}:owner:v1", kind=REVIEW, model='ManualFulfillmentReview',
                       source=row['id'], chat=chat, target='admin', locale='id', template=TEMPLATES[REVIEW])

    def recover_results(self, record, limit):
        cursor = self._cursors.get('results')
        rows = self.db.rows('''SELECT e.* FROM public.telegram_outbound_effects e
            WHERE e.effect_type=%s AND e.source_type='App\\Models\\Order'
              AND e.outcome_metadata->'paid_outcome'->>'resolved'='false'
              AND e.state IN ('sent','unknown','manual_review') AND e.telegram_chat_id=ANY(%s)
              AND (%s::uuid IS NULL OR e.id>%s::uuid)
              AND NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects n WHERE n.supersedes_effect_id=e.id)
            ORDER BY e.id LIMIT %s''', (PAID, list(self.recipients.telegram), cursor, cursor, limit))
        self._cursors['results'] = str(rows[-1]['id']) if rows else None
        for effect in rows:
            with transaction.atomic(using=self.db.using):
                self.db.one('SELECT id FROM public.orders WHERE id=%s FOR UPDATE', (effect['source_id'],))
                order = self._order(effect['source_id'])
                if not order or not self.outcome(order, self._items(order['id']), self._payments(order['id']))['resolved']:
                    continue
                record(key=effect['effect_key'] + ':result', kind=PAID, model='Order', source=order['id'],
                       chat=effect['telegram_chat_id'], target='admin', locale='id', template=effect['template_version'],
                       supersedes=effect['id'])
