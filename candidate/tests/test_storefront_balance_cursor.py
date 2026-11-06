import json
import unittest
from unittest.mock import patch

from lhie_candidate.compat.crypto import LaravelEncrypter
from lhie_candidate.storefront import balance_cursor


class BalanceCursorTests(unittest.TestCase):
    def setUp(self):
        self.key = b'x' * 32
        self.key_patch = patch.object(balance_cursor, 'application_key', return_value=self.key)
        self.key_patch.start()
        self.addCleanup(self.key_patch.stop)

    def test_cursor_is_laravel_encrypted_and_bound_to_customer_and_currency(self):
        position = (1767320000123456, 2, 123)
        token = balance_cursor.encode(7, 'IDR', position)
        plain = json.loads(LaravelEncrypter(self.key).decrypt_string(token))
        self.assertEqual(plain, {'version': 1, 'customer_id': 7, 'currency': 'IDR',
                                 'occurred_at': position[0], 'rank': 2, 'id': 123})
        self.assertEqual(balance_cursor.decode(token, 7, 'IDR'), position)
        self.assertIsNone(balance_cursor.decode(token, 8, 'IDR'))
        self.assertIsNone(balance_cursor.decode(token, 7, 'USDT'))
        self.assertIsNone(balance_cursor.decode(token[:-1] + ('A' if token[-1] != 'A' else 'B'), 7, 'IDR'))

    def test_invalid_cursor_values_are_rejected(self):
        cipher = LaravelEncrypter(self.key)
        base = {'version': 1, 'customer_id': 7, 'currency': 'USDT', 'occurred_at': 1, 'rank': 1, 'id': 1}
        for key, value in (('version', True), ('customer_id', True), ('occurred_at', 0), ('rank', 3), ('id', -1)):
            payload = dict(base, **{key: value})
            with self.subTest(key=key):
                token = cipher.encrypt_string(json.dumps(payload))
                self.assertIsNone(balance_cursor.decode(token, 7, 'USDT'))
