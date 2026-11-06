import unittest

from lhie_candidate.compat.crypto import LaravelEncrypter
from lhie_candidate.customer_api.admission import Admission, ApiFailure
from lhie_candidate.customer_api.delivery import _decode, _encode
from lhie_candidate.customer_api.orders import OrderRequest


class CustomerApiCompatibilityTests(unittest.TestCase):
    def test_php_canonical_fingerprint_and_exact_usd(self):
        request = OrderRequest.parse('purchase-42', {
            'product_id': '42', 'quantity': 2, 'preference': 'usd_first',
            'expected_totals': {'IDR': '30000', 'USD': '1.250000'},
        })
        self.assertEqual(request.expected_usd_micro, 1250000)
        self.assertEqual(request.fingerprint(), '09045fbcac7ce4c344f3c91fca5cd23bd673712e3aaee3c44cd7c098492156e6')
        same = OrderRequest.parse('purchase-42', {
            'product_id': '42', 'quantity': 2, 'preference': 'usd_first',
            'expected_totals': {'USD': '1.2500000', 'IDR': '30000'},
        })
        self.assertEqual(request.fingerprint(), same.fingerprint())

    def test_rejects_noncanonical_or_unsafe_order_input(self):
        cases = [
            ('', {'product_id': '42', 'quantity': 1}),
            ('ok', {'product_id': 42, 'quantity': 1}),
            ('ok', {'product_id': '042', 'quantity': 1}),
            ('ok', {'product_id': '42', 'quantity': True}),
            ('ok', {'product_id': '42', 'quantity': 101}),
            ('ok', {'product_id': '42', 'quantity': 1, 'expected_totals': {'USD': '0.0000001'}}),
            ('ok', {'product_id': '42', 'quantity': 1, 'preference': []}),
            ('ok', {'product_id': '42', 'quantity': 1, 'inputs': {}}),
        ]
        for key, body in cases:
            with self.subTest(body=body), self.assertRaises(ApiFailure) as error:
                OrderRequest.parse(key, body)
            self.assertEqual(error.exception.status, 422)

    def test_delivery_cursor_is_authenticated_and_key_compatible(self):
        cipher = LaravelEncrypter(b'a' * 32)
        cursor = _encode(12345, cipher)
        self.assertEqual(_decode(cursor, cipher), 12345)
        with self.assertRaises(ApiFailure) as error:
            _decode(cursor, LaravelEncrypter(b'b' * 32))
        self.assertEqual((error.exception.status, error.exception.code), (422, 'invalid_input'))

    def test_unavailable_redis_cannot_admit_requests(self):
        class Down:
            def eval(self, *args):
                raise OSError('connection refused')

        with self.assertRaises(ApiFailure) as error:
            Admission('synthetic-key', client=Down()).ip('127.0.0.1')
        self.assertEqual((error.exception.status, error.exception.code), (503, 'temporarily_unavailable'))
