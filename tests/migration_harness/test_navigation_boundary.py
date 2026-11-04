import base64
import hashlib
import json
import sys
from pathlib import Path
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'tools/migration_harness'))
from boundary import navigation_request, telegram_request
from harness import FIELDS, Refused, default, validate


class NavigationBoundaryTests(unittest.TestCase):
    def test_fake_navigation_returns_realistic_message_ack_without_egress(self):
        with patch('boundary.upstream') as upstream:
            result = navigation_request(default('candidate'), {'method':'sendMessage','payload':{
                'chat_id':1,'text':'Synthetic','reply_markup':{'inline_keyboard':[[
                    {'text':'Unavailable','disabled':{},'style':'danger'}]]}}}, 1001)
        self.assertEqual(result, {'ok':True,'result':{'message_id':1001}})
        upstream.assert_not_called()

    def test_generated_payment_images_are_fake_only_even_with_live_target_approval(self):
        image = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN2kAAAAASUVORK5CYII='
        request = {'method': 'sendPhoto', 'payload': {
            'chat_id': 1, 'photo': {'payment_image': image}, 'caption': 'Synthetic QR'}}
        with patch('boundary.upstream') as upstream, patch('boundary.navigation_telegram.request') as relay:
            self.assertEqual(navigation_request(default('candidate'), request, 1001),
                             {'ok': True, 'result': {'message_id': 1001}})
            for mode, gate in (('testing-bot', 'P06-approved-navigation-targets-only'),
                               ('p08-sandbox-testing-bot', 'P08-approved-dual-targets-only')):
                config = {**default('candidate'), 'mode': mode, 'bot_token': 'synthetic',
                          'approval': {'gate': gate}}
                with self.subTest(mode=mode), self.assertRaises(ValueError):
                    navigation_request(config, request, 1001)
            for encoded in ('not-base64', base64.b64encode(b'not an image').decode()):
                with self.subTest(encoded=encoded), self.assertRaises(ValueError):
                    navigation_request(default('candidate'), {'method': 'sendPhoto', 'payload': {
                        'chat_id': 1, 'photo': {'payment_image': encoded}}}, 1001)
            upstream.assert_not_called()
            relay.assert_not_called()

    def test_navigation_never_enables_telegram_link_previews(self):
        config = {**default('candidate'), 'mode': 'p08-sandbox-testing-bot',
                  'bot_token': 'test-token',
                  'approval': {'gate': 'P08-approved-dual-targets-only'}}
        visible = []
        previews = []

        def telegram(_, payload):
            visible.append(payload['text'])
            options = payload.get('link_preview_options', {})
            if not options.get('is_disabled'):
                previews.append(options.get('url') or payload['text'])
            return {'ok': True}

        with patch('boundary.navigation_telegram.request', side_effect=telegram):
            for method in ('sendMessage', 'editMessageText'):
                payload = {'chat_id': 1, 'text': 'https://preview.invalid/synthetic'}
                if method == 'editMessageText':
                    payload['message_id'] = 4
                navigation_request(config, {'method': method, 'payload': payload}, 1001)
                for override in ({'link_preview_options': {'url': 'https://preview.invalid/override'}},
                                 {'link_preview_options': {'is_disabled': False}},
                                 {'disable_web_page_preview': False}):
                    with self.subTest(method=method, override=override), self.assertRaises(ValueError):
                        navigation_request(config, {'method': method, 'payload': {**payload, **override}}, 1001)

        self.assertEqual(visible, ['https://preview.invalid/synthetic'] * 2)
        self.assertEqual(previews, [])

    def test_text_relay_never_enables_telegram_link_previews(self):
        config = {**default('candidate'), 'mode': 'testing-bot', 'bot_token': 'test-token'}
        previews = []

        def telegram(_, payload):
            if not payload.get('link_preview_options', {}).get('is_disabled'):
                previews.append(payload['text'])
            return {'ok': True}

        with patch('boundary.upstream', side_effect=telegram):
            self.assertEqual(telegram_request(config, {'chat_id': 1, 'text': 'https://preview.invalid/synthetic'}),
                             {'ok': True})
        self.assertEqual(previews, [])

    def test_foreign_destinations_urls_uploads_and_non_navigation_methods_are_denied(self):
        config = default('candidate')
        cases = [
            {'method':'sendMessage','payload':{'chat_id':2,'text':'Synthetic'}},
            {'method':'sendPhoto','payload':{'chat_id':1,'photo':'https://example.invalid/production.jpg'}},
            {'method':'setWebhook','payload':{'chat_id':1,'url':'https://example.invalid'}},
            {'method':'pinChatMessage','payload':{'chat_id':1,'message_id':1}},
            {'method':'sendMessage','payload':{'chat_id':1,'text':'Synthetic','token':'secret'}},
            {'method':'sendMessage','payload':{'chat_id':1,'text':'Synthetic','reply_markup':{'inline_keyboard':[[
                {'text':'Bad','callback_data':'a'*65}]]}}},
        ]
        with patch('boundary.upstream') as upstream:
            for body in cases:
                with self.subTest(body=body), self.assertRaises(ValueError):
                    navigation_request(config,body,1001)
        upstream.assert_not_called()

    def test_dual_approval_allows_only_provider_photo_urls(self):
        config = {**default('candidate'), 'mode': 'p08-sandbox-testing-bot',
                  'bot_token': 'test-token',
                  'approval': {'gate': 'P08-approved-dual-targets-only'}}
        photo = 'https://sakurupiah.id/assets/qr/synthetic.png'
        requests = [
            {'method': 'sendPhoto', 'payload': {'chat_id': 1, 'photo': photo}},
            {'method': 'editMessageMedia', 'payload': {'chat_id': 1, 'message_id': 4,
                                                       'media': {'type': 'photo', 'media': photo}}},
        ]
        with patch('boundary.navigation_telegram.request', return_value={'ok': True}) as relay:
            for request in requests:
                with self.subTest(method=request['method']):
                    self.assertEqual(navigation_request(config, request, 1001), {'ok': True})
                    self.assertEqual(relay.call_args.args[1], request['payload'])
            self.assertEqual(relay.call_count, 2)

    def test_photo_url_validation_rejects_deceptive_and_malformed_urls(self):
        config = {**default('candidate'), 'mode': 'p08-sandbox-testing-bot',
                  'bot_token': 'test-token',
                  'approval': {'gate': 'P08-approved-dual-targets-only'}}
        invalid = (
            'http://sakurupiah.id/assets/qr.png', 'https://sakurupiah.id.evil.example/assets/qr.png',
            'https://evil.example/assets/qr.png', 'https://sakurupiah.id@evil.example/assets/qr.png',
            'https://user@sakurupiah.id/assets/qr.png', 'https://sakurupiah.id:443/assets/qr.png',
            'https://sakurupiah.id:/assets/qr.png', 'https://sakurupiah.id./assets/qr.png',
            'https://sakurupiah.id\\@evil.example/assets/qr.png',
            'https://sakurupiah.id/assets/qr.png#fragment', 'https://sakurupiah.id/assets/qr.png#',
            'https://sakurupiah.id/assets/qr\n.png', '\thttps://sakurupiah.id/assets/qr.png',
            'https://sakurupiah.id/assets/qr\x7f.png', 'https://sakurupiah.id/assets/qr\x85.png',
            'https://[invalid/assets/qr.png', 'https://sakurupiah.id/admin/action.png',
            'https://sakurupiah.id/api/production.png', 'https://sakurupiah.id/assets/../admin.png',
            'https://sakurupiah.id/assets/./qr.png', 'https://sakurupiah.id/assets/%2e%2e/admin.png',
            'https://sakurupiah.id/assets/qr%2epng', 'https://sakurupiah.id/assets/qr.png?x=1',
            'https://sakurupiah.id/assets/qr.png?', 'https://sakurupiah.id/assets/qr.jpg',
            'https://sakurupiah.id/assets//qr.png', 'https://sakurupiah.id/assets/' + 'a' * 2048 + '.png',
        )
        with patch('boundary.navigation_telegram.request') as relay:
            for url in invalid:
                for method, payload in (
                    ('sendPhoto', {'chat_id': 1, 'photo': url}),
                    ('editMessageMedia', {'chat_id': 1, 'message_id': 4,
                                          'media': {'type': 'photo', 'media': url}}),
                ):
                    with self.subTest(url=url, method=method), self.assertRaises(ValueError):
                        navigation_request(config, {'method': method, 'payload': payload}, 1001)
            relay.assert_not_called()

    def test_photo_url_does_not_broaden_modes_methods_media_or_chat_targets(self):
        photo = 'https://sakurupiah.id/assets/qr/synthetic.png'
        dual = {**default('candidate'), 'mode': 'p08-sandbox-testing-bot',
                'bot_token': 'test-token',
                'approval': {'gate': 'P08-approved-dual-targets-only'}}
        requests = [
            {'method': 'sendPhoto', 'payload': {'chat_id': 2, 'photo': photo}},
            {'method': 'sendAnimation', 'payload': {'chat_id': 1, 'animation': photo}},
            {'method': 'sendMessage', 'payload': {'chat_id': 1, 'photo': photo}},
            {'method': 'sendPhoto', 'payload': {'chat_id': 1, 'media': {'type': 'photo', 'media': photo}}},
            {'method': 'editMessageMedia', 'payload': {'chat_id': 1, 'message_id': 4,
                                                       'media': {'type': 'animation', 'media': photo}}},
        ]
        with patch('boundary.navigation_telegram.request') as relay:
            for request in requests:
                with self.subTest(request=request), self.assertRaises(ValueError):
                    navigation_request(dual, request, 1001)
            for mode, gate in (('fake', None), ('sandbox', None),
                               ('testing-bot', 'P06-approved-navigation-targets-only'),
                               ('p08-sandbox-testing-bot', 'H0-approved-test-targets-only')):
                config = {**dual, 'mode': mode, 'approval': {'gate': gate}}
                for request in (
                    {'method': 'sendPhoto', 'payload': {'chat_id': 1, 'photo': photo}},
                    {'method': 'editMessageMedia', 'payload': {'chat_id': 1, 'message_id': 4,
                                                               'media': {'type': 'photo', 'media': photo}}},
                ):
                    with self.subTest(mode=mode, method=request['method']), self.assertRaises(ValueError):
                        navigation_request(config, request, 1001)
            relay.assert_not_called()

    def test_file_ids_remain_supported_for_photos_and_animations(self):
        config = default('candidate')
        for method, payload in (
            ('sendPhoto', {'chat_id': 1, 'photo': 'TEST_file-123'}),
            ('sendAnimation', {'chat_id': 1, 'animation': 'TEST_file-123'}),
            ('editMessageMedia', {'chat_id': 1, 'message_id': 4,
                                  'media': {'type': 'photo', 'media': 'TEST_file-123'}}),
            ('editMessageMedia', {'chat_id': 1, 'message_id': 4,
                                  'media': {'type': 'animation', 'media': 'TEST_file-123'}}),
        ):
            with self.subTest(method=method, payload=payload):
                self.assertTrue(navigation_request(config, {'method': method, 'payload': payload}, 1001)['ok'])

    def test_sandbox_mode_cannot_become_a_telegram_transport(self):
        config = {**default('candidate'),'mode':'sandbox'}
        with patch('boundary.upstream') as upstream, self.assertRaises(ValueError):
            navigation_request(config,{'method':'sendMessage','payload':{'chat_id':1,'text':'Synthetic'}},1001)
        upstream.assert_not_called()

    def test_text_only_approval_cannot_silently_enable_navigation_or_change_targets(self):
        config = default('candidate')
        config.update(mode='testing-bot',bot_token='123:' + 'a'*35,
                      bot_id=123,bot_username='LhieStoreTestBot')
        scope = {key:config[key] for key in sorted(FIELDS - {'approval'})}
        config['approval'] = {'gate':'H0-approved-test-targets-only',
                              'scope_sha256':hashlib.sha256(json.dumps(scope,sort_keys=True).encode()).hexdigest()}
        validate(config)
        with patch('boundary.upstream') as upstream:
            with self.assertRaises(ValueError):
                navigation_request(config,{'method':'sendMessage','payload':{'chat_id':1,'text':'Synthetic'}},1001)
            config['approval']['gate'] = 'P06-approved-navigation-targets-only'
            validate(config)
            with self.assertRaises(ValueError):
                navigation_request(config,{'method':'sendMessage','payload':{'chat_id':2,'text':'Synthetic'}},1001)
            config['safe_chat_ids'] = [2]
            with self.assertRaises(Refused):
                validate(config)
        upstream.assert_not_called()

    def test_navigation_approval_cannot_authorize_sandbox_provider_mode(self):
        config = default('candidate')
        config.update(mode='sandbox',sandbox_token='synthetic',
                      sandbox_status_url='https://klikqris.com/api/sandbox/example/status')
        scope = {key:config[key] for key in sorted(FIELDS - {'approval'})}
        config['approval'] = {'gate':'P06-approved-navigation-targets-only',
                              'scope_sha256':hashlib.sha256(json.dumps(scope,sort_keys=True).encode()).hexdigest()}
        with self.assertRaises(Refused):
            validate(config)
