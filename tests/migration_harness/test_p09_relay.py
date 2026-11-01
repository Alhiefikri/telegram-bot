"""Receipt relay authorization and delivery-ambiguity checks without network access."""
import base64
import hashlib
import json
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'tools/migration_harness'))
import boundary
import harness


class ReceiptRelayTests(unittest.TestCase):
    def setUp(self):
        self.config = harness.default('candidate')
        self.config.update(mode='testing-bot', bot_token='123:' + 'a' * 35,
                           bot_id=123, bot_username='LhieStoreTestBot')
        scope = {key: self.config[key] for key in sorted(set(self.config) - {'approval'})}
        self.config['approval'] = {'gate': 'P09-approved-receipt-targets-only',
                                   'scope_sha256': hashlib.sha256(json.dumps(scope, sort_keys=True).encode()).hexdigest()}
        self.document = b'synthetic receipt only\n'
        self.request = {'method': 'sendDocument', 'payload': {'chat_id': '1', 'filename': 'synthetic.txt',
                        'document': base64.b64encode(self.document).decode(), 'protect_content': True}}

    def test_other_approvals_and_foreign_chats_cannot_send_receipts(self):
        harness.validate(self.config)
        with patch.object(boundary, 'upstream', side_effect=AssertionError('network reached')):
            for gate in ('P06-approved-navigation-targets-only', 'H0-approved-test-targets-only'):
                denied = {**self.config, 'approval': {'gate': gate}}
                self.assertEqual(boundary.receipt_request(denied, self.request, 1)['error_code'], 403)
            foreign = {'method': 'sendDocument', 'payload': {**self.request['payload'], 'chat_id': '2'}}
            with self.assertRaises(ValueError):
                boundary.receipt_request(self.config, foreign, 1)

    def test_upload_uses_multipart_and_redacts_upstream_response(self):
        with patch.object(boundary, 'upstream', return_value={'ok': True, 'result': {'message_id': 42,
                                                                                    'private': 'discard'}}) as send:
            result = boundary.receipt_request(self.config, self.request, 1)
        self.assertEqual(result, {'ok': True, 'result': {'message_id': 42}})
        args, kwargs = send.call_args
        self.assertEqual(args[0], 'https://api.telegram.org/bot' + self.config['bot_token'] + '/sendDocument')
        self.assertTrue(kwargs['content_type'].startswith('multipart/form-data; boundary='))
        self.assertIn(b'name="document"; filename="synthetic.txt"', kwargs['raw_body'])
        self.assertIn(self.document, kwargs['raw_body'])
        self.assertNotIn(self.document.decode().strip(), json.dumps(result))

    def test_uncertain_upload_is_not_reported_as_definite_failure(self):
        with patch.object(boundary, 'upstream', side_effect=TimeoutError):
            response = boundary.receipt_request(self.config, self.request, 1)
        self.assertEqual(response, {'ok': False, 'error_code': 502})
        with patch.object(boundary, 'upstream', return_value={'ok': False, 'error_code': 429,
                                                               'parameters': {'retry_after': 9999}}):
            response = boundary.receipt_request(self.config, self.request, 1)
        self.assertEqual(response, {'ok': False, 'error_code': 429, 'parameters': {'retry_after': 300}})

    def test_emoji_rejection_is_signalled_without_echoing_description(self):
        reply = boundary.receipt_response({'ok': False, 'error_code': 400,
                                           'description': 'Bad Request: custom emoji rejected synthetic-marker'})
        self.assertEqual(reply, {'ok': False, 'error_code': 400, 'emoji_rejected': True})


if __name__ == '__main__':
    unittest.main()
