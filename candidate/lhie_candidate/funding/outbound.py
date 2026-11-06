"""Durable funding effects in the canonical Telegram outbound ledger."""
import json
from uuid import uuid4


def record_effect(db, topup_id, effect_type, key_part, target, chat, method, template, now, *, locale=None):
    db.require_transaction()
    key = f'balance-top-up:{topup_id}:{key_part}:v1'
    contexts = {
        'balance.top_up_instructions': 'balance_top_up',
        'balance.top_up_receipt': 'balance_top_up_receipt',
        'admin.balance_top_up_payment_proof': 'balance_top_up_payment_proof',
        'admin.balance_top_up_paid_notification': 'balance_top_up_paid_notification',
    }
    context = contexts[effect_type]
    db.execute('''INSERT INTO public.telegram_outbound_effects
        (id,effect_key,effect_group_key,sequence,effect_type,source_type,source_id,target_type,
         telegram_chat_id,queue,telegram_method,locale,template_version,state,attempts,next_attempt_at,
         outcome_metadata,created_at,updated_at)
        VALUES (%s,%s,%s,1,%s,'App\\Models\\StoreBalanceTopUp',%s,%s,%s,
                %s,%s,%s,%s,'pending',0,%s,%s::jsonb,%s,%s)
        ON CONFLICT (effect_key) DO NOTHING''',
        (str(uuid4()), key, key, effect_type, str(topup_id), target, str(chat),
         'telegram-outbound' if target == 'customer' else 'notifications', method, locale, template,
         now, json.dumps({'delivery_context': context, 'delivery_method': method}), now, now))


def record_paid(db, topup, customer, now):
    if customer and str(customer['telegram_id'] or '') == topup['chat_id']:
        record_effect(db, topup['id'], 'balance.top_up_receipt', topup['provider'] + '-receipt',
                      'customer', topup['chat_id'], 'sendMessage', 'balance-top-up-receipt-v1',
                      now, locale=topup['locale'] or customer['locale'])
    setting = db.one('''SELECT admin_telegram_chat_id FROM public.manual_payment_settings
        WHERE is_active=true ORDER BY id LIMIT 1''')
    chat = str(setting['admin_telegram_chat_id'] or '').strip() if setting else ''
    if chat:
        record_effect(db, topup['id'], 'admin.balance_top_up_paid_notification', 'admin-paid',
                      'admin', chat, 'sendMessage', 'balance-top-up-admin-paid:v1', now)
