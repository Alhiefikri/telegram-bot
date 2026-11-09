from datetime import datetime
from html import unescape
from pathlib import Path
from types import SimpleNamespace
import unittest
from unittest.mock import Mock
from zoneinfo import ZoneInfo

from django.template import Context, Engine

from lhie_candidate.notifications.credential_render import credential_text, presentation
from lhie_candidate.notifications.html import bounded_html
from lhie_candidate.notifications.policy import BROADCAST, STOCK, PRICE, MANUAL, ADJUSTMENT, PROOF
from lhie_candidate.notifications.order_notices import OrderNotices
from lhie_candidate.notifications.proof_publication import caption, money
from lhie_candidate.notifications.proof import payment_method
from lhie_candidate.notifications.service import Notifications
from lhie_candidate.notifications.balance import BalanceNotifications
from lhie_candidate.notifications.transport import NotificationTransport
from lhie_candidate.receipt.render import _len
from lhie_candidate.workers import contract, wakeup
from lhie_candidate.workers.notifications import MailHandler
from lhie_candidate.workers.supervisor import commands


class CompletionTests(unittest.TestCase):
    def test_compensation_uses_database_button_icons_and_safe_definite_fallback(self):
        icons = [
            {'key': 'menu.balance', 'source_emoji': '💰', 'fallback_emoji': '🪙', 'custom_emoji_id': '123'},
            {'key': 'menu.home', 'source_emoji': '🏠', 'fallback_emoji': '🏡', 'custom_emoji_id': None},
        ]
        owner = BalanceNotifications(None, None)
        owner.db = SimpleNamespace(rows=lambda sql: icons)
        owner.compensation_source = lambda identity, chat: {
            'customer_locale': 'en', 'resolved_at': datetime(2026, 1, 1, tzinfo=ZoneInfo('Asia/Jakarta')),
            'created_at': datetime(2026, 1, 1, tzinfo=ZoneInfo('Asia/Jakarta')), 'amount': 1000, 'topup_id': 7}
        payload = owner.compensation({'source_id': 1, 'telegram_chat_id': '1', 'target_type': 'customer',
                                      'telegram_method': 'sendMessage', 'locale': 'en'})
        buttons = payload['reply_markup']['inline_keyboard'][0]
        self.assertEqual(buttons[0]['text'], 'Balance')
        self.assertEqual(buttons[0]['icon_custom_emoji_id'], '123')
        self.assertEqual(buttons[1]['text'], '🏡 Home')
        relay = NotificationTransport()
        relay.relay = SimpleNamespace(_request=Mock(side_effect=[
            {'ok': False, 'error_code': 400, 'description': 'custom emoji unavailable'},
            {'ok': True, 'result': {'message_id': 1}},
        ]))
        self.assertEqual(relay.execute('sendMessage', payload).outcome, 'success')
        first, second = [call.args[1] for call in relay.relay._request.call_args_list]
        self.assertNotIn('_unicode_fallback', first['reply_markup']['inline_keyboard'][0][0])
        self.assertEqual(second['reply_markup']['inline_keyboard'][0][0]['text'], '🪙 Balance')
        self.assertNotIn('icon_custom_emoji_id', second['reply_markup']['inline_keyboard'][0][0])

    def test_admin_paid_outcome_does_not_infer_delivery_from_paid_or_completed(self):
        owner = OrderNotices(SimpleNamespace(telegram=frozenset({'1'})))
        effect = {'effect_group_key': 'receipt-group', 'state': 'unknown', 'telegram_message_id': None,
                  'sequence': 2, 'template_version': 'receipt-photo-caption-v1', 'attempts': 1,
                  'last_error_code': None, 'outcome_metadata': {'contains_credentials': True}}
        owner.db = SimpleNamespace(one=Mock(return_value=None), rows=Mock(
            side_effect=lambda sql, params: [effect] if 'FROM public.telegram_outbound_effects' in sql else []))
        owner.protocol = SimpleNamespace(is_receipt_group_complete=Mock(return_value=False), terminal_codes=set())
        order = {'id': 'synthetic-order', 'order_status': 'completed', 'payment_status': 'paid',
                 'telegram_receipt_sent_at': None}
        value = owner.outcome(order, [{'id': 1, 'quantity': 1, 'allocated': 1, 'product_name': 'Product'}],
                              [{'status': 'paid', 'paid_at': datetime.now().replace(microsecond=0)}])
        self.assertTrue(value['ready'])
        self.assertFalse(value['resolved'])
        self.assertIn('hasil pengiriman Telegram belum dapat dipastikan; jangan kirim ulang', '\n'.join(value['lines']))
        self.assertNotIn('1/1 unit terkirim', '\n'.join(value['lines']))

    def test_long_notification_keeps_emoji_tags_and_escaped_values_intact(self):
        value = '<tg-emoji emoji-id="123">😀</tg-emoji> ' + '&lt;Product&gt;' * 1000
        result = bounded_html(value)
        self.assertTrue(result.startswith('<tg-emoji emoji-id="123">😀</tg-emoji>'))
        self.assertTrue(result.endswith('...'))
        self.assertNotIn('<Product>', result)
        self.assertLessEqual(_len(unescape(result.replace('<tg-emoji emoji-id="123">', '').replace('</tg-emoji>', ''))), 3900)

    def test_credential_mail_projection_preserves_legacy_and_structured_access(self):
        guide = {'title': 'Guide', 'url': 'https://example.invalid/guide'}
        source = {'invoice_number': 'INV-SYNTHETIC', 'invoice_url': 'http://127.0.0.1:18801/shop/orders/123',
                  'groups': [{'product_name': '<Product>', 'default_tutorial': 'Default note', 'guide': guide,
                    'accounts': [
                        {'format': 'legacy', 'secret': 'first@example.invalid| password |MFA', 'number': 1,
                         'tutorial': 'Default note'},
                        {'format': 'structured_account', 'email': 'unit@example.invalid', 'password': '<secret>',
                         'access_method': 'auth_token', 'access_value': 'exact-token', 'number': 2,
                         'reader_mail_link': 'https://reader.example.invalid/path?x=1&y=2', 'tutorial': 'Override'},
                        {'format': 'structured_account', 'email': 'other@example.invalid', 'password': 'pass',
                         'access_method': 'otp_mail_link', 'access_value': 'https://otp.example.invalid/box',
                         'number': 3, 'tutorial': 'Default note'}]}]}
        value = presentation(source)
        accounts = value['groups'][0]['accounts']
        self.assertEqual(accounts[0]['parts']['password'], ' password ')
        self.assertIsNone(accounts[0]['tutorial'])
        self.assertEqual(accounts[1]['access'], {'label': 'Akses Token', 'value': 'exact-token',
            'url': 'https://reader.example.invalid/path?x=1&y=2', 'domain': 'reader.example.invalid'})
        self.assertEqual(accounts[2]['access']['label'], 'OTP Mail')
        self.assertIsNone(accounts[2]['access']['value'])
        text = credential_text(source)
        self.assertIn('instruksi akun : Override', text)
        self.assertIn('instruksi produk : Default note', text)
        self.assertIn('akses token : exact-token', text)
        engine = Engine(dirs=[str(Path(__file__).resolve().parents[1] / 'templates')])
        html = engine.get_template('notifications/credential.html').render(Context(value))
        self.assertIn('&lt;secret&gt;', html)
        self.assertNotIn('<secret>', html)
        self.assertIn('Domain tujuan:', html)
        self.assertIn('Buka Akses Token', html)
        self.assertIn('x=1&amp;y=2', html)
        self.assertEqual(value['usage_guides'], [guide])

    def test_sales_proof_public_caption_economics_privacy_and_bounds(self):
        snapshot = {'invoice_number': 'INV-TEST', 'buyer_name': 'Syn***', 'legacy_buyer': 'Sy***',
            'api': True, 'paid_at': datetime(2026, 11, 8, 12, 30, tzinfo=ZoneInfo('Asia/Jakarta')),
            'payment_method': 'USDT Balance · API', 'currency': 'USDT', 'provider_fee': 1,
            'payment_subtotal': 1_000_001, 'total_paid': 1_000_002,
            'items': [{'product_name': '<Product>', 'icon': '📦', 'quantity': 2,
                       'subtotal': 1_000_001, 'quantity_price_tier_minimum': 2}]}
        rendered = caption(snapshot, 8, 3)
        for expected in ('API ORDER FULFILLED', 'Origin: API', 'Syn***', '&lt;Product&gt;',
                         'Only 3 left in stock', '1.000002 USDT', 'Successful orders: #8',
                         '08 November 2026, 12:30 WIB', 'Bulk pricing applied · 2+ units'):
            self.assertIn(expected, rendered)
        self.assertNotIn('left in stock', caption(snapshot, 9, 0))
        self.assertIn('SUCCESSFUL PURCHASE', caption(snapshot, 10, legacy=True))
        self.assertEqual(money(100_000, 'IDR'), 'Rp 100.000')
        self.assertEqual(money(10_000_000, 'USDT'), '10 USDT')
        snapshot['items'] = [{**snapshot['items'][0], 'product_name': '😀' * 80} for _ in range(10)]
        self.assertLessEqual(_len(unescape(caption(snapshot, 11))), 1024)

    def test_sales_proof_payment_method_matches_owner_labels(self):
        self.assertEqual(payment_method('STORE_BALANCE', 'local'), 'STORE_BALANCE')
        self.assertEqual(payment_method('USDT_BEP20', 'manual'), 'USDT_BEP20')
        self.assertEqual(payment_method('hosted', 'pakasir'), 'Pakasir')
        self.assertEqual(payment_method('legacy', 'pakasir'), 'QRIS')
        self.assertEqual(payment_method(' BINANCE_PAY_ID ', 'manual'), 'Binance Pay')
        self.assertEqual(payment_method('x' * 49, 'manual'), 'x' * 47 + '…')

    def test_optional_profile_is_disjoint_and_keeps_default_budget(self):
        self.assertEqual(contract.worker_connection_budget(), {'processes': 5, 'threads': 5, 'db_connections': 4})
        self.assertEqual(contract.worker_connection_budget(notifications=True), {'processes': 8, 'threads': 8, 'db_connections': 7})
        self.assertEqual(set(commands('/tmp', include_http=True, notifications=True)),
                         {'http', 'interaction', 'payment', 'receipt', 'notice', 'bulk', 'mail'})
        for family, queue, identity in (('notification.effect', 'candidate:notices', '123e4567-e89b-12d3-a456-426614174000'),
                                        ('bulk.effect', 'candidate:bulk', '123e4567-e89b-12d3-a456-426614174000'),
                                        ('credential.mail', 'candidate:mail', 7)):
            wire = wakeup.encode(family, identity)
            self.assertEqual(wakeup.decode(queue, wire), (family, identity))
            with self.assertRaises(wakeup.InvalidWakeup):
                wakeup.decode('candidate:interaction', wire)
        notice = Notifications(None, proof=object(), families=(MANUAL, ADJUSTMENT, PROOF))
        bulk = Notifications(None, families=(BROADCAST, STOCK, PRICE))
        self.assertFalse(set(notice.protocol.effect_types) & set(bulk.protocol.effect_types))

    def test_mail_sweeps_rotate_and_stop_between_bounded_owners(self):
        first, second = SimpleNamespace(recover=Mock()), SimpleNamespace(recover=Mock())
        handler = MailHandler(first, second)
        bounded = Mock(side_effect=lambda function, limit: function(limit))
        handler.sweep_bounded(5, bounded, lambda: False)
        self.assertIs(bounded.call_args_list[0].args[0], first.recover)
        bounded.reset_mock()
        handler.sweep_bounded(5, bounded, lambda: False)
        self.assertIs(bounded.call_args_list[0].args[0], second.recover)
        bounded.reset_mock()
        handler.sweep_bounded(5, bounded, lambda: True)
        bounded.assert_not_called()

    def test_auth_and_milestone_mail_share_only_isolated_mail_budget(self):
        owners = [SimpleNamespace(recover=Mock(), deliver=Mock(), confirm_mail=Mock()) for _ in range(4)]
        handler = MailHandler(*owners)
        bounded = Mock(side_effect=lambda function, limit: function(limit))
        firsts = []
        for _ in range(4):
            bounded.reset_mock()
            handler.sweep_bounded(5, bounded, lambda: False)
            self.assertEqual(bounded.call_count, 4)
            firsts.append(bounded.call_args_list[0].args[0])
        self.assertEqual(firsts, [owner.recover for owner in owners])
        for kind, owner in (('auth.mail', owners[2]), ('milestone.mail', owners[3])):
            handler.handle(wakeup.encode(kind, 123))
            owner.deliver.assert_called_once_with(123)
            for queue in ('candidate:interaction', 'candidate:payments', 'candidate:telegram-receipts', 'candidate:notices'):
                with self.assertRaises(wakeup.InvalidWakeup):
                    wakeup.decode(queue, wakeup.encode(kind, 123))

    def test_auth_mail_copy_uses_fragments_and_php_expiry(self):
        from lhie_candidate.auth.mail import auth_copy
        for purpose, minutes in (('magic', '15 menit'), ('password_reset', '30 menit'), ('email_verification', '30 menit')):
            copy = auth_copy(purpose, 'a' * 64)
            self.assertIn('#token=' + 'a' * 64, copy['url'])
            self.assertIn(minutes, ' '.join(copy['lines']) + (copy['expiry'] or ''))
        copy = auth_copy('email_otp', '123456')
        self.assertIsNone(copy['url'])
        self.assertIn('123456', copy['lines'])
        self.assertIn('10 menit', ' '.join(copy['lines']))


if __name__ == '__main__':
    unittest.main()
