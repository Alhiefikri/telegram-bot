from dataclasses import replace
from datetime import datetime, timedelta
from types import SimpleNamespace
import unittest
from unittest.mock import patch

from lhie_candidate.checkout.errors import CheckoutError
from lhie_candidate.checkout.external_usdt import (
    APPROVED_USDT, BinancePreparation, BscPreparation,
)
from lhie_candidate.checkout.intents import PaymentSetting
from lhie_candidate.checkout.pricing import PricingSnapshot


NOW = datetime(2026, 11, 4, 10, 0)
SETTING = PaymentSetting(1, 3, True, binance_enabled=True, binance_receiver_type='uid',
                         binance_receiver_value='123456', bsc_enabled=True)


class MemoryDatabase:
    def __init__(self, receivers=(), occupied=()):
        self.receivers = list(receivers)
        self.occupied = occupied
        self.statements = []

    def require_transaction(self):
        pass

    def rows(self, sql, params=()):
        self.statements.append((sql, params))
        if 'bsc_payment_receivers' in sql:
            return self.receivers
        return [{'amount': amount} for amount in self.occupied]

    def execute(self, sql, params=()):
        self.statements.append((sql, params))


class ExternalPreparationTests(unittest.TestCase):
    def quote(self, usd=1234567):
        return PricingSnapshot.build([{'product_id': 1, 'quantity': 2}],
                                     {1: {'price_amount': 15000, 'usdt_price_amount': usd}}, {})

    def adapter(self, **values):
        return SimpleNamespace(readiness=lambda: {'ready': True, 'chain_id': 56,
            'token_contract': APPROVED_USDT, 'token_decimals': 18, 'transfer_logs': True, **values})

    def preflight(self, preparation, setting=SETTING):
        with patch('lhie_candidate.checkout.external_usdt.connections',
                   {'default': SimpleNamespace(in_atomic_block=False)}):
            return preparation.preflight(setting)

    def test_unqualified_adapter_and_missing_receiver_fail_closed(self):
        for adapter, setting in ((None, SETTING), (self.adapter(ready=False), SETTING),
                                 (self.adapter(), replace(SETTING, binance_receiver_value=None))):
            with self.subTest(adapter=adapter, setting=setting), self.assertRaises(CheckoutError):
                self.preflight(BinancePreparation(adapter, clock=lambda: NOW), setting)

    def test_order_id_creation_requires_explicit_schema_compatibility(self):
        setting = replace(SETTING, binance_verification_mode='order_id')
        prep = BinancePreparation(self.adapter(), clock=lambda: NOW)
        with self.assertRaises(CheckoutError):
            self.preflight(prep, setting)

    def test_order_id_uses_exact_catalog_micro_amount_and_never_converts_idr(self):
        setting = replace(SETTING, binance_verification_mode='order_id',
                          binance_order_id_compatibility_ready_at=NOW)
        prep = BinancePreparation(self.adapter(), clock=lambda: NOW)
        prep.db = MemoryDatabase(occupied=[2469134])
        plan = prep.prepare(self.quote(), setting, self.preflight(prep, setting), NOW)
        self.assertEqual((plan.amount, plan.currency, self.quote().idr_total), (2469134, 'USDT', 30000))
        self.assertEqual((plan.rail_snapshot.receiver_type, plan.rail_snapshot.receiver_value), ('uid', '123456'))

    def test_unique_amount_increments_only_integer_micro_usdt(self):
        prep = BinancePreparation(self.adapter(), clock=lambda: NOW)
        prep.db = MemoryDatabase(occupied=[2469134, 2470134])
        plan = prep.prepare(self.quote(), SETTING, self.preflight(prep), NOW)
        self.assertEqual(plan.amount, 2471134)
        self.assertEqual(plan.expires_at, NOW + timedelta(minutes=20))

    def test_preflight_is_invalidated_by_setting_or_age(self):
        prep = BinancePreparation(self.adapter(), clock=lambda: NOW)
        prep.db = MemoryDatabase()
        preflight = self.preflight(prep)
        for setting, now in ((replace(SETTING, binance_receiver_value='654321'), NOW),
                             (SETTING, NOW + timedelta(seconds=60))):
            with self.assertRaises(CheckoutError):
                prep.prepare(self.quote(), setting, preflight, now)

    def test_missing_usdt_price_never_falls_back_to_idr(self):
        prep = BinancePreparation(self.adapter(), clock=lambda: NOW)
        prep.db = MemoryDatabase()
        with self.assertRaises(CheckoutError):
            prep.prepare(self.quote(None), SETTING, self.preflight(prep), NOW)

    def test_bsc_uses_existing_checkout_receiver_and_immutable_policy_snapshot(self):
        prep = BscPreparation(self.adapter(), clock=lambda: NOW)
        prep.db = MemoryDatabase([{'id': 4, 'address_normalized': '0x' + 'a' * 40,
            'chain_id': 56, 'token_contract': APPROVED_USDT, 'token_decimals': 18,
            'address_claim_id': 7, 'verification_status': 'verified', 'verified_at': NOW}])
        preflight = self.preflight(prep)
        plan = prep.prepare(self.quote(), SETTING, preflight, NOW)
        self.assertEqual(plan.amount, 2469134)
        self.assertEqual(plan.rail_snapshot.receiver_id, 4)
        self.assertEqual(plan.rail_snapshot.required_confirmations, 3)
        prep.db.receivers[0]['address_normalized'] = '0x' + 'b' * 40
        with self.assertRaises(CheckoutError):
            prep.prepare(self.quote(), SETTING, preflight, NOW)

    def test_bsc_readiness_requires_transfer_logs_and_approved_network(self):
        for values in ({'chain_id': 1}, {'token_decimals': 6}, {'transfer_logs': False},
                       {'token_contract': '0x' + 'b' * 40}):
            prep = BscPreparation(self.adapter(**values), clock=lambda: NOW)
            prep.db = MemoryDatabase([{'id': 4}])
            with self.subTest(values=values), self.assertRaises(CheckoutError):
                self.preflight(prep)

