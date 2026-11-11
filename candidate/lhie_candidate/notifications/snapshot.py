"""Immutable, non-credential presentation snapshots on the existing effect ledger."""
import json
from copy import deepcopy

from ..receipt.protocol import _now
from .policy import NotEligible


def metadata(effect):
    value = effect.get('outcome_metadata') or {}
    if isinstance(value, str):
        value = json.loads(value)
    if not isinstance(value, dict):
        raise NotEligible()
    return value


def frozen_payload(effect):
    payload = metadata(effect).get('notification_payload')
    method = effect['telegram_method']
    field = 'caption' if method == 'sendPhoto' else 'text'
    if (not isinstance(payload, dict) or str(payload.get('chat_id')) != str(effect['telegram_chat_id'])
            or method not in ('sendMessage', 'sendPhoto') or not isinstance(payload.get(field), str)
            or not payload[field] or (method == 'sendPhoto' and not payload.get('photo'))):
        raise NotEligible()
    return deepcopy(payload)


def record_snapshot(service, *, key, kind, model, source, chat, template, payload,
                    target='admin', locale='id', legacy=None, sequence=1, supersedes=None, extra=None):
    """Caller locks the durable source; no snapshot is replaced on replay."""
    service.db.require_transaction()
    if not service.recipients.permits(chat) or str(payload.get('chat_id')) != str(chat):
        raise NotEligible()
    method = 'sendPhoto' if 'photo' in payload else 'sendMessage'
    effect = service.record(key=key, kind=kind, model=model, source=source, chat=chat,
                            target=target, locale=locale, template=template,
                            method=method, legacy=legacy)
    if effect is None:
        return None
    if (effect['effect_type'] != kind or effect['source_type'] != 'App\\Models\\' + model
            or effect['source_id'] != str(source) or effect['telegram_chat_id'] != str(chat)
            or effect['target_type'] != target or effect['telegram_method'] != method):
        raise NotEligible()
    value = metadata(effect)
    if 'notification_payload' not in value and effect['state'] in ('pending', 'failed'):
        value.update(extra or {})
        value['notification_payload'] = payload
        service.db.execute('''UPDATE public.telegram_outbound_effects
            SET outcome_metadata=%s::json,sequence=%s,supersedes_effect_id=%s,updated_at=%s
            WHERE id=%s AND NOT (COALESCE(outcome_metadata,'{}'::json)::jsonb ? 'notification_payload')
              AND state IN ('pending','failed')''',
            (json.dumps(value, ensure_ascii=False), sequence, supersedes, _now(), effect['id']))
        effect = service.db.one('SELECT * FROM public.telegram_outbound_effects WHERE id=%s', (effect['id'],))
    return effect


def latest_sent(db, effect):
    if (effect.get('state') != 'sent' or not effect.get('sent_at')
            or isinstance(effect.get('telegram_message_id'), bool)
            or not str(effect.get('telegram_message_id') or '').isdigit()
            or int(effect['telegram_message_id']) <= 0):
        return False
    row = db.one('''SELECT id FROM public.telegram_outbound_effects
        WHERE source_type=%s AND source_id=%s AND effect_type=%s AND state='sent'
          AND telegram_message_id IS NOT NULL
        ORDER BY sequence DESC,created_at DESC,id DESC LIMIT 1''',
        (effect['source_type'], effect['source_id'], effect['effect_type']))
    return row is not None and str(row['id']) == str(effect['id'])
