import hashlib
import json
import sys
from pathlib import Path
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'tools/migration_harness'))
from boundary import navigation_request
from harness import FIELDS, Refused, default, validate


class NavigationBoundaryTests(unittest.TestCase):
    def test_fake_navigation_returns_realistic_message_ack_without_egress(self):
        with patch('boundary.upstream') as upstream:
            result = navigation_request(default('candidate'), {'method':'sendMessage','payload':{
                'chat_id':1,'text':'Synthetic','reply_markup':{'inline_keyboard':[[
                    {'text':'Unavailable','disabled':{},'style':'danger'}]]}}}, 1001)
        self.assertEqual(result, {'ok':True,'result':{'message_id':1001}})
        upstream.assert_not_called()

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
