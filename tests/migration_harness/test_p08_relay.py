"""Sandbox relay admission and credential containment without external requests."""
import base64
import hashlib
import hmac
import json
from pathlib import Path
import sys
import unittest
from unittest.mock import patch
from urllib.parse import parse_qs

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'tools/migration_harness'))
import boundary


class Response:
    status = 200

    def read(self, limit):
        return b'{"status":"200","data":[]}'


class Connection:
    def __init__(self, *args, **kwargs):
        self.requests = []

    def request(self, method, path, body, headers):
        self.requests.append((method, path, body, headers))

    def getresponse(self):
        return Response()

    def close(self):
        pass


class SandboxRelayTests(unittest.TestCase):
    def setUp(self):
        self.config = {'mode': 'sandbox', 'p08_callback': {'gate': 'P08-approved-sandbox-callback-only'},
                       'p08_phone': '0812345678', 'p08_callback_url': 'https://example.test/cb',
                       'p08_return_url': 'https://example.test/return', 'p08_api_id': 'test-merchant',
                       'sandbox_token': 'test-secret'}
        self.fields = {'method': 'QRIS', 'phone': self.config['p08_phone'], 'amount': '10000',
                       'merchant_fee': '2', 'merchant_ref': 'synthetic-ref',
                       'callback_url': self.config['p08_callback_url'],
                       'return_url': self.config['p08_return_url']}

    def test_create_is_confined_to_sandbox_and_signed_at_boundary(self):
        conn = Connection()
        with (patch.object(boundary.socket, 'getaddrinfo', return_value=[(2, 1, 6, '', ('8.8.8.8', 443))]),
              patch.object(boundary.http.client, 'HTTPSConnection', return_value=conn) as factory):
            result = boundary.p08_request(self.config, {'operation': 'create', 'fields': self.fields})
        factory.assert_called_once_with('sakurupiah.id', timeout=5)
        method, path, body, headers = conn.requests[0]
        self.assertEqual((method, path), ('POST', '/api-sanbox/create.php'))
        form = parse_qs(body)
        self.assertEqual(form['signature'], [hmac.new(b'test-secret', b'test-merchantQRISsynthetic-ref10000', hashlib.sha256).hexdigest()])
        self.assertEqual(headers['Authorization'], 'Bearer test-secret')
        self.assertNotIn('test-secret', json.dumps(result))
        self.assertEqual(base64.b64decode(result['body']), b'{"status":"200","data":[]}')

    def test_unapproved_targets_and_operations_never_reach_network(self):
        requests = [
            ({**self.config, 'mode': 'fake'}, {'operation': 'create', 'fields': self.fields}),
            (self.config, {'operation': 'create', 'fields': {**self.fields, 'callback_url': 'https://production.invalid/'}}),
            (self.config, {'operation': 'create', 'fields': {**self.fields, 'phone': '0812349999'}}),
            (self.config, {'operation': 'create', 'fields': {**self.fields, 'amount': '1.5'}}),
            (self.config, {'operation': 'purchase', 'fields': {}}),
        ]
        with patch.object(boundary.socket, 'getaddrinfo', side_effect=AssertionError('network reached')):
            for config, request in requests:
                with self.subTest(request=request), self.assertRaises(ValueError):
                    boundary.p08_request(config, request)


if __name__ == '__main__':
    unittest.main()
