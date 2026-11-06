"""Durable, source-bound USDT funding receipts on the existing effect ledger."""
import json
from pathlib import Path
import re
from uuid import uuid4

from ..domain.money import Currency, Money
from ..receipt.jobs import wake_after_commit
from ..receipt.protocol import TOPUP_TYPE, _now

_SOURCE = 'App\\Models\\CustomerUsdtTopUp'
_TEMPLATE = 'usdt-top-up-receipt-v1'
_COPY = {code: json.loads((Path(__file__).parents[1] / 'navigation' / (code + '.json')).read_text())
         for code in ('id', 'en', 'ms')}


def _source(db, target_id):
    return db.one('''SELECT t.id,t.reference,t.customer_id,t.amount_micro,t.fee_micro,t.rail,
            r.kind,r.entry_id,r.late,rc.amount_units,rc.decimals,e.amount_micro AS credit_micro,
            e.balance_after_micro,c.telegram_id,c.locale
        FROM public.customer_usdt_top_ups t
        JOIN public.customer_usdt_top_up_resolutions r ON r.top_up_id=t.id
        JOIN public.customer_usdt_balance_entries e ON e.id=r.entry_id
          AND e.kind='funding_credit' AND e.source_type='customer_usdt_top_up' AND e.source_id=t.id::text
        JOIN public.customer_usdt_balance_accounts a ON a.id=e.customer_usdt_balance_account_id
          AND a.customer_id=t.customer_id
        JOIN public.customers c ON c.id=t.customer_id
        LEFT JOIN public.external_money_receipts rc ON rc.id=r.receipt_id
        WHERE t.id=%s AND r.kind IN ('settled','resolved_by_compensation')''', (str(target_id),))


def _format(amount):
    return Money(Currency.USDT, amount).format_decimal()


def _received(source):
    try:
        return Money.from_raw_usdt_units(str(source['amount_units']), source['decimals']).units
    except (TypeError, ValueError):
        return None


def record_topup_receipt(db, target, entry, *, late, using='default'):
    db.require_transaction()
    source = _source(db, target['id'])
    if (source is None or source['entry_id'] != entry['id'] or source['late'] != late
            or re.fullmatch(r'[1-9][0-9]{0,18}', str(source['telegram_id'])) is None):
        raise ValueError('USDT top-up receipt source unavailable')
    locale = source['locale'] if source['locale'] in _COPY else 'id'
    copy = _COPY[locale]
    def line(key, **values):
        result = copy[key]
        for name, value in values.items():
            result = result.replace(':' + name, str(value))
        return result
    usd = source['rail'] == 'binance_pay'
    amount, credit = source['amount_micro'], source['credit_micro']
    received = _received(source)
    lines = [copy['balance.usdt.receipt.title'], line('balance.usdt.receipt.submitted', amount=_format(amount))]
    if received is not None:
        lines.append(line('balance.usdt.receipt.received', amount=_format(received)))
    lines.append(line('balance.usdt.receipt.usd_credit' if usd else 'balance.usdt.receipt.credit', amount=_format(credit)))
    if source['fee_micro']:
        lines.append(line('balance.usdt.status.fee', amount=_format(source['fee_micro'])))
    lines.extend((line('balance.usdt.receipt.balance_before_usd' if usd else 'balance.usdt.receipt.balance_before',
                       amount=_format(source['balance_after_micro'] - credit)),
                  line('balance.usdt.receipt.balance_after_usd' if usd else 'balance.usdt.receipt.balance_after',
                       amount=_format(source['balance_after_micro'])),
                  line('balance.usdt.receipt.reference', reference=source['reference'])))
    if credit != amount:
        lines.append(line('balance.usdt.receipt.revised', original=_format(amount), approved=_format(credit)))
    if late:
        lines.append(copy['balance.usdt.receipt.late'])
    message = '\n'.join(lines)
    key, at = f"usdt-top-up:{target['id']}:receipt:v1", _now()
    metadata = {'delivery_context': 'usdt_top_up_receipt', 'message': message}
    db.execute('''INSERT INTO public.telegram_outbound_effects
        (id,effect_key,effect_group_key,sequence,effect_type,source_type,source_id,target_type,
         telegram_chat_id,queue,telegram_method,locale,template_version,state,attempts,
         next_attempt_at,outcome_metadata,created_at,updated_at)
        VALUES (%s,%s,%s,1,%s,%s,%s,'customer',%s,'telegram-outbound','sendMessage',%s,%s,
            'pending',0,%s,%s,%s,%s) ON CONFLICT (effect_key) DO NOTHING''',
        (str(uuid4()), key, key, TOPUP_TYPE, _SOURCE, str(target['id']), str(source['telegram_id']),
         locale, _TEMPLATE, at, json.dumps(metadata), at, at))
    effect = db.one('SELECT * FROM public.telegram_outbound_effects WHERE effect_key=%s', (key,))
    existing = effect['outcome_metadata']
    if isinstance(existing, str):
        existing = json.loads(existing)
    if (effect['effect_type'] != TOPUP_TYPE or effect['source_type'] != _SOURCE
            or effect['source_id'] != str(target['id']) or effect['telegram_chat_id'] != str(source['telegram_id'])
            or effect['target_type'] != 'customer' or effect['template_version'] != _TEMPLATE
            or existing.get('message') != message):
        raise ValueError('USDT top-up receipt identity conflict')
    wake_after_commit('effect', str(effect['id']), using=using)
    return effect


def topup_payload(db, effect):
    if (effect['source_type'] != _SOURCE or effect['target_type'] != 'customer'
            or effect['template_version'] != _TEMPLATE or effect['telegram_method'] != 'sendMessage'):
        raise ValueError('Invalid top-up receipt effect')
    source = _source(db, effect['source_id'])
    if source is None or str(source['telegram_id']) != effect['telegram_chat_id']:
        raise ValueError('Top-up receipt owner changed')
    metadata = effect['outcome_metadata']
    if isinstance(metadata, str):
        metadata = json.loads(metadata)
    message = metadata.get('message')
    if type(message) is not str or not message or len(message) > 4096:
        raise ValueError('Invalid top-up receipt message')
    return {'chat_id': effect['telegram_chat_id'], 'text': message}
