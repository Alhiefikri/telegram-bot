"""Manual QR checkout stays pending until an authorized operator confirms money."""
from datetime import timedelta

from .errors import CheckoutError
from .intents import PaymentPlan, PaymentPreflight


class ManualQrisPreparation:
    provider = 'manual'
    method = 'QR_MANUAL'

    def __init__(self, *, ready):
        self.ready = ready

    def preflight(self, setting):
        if (setting is not None and (not setting.active or setting.provider != self.provider)) or not self.ready():
            raise CheckoutError('provider_unavailable')
        return PaymentPreflight(self.provider, self.method, setting)

    def prepare(self, pricing, setting, preflight, now):
        if (preflight.provider, preflight.method, preflight.setting) != (self.provider, self.method, setting):
            raise CheckoutError('stale_preflight')
        if setting is not None and (not setting.active or setting.provider != self.provider):
            raise CheckoutError('stale_preflight')
        ttl = setting.qris_ttl_minutes if setting else 10
        return PaymentPlan(self.provider, self.method, pricing.idr_total,
                           now + timedelta(minutes=ttl))
