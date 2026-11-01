import json
import os
import unittest
from unittest.mock import MagicMock, patch

from lhie_candidate.runtime import Runtime


class CallbackTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        import django
        os.environ['DJANGO_SETTINGS_MODULE'] = 'lhie_candidate.settings'
        with patch('lhie_candidate.runtime.load_runtime', return_value=Runtime('synthetic-empty', 'a' * 64)):
            django.setup()
        from django.test import Client
        cls.client = Client(raise_request_exception=False, HTTP_HOST='127.0.0.1')

    def request(self, body, secret='b' * 64):
        return self.client.post('/callback/sakurupiah', data=body, content_type='application/json',
                                HTTP_X_P08_INTERNAL_SECRET=secret, HTTP_X_CALLBACK_EVENT='payment_status')

    def test_default_disabled_and_forged_internal_secret_are_denied(self):
        from django.conf import settings
        body = b'{"trx_id":"t1","merchant_ref":"r1","status":"pending","status_kode":0}'
        with patch.object(settings, 'RUNTIME', Runtime('synthetic-empty', 'a' * 64)):
            self.assertEqual(self.request(body).status_code, 403)
        with patch.object(settings, 'RUNTIME', Runtime('synthetic-empty', 'a' * 64, 'b' * 64)):
            self.assertEqual(self.request(body, 'wrong').status_code, 403)

    def test_duplicate_keys_oversized_and_invalid_status_never_enter_inbox(self):
        from django.conf import settings
        bodies = [b'{"trx_id":"t","trx_id":"u"}', b'x' * 16385,
                  json.dumps({'trx_id':'t', 'merchant_ref':'r', 'status':'berhasil', 'status_kode':0}).encode()]
        with patch.object(settings, 'RUNTIME', Runtime('synthetic-empty', 'a' * 64, 'b' * 64)), \
                patch('lhie_candidate.payments.callback.connection') as db:
            for body in bodies:
                self.assertEqual(self.request(body).status_code, 400)
            db.cursor.assert_not_called()

