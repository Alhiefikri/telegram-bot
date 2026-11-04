"""Read a committed physical receipt without rewriting its rail representation."""
import hashlib
import json
import re

from .receipts import _reference_segment


def order_receipt_claimed(db, order, payment):
    receipts = db.rows('''SELECT id,canonical_key,provider,identity_kind,account_namespace,reference,
        chain_id,tx_hash,log_index,beneficiary,currency,amount_units,decimals,fingerprint,target_type,target_id
        FROM public.external_money_receipts WHERE target_type='order_payment' AND target_id=%s
        AND status='claimed' ORDER BY id''', (str(payment['id']),))
    for receipt in receipts:
        if receipt['currency'] != payment['currency']:
            continue
        payload = {'version': 1, 'identity': receipt['canonical_key'], 'beneficiary': receipt['beneficiary'],
                   'currency': receipt['currency'], 'amount_units': receipt['amount_units'],
                   'decimals': receipt['decimals'], 'target_type': 'order_payment', 'target_id': str(payment['id'])}
        fingerprint = hashlib.sha256(json.dumps(payload, ensure_ascii=True, separators=(',', ':')).encode()).hexdigest()
        if fingerprint != receipt['fingerprint']:
            continue
        if receipt['identity_kind'] == 'provider_reference':
            expected_key = f"v1|provider_reference|{receipt['provider']}|{receipt['account_namespace']}|{receipt['reference']}"
            if receipt['canonical_key'] != expected_key or receipt['amount_units'] != str(payment['amount']):
                continue
            if receipt['provider'] == 'manual' and payment['provider'] == 'manual':
                valid = (receipt['account_namespace'] == 'owner_attestation' and receipt['decimals'] == 0
                         and receipt['beneficiary'] == 'order:' + str(order['id'])
                         and receipt['reference'] == _reference_segment(payment['merchant_ref']))
            elif receipt['provider'] == 'binance' and payment['provider'] == 'manual' and payment['method'] == 'BINANCE_PAY_ID':
                namespace = receipt['account_namespace'] or ''
                receiver_type, _, receiver_value = namespace.partition(':')
                valid = (receiver_type in ('pay_id', 'uid') and bool(receiver_value)
                         and receipt['beneficiary'] == namespace and receipt['decimals'] == 6)
                if payment['binance_receiver_type'] is not None or payment['binance_receiver_value'] is not None:
                    valid = valid and namespace == f"{payment['binance_receiver_type']}:{str(payment['binance_receiver_value'] or '').strip()}"
            else:
                reference = payment['provider_reference'] or payment['merchant_ref']
                valid = (receipt['provider'] == payment['provider'] and receipt['account_namespace'] == 'default'
                         and receipt['decimals'] == 0 and receipt['beneficiary'] == 'order:' + str(order['id'])
                         and receipt['reference'] == _reference_segment(reference))
            if valid:
                return True
        elif (receipt['identity_kind'] == 'bsc_log' and receipt['provider'] == 'bsc'
              and payment['provider'] == 'manual' and payment['method'] == 'USDT_BEP20'):
            detail = db.one('''SELECT chain_id,receiver_address,token_decimals,expected_token_units,payable_amount_micro
                FROM public.payment_bsc_details WHERE payment_id=%s''', (payment['id'],))
            if detail is None or detail['payable_amount_micro'] != payment['amount']:
                continue
            tx = receipt['tx_hash'] or ''
            key = f"v1|bsc_log|chain:{receipt['chain_id']}|tx:{tx}|log:{receipt['log_index']}"
            if (re.fullmatch(r'0x[0-9a-f]{64}', tx) is None or receipt['log_index'] is None
                    or receipt['log_index'] < 0 or receipt['canonical_key'] != key
                    or receipt['chain_id'] != detail['chain_id'] or receipt['decimals'] != detail['token_decimals']
                    or receipt['beneficiary'] != detail['receiver_address'].lower()
                    or receipt['amount_units'] != str(detail['expected_token_units'])):
                continue
            parent = db.one('''SELECT id FROM public.external_money_receipt_parent_claims
                WHERE chain_id=%s AND tx_hash=%s AND external_money_receipt_id=%s AND status='claimed'
                AND target_type='order_payment' AND target_id=%s''',
                (receipt['chain_id'], tx, receipt['id'], str(payment['id'])))
            if parent is not None:
                return True
    return False
