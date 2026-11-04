from datetime import timedelta

from .errors import CheckoutError
from .intents import PaymentPlan, PaymentPreflight


class KlikQrisPreparation:
    provider = 'klikqris'
    method = 'QRIS'

    def __init__(self, adapter):
        self.adapter = adapter

    def preflight(self, setting):
        if (setting is None or not setting.active or setting.provider != self.provider
                or self.adapter.readiness()):
            raise CheckoutError('provider_unavailable')
        return PaymentPreflight(self.provider, self.method, setting)

    def prepare(self, pricing, setting, preflight, now):
        if (preflight.provider, preflight.method, preflight.setting) != (self.provider, self.method, setting):
            raise CheckoutError('stale_preflight')
        if setting is None or setting.provider != self.provider or self.adapter.readiness():
            raise CheckoutError('provider_unavailable')
        return PaymentPlan(self.provider, self.method, pricing.idr_total,
                           now + timedelta(minutes=self.adapter.config.ttl_minutes))
