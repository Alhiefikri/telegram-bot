"""Bounded deferred fan-out. The confirmed legacy broadcast is the durable handoff."""
import json

from django.db import transaction

from ..receipt.protocol import _now
from ..receipt.render import _tr
from .policy import BROADCAST, NotEligible


def markup(value, locale):
    if isinstance(value, str):
        value = json.loads(value)
    if not value:
        return {}
    if not isinstance(value, dict):
        raise NotEligible()
    if set(value) & {'id', 'en', 'ms'}:
        selected = value.get(locale, value.get('en', value.get('id', {})))
        return selected if isinstance(selected, dict) else {}
    return value


def payload(broadcast, effect):
    locale = effect['locale'] if effect['locale'] in ('id', 'en', 'ms') else 'en'
    text = broadcast['message']
    photo = broadcast.get('photo_file_id')
    if not isinstance(text, str) or (not photo and not text):
        raise NotEligible()
    result = {'chat_id': effect['telegram_chat_id'],
              'reply_markup': markup(broadcast.get('reply_markup'), locale), 'parse_mode': 'HTML'}
    if photo:
        result.update(photo=photo, caption=text)
    else:
        result['text'] = '\n'.join((_tr(locale, 'notification.broadcast.title'), '━━━━━━━━━━━━━━',
                                    text, '', _tr(locale, 'notification.broadcast.help')))
    return result


def materialize(db, recipients, broadcast_id, record, *, limit=100):
    if type(limit) is not int or not 1 <= limit <= 100:
        raise ValueError('Invalid broadcast slice')
    with transaction.atomic(using=db.using):
        broadcast = db.one('SELECT * FROM public.telegram_broadcasts WHERE id=%s FOR UPDATE', (broadcast_id,))
        if not broadcast:
            return 0
        # Test-only admission filters both discovery and send, without changing
        # the source audience or pretending that the full audience was processed.
        customers = db.rows('''SELECT c.id,c.telegram_id,c.locale FROM public.customers c
            WHERE c.telegram_id::text=ANY(%s) AND NOT EXISTS (
              SELECT 1 FROM public.telegram_broadcast_recipients r
              WHERE r.broadcast_id=%s AND r.telegram_id=c.telegram_id::text)
            ORDER BY c.id LIMIT %s''', (list(recipients.telegram), broadcast_id, limit))
        for customer in customers:
            at = _now()
            db.execute('''INSERT INTO public.telegram_broadcast_recipients
                (broadcast_id,customer_id,telegram_id,locale,created_at,updated_at)
                VALUES (%s,%s,%s,%s,%s,%s) ON CONFLICT DO NOTHING''',
                (broadcast_id, customer['id'], str(customer['telegram_id']),
                 customer['locale'] if customer['locale'] in ('id', 'en', 'ms') else 'en', at, at))
        rows = db.rows('''SELECT r.* FROM public.telegram_broadcast_recipients r
            WHERE r.broadcast_id=%s AND r.telegram_id=ANY(%s) AND NOT EXISTS (
              SELECT 1 FROM public.telegram_outbound_effects e
              WHERE e.effect_type=%s AND e.source_type='App\\Models\\TelegramBroadcastRecipient'
                AND e.source_id=r.id::text) ORDER BY r.id LIMIT %s''',
            (broadcast_id, list(recipients.telegram), BROADCAST, limit))
        for recipient in rows:
            photo = bool(broadcast.get('photo_file_id'))
            record(key=f"broadcast:{broadcast['broadcast_key']}:recipient:{recipient['id']}:v1",
                   kind=BROADCAST, model='TelegramBroadcastRecipient', source=recipient['id'],
                   chat=recipient['telegram_id'], target='customer', locale=recipient['locale'],
                   template='admin-broadcast-photo-v1' if photo else 'admin-broadcast-v1',
                   method='sendPhoto' if photo else 'sendMessage', legacy=recipient)
        return len(rows)


def project(db, recipients, broadcast_id):
    """Aggregate is a read projection. Unknown delivery never becomes completed."""
    with transaction.atomic(using=db.using):
        source = db.one('SELECT id,recipients_materialized_at,completed_at FROM public.telegram_broadcasts WHERE id=%s FOR UPDATE',
                        (broadcast_id,))
        if not source:
            return
        excluded = db.one('''SELECT 1 AS excluded FROM public.telegram_broadcast_recipients
            WHERE broadcast_id=%s AND NOT (telegram_id=ANY(%s)) LIMIT 1''',
            (broadcast_id, list(recipients.telegram)))
        if excluded and source['completed_at'] is not None:
            return
        counts = db.one('''SELECT count(*) AS total,
            count(*) FILTER (WHERE e.state='sent') AS sent,
            count(*) FILTER (WHERE e.state='failed') AS failed,
            count(*) FILTER (WHERE e.state='failed' AND (e.attempts>=5 OR
              e.last_error_code IN ('telegram_http_400','telegram_http_403',
                'notification_not_eligible','notification_render_invalid','legacy_notification_terminal'))) AS terminal,
            count(*) FILTER (WHERE e.state IN ('unknown','manual_review')) AS review
            FROM public.telegram_broadcast_recipients r LEFT JOIN LATERAL (
              SELECT state,attempts,last_error_code FROM public.telegram_outbound_effects e
              WHERE e.source_id=r.id::text AND e.source_type='App\\Models\\TelegramBroadcastRecipient'
                AND e.effect_type=%s ORDER BY e.created_at DESC,e.id DESC LIMIT 1
            ) e ON true WHERE r.broadcast_id=%s''', (BROADCAST, broadcast_id))
        pending = counts['total'] - counts['sent'] - counts['terminal']
        complete = (source['recipients_materialized_at'] is not None and not excluded
                    and pending == 0 and counts['review'] == 0)
        status = ('completed' if not counts['terminal'] else 'partially_failed' if counts['sent'] else 'failed') if complete else 'in_progress'
        db.execute('''UPDATE public.telegram_broadcasts SET recipient_count=%s,sent_count=%s,failed_count=%s,
            pending_count=%s,terminal_failed_count=%s,status=%s,completed_at=%s,updated_at=%s WHERE id=%s''',
            (counts['total'], counts['sent'], counts['failed'], pending, counts['terminal'], status,
             (source['completed_at'] or _now()) if complete else None, _now(), broadcast_id))
