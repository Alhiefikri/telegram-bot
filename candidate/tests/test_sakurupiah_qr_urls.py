import unittest
from datetime import datetime, timedelta
from types import SimpleNamespace

from lhie_candidate.checkout.navigation import _presentation_values
from lhie_candidate.payments.lifecycle import SakurupiahLifecycle, _presentation
from lhie_candidate.payments.sakurupiah_urls import (
    validate_sakurupiah_checkout_url, validate_sakurupiah_qr_image_url,
)


class SakurupiahQrUrlTests(unittest.TestCase):
    def test_only_bounded_sandbox_asset_png_paths_are_accepted(self):
        valid = 'https://sakurupiah.id/assets/qr/synthetic.png'
        invalid = (
            'https://sakurupiah.id/admin/action.png',
            'https://sakurupiah.id/api/production.png',
            'https://sakurupiah.id/assets/../admin.png',
            'https://sakurupiah.id/assets/./qr.png',
            'https://sakurupiah.id/assets/%2e%2e/admin.png',
            'https://sakurupiah.id/assets/qr%2epng',
            'https://sakurupiah.id/assets/qr.png?x=1',
            'https://sakurupiah.id/assets/qr.png#fragment',
            'https://sakurupiah.id/assets/qr.jpg',
            'http://sakurupiah.id/assets/qr.png',
            'https://user@sakurupiah.id/assets/qr.png',
            'https://sakurupiah.id:443/assets/qr.png',
            'https://sakurupiah.id.evil.example/assets/qr.png',
            'https://sakurupiah.id/assets/qr\\bad.png',
            'https://sakurupiah.id/assets/qr\n.png',
            'https://sakurupiah.id/assets/' + 'a' * 2048 + '.png',
            None,
        )
        self.assertTrue(validate_sakurupiah_qr_image_url(valid))
        for url in invalid:
            with self.subTest(url=url):
                self.assertFalse(validate_sakurupiah_qr_image_url(url))

    def test_checkout_rejects_unsafe_snapshot_image_even_with_hosted_link(self):
        snapshot = {'mode': 'qris_image', 'qris_payload': None,
                    'qris_image_url': 'https://sakurupiah.id/api/admin.png',
                    'checkout_url': 'https://sakurupiah.id/sanbox-payout.php?token=synthetic',
                    'effective_expires_at': None}
        self.assertEqual(_presentation_values(snapshot), (None, None, None))
        snapshot['qris_image_url'] = 'https://sakurupiah.id/assets/qr.png'
        self.assertEqual(_presentation_values(snapshot)[:2],
                         (snapshot['qris_image_url'], snapshot['checkout_url']))

    def test_lifecycle_rejects_unsafe_provider_image(self):
        expiry = datetime.now() + timedelta(hours=1)
        payment = {'expired_at': expiry}
        order = {'expires_at': expiry}
        outcome = SimpleNamespace(qr='https://sakurupiah.id/assets/../admin.png', checkout_url=None)
        with self.assertRaises(ValueError):
            _presentation(outcome, payment, order, 2, expiry)
        outcome.qr = 'https://sakurupiah.id/assets/qr.png'
        _, snapshot = _presentation(outcome, payment, order, 2, expiry)
        self.assertEqual(snapshot['qris_image_url'], outcome.qr)

    def test_lifecycle_revalidates_persisted_image(self):
        expiry = datetime.now() + timedelta(days=1)
        snapshot = {'mode': 'qris_image', 'qris_payload': None, 'customer_pays_fee': True,
                    'qris_image_url': 'https://sakurupiah.id/admin/qr.png', 'checkout_url': None,
                    'effective_expires_at': expiry.isoformat()}
        lifecycle = SakurupiahLifecycle(None, SimpleNamespace(decrypt_json=lambda _: snapshot))
        lifecycle.db = SimpleNamespace(one=lambda *args: None)
        order = {'id': 1, 'expires_at': expiry, 'order_status': 'pending_payment', 'payment_status': 'pending'}
        payment = {'status': 'pending', 'provider_create_state': 'created', 'provider_presentation': 'ciphertext',
                   'expired_at': expiry, 'provider_expires_at': expiry, 'provider_reference': 'ref',
                   'provider_payment_method': 'QRIS', 'sakurupiah_channel': 'QRIS',
                   'provider_fee': 200, 'provider_total_payment': 1200, 'amount': 1000}
        self.assertEqual(lifecycle._result(order, payment).state, 'provider_invalid_result')
        snapshot['qris_image_url'] = 'https://sakurupiah.id/assets/qr.png'
        self.assertEqual(lifecycle._result(order, payment).state, 'created')
        snapshot['checkout_url'] = 'https://sakurupiah.id/api/status?token=synthetic'
        self.assertEqual(lifecycle._result(order, payment).state, 'provider_invalid_result')
        snapshot['checkout_url'] = 'https://sakurupiah.id/sanbox-payout.php?token=synthetic'
        self.assertEqual(lifecycle._result(order, payment).state, 'created')

    def test_hosted_url_is_exact_sandbox_route_with_opaque_query(self):
        valid = 'https://sakurupiah.id/sanbox-payout.php?token=synthetic%2Fvalue'
        self.assertTrue(validate_sakurupiah_checkout_url(valid))
        invalid = (
            'https://sakurupiah.id/sanbox-payout.php',
            'https://sakurupiah.id/sanbox-payout.php?',
            'https://sakurupiah.id/admin?token=synthetic',
            'https://sakurupiah.id/api/status?token=synthetic',
            'https://sakurupiah.id/%73anbox-payout.php?token=synthetic',
            'https://sakurupiah.id/sanbox-payout.php/extra?token=synthetic',
            'http://sakurupiah.id/sanbox-payout.php?token=synthetic',
            'https://evil.example/sanbox-payout.php?token=synthetic',
            'https://sakurupiah.id.evil.example/sanbox-payout.php?token=synthetic',
            'https://user@sakurupiah.id/sanbox-payout.php?token=synthetic',
            'https://sakurupiah.id:443/sanbox-payout.php?token=synthetic',
            'https://sakurupiah.id/sanbox-payout.php?token=x#fragment',
            'https://sakurupiah.id/sanbox-payout.php?token=x#',
            'https://sakurupiah.id/sanbox-payout.php?token=x\\y',
            'https://sakurupiah.id/sanbox-payout.php?token=x\n',
            'https://sakurupiah.id/sanbox-payout.php?token=é',
            'https://sakurupiah.id/sanbox-payout.php?' + 'a' * 2048,
            None,
        )
        for url in invalid:
            with self.subTest(url=url):
                self.assertFalse(validate_sakurupiah_checkout_url(url))

    def test_hosted_snapshot_and_provider_creation_fail_closed(self):
        good = 'https://sakurupiah.id/sanbox-payout.php?token=synthetic%2Fvalue'
        snapshot = {'mode': 'hosted', 'qris_payload': None, 'qris_image_url': None,
                    'checkout_url': good, 'effective_expires_at': None}
        self.assertEqual(_presentation_values(snapshot), (None, good, None))
        expiry = datetime.now() + timedelta(hours=1)
        outcome = SimpleNamespace(qr=None, checkout_url=good)
        _, created = _presentation(outcome, {'expired_at': expiry}, {'expires_at': expiry}, 2, expiry)
        self.assertEqual(created['checkout_url'], good)
        snapshot['checkout_url'] = outcome.checkout_url = 'https://sakurupiah.id/admin?token=synthetic'
        self.assertEqual(_presentation_values(snapshot), (None, None, None))
        with self.assertRaises(ValueError):
            _presentation(outcome, {'expired_at': expiry}, {'expires_at': expiry}, 2, expiry)
