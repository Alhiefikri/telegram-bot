"""Canonical physical-money claims shared by checkout and balance funding.

Callers validate authoritative evidence first, then claim inside the same
transaction as the money/lifecycle effect. Canonical and alternate identities
share one uniqueness domain, including historical rows without self-aliases.
"""

from dataclasses import dataclass
from datetime import datetime
import hashlib
import json
import re
from zoneinfo import ZoneInfo

from django.db import transaction


_SAFE_REFERENCE = re.compile(r'[\x21-\x7e]{1,64}\Z')


@dataclass(frozen=True, slots=True)
class ReceiptClaim:
    status: str
    receipt_id: int | None = None

    @property
    def allowed(self):
        return self.status in ('claimed', 'replayed')

    @property
    def held(self):
        return not self.allowed


def _reference_segment(reference):
    reference = reference.strip()
    if _SAFE_REFERENCE.fullmatch(reference) and '|' not in reference:
        return reference
    return hashlib.sha256(reference.encode()).hexdigest()

def _provider_reference_ambiguous(db, provider, reference, namespace, target_type, target_id):
    if provider not in ('sakurupiah', 'klikqris', 'pakasir'):
        return False
    key = f'v1|provider_reference|{provider}|{namespace}|{reference}'
    db.one("SELECT pg_advisory_xact_lock(hashtextextended(%s, 0)) AS locked",
           (f'provider-receipt|{provider}|{reference}',))
    ownership = db.one('''SELECT
        EXISTS(SELECT 1 FROM public.external_money_receipts
            WHERE canonical_key=%s AND target_type=%s AND target_id=%s) AS own,
        EXISTS(SELECT 1 FROM public.external_money_receipts
            WHERE provider=%s AND identity_kind='provider_reference' AND reference=%s
            AND account_namespace IS DISTINCT FROM %s
            AND (target_type IS DISTINCT FROM %s OR target_id IS DISTINCT FROM %s)) AS ambiguous''',
        (key, target_type, str(target_id), provider, reference, namespace, target_type, str(target_id)))
    return ownership['ambiguous'] and not ownership['own']


def claim_provider_reference(db, *, provider, reference, beneficiary, currency, amount,
                             target_type, target_id, evidence=None, now=None, using='default',
                             account_namespace='default'):
    """Claim ``(provider, provider_reference)`` for exactly one money target.

    Returns ``claimed`` for a new or matching replay, ``conflict`` when another
    target/fingerprint owns the reference, and ``review`` for non-claimed
    registry rows. No database error escapes to continue a broken transaction.
    """
    db.require_transaction()
    provider = provider.strip().lower()
    reference = reference.strip()
    if (not (((provider in ('sakurupiah', 'klikqris', 'pakasir')
               or provider == 'manual' and target_type == 'balance_top_up') and currency == 'IDR'
              and (account_namespace == 'default'
                   or (target_type == 'balance_top_up'
                       and re.fullmatch(r'setting:[1-9][0-9]*', account_namespace))))
             or (provider == 'manual' and account_namespace == 'owner_attestation'
                 and currency in ('IDR', 'USDT') and target_type == 'order_payment'))
            or not reference or type(amount) is not int or amount < 1
            or target_type not in ('order_payment', 'balance_top_up')
            or not str(target_id).strip()):
        raise ValueError('Invalid receipt claim identity')
    segment = _reference_segment(reference)
    if _provider_reference_ambiguous(db, provider, segment, account_namespace, target_type, target_id):
        return ReceiptClaim('review')
    return _claim_receipt(
        db, provider=provider, kind='provider_reference', namespace=account_namespace,
        reference=segment, key=f'v1|provider_reference|{provider}|{account_namespace}|{segment}',
        beneficiary=beneficiary, currency=currency, units=str(amount), decimals=0,
        target_type=target_type, target_id=str(target_id), evidence=evidence,
        now=now, using=using)


def claim_owner_attestation(db, *, payment, now=None, using='default'):
    return claim_provider_reference(
        db, provider='manual', account_namespace='owner_attestation',
        reference=payment['merchant_ref'], beneficiary='order:' + str(payment['order_id']),
        currency=payment['currency'], amount=payment['amount'], target_type='order_payment',
        target_id=str(payment['id']), evidence={'provider': 'manual', 'identity': 'owner_attestation'},
        now=now, using=using)


def claim_binance_payment(db, *, payment, transaction_id, order_id=None, now=None, using='default'):
    receiver_type = payment.get('binance_receiver_type')
    receiver_value = payment.get('binance_receiver_value')
    if (receiver_type not in ('pay_id', 'uid') or type(receiver_value) is not str
            or re.fullmatch(r'[0-9]{1,57}', receiver_value) is None
            or payment['currency'] != 'USDT' or type(payment['amount']) is not int
            or payment['amount'] < 1 or type(transaction_id) is not str or not transaction_id.strip()):
        raise ValueError('Invalid Binance receipt snapshot')
    namespace = f'{receiver_type}:{receiver_value}'
    reference = _reference_segment(transaction_id)
    key = f'v1|provider_reference|binance|{namespace}|{reference}'
    aliases = {}
    if order_id is not None and order_id.strip() and order_id.strip() != transaction_id.strip():
        aliases[f'v1|provider_reference|binance|{namespace}|{_reference_segment(order_id)}'] = 'binance_order_id'
    return _claim_receipt(
        db, provider='binance', kind='provider_reference', namespace=namespace,
        reference=reference, key=key, beneficiary=namespace, currency='USDT',
        units=str(payment['amount']), decimals=6, target_type='order_payment',
        target_id=str(payment['id']), aliases=aliases, now=now, using=using,
        evidence={'provider': 'binance', 'identity': 'receiver_namespace'}, reserve=True)


def claim_bsc_payment(db, *, payment, chain_id, tx_hash, log_index, token_contract,
                      receiver, amount_units, decimals, now=None, using='default'):
    tx_hash, receiver, token_contract = tx_hash.lower(), receiver.lower(), token_contract.lower()
    if (type(chain_id) is not int or chain_id <= 0 or type(log_index) is not int or log_index < 0
            or re.fullmatch(r'0x[0-9a-f]{64}', tx_hash) is None
            or re.fullmatch(r'0x[0-9a-f]{40}', receiver) is None
            or re.fullmatch(r'0x[0-9a-f]{40}', token_contract) is None
            or type(amount_units) is not str or re.fullmatch(r'[1-9][0-9]{0,77}', amount_units) is None
            or type(decimals) is not int or not 0 <= decimals <= 255 or payment['currency'] != 'USDT'):
        raise ValueError('Invalid BSC receipt snapshot')
    return _claim_receipt(
        db, provider='bsc', kind='bsc_log', namespace=None, reference=None,
        key=f'v1|bsc_log|chain:{chain_id}|tx:{tx_hash}|log:{log_index}',
        beneficiary=receiver, currency='USDT', units=amount_units, decimals=decimals,
        target_type='order_payment', target_id=str(payment['id']),
        chain_id=chain_id, tx_hash=tx_hash, log_index=log_index, now=now, using=using,
        evidence={'provider': 'bsc', 'chain_id': chain_id, 'log_index': log_index},
        reserve=True)


def _reserve(db, provider, target_id, key, status, now):
    db.execute('''INSERT INTO public.external_money_receipt_reservations
        (source,legacy_type,legacy_id,provider,reason,details,reserved_at,created_at,updated_at)
        VALUES ('runtime','payment',%s,%s,%s,%s,%s,%s,%s)
        ON CONFLICT (legacy_type,legacy_id,reason) DO NOTHING''',
        (target_id, provider, 'registry_review' if status == 'review' else 'registry_conflict',
         json.dumps({'canonical_key': key, 'target_type': 'order_payment', 'target_id': target_id}),
         now, now, now))


def _claim_receipt(db, *, provider, kind, namespace, reference, key, beneficiary, currency,
                   units, decimals, target_type, target_id, evidence=None, aliases=None,
                   chain_id=None, tx_hash=None, log_index=None, now=None, using='default', reserve=False):
    db.require_transaction()
    now = now or datetime.now(ZoneInfo('Asia/Jakarta')).replace(tzinfo=None)
    payload = {'version': 1, 'identity': key, 'beneficiary': beneficiary, 'currency': currency,
               'amount_units': units, 'decimals': decimals, 'target_type': target_type, 'target_id': target_id}
    fingerprint = hashlib.sha256(json.dumps(payload, ensure_ascii=True, separators=(',', ':')).encode()).hexdigest()
    identities = dict(aliases or {})
    identities[key] = 'canonical'
    savepoint = transaction.savepoint(using=using)

    def reject(status):
        transaction.savepoint_rollback(savepoint, using=using)
        if reserve:
            _reserve(db, provider, target_id, key, status, now)
        return ReceiptClaim(status)

    chain_columns = ',chain_id,tx_hash,log_index' if kind == 'bsc_log' else ''
    chain_values = ',%s,%s,%s' if kind == 'bsc_log' else ''
    parameters = (key, provider, kind, namespace, reference, beneficiary, currency, units,
                  decimals, target_type, target_id, fingerprint,
                  json.dumps(evidence or {'provider': provider, 'identity': kind}), now, now, now)
    if kind == 'bsc_log':
        parameters += (chain_id, tx_hash, log_index)
    inserted = db.one(f'''INSERT INTO public.external_money_receipts
        (canonical_key,provider,identity_kind,account_namespace,reference,
         beneficiary,currency,amount_units,decimals,status,target_type,target_id,fingerprint,
         evidence_payload,claimed_at,created_at,updated_at{chain_columns})
        VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,'claimed',%s,%s,%s,%s,%s,%s,%s{chain_values})
        ON CONFLICT (canonical_key) DO NOTHING RETURNING id''', parameters)
    receipt = db.one('''SELECT id,fingerprint,status,target_type,target_id
        FROM public.external_money_receipts WHERE canonical_key=%s FOR UPDATE''', (key,))
    if (receipt is None or receipt['fingerprint'] != fingerprint
            or receipt['target_type'] != target_type or receipt['target_id'] != target_id):
        return reject('conflict')
    if receipt['status'] != 'claimed':
        return reject('review')
    for identity in sorted(identities):
        historical = db.one('SELECT id FROM public.external_money_receipts WHERE canonical_key=%s', (identity,))
        if historical is not None and historical['id'] != receipt['id']:
            return reject('conflict')
        db.execute('''INSERT INTO public.external_money_receipt_aliases
            (external_money_receipt_id,alias_key,alias_type,created_at) VALUES (%s,%s,%s,%s)
            ON CONFLICT (alias_key) DO NOTHING''', (receipt['id'], identity, identities[identity], now))
        alias = db.one('SELECT external_money_receipt_id FROM public.external_money_receipt_aliases WHERE alias_key=%s', (identity,))
        if alias is None or alias['external_money_receipt_id'] != receipt['id']:
            return reject('conflict')
    status = 'claimed' if inserted is not None else 'replayed'
    if kind == 'bsc_log':
        db.execute('''INSERT INTO public.external_money_receipt_parent_claims
            (chain_id,tx_hash,external_money_receipt_id,status,target_type,target_id,created_at,updated_at)
            VALUES (%s,%s,%s,'claimed',%s,%s,%s,%s) ON CONFLICT (chain_id,tx_hash) DO NOTHING''',
            (chain_id, tx_hash, receipt['id'], target_type, target_id, now, now))
        parent = db.one('''SELECT id,status,target_type,target_id FROM public.external_money_receipt_parent_claims
            WHERE chain_id=%s AND tx_hash=%s FOR UPDATE''', (chain_id, tx_hash))
        if parent is None:
            return reject('conflict')
        if parent['status'] != 'claimed' or (parent['target_type'], parent['target_id']) != (target_type, target_id):
            db.execute("""UPDATE public.external_money_receipt_parent_claims SET status='review',
                review_reason='bsc_parent_transaction_cross_target',updated_at=%s WHERE id=%s""", (now, parent['id']))
            db.execute("""UPDATE public.external_money_receipts SET status='review',
                review_reason='bsc_parent_transaction_cross_target',claimed_at=NULL,updated_at=%s WHERE id=%s""", (now, receipt['id']))
            status = 'review'
    transaction.savepoint_commit(savepoint, using=using)
    if reserve and status == 'review':
        _reserve(db, provider, target_id, key, status, now)
    return ReceiptClaim(status, receipt['id'])
