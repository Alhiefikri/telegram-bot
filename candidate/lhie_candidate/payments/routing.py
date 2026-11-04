"""Route by persisted provider; the owner's current selection affects only checkout."""
from ..checkout.db import Database
from ..checkout.errors import CheckoutError
from ..checkout.lifecycle import OrderLifecycle
from ..checkout.manual import ManualQrisPreparation
from ..checkout.sakurupiah import SakurupiahPreparation
from .lifecycle import LifecycleResult, SakurupiahLifecycle
from .manual import ManualQrisLifecycle
from .presentation import QrPresentation
from .settings import NEW_QRIS_PROVIDERS


class QrisPayments:
    def __init__(self, lifecycles=(), *, presentation=None, using='default'):
        self.db = Database(using)
        self.using = using
        self.presentation = presentation or QrPresentation(using=using)
        self.lifecycles = dict(lifecycles)
        self.lifecycles.setdefault('manual', ManualQrisLifecycle(self.presentation, using=using))
        self.orders = OrderLifecycle(using=using)

    def preparation(self, setting):
        provider = setting.provider if setting is not None else 'manual'
        if provider not in NEW_QRIS_PROVIDERS:
            raise CheckoutError('provider_retired')
        lifecycle = self.lifecycles.get(provider)
        if lifecycle is None:
            raise CheckoutError('provider_unavailable')
        if provider == 'manual':
            return ManualQrisPreparation(ready=self.presentation.manual_ready)
        if provider == 'sakurupiah':
            return SakurupiahPreparation(lifecycle.adapter)
        from ..checkout.klikqris import KlikQrisPreparation
        return KlikQrisPreparation(lifecycle.adapter)

    def for_payment(self, payment_id):
        row = self.db.one('SELECT provider FROM public.payments WHERE id=%s', (payment_id,))
        if row is None:
            raise CheckoutError('foreign_payment')
        return self.lifecycles.get(row['provider'].lower())

    def _call(self, operation, payment_id, **kwargs):
        lifecycle = self.for_payment(payment_id)
        if lifecycle is None:
            return LifecycleResult('provider_unavailable')
        if operation == 'create' and not hasattr(lifecycle, 'create'):
            return lifecycle.classify(payment_id)
        if operation == 'create' and lifecycle is not self.lifecycles.get('sakurupiah'):
            kwargs = {}
        return getattr(lifecycle, operation)(payment_id, **kwargs)

    def classify(self, payment_id):
        return self._call('classify', payment_id)

    def create(self, payment_id, **kwargs):
        return self._call('create', payment_id, **kwargs)

    def refresh(self, payment_id):
        return self._call('refresh', payment_id)

    def recover(self, payment_id):
        return self._call('recover', payment_id)

    def consume_callbacks(self, payment_id):
        return self._call('consume_callbacks', payment_id)

    def refresh_background(self, payment_id):
        lifecycle = self.for_payment(payment_id)
        if lifecycle is None:
            return LifecycleResult('provider_unavailable')
        if isinstance(lifecycle, SakurupiahLifecycle):
            return lifecycle.refresh(payment_id, background=True)
        return lifecycle.refresh(payment_id)

    def recover_background(self, payment_id):
        lifecycle = self.for_payment(payment_id)
        if lifecycle is None:
            return LifecycleResult('provider_unavailable')
        if isinstance(lifecycle, SakurupiahLifecycle):
            return lifecycle.recover(payment_id, background=True)
        return lifecycle.recover(payment_id)
