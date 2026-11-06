import unittest

from lhie_candidate.checkout.errors import CheckoutError
from lhie_candidate.checkout.pricing import PricingSnapshot
from lhie_candidate.checkout.wallet_selection import BalanceCheckoutRequest


class WalletSelectionTests(unittest.TestCase):
    def setUp(self):
        self.pricing = PricingSnapshot((), 15000, 1_000_001)

    def test_automatic_fallback_spends_one_complete_consented_wallet(self):
        request = BalanceCheckoutRequest('idr_first', 15000, 1_000_001)
        _, currencies = request.currencies(None)
        self.assertEqual(request.select(currencies, self.pricing,
                                        {'balance': 14999}, {'balance_micro': 1_000_001}), 'USDT')

    def test_stale_secondary_consent_does_not_fallback_or_spend_primary(self):
        request = BalanceCheckoutRequest('idr_first', 15000, 1_000_000)
        with self.assertRaises(CheckoutError) as error:
            request.select(('IDR', 'USDT'), self.pricing,
                           {'balance': 15000}, {'balance_micro': 1_000_001})
        self.assertEqual(error.exception.code, 'price_changed')

    def test_partial_wallets_are_not_combined(self):
        request = BalanceCheckoutRequest('usd_first', 15000, 1_000_001)
        with self.assertRaises(CheckoutError) as error:
            request.select(('USDT', 'IDR'), self.pricing,
                           {'balance': 14999}, {'balance_micro': 1_000_000})
        self.assertEqual(error.exception.code, 'insufficient_balance')

    def test_no_eligible_primary_price_can_use_consented_secondary(self):
        pricing = PricingSnapshot((), 15000, None)
        request = BalanceCheckoutRequest('usd_first', 15000)
        self.assertEqual(request.select(('USDT', 'IDR'), pricing,
                                        {'balance': 15000}, {'balance_micro': 0}), 'IDR')

    def test_primary_eligible_price_requires_consent(self):
        request = BalanceCheckoutRequest('idr_first', None, 1_000_001)
        with self.assertRaises(CheckoutError) as error:
            request.select(('IDR', 'USDT'), self.pricing,
                           {'balance': 0}, {'balance_micro': 1_000_001})
        self.assertEqual(error.exception.code, 'balance_price_consent_required')


if __name__ == '__main__':
    unittest.main()
