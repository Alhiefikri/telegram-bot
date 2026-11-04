from dataclasses import replace
import hashlib
import hmac
import json
from unittest import TestCase
from unittest.mock import Mock

from lhie_candidate.payments.sakurupiah import CreateRequest, InvoiceIdentity, OutcomeState
from lhie_candidate.payments.sakurupiah_production import SakurupiahProductionAdapter, SakurupiahProductionConfig
from lhie_candidate.payments.sakurupiah_urls import validate_sakurupiah_checkout_url


class SakurupiahProductionContracts(TestCase):
    def config(self, **changes):
        return replace(SakurupiahProductionConfig(api_id='synthetic-production-id', api_key='synthetic-production-key',
                                                 enabled=True, qualified=True), **changes)

    def request(self, config):
        return CreateRequest(InvoiceIdentity('synthetic-payment', 10000, 'QRISC'), '08000000000',
                             config.callback_url, config.return_url)

    def test_disabled_unqualified_incomplete_or_shared_credentials_never_dispatch(self):
        for config in (SakurupiahProductionConfig(), self.config(enabled=False), self.config(qualified=False),
                       self.config(api_id=''), self.config(api_key=''), self.config(sandbox_api_key='synthetic-production-key'),
                       self.config(callback_url='https://evil.test/callback')):
            transport = Mock()
            result = SakurupiahProductionAdapter(transport=transport, config=config).create(self.request(config))
            self.assertIs(result.state, OutcomeState.REJECTED)
            transport.request.assert_not_called()
            self.assertNotIn('synthetic-production-key', repr(config))

    def test_create_signs_original_exact_identity_only_on_fixed_production_endpoint(self):
        config, transport = self.config(), Mock()
        transport.request.return_value = (200, json.dumps({'status':'200','data':[{
            'trx_id':'production-shaped-test','merchant_ref':'synthetic-payment','payment_kode':'QRISC',
            'payment_status':'pending','total':'10070','fee':'70','merchant_fee':'2',
            'expired':'2030-01-01 00:00:00','checkout_url':'https://sakurupiah.id/payout.php?token=synthetic'}]}).encode())
        result = SakurupiahProductionAdapter(transport=transport, config=config).create(self.request(config))
        self.assertIs(result.state, OutcomeState.CREATED)
        args, kwargs = transport.request.call_args
        self.assertEqual(args, ('POST','https://sakurupiah.id/api/create.php'))
        material = config.api_id + 'QRISCsynthetic-payment10000'
        self.assertEqual(kwargs['fields']['signature'], hmac.new(config.api_key.encode(),material.encode(),hashlib.sha256).hexdigest())
        self.assertEqual(kwargs['fields']['api_id'],config.api_id)
        self.assertEqual(kwargs['headers'], {'Authorization':'Bearer '+config.api_key})
        self.assertEqual(kwargs['allowed_hosts'], ('sakurupiah.id',))
        self.assertIs(kwargs['allow_redirects'], False)
        self.assertEqual(kwargs['fields']['callback_url'],config.callback_url)
        self.assertEqual(kwargs['fields']['merchant_fee'],'2')

    def test_changed_request_targets_are_rejected_before_dispatch(self):
        config, transport = self.config(), Mock()
        request = replace(self.request(config), callback_url='https://other.test/callback')
        result = SakurupiahProductionAdapter(transport=transport, config=config).create(request)
        self.assertIs(result.state, OutcomeState.REJECTED)
        transport.request.assert_not_called()

    def test_callback_authentication_uses_original_bytes_and_production_key(self):
        config = self.config()
        adapter = SakurupiahProductionAdapter(transport=Mock(), config=config)
        body = b'{"merchant_ref":"synthetic-payment","status":"berhasil"}'
        signature = hmac.new(config.api_key.encode(), body, hashlib.sha256).hexdigest()
        self.assertTrue(adapter.authenticate_callback(body,signature))
        for raw, key in ((body+b' ',signature),(body,'0'*64),(body,signature+' '),(body,None)):
            self.assertFalse(adapter.authenticate_callback(raw,key))
        self.assertFalse(SakurupiahProductionAdapter(transport=Mock()).authenticate_callback(body,signature))

    def test_checkout_urls_do_not_cross_environment_or_host_boundaries(self):
        url='https://sakurupiah.id/payout.php?token=synthetic'
        self.assertTrue(validate_sakurupiah_checkout_url(url,'production'))
        self.assertFalse(validate_sakurupiah_checkout_url(url,'sandbox'))
        for invalid in (url.replace('sakurupiah.id','evil.test'),url+'#fragment',url.replace('.id/','.id:443/'),
                        url.replace('/payout','/%70ayout'),url.replace('https://','https://user@')):
            self.assertFalse(validate_sakurupiah_checkout_url(invalid,'production'))
