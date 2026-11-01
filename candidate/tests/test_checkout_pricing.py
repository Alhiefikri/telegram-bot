import unittest

from lhie_candidate.checkout.errors import CheckoutError
from lhie_candidate.checkout.intents import telegram_checkout_key, telegram_detail_checkout_key
from lhie_candidate.checkout.pricing import PricingSnapshot
from lhie_candidate.domain.money import MAX_UNITS


class CheckoutPricingTests(unittest.TestCase):
    def quote(self, quantity=2, *, price=15000, usd=2000000, tiers=None, reseller=False, **overrides):
        product = {'id': 1, 'price_amount': price, 'usdt_price_amount': usd, **overrides}
        return PricingSnapshot.build([{'product_id': 1, 'quantity': quantity}], {1: product},
                                     {1: tiers or []}, reseller=reseller)

    def test_tier_and_reseller_precedence_do_not_stack_or_convert_currencies(self):
        tiers = [{'minimum_quantity': 2, 'price_amount': 12000, 'usdt_price_amount': 1234567}]
        retail = self.quote(tiers=tiers)
        self.assertEqual((retail.idr_total, retail.usdt_total, retail.quotes[0].tier_minimum), (24000, 2469134, 2))
        reseller = self.quote(tiers=tiers, reseller=True, reseller_price_amount=11000)
        self.assertEqual((reseller.idr_total, reseller.usdt_total, reseller.quotes[0].tier_minimum), (22000, 2469134, None))
        self.assertIsNone(self.quote(usd=None, reseller=True, reseller_usdt_price_amount=1).usdt_total)

    def test_incomplete_or_nonmonotonic_schedule_fails_without_cheaper_fallback(self):
        for tiers in (
            [{'minimum_quantity': 2, 'price_amount': 12000, 'usdt_price_amount': None}],
            [{'minimum_quantity': 2, 'price_amount': 15000, 'usdt_price_amount': 1000000}],
            [{'minimum_quantity': 21, 'price_amount': 12000, 'usdt_price_amount': 1000000}],
        ):
            with self.subTest(tiers=tiers), self.assertRaises(CheckoutError):
                self.quote(tiers=tiers)

    def test_float_bool_and_overflow_are_not_money_or_quantities(self):
        for quantity in (True, 1.0, 0, -1):
            with self.subTest(quantity=quantity), self.assertRaises(ValueError):
                self.quote(quantity)
        with self.assertRaises(ValueError):
            self.quote(price=MAX_UNITS)
        with self.assertRaises(ValueError):
            self.quote(price=1.0)
        with self.assertRaises(ValueError):
            PricingSnapshot.build([{'product_id': 1, 'quantity': 1}, {'product_id': 2, 'quantity': 1}],
                {1: {'price_amount': MAX_UNITS}, 2: {'price_amount': 1}}, {})

    def test_checkout_prompt_key_is_update_and_rail_independent_but_owner_bound(self):
        args = ('a'*64, 42, 700042, '700042', 99)
        key = telegram_checkout_key(*args, 'checkout:balance')
        self.assertEqual(key, telegram_checkout_key(*args, 'checkout:current'))
        self.assertNotEqual(key, telegram_checkout_key('a'*64, 43, 700043, '700043', 99, 'checkout:balance'))
        self.assertNotEqual(key, telegram_checkout_key('a'*64, 42, 700042, '700042', 100, 'checkout:balance'))
        self.assertNotEqual(telegram_detail_checkout_key('a'*64, 42, 700042, '700042', 99, 1, 'revision1'),
                            telegram_detail_checkout_key('a'*64, 42, 700042, '700042', 99, 1, 'revision2'))
