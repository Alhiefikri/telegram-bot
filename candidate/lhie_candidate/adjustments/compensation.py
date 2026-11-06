"""Original-top-up compensation within an approved adjustment transaction."""
import hashlib
import json
from uuid import uuid4

from ..auth.types import legacy_now
from .password import AdjustmentRejected

TOPUP_COLUMNS = ('id,customer_id,amount,currency,status,provider_created_at,provider_reference,'
                 'provider,chat_id')


def eligible(db, topup, customer_id, amount):
    if (topup is None or topup['customer_id'] != customer_id or topup['amount'] != amount
            or topup['currency'] != 'IDR'):
        return False
    status = topup['status']
    if status not in ('expired', 'recovery_pending', 'invalid_provider_result') and not (
            status == 'failed' and (topup['provider_created_at'] is not None or topup['provider_reference'])):
        return False
    return (db.one('SELECT id FROM public.store_balance_compensations WHERE store_balance_top_up_id=%s', (topup['id'],)) is None
            and db.one("SELECT id FROM public.store_balance_entries WHERE source_type='balance_top_up' AND source_id=%s AND kind='top_up_credit'", (str(topup['id']),)) is None)


def apply(db, ledger, account, customer, preview):
    db.require_transaction()
    topup = db.one('SELECT ' + TOPUP_COLUMNS + ' FROM public.store_balance_top_ups WHERE id=%s FOR UPDATE', (preview['source_id'],))
    if not eligible(db, topup, customer['id'], preview['amount']):
        raise AdjustmentRejected('original_source_already_resolved')
    if str(topup['chat_id']) != str(customer['telegram_id']):
        raise AdjustmentRejected('original_top_up_notification_unavailable')
    source = 'top_up:' + str(topup['id'])
    key = 'owner-compensation:top-up:' + str(topup['id']) + ':v1'
    reference = topup['provider_reference'] or 'owner-adjustment:' + str(preview['id'])
    now = legacy_now()
    details = {'amount': topup['amount'], 'currency': 'IDR',
               'provider_reference_hash': hashlib.sha256(reference.encode()).hexdigest()}
    db.execute('''INSERT INTO public.external_money_receipt_reservations
        (source,legacy_type,legacy_id,provider,reason,details,reserved_at,created_at,updated_at)
        VALUES ('runtime','store_balance_top_up',%s,%s,'compensated_unresolved_money',%s,%s,%s,%s)
        ON CONFLICT (legacy_type,legacy_id,reason) DO NOTHING''',
        (str(topup['id']), topup['provider'], json.dumps(details), now, now, now))
    reservation = db.one("""SELECT resolved_at FROM public.external_money_receipt_reservations
        WHERE legacy_type='store_balance_top_up' AND legacy_id=%s AND reason='compensated_unresolved_money' FOR UPDATE""", (str(topup['id']),))
    if reservation is None or reservation['resolved_at'] is not None:
        raise AdjustmentRejected('original_source_already_resolved')
    if ledger._find_source_entry(account, 'owner_compensation_credit', preview['amount'], 'owner_compensation', source, key):
        raise AdjustmentRejected('original_source_already_resolved')
    before = account['balance']
    entry = ledger._post(account, 'owner_compensation_credit', preview['amount'], source, key, source_type='owner_compensation')
    compensation = db.one('''INSERT INTO public.store_balance_compensations
        (store_balance_top_up_id,customer_id,owner_user_id,ledger_entry_id,resolution_type,idempotency_key,
         provider_reference,reason,amount,resolved_at,created_at)
        VALUES (%s,%s,%s,%s,'owner_compensation',%s,%s,%s,%s,%s,%s) RETURNING *''',
        (topup['id'], customer['id'], preview['owner_user_id'], entry['id'], key, reference,
         preview['reason'], preview['amount'], now, now))
    db.execute("UPDATE public.store_balance_top_ups SET status='compensated',settled_at=COALESCE(settled_at,%s),updated_at=%s WHERE id=%s", (now, now, topup['id']))
    values = {'status': 'compensated', 'customer_id': customer['id'], 'amount': preview['amount'],
              'provider_reference': reference, 'reason': preview['reason'], 'ledger_entry_id': entry['id'],
              'compensation_id': compensation['id'], 'balance_before': before, 'balance_after': entry['balance_after']}
    db.execute('''INSERT INTO public.audit_logs(user_id,action,subject_type,subject_id,old_values,new_values,created_at)
        VALUES (%s,'store_balance.top_up.compensated','App\\Models\\StoreBalanceTopUp',%s,%s,%s,%s)''',
        (preview['owner_user_id'], str(topup['id']), json.dumps({'status': topup['status'], 'balance_before': before}), json.dumps(values), now))
    effect_key = f"balance-owner-compensation:{compensation['id']}:notification:v1"
    db.execute('''INSERT INTO public.telegram_outbound_effects
        (id,effect_key,effect_group_key,sequence,effect_type,source_type,source_id,target_type,telegram_chat_id,
         queue,telegram_method,locale,template_version,state,attempts,next_attempt_at,outcome_metadata,created_at,updated_at)
        VALUES (%s,%s,%s,1,'balance.owner_compensation_notification','App\\Models\\StoreBalanceCompensation',%s,
                'customer',%s,'telegram-outbound','sendMessage',%s,'balance-owner-compensation-v1','pending',0,%s,%s,%s,%s)
        ON CONFLICT (effect_key) DO NOTHING''',
        (str(uuid4()), effect_key, effect_key, str(compensation['id']), str(customer['telegram_id']), customer['locale'], now,
         json.dumps({'delivery_context': 'balance_owner_compensation', 'delivery_method': 'sendMessage'}), now, now))
    return entry
