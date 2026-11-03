"""Offline regression for the admitted P08 target boundary."""
import importlib.util
from pathlib import Path
import unittest
from unittest.mock import Mock, patch
from urllib.parse import parse_qs

_spec = importlib.util.spec_from_file_location('p08_boundary', Path(__file__).with_name('boundary.py'))
boundary = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(boundary)


class P08BoundaryTests(unittest.TestCase):
    def test_boundary_supplies_admitted_targets_and_rejects_override(self):
        config = {'mode': 'sandbox', 'p08_callback': {'gate': 'P08-approved-sandbox-callback-only'},
                  'p08_phone': '08123456789', 'p08_callback_url': 'https://approved.example/callback/sakurupiah',
                  'p08_return_url': 'https://approved.example/return', 'p08_api_id': 'synthetic',
                  'sandbox_token': 'synthetic-secret'}
        fields = {'method': 'QRIS', 'amount': '10000', 'merchant_fee': '2', 'merchant_ref': 'ref-1'}
        response = Mock(status=200)
        response.read.return_value = b'{}'
        connection = Mock()
        connection.getresponse.return_value = response
        address = [(2, 1, 6, '', ('8.8.8.8', 443))]
        with patch.object(boundary.socket, 'getaddrinfo', return_value=address), \
             patch.object(boundary.http.client, 'HTTPSConnection', return_value=connection):
            result = boundary.p08_request(config, {'operation': 'create', 'fields': fields})
        self.assertEqual(result['status'], 200)
        form = parse_qs(connection.request.call_args.args[2])
        self.assertEqual(form['phone'], [config['p08_phone']])
        self.assertEqual(form['callback_url'], [config['p08_callback_url']])
        self.assertEqual(form['return_url'], [config['p08_return_url']])
        supplied = {**fields, 'phone': '08000000000', 'callback_url': 'https://attacker.invalid/callback',
                    'return_url': config['p08_return_url']}
        with self.assertRaises(ValueError):
            boundary.p08_request(config, {'operation': 'create', 'fields': supplied})
        fake = boundary.fake_p08_request({'operation': 'create', 'fields': fields})
        self.assertEqual(fake['status'], '200')
