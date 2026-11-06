"""Internal verifier-to-adjustment receipt contract; no verifier is activated here."""
from dataclasses import dataclass
import hashlib
import json
import re

from ..auth.types import legacy_now
from ..payments.receipts import ReceiptClaim, _claim_receipt, _provider_reference_ambiguous
from .password import AdjustmentRejected


_SEGMENT = re.compile(r'[\x21-\x7e]{1,64}\Z')


@dataclass(frozen=True, slots=True)
class VerifiedAdjustmentReceipt:
    provider: str
    account_namespace: str
    reference: str
    beneficiary: str
    amount_units: str
    customer_id: int
    ownership_verified: bool
    target_type: str = 'balance_adjustment'
    currency: str = 'IDR'
    decimals: int = 0
    aliases: tuple = ()

    def bind(self, customer_id, amount, target_id):
        if (not re.fullmatch(r'[a-z0-9_]{2,32}', self.provider)
                or not _SEGMENT.fullmatch(self.account_namespace) or '|' in self.account_namespace
                or not _SEGMENT.fullmatch(self.reference) or '|' in self.reference
                or not isinstance(self.beneficiary, str) or not self.beneficiary.isascii()
                or not 1 <= len(self.beneficiary) <= 191 or re.search(r'[^\x21-\x7e]|\|', self.beneficiary)
                or self.target_type != 'balance_adjustment' or self.currency != 'IDR' or self.decimals != 0
                or type(self.customer_id) is not int or self.customer_id != customer_id
                or self.ownership_verified is not True or self.amount_units != str(amount)
                or type(self.aliases) is not tuple
                or any(type(alias) is not tuple or len(alias) != 2 or not all(isinstance(x, str) and x for x in alias)
                       for alias in self.aliases)):
            raise AdjustmentRejected('receipt_customer_or_money_mismatch')
        return BoundReceipt(self, str(target_id))

@dataclass(frozen=True, slots=True)
class VerifiedBscAdjustmentReceipt:
    chain_id: int
    tx_hash: str
    log_index: int
    beneficiary: str
    amount_units: str
    customer_id: int
    ownership_verified: bool
    target_type: str = 'balance_adjustment'
    currency: str = 'IDR'
    decimals: int = 0
    aliases: tuple = ()

    def bind(self, customer_id, amount, target_id):
        if (type(self.chain_id) is not int or not 1 <= self.chain_id <= (1 << 63) - 1
                or type(self.log_index) is not int or not 0 <= self.log_index <= (1 << 63) - 1
                or type(self.tx_hash) is not str
                or re.fullmatch(r'0x[0-9a-f]{64}', self.tx_hash) is None
                or not isinstance(self.beneficiary, str) or not self.beneficiary.isascii()
                or not 1 <= len(self.beneficiary) <= 191 or re.search(r'[^\x21-\x7e]|\|', self.beneficiary)
                or self.target_type != 'balance_adjustment' or self.currency != 'IDR'
                or type(self.decimals) is not int or self.decimals != 0
                or type(self.customer_id) is not int or self.customer_id != customer_id
                or self.ownership_verified is not True or self.amount_units != str(amount)
                or type(self.aliases) is not tuple
                or any(type(alias) is not tuple or len(alias) != 2 or not all(isinstance(x, str) and x for x in alias)
                       for alias in self.aliases)):
            raise AdjustmentRejected('receipt_customer_or_money_mismatch')
        return BoundReceipt(self, str(target_id))


@dataclass(frozen=True, slots=True)
class BoundReceipt:
    receipt: VerifiedAdjustmentReceipt | VerifiedBscAdjustmentReceipt
    target_id: str

    @property
    def key(self):
        r = self.receipt
        if isinstance(r, VerifiedBscAdjustmentReceipt):
            return f'v1|bsc_log|chain:{r.chain_id}|tx:{r.tx_hash}|log:{r.log_index}'
        return f'v1|provider_reference|{r.provider}|{r.account_namespace}|{r.reference}'

    def fingerprint(self):
        r = self.receipt
        payload = dict(version=1, identity=self.key, beneficiary=r.beneficiary, currency='IDR',
                       amount_units=r.amount_units, decimals=0, target_type='balance_adjustment', target_id=self.target_id)
        return hashlib.sha256(json.dumps(payload, ensure_ascii=True, separators=(',', ':')).encode()).hexdigest()

    def claim(self, db):
        r = self.receipt
        if isinstance(r, VerifiedBscAdjustmentReceipt):
            result = _claim_receipt(db, provider='bsc', kind='bsc_log', namespace=None,
                reference=None, key=self.key, beneficiary=r.beneficiary, currency='IDR', units=r.amount_units,
                decimals=0, target_type='balance_adjustment', target_id=self.target_id,
                chain_id=r.chain_id, tx_hash=r.tx_hash, log_index=r.log_index,
                evidence={'customer_id': r.customer_id, 'ownership_verified': True},
                aliases=dict(r.aliases), using=db.using)
        elif _provider_reference_ambiguous(db, r.provider, r.reference, r.account_namespace,
                                           'balance_adjustment', self.target_id):
            result = ReceiptClaim('review')
        else:
            result = _claim_receipt(db, provider=r.provider, kind='provider_reference', namespace=r.account_namespace,
                reference=r.reference, key=self.key, beneficiary=r.beneficiary, currency='IDR', units=r.amount_units,
                decimals=0, target_type='balance_adjustment', target_id=self.target_id,
                evidence={'customer_id': r.customer_id, 'ownership_verified': True}, aliases=dict(r.aliases), using=db.using)
        if result.held:
            now = legacy_now()
            reason = 'registry_conflict' if result.status == 'conflict' else 'registry_review'
            details = {'canonical_key': self.key, 'target_type': 'balance_adjustment', 'target_id': self.target_id,
                       'currency': 'IDR', 'amount_units': r.amount_units}
            db.execute('''INSERT INTO public.external_money_receipt_reservations
                (source,legacy_type,legacy_id,provider,reason,details,reserved_at,created_at,updated_at)
                VALUES ('runtime','balance_adjustment',%s,%s,%s,%s,%s,%s,%s)
                ON CONFLICT (legacy_type,legacy_id,reason) DO NOTHING''',
                (self.target_id, 'bsc' if isinstance(r, VerifiedBscAdjustmentReceipt) else r.provider,
                 reason, json.dumps(details), now, now, now))
        return result
