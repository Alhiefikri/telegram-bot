"""Waitlist signature, immutable-mail and opt-out boundaries without PostgreSQL."""
from datetime import timedelta
from html import escape
import json
from pathlib import Path
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

from django.template import Context, Engine

from lhie_candidate.compat.crypto import LaravelEncrypter
from lhie_candidate.compat.identity import signed_url_digest, waitlist_email_hash
from lhie_candidate.notifications.mail import DefiniteMailFailure
from lhie_candidate.notifications.policy import NotEligible, TestRecipients as Recipients
from lhie_candidate.notifications.waitlist import CONFIRMATION, RESTOCK, WaitlistMail, mail_content, safe_product_name
from lhie_candidate.receipt.protocol import _now


KEY = 'base64:QUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUE='


class WaitlistTests(unittest.TestCase):
    def service(self, *, enabled=True):
        return WaitlistMail(LaravelEncrypter(KEY), signing_key=KEY, base_url='http://127.0.0.1:18801',
                            recipients=Recipients(frozenset({'1'}) if enabled else frozenset()),
                            transport=SimpleNamespace(send=Mock()))

    def render(self, name, context):
        engine = Engine(dirs=[str(Path(__file__).resolve().parents[1] / 'templates')])
        return engine.get_template(name).render(Context(context, use_l10n=False))

    def test_mail_escapes_dynamic_values_and_preserves_action_targets(self):
        self.assertEqual(self.service().app_name, 'LHIE STORE')
        context = {'app_name': 'LhieStore', 'product_name': '<Synthetic>& Product',
                   'confirmation_url': 'http://127.0.0.1:18801/waitlist/confirm/7?expires=9&signature=x',
                   'available_stock': 3, 'product_url': 'http://127.0.0.1:18801/produk/7',
                   'unsubscribe_url': 'http://127.0.0.1:18801/waitlist/unsubscribe/7?expires=9&signature=x'}
        with patch('lhie_candidate.notifications.waitlist.render_to_string', side_effect=self.render):
            confirmation = mail_content(CONFIRMATION, context)
            restock = mail_content(RESTOCK, context)
        self.assertIn('&lt;Synthetic&gt;&amp; Product', confirmation['html'])
        self.assertNotIn('<Synthetic>', confirmation['html'])
        self.assertIn('href="' + escape(context['confirmation_url'], quote=True) + '"', confirmation['html'])
        self.assertIn('href="' + escape(context['product_url'], quote=True) + '"', restock['html'])
        self.assertIn('href="' + escape(context['unsubscribe_url'], quote=True) + '"', restock['html'])
        self.assertIn('3', restock['text'])

    def test_signed_links_bind_absolute_origin_path_query_and_expiry(self):
        service = self.service()
        url = service.signed_url('confirm', 7, _now() + timedelta(minutes=5))
        unsigned, signature = url.rsplit('&signature=', 1)
        self.assertEqual(signature, signed_url_digest(unsigned, KEY))
        self.assertTrue(service.valid_url(url, 'confirm', 7))
        for altered in (url.replace(':18801', ':18802'), url.replace('/confirm/', '/unsubscribe/'),
                        url.replace('/7?', '/8?'), url + '&x=1', url + '&expires=1',
                        url + '&signature=' + signature, url + '#fragment'):
            self.assertFalse(service.valid_url(altered, 'confirm', 7))
        self.assertFalse(service.valid_url(service.signed_url('confirm', 7, _now() - timedelta(seconds=1)), 'confirm', 7))
        self.assertFalse(service.valid_url(url, 'unsubscribe', 7))

    def test_email_envelope_and_hmac_are_both_required(self):
        service = self.service()
        row = {'email': service.encrypter.encrypt_string(' Synthetic@Example.Invalid '),
               'email_hash': waitlist_email_hash('synthetic@example.invalid', KEY)}
        self.assertEqual(service._address(row), 'synthetic@example.invalid')
        row['email_hash'] = '0' * 64
        with self.assertRaises(NotEligible):
            service._address(row)
        row['email'] = service.encrypter.encrypt_string('real@example.com')
        with self.assertRaises(NotEligible):
            service._address(row)

    def test_disabled_composition_has_no_database_or_transport_work(self):
        service = self.service(enabled=False)
        service.db = SimpleNamespace(one=Mock(), execute=Mock(), rows=Mock())
        service._outside = Mock()
        self.assertFalse(service.subscribe(7, 'synthetic@example.invalid'))
        self.assertEqual(service.materialize(), 0)
        self.assertEqual(service.recover(), {'disabled': True})
        self.assertEqual(service.deliver('effect'), 'disabled')
        service.db.one.assert_not_called()
        service.db.rows.assert_not_called()
        service.db.execute.assert_not_called()
        service.transport.send.assert_not_called()

    def test_only_shared_protocol_owns_smtp_attempt_outcomes(self):
        for outcome in ('sent', 'definite', 'unknown'):
            with self.subTest(outcome=outcome):
                service = self.service()
                service._outside = Mock()
                service._capture_attempt = Mock(return_value=True)
                effect = {'id': 'effect', 'claim_token': 'token'}
                service.protocol = SimpleNamespace(claim=Mock(return_value=effect),
                    finalize_sent=Mock(return_value={'state': 'sent'}),
                    finalize_failed=Mock(return_value={'state': 'failed'}),
                    finalize_unknown=Mock(return_value={'state': 'unknown'}))
                service.payload = Mock(return_value={'recipient': 'synthetic@example.invalid',
                    'subject': 'Synthetic', 'text': 'Exact text', 'html': '<p>Exact HTML</p>'})
                service.project = Mock()
                if outcome != 'sent':
                    service.transport.send.side_effect = DefiniteMailFailure() if outcome == 'definite' else TimeoutError()
                expected = 'failed' if outcome == 'definite' else outcome
                self.assertEqual(service.deliver('effect'), expected)
                service.transport.send.assert_called_once_with('synthetic@example.invalid', 'Synthetic', 'Exact text', '<p>Exact HTML</p>')
                if outcome == 'sent':
                    service.protocol.finalize_sent.assert_called_once_with('effect', 'token', 1)
                elif outcome == 'definite':
                    service.protocol.finalize_failed.assert_called_once_with('effect', 'token', 'mail_delivery_failed', retry_after_seconds=60)
                else:
                    service.protocol.finalize_unknown.assert_called_once_with('effect', 'token')
                    service.protocol.finalize_failed.assert_not_called()

    def test_immutable_snapshot_contains_encrypted_recipient_and_both_bodies(self):
        service = self.service()
        service.db = SimpleNamespace(require_transaction=Mock(), one=Mock(return_value=None))
        row = {'id': 7, 'email': service.encrypter.encrypt_string('synthetic@example.invalid'),
               'email_hash': waitlist_email_hash('synthetic@example.invalid', KEY),
               'confirmation_expires_at': _now() + timedelta(minutes=5), 'confirmation_sent_at': None,
               'confirmation_delivery_started_at': None, 'confirmation_attempts': 0,
               'confirmation_last_error': None}
        with patch('lhie_candidate.notifications.waitlist.render_to_string', side_effect=self.render):
            service._record(CONFIRMATION, row, row, {'id': 8, 'name': 'Synthetic Product'})
        sql, params = service.db.one.call_args.args
        metadata = json.loads(params[-3])
        self.assertNotIn('synthetic@example.invalid', json.dumps(metadata))
        content = json.loads(service.encrypter.decrypt_string(metadata['mail_snapshot']))
        self.assertEqual(content['recipient'], 'synthetic@example.invalid')
        self.assertIn('Konfirmasi permintaan', content['text'])
        self.assertIn('<!doctype html>', content['html'])

    def test_product_name_safety_matches_php_single_line_limit(self):
        self.assertEqual(safe_product_name(' \r\n '), 'Produk')
        self.assertEqual(safe_product_name(' one\r\ntwo '), 'one two')
        self.assertEqual(len(safe_product_name('é' * 200)), 160)
