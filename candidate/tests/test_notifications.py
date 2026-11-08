"""Focused non-DB boundaries: verbatim content, privacy and no ambiguous fallback."""
import unittest
from types import SimpleNamespace
from unittest.mock import Mock

from lhie_candidate.notifications.broadcast import markup, payload
from lhie_candidate.notifications.mail import credential_text
from lhie_candidate.notifications.policy import NotEligible, TestRecipients as Recipients
from lhie_candidate.notifications.price import payload as price_payload, usdt
from lhie_candidate.notifications.proof import mask
from lhie_candidate.notifications.rate import BroadcastRate
from lhie_candidate.notifications.service import Notifications
from lhie_candidate.notifications.transport import NotificationTransport
from lhie_candidate.receipt.protocol import RECEIPT_TYPES, ReceiptProtocol
from lhie_candidate.receipt.transport import ReceiptResult


class NotificationTests(unittest.TestCase):
    def test_default_admission_is_disabled_and_reserved_mail_only(self):
        recipients = Recipients()
        self.assertFalse(recipients.permits('1'))
        self.assertTrue(Recipients(frozenset({'1'})).permits(1))
        for value in ('buyer@example.com', 'a@example.invalid\nBcc:x@example.invalid',
                      'a@b.invalid,c@d.invalid', None, ''):
            with self.subTest(value=value), self.assertRaises(NotEligible):
                recipients.mail(value)
        self.assertEqual(recipients.mail('test@example.invalid'), 'test@example.invalid')

    def test_broadcast_author_content_is_verbatim_in_locale_wrapper(self):
        authored = '<b>Admin</b>\n日本語 &amp; 50%'
        source = {'message': authored, 'photo_file_id': None, 'reply_markup': {
            'id': {'inline_keyboard': [[{'text': 'Produk', 'callback_data': 'product:1'}]]},
            'en': {'inline_keyboard': [[{'text': 'Product', 'callback_data': 'product:1'}]]}}}
        effect = {'locale': 'id', 'telegram_chat_id': '1'}
        rendered = payload(source, effect)
        self.assertIn('\n' + authored + '\n\n', rendered['text'])
        self.assertEqual(rendered['reply_markup']['inline_keyboard'][0][0]['text'], 'Produk')
        source['photo_file_id'] = 'synthetic-file-reference'
        photo = payload(source, effect)
        self.assertEqual(photo['caption'], authored)
        self.assertNotIn('text', photo)
        self.assertEqual(markup(source['reply_markup'], 'unsupported'), source['reply_markup']['en'])

    def test_unknown_or_missing_ack_never_triggers_fallback(self):
        for response in ({}, {'ok': True, 'result': {}},
                         {'ok': False, 'error_code': 500, 'description': 'custom emoji'}):
            transport = NotificationTransport()
            transport.relay = SimpleNamespace(_request=Mock(return_value=response))
            self.assertEqual(transport.execute('sendMessage', {'chat_id': '1', 'text': 'test'}).outcome, 'ambiguous')
            self.assertEqual(transport.relay._request.call_count, 1)

    def test_only_explicit_emoji_rejection_allows_unicode_fallback(self):
        transport = NotificationTransport()
        transport.relay = SimpleNamespace(_request=Mock(side_effect=[
            {'ok': False, 'error_code': 400, 'description': 'custom emoji rejected'},
            {'ok': True, 'result': {'message_id': 7}}]))
        text = '<tg-emoji emoji-id="123">📦</tg-emoji> Stock'
        result = transport.execute('sendMessage', {'chat_id': '1', 'text': text})
        self.assertEqual(result.message_id, 7)
        self.assertEqual(transport.relay._request.call_args.args[1]['text'], '📦 Stock')

    def test_rate_unavailability_does_not_open_admission(self):
        client = SimpleNamespace(eval=Mock(side_effect=OSError('private-provider-text')))
        self.assertFalse(BroadcastRate(client).acquire())
        for reply, allowed in ((0, False), (1, True), ('1', False)):
            client.eval = Mock(return_value=reply)
            self.assertEqual(BroadcastRate(client).acquire(), allowed)

    def test_family_policy_is_closed_without_expanding_receipt_worker(self):
        from lhie_candidate.notifications.policy import LIMITS
        ordinary = ReceiptProtocol()
        notifications = ReceiptProtocol(family_limits=LIMITS)
        self.assertEqual(ordinary.effect_types, RECEIPT_TYPES)
        self.assertFalse(set(ordinary.effect_types) & set(notifications.effect_types))
        for limits in ({}, {'x': True}, {'x': 6}, {'x': 0}):
            with self.assertRaises(ValueError):
                ReceiptProtocol(family_limits=limits)

    def test_price_money_is_exact_and_untrusted_names_escaped(self):
        self.assertEqual(usdt(10_000_000), '10 USDT')
        self.assertEqual(usdt(1_000_001), '1.000001 USDT')
        with self.assertRaises(NotEligible):
            usdt(1.5)
        event = {'old_price_amount': 20000, 'new_price_amount': 15000,
                 'old_usdt_price_amount': 2000001, 'new_usdt_price_amount': 1000001,
                 'product_id': 7, 'product_name': '<private>&', 'product_icon': '📦'}
        rendered = price_payload(event, {'locale': 'en', 'telegram_chat_id': '1'})
        self.assertIn('&lt;private&gt;&amp;', rendered['text'])
        self.assertIn('Rp20,000 → Rp15,000', rendered['text'])
        self.assertIn('2.000001 USDT → 1.000001 USDT', rendered['text'])
        self.assertEqual(rendered['reply_markup']['inline_keyboard'][0][0]['style'], 'primary')

    def test_public_mask_never_contains_full_identity(self):
        self.assertEqual(mask('Private Buyer', 'secret_username'), 'Pri***')
        self.assertEqual(mask('Telegram Customer', '@UserName'), 'Use***')
        self.assertEqual(mask('', ''), 'Buyer')

    def test_credential_email_uses_paid_allocation_not_an_ownership_secret(self):
        source = {'invoice_number': 'INV-SYNTHETIC', 'groups': [
            {'product_name': 'Product', 'accounts': [
                {'number': 1, 'format': 'structured_account', 'email': 'stock@example.invalid',
                 'password': 'synthetic-password', 'access_method': 'auth_token', 'access_value': 'synthetic-token',
                 'tutorial': 'Synthetic instructions', 'reader_mail_link': None}]}]}
        text = credential_text(source)
        self.assertIn('stock@example.invalid', text)
        self.assertIn('synthetic-password', text)
        self.assertIn('Auth Token: synthetic-token', text)
        self.assertNotIn('Recovery Key', text)

    def service(self, result):
        from lhie_candidate.notifications.policy import BROADCAST
        transport = SimpleNamespace(execute=Mock(return_value=result))
        service = Notifications(transport, recipients=Recipients(frozenset({'1'})),
                                rate=SimpleNamespace(acquire=Mock(return_value=True)))
        effect = {'id': 'safe-effect-id', 'claim_token': 'safe-token', 'effect_type': BROADCAST,
                  'telegram_method': 'sendMessage', 'telegram_chat_id': '1'}
        service.db = SimpleNamespace(one=Mock(return_value=effect))
        service.protocol = SimpleNamespace(effect_types=(BROADCAST,),
            claim=Mock(return_value=effect), finalize_unknown=Mock(return_value={'state': 'unknown'}),
            finalize_sent=Mock(return_value={'state': 'sent'}), finalize_failed=Mock(return_value={'state': 'failed'}))
        service._outside = Mock()
        service.payload = Mock(return_value={'chat_id': '1', 'text': 'Synthetic'})
        service.project = Mock()
        service._aggregate = Mock()
        return service

    def test_nonadmitted_recipient_cannot_be_claimed(self):
        service = self.service(ReceiptResult(7, 'success'))
        service.db.one.return_value['telegram_chat_id'] = '2'
        self.assertEqual(service.deliver('safe-effect-id'), 'disabled')
        service.protocol.claim.assert_not_called()
        service.transport.execute.assert_not_called()

    def test_budget_miss_does_not_consume_an_effect_attempt(self):
        service = self.service(ReceiptResult(7, 'success'))
        service.rate.acquire.return_value = False
        self.assertEqual(service.deliver('safe-effect-id'), 'rate_limited')
        service.protocol.claim.assert_not_called()

    def test_missing_positive_message_id_is_unknown_not_sent(self):
        for identity in (None, 0, True, -1, '7'):
            service = self.service(ReceiptResult(identity, 'success'))
            self.assertEqual(service.deliver('safe-effect-id'), 'unknown')
            service.protocol.finalize_sent.assert_not_called()
            service.protocol.finalize_unknown.assert_called_once_with('safe-effect-id', 'safe-token')

    def test_fenced_finalization_cannot_repair_projection(self):
        service = self.service(ReceiptResult(7, 'success'))
        service.protocol.finalize_sent.return_value = None
        self.assertEqual(service.deliver('safe-effect-id'), 'fenced')
        service.project.assert_not_called()

    def test_ineligible_render_never_reaches_transport(self):
        service = self.service(ReceiptResult(7, 'success'))
        service.payload.side_effect = NotEligible()
        self.assertEqual(service.deliver('safe-effect-id'), 'ineligible')
        service.transport.execute.assert_not_called()
        service.protocol.finalize_failed.assert_called_once_with(
            'safe-effect-id', 'safe-token', 'notification_not_eligible')


if __name__ == '__main__':
    unittest.main()
