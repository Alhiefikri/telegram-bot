"""Retained manual proof attachment and PHP operator decision presentation."""
from html import escape

from .html import bounded_html
from .policy import NotEligible


def payload(db, effect):
    row = db.one('''SELECT p.id,p.order_id,p.provider,p.method,p.status,p.amount,p.currency,
        p.payment_proof_file_id,p.payment_proof_submitted_at,p.payment_proof_forwarded_at,p.payment_proof_admin_chat_id,
        o.invoice_number,o.order_status,o.total_amount,
        c.username,c.telegram_id FROM public.payments p JOIN public.orders o ON o.id=p.order_id
        JOIN public.customers c ON c.id=o.customer_id WHERE p.id=%s''', (effect['source_id'],))
    if (not row or row['provider'] != 'manual' or not row['payment_proof_submitted_at'] or not row['payment_proof_file_id']
            or row['payment_proof_forwarded_at'] is not None or effect['target_type'] != 'admin'
            or str(row['payment_proof_admin_chat_id']) != effect['telegram_chat_id']):
        raise NotEligible()
    late = row['method'] == 'BINANCE_PAY_ID' and row['status'] in ('expired', 'failed') and row['order_status'] in ('expired', 'cancelled')
    if row['status'] != 'pending' and not late:
        raise NotEligible()
    icons = db.rows('SELECT source_emoji,fallback_emoji,custom_emoji_id FROM public.telegram_ui_icons')
    def static(value):
        # One regex pass prevents a replacement glyph being resolved again.
        import re
        slots = {icon['source_emoji']: icon for icon in icons if icon['source_emoji']}
        if not slots:
            return escape(value)
        parts, cursor = [], 0
        for match in re.finditer('|'.join(re.escape(source) for source in sorted(slots, key=len, reverse=True)), value):
            icon = slots[match.group()]
            glyph = escape(icon['fallback_emoji'] or '')
            if icon['custom_emoji_id']:
                glyph = '<tg-emoji emoji-id="' + escape(str(icon['custom_emoji_id'])) + '">' + glyph + '</tg-emoji>'
            parts.extend((escape(value[cursor:match.start()]), glyph))
            cursor = match.end()
        parts.append(escape(value[cursor:]))
        return ''.join(parts)
    if row['currency'] == 'USDT':
        whole, fraction = divmod(row['amount'], 1_000_000)
        total = str(whole) + f'.{fraction:06d} USDT'
    else:
        total = 'Rp ' + f"{row['total_amount']:,}".replace(',', '.')
    method = {'QR_MANUAL': 'QR Manual', 'BINANCE_PAY_ID': 'Binance Pay'}.get(row['method'], row['method'] or 'manual')
    lines = [static('📸 Bukti pembayaran masuk'), '━━━━━━━━━━━━━━', static('🧾 Invoice: ') + escape(row['invoice_number']),
             static('👤 Customer: ') + '@' + escape(row['username'] or '-') + ' (' + escape(str(row['telegram_id'] or '')) + ')',
             static('💳 Metode: ') + escape(method), static('💰 Total: ') + total, '', 'Item:']
    for item in db.rows('''SELECT product_name,product_icon,product_custom_emoji_id,quantity
        FROM public.order_items WHERE order_id=%s ORDER BY id''', (row['order_id'],)):
        glyph = escape(item['product_icon'] or '🛍️')
        if item['product_custom_emoji_id']:
            glyph = '<tg-emoji emoji-id="' + escape(str(item['product_custom_emoji_id'])) + '">' + glyph + '</tg-emoji>'
        lines.append('- ' + glyph + ' ' + escape(item['product_name']) + ' x ' + str(item['quantity']))
    if late:
        lines.extend((static('⚠️ Late Binance evidence: order sudah expired dan stok sudah dilepas.'),
                      'Jangan approve lewat tombol otomatis. Review/refund/fulfill manual sesuai kondisi stok.'))
        markup = {}
    else:
        lines.extend(('', 'Cek pembayaran Binance Pay dan pastikan nominalnya sama persis, lalu setujui.' if row['method'] == 'BINANCE_PAY_ID'
                      else 'Cek pembayaran QR manual dulu. Kalau pembayaran masuk dan nominal sama persis, setujui.',
                      'Kalau belum cocok, tolak agar stok balik lagi.'))
        markup = {'inline_keyboard': [[{'text': '✅ Setujui & Kirim Produk', 'style': 'primary', 'callback_data': 'admin:approve-payment:' + str(row['order_id'])},
                                      {'text': '❌ Tolak Pembayaran', 'style': 'primary', 'callback_data': 'admin:reject-payment:' + str(row['order_id'])}]]}
    return {'chat_id': effect['telegram_chat_id'], 'photo': row['payment_proof_file_id'],
            'caption': bounded_html('\n'.join(lines), 1024), 'parse_mode': 'HTML', 'reply_markup': markup}
