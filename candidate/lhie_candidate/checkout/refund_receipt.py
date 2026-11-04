"""USDT refund family on the existing Telegram effect ledger and claim protocol."""
import json
from pathlib import Path
import re
from uuid import uuid4

from lhie_candidate.domain.money import Currency, Money
from lhie_candidate.receipt.jobs import wake_after_commit
from lhie_candidate.receipt.protocol import REFUND_TYPE, _now


_SOURCE = 'App\\Models\\CustomerUsdtBalanceEntry'
_TEMPLATE = 'usdt-refund-receipt-v1'
_COPY = {code: json.loads((Path(__file__).parents[1] / 'navigation' / (code + '.json')).read_text())
         for code in ('id', 'en', 'ms')}


def record_refund_receipt(db, entry, *, using='default'):
    db.require_transaction()
    source = db.one('''SELECT c.telegram_id,c.locale,o.invoice_number
        FROM public.customer_usdt_balance_accounts a JOIN public.customers c ON c.id=a.customer_id
        JOIN public.orders o ON o.id=%s AND o.customer_id=c.id WHERE a.id=%s''',
        (entry['refund_order_id'], entry['customer_usdt_balance_account_id']))
    if source is None or re.fullmatch(r'[1-9][0-9]{0,18}', str(source['telegram_id'])) is None:
        return None
    locale = source['locale'] if source['locale'] in _COPY else 'id'
    copy = _COPY[locale]
    amount = Money(Currency.USDT, entry['amount_micro']).format_decimal()
    message = '\n'.join((copy['balance.usdt.refund.title'],
        copy['balance.usdt.receipt.credit'].replace(':amount', amount), source['invoice_number'],
        copy['balance.usdt.receipt.reference'].replace(':reference', str(entry['id']))))
    key = f"usdt-refund:{entry['id']}:receipt:v1"
    at = _now()
    db.execute('''INSERT INTO public.telegram_outbound_effects
        (id,effect_key,effect_group_key,sequence,effect_type,source_type,source_id,target_type,
         telegram_chat_id,queue,telegram_method,locale,template_version,state,attempts,
         next_attempt_at,outcome_metadata,created_at,updated_at)
        VALUES (%s,%s,%s,1,%s,%s,%s,'customer',%s,'telegram-outbound','sendMessage',%s,%s,
            'pending',0,%s,%s,%s,%s) ON CONFLICT (effect_key) DO NOTHING''',
        (str(uuid4()), key, key, REFUND_TYPE, _SOURCE, str(entry['id']), str(source['telegram_id']),
         locale, _TEMPLATE, at, json.dumps({'delivery_context': 'usdt_refund_receipt', 'message': message}), at, at))
    effect = db.one('SELECT id FROM public.telegram_outbound_effects WHERE effect_key=%s', (key,))
    wake_after_commit('effect', str(effect['id']), using=using)
    return effect


def refund_payload(db, effect):
    if (effect['source_type'] != _SOURCE or effect['target_type'] != 'customer'
            or effect['template_version'] != _TEMPLATE or effect['telegram_method'] != 'sendMessage'):
        raise ValueError('Invalid refund receipt effect')
    entry = db.one('''SELECT e.id,c.telegram_id FROM public.customer_usdt_balance_entries e
        JOIN public.customer_usdt_balance_accounts a ON a.id=e.customer_usdt_balance_account_id
        JOIN public.customers c ON c.id=a.customer_id WHERE e.id::text=%s AND e.kind='refund_credit' ''',
        (effect['source_id'],))
    if entry is None or str(entry['telegram_id']) != effect['telegram_chat_id']:
        raise ValueError('Refund receipt owner changed')
    metadata = effect['outcome_metadata']
    if isinstance(metadata, str):
        metadata = json.loads(metadata)
    message = metadata.get('message')
    if type(message) is not str or not message or len(message) > 4096:
        raise ValueError('Invalid refund receipt message')
    return {'chat_id': effect['telegram_chat_id'], 'text': message}
