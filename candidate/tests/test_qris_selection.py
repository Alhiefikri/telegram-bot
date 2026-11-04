from dataclasses import replace
from datetime import datetime, timedelta
from types import SimpleNamespace
import unittest

from lhie_candidate.checkout.errors import CheckoutError
from lhie_candidate.checkout.intents import PaymentSetting
from lhie_candidate.checkout.manual import ManualQrisPreparation


class QrisSelectionContracts(unittest.TestCase):
    def test_manual_uses_frozen_setting_ttl_and_rejects_version_or_provider_switch(self):
        setting = PaymentSetting(1, 4, True, 'manual', qris_ttl_minutes=13)
        preparation = ManualQrisPreparation(ready=lambda: True)
        preflight = preparation.preflight(setting)
        at = datetime(2026, 11, 4, 12)
        plan = preparation.prepare(SimpleNamespace(idr_total=12345), setting, preflight, at)
        self.assertEqual((plan.provider, plan.method, plan.amount, plan.expires_at),
                         ('manual', 'QR_MANUAL', 12345, at + timedelta(minutes=13)))
        for current in (replace(setting, version=5), replace(setting, provider='sakurupiah'), None):
            with self.subTest(current=current), self.assertRaises(CheckoutError):
                preparation.prepare(SimpleNamespace(idr_total=12345), current, preflight, at)

    def test_unavailable_selected_manual_does_not_fallback_to_wallet_or_provider(self):
        preparation = ManualQrisPreparation(ready=lambda: False)
        with self.assertRaises(CheckoutError) as raised:
            preparation.preflight(PaymentSetting(1, 1, True, 'manual'))
        self.assertEqual(raised.exception.code, 'provider_unavailable')

