"""Notification family adapters; the shared protocol alone owns delivery state."""
from uuid import uuid4

from django.db import connections, transaction

from ..receipt.protocol import ReceiptProtocol, _now
from ..receipt.transport import ReceiptPreSendError
from .families import ADAPTERS, BULK_TYPES, LIMITS, MODELS
from .rate import BroadcastRate
from .policy import TERMINAL_CODES, NotEligible, TestRecipients


class Notifications:
    def __init__(self, transport, *, recipients=None, using='default', rate=None,
                 effect_types=None, bot_username=None, bot_scope='LhieStoreTestBot',
                 funding=None, qr_presentation=None, queue=None):
        self.recipients = recipients if recipients is not None else TestRecipients()
        selected = set(LIMITS) if effect_types is None else set(effect_types)
        if not selected or selected - LIMITS.keys():
            raise ValueError('Invalid notification family selection')
        if queue not in (None, 'notifications', 'bulk'):
            raise ValueError('Invalid notification worker queue')
        active = {kind: LIMITS[kind] for kind in LIMITS if kind in selected}
        self.protocol = ReceiptProtocol(using, lease_seconds=180, family_limits=active,
                                        terminal_codes=TERMINAL_CODES)
        self.db = self.protocol.db
        self.transport = transport
        self.bot_username, self.bot_scope = bot_username, bot_scope
        self.funding, self.qr_presentation = funding, qr_presentation
        self.queue = queue
        self.rate = rate if rate is not None else BroadcastRate()
        self._projection_cursor = None
        self.adapters = {}
        self._materializers = []
        for adapter_class, models, _ in ADAPTERS:
            kinds = selected & models.keys()
            if not kinds:
                continue
            adapter = adapter_class(self)
            self._materializers.append(adapter)
            self.adapters.update((kind, adapter) for kind in kinds)

    def _outside(self):
        if (connections[self.db.using].in_atomic_block
                or not connections[self.db.using].get_autocommit()):
            raise RuntimeError('Notification transport requires an outermost boundary')

    def record(self, *, key, kind, model, source, chat, target, locale, template,
               method='sendMessage', legacy=None):
        self.db.require_transaction()
        if (kind not in self.protocol.effect_types or MODELS[kind] != model
                or not self.recipients.permits(chat) or method not in ('sendMessage', 'sendPhoto')):
            raise NotEligible()
        queue = 'bulk' if kind in BULK_TYPES else 'notifications'
        if self.queue is not None and self.queue != queue:
            raise NotEligible()
        legacy = legacy or {}
        at = _now()
        # Import retained delivery history conservatively. An old in-flight
        # claim must not become a new pending send after migration.
        state = ('sent' if legacy.get('sent_at') else 'unknown' if
                 legacy.get('send_started_at') or legacy.get('claim_expires_at') else
                 'failed' if legacy.get('terminal_failed_at') or legacy.get('failed_at') else 'pending')
        terminal = bool(legacy.get('terminal_failed_at'))
        self.db.execute('''INSERT INTO public.telegram_outbound_effects
            (id,effect_key,effect_group_key,sequence,effect_type,source_type,source_id,target_type,
             telegram_chat_id,queue,telegram_method,locale,template_version,state,attempts,
             next_attempt_at,sent_at,unknown_at,failed_at,last_error_code,outcome_metadata,created_at,updated_at)
            VALUES (%s,%s,%s,1,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'{}',%s,%s)
            ON CONFLICT (effect_key) DO NOTHING''',
            (str(uuid4()), key, key, kind, 'App\\Models\\' + model, str(source), target, str(chat), queue, method,
             locale, template, state, legacy.get('attempts', 0),
             legacy.get('retry_at') or at if state in ('pending', 'failed') and not terminal else None,
             legacy.get('sent_at'), at if state == 'unknown' else None, legacy.get('failed_at'),
             'legacy_notification_terminal' if terminal else None, at, at))
        return self.db.one('SELECT * FROM public.telegram_outbound_effects WHERE effect_key=%s', (key,))

    def payload(self, effect):
        kind = effect['effect_type']
        if (kind not in self.adapters or effect['source_type'] != 'App\\Models\\' + MODELS[kind]
                or not self.recipients.permits(effect['telegram_chat_id'])):
            raise NotEligible()
        result = self.adapters[kind].payload(effect)
        if (not isinstance(result, dict) or str(result.get('chat_id')) != str(effect['telegram_chat_id'])
                or ('photo' in result) != (effect['telegram_method'] == 'sendPhoto')):
            raise NotEligible()
        return result

    def deliver(self, effect_id):
        self._outside()
        preview = self.db.one('SELECT * FROM public.telegram_outbound_effects WHERE id=%s', (effect_id,))
        if (not preview or preview['effect_type'] not in self.protocol.effect_types
                or not self.recipients.permits(preview['telegram_chat_id']) or self.queue is None
                or preview['queue'] != self.queue):
            return 'disabled'
        adapter = self.adapters.get(preview['effect_type'])
        if adapter is not None and hasattr(adapter, 'ready') and not adapter.ready(preview):
            return 'not_ready'
        if preview['effect_type'] in BULK_TYPES and not self.rate.acquire():
            return 'rate_limited'
        effect = self.protocol.claim(effect_id)
        if effect is None:
            return 'not_ready'
        token = effect['claim_token']
        try:
            payload = self.payload(effect)
        except NotEligible:
            row = self.protocol.finalize_failed(effect_id, token, 'notification_not_eligible')
            if row:
                self.project(row)
            return 'ineligible'
        except Exception:
            row = self.protocol.finalize_failed(effect_id, token, 'notification_render_invalid')
            if row:
                self.project(row)
            return 'render_failed'
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
        return row['state']

    def project(self, effect):
        """Read-model repair never invokes claim or transport."""
        kind = effect['effect_type']
        if kind not in self.adapters or effect['source_type'] != 'App\\Models\\' + MODELS.get(kind, ''):
            return 0
        return self.adapters[kind].project(effect)

    def recover(self, limit=25):
        self._outside()
        if type(limit) is not int or not 1 <= limit <= 100:
            raise ValueError('Invalid notification batch')
        if self.queue is None:
            return {'disabled': True}
        # Disabled composition is a true no-op, including no lease/projection writes.
        if not self.recipients.telegram:
            return {'disabled': True}
        chats = list(self.recipients.telegram)
        expired = self.protocol.recover_expired_leases(limit, chats=chats, queue=self.queue)
        projected = 0
        rows = self.db.rows('''SELECT * FROM public.telegram_outbound_effects
            WHERE effect_type=ANY(%s) AND telegram_chat_id=ANY(%s) AND queue=%s
              AND state IN ('sent','failed','unknown','manual_review')
              AND (%s::uuid IS NULL OR id>%s::uuid) ORDER BY id LIMIT %s''',
            (list(self.protocol.effect_types), chats, self.queue, self._projection_cursor, self._projection_cursor, limit))
        for effect in rows:
            projected += self.project(effect)
        self._projection_cursor = str(rows[-1]['id']) if rows else None
        materialized = sum(adapter.materialize(limit) for adapter in self._materializers)
        outcomes = {}
        for effect in self.protocol.ready_effects(limit, chats=chats, queue=self.queue):
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
        return {'expired_unknown': expired, 'projections_repaired': projected,
                'materialized': materialized, 'outcomes': outcomes}
