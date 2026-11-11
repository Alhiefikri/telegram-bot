"""Admin Order lifecycle and manual-payment evidence adapters."""
from datetime import datetime, timedelta
from html import escape
import re
from urllib.parse import urlsplit
from zoneinfo import ZoneInfo

from django.conf import settings
from django.db import transaction

from ..receipt.protocol import ReceiptProtocol, EFFECT_TYPE, LEGACY_INVOICE_TEMPLATE, _now
from .policy import NotEligible
from .snapshot import frozen_payload, latest_sent, metadata, record_snapshot


PENDING = 'admin.order_pending_notification'
PAID = 'admin.order_paid_notification'
EXPIRED = 'admin.order_expired_notification'
REVIEW = 'admin.order_review_notification'
MANUAL = 'admin.manual_payment_proof'
MODELS = {PENDING: 'Order', PAID: 'Order', EXPIRED: 'Order', REVIEW: 'ManualFulfillmentReview', MANUAL: 'Payment'}
LIMITS = {kind: 3 for kind in MODELS}
MARKERS = {PENDING: 'admin_pending_order_notified_at', PAID: 'admin_paid_order_notified_at',
           EXPIRED: 'admin_expired_order_notified_at'}
TEMPLATES = {PENDING: 'admin-order-pending-v1', PAID: 'admin-order-paid-v1',
             EXPIRED: 'admin-order-expired-v1', REVIEW: 'admin-order-review-v1', MANUAL: 'manual-payment-proof-v1'}


def rupiah(amount, space=False):
    return ('Rp ' if space else 'Rp') + f'{int(amount):,}'.replace(',', '.')


def usdt(amount, fixed=False):
    whole, fraction = divmod(int(amount), 1_000_000)
    value = str(whole) + '.' + f'{fraction:06d}'
    return (value if fixed else value.rstrip('0').rstrip('.')) + ' USDT'


def time_label(value):
    if isinstance(value, str):
        value = datetime.fromisoformat(value)
    if value.tzinfo:
        value = value.astimezone(ZoneInfo('Asia/Jakarta'))
    return value.strftime('%d %b %Y, %H:%M')


def legacy_icons(text, icons):
    by_source = {row['source_emoji']: row for row in icons if row.get('source_emoji')}
    if not by_source:
        return escape(text)
    pattern = '|'.join(re.escape(source) for source in sorted(by_source, key=len, reverse=True))
    result, previous = [], 0
    for match in re.finditer(pattern, text):
        result.append(escape(text[previous:match.start()]))
        row = by_source[match[0]]
        glyph, identifier = row.get('fallback_emoji') or '', str(row.get('custom_emoji_id') or '')
        result.append(f'<tg-emoji emoji-id="{identifier}">{escape(glyph)}</tg-emoji>'
                      if identifier.isascii() and identifier.isdigit() and glyph else escape(glyph))
        previous = match.end()
    result.append(escape(text[previous:]))
    return ''.join(result)


def product_name(item):
    glyph, identifier = (item.get('product_icon') or '').strip(), str(item.get('product_custom_emoji_id') or '')
    glyph = glyph or '🛍️'
    icon = (f'<tg-emoji emoji-id="{identifier}">{escape(glyph)}</tg-emoji>'
            if identifier.isascii() and identifier.isdigit() else escape(glyph))
    return icon + ' ' + escape(item['product_name'])


def buyer_label(source):
    name = str(source.get('username') or source.get('first_name') or 'Pembeli').strip()
    if source.get('username'):
        name = '@' + name.lstrip('@')
    return name + (' (' + str(source['telegram_id']) + ')' if source.get('telegram_id') else '')


def payment_label(payment, paid=False):
    value = str(payment.get('payment_method_label') or payment.get('method') or payment.get('provider') or 'Pembayaran').strip()
    labels = {'QR_MANUAL': 'QR Manual', 'api_qris': 'QRIS', 'hosted': 'QRIS', 'USDT_BEP20': 'USDT BEP20 (BSC)'}
    if not paid:
        labels['BINANCE_PAY_ID'] = 'Binance Pay'
    return labels.get(value, value)


def _button(icons, slot, label, callback):
    row = next((row for row in icons if row['key'] == slot), {})
    identifier = str(row.get('custom_emoji_id') or '')
    glyph = row.get('fallback_emoji')
    text = label
    for prefix in (row.get('source_emoji'), glyph, '✅' if slot == 'message.success' else '❌'):
        if prefix and text.startswith(prefix):
            text = text[len(prefix):].lstrip()
            break
    button = {'text': text if identifier else ((glyph + ' ' + text) if glyph else label),
              'callback_data': callback, 'style': 'primary'}
    if identifier:
        button['icon_custom_emoji_id'] = identifier
    return button


def late_proof(order, payment):
    return (payment['method'] == 'BINANCE_PAY_ID' and payment['status'] in ('expired', 'failed')
            and order['order_status'] in ('expired', 'cancelled'))


def manual_payload(order, payment, items, icons, chat):
    lines = [legacy_icons('📸 Bukti pembayaran masuk', icons), '━━━━━━━━━━━━━━',
             legacy_icons('🧾 Invoice: ', icons) + escape(order['invoice_number']),
             legacy_icons('👤 Customer: ', icons) + escape('@' + str(order.get('username') or '-') + ' (' + str(order.get('telegram_id') or '') + ')'),
             legacy_icons('💳 Metode: ', icons) + escape(payment.get('payment_method_label') or 'manual'),
             legacy_icons('💰 Total: ', icons) + (usdt(payment['amount'], fixed=True) if payment['currency'] == 'USDT' else rupiah(order['total_amount'], True)),
             '', 'Item:']
    lines.extend('- ' + product_name(item) + ' x ' + str(item['quantity']) for item in items)
    late = late_proof(order, payment)
    if late:
        lines.extend((legacy_icons('⚠️ Late Binance evidence: order sudah expired dan stok sudah dilepas.', icons),
                      'Jangan approve lewat tombol otomatis. Review/refund/fulfill manual sesuai kondisi stok.'))
    else:
        lines.extend(('', 'Cek pembayaran Binance Pay dan pastikan nominalnya sama persis, lalu setujui.'
                      if payment['method'] == 'BINANCE_PAY_ID' else
                      'Cek pembayaran QR manual dulu. Kalau pembayaran masuk dan nominal sama persis, setujui.',
                      'Kalau belum cocok, tolak agar stok balik lagi.'))
    payload = {'chat_id': str(chat), 'photo': payment['payment_proof_file_id'], 'caption': '\n'.join(lines), 'parse_mode': 'HTML'}
    if not late:
        payload['reply_markup'] = {'inline_keyboard': [[
            _button(icons, 'message.success', '✅ Setujui & Kirim Produk', 'admin:approve-payment:' + str(order['id'])),
            _button(icons, 'message.warning', '❌ Tolak Pembayaran', 'admin:reject-payment:' + str(order['id']))]]}
    return payload


def item_lines(items):
    result = ['- ' + product_name(item) + ' x ' + str(item['quantity']) for item in items[:6]]
    if len(items) > 6:
        result.append('+ ' + str(len(items) - 6) + ' produk lainnya')
    return result or ['- Produk digital x 1']


def admin_payload(order, payment, items, icons, kind, chat, dashboard, *, outcome=None,
                  fulfillment_lines=(), stocks=(), review_open=False, pakasir_customer_fee=False):
    paid = kind in (PAID, REVIEW)
    api = order.get('api_order_claim_id') is not None
    if kind == PENDING:
        heading, status = '🧾 Checkout belum bayar', 'Status: Menunggu pembayaran'
    elif kind == EXPIRED:
        heading, status = '⌛ Checkout expired belum bayar', 'Status: Expired / belum bayar'
    else:
        heading = ('🔔 Review fulfillment API' if api else '🔔 Review fulfillment') if kind == REVIEW else (
            ('🔔 Hasil order' if outcome['resolved'] else '🔔 Order perlu ditinjau') if outcome else '🔔 Pembayaran diterima')
        status = 'Status pembayaran: ' + (order['payment_status'] if api else 'Paid')
    source = 'Customer API' if paid and api else 'Telegram Bot' if order.get('telegram_id') else 'Web Shop'
    lines = [legacy_icons(heading, icons), '━━━━━━━━━━━━━━', 'Invoice: ' + escape(order['invoice_number']),
             'Pembeli: ' + escape(buyer_label(order)), 'Sumber: ' + source, status]
    if paid:
        lines.extend(escape(line) for line in (outcome['lines'] if outcome else fulfillment_lines))
        if api or kind == REVIEW:
            lines.extend(('Status order: ' + order['order_status'], 'Review operator: ' + (
                'diperlukan; buka dashboard' if review_open else 'tidak ada review terbuka')))
    lines.append('Metode: ' + escape(payment_label(payment, paid)))
    if paid:
        amount, fee = payment.get('amount', order['total_amount']), payment.get('provider_fee') or 0
        net = max(0, payment['provider_total_payment'] - fee) if payment.get('provider_total_payment') is not None else max(0, amount - (0 if pakasir_customer_fee else fee))
        money = usdt(net) if payment.get('currency') == 'USDT' else rupiah(net)
        lines.append('Earning: ' + money + (' (setelah fee)' if fee > 0 and net < amount else ''))
    else:
        lines.append('Total: ' + (usdt(payment['amount']) if payment.get('currency') == 'USDT' else rupiah(order['total_amount'])))
    lines.extend(('', 'Produk:', *item_lines(items)))
    if paid:
        lines.extend(('', legacy_icons('📦 Sisa Stok:', icons)))
        lines.extend(stocks or ['- Produk digital: ∞'])
        lines.extend(('', 'Paid at: ' + time_label(payment.get('paid_at') or order['updated_at']), '',
                      escape(outcome['action']) if outcome else 'Cek dashboard untuk proses order ini.'))
    else:
        at = (payment.get('expired_at') or order.get('expires_at') or order['updated_at']) if kind == EXPIRED else order.get('expires_at')
        if at:
            lines.extend(('', ('Expired at: ' if kind == EXPIRED else 'Batas bayar: ') + time_label(at)))
        lines.append('')
        if kind == EXPIRED:
            lines.append('Stok sudah dilepas kembali.')
        lines.append('Cek dashboard untuk follow up pembeli ini.')
    # PHP bounds these operational notices at 3900 characters. Truncate text
    # before introducing entities so a custom-emoji tag can never be cut open.
    text = '\n'.join(lines)
    plain = re.sub(r'<[^>]+>', '', text)
    if len(plain) > 3900:
        from html import unescape
        text = escape(unescape(plain)[:3890] + '...')
    return {'chat_id': str(chat), 'text': text, 'parse_mode': 'HTML',
            'reply_markup': {'inline_keyboard': [[{'text': 'Buka Dashboard', 'url': dashboard, 'style': 'primary'}]]}}


def paid_outcome(order, payment, items, fulfillments, receipts, *, complete, review_open, at):
    credentials = [row for row in receipts if metadata(row).get('contains_credentials') is not False
                   and not (row['sequence'] == 1 and row['template_version'] == LEGACY_INVOICE_TEMPLATE)]
    positive = lambda row: str(row.get('telegram_message_id') or '').isdigit() and int(row['telegram_message_id']) > 0
    acknowledged = sum(row['state'] == 'sent' and positive(row) for row in credentials)
    unknown = any(row['state'] in ('unknown', 'manual_review') for row in credentials)
    failures = [row for row in credentials if row.get('terminal_failure')]
    failure = failures[0] if failures else None
    reason = {'telegram_http_403': 'Telegram menolak akses bot ke buyer',
              'telegram_http_400': 'Telegram menolak permintaan pengiriman'}.get(
                  (failure or {}).get('last_error_code'), 'pengiriman berhenti pada kegagalan pasti; periksa bukti effect')
    resolved, intervention, lines = bool(items), review_open or unknown or bool(failures), []
    for item in items:
        fulfillment = next((row for row in fulfillments if row['order_item_id'] == item['id']), None)
        purchases = (fulfillment or {}).get('purchases', [])
        all_rejected = bool(fulfillment and fulfillment['state'] == 'review' and purchases
                            and all(row['state'] == 'rejected' and row['committed_at'] is None for row in purchases))
        supplier_resolved, purchase_lines = True, []
        for purchase in purchases:
            acquired = (purchase['committed_at'] is not None and purchase['supplier_order_id']
                        and purchase['actual_cost_micros'] is not None and purchase['observed_quantity'] is not None)
            rejected = purchase['state'] == 'rejected' and purchase['committed_at'] is None
            supplier_resolved &= bool((acquired and purchase['state'] not in ('ambiguous', 'review')) or rejected)
            intervention |= purchase['state'] in ('ambiguous', 'review') or fulfillment['state'] == 'review'
            label = (f"pembelian berhasil ({purchase['observed_quantity']}/{purchase['quantity']} unit)" +
                     ('; perlu diperiksa' if purchase['state'] in ('ambiguous', 'review') else '')) if acquired else (
                         'pembelian ditolak; tidak ada debit' if rejected else 'hasil pembelian belum dapat dipastikan; jangan beli ulang')
            purchase_lines.append(purchase['supplier_name'] + ' · ' + label)
        if fulfillment and not purchase_lines:
            supplier_resolved = False
            purchase_lines.append('belum terkonfirmasi; jangan beli ulang')
        handoff, allocated, refunded = (fulfillment or {}).get('handoff', 0), item['allocated'], (fulfillment or {}).get('refunded_quantity', 0)
        buyer_resolved = ((complete and allocated + refunded == item['quantity']) or handoff + refunded >= item['quantity']
                          or bool(failures) or (all_rejected and allocated == 0))
        if complete:
            buyer = f"{allocated}/{item['quantity']} unit terkirim"
        elif handoff > 0:
            buyer = f"{handoff}/{item['quantity']} unit diserahkan di luar bot"
        elif unknown:
            buyer = 'hasil pengiriman Telegram belum dapat dipastikan; jangan kirim ulang'
        elif failures:
            buyer = ('pengiriman sebagian; jumlah unit belum pasti' if acknowledged else 'akun belum terkirim') + '; ' + reason
        elif all_rejected and allocated == 0:
            buyer = f"0/{item['quantity']} unit terkirim; pembelian ditolak"
        else:
            buyer = 'pengiriman belum terkonfirmasi; jangan kirim ulang'
        resolved &= supplier_resolved and buyer_resolved and (not unknown or complete)
        lines.extend((f"{item['product_name']} x {item['quantity']}: {buyer}" +
                      (f'; {refunded} unit refund tercatat' if refunded > 0 else ''),
                      'Supplier: ' + '; '.join(purchase_lines) if purchase_lines else 'Supplier: tidak digunakan (stok lokal)'))
    if complete and review_open:
        resolved = False
    paid_at = payment.get('paid_at')
    deadline = paid_at is not None and paid_at + timedelta(seconds=120) <= at
    if not resolved:
        lines.append('Alasan: ' + ('waktu pembayaran belum terkonfirmasi' if paid_at is None else
                                  'belum selesai setelah 120 detik' if deadline else 'perlu pemeriksaan'))
    return {'ready': bool(resolved or intervention or deadline or paid_at is None), 'resolved': bool(resolved), 'lines': lines,
            'action': 'Order selesai. Tidak perlu proses ulang.' if resolved and complete else
                      'Periksa dashboard / Manual Delivery Review. Jangan beli ulang, refund, atau kirim ulang sebelum diperiksa.'}


class LifecycleNotices:
    def __init__(self, service):
        self.service, self.db = service, service.db
        self.receipts = ReceiptProtocol(self.db.using)
        self.cursors = {}

    def _admin(self):
        row = self.db.one('''SELECT admin_telegram_chat_id FROM public.manual_payment_settings
            WHERE is_active=true ORDER BY id LIMIT 1''') or {}
        chat = str(row.get('admin_telegram_chat_id') or '').strip()
        return chat if re.fullmatch(r'[1-9][0-9]{0,18}', chat) and self.service.recipients.permits(chat) else None

    def _load(self, order_id):
        order = self.db.one('''SELECT o.id,o.invoice_number,o.order_status,o.payment_status,o.total_amount,o.currency,
            o.api_order_claim_id,o.expires_at,o.updated_at,o.telegram_receipt_sent_at,
            o.admin_pending_order_notified_at,o.admin_paid_order_notified_at,o.admin_expired_order_notified_at,
            c.username,c.first_name,c.telegram_id FROM public.orders o
            LEFT JOIN public.customers c ON c.id=o.customer_id WHERE o.id=%s FOR UPDATE OF o''', (order_id,))
        if not order:
            raise NotEligible()
        payments = self.db.rows('''SELECT id,order_id,provider,method,currency,amount,status,
            provider_fee,provider_total_payment,paid_at,expired_at,payment_proof_file_id,payment_proof_submitted_at,
            payment_proof_admin_chat_id,payment_proof_forwarded_at FROM public.payments
            WHERE order_id=%s ORDER BY created_at,id''', (order_id,))
        for payment in payments:
            payment['payment_method_label'] = ('QRIS' if payment['provider'].strip().lower() == 'pakasir' else
                                               (payment['method'] or payment['provider'] or 'unknown').strip())
        items = self.db.rows('''SELECT i.id,i.product_id,i.product_name,i.product_icon,i.product_custom_emoji_id,i.quantity,
            (SELECT count(*) FROM public.product_stock_items s WHERE s.order_item_id=i.id) AS allocated
            FROM public.order_items i WHERE order_id=%s ORDER BY id''', (order_id,))
        return order, payments, items

    def _review_eligible(self, order, review, items):
        return bool(order['api_order_claim_id'] is not None or review['reason'] == 'local_credentials_missing' or
                    (review['reason'] == 'late_payment' and any(item['quantity'] > 0 for item in items)
                     and not any(item['allocated'] for item in items) and not self.db.one(
                         'SELECT id FROM public.supplier_fulfillments WHERE order_id=%s LIMIT 1', (order['id'],))))

    def _eligible(self, kind, order, payment=None, review=None, items=()):
        if kind == MANUAL:
            return bool(payment and payment['provider'] == 'manual' and
                        (payment['status'] == 'pending' or late_proof(order, payment)) and
                        payment['payment_proof_file_id'] and payment['payment_proof_submitted_at'] and
                        payment['payment_proof_forwarded_at'] is None)
        if kind == REVIEW:
            return self._review_eligible(order, review, items)
        return ((order['order_status'], order['payment_status']) == ('pending_payment', 'pending') if kind == PENDING else
                (order['payment_status'] == 'paid' and order['order_status'] in ('processing', 'completed')) if kind == PAID else
                (order['order_status'], order['payment_status']) == ('expired', 'expired'))

    def _fulfillments(self, order_id):
        rows = self.db.rows('''SELECT f.id,f.order_item_id,f.state,f.quantity,f.refunded_quantity,
            (SELECT COALESCE(sum(quantity),0) FROM public.supplier_manual_handoffs h
              WHERE h.supplier_fulfillment_id=f.id) AS handoff
            FROM public.supplier_fulfillments f WHERE f.order_id=%s ORDER BY f.id''', (order_id,))
        purchases = self.db.rows('''SELECT p.supplier_fulfillment_id,p.state,p.committed_at,p.supplier_order_id,
            p.actual_cost_micros,p.observed_quantity,p.quantity,s.name AS supplier_name
            FROM public.supplier_purchases p JOIN public.suppliers s ON s.id=p.supplier_id
            JOIN public.supplier_fulfillments f ON f.id=p.supplier_fulfillment_id
            WHERE f.order_id=%s ORDER BY p.id''', (order_id,))
        for row in rows:
            row['purchases'] = [p for p in purchases if p['supplier_fulfillment_id'] == row['id']]
        return rows

    def _outcome(self, order, payment, items, fulfillments, review_open):
        rows = self.db.rows('''SELECT * FROM public.telegram_outbound_effects
            WHERE source_type='App\\Models\\Order' AND source_id=%s AND effect_type=%s''', (str(order['id']), EFFECT_TYPE))
        for row in rows:
            row['terminal_failure'] = self.receipts.is_terminal_failure(row)
        complete = any(self.receipts.is_receipt_group_complete(key) and all(
            row['state'] != 'sent' or (str(row.get('telegram_message_id') or '').isdigit() and int(row['telegram_message_id']) > 0)
            for row in rows if row['effect_group_key'] == key) for key in {row['effect_group_key'] for row in rows})
        return paid_outcome(order, payment, items, fulfillments, rows, complete=complete, review_open=review_open, at=_now())

    def _render(self, kind, order, payments, items, chat):
        preferred = ('paid',) if kind in (PAID, REVIEW) else ('paid', 'pending', 'expired')
        payment = next((p for status in preferred for p in payments if p['status'] == status), payments[0] if payments else {})
        icons = self.db.rows('SELECT key,source_emoji,fallback_emoji,custom_emoji_id FROM public.telegram_ui_icons ORDER BY sort_order,label,id')
        if kind == MANUAL:
            return manual_payload(order, payment, items, icons, chat), {}
        base = getattr(settings, 'CANDIDATE_LEGACY_APP_URL', '').rstrip('/')
        parsed = urlsplit(base)
        if parsed.scheme not in ('http', 'https') or not parsed.hostname or parsed.username or parsed.password:
            raise NotEligible()
        outcome, extras, stocks, lines, review_open = None, {}, [], [], False
        if kind in (PAID, REVIEW):
            fulfillments = self._fulfillments(order['id'])
            review_open = self.db.one("SELECT id FROM public.manual_fulfillment_reviews WHERE order_id=%s AND state IN ('pending','refund_uncertain') LIMIT 1", (order['id'],)) is not None
            if order['api_order_claim_id'] is None and order.get('telegram_id'):
                outcome = self._outcome(order, payment, items, fulfillments, review_open)
                if kind == PAID and not outcome['ready']:
                    return None, {}
                extras['paid_outcome'] = outcome
            lines = self._fulfillment_lines(order, items, fulfillments)
            products = self.db.rows('''SELECT p.id,p.stock,p.reserved_stock,p.supplier_available_stock,p.supplier_available_until,
                EXISTS(SELECT 1 FROM public.product_stock_items s WHERE s.product_id=p.id AND s.supplier_purchase_id IS NULL) AS managed,
                (SELECT count(*) FROM public.product_stock_items s WHERE s.product_id=p.id AND s.supplier_purchase_id IS NULL
                  AND s.order_item_id IS NULL AND s.retired_at IS NULL) AS available
                FROM public.products p WHERE id=ANY(%s)''', ([item['product_id'] for item in items if item['product_id']],)) if items else []
            for item in items:
                product = next((p for p in products if p['id'] == item['product_id']), None)
                value = '(Produk dihapus)'
                if product:
                    local = max(0, product['stock'] - product['reserved_stock'])
                    local = min(local, product['available']) if product['managed'] else local
                    external = max(0, product['supplier_available_stock']) if product['supplier_available_until'] and product['supplier_available_until'] > _now() else 0
                    value = str(local + external)
                stocks.append('- ' + product_name(item) + ': ' + value)
        customer_fee = bool(payment.get('provider') == 'pakasir' and
                            (payment.get('method') == 'api_qris') and getattr(settings, 'CANDIDATE_PAKASIR_FEE_PAYER', 'customer') == 'customer')
        return admin_payload(order, payment, items, icons, kind, chat, base + '/admin/orders/' + str(order['id']),
                             outcome=outcome, fulfillment_lines=lines, stocks=stocks, review_open=review_open,
                             pakasir_customer_fee=customer_fee), extras

    def _api_fulfillment_eligible(self, order):
        if (order['order_status'] not in ('processing', 'completed')
                or order['payment_status'] != 'paid'):
            return False
        payment = self.db.one('''SELECT status,paid_at FROM public.payments
            WHERE order_id=%s ORDER BY created_at DESC,id DESC LIMIT 1''', (order['id'],))
        if not payment or payment['status'] != 'paid' or payment['paid_at'] is None:
            return False
        items = self.db.rows('''SELECT i.quantity,
            (SELECT count(*) FROM public.product_stock_items s WHERE s.order_item_id=i.id
              AND s.product_id=i.product_id AND s.retired_at IS NULL) AS allocated,
            EXISTS(SELECT 1 FROM public.inventory_movements m WHERE m.order_id=i.order_id
              AND m.product_id=i.product_id AND m.type='sale' AND m.quantity=-i.quantity) AS sale_proven
            FROM public.order_items i WHERE i.order_id=%s ORDER BY i.id''', (order['id'],))
        if not items or any(item['quantity'] < 1 or item['allocated'] != item['quantity']
                            or not item['sale_proven'] for item in items):
            return False
        return not any((
            self.db.one("SELECT id FROM public.inventory_movements WHERE order_id=%s AND type='release' LIMIT 1",
                        (order['id'],)),
            self.db.one("SELECT id FROM public.supplier_fulfillments WHERE order_id=%s AND state<>'delivered' LIMIT 1",
                        (order['id'],)),
            self.db.one("SELECT id FROM public.manual_fulfillment_reviews WHERE order_id=%s AND state IN ('pending','refund_uncertain') LIMIT 1",
                        (order['id'],)),
        ))

    def _fulfillment_lines(self, order, items, fulfillments):
        def delivery(state):
            return {'completed': 'Selesai', 'delivered': 'Selesai', 'review': 'Perlu review operator', 'cancelled': 'Dibatalkan',
                    'processing': 'Sedang diproses', 'pending': 'Sedang diproses', 'purchasing': 'Sedang diproses',
                    'reserved': 'Sedang diproses', 'awaiting_delivery': 'Sedang diproses'}.get(state, 'Belum dipastikan')
        api = order['api_order_claim_id'] is not None
        authorized = api and self._api_fulfillment_eligible(order)
        if not fulfillments:
            if api:
                state = 'Fulfillment API selesai · hasil tersedia melalui API' if authorized else 'Belum selesai; periksa dashboard'
            elif order['order_status'] == 'completed':
                state = 'Receipt Telegram tercatat terkirim' if order['telegram_receipt_sent_at'] else 'Fulfillment selesai · receipt Telegram belum terkonfirmasi; periksa Manual Delivery Review'
            else:
                state = delivery(order['order_status'])
            return ['Pengiriman pelanggan: ' + state, 'Pembelian supplier: tidak digunakan']
        deliveries, purchases = [], []
        for fulfillment in fulfillments:
            item = next((item for item in items if item['id'] == fulfillment['order_item_id']), {})
            state = delivery(fulfillment['state'])
            if fulfillment['state'] == 'delivered':
                state = ('Selesai · handoff luar bot dicatat' if fulfillment['handoff'] else
                         'Fulfillment API selesai · hasil tersedia melalui API' if api and authorized else
                         'Belum selesai; periksa dashboard' if api else
                         'Receipt Telegram tercatat terkirim' if order['telegram_receipt_sent_at'] else
                         'Supplier fulfillment selesai · receipt Telegram belum terkonfirmasi; periksa Manual Delivery Review')
            deliveries.append((item.get('product_name') or 'Produk').strip() + ': ' + state)
            for purchase in fulfillment['purchases']:
                label = {'delivered': 'pembelian berhasil', 'settled': 'pembelian berhasil', 'rejected': 'pembelian ditolak',
                         'cancelled': 'dibatalkan', 'ambiguous': 'belum dapat dipastikan; periksa dashboard, jangan beli ulang',
                         'review': 'belum dapat dipastikan; periksa dashboard, jangan beli ulang'}.get(purchase['state'], 'sedang diproses')
                purchases.append(purchase['supplier_name'] + ' · ' + label)
        return ['Pengiriman pelanggan: ' + '; '.join(deliveries[:3]),
                'Supplier: ' + ('; '.join(purchases[:3]) if purchases else 'belum ada pembelian')]

    def _materialize_source(self, kind, source, chat, *, existing=None):
        with transaction.atomic(using=self.db.using):
            review, proof = None, None
            if kind == REVIEW:
                review = self.db.one('SELECT id,order_id,reason FROM public.manual_fulfillment_reviews WHERE id=%s', (source,))
                if not review:
                    return 0
                order_id = review['order_id']
            elif kind == MANUAL:
                proof = self.db.one('SELECT order_id FROM public.payments WHERE id=%s', (source,))
                if not proof:
                    return 0
                order_id = proof['order_id']
            else:
                order_id = source
            order, payments, items = self._load(order_id)
            payment = next((p for p in payments if str(p['id']) == str(source)), None) if kind == MANUAL else None
            if not self._eligible(kind, order, payment, review, items):
                return 0
            if kind == MANUAL:
                chat = str(payment['payment_proof_admin_chat_id'] or '')
                if not self.service.recipients.permits(chat):
                    return 0
                payments = [payment]
            payload, extra = self._render(kind, order, payments, items, chat)
            if payload is None:
                return 0
            key = (f'payment:{source}:{MANUAL}:v1' if kind == MANUAL else f'order-review:{source}:owner:v1'
                   if kind == REVIEW else f'order:{source}:{kind}:v1')
            legacy = {'sent_at': order.get(MARKERS.get(kind, ''))}
            record_snapshot(self.service, key=existing['effect_key'] if existing else key,
                            kind=kind, model=MODELS[kind], source=source, chat=chat, template=TEMPLATES[kind],
                            payload=payload, legacy=legacy, extra=extra)
            return 1

    def materialize(self, limit=25):
        if not self.service.recipients.telegram:
            return 0
        selected = set(self.service.protocol.effect_types) & MODELS.keys()
        chat, count = self._admin(), 0
        if chat:
            predicates = {PENDING: "o.order_status='pending_payment' AND o.payment_status='pending'",
                          PAID: "o.order_status IN ('processing','completed') AND o.payment_status='paid'",
                          EXPIRED: "o.order_status='expired' AND o.payment_status='expired'"}
            for kind, predicate in predicates.items():
                if kind not in selected:
                    continue
                cursor = self.cursors.get(kind)
                rows = self.db.rows(f'''SELECT o.id FROM public.orders o WHERE {predicate}
                    AND (%s::uuid IS NULL OR o.id>%s::uuid) AND NOT EXISTS (
                      SELECT 1 FROM public.telegram_outbound_effects e WHERE e.source_type='App\\Models\\Order'
                        AND e.source_id=o.id::text AND e.effect_type=%s) ORDER BY o.id LIMIT %s''', (cursor, cursor, kind, limit))
                for row in rows:
                    count += self._materialize_source(kind, row['id'], chat)
                self.cursors[kind] = str(rows[-1]['id']) if rows else None
            reviews = self.db.rows('''SELECT r.id FROM public.manual_fulfillment_reviews r JOIN public.orders o ON o.id=r.order_id
                WHERE (o.api_order_claim_id IS NOT NULL OR r.reason IN ('local_credentials_missing','late_payment'))
                  AND NOT EXISTS(SELECT 1 FROM public.telegram_outbound_effects e WHERE e.source_type='App\\Models\\ManualFulfillmentReview'
                    AND e.source_id=r.id::text AND e.effect_type=%s) AND r.id>%s ORDER BY r.id LIMIT %s''', (REVIEW, self.cursors.get(REVIEW, 0), limit))
            for row in reviews if REVIEW in selected else ():
                count += self._materialize_source(REVIEW, row['id'], chat)
            self.cursors[REVIEW] = reviews[-1]['id'] if reviews else 0
        proofs = self.db.rows('''SELECT p.id FROM public.payments p WHERE p.provider='manual'
            AND p.payment_proof_submitted_at IS NOT NULL AND p.payment_proof_forwarded_at IS NULL
            AND p.payment_proof_admin_chat_id=ANY(%s) AND NOT EXISTS(
              SELECT 1 FROM public.telegram_outbound_effects e WHERE e.source_type='App\\Models\\Payment'
                AND e.source_id=p.id::text AND e.effect_type=%s) ORDER BY p.id LIMIT %s''',
            (list(self.service.recipients.telegram), MANUAL, limit))
        for row in proofs if MANUAL in selected else ():
            count += self._materialize_source(MANUAL, row['id'], None)
        existing = self.db.rows('''SELECT * FROM public.telegram_outbound_effects
            WHERE effect_type=ANY(%s) AND telegram_chat_id=ANY(%s) AND state IN ('pending','failed')
              AND NOT (COALESCE(outcome_metadata,'{}'::json)::jsonb ? 'notification_payload') ORDER BY id LIMIT %s''',
            (list(selected), list(self.service.recipients.telegram), limit))
        for effect in existing:
            count += self._materialize_source(effect['effect_type'], effect['source_id'], effect['telegram_chat_id'], existing=effect)
        if PAID in selected:
            self._outcome_updates(limit)
        return count

    def _outcome_updates(self, limit):
        rows = self.db.rows('''SELECT * FROM public.telegram_outbound_effects e WHERE effect_type=%s
            AND telegram_chat_id=ANY(%s) AND state IN ('sent','unknown','manual_review')
            AND outcome_metadata->'paid_outcome'->>'resolved'='false' AND NOT EXISTS(
              SELECT 1 FROM public.telegram_outbound_effects n WHERE n.supersedes_effect_id=e.id)
            ORDER BY e.id LIMIT %s''', (PAID, list(self.service.recipients.telegram), limit))
        for effect in rows:
            with transaction.atomic(using=self.db.using):
                order, payments, items = self._load(effect['source_id'])
                if not self._eligible(PAID, order):
                    continue
                payload, extra = self._render(PAID, order, payments, items, effect['telegram_chat_id'])
                if payload and extra.get('paid_outcome', {}).get('resolved'):
                    record_snapshot(self.service, key=effect['effect_key'] + ':result', kind=PAID, model='Order',
                                    source=effect['source_id'], chat=effect['telegram_chat_id'], template=effect['template_version'],
                                    payload=payload, sequence=effect['sequence'] + 1, supersedes=effect['id'], extra=extra)

    def ready(self, effect):
        # Eligibility failures still pass through claim/fenced finalization.
        # An eligible paid notice waits without consuming a transport attempt.
        if effect['effect_type'] != PAID or metadata(effect).get('notification_payload'):
            return True
        row = self.db.one('SELECT order_status,payment_status FROM public.orders WHERE id=%s',
                          (effect['source_id'],))
        return row is None or not self._eligible(PAID, row)

    def payload(self, effect):
        kind = effect['effect_type']
        if (kind not in MODELS or effect['source_type'] != 'App\\Models\\' + MODELS[kind]
                or effect['target_type'] != 'admin' or not self.service.recipients.permits(effect['telegram_chat_id'])):
            raise NotEligible()
        with transaction.atomic(using=self.db.using):
            review, payment = None, None
            if kind == REVIEW:
                review = self.db.one('SELECT id,order_id,reason FROM public.manual_fulfillment_reviews WHERE id=%s', (effect['source_id'],))
                order_id = review['order_id'] if review else None
            elif kind == MANUAL:
                row = self.db.one('SELECT order_id FROM public.payments WHERE id=%s', (effect['source_id'],))
                order_id = row['order_id'] if row else None
            else:
                order_id = effect['source_id']
            order, payments, items = self._load(order_id)
            if kind == MANUAL:
                payment = next((p for p in payments if str(p['id']) == effect['source_id']), None)
                if not payment or str(payment['payment_proof_admin_chat_id']) != effect['telegram_chat_id']:
                    raise NotEligible()
            if not self._eligible(kind, order, payment, review, items):
                raise NotEligible()
        return frozen_payload(effect)

    def project(self, effect):
        kind = effect['effect_type']
        if kind not in MODELS or effect['source_type'] != 'App\\Models\\' + MODELS[kind] or not latest_sent(self.db, effect):
            return 0
        if kind == MANUAL:
            return self.db.execute('''UPDATE public.payments SET payment_proof_forwarded_at=%s,
                payment_proof_admin_chat_id=%s,updated_at=%s WHERE id=%s AND payment_proof_forwarded_at IS NULL''',
                (effect['sent_at'], effect['telegram_chat_id'], _now(), effect['source_id']))
        if kind not in MARKERS:
            return 0
        column = MARKERS[kind]
        extra, values, condition = '', [], f'{column} IS DISTINCT FROM %s'
        comparison = [effect['sent_at']]
        if kind == PENDING:
            extra = ',admin_pending_order_notification_chat_id=%s,admin_pending_order_notification_message_id=%s'
            values = [effect['telegram_chat_id'], effect['telegram_message_id']]
            condition += ' OR admin_pending_order_notification_chat_id IS DISTINCT FROM %s OR admin_pending_order_notification_message_id IS DISTINCT FROM %s'
            comparison += values
        return self.db.execute(f'''UPDATE public.orders SET {column}=%s{extra},updated_at=%s
            WHERE id=%s AND ({condition})''', (effect['sent_at'], *values, _now(), effect['source_id'], *comparison))
