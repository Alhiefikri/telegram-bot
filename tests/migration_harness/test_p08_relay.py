"""Sandbox relay admission and credential containment without external requests."""
import base64
from concurrent.futures import ThreadPoolExecutor
import hashlib
import hmac
import json
import os
from pathlib import Path
import sys
import tempfile
import threading
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
              patch.object(boundary.http.client, 'HTTPSConnection', return_value=conn)):
            result = boundary.p08_request(self.config, {'operation': 'create', 'fields': self.fields})
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

    def test_attested_amount_rejects_without_reservation_then_accepts_match(self):
        config = {**self.config, 'mode': 'p08-sandbox-testing-bot',
                  'p08_create_limit': 1, 'p08_create_amount': 500}
        conn = Connection()
        with tempfile.TemporaryDirectory() as state:
            descriptor = boundary.open_p08_create_state(state)
            try:
                with (patch.object(boundary, '_P08_CREATE_STATE', descriptor),
                      patch.object(boundary.socket, 'getaddrinfo', side_effect=AssertionError('network reached'))):
                    with self.assertRaises(ValueError):
                        boundary.p08_request(config, {'operation': 'create', 'fields': self.fields})
                    self.assertEqual(os.fstat(descriptor).st_size, 0)
                with (patch.object(boundary, '_P08_CREATE_STATE', descriptor),
                      patch.object(boundary.socket, 'getaddrinfo',
                                   return_value=[(2, 1, 6, '', ('8.8.8.8', 443))]),
                      patch.object(boundary.http.client, 'HTTPSConnection', return_value=conn)):
                    boundary.p08_request(config, {'operation': 'create',
                                                   'fields': {**self.fields, 'amount': '500'}})
                self.assertGreater(os.fstat(descriptor).st_size, 0)
                self.assertEqual(parse_qs(conn.requests[0][2])['amount'], ['500'])
            finally:
                os.close(descriptor)

    def test_single_attempt_is_concurrent_and_restart_safe_after_timeout(self):
        config = {**self.config, 'mode': 'p08-sandbox-testing-bot', 'p08_create_limit': 1}
        request = {'operation': 'create', 'fields': self.fields}
        barrier = threading.Barrier(2)
        conn = Connection()
        def timed_out(*args):
            conn.requests.append(args)
            raise TimeoutError()
        conn.request = timed_out
        with tempfile.TemporaryDirectory() as state:
            descriptor = boundary.open_p08_create_state(state)
            try:
                with (patch.object(boundary, '_P08_CREATE_STATE', descriptor),
                      patch.object(boundary.socket, 'getaddrinfo',
                                   return_value=[(2, 1, 6, '', ('8.8.8.8', 443))]) as dns,
                      patch.object(boundary.http.client, 'HTTPSConnection', return_value=conn)):
                    def attempt():
                        barrier.wait()
                        try:
                            boundary.p08_request(config, request)
                        except (ValueError, TimeoutError) as error:
                            return type(error)
                    with ThreadPoolExecutor(max_workers=2) as executor:
                        results = list(executor.map(lambda _: attempt(), range(2)))
                    self.assertCountEqual(results, [ValueError, TimeoutError])
                    self.assertEqual(len(conn.requests), 1)
                    self.assertEqual(dns.call_count, 1)
                os.close(descriptor)
                descriptor = boundary.open_p08_create_state(state)
                with (patch.object(boundary, '_P08_CREATE_STATE', descriptor),
                      patch.object(boundary.socket, 'getaddrinfo', side_effect=AssertionError('network reached'))):
                    with self.assertRaises(ValueError):
                        boundary.p08_request(config, request)
                conn = Connection()
                with (patch.object(boundary, '_P08_CREATE_STATE', descriptor),
                      patch.object(boundary.socket, 'getaddrinfo',
                                   return_value=[(2, 1, 6, '', ('8.8.8.8', 443))]),
                      patch.object(boundary.http.client, 'HTTPSConnection', return_value=conn)):
                    for operation, fields in (
                            ('list', {'method': 'list'}),
                            ('status', {'method': 'status', 'trx_id': 'synthetic'}),
                            ('history', {'method': 'transaction', 'mechant': '1', 'merchant_ref': 'synthetic'})):
                        boundary.p08_request(config, {'operation': operation, 'fields': fields})
                self.assertEqual(len(conn.requests), 3)
            finally:
                os.close(descriptor)

    def test_capped_create_fails_closed_without_state(self):
        config = {**self.config, 'mode': 'p08-sandbox-testing-bot', 'p08_create_limit': 1}
        with (patch.object(boundary, '_P08_CREATE_STATE', None),
              patch.object(boundary.socket, 'getaddrinfo', side_effect=AssertionError('network reached'))):
            with self.assertRaises(ValueError):
                boundary.p08_request(config, {'operation': 'create', 'fields': self.fields})


if __name__ == '__main__':
    unittest.main()
