"""One registry for Telegram family policy; SMTP families remain separate."""
from . import balance, bulk, fake_proof, lifecycle, operations, proof_publication


ADAPTERS = (
    (bulk.BulkNotices, bulk.MODELS, bulk.LIMITS),
    (lifecycle.LifecycleNotices, lifecycle.MODELS, lifecycle.LIMITS),
    (balance.BalanceNotices, balance.MODELS, balance.LIMITS),
    (operations.Operations, operations.MODELS, operations.LIMITS),
    (proof_publication.SalesProofPublication, proof_publication.MODELS, proof_publication.LIMITS),
    (fake_proof.FakeProofPublication, fake_proof.MODELS, fake_proof.LIMITS),
)
MODELS = {}
LIMITS = {}
for _, models, limits in ADAPTERS:
    if set(models) != set(limits) or MODELS.keys() & models.keys():
        raise RuntimeError('Notification family ownership conflicts')
    MODELS.update(models)
    LIMITS.update(limits)
BULK_TYPES = frozenset(bulk.MODELS)
BALANCE_TYPES = frozenset(balance.MODELS)
