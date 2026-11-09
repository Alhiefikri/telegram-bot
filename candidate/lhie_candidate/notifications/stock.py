"""Bounded stock audience snapshots over committed inventory movements."""
from html import escape
import json

from django.db import transaction

from ..navigation.repository import CatalogRepository
from ..receipt.protocol import _now
from ..receipt.render import _tr
from .policy import NotEligible
from .html import localized_html


def render(product, movement, event, locale, *, channel=False, icons=()):
    def tr(key, **values):
        return localized_html(locale, key, icons=icons, custom=not channel, **values)
    def amount(value):
        return f'{value:,}'.replace(',', '.')
    def usdt(value):
        whole, part = divmod(value, 1_000_000)
        return (str(whole) + f'.{part:06d}').rstrip('0').rstrip('.')
    name = escape((product.get('icon') or '🛍️') + ' ' + product['name'])
    if not channel and product.get('custom_emoji_id'):
        identifier = str(product['custom_emoji_id'])
        if identifier.isascii() and identifier.isdigit():
            name = '<tg-emoji emoji-id="' + identifier + '">' + escape(product.get('icon') or '🛍️') + '</tg-emoji> ' + escape(product['name'])
    sold = movement['quantity'] < 0
    tier = event.get('promoted_tier_minimum')
    if channel:
        if sold:
            lines = [name, tr('notification.store_channel.sold_out.label'), '━━━━━━━━━━━━━━', '',
                     tr('notification.store_channel.sold_out.confirmation'), tr('notification.store_channel.sold_out.thanks'), '',
                     tr('notification.store_channel.sold_out.unavailable'), tr('notification.store_channel.sold_out.follow_up')]
            markup, photo = {}, None
        else:
            price = tr('notification.store_channel.restock.price', amount=amount(product['price_amount']))
            if product['usdt_price_amount'] is not None:
                price += ' / ' + tr('notification.store_channel.restock.binance', amount=usdt(product['usdt_price_amount']))
            # Product presentation is already escaped; don't escape it twice.
            title = escape(_tr(locale, 'notification.store_channel.restock.title')).replace(':product', name)
            lines = [title, tr('notification.store_channel.restock.added', quantity=movement['quantity']),
                     tr('notification.store_channel.restock.available', stock=movement['available_after']), price]
            if tier is not None:
                line = tr('notification.store_channel.restock.tier', minimum=tier, amount=amount(event['promoted_tier_price_amount']))
                if event['promoted_tier_usdt_price_amount'] is not None:
                    line += ' / ' + usdt(event['promoted_tier_usdt_price_amount']) + ' USDT each'
                lines.append(line)
            markup = {'inline_keyboard': [[{'text': _tr(locale, 'notification.store_channel.cta'), 'style': 'primary',
                'url': 'https://t.me/LhieStoreTestBot?start=product_' + str(product['id'])}]]}
            family = product.get('family') or {}
            photo = (product.get('telegram_channel_image_path') or product.get('telegram_image_path') or
                     family.get('telegram_image_path') or product.get('image_path') or family.get('image_path'))
    elif sold:
        lines = [tr('notification.product.sold_out.title'), '━━━━━━━━━━━━━━', name,
                 tr('notification.product.sold_out.message'), '', tr('notification.product.sold_out.catalog')]
        markup, photo = {}, None
    else:
        dual = product['usdt_price_amount'] is not None
        lines = [tr('notification.product.restock.title'), '━━━━━━━━━━━━━━', tr('notification.product.restock.lead'), name,
                 tr('notification.product.price_dual' if dual else 'notification.product.price',
                    amount=amount(product['price_amount']), usd=usdt(product['usdt_price_amount']) if dual else ''),
                 tr('notification.product.stock', stock=movement['available_after'])]
        if tier is not None:
            dual_tier = event['promoted_tier_usdt_price_amount'] is not None
            lines.append(tr('notification.product.tier_dual' if dual_tier else 'notification.product.tier', minimum=tier,
                            amount=amount(event['promoted_tier_price_amount']),
                            usd=usdt(event['promoted_tier_usdt_price_amount']) if dual_tier else ''))
        description = product.get('description' if locale == 'id' else 'description_' + locale) or product.get('description')
        lines.extend(('', tr('notification.product.description'), escape(description) if description and description.strip()
                      else tr('notification.product.description_missing'), '', tr('notification.broadcast.help')))
        markup = {'inline_keyboard': [[{'text': _tr(locale, 'notification.product.cta'), 'style': 'primary',
                                       'callback_data': 'product:' + str(product['id'])}]]}
        photo = None
    return '\n'.join(lines), markup, photo


class StockNotifications:
    def __init__(self, db, recipients):
        self.db, self.recipients = db, recipients
        self.catalog = CatalogRepository(db.using)
        self._movement_cursor = 0
        self._event_cursor = 0

    def recover(self, limit):
        movements = self.db.rows('''SELECT id FROM public.inventory_movements WHERE id>%s
            AND ((type IN ('adjustment','availability') AND quantity>0) OR (quantity<0 AND became_unavailable=true))
            ORDER BY id LIMIT %s''', (self._movement_cursor, limit))
        self._movement_cursor = movements[-1]['id'] if movements else 0
        for movement in movements:
            self.db.execute('''INSERT INTO public.stock_notification_events
                (inventory_movement_id,kind,created_at,updated_at)
                SELECT id,type,%s,%s FROM public.inventory_movements WHERE id=%s
                ON CONFLICT (inventory_movement_id) DO NOTHING''', (_now(), _now(), movement['id']))
        events = self.db.rows('''SELECT id FROM public.stock_notification_events WHERE id>%s
            AND recipients_snapshotted_at IS NULL ORDER BY id LIMIT %s''', (self._event_cursor, limit))
        self._event_cursor = events[-1]['id'] if events else 0
        for event in events:
            self.materialize(event['id'], limit)

    def materialize(self, identity, limit):
        with transaction.atomic(using=self.db.using):
            event = self.db.one('SELECT * FROM public.stock_notification_events WHERE id=%s FOR UPDATE', (identity,))
            if not event or event['recipients_snapshotted_at']:
                return
            movement = self.db.one('SELECT * FROM public.inventory_movements WHERE id=%s', (event['inventory_movement_id'],))
            product = self.catalog.product(movement['product_id']) if movement else None
            if not product:
                return
            media = self.db.one('''SELECT p.telegram_channel_image_path,p.image_path,f.image_path AS family_image_path
                FROM public.products p LEFT JOIN public.product_families f ON f.id=p.product_family_id
                WHERE p.id=%s''', (product['id'],))
            product.update(telegram_channel_image_path=media['telegram_channel_image_path'], image_path=media['image_path'])
            if product.get('family'):
                product['family']['image_path'] = media['family_image_path']
            if (movement['quantity'] > 0 and (movement['available_after'] or 0) < 1) or (movement['quantity'] < 0 and product['available_stock'] > 0):
                return
            if event['tier_promotion_snapshotted_at'] is None and movement['quantity'] > 0:
                tier = self.db.one('''SELECT minimum_quantity,price_amount,usdt_price_amount FROM public.product_quantity_price_tiers
                    WHERE product_id=%s AND minimum_quantity<=%s AND (%s::integer IS NULL OR minimum_quantity>%s)
                    ORDER BY minimum_quantity LIMIT 1''', (product['id'], movement['available_after'], movement['available_before'], movement['available_before']))
                self.db.execute('''UPDATE public.stock_notification_events SET tier_promotion_snapshotted_at=%s,
                    promoted_tier_minimum=%s,promoted_tier_price_amount=%s,promoted_tier_usdt_price_amount=%s WHERE id=%s''',
                    (_now(), tier['minimum_quantity'] if tier else None, tier['price_amount'] if tier else None,
                     tier['usdt_price_amount'] if tier else None, identity))
                event = self.db.one('SELECT * FROM public.stock_notification_events WHERE id=%s', (identity,))
            customers = self.db.rows('''SELECT id,telegram_id,locale FROM public.customers c
                WHERE telegram_id::text=ANY(%s) AND NOT EXISTS (SELECT 1 FROM public.stock_notification_recipients r
                WHERE r.stock_notification_event_id=%s AND r.recipient_type='customer' AND r.telegram_id=c.telegram_id::text)
                ORDER BY id LIMIT %s''', (list(self.recipients.telegram), identity, limit))
            targets = [(c['id'], str(c['telegram_id']), c['locale'] if c['locale'] in ('id', 'en', 'ms') else 'en', False) for c in customers]
            setting = self.db.one("SELECT telegram_chat_id,last_test_success FROM public.store_channel_settings WHERE singleton_key='store_channel'")
            if setting and setting['last_test_success'] and self.recipients.permits(setting['telegram_chat_id']):
                targets.insert(0, (None, str(setting['telegram_chat_id']), 'en', True))
            for customer_id, chat, locale, channel in targets:
                icons = self.db.rows('SELECT source_emoji,fallback_emoji,custom_emoji_id FROM public.telegram_ui_icons')
                text, markup, photo = render(product, movement, event, locale, channel=channel, icons=icons)
                self.db.execute('''INSERT INTO public.stock_notification_recipients
                    (stock_notification_event_id,customer_id,recipient_type,telegram_id,locale,text,reply_markup,photo_path,created_at,updated_at)
                    VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s) ON CONFLICT DO NOTHING''',
                    (identity, customer_id, 'store_channel' if channel else 'customer', chat, None if channel else locale,
                     text, json.dumps(markup) if markup else None, photo, _now(), _now()))
            # Keep the global PHP handoff incomplete while local admission
            # intentionally excludes any canonical audience. No hidden waiver.
