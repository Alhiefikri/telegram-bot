"""One canonical external-money receipt claim seam for P08 writers.

The provider reference is the physical-money identity. A claim belongs to one
target globally; the same reference can never fund both an Order and a Store
Balance top-up, or two top-ups. Callers must run this inside their money
transaction after locking the target.
"""

from dataclasses import dataclass
from datetime import datetime
import hashlib
import json
import re
from zoneinfo import ZoneInfo

from django.db import transaction

from ..checkout.db import Database


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
    if (not ((provider in ('sakurupiah', 'klikqris', 'pakasir') and account_namespace == 'default' and currency == 'IDR')
             or (provider == 'manual' and account_namespace == 'owner_attestation'
                 and currency in ('IDR', 'USDT') and target_type == 'order_payment'))
            or not reference or type(amount) is not int or amount < 1
            or target_type not in ('order_payment', 'balance_top_up')
            or not str(target_id).strip()):
        raise ValueError('Invalid receipt claim identity')
    now = now or datetime.now(ZoneInfo('Asia/Jakarta')).replace(tzinfo=None)
    segment = _reference_segment(reference)
    key = f'v1|provider_reference|{provider}|{account_namespace}|{segment}'
    payload = {'version': 1, 'identity': key, 'beneficiary': beneficiary,
               'currency': currency, 'amount_units': str(amount), 'decimals': 0,
               'target_type': target_type, 'target_id': str(target_id)}
    fingerprint = hashlib.sha256(
        json.dumps(payload, ensure_ascii=True, separators=(',', ':')).encode()
    ).hexdigest()
    savepoint = transaction.savepoint(using=using)
    db.execute('''INSERT INTO public.external_money_receipts
        (canonical_key,provider,identity_kind,account_namespace,reference,beneficiary,currency,amount_units,
         decimals,status,target_type,target_id,fingerprint,evidence_payload,claimed_at,created_at,updated_at)
        VALUES (%s,%s,'provider_reference',%s,%s,%s,%s,%s,0,'claimed',%s,%s,%s,%s,%s,%s,%s)
        ON CONFLICT (canonical_key) DO NOTHING''',
        (key, provider, account_namespace, segment, beneficiary, currency, amount, target_type, str(target_id),
         fingerprint, json.dumps(evidence or {'provider': provider, 'identity': 'provider_reference'}),
         now, now, now))
    receipt = db.one('''SELECT id,fingerprint,status,target_type,target_id
        FROM public.external_money_receipts WHERE canonical_key=%s FOR UPDATE''', (key,))
    if (receipt is None or receipt['fingerprint'] != fingerprint
            or receipt['status'] != 'claimed'
            or receipt['target_type'] != target_type or receipt['target_id'] != str(target_id)):
        transaction.savepoint_rollback(savepoint, using=using)
        return ReceiptClaim('review' if receipt is not None and receipt['status'] != 'claimed' else 'conflict')
    db.execute('''INSERT INTO public.external_money_receipt_aliases
        (external_money_receipt_id,alias_key,alias_type,created_at) VALUES (%s,%s,'canonical',%s)
        ON CONFLICT (alias_key) DO NOTHING''', (receipt['id'], key, now))
    alias = db.one('SELECT external_money_receipt_id FROM public.external_money_receipt_aliases WHERE alias_key=%s', (key,))
    if alias is None or alias['external_money_receipt_id'] != receipt['id']:
        transaction.savepoint_rollback(savepoint, using=using)
        return ReceiptClaim('conflict')
    transaction.savepoint_commit(savepoint, using=using)
    return ReceiptClaim('claimed', receipt['id'])


def claim_owner_attestation(db, *, payment, now=None, using='default'):
    return claim_provider_reference(
        db, provider='manual', account_namespace='owner_attestation',
        reference=payment['merchant_ref'], beneficiary='order:' + str(payment['order_id']),
        currency=payment['currency'], amount=payment['amount'], target_type='order_payment',
        target_id=str(payment['id']), evidence={'provider': 'manual', 'identity': 'owner_attestation'},
        now=now, using=using)
