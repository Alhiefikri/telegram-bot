"""Receipt relay authorization and delivery-ambiguity checks without network access."""
import base64
import hashlib
import json
import os
from pathlib import Path
import sys
import tempfile
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

    def test_dual_approval_relays_only_admitted_receipts(self):
        config = {**self.config, 'mode': 'p08-sandbox-testing-bot',
                  'sandbox_token': 'test-key', 'sandbox_status_url': 'https://sakurupiah.id/api-sanbox/',
                  'p08_callback': {'gate': 'P08-approved-sandbox-callback-only'}, 'p08_api_id': 'test-id',
                  'p08_phone': '628000000000', 'p08_callback_url': 'https://callback.example/callback/sakurupiah',
                  'p08_return_url': 'https://callback.example/return'}
        scope = {key: config[key] for key in sorted(set(config) - {'approval'})}
        config['approval'] = {'gate': 'P08-approved-dual-targets-only',
                              'scope_sha256': hashlib.sha256(json.dumps(scope, sort_keys=True).encode()).hexdigest()}
        harness.validate(config)
        with patch.object(boundary, 'upstream', side_effect=AssertionError('network reached')):
            self.assertEqual(boundary.receipt_request(config, self.request, 1),
                             {'ok': False, 'error_code': 403})
        config['p08_receipt_delivery'] = True
        with self.assertRaises(harness.Refused):
            harness.validate(config)
        scope = {key: config[key] for key in sorted(set(config) - {'approval'})}
        config['approval']['scope_sha256'] = hashlib.sha256(json.dumps(scope, sort_keys=True).encode()).hexdigest()
        harness.validate(config)
        for invalid in (False, 1, 'true', None):
            with self.subTest(receipt_delivery=invalid), self.assertRaises(harness.Refused):
                harness.validate({**config, 'p08_receipt_delivery': invalid})
        capped = {**config, 'p08_create_limit': 1, 'p08_create_amount': 500}
        with self.assertRaises(harness.Refused):
            harness.validate(capped)
        scope = {key: capped[key] for key in sorted(set(capped) - {'approval'})}
        capped['approval'] = {'gate': 'P08-approved-dual-targets-only',
                              'scope_sha256': hashlib.sha256(json.dumps(scope, sort_keys=True).encode()).hexdigest()}
        harness.validate(capped)
        self.assertEqual(boundary.initialize_h1(harness.default('candidate'), '/missing'), (None, None))
        with tempfile.TemporaryDirectory() as state:
            self.assertEqual(boundary.initialize_h1(config, state), (None, None))
            legacy = {**config, 'p08_create_limit': 1}
            scope = {key: legacy[key] for key in sorted(set(legacy) - {'approval'})}
            legacy['approval'] = {'gate': 'P08-approved-dual-targets-only',
                                  'scope_sha256': hashlib.sha256(json.dumps(scope, sort_keys=True).encode()).hexdigest()}
            descriptor, legacy_proof = boundary.initialize_h1(legacy, state)
            os.close(descriptor)
            self.assertIsNone(legacy_proof)
            descriptor, proof = boundary.initialize_h1(capped, state)
            try:
                self.assertEqual(proof, {'version': 'p01-h1-relay-v1',
                                         'scope_sha256': capped['approval']['scope_sha256'],
                                         'p08_create_limit': 1, 'p08_create_amount': 500,
                                         'p08_receipt_delivery': True})
            finally:
                os.close(descriptor)
            with self.assertRaises(harness.Refused):
                boundary.initialize_h1({**capped, 'safe_chat_ids': [2]}, state)
        with self.assertRaises(OSError):
            boundary.initialize_h1(capped, '/nonexistent-h1-state')
        spec = harness.compose(capped, Path('/tmp/p01-private'))
        self.assertIn('/tmp/p01-private/p08-create-state:/run/harness/p08-create-state',
                      spec['services']['boundary']['volumes'])
        self.assertNotIn('/tmp/p01-private/p08-create-state:/run/harness/p08-create-state',
                         harness.compose(config, Path('/tmp/p01-private'))['services']['boundary']['volumes'])
        for invalid in (True, 0, 2, '1', None):
            with self.subTest(limit=invalid), self.assertRaises(harness.Refused):
                harness.validate({**capped, 'p08_create_limit': invalid})
        for invalid in (True, False, 0, -1, '500', None, 1.5):
            with self.subTest(amount=invalid), self.assertRaises(harness.Refused):
                harness.validate({**capped, 'p08_create_amount': invalid})
        with self.assertRaises(harness.Refused):
            harness.validate({**capped, 'p08_create_amount': 501})
        without_limit = {key: value for key, value in capped.items() if key != 'p08_create_limit'}
        with self.assertRaises(harness.Refused):
            harness.validate(without_limit)
        with self.assertRaises(harness.Refused):
            harness.validate({**capped, 'mode': 'sandbox'})
        with patch.object(boundary, 'upstream', return_value={'ok': True, 'result': {'message_id': 42}}) as send:
            self.assertEqual(boundary.receipt_request(config, self.request, 1),
                             {'ok': True, 'result': {'message_id': 42}})
            args, kwargs = send.call_args
            self.assertEqual(args[0], 'https://api.telegram.org/bot' + config['bot_token'] + '/sendDocument')
            self.assertIn(self.document, kwargs['raw_body'])
            send.reset_mock()
            foreign = {'method': 'sendDocument', 'payload': {**self.request['payload'], 'chat_id': '2'}}
            with self.assertRaises(ValueError):
                boundary.receipt_request(config, foreign, 1)
            for approval in (None, {}, {'gate': 'P09-approved-receipt-targets-only'},
                             {'gate': 'H0-approved-test-targets-only'}):
                denied = {**config, 'approval': approval}
                self.assertEqual(boundary.receipt_request(denied, self.request, 1),
                                 {'ok': False, 'error_code': 403})
            with self.assertRaises(ValueError):
                boundary.receipt_request(config, {'method': 'sendVideo', 'payload': self.request['payload']}, 1)
            invalid_attachment = {'method': 'sendDocument',
                                  'payload': {**self.request['payload'], 'document': 'not-base64!'}}
            with self.assertRaises(ValueError):
                boundary.receipt_request(config, invalid_attachment, 1)
            send.assert_not_called()

    def test_fake_receipt_does_not_reach_telegram(self):
        config = harness.default('candidate')
        with patch.object(boundary, 'upstream', side_effect=AssertionError('network reached')) as send:
            self.assertEqual(boundary.receipt_request(config, self.request, 7),
                             {'ok': True, 'result': {'message_id': 7}})
            send.assert_not_called()

    def test_old_boundary_image_is_rejected_and_matching_image_is_pinned(self):
        identity = 'sha256:' + 'a' * 64
        image = {'Id': identity, 'Config': {'Labels': {}}}
        with patch.object(harness, 'run', return_value=json.dumps([image])):
            with self.assertRaises(harness.Refused):
                harness.compatible_boundary_image()
        image['Config']['Labels']['lhie.p01.h1-source-sha256'] = harness.boundary_build_digest()
        with patch.object(harness, 'run', return_value=json.dumps([image])):
            self.assertEqual(harness.compatible_boundary_image(), identity)

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


class BotIdentityRelayTests(unittest.TestCase):
    def test_fake_and_unverified_identity_never_supply_a_bot_username(self):
        with patch.object(boundary, 'upstream', side_effect=AssertionError('network reached')):
            self.assertEqual(boundary.verified_bot_identity(harness.default('candidate')), {'ok': False})
        config = {**harness.default('candidate'), 'mode': 'testing-bot',
                  'bot_id': 123, 'bot_username': 'LhieStoreTestBot', 'bot_token': 'synthetic-secret'}
        for bot in ({'id': 999, 'username': 'LhieStoreTestBot', 'is_bot': True},
                    {'id': 123, 'username': 'DifferentBot', 'is_bot': True},
                    {'id': 123, 'username': 'LhieStoreTestBot', 'is_bot': 1}):
            with patch.object(boundary, 'upstream', return_value={'ok': True, 'result': bot}):
                with self.assertRaises(ValueError):
                    boundary.verified_bot_identity(config)

    def test_only_verified_attested_identity_is_returned_without_credentials(self):
        config = {**harness.default('candidate'), 'mode': 'testing-bot',
                  'bot_id': 123, 'bot_username': 'LhieStoreTestBot', 'bot_token': 'synthetic-secret'}
        bot = {'id': 123, 'username': 'LhieStoreTestBot', 'is_bot': True}
        with patch.object(boundary, 'upstream', return_value={'ok': True, 'result': bot}):
            result = boundary.verified_bot_identity(config)
        self.assertEqual(result, {'ok': True, 'result': bot})
        self.assertNotIn('synthetic-secret', json.dumps(result))


if __name__ == '__main__':
    unittest.main()
