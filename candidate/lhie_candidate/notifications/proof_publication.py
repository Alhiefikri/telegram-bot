"""Store Channel publication over the shared ledger, with public-only snapshots."""
import copy
from datetime import datetime
from html import escape, unescape
import json
import re
from zoneinfo import ZoneInfo

from django.db import transaction

from ..auth.bot_identity import TelegramBotIdentity
from ..checkout.errors import CheckoutError
from ..navigation.repository import _PRODUCT_SELECT
from ..receipt.invoice import render_invoice, _money
from ..receipt.protocol import _now
from . import bulk_content
from .policy import NotEligible, PROOF
from .proof import SalesProofEligibility

PHOTO_TEMPLATE = 'sales-proof-post-v2'
LEGACY_TEMPLATE = 'sales-proof-post-v1'
MODELS = {PROOF: 'Order'}
LIMITS = {PROOF: 3}
LOW_STOCK_THRESHOLD = 5


def _clean(value):
    return re.sub(r'[\x00-\x1f\x7f]+', ' ', str(value or '')).strip()


def _legacy(text, icons):
    by_source = {row['source_emoji']: row for row in icons if row.get('source_emoji')}
    if not by_source:
        return escape(text)
    pattern = re.compile('|'.join(re.escape(s) for s in sorted(by_source, key=len, reverse=True)))
    parts, offset = [], 0
    for match in pattern.finditer(text):
        row = by_source[match.group()]
        parts.extend((escape(text[offset:match.start()]), bulk_content.icon(row['fallback_emoji'], row.get('custom_emoji_id'))))
        offset = match.end()
    return ''.join(parts) + escape(text[offset:])


def _slot(key, fallback, icons):
    row = next((row for row in icons if row['key'] == key), None)
    return bulk_content.icon(row['fallback_emoji'], row.get('custom_emoji_id')) if row else fallback


def _length(text):
    return len(unescape(re.sub(r'<[^>]*>', '', text)).encode('utf-16-le')) // 2


def _truncate(text, budget):
    # Custom emoji entities stay whole; escaped characters are counted as their
    # rendered character rather than their HTML spelling.
    result, used = [], 0
    for token in re.findall(r'<tg-emoji\b[^>]*>.*?</tg-emoji>|&[^;\s]+;|[^<&]|[<&]', text, re.S):
        size = _length(token)
        if used + size > budget:
            break
        result.append(token)
        used += size
    return ''.join(result).rstrip()


def _bounded(lines):
    caption = '\n'.join(lines)
    if _length(caption) <= 1024:
        return caption
    tail = '\n'.join(lines[-4:])
    head = _truncate('\n'.join(lines[:-4]), max(1, 1024 - _length(tail) - 3))
    return head + '…\n\n' + tail


def _paid_at(snapshot):
    paid = snapshot['paid_at']
    if isinstance(paid, str):
        paid = datetime.fromisoformat(paid)
    if not isinstance(paid, datetime) or paid.utcoffset() is None:
        raise NotEligible()
    return paid.astimezone(ZoneInfo('Asia/Jakarta')).strftime('%d %B %Y, %H:%M WIB')


def format_caption(snapshot, number, remaining=None, icons=()):
    items = snapshot['items']
    quantity = sum(max(0, item['quantity']) for item in items)
    lines = [_legacy('🎉 API ORDER FULFILLED' if snapshot.get('api') else '🎉 ORDER PAYMENT CONFIRMED', icons), '',
             _slot('message.users', '👥', icons) + ' Buyer: ' + escape(snapshot['buyer_name']),
             _slot('message.ticket', '🎫', icons) + ' Order ID: ' + escape(snapshot['invoice_number'])]
    if snapshot.get('api'):
        lines.append('Origin: API')
    def label(item):
        name = _clean(item['product_name']) or 'Digital product'
        if len(name) > 72:
            name = name[:69] + '…'
        if 'product_icon' in item or 'product_custom_emoji_id' in item:
            name = bulk_content.icon(item.get('product_icon') or '📦', item.get('product_custom_emoji_id')) + ' ' + escape(name)
        else:
            name = escape(name)
        return name + ' × ' + str(max(0, item['quantity']))
    detail = _slot('message.details', '📋', icons)
    if not items:
        lines.append(detail + ' Item: Digital product')
    elif len(items) == 1:
        lines.append(detail + ' Item: ' + label(items[0]))
    else:
        lines.extend([detail + ' Items:'] + ['• ' + label(item) for item in items[:6]])
        if len(items) > 6:
            lines.append('• +' + str(len(items) - 6) + ' other items')
    if remaining is not None and remaining > 0:
        lines.append(_legacy('🔥 Only ', icons) + str(remaining) + ' left in stock')
    currency = snapshot['currency']
    subtotal = snapshot.get('payment_subtotal')
    if subtotal is None:
        subtotal = sum(item['subtotal'] for item in items)
    lines.extend((_legacy('💳 Paid via: ', icons) + escape(snapshot['payment_method']),
                  _slot('message.deadline', '⏰', icons) + ' Completed: ' + _paid_at(snapshot),
                  _slot('message.quantity', '🔢', icons) + ' Quantity: ' + str(quantity),
                  _legacy('💵 Subtotal: ', icons) + _money(subtotal, currency)))
    if snapshot['provider_fee'] > 0:
        lines.append(_legacy('💸 Fee: ', icons) + _money(snapshot['provider_fee'], currency))
    lines.extend((_slot('message.cash', '💰', icons) + ' Amount paid: ' + _money(snapshot['total_paid'], currency),
                  _legacy('🏆 Successful orders: #', icons) + str(number)))
    minimums = sorted({item['quantity_price_tier_minimum'] for item in items if item.get('quantity_price_tier_minimum')})
    if minimums:
        lines.append(_legacy('🏷 Bulk pricing applied · ', icons) + str(minimums[0]) + '+ units' if len(items) == len(minimums) == 1 else _legacy('🏷 Bulk pricing applied', icons))
    else:
        lines.append('')
    lines.extend(('━━━━━━━━━━━━━━', _legacy('✨ Thank you for choosing Lhie Store.', icons)))
    return _bounded(lines)


def format_topup(snapshot, icons=()):
    return _bounded([_legacy('🎉 BALANCE TOP-UP CONFIRMED', icons), '',
        _slot('message.users', '👥', icons) + ' Buyer: ' + escape(snapshot['buyer_name']),
        _slot('message.ticket', '🎫', icons) + ' Top-up ID: ' + escape(snapshot['invoice_number']),
        _legacy('💳 Paid via: ', icons) + escape(snapshot['payment_method']),
        _slot('message.deadline', '⏰', icons) + ' Completed: ' + _paid_at(snapshot),
        _slot('message.cash', '💰', icons) + ' Amount paid: ' + _money(snapshot['total_paid'], snapshot['currency']),
        '', '━━━━━━━━━━━━━━', _legacy('✨ Thank you for topping up at Lhie Store.', icons)])


def shopping_markup(icons, url):
    # Inline keyboard labels use Bot API's icon_custom_emoji_id field, never
    # Telegram HTML custom-emoji markup.
    row = next((row for row in icons if row.get('key') == 'message.cart'), None)
    fallback = (row.get('fallback_emoji') or '🛒') if row else '🛒'
    identifier = str(row.get('custom_emoji_id') or '').strip() if row else ''
    label = 'Shop Now'
    unicode_fallback = fallback + ' ' + label
    has_custom_id = identifier.isascii() and identifier.isdigit()
    button = {'text': label if has_custom_id else unicode_fallback,
              'url': url, 'style': 'primary'}
    if has_custom_id:
        button['icon_custom_emoji_id'] = identifier
        button['_unicode_fallback'] = unicode_fallback
    return {'inline_keyboard': [[button]]}


def shopping_url(bot_username):
    if not isinstance(bot_username, str) or re.fullmatch(r'[A-Za-z][A-Za-z0-9_]{4,31}', bot_username) is None:
        raise NotEligible()
    return 'https://t.me/' + bot_username + '?start=start'


def _metadata(effect):
    value = effect.get('outcome_metadata') or {}
    return json.loads(value) if isinstance(value, str) else value


class SalesProofPublication:
    def __init__(self, service, *, bot_username=None):
        self.service, self.db = service, service.db
        self.eligibility = SalesProofEligibility(using=self.db.using)
        self.bot_username = bot_username if bot_username is not None else getattr(service, 'bot_username', None)
        self._cursor = None

    def _username(self):
        # getMe is an external boundary and must precede source transactions.
        value = self.bot_username if self.bot_username is not None else TelegramBotIdentity().username()
        if not isinstance(value, str) or re.fullmatch(r'[A-Za-z][A-Za-z0-9_]{4,31}', value) is None:
            raise NotEligible()
        return value

    def _channel(self):
        row = self.db.one('''SELECT sales_proof_enabled,telegram_chat_id,channel_username,last_test_success
            FROM public.store_channel_settings WHERE singleton_key='store_channel' ''')
        if not row or not row['sales_proof_enabled'] or row['last_test_success'] is not True:
            raise NotEligible()
        chat = str(row['telegram_chat_id'] or row['channel_username'] or '').strip()
        if not self.service.recipients.permits(chat):
            raise NotEligible()
        return chat

    def _number(self):
        self.db.require_transaction()
        self.db.execute("SELECT pg_advisory_xact_lock(hashtextextended('sales-proof-posts:published-count',0))")
        row = self.db.one('''SELECT GREATEST(
            (SELECT COUNT(*) FROM public.sales_proof_posts WHERE status='sent'),
            COALESCE((SELECT MAX((outcome_metadata::jsonb->'proof_publication'->>'number')::bigint)
             FROM public.telegram_outbound_effects WHERE effect_type IN (%s,'sales_proof.fake')
              AND outcome_metadata::jsonb->'proof_publication'->>'number' ~ '^[0-9]+$'),0)) AS count''', (PROOF,))
        return int(row['count']) + 1

    def _stock(self, product_id):
        if product_id is None:
            return None
        row = self.db.one(_PRODUCT_SELECT + ' AND p.id=%s', (product_id,))
        remaining = int(row['available_stock']) if row else 0
        return remaining if 0 < remaining <= LOW_STOCK_THRESHOLD else None

    def _freeze(self, effect, snapshot, username, product_ids, legacy_buyer=None):
        if _metadata(effect).get('proof_publication'):
            return effect
        number = self._number()
        icons = bulk_content.icons(self.db)
        url = bulk_content.product_url(product_ids[0], username) if product_ids and product_ids[0] else 'https://t.me/' + username + '?start=start'
        remaining = self._stock(product_ids[0]) if len(product_ids) == 1 else None
        if effect['template_version'] == LEGACY_TEMPLATE:
            items = snapshot['items']
            first = items[0]
            name = bulk_content.icon(first.get('product_icon') or '📦', first.get('product_custom_emoji_id')) + ' ' + escape(first['product_name'])
            if len(items) > 1:
                name += ' + ' + str(len(items) - 1) + ' other products'
            caption = (_legacy('🎉 SUCCESSFUL PURCHASE 🎉\n\n', icons)
                + _slot('message.users', '👥', icons) + ' Buyer: ' + escape(legacy_buyer or snapshot['buyer_name']) + '\n'
                + _slot('message.details', '📋', icons) + ' Product: ' + name + '\n'
                + _slot('message.quantity', '🔢', icons) + ' Quantity: ' + str(sum(i['quantity'] for i in items)) + 'x\n'
                + _legacy('🏆 Successful orders: #', icons) + str(number) + '\n\n'
                + _legacy('✨ Thank you for shopping with us!\n', icons)
                + 'Want to order too? Tap the button below ' + _slot('message.next', '➡️', icons))
        elif effect['template_version'] == PHOTO_TEMPLATE:
            caption = format_caption(snapshot, number, remaining, icons)
        else:
            raise NotEligible()
        public = {**snapshot, 'paid_at': snapshot['paid_at'].isoformat()}
        frozen = {'snapshot': public, 'caption': caption, 'reply_markup': shopping_markup(icons, url), 'number': number}
        self.db.execute('''UPDATE public.telegram_outbound_effects SET outcome_metadata=
            (COALESCE(outcome_metadata::jsonb,'{}'::jsonb) || jsonb_build_object('proof_publication',%s::jsonb))::json,
            telegram_method=%s,updated_at=%s WHERE id=%s AND state IN ('pending','failed')''',
            (json.dumps(frozen, ensure_ascii=False), 'sendPhoto' if effect['template_version'] == PHOTO_TEMPLATE else 'sendMessage', _now(), effect['id']))
        return self.db.one('SELECT * FROM public.telegram_outbound_effects WHERE id=%s', (effect['id'],))

    def materialize_order(self, order_id, username):
        with transaction.atomic(using=self.db.using):
            if not self.db.one('SELECT id FROM public.orders WHERE id=%s FOR UPDATE', (order_id,)):
                raise NotEligible()
            chat = self._channel()
            snapshot = self.eligibility.load(order_id)
            items = self.db.rows('SELECT product_id FROM public.order_items WHERE order_id=%s ORDER BY id', (order_id,))
            self.db.execute('''INSERT INTO public.sales_proof_posts (order_id,status,attempts,created_at,updated_at)
                VALUES (%s,'pending',0,%s,%s) ON CONFLICT (order_id) DO NOTHING''', (order_id, _now(), _now()))
            post = self.db.one('SELECT * FROM public.sales_proof_posts WHERE order_id=%s FOR UPDATE', (order_id,))
            effect = self.db.one('''SELECT * FROM public.telegram_outbound_effects WHERE effect_type=%s
                AND source_type='App\\Models\\Order' AND source_id=%s ORDER BY created_at DESC,id DESC LIMIT 1 FOR UPDATE''', (PROOF, str(order_id)))
            if not effect:
                legacy = {'attempts': post['attempts'], 'sent_at': post['sent_at'],
                          'send_started_at': post['updated_at'] if post['status'] in ('sending','unknown','manual_review') else None,
                          'failed_at': post['updated_at'] if post['status'] == 'failed' else None}
                effect = self.service.record(key=f'order:{order_id}:{PROOF}:v1', kind=PROOF, model='Order',
                    source=order_id, chat=chat, target='store_channel', locale='en', template=PHOTO_TEMPLATE,
                    method='sendPhoto', legacy=legacy)
                if post['telegram_message_id'] and effect['state'] == 'sent':
                    self.db.execute('UPDATE public.telegram_outbound_effects SET telegram_message_id=%s WHERE id=%s', (post['telegram_message_id'], effect['id']))
                    effect['telegram_message_id'] = post['telegram_message_id']
            if effect['state'] in ('pending','failed'):
                if effect['telegram_chat_id'] != chat:
                    raise NotEligible()
                buyer = self.db.one('''SELECT c.username,c.first_name FROM public.orders o
                    JOIN public.customers c ON c.id=o.customer_id WHERE o.id=%s''', (order_id,))
                legacy_buyer = str(buyer['username'] or buyer['first_name'] or 'Buyer').strip().lstrip('@')[:2] + '***'
                effect = self._freeze(effect, snapshot, username, [i['product_id'] for i in items], legacy_buyer)
            return effect

    def materialize(self, limit):
        if not self.service.recipients.telegram:
            return 0
        try:
            self._channel()
            username = self._username()
        except NotEligible:
            return 0
        rows = self.db.rows('''SELECT o.id FROM public.orders o WHERE o.payment_status='paid'
            AND o.order_status IN ('processing','completed') AND (%s::uuid IS NULL OR o.id>%s::uuid)
            AND (NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects e WHERE e.effect_type=%s
                 AND e.source_type='App\\Models\\Order' AND e.source_id=o.id::text)
              OR EXISTS (SELECT 1 FROM public.telegram_outbound_effects e WHERE e.effect_type=%s
                 AND e.source_type='App\\Models\\Order' AND e.source_id=o.id::text AND e.state IN ('pending','failed')
                 AND NOT COALESCE(e.outcome_metadata::jsonb ? 'proof_publication',false)))
            ORDER BY o.id LIMIT %s''', (self._cursor, self._cursor, PROOF, PROOF, limit))
        self._cursor = str(rows[-1]['id']) if rows else None
        count = 0
        for row in rows:
            try:
                self.materialize_order(row['id'], username)
                count += 1
            except NotEligible:
                continue
        return count

    def payload(self, effect):
        if (effect['effect_type'] != PROOF or effect['source_type'] != 'App\\Models\\Order'
                or effect['target_type'] != 'store_channel' or effect['telegram_chat_id'] != self._channel()):
            raise NotEligible()
        self.eligibility.load(effect['source_id'])
        frozen = copy.deepcopy(_metadata(effect).get('proof_publication'))
        if not isinstance(frozen, dict):
            raise NotEligible()
        result = {'chat_id': effect['telegram_chat_id'], 'reply_markup': frozen['reply_markup'], 'parse_mode': 'HTML'}
        if effect['template_version'] == PHOTO_TEMPLATE and effect['telegram_method'] == 'sendPhoto':
            snapshot = frozen['snapshot']
            snapshot['paid_at'] = datetime.fromisoformat(snapshot['paid_at'])
            result.update(photo=render_invoice(snapshot), caption=frozen['caption'])
        elif effect['template_version'] == LEGACY_TEMPLATE and effect['telegram_method'] == 'sendMessage':
            result['text'] = frozen['caption']
        else:
            raise NotEligible()
        return result

    def project(self, effect):
        if effect['effect_type'] != PROOF or effect['source_type'] != 'App\\Models\\Order':
            return 0
        # A sent replacement is authoritative even when an older attempt is
        # reviewed later; repair compares all fields, not just the status.
        latest = self.db.one('''SELECT * FROM public.telegram_outbound_effects WHERE effect_type=%s
            AND source_type=%s AND source_id=%s AND state='sent'
            ORDER BY created_at DESC,id DESC LIMIT 1''', (PROOF, effect['source_type'], effect['source_id']))
        row = latest or effect
        if row['state'] not in ('sent','failed','unknown','manual_review'):
            return 0
        if row['state'] == 'sent' and not re.fullmatch(r'[1-9][0-9]*', str(row['telegram_message_id'] or '')):
            return 0
        error = None if row['state'] == 'sent' else ('Delivery outcome requires review.' if row['state'] in ('unknown','manual_review') else 'Sales Proof delivery failed before completion.')
        return self.db.execute('''UPDATE public.sales_proof_posts SET status=%s,telegram_message_id=%s,
            sent_at=%s,attempts=%s,last_error=%s,updated_at=%s WHERE order_id=%s
            AND (status,telegram_message_id,sent_at,attempts,last_error) IS DISTINCT FROM (%s,%s,%s,%s,%s)''',
            (row['state'], row['telegram_message_id'], row['sent_at'], row['attempts'], error, _now(), row['source_id'],
             row['state'], row['telegram_message_id'], row['sent_at'], row['attempts'], error))

    def retry(self, order_id, actor_id, ip=None):
        if not self.service.recipients.telegram:
            raise CheckoutError('sales_proof_not_ready')
        try:
            username = self._username()
        except NotEligible:
            raise CheckoutError('sales_proof_not_ready') from None
        with transaction.atomic(using=self.db.using):
            actor = self.db.one('SELECT role FROM public.users WHERE id=%s FOR SHARE', (actor_id,))
            if not actor or actor['role'] not in ('owner','admin'):
                raise CheckoutError('operator_forbidden')
            if not self.db.one('SELECT id FROM public.orders WHERE id=%s FOR UPDATE', (order_id,)):
                raise CheckoutError('sales_proof_not_failed')
            post = self.db.one('SELECT * FROM public.sales_proof_posts WHERE order_id=%s FOR UPDATE', (order_id,))
            if not post or post['status'] != 'failed':
                raise CheckoutError('sales_proof_not_failed')
            try:
                effect = self.materialize_order(order_id, username)
            except NotEligible:
                raise CheckoutError('sales_proof_not_ready') from None
            reset = self.service.protocol.request_manual_retry(effect['id'])
            if reset is None:
                raise CheckoutError('sales_proof_not_failed')
            self.db.execute("UPDATE public.sales_proof_posts SET status='pending',last_error=NULL,updated_at=%s WHERE order_id=%s", (_now(), order_id))
            from ..admin.db import audit
            audit(self.db, actor_id, 'sales_proof.retry_requested', 'App\\Models\\SalesProofPost', post['id'],
                  {'status': post['status'], 'attempts': post['attempts']}, {'order_id': str(order_id)}, ip)
            return reset
