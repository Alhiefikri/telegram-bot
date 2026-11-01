"""Sakurupiah checkout preparation; provider I/O precedes checkout locks."""
from datetime import timedelta

from lhie_candidate.payments.sakurupiah import ProviderUnavailable

from .errors import CheckoutError
from .intents import PaymentPlan, PaymentPreflight


class SakurupiahPreparation:
    provider = 'sakurupiah'
    method = 'QRIS'

    def __init__(self, adapter):
        self.adapter = adapter

    def preflight(self, setting):
        if (setting is None or not setting.active or setting.provider != self.provider
                or setting.sakurupiah_channel not in ('QRIS', 'QRISC', 'QRISMU', 'QRIS2')):
            raise CheckoutError('provider_unavailable')
        try:
            channels = self.adapter.list_channels()
        except ProviderUnavailable:
            raise CheckoutError('provider_unavailable') from None
        matches = [channel for channel in channels if channel.code == setting.sakurupiah_channel]
        if len(matches) != 1 or not matches[0].active:
            raise CheckoutError('provider_unavailable')
        return PaymentPreflight(self.provider, self.method, setting, matches[0])

    def prepare(self, pricing, setting, preflight, now):
        channel = preflight.observation
        if (preflight.provider != self.provider or preflight.method != self.method
                or preflight.setting != setting or setting is None
                or channel is None or channel.code != setting.sakurupiah_channel):
            raise CheckoutError('stale_preflight')
        if not channel.minimum <= pricing.idr_total <= channel.maximum:
            raise CheckoutError('provider_amount_outside_limits')
        return PaymentPlan(self.provider, self.method, pricing.idr_total,
                           now + timedelta(minutes=15), sakurupiah_channel=channel.code)
