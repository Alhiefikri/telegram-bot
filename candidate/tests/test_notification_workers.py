"""Presentation parity and disjoint worker boundaries without a database."""
from types import SimpleNamespace
import smtplib
import unittest
from unittest.mock import Mock, patch

from lhie_candidate.notifications.mail import (CredentialMail, DefiniteMailFailure, SinkMail,
                                                credential_context, credential_html, credential_text)
from lhie_candidate.notifications.policy import TestRecipients
from lhie_candidate.workers import contract, wakeup
from lhie_candidate.workers.notifications import MailHandler


def allocation():
    return {'invoice_number': 'INV-<synthetic>',
            'invoice_url': 'http://127.0.0.1:18801/shop/orders/synthetic?expires=1&signature=test',
            'recovery_key': 'NEVER-RENDER-THIS-KEY',
            'groups': [{'product_name': '<Product>', 'default_tutorial': 'Shared instruction',
                        'guide': {'title': 'Usage', 'url': 'https://guide.example.invalid/help'},
                        'accounts': [
                            {'number': 7, 'format': 'legacy', 'secret': 'stock@example.invalid---- pass ----mfa',
                             'tutorial': 'Shared instruction'},
                            {'number': 8, 'format': 'structured_account', 'email': 'otp@example.invalid',
                             'password': '<password>&', 'access_method': 'otp_mail_link',
                             'access_value': 'https://otp.example.invalid/inbox', 'reader_mail_link': None,
                             'tutorial': 'Account override'},
                            {'number': 9, 'format': 'structured_account', 'email': 'reader@example.invalid',
                             'password': 'pw', 'access_method': 'auth_token', 'access_value': 'private-token',
                             'reader_mail_link': 'https://reader.example.invalid/inbox', 'tutorial': None}]}]}


class CredentialTemplateTests(unittest.TestCase):
    def test_channel_mapping_matches_php_access_priority_and_tutorial_deduplication(self):
        context = credential_context(allocation())
        group = context['groups'][0]
        legacy, otp, reader = group['accounts']
        self.assertEqual(legacy['label'], 'Data akun 7')
        self.assertEqual(legacy['parts']['password'], ' pass ')
        self.assertIn('MFA : mfa', legacy['text'])
        self.assertIsNone(legacy['tutorial'])
        self.assertEqual(group['delivery_note'], 'Shared instruction')
        self.assertEqual(otp['tutorial'], 'Account override')
        self.assertEqual(otp['access'], {'label': 'OTP Mail', 'value': None,
                         'url': 'https://otp.example.invalid/inbox', 'domain': 'otp.example.invalid'})
        self.assertEqual(reader['access']['label'], 'Akses Token')
        self.assertEqual(reader['access']['value'], 'private-token')
        self.assertEqual(context['order_access_url'], 'http://127.0.0.1:18801/akses-pesanan')
        self.assertEqual(context['usage_guides'], [group['usage_guide']])

    def test_html_escapes_values_and_preserves_php_layout_copy_and_destinations(self):
        html = credential_html(allocation())
        self.assertIn('&lt;Product&gt;', html)
        self.assertIn('&lt;password&gt;&amp;', html)
        self.assertNotIn('<password>', html)
        self.assertNotIn('NEVER-RENDER-THIS-KEY', html)
        self.assertEqual(html.count('>Shared instruction</p>'), 1)

    def test_plain_text_preserves_fields_links_and_generic_recovery_instructions_only(self):
        text = credential_text(allocation())
        for value in ('stock@example.invalid', ' pass ', 'mfa', 'Account override',
                      'Shared instruction', 'https://otp.example.invalid/inbox',
                      'private-token', 'http://127.0.0.1:18801/shop/orders/synthetic'):
            self.assertIn(value, text)
        self.assertNotIn('NEVER-RENDER-THIS-KEY', text)


    def test_only_proven_nonacceptance_becomes_retryable(self):
        for error, definite in ((smtplib.SMTPDataError(450, b'private'), True),
                                (smtplib.SMTPRecipientsRefused({'a': (550, b'private')}), True),
                                (TimeoutError('private'), False),
                                (smtplib.SMTPServerDisconnected('private'), False)):
            message = Mock()
            message.send.side_effect = error
            with self.subTest(error=type(error)), patch('lhie_candidate.notifications.mail.EmailBackend'), patch(
                    'lhie_candidate.notifications.mail.EmailMultiAlternatives', return_value=message):
                with self.assertRaises(DefiniteMailFailure if definite else type(error)):
                    SinkMail().send('test@example.invalid', 'subject', 'text')

    def test_disabled_credential_composition_never_reads_claims_or_writes(self):
        mail = CredentialMail(Mock(), Mock())
        mail.db = Mock()
        mail._outside = Mock()
        self.assertIsNone(mail.record('synthetic'))
        self.assertEqual(mail.recover(10), {})
        self.assertEqual(mail.deliver(1), 'disabled')
        self.assertEqual(mail.db.mock_calls, [])



class IsolatedConsumerTests(unittest.TestCase):
    def test_wakeups_cannot_cross_original_or_new_workers(self):
        identities = {'notification.effect': '123e4567-e89b-12d3-a456-426614174000',
                      'bulk.effect': '123e4567-e89b-12d3-a456-426614174000',
                      'waitlist.mail': '123e4567-e89b-12d3-a456-426614174000',
                      'credential.mail': 7, 'guest.mail': 8, 'auth.mail': 9}
        for kind, identity in identities.items():
            wire = wakeup.encode(kind, identity)
            own = contract.KIND_TO_QUEUE[kind]
            self.assertEqual(wakeup.decode(own, wire), (kind, identity))
            for spec in contract.TOPOLOGY.values():
                if spec.wakeup_queue != own:
                    with self.subTest(kind=kind, queue=spec.name), self.assertRaises(wakeup.InvalidWakeup):
                        wakeup.decode(spec.wakeup_queue, wire)


    def test_mail_rotation_and_shutdown_stop_new_pickup(self):
        services = [Mock(), Mock(), Mock()]
        mail = MailHandler(*services)
        calls = []
        def execute(function, limit):
            calls.append(function)
            function(limit)
        mail.sweep_bounded(10, execute, lambda: len(calls) >= 1)
        self.assertEqual(calls, [services[0].recover])
        calls.clear()
        mail.sweep_bounded(10, execute, lambda: False)
        self.assertEqual(calls, [services[1].recover, services[2].recover, services[0].recover])

    def test_auth_default_composition_does_not_touch_p19_grants_or_work(self):
        from lhie_candidate.workers.notifications import auth_mail_service
        with patch('lhie_candidate.runtime.application_key', return_value='key'), patch(
                'lhie_candidate.compat.crypto.LaravelEncrypter'), patch('lhie_candidate.auth.mail.MailWork') as work:
            handler = auth_mail_service()
            self.assertEqual(handler.sweep(10), 0)
            self.assertFalse(handler.handle(wakeup.encode('auth.mail', 1)))
            work.return_value.recover.assert_not_called()
            work.return_value.deliver.assert_not_called()


if __name__ == '__main__':
    unittest.main()
