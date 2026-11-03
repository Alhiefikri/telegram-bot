import unittest
from types import SimpleNamespace

from lhie_candidate.checkout.navigation import NavigationCheckout
from lhie_candidate.compat.crypto import LaravelEncrypter


class CheckoutFeeSnapshotTests(unittest.TestCase):
    def setUp(self):
        self.encrypter = LaravelEncrypter(b'a' * 32)
        self.checkout = NavigationCheckout(lifecycle=SimpleNamespace(encrypter=self.encrypter))
        self.payment = {
            'order_id': 'order', 'id': 'payment', 'invoice_number': 'INV',
            'amount': 25000, 'provider_fee': 200, 'provider_total_payment': 25200,
        }

    def view(self, payer, total=25200):
        self.payment['provider_total_payment'] = total
        self.payment['provider_presentation'] = self.encrypter.encrypt_json(
            {'customer_pays_fee': payer})
        return self.checkout._payment_view(self.payment, state='pending', replayed=True)

    def test_customer_fee_survives_uncertain_observation(self):
        view = self.view(True)
        self.assertEqual((view.fee, view.total, view.replayed), (200, 25200, True))

    def test_merchant_fee_is_never_shown_as_customer_charge(self):
        view = self.view(False, 25000)
        self.assertEqual((view.fee, view.total), (None, 25000))

    def test_corrupt_snapshot_or_mismatched_total_does_not_claim_fee(self):
        self.assertIsNone(self.view(True, 25000).fee)
        self.payment['provider_presentation'] = 'invalid'
        self.assertIsNone(self.checkout._payment_view(self.payment, state='pending').fee)
