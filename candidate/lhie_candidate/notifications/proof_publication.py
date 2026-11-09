"""Store Channel adapter. Only public-safe economics reach the invoice renderer."""
from html import escape, unescape
import re
from zoneinfo import ZoneInfo

from django.db import transaction

from ..auth.limits import RedisAdmission
from ..navigation.repository import CatalogRepository
from ..receipt.invoice import render_invoice
from ..receipt.protocol import _now
from ..receipt.render import _len
from .policy import NotEligible, PROOF
from .proof import SalesProofEligibility

PHOTO_TEMPLATE = 'sales-proof-post-v2'
LEGACY_TEMPLATE = 'sales-proof-post-v1'
TEXT_ICONS = {'message.users': '👥', 'message.ticket': '🎫', 'message.details': '📝',
              'message.quantity': '🔢', 'message.deadline': '⏳', 'message.cash': '💵', 'message.next': '➡️'}
_NUMBER = '''local current=tonumber(redis.call('GET',KEYS[1]) or '0')
local floor=tonumber(ARGV[1])
if not current or current<0 or current~=math.floor(current) or not floor
  or floor<0 or floor~=math.floor(floor) or math.max(current,floor)>=9007199254740990
  then return redis.error_reply('invalid counter') end
if current<floor then redis.call('SET',KEYS[1],ARGV[1]) end
return redis.call('INCR',KEYS[1])'''
_COUNTER_FLOOR = '''local current=tonumber(redis.call('GET',KEYS[1]) or '0')
local floor=tonumber(ARGV[1])
if not current or current<0 or current~=math.floor(current) or not floor or floor<0
  or floor~=math.floor(floor) or math.max(current,floor)>=9007199254740991
  then return redis.error_reply('invalid counter') end
if current<floor then redis.call('SET',KEYS[1],ARGV[1]); return floor end
return current'''
COUNTER_KEY = 'candidate:sales-proof-posts:published-count'


class PublicationUnavailable(Exception):
    """A dependency failed before any external publication was attempted."""


def _clean(value):
    return re.sub(r'[\x00-\x1f\x7f]+', ' ', str(value or '')).strip()


def money(value, currency):
    if type(value) is not int or value < 0:
        raise NotEligible()
    if currency == 'USDT':
        whole, fraction = divmod(value, 1_000_000)
        return (str(whole) + f'.{fraction:06d}').rstrip('0').rstrip('.') + ' USDT'
    if currency != 'IDR':
        raise NotEligible()
    return 'Rp ' + f'{value:,}'.replace(',', '.')


def caption(snapshot, number, remaining=None, *, icon=lambda value: value, text_icon=None, legacy=False):
    items = snapshot['items']
    text_icon = text_icon or (lambda key: TEXT_ICONS[key])
    def label(item):
        name = _clean(item['product_name']) or 'Digital product'
        name = name if len(name) <= 72 else name[:69] + '…'
        return (item.get('icon') or '🛍️') + ' ' + name
    quantity = sum(item['quantity'] for item in items)
    if legacy:
        item = items[0]
        name = item['product_name'] if item['product_name'] is not None else 'Digital product'
        glyph = str(item.get('icon') or '').strip() or '🛍️'
        primary = glyph + ' ' + str(name) + (f' + {len(items) - 1} other products' if len(items) > 1 else '')
        return escape('\n'.join((icon('🎉 SUCCESSFUL PURCHASE 🎉'), '',
            text_icon('message.users') + ' Buyer: ' + snapshot['legacy_buyer'], text_icon('message.details') + ' Product: ' + primary,
            text_icon('message.quantity') + ' Quantity: ' + str(quantity) + 'x', icon('🏆 Successful orders: #') + str(number), '',
            icon('✨ Thank you for shopping with us!'), 'Want to order too? Tap the button below ' + text_icon('message.next'))))
    lines = [icon('🎉 API ORDER FULFILLED' if snapshot['api'] else '🎉 ORDER PAYMENT CONFIRMED'), '',
             text_icon('message.users') + ' Buyer: ' + snapshot['buyer_name'], text_icon('message.ticket') + ' Order ID: ' + snapshot['invoice_number']]
    if snapshot['api']:
        lines.append('Origin: API')
    if len(items) == 1:
        lines.append(text_icon('message.details') + ' Item: ' + label(items[0]) + ' × ' + str(items[0]['quantity']))
    else:
        lines.append(text_icon('message.details') + ' Items:')
        lines.extend('• ' + label(item) + ' × ' + str(item['quantity']) for item in items[:6])
        if len(items) > 6:
            lines.append('• +' + str(len(items) - 6) + ' other items')
    if remaining is not None and 1 <= remaining <= 5:
        lines.append(icon('🔥 Only ') + str(remaining) + ' left in stock')
    subtotal = snapshot.get('payment_subtotal')
    if subtotal is None:
        subtotal = sum(item['subtotal'] for item in items)
    lines.extend((icon('💳 Paid via: ') + snapshot['payment_method'],
                  text_icon('message.deadline') + ' Completed: ' + snapshot['paid_at'].astimezone(ZoneInfo('Asia/Jakarta')).strftime('%d %B %Y, %H:%M WIB'),
                  text_icon('message.quantity') + ' Quantity: ' + str(quantity), icon('💵 Subtotal: ') + money(subtotal, snapshot['currency'])))
    if snapshot['provider_fee'] > 0:
        lines.append(icon('💸 Fee: ') + money(snapshot['provider_fee'], snapshot['currency']))
    lines.extend((text_icon('message.cash') + ' Amount paid: ' + money(snapshot['total_paid'], snapshot['currency']),
                  icon('🏆 Successful orders: #') + str(number)))
    minimums = {item.get('quantity_price_tier_minimum') for item in items if item.get('quantity_price_tier_minimum')}
    if minimums:
        lines.append(icon('🏷 Bulk pricing applied') + (f' · {next(iter(minimums))}+ units' if len(items) == len(minimums) == 1 else ''))
    else:
        lines.append('')
    lines.extend(('━━━━━━━━━━━━━━', icon('✨ Thank you for choosing Lhie Store.')))
    raw = '\n'.join(lines)
    if _len(raw) > 1024:
        tail = '\n'.join(lines[-4:])
        budget = max(1, 1024 - _len(tail) - 3)
        head = '\n'.join(lines[:-4]).encode('utf-16-le')[:budget * 2].decode('utf-16-le', errors='ignore')
        raw = head.rstrip() + '…\n\n' + tail
    if _len(raw) > 1024:
        raise NotEligible()
    return escape(raw)


class SalesProofPublication:
    def __init__(self, recipients, *, using='default', counter=None):
        self.eligibility = SalesProofEligibility(using=using)
        self.db = self.eligibility.db
        self.catalog = CatalogRepository(using)
        self.recipients = recipients
        self.counter = counter if counter is not None else RedisAdmission()
        self._cursor = None

    def handoff_counter(self, legacy_floor, *, producers_stopped=False):
        """Local cutover seam: import PHP's publishedCount, export rollback floor.

        Run only after both publishers are drained. The returned floor must be
        installed in PHP's existing sales-proof-posts:published-count cache
        before PHP publication resumes. Never infer the floor from sent rows:
        reserved/unknown publications and fake-proof reservations count too.
        """
        if producers_stopped is not True or type(legacy_floor) is not int or not 0 <= legacy_floor < 2**53 - 1:
            raise ValueError('Counter handoff requires stopped publishers and an exact floor')
        sent = self.db.one("SELECT count(*) AS count FROM public.sales_proof_posts WHERE status='sent'")['count']
        try:
            result = self.counter.eval(_COUNTER_FLOOR, 1, COUNTER_KEY, max(sent, legacy_floor))
        except Exception:
            raise PublicationUnavailable() from None
        if type(result) is not int or not 0 <= result < 2**53 - 1:
            raise PublicationUnavailable()
        return result

    def setting(self):
        row = self.db.one("SELECT sales_proof_enabled,telegram_chat_id,channel_username FROM public.store_channel_settings WHERE singleton_key='store_channel'")
        if not row or not row['sales_proof_enabled']:
            raise NotEligible()
        chat = str(row['telegram_chat_id'] or row['channel_username'] or '').strip()
        if not self.recipients.permits(chat):
            raise NotEligible()
        return chat

    def discover(self, record, limit):
        try:
            chat = self.setting()
        except NotEligible:
            return 0
        rows = self.db.rows('''SELECT o.id FROM public.orders o WHERE o.payment_status='paid'
            AND o.order_status IN ('processing','completed') AND (%s::uuid IS NULL OR o.id>%s::uuid)
            AND NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects e
                WHERE e.source_type='App\\Models\\Order' AND e.source_id=o.id::text AND e.effect_type=%s)
            ORDER BY o.id LIMIT %s''', (self._cursor, self._cursor, PROOF, limit))
        self._cursor = str(rows[-1]['id']) if rows else None
        created = 0
        for row in rows:
            with transaction.atomic(using=self.db.using):
                self.db.one('SELECT id FROM public.orders WHERE id=%s FOR UPDATE', (row['id'],))
                try:
                    self.eligibility.load(row['id'])
                except NotEligible:
                    continue
                at = _now()
                self.db.execute('''INSERT INTO public.sales_proof_posts (order_id,status,created_at,updated_at)
                    VALUES (%s,'pending',%s,%s) ON CONFLICT (order_id) DO NOTHING''', (row['id'], at, at))
                post = self.db.one('SELECT * FROM public.sales_proof_posts WHERE order_id=%s', (row['id'],))
                record(key=f"order:{row['id']}:{PROOF}:v1", kind=PROOF, model='Order', source=row['id'],
                       chat=chat, target='store_channel', locale='en', template=PHOTO_TEMPLATE, method='sendPhoto', legacy=post)
                created += 1
        return created

    def prepare(self, effect_id):
        with transaction.atomic(using=self.db.using):
            self.db.execute('''UPDATE public.telegram_outbound_effects SET telegram_method='sendPhoto',updated_at=%s
                WHERE id=%s AND effect_type=%s AND template_version=%s AND state IN ('pending','failed')
                  AND telegram_method<>'sendPhoto' ''', (_now(), effect_id, PROOF, PHOTO_TEMPLATE))

    def retry(self, order_id, actor_id, protocol, *, ip=None):
        from ..checkout.errors import CheckoutError
        with transaction.atomic(using=self.db.using):
            actor = self.db.one('SELECT role FROM public.users WHERE id=%s', (actor_id,))
            if not actor or actor['role'] not in ('owner', 'admin'):
                raise CheckoutError('operator_forbidden')
            self.db.one('SELECT id FROM public.orders WHERE id=%s FOR UPDATE', (order_id,))
            try:
                self.setting()
                self.eligibility.load(order_id)
            except NotEligible:
                raise CheckoutError('sales_proof_not_eligible') from None
            post = self.db.one('SELECT * FROM public.sales_proof_posts WHERE order_id=%s FOR UPDATE', (order_id,))
            effect = self.db.one('''SELECT * FROM public.telegram_outbound_effects
                WHERE source_type='App\\Models\\Order' AND source_id=%s AND effect_type=%s
                ORDER BY sequence DESC,created_at DESC,id DESC LIMIT 1 FOR UPDATE''', (str(order_id), PROOF))
            if (not post or post['status'] != 'failed' or not effect
                    or not self.recipients.permits(effect['telegram_chat_id'])):
                raise CheckoutError('sales_proof_retry_unavailable')
            result = protocol.request_manual_retry(effect['id'])
            if not result:
                raise CheckoutError('sales_proof_retry_unavailable')
            self.db.execute("UPDATE public.sales_proof_posts SET status='pending',last_error=NULL,updated_at=%s WHERE id=%s", (_now(), post['id']))
            self.db.execute('''INSERT INTO public.audit_logs
                (user_id,action,subject_type,subject_id,new_values,ip_address,created_at)
                VALUES (%s,'sales_proof.retry_requested','App\\Models\\SalesProofPost',%s,%s,%s,%s)''',
                (actor_id, str(post['id']), '{"order_id":"' + str(order_id) + '"}', ip, _now()))
            return result

    def payload(self, effect):
        self.setting()  # Disabled channels never send retained work.
        if effect['target_type'] != 'store_channel' or effect['template_version'] not in (PHOTO_TEMPLATE, LEGACY_TEMPLATE):
            raise NotEligible()
        snapshot = self.eligibility.load(effect['source_id'])
        icons = self.db.rows('SELECT key,source_emoji,fallback_emoji FROM public.telegram_ui_icons')
        slots = {row['key']: row for row in icons}
        def text_icon(key):
            row = slots.get(key)
            return str(row['fallback_emoji'] or '').strip() if row else TEXT_ICONS[key]
        def icon(value):
            # Channel scope is Unicode; premium identifiers are deliberately
            # absent unless an independently qualified channel capability exists.
            for row in sorted((row for row in icons if row['source_emoji']), key=lambda row: len(row['source_emoji']), reverse=True):
                value = value.replace(row['source_emoji'], row['fallback_emoji'] or '')
            return value
        count = self.db.one("SELECT COUNT(*) AS count FROM public.sales_proof_posts WHERE status='sent'")['count']
        try:
            number = self.counter.eval(_NUMBER, 1, COUNTER_KEY, count)
        except Exception:
            raise PublicationUnavailable() from None
        if type(number) is not int or number < 1:
            raise PublicationUnavailable()
        item = snapshot['items'][0]
        remaining = None
        if len(snapshot['items']) == 1:
            product = self.catalog.product(item['product_id'])
            if product and 1 <= product['available_stock'] <= 5:
                remaining = product['available_stock']
        legacy = effect['template_version'] == LEGACY_TEMPLATE
        if effect['telegram_method'] != ('sendMessage' if legacy else 'sendPhoto'):
            raise NotEligible()
        button = {'text': icon('🛒 Shop Now'), 'style': 'primary',
                  'url': 'https://t.me/LhieStoreTestBot?start=product_' + str(item['product_id'])}
        result = {'chat_id': effect['telegram_chat_id'], 'parse_mode': 'HTML',
                  'reply_markup': {'inline_keyboard': [[button]]}}
        text = caption(snapshot, number, remaining, icon=icon, text_icon=text_icon, legacy=legacy)
        if legacy:
            result['text'] = text
        else:
            # All channel glyphs are Unicode and customer/product values have
            # no formatting authority. Use literal text on the bounded upload
            # relay, which deliberately admits entities rather than parse_mode.
            result.pop('parse_mode')
            result.update(photo=render_invoice(snapshot), caption=unescape(text))
        return result
