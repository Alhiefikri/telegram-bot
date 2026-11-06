"""Dispatch the immutable sanitized snapshot of an existing balance alert."""
import json
import re


def alert_payload(db, effect):
    if (effect['source_type'] != 'App\\Models\\BalanceOperationsAlertEpisode'
            or effect['target_type'] != 'admin'
            or effect['template_version'] != 'balance-operations-alert-v1'
            or effect['telegram_method'] != 'sendMessage'
            or re.fullmatch(r'[1-9][0-9]{0,18}', str(effect['telegram_chat_id'])) is None):
        raise ValueError('Invalid balance alert effect')
    episode = db.one('SELECT id FROM public.balance_operations_alert_episodes WHERE id::text=%s',
                     (effect['source_id'],))
    metadata = effect['outcome_metadata']
    if isinstance(metadata, str):
        metadata = json.loads(metadata)
    message = metadata.get('message') if isinstance(metadata, dict) else None
    if episode is None or type(message) is not str or not message or len(message) > 4096:
        raise ValueError('Balance alert snapshot unavailable')
    return {'chat_id': effect['telegram_chat_id'], 'text': message}
