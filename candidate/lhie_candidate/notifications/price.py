"""Price notices render immutable event economics, not current Product prices."""
from html import escape

from ..receipt.render import _tr, _translation
from .policy import NotEligible, PRICE_CHANNEL


def usdt(amount):
    if type(amount) is not int or amount < 0:
        raise NotEligible()
    whole, fraction = divmod(amount, 1_000_000)
    return (str(whole) + f'.{fraction:06d}').rstrip('0').rstrip('.') + ' USDT'


def payload(event, effect, *, icons=()):
    locale = effect['locale'] if effect['locale'] in ('id', 'en', 'ms') else 'en'
    channel = effect.get('effect_type') == PRICE_CHANNEL
    def tr(key, *, button=False, **values):
        # Resolve DB-owned static icons before inserting event values. Product
        # names and exact amounts can never acquire icon/formatting authority.
        unit = _translation(locale, 'notification.price.' + key, icons=icons, custom=not channel and not button, **values)
        if button:
            return unit.text
        data, cursor, parts = unit.text.encode('utf-16-le'), 0, []
        for entity in unit.entities:
            start, end = entity['offset'] * 2, (entity['offset'] + entity['length']) * 2
            parts.extend((escape(data[cursor:start].decode('utf-16-le')),
                '<tg-emoji emoji-id="' + escape(entity['custom_emoji_id']) + '">' + escape(data[start:end].decode('utf-16-le')) + '</tg-emoji>'))
            cursor = end
        parts.append(escape(data[cursor:].decode('utf-16-le')))
        return ''.join(parts)
    def idr(amount):
        if type(amount) is not int or amount < 0:
            raise NotEligible()
        return 'Rp' + f'{amount:,}'.replace(',', ',' if locale == 'en' else '.')
    old, new = event['old_price_amount'], event['new_price_amount']
    before, after = event['old_usdt_price_amount'], event['new_usdt_price_amount']
    changes = []
    if old != new:
        changes.append(new < old)
    if before is not None and after is not None and before != after:
        changes.append(after < before)
    if (before is None) != (after is None):
        changes.append(False)
    decrease = bool(changes) and all(changes)
    glyph = event.get('product_icon') or '📦'
    name = escape(event['product_name'])
    identifier = event.get('product_custom_emoji_id')
    icon = escape(glyph)
    if not channel and identifier and str(identifier).isascii() and str(identifier).isdigit():
        icon = f'<tg-emoji emoji-id="{identifier}">{icon}</tg-emoji>'
    lines = [tr('title.drop' if decrease else 'title.update'), '━━━━━━━━━━━━━━',
             tr('lead.drop' if decrease else 'lead.update'), icon + ' ' + name, '',
             tr('qris.label'), idr(old) + ' → ' + idr(new)]
    if before != after:
        line = (tr('usdt.now_available', amount=usdt(after)) if before is None else
                tr('usdt.unavailable') if after is None else usdt(before) + ' → ' + usdt(after))
        lines.extend(('', tr('binance.label'), line))
    savings = []
    if new < old:
        savings.append(idr(old - new))
    if before is not None and after is not None and after < before:
        savings.append(usdt(before - after))
    lines.append('')
    if decrease and savings:
        lines.extend((tr('savings', amount=' + '.join(savings)), tr('new_price_ready')))
    else:
        lines.extend((tr('future_checkout'), tr('existing_orders')))
    lines.extend(('', tr('help')))
    return {'chat_id': effect['telegram_chat_id'], 'text': '\n'.join(lines), 'parse_mode': 'HTML',
             'reply_markup': {'inline_keyboard': [[{'text': tr('cta', button=True), 'style': 'primary',
                 **({'url': 'https://t.me/LhieStoreTestBot?start=product_' + str(event['product_id'])} if channel else
                    {'callback_data': 'product:' + str(event['product_id'])})}]]}}


class PriceNotifications:
    """Bounded local admission over the canonical immutable price event."""
    def __init__(self, db, recipients):
        self.db, self.recipients = db, recipients
        self._cursor = 0

    def record_change(self, before, after):
        from uuid import uuid4
        from ..navigation.repository import CatalogRepository
        from ..receipt.protocol import _now
        self.db.require_transaction()
        if (not before['is_active'] or (before['price_amount'], before['usdt_price_amount']) ==
                (after['price_amount'], after['usdt_price_amount'])):
            return None
        product = CatalogRepository(self.db.using).product(before['id'])
        if not product or product['available_stock'] < 1:
            return None
        return self.db.one('''INSERT INTO public.product_price_change_events
            (event_key,product_id,product_name,product_icon,product_custom_emoji_id,
             old_price_amount,new_price_amount,old_usdt_price_amount,new_usdt_price_amount,created_at,updated_at)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s) RETURNING *''',
            (str(uuid4()), before['id'], before['name'], product.get('icon'), product.get('custom_emoji_id'),
             before['price_amount'], after['price_amount'], before['usdt_price_amount'], after['usdt_price_amount'], _now(), _now()))

    def recover(self, record, limit, *, channel_enabled=True):
        from django.db import transaction
        from ..receipt.protocol import _now
        rows = self.db.rows('''SELECT id FROM public.product_price_change_events
            WHERE id>%s ORDER BY id LIMIT %s''', (self._cursor, limit))
        self._cursor = rows[-1]['id'] if rows else 0
        for row in rows:
            with transaction.atomic(using=self.db.using):
                event = self.db.one('SELECT * FROM public.product_price_change_events WHERE id=%s FOR UPDATE', (row['id'],))
                if event['recipients_materialized_at'] is None:
                    customers = self.db.rows('''SELECT c.id,c.telegram_id,c.locale FROM public.customers c
                        WHERE c.telegram_id::text=ANY(%s) AND NOT EXISTS (
                            SELECT 1 FROM public.product_price_change_notification_recipients r
                            WHERE r.event_id=%s AND r.telegram_id=c.telegram_id::text) ORDER BY c.id LIMIT %s''',
                        (list(self.recipients.telegram), event['id'], limit))
                    for customer in customers:
                        locale = customer['locale'] if customer['locale'] in ('id', 'ms', 'en') else 'en'
                        self.db.execute('''INSERT INTO public.product_price_change_notification_recipients
                            (event_id,customer_id,telegram_id,locale,created_at,updated_at)
                            VALUES (%s,%s,%s,%s,%s,%s) ON CONFLICT DO NOTHING''',
                            (event['id'], customer['id'], str(customer['telegram_id']), locale, _now(), _now()))
                    # Never claim a filtered local audience is the full PHP
                    # audience. The original handoff remains discoverable.
                setting = self.db.one("SELECT telegram_chat_id,channel_username FROM public.store_channel_settings WHERE singleton_key='store_channel'")
                chat = str((setting or {}).get('telegram_chat_id') or (setting or {}).get('channel_username') or '').strip()
                if channel_enabled and self.recipients.permits(chat):
                    record(key=f"price-change:{event['id']}:store-channel:v1", kind=PRICE_CHANNEL,
                           model='ProductPriceChangeEvent', source=event['id'], chat=chat, target='store_channel', locale='en',
                           template='price-change-store-channel-v1', legacy={
                               'sent_at': event['store_channel_sent_at'], 'failed_at': event['store_channel_failed_at'],
                               'send_started_at': event.get('store_channel_send_started_at'),
                               'terminal_failed_at': event['store_channel_failed_at'], 'attempts': event['store_channel_attempts'],
                               'telegram_message_id': event['store_channel_message_id']})
