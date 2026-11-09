"""Notification family adapters; the shared protocol alone owns delivery state."""
import json
from uuid import uuid4

from django.db import connections, transaction

from ..receipt.protocol import ReceiptProtocol, _now
from ..receipt.transport import ReceiptPreSendError
from . import broadcast, price
from .rate import BroadcastRate
from .proof_publication import PublicationUnavailable
from .policy import (ADJUSTMENT, BROADCAST, LIMITS, MANUAL, PRICE, PRICE_CHANNEL, PROOF, STOCK,
                     ORDER_TYPES, BALANCE_TYPES, TERMINAL_CODES, NotEligible, TestRecipients)


MODELS = {BROADCAST: 'TelegramBroadcastRecipient', STOCK: 'StockNotificationRecipient',
          PRICE: 'ProductPriceChangeNotificationRecipient', PROOF: 'Order',
          MANUAL: 'Payment', ADJUSTMENT: 'BalanceAdjustmentRequest',
          PRICE_CHANNEL: 'ProductPriceChangeEvent',
          **{kind: 'StoreBalanceTopUp' for kind in BALANCE_TYPES},
          **{kind: 'Order' for kind in ORDER_TYPES[:-1]}, ORDER_TYPES[-1]: 'ManualFulfillmentReview'}
RECIPIENT_TABLES = {BROADCAST: 'telegram_broadcast_recipients',
                    STOCK: 'stock_notification_recipients',
                    PRICE: 'product_price_change_notification_recipients'}


class Notifications:
    def __init__(self, transport, *, recipients=None, using='default', proof=None, rate=None, families=None, order_notices=None):
        self.recipients = recipients if recipients is not None else TestRecipients()
        active = {kind: limit for kind, limit in LIMITS.items() if (kind != PROOF or proof is not None)
                  and (kind not in ORDER_TYPES or order_notices is not None)}
        if families is not None:
            if not families or not set(families) <= set(active):
                raise ValueError('Invalid notification worker families')
            active = {kind: limit for kind, limit in active.items() if kind in families}
        self.protocol = ReceiptProtocol(using, lease_seconds=180, family_limits=active,
                                        terminal_codes=TERMINAL_CODES)
        self.db = self.protocol.db
        self.transport, self.proof = transport, proof
        self.order_notices = order_notices
        self.rate = rate if rate is not None else BroadcastRate()
        self._projection_cursor = None
        self._broadcast_cursor = 0
        self.price_notifications = price.PriceNotifications(self.db, self.recipients)
        from .stock import StockNotifications
        self.stock_notifications = StockNotifications(self.db, self.recipients)
        from .balance import BalanceNotifications
        self.balance_notifications = BalanceNotifications(self.db, self.recipients)

    def _outside(self):
        if (connections[self.db.using].in_atomic_block
                or not connections[self.db.using].get_autocommit()):
            raise RuntimeError('Notification transport requires an outermost boundary')

    def record(self, *, key, kind, model, source, chat, target, locale, template,
               method='sendMessage', legacy=None, supersedes=None):
        self.db.require_transaction()
        if (kind not in self.protocol.effect_types or MODELS[kind] != model or not self.recipients.permits(chat)
                or method not in ('sendMessage', 'sendPhoto')):
            raise NotEligible()
        legacy = legacy or {}
        sequence = 1
        if supersedes is not None:
            predecessor = self.db.one('SELECT * FROM public.telegram_outbound_effects WHERE id=%s FOR UPDATE', (supersedes,))
            if (not predecessor or predecessor['effect_type'] != kind
                    or predecessor['source_type'] != 'App\\Models\\' + model
                    or predecessor['source_id'] != str(source)
                    or predecessor['telegram_chat_id'] != str(chat)
                    or predecessor['state'] not in ('sent', 'unknown', 'manual_review')):
                raise NotEligible()
            sequence = predecessor['sequence'] + 1
        at = _now()
        # Import retained delivery history conservatively. An old in-flight
        # claim must not become a new pending send after migration.
        state = ('sent' if legacy.get('sent_at') else 'unknown' if
                 legacy.get('status') in ('sending', 'unknown', 'manual_review') or
                 legacy.get('send_started_at') or legacy.get('claim_expires_at') else
                  'failed' if legacy.get('terminal_failed_at') or legacy.get('failed_at') or legacy.get('status') == 'failed' else 'pending')
        terminal = bool(legacy.get('terminal_failed_at'))
        self.db.execute('''INSERT INTO public.telegram_outbound_effects
            (id,effect_key,effect_group_key,sequence,effect_type,source_type,source_id,target_type,
             telegram_chat_id,queue,telegram_method,locale,template_version,state,attempts,
              next_attempt_at,sent_at,unknown_at,failed_at,last_error_code,telegram_message_id,supersedes_effect_id,outcome_metadata,created_at,updated_at)
              VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,'notifications',%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s::jsonb,%s,%s)
            ON CONFLICT (effect_key) DO NOTHING''',
            (str(uuid4()), key, key, sequence, kind, 'App\\Models\\' + model, str(source), target, str(chat), method,
             locale, template, state, legacy.get('attempts', 0),
             legacy.get('retry_at') or at if state in ('pending', 'failed') and not terminal else None,
             legacy.get('sent_at'), at if state == 'unknown' else None, legacy.get('failed_at'),
              'legacy_notification_terminal' if terminal else None, legacy.get('telegram_message_id'), supersedes,
              json.dumps({'delivery_context': 'order_lifecycle'} if kind in ORDER_TYPES else {}), at, at))
        return self.db.one('SELECT * FROM public.telegram_outbound_effects WHERE effect_key=%s', (key,))

    def _recipient(self, effect, table):
        # table comes exclusively from the closed family map above.
        row = self.db.one(f'SELECT * FROM public.{table} WHERE id=%s', (effect['source_id'],))
        if not row or str(row['telegram_id']) != effect['telegram_chat_id']:
            raise NotEligible()
        return row

    def payload(self, effect):
        kind = effect['effect_type']
        if (kind not in MODELS or effect['source_type'] != 'App\\Models\\' + MODELS[kind]
                or not self.recipients.permits(effect['telegram_chat_id'])):
            raise NotEligible()
        if kind in ORDER_TYPES and self.order_notices is not None:
            return self.order_notices.payload(effect)
        if kind in BALANCE_TYPES:
            return self.balance_notifications.payload(effect)
        if kind == BROADCAST:
            row = self._recipient(effect, RECIPIENT_TABLES[kind])
            source = self.db.one('SELECT * FROM public.telegram_broadcasts WHERE id=%s', (row['broadcast_id'],))
            if not source or effect['target_type'] != 'customer':
                raise NotEligible()
            result = broadcast.payload(source, effect)
            if ('photo' in result) != (effect['telegram_method'] == 'sendPhoto'):
                raise NotEligible()
            return result
        if kind == STOCK:
            row = self._recipient(effect, RECIPIENT_TABLES[kind])
            from .html import telegram_entities
            from ..navigation.repository import CatalogRepository
            movement = self.db.one('''SELECT m.product_id,m.quantity,m.became_unavailable
                FROM public.stock_notification_events e JOIN public.inventory_movements m ON m.id=e.inventory_movement_id
                WHERE e.id=%s''', (row['stock_notification_event_id'],))
            if movement and movement['quantity'] < 0 and movement['became_unavailable']:
                product = CatalogRepository(self.db.using).product(movement['product_id'])
                if product and product['available_stock'] > 0:
                    raise NotEligible()
            if effect['target_type'] != row['recipient_type']:
                raise NotEligible()
            if effect['target_type'] == 'store_channel':
                setting = self.db.one("SELECT telegram_chat_id,last_test_success FROM public.store_channel_settings WHERE singleton_key='store_channel'")
                if not setting or not setting['last_test_success'] or not self.recipients.permits(setting['telegram_chat_id']):
                    raise NotEligible()
            if effect['telegram_method'] == 'sendPhoto':
                from ..storefront.public_media import open_image
                if not row.get('photo_path'):
                    raise NotEligible()
                text, entities = telegram_entities(row['text'])
                stream, _, _ = open_image(row['photo_path'])
                with stream:
                    image = stream.read()
                return {'chat_id': effect['telegram_chat_id'], 'photo': image, 'caption': text,
                        'caption_entities': entities,
                        'reply_markup': broadcast.markup(row['reply_markup'], effect['locale'])}
            return {'chat_id': effect['telegram_chat_id'], 'text': row['text'],
                    'reply_markup': broadcast.markup(row['reply_markup'], effect['locale']), 'parse_mode': 'HTML'}
        if kind == MANUAL:
            from .payment_proof import payload
            return payload(self.db, effect)
        if kind == PRICE:
            row = self._recipient(effect, RECIPIENT_TABLES[kind])
            event = self.db.one('SELECT * FROM public.product_price_change_events WHERE id=%s', (row['event_id'],))
            if not event or effect['target_type'] != 'customer':
                raise NotEligible()
            return price.payload(event, effect, icons=self.db.rows('SELECT source_emoji,fallback_emoji,custom_emoji_id FROM public.telegram_ui_icons'))
        if kind == PRICE_CHANNEL:
            event = self.db.one('SELECT * FROM public.product_price_change_events WHERE id=%s', (effect['source_id'],))
            setting = self.db.one("SELECT telegram_chat_id,channel_username FROM public.store_channel_settings WHERE singleton_key='store_channel'")
            chat = str((setting or {}).get('telegram_chat_id') or (setting or {}).get('channel_username') or '').strip()
            if not event or effect['target_type'] != 'store_channel' or not self.recipients.permits(chat):
                raise NotEligible()
            return price.payload(event, effect, icons=self.db.rows('SELECT source_emoji,fallback_emoji,custom_emoji_id FROM public.telegram_ui_icons'))
        if kind == ADJUSTMENT:
            metadata = effect.get('outcome_metadata') or {}
            if isinstance(metadata, str):
                metadata = json.loads(metadata)
            if (metadata.get('delivery_context') != 'balance_adjustment'
                    or not isinstance(metadata.get('message'), str) or not metadata['message']):
                raise NotEligible()
            return {'chat_id': effect['telegram_chat_id'], 'text': metadata['message']}
        if kind == PROOF and self.proof is not None:
            return self.proof.payload(effect)
        # Unported layouts stay blocked. Do not invent a replacement message
        # for immutable historical snapshots or activate an unqualified adapter.
        raise NotEligible()

    def deliver(self, effect_id):
        self._outside()
        preview = self.db.one('SELECT effect_type,telegram_chat_id FROM public.telegram_outbound_effects WHERE id=%s', (effect_id,))
        if (not preview or preview['effect_type'] not in self.protocol.effect_types
                or not self.recipients.permits(preview['telegram_chat_id'])):
            return 'disabled'
        if preview['effect_type'] == PROOF and self.proof is not None:
            self.proof.prepare(effect_id)
        if preview['effect_type'] in ORDER_TYPES and self.order_notices is not None:
            pending = self.db.one('SELECT * FROM public.telegram_outbound_effects WHERE id=%s', (effect_id,))
            if not self.order_notices.prepare(pending):
                return 'waiting_outcome'
        if preview['effect_type'] in (BROADCAST, STOCK, PRICE, PRICE_CHANNEL) and not self.rate.acquire():
            return 'rate_limited'
        effect = self.protocol.claim(effect_id)
        if effect is None:
            return 'not_ready'
        token = effect['claim_token']
        try:
            payload = self.payload(effect)
        except PublicationUnavailable:
            self.protocol.finalize_failed(effect_id, token, 'telegram_delivery_failed', retry_after_seconds=60)
            return 'deferred'
        except NotEligible:
            self.protocol.finalize_failed(effect_id, token, 'notification_not_eligible')
            return 'ineligible'
        except Exception:
            self.protocol.finalize_failed(effect_id, token, 'notification_render_invalid')
            return 'render_failed'
        if effect['effect_type'] in ORDER_TYPES[1:3] and self.order_notices is not None:
            self.order_notices.cleanup(effect['source_id'])
        try:
            result = self.transport.execute(effect['telegram_method'], payload)
        except ReceiptPreSendError:
            row = self.protocol.finalize_failed(effect_id, token, 'notification_render_invalid')
        except Exception:
            row = self.protocol.finalize_unknown(effect_id, token)
        else:
            if result.outcome == 'success' and type(result.message_id) is int and result.message_id > 0:
                row = self.protocol.finalize_sent(effect_id, token, result.message_id)
            elif result.outcome == 'definite':
                row = self.protocol.finalize_failed(effect_id, token, result.error_code or 'telegram_delivery_failed',
                                                     retry_after_seconds=result.retry_after_seconds)
            else:
                row = self.protocol.finalize_unknown(effect_id, token)
        if not row:
            return 'fenced'
        self.project(row)
        self._aggregate(row)
        if (row['state'] == 'sent' and row['effect_type'] in ORDER_TYPES[1:3]
                and self.order_notices is not None):
            self.order_notices.cleanup(row['source_id'])
        return row['state']

    def _aggregate(self, effect):
        if effect['effect_type'] == BROADCAST:
            recipient = self.db.one('SELECT broadcast_id FROM public.telegram_broadcast_recipients WHERE id=%s',
                                    (effect['source_id'],))
            if recipient:
                broadcast.project(self.db, recipient['broadcast_id'])

    def project(self, effect):
        """Read-model repair never invokes claim or transport."""
        kind = effect['effect_type']
        if effect['source_type'] != 'App\\Models\\' + MODELS.get(kind, ''):
            return 0
        at = _now()
        if kind in ORDER_TYPES and self.order_notices is not None:
            return self.order_notices.project(effect)
        if kind in RECIPIENT_TABLES:
            table = RECIPIENT_TABLES[kind]
            if effect['state'] == 'sent':
                clear = ',terminal_failed_at=NULL' if kind in (BROADCAST, STOCK) else ''
                if kind == BROADCAST:
                    clear += ',retry_at=NULL'
                return self.db.execute(f'''UPDATE public.{table} SET sent_at=%s,failed_at=NULL,
                    last_error=NULL,attempts=%s,updated_at=%s {clear} WHERE id=%s AND sent_at IS NULL
                    AND telegram_id=%s''',
                    (effect['sent_at'], effect['attempts'], at, effect['source_id'], effect['telegram_chat_id']))
            if effect['state'] in ('unknown', 'manual_review'):
                return self.db.execute(f'''UPDATE public.{table} SET failed_at=NULL,
                    last_error='Delivery outcome requires review.',attempts=%s,updated_at=%s
                    WHERE id=%s AND sent_at IS NULL AND telegram_id=%s
                      AND (last_error IS DISTINCT FROM 'Delivery outcome requires review.' OR attempts<>%s)''',
                    (effect['attempts'], at, effect['source_id'], effect['telegram_chat_id'], effect['attempts']))
        if kind == MANUAL and effect['state'] == 'sent':
            return self.db.execute('''UPDATE public.payments SET payment_proof_forwarded_at=%s,
                updated_at=%s WHERE id=%s AND payment_proof_forwarded_at IS NULL
                AND payment_proof_admin_chat_id=%s''',
                (effect['sent_at'], at, effect['source_id'], effect['telegram_chat_id']))
        if kind == PRICE_CHANNEL and effect['state'] in ('sent', 'unknown', 'manual_review'):
            return self.db.execute('''UPDATE public.product_price_change_events SET store_channel_sent_at=%s,
                store_channel_message_id=%s,store_channel_failed_at=NULL,store_channel_last_error=%s,
                store_channel_attempts=%s,updated_at=%s WHERE id=%s AND store_channel_sent_at IS NULL''',
                (effect['sent_at'] if effect['state'] == 'sent' else None, effect['telegram_message_id'],
                 None if effect['state'] == 'sent' else 'Delivery outcome requires review.', effect['attempts'], at, effect['source_id']))
        if kind == PROOF and effect['state'] in ('sent', 'unknown', 'manual_review'):
            return self.db.execute('''UPDATE public.sales_proof_posts SET status=%s,
                telegram_message_id=%s,sent_at=%s,attempts=%s,last_error=%s,updated_at=%s
                WHERE order_id=%s AND (status,telegram_message_id,sent_at,attempts,last_error)
                  IS DISTINCT FROM (%s,%s,%s,%s,%s)
                AND NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects newer
                    WHERE newer.source_type=%s AND newer.source_id=%s AND newer.effect_type=%s
                      AND newer.sequence>%s AND newer.state='sent') ''',
                (effect['state'], effect['telegram_message_id'], effect['sent_at'], effect['attempts'],
                 None if effect['state'] == 'sent' else 'Delivery outcome requires review.', at, effect['source_id'],
                 effect['state'], effect['telegram_message_id'], effect['sent_at'], effect['attempts'],
                 None if effect['state'] == 'sent' else 'Delivery outcome requires review.',
                 effect['source_type'], effect['source_id'], kind, effect['sequence']))
        if kind == PROOF and effect['state'] == 'failed':
            return self.db.execute('''UPDATE public.sales_proof_posts SET status='failed',attempts=%s,
                last_error=%s,updated_at=%s WHERE order_id=%s AND status NOT IN ('sent','unknown','manual_review')
                  AND (status,attempts,last_error) IS DISTINCT FROM ('failed',%s,%s)''',
                (effect['attempts'], effect['last_error_code'], at, effect['source_id'],
                 effect['attempts'], effect['last_error_code']))
        return 0

    def recover(self, limit=25):
        self._outside()
        if type(limit) is not int or not 1 <= limit <= 100:
            raise ValueError('Invalid notification batch')
        # Disabled composition is a true no-op, including no lease/projection writes.
        if not self.recipients.telegram:
            return {'disabled': True}
        chats = list(self.recipients.telegram)
        expired = self.protocol.recover_expired_leases(limit, chats=chats)
        projected = 0
        rows = self.db.rows('''SELECT * FROM public.telegram_outbound_effects
            WHERE effect_type=ANY(%s) AND telegram_chat_id=ANY(%s)
              AND state IN ('sent','unknown','manual_review')
              AND (%s::uuid IS NULL OR id>%s::uuid) ORDER BY id LIMIT %s''',
            (list(self.protocol.effect_types), chats, self._projection_cursor, self._projection_cursor, limit))
        for effect in rows:
            projected += self.project(effect)
            self._aggregate(effect)
        self._projection_cursor = str(rows[-1]['id']) if rows else None
        sources = self.db.rows('''SELECT id FROM public.telegram_broadcasts
            WHERE recipients_materialized_at IS NULL AND id>%s ORDER BY id LIMIT %s''',
            (self._broadcast_cursor, limit)) if BROADCAST in self.protocol.effect_types else ()
        self._broadcast_cursor = sources[-1]['id'] if sources else 0
        for source in sources:
            broadcast.materialize(self.db, self.recipients, source['id'], self.record)
        if PRICE in self.protocol.effect_types or PRICE_CHANNEL in self.protocol.effect_types:
            self.price_notifications.recover(self.record, limit, channel_enabled=PRICE_CHANNEL in self.protocol.effect_types)
        if STOCK in self.protocol.effect_types:
            self.stock_notifications.recover(limit)
        self.materialize_recipients(limit)
        if PROOF in self.protocol.effect_types and self.proof is not None:
            self.proof.discover(self.record, limit)
        if self.order_notices is not None:
            self.order_notices.discover(self.record, limit)
            self.order_notices.recover_results(self.record, limit)
        if set(BALANCE_TYPES) <= set(self.protocol.effect_types):
            self.balance_notifications.recover(self.record, limit)
        outcomes = {}
        for effect in self.protocol.ready_effects(limit, chats=chats):
            result = self.deliver(effect['id'])
            outcomes[result] = outcomes.get(result, 0) + 1
            if result == 'rate_limited':
                break
            # Per-recipient 429 scheduling is owned by the protocol; stop this
            # batch so a rejected burst is not continued against other recipients.
            if result == 'failed':
                latest = self.db.one('SELECT last_error_code FROM public.telegram_outbound_effects WHERE id=%s', (effect['id'],))
                if latest and latest['last_error_code'] == 'telegram_http_429':
                    break
        return {'expired_unknown': expired, 'projections_repaired': projected, 'outcomes': outcomes}

    def materialize_recipients(self, limit):
        """Recover missing effects from existing immutable stock/price snapshots."""
        for kind in (STOCK, PRICE):
            if kind not in self.protocol.effect_types:
                continue
            table = RECIPIENT_TABLES[kind]
            rows = self.db.rows(f'''SELECT r.* FROM public.{table} r
                WHERE r.telegram_id=ANY(%s) AND NOT EXISTS (
                  SELECT 1 FROM public.telegram_outbound_effects e
                  WHERE e.effect_type=%s AND e.source_type=%s AND e.source_id=r.id::text)
                ORDER BY r.id LIMIT %s''',
                (list(self.recipients.telegram), kind, 'App\\Models\\' + MODELS[kind], limit))
            for recipient in rows:
                with transaction.atomic(using=self.db.using):
                    if kind == STOCK:
                        source = self.db.one('''SELECT inventory_movement_id FROM public.stock_notification_events
                            WHERE id=%s''', (recipient['stock_notification_event_id'],))
                        if not source:
                            continue
                        key = f"inventory:{source['inventory_movement_id']}:{STOCK}:recipient:{recipient['id']}:v1"
                        target = recipient['recipient_type']
                    else:
                        key = f"price-change:{recipient['event_id']}:recipient:{recipient['id']}:v1"
                        target = 'customer'
                    self.record(key=key, kind=kind, model=MODELS[kind], source=recipient['id'],
                                chat=recipient['telegram_id'], target=target,
                                locale=recipient.get('locale') or 'en', template='notification-v1', legacy=recipient,
                                method='sendPhoto' if kind == STOCK and recipient.get('photo_path') and len(recipient['text']) <= 1024 else 'sendMessage')
