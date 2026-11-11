"""Bounded broadcast/stock/price discovery over the existing outbound ledger."""
import json

from django.db import transaction

from ..navigation.repository import CatalogRepository
from ..receipt.protocol import _now
from . import broadcast, price, stock
from .bulk_content import PRICE_CHANNEL, channel, freeze, frozen, icons, locale, product_url
from .policy import BROADCAST, PRICE, STOCK, NotEligible

MODELS = {BROADCAST: 'TelegramBroadcastRecipient', STOCK: 'StockNotificationRecipient',
          PRICE: 'ProductPriceChangeNotificationRecipient', PRICE_CHANNEL: 'ProductPriceChangeEvent'}
LIMITS = {BROADCAST: 5, STOCK: 5, PRICE: 5, PRICE_CHANNEL: 3}
TABLES = {BROADCAST: ('telegram_broadcast_recipients', 'broadcast_id'),
          STOCK: ('stock_notification_recipients', 'stock_notification_event_id'),
          PRICE: ('product_price_change_notification_recipients', 'event_id')}


class BulkNotices:
    def __init__(self, service, *, bot_username=None, bot_scope=None, effect_types=None):
        bot_username = getattr(service, 'bot_username', None) if bot_username is None else bot_username
        bot_scope = getattr(service, 'bot_scope', 'synthetic') if bot_scope is None else bot_scope
        if bot_scope not in ('synthetic', 'LhieStoreTestBot'):
            raise ValueError('Only explicit test-bot asset scopes are allowed')
        self.service, self.db = service, service.db
        self.recipients = service.recipients
        self.bot_username, self.bot_scope = bot_username, bot_scope
        self.repository = CatalogRepository(self.db.using)
        self.effect_types = frozenset(service.protocol.effect_types if effect_types is None else effect_types) & MODELS.keys()
        self._cursors = {BROADCAST: 0, PRICE: 0, STOCK: 0}

    def _page(self, family, sql, params, limit):
        rows = self.db.rows(sql, (*params, self._cursors[family], limit))
        self._cursors[family] = rows[-1]['id'] if rows else 0
        return rows

    def _customers(self, table, foreign, source, limit):
        return self.db.rows(f'''SELECT c.id,c.telegram_id,c.locale FROM public.customers c
            WHERE c.telegram_id::text=ANY(%s) AND NOT EXISTS (
                SELECT 1 FROM public.{table} r WHERE r.{foreign}=%s AND r.telegram_id=c.telegram_id::text)
            ORDER BY c.id LIMIT %s''', (list(self.recipients.telegram), source, limit))

    def _complete_audience(self, table, foreign, source):
        return not self.db.one(f'''SELECT EXISTS(SELECT 1 FROM public.customers c
            WHERE c.telegram_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM public.{table} r
                WHERE r.{foreign}=%s AND r.telegram_id=c.telegram_id::text)) AS remaining''', (source,))['remaining']

    def _insert_customers(self, table, foreign, source, limit):
        for customer in self._customers(table, foreign, source, limit):
            at = _now()
            self.db.execute(f'''INSERT INTO public.{table}
                ({foreign},customer_id,telegram_id,locale,created_at,updated_at)
                VALUES (%s,%s,%s,%s,%s,%s) ON CONFLICT DO NOTHING''',
                (source, customer['id'], str(customer['telegram_id']), locale(customer['locale']), at, at))

    def _missing(self, kind, source, limit):
        table, foreign = TABLES[kind]
        return self.db.rows(f'''SELECT r.* FROM public.{table} r WHERE r.{foreign}=%s
            AND r.telegram_id=ANY(%s) AND NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects e
                WHERE e.effect_type=%s AND e.source_type=%s AND e.source_id=r.id::text)
            ORDER BY r.id LIMIT %s''',
            (source, list(self.recipients.telegram), kind, 'App\\Models\\' + MODELS[kind], limit))

    def _record(self, *, kind, source, chat, target, code, key, rendered, legacy):
        method = 'sendPhoto' if 'photo' in rendered else 'sendMessage'
        effect = self.service.record(key=key, kind=kind, model=MODELS[kind], source=source,
            chat=chat, target=target, locale=locale(code), template='php-bulk-snapshot-v1',
            method=method, legacy=legacy)
        freeze(self.db, effect, rendered)
        return effect

    def materialize(self, limit=25):
        if type(limit) is not int or not 1 <= limit <= 100:
            raise ValueError('Invalid bulk slice')
        if not self.recipients.telegram:
            return 0
        made = 0
        # A source with only nonadmitted customers does not monopolize recovery.
        broadcasts = self._page(BROADCAST, '''SELECT b.id FROM public.telegram_broadcasts b WHERE (
            EXISTS (SELECT 1 FROM public.customers c WHERE c.telegram_id::text=ANY(%s)
                AND NOT EXISTS (SELECT 1 FROM public.telegram_broadcast_recipients r
                    WHERE r.broadcast_id=b.id AND r.telegram_id=c.telegram_id::text))
            OR EXISTS (SELECT 1 FROM public.telegram_broadcast_recipients r WHERE r.broadcast_id=b.id
                AND r.telegram_id=ANY(%s) AND NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects e
                    WHERE e.effect_type=%s AND e.source_type=%s AND e.source_id=r.id::text))
            ) AND b.id>%s ORDER BY b.id LIMIT %s''',
            (list(self.recipients.telegram), list(self.recipients.telegram), BROADCAST,
             'App\\Models\\TelegramBroadcastRecipient'), limit) if BROADCAST in self.effect_types else []
        for source in broadcasts:
            made += broadcast.materialize(self.db, self.recipients, source['id'], self.service.record, limit=limit)
        # Historical completed events remain discoverable when their effect is missing.
        prices = self._page(PRICE, '''SELECT p.id FROM public.product_price_change_events p WHERE (
            (%s AND p.recipients_materialized_at IS NULL AND EXISTS (
                SELECT 1 FROM public.customers c WHERE c.telegram_id::text=ANY(%s) AND NOT EXISTS (
                    SELECT 1 FROM public.product_price_change_notification_recipients r
                    WHERE r.event_id=p.id AND r.telegram_id=c.telegram_id::text)))
            OR (%s AND EXISTS (SELECT 1 FROM public.product_price_change_notification_recipients r WHERE r.event_id=p.id
                AND r.telegram_id=ANY(%s) AND NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects e
                    WHERE e.effect_type=%s AND e.source_type=%s AND e.source_id=r.id::text)))
            OR (%s AND EXISTS (SELECT 1 FROM public.store_channel_settings s WHERE s.last_test_success=true
                    AND s.telegram_chat_id::text=ANY(%s)) AND NOT EXISTS (
                SELECT 1 FROM public.telegram_outbound_effects e WHERE e.effect_type=%s
                AND e.source_type=%s AND e.source_id=p.id::text)))
            AND p.id>%s ORDER BY p.id LIMIT %s''', (PRICE in self.effect_types, list(self.recipients.telegram),
            PRICE in self.effect_types, list(self.recipients.telegram), PRICE,
            'App\\Models\\ProductPriceChangeNotificationRecipient', PRICE_CHANNEL in self.effect_types,
            list(self.recipients.telegram), PRICE_CHANNEL, 'App\\Models\\ProductPriceChangeEvent'),
            limit) if self.effect_types & {PRICE, PRICE_CHANNEL} else []
        for source in prices:
            made += self.materialize_price(source['id'], limit=limit)
        movements = self._page(STOCK, '''SELECT m.id FROM public.inventory_movements m
            LEFT JOIN public.stock_notification_events n ON n.inventory_movement_id=m.id
            WHERE (m.type='adjustment' AND m.quantity>0 OR m.type='availability'
                AND m.quantity>0 AND m.available_before=0 AND m.available_after>0
                OR m.type IN ('sale','adjustment','availability') AND m.quantity<0 AND m.became_unavailable=true)
            AND (n.id IS NULL OR (n.recipients_snapshotted_at IS NULL AND (
                EXISTS (SELECT 1 FROM public.customers c WHERE c.telegram_id::text=ANY(%s)
                    AND NOT EXISTS (SELECT 1 FROM public.stock_notification_recipients r
                        WHERE r.stock_notification_event_id=n.id AND r.telegram_id=c.telegram_id::text))
                OR EXISTS (SELECT 1 FROM public.store_channel_settings s WHERE s.last_test_success=true
                    AND s.telegram_chat_id::text=ANY(%s) AND NOT EXISTS (
                        SELECT 1 FROM public.stock_notification_recipients r
                        WHERE r.stock_notification_event_id=n.id AND r.telegram_id=s.telegram_chat_id::text))))
                OR EXISTS (SELECT 1 FROM public.stock_notification_recipients r WHERE r.stock_notification_event_id=n.id
                    AND r.telegram_id=ANY(%s) AND NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects e
                        WHERE e.effect_type=%s AND e.source_type=%s AND e.source_id=r.id::text)))
            AND m.id>%s ORDER BY m.id LIMIT %s''', (list(self.recipients.telegram), list(self.recipients.telegram),
                list(self.recipients.telegram), STOCK, 'App\\Models\\StockNotificationRecipient'),
                limit) if STOCK in self.effect_types else []
        for source in movements:
            made += self.materialize_stock(source['id'], limit=limit)
        return made

    def materialize_price(self, event_id, *, limit=100):
        if type(limit) is not int or not 1 <= limit <= 100:
            raise ValueError('Invalid bulk slice')
        if not self.recipients.telegram:
            return 0
        with transaction.atomic(using=self.db.using):
            event = self.db.one('SELECT * FROM public.product_price_change_events WHERE id=%s FOR UPDATE', (event_id,))
            if not event:
                return 0
            event['ui_icons'] = icons(self.db)
            if PRICE in self.effect_types and event['recipients_materialized_at'] is None:
                self._insert_customers(*TABLES[PRICE], event_id, limit)
                if self._complete_audience(*TABLES[PRICE], event_id):
                    self.db.execute('''UPDATE public.product_price_change_events SET recipients_materialized_at=%s,
                        updated_at=%s WHERE id=%s''', (_now(), _now(), event_id))
            rows = self._missing(PRICE, event_id, limit) if PRICE in self.effect_types else []
            for recipient in rows:
                effect = {'telegram_chat_id': recipient['telegram_id'], 'locale': recipient['locale']}
                self._record(kind=PRICE, source=recipient['id'], chat=recipient['telegram_id'], target='customer',
                    code=recipient['locale'], key=f"price-change:{event_id}:recipient:{recipient['id']}:v1",
                    rendered=price.payload(event, effect), legacy=recipient)
            target = channel(self.db, self.recipients) if PRICE_CHANNEL in self.effect_types else None
            if target and not self.db.one('''SELECT id FROM public.telegram_outbound_effects
                WHERE effect_type=%s AND source_type=%s AND source_id=%s LIMIT 1''',
                (PRICE_CHANNEL, 'App\\Models\\ProductPriceChangeEvent', str(event_id))):
                event['product_url'] = product_url(event['product_id'], self.bot_username)
                effect = {'telegram_chat_id': target, 'locale': 'en', 'effect_type': PRICE_CHANNEL}
                legacy = {'sent_at': event.get('store_channel_sent_at'),
                          'failed_at': event.get('store_channel_failed_at'),
                          'terminal_failed_at': event.get('store_channel_failed_at'),
                          'attempts': event.get('store_channel_attempts', 0)}
                self._record(kind=PRICE_CHANNEL, source=event_id, chat=target, target='store_channel', code='en',
                    key=f'price-change:{event_id}:store-channel:v1', rendered=price.payload(event, effect), legacy=legacy)
                return len(rows) + 1
            return len(rows)

    def _product(self, product_id):
        return self.db.one('''SELECT p.*,f.icon AS family_icon,f.custom_emoji_id AS family_custom_emoji_id,
            f.telegram_image_path AS family_telegram_image_path,f.image_path AS family_image_path
            FROM public.products p LEFT JOIN public.product_families f ON f.id=p.product_family_id
            WHERE p.id=%s AND p.deleted_at IS NULL''', (product_id,))

    def _stock_photo(self, product):
        return next((product.get(key) for key in ('telegram_channel_image_path', 'telegram_image_path',
            'family_telegram_image_path', 'image_path', 'family_image_path') if product.get(key) is not None), None)

    def materialize_stock(self, movement_id, *, limit=100):
        if type(limit) is not int or not 1 <= limit <= 100:
            raise ValueError('Invalid bulk slice')
        if not self.recipients.telegram:
            return 0
        with transaction.atomic(using=self.db.using):
            movement = self.db.one('SELECT * FROM public.inventory_movements WHERE id=%s FOR UPDATE', (movement_id,))
            if not movement or not stock.notifiable(movement):
                return 0
            product = self._product(movement['product_id'])
            if not product:
                return 0
            self.db.execute('''INSERT INTO public.stock_notification_events (inventory_movement_id,kind,created_at,updated_at)
                VALUES (%s,%s,%s,%s) ON CONFLICT DO NOTHING''', (movement_id, movement['type'], _now(), _now()))
            event = self.db.one('SELECT * FROM public.stock_notification_events WHERE inventory_movement_id=%s FOR UPDATE', (movement_id,))
            ui_icons = icons(self.db)
            sold_out = movement['quantity'] < 0
            current = self.repository.product(product['id'])
            available = current['available_stock'] if current else max(0, product['stock'] - product['reserved_stock'])
            eligible = (available == 0 if sold_out else product['is_active'] and
                        (movement.get('available_after') if movement.get('available_after') is not None else available) > 0)
            if eligible and event['recipients_snapshotted_at'] is None:
                if not sold_out and event.get('tier_promotion_snapshotted_at') is None:
                    after = movement.get('available_after') if movement.get('available_after') is not None else available
                    before = movement.get('available_before')
                    tier = self.db.one('''SELECT minimum_quantity,price_amount,usdt_price_amount FROM public.product_quantity_price_tiers
                        WHERE product_id=%s AND minimum_quantity<=%s AND (%s::bigint IS NULL OR minimum_quantity>%s)
                        ORDER BY minimum_quantity,id LIMIT 1''', (product['id'], after, before, max(0, before or 0))) or {}
                    event.update(promoted_tier_minimum=tier.get('minimum_quantity'), promoted_tier_price_amount=tier.get('price_amount'),
                                 promoted_tier_usdt_price_amount=tier.get('usdt_price_amount'))
                    self.db.execute('''UPDATE public.stock_notification_events SET tier_promotion_snapshotted_at=%s,
                        promoted_tier_minimum=%s,promoted_tier_price_amount=%s,promoted_tier_usdt_price_amount=%s,updated_at=%s
                        WHERE id=%s''', (_now(), event['promoted_tier_minimum'], event['promoted_tier_price_amount'],
                            event['promoted_tier_usdt_price_amount'], _now(), event['id']))
                audience = [dict(customer, recipient_type='customer') for customer in
                            self._customers(*TABLES[STOCK], event['id'], limit)]
                target = channel(self.db, self.recipients)
                if target:
                    audience.append({'id': None, 'telegram_id': target, 'locale': 'en', 'recipient_type': 'store_channel'})
                for customer in audience:
                    code = locale(customer['locale'])
                    is_channel = customer['recipient_type'] == 'store_channel'
                    text, keyboard = stock.render(product, movement, event, code, store_channel=is_channel,
                                                  ui_icons=ui_icons, bot_username=self.bot_username)
                    photo = self._stock_photo(product) if is_channel and not sold_out else None
                    self.db.execute('''INSERT INTO public.stock_notification_recipients
                        (stock_notification_event_id,customer_id,recipient_type,telegram_id,locale,text,reply_markup,photo_path,created_at,updated_at)
                        VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s) ON CONFLICT DO NOTHING''',
                        (event['id'], customer['id'], customer['recipient_type'], str(customer['telegram_id']),
                         code, text, json.dumps(keyboard), photo, _now(), _now()))
                ready_channel = self.db.one('''SELECT telegram_chat_id FROM public.store_channel_settings
                    WHERE last_test_success=true ORDER BY id LIMIT 1''')
                channel_done = not ready_channel or self.db.one('''SELECT id FROM public.stock_notification_recipients
                    WHERE stock_notification_event_id=%s AND telegram_id=%s''', (event['id'], str(ready_channel['telegram_chat_id'])))
                if channel_done and self._complete_audience(*TABLES[STOCK], event['id']):
                    self.db.execute('''UPDATE public.stock_notification_events SET recipients_snapshotted_at=%s,updated_at=%s
                        WHERE id=%s''', (_now(), _now(), event['id']))
            rows = self._missing(STOCK, event['id'], limit)
            made = 0
            for recipient in rows:
                effect = {'telegram_chat_id': recipient['telegram_id'], 'locale': locale(recipient.get('locale')),
                          'telegram_method': 'sendPhoto' if recipient.get('photo_path') and len(recipient['text']) <= 1024 else 'sendMessage'}
                asset = self.repository.asset(recipient.get('photo_path'), self.bot_scope) if effect['telegram_method'] == 'sendPhoto' else None
                if effect['telegram_method'] == 'sendPhoto' and not asset:
                    # Missing explicit media admission defers materialization, not a terminal text substitution.
                    continue
                self._record(kind=STOCK, source=recipient['id'], chat=recipient['telegram_id'], target=recipient['recipient_type'],
                    code=effect['locale'], key=f"inventory:{movement_id}:{STOCK}:recipient:{recipient['id']}:v1",
                    rendered=stock.payload(recipient, effect, asset=asset), legacy=recipient)
                made += 1
            return made

    def payload(self, effect):
        kind = effect['effect_type']
        if (kind not in self.effect_types or effect['source_type'] != 'App\\Models\\' + MODELS[kind]
                or not self.recipients.permits(effect['telegram_chat_id'])):
            raise NotEligible()
        if kind == PRICE_CHANNEL:
            if effect['target_type'] != 'store_channel' or channel(self.db, self.recipients) != effect['telegram_chat_id']:
                raise NotEligible()
            event = self.db.one('SELECT * FROM public.product_price_change_events WHERE id=%s', (effect['source_id'],))
            if not event:
                raise NotEligible()
            retained = frozen(effect)
            if retained is not None:
                return retained
            event.update(ui_icons=icons(self.db), product_url=product_url(event['product_id'], self.bot_username))
            return price.payload(event, effect)
        table, foreign = TABLES[kind]
        recipient = self.db.one(f'SELECT * FROM public.{table} WHERE id=%s', (effect['source_id'],))
        if not recipient or str(recipient['telegram_id']) != effect['telegram_chat_id']:
            raise NotEligible()
        if kind == STOCK:
            if recipient['recipient_type'] != effect['target_type']:
                raise NotEligible()
            if effect['target_type'] == 'store_channel' and channel(self.db, self.recipients) != effect['telegram_chat_id']:
                raise NotEligible()
            movement = self.db.one('''SELECT m.* FROM public.inventory_movements m JOIN public.stock_notification_events n
                ON n.inventory_movement_id=m.id WHERE n.id=%s''', (recipient[foreign],))
            if not movement:
                raise NotEligible()
            product = self.repository.product(movement['product_id']) if movement['quantity'] < 0 else None
            if product and product['available_stock'] > 0:
                raise NotEligible()
            retained = frozen(effect)
            if retained is not None:
                return retained
            asset = self.repository.asset(recipient.get('photo_path'), self.bot_scope) if recipient.get('photo_path') else None
            return stock.payload(recipient, effect, asset=asset)
        if effect['target_type'] != 'customer':
            raise NotEligible()
        retained = frozen(effect)
        if retained is not None:
            return retained
        if kind == BROADCAST:
            source = self.db.one('SELECT * FROM public.telegram_broadcasts WHERE id=%s', (recipient[foreign],))
            if not source:
                raise NotEligible()
            source['ui_icons'] = icons(self.db)
            return broadcast.payload(source, effect)
        event = self.db.one('SELECT * FROM public.product_price_change_events WHERE id=%s', (recipient[foreign],))
        if not event:
            raise NotEligible()
        event['ui_icons'] = icons(self.db)
        return price.payload(event, effect)

    def project(self, effect):
        kind = effect['effect_type']
        if kind not in MODELS or effect['source_type'] != 'App\\Models\\' + MODELS[kind]:
            return 0
        with transaction.atomic(using=self.db.using):
            latest = self.db.one('''SELECT * FROM public.telegram_outbound_effects WHERE effect_type=%s
                AND source_type=%s AND source_id=%s AND state='sent'
                ORDER BY created_at DESC,id DESC LIMIT 1''', (kind, effect['source_type'], effect['source_id']))
            if latest:
                effect = latest
            at = _now()
            if kind == PRICE_CHANNEL:
                if effect['state'] == 'sent':
                    return self.db.execute('''UPDATE public.product_price_change_events SET store_channel_sent_at=%s,
                        store_channel_message_id=%s,store_channel_failed_at=NULL,store_channel_last_error=NULL,
                        store_channel_attempts=%s,updated_at=%s WHERE id=%s
                        AND (store_channel_sent_at IS DISTINCT FROM %s OR store_channel_attempts<>%s
                            OR store_channel_message_id IS DISTINCT FROM %s)''',
                        (effect['sent_at'], effect['telegram_message_id'], effect['attempts'], at, effect['source_id'],
                         effect['sent_at'], effect['attempts'], effect['telegram_message_id']))
                if effect['state'] not in ('failed', 'unknown', 'manual_review'):
                    return 0
                terminal = self.service.protocol.is_terminal_failure(effect)
                return self.db.execute('''UPDATE public.product_price_change_events SET
                    store_channel_failed_at=%s,store_channel_last_error=%s,store_channel_attempts=%s,updated_at=%s
                    WHERE id=%s AND store_channel_sent_at IS NULL''',
                    (effect.get('failed_at') if terminal else None,
                     'Delivery failed.' if effect['state'] == 'failed' else 'Delivery outcome requires review.',
                     effect['attempts'], at, effect['source_id']))
            table, foreign = TABLES[kind]
            if effect['state'] == 'sent':
                clear = ',terminal_failed_at=NULL' if kind in (BROADCAST, STOCK) else ''
                if kind == BROADCAST:
                    clear += ',retry_at=NULL'
                changed = self.db.execute(f'''UPDATE public.{table} SET sent_at=%s,failed_at=NULL,last_error=NULL,
                    attempts=%s,updated_at=%s {clear} WHERE id=%s AND telegram_id=%s
                    AND (sent_at IS DISTINCT FROM %s OR attempts<>%s)''',
                    (effect['sent_at'], effect['attempts'], at, effect['source_id'], effect['telegram_chat_id'], effect['sent_at'], effect['attempts']))
            elif effect['state'] in ('unknown', 'manual_review'):
                clear = ',terminal_failed_at=NULL' if kind in (BROADCAST, STOCK) else ''
                changed = self.db.execute(f'''UPDATE public.{table} SET failed_at=NULL,
                    last_error='Delivery outcome requires review.',attempts=%s,updated_at=%s {clear}
                    WHERE id=%s AND sent_at IS NULL AND telegram_id=%s
                    AND (last_error IS DISTINCT FROM 'Delivery outcome requires review.' OR attempts<>%s)''',
                    (effect['attempts'], at, effect['source_id'], effect['telegram_chat_id'], effect['attempts']))
            elif effect['state'] == 'failed':
                terminal = self.service.protocol.is_terminal_failure(effect)
                terminal_sql = ',terminal_failed_at=%s' if kind in (BROADCAST, STOCK) else ''
                params = [effect.get('failed_at') or at, 'Delivery failed.', effect['attempts'], at]
                if terminal_sql:
                    params.append(at if terminal else None)
                params.extend((effect['source_id'], effect['telegram_chat_id']))
                changed = self.db.execute(f'''UPDATE public.{table} SET failed_at=%s,last_error=%s,attempts=%s,updated_at=%s
                    {terminal_sql} WHERE id=%s AND sent_at IS NULL AND telegram_id=%s''', params)
            else:
                return 0
            recipient = self.db.one(f'SELECT {foreign} FROM public.{table} WHERE id=%s', (effect['source_id'],))
            if recipient and kind == BROADCAST:
                broadcast.project(self.db, recipient[foreign])
            if recipient and kind == STOCK:
                self._project_stock(recipient[foreign])
            return changed

    def _project_stock(self, event_id):
        # Null completeness under restricted admission keeps the historical full-audience handoff open.
        event = self.db.one('SELECT * FROM public.stock_notification_events WHERE id=%s FOR UPDATE', (event_id,))
        if not event or event['recipients_snapshotted_at'] is None:
            return
        remaining = self.db.one('''SELECT EXISTS(SELECT 1 FROM public.stock_notification_recipients r
            WHERE r.stock_notification_event_id=%s AND r.sent_at IS NULL AND r.terminal_failed_at IS NULL) AS remaining''', (event_id,))
        if not remaining['remaining']:
            self.db.execute('''UPDATE public.inventory_movements SET stock_notification_sent_at=COALESCE(stock_notification_sent_at,%s)
                WHERE id=%s''', (_now(), event['inventory_movement_id']))
