"""Dedicated P25 parity checks for public Sales Proof presentation."""
import unittest
from types import SimpleNamespace
from unittest.mock import Mock

from lhie_candidate.notifications.proof_publication import format_caption, shopping_markup



class P25ProofButtonParityTests(unittest.TestCase):
    def test_custom_cart_icon_uses_keyboard_icon_field(self):
        markup = shopping_markup([{
            'key': 'message.cart', 'source_emoji': '🛒',
            'fallback_emoji': '🛍️', 'custom_emoji_id': '987654321',
        }], 'https://t.me/LhieStoreTestBot?start=start')

        self.assertEqual(markup, {'inline_keyboard': [[{
            'text': 'Shop Now', 'icon_custom_emoji_id': '987654321',
            '_unicode_fallback': '🛍️ Shop Now',
            'url': 'https://t.me/LhieStoreTestBot?start=start', 'style': 'primary',
        }]]})

    def test_cart_fallback_is_plain_keyboard_text(self):
        markup = shopping_markup([{
            'key': 'message.cart', 'source_emoji': '🛒',
            'fallback_emoji': '🛍️', 'custom_emoji_id': None,
        }], 'https://t.me/LhieStoreTestBot?start=start')

        self.assertEqual(markup['inline_keyboard'][0][0]['text'], '🛍️ Shop Now')
        self.assertNotIn('icon_custom_emoji_id', markup['inline_keyboard'][0][0])
        self.assertNotIn('<', markup['inline_keyboard'][0][0]['text'])
        self.assertNotIn('>', markup['inline_keyboard'][0][0]['text'])

    def test_missing_cart_icon_uses_original_unicode_label(self):
        markup = shopping_markup([], 'https://t.me/LhieStoreTestBot?start=start')

        self.assertEqual(markup['inline_keyboard'][0][0]['text'], '🛒 Shop Now')

    def test_custom_icon_fallback_requires_definite_rejection(self):
        from lhie_candidate.notifications.transport import NotificationTransport

        payload = {
            'chat_id': '1',
            'text': 'Proof caption',
            'reply_markup': shopping_markup([{
                'key': 'message.cart', 'source_emoji': '🛒',
                'fallback_emoji': '🛍️', 'custom_emoji_id': '987654321',
            }], 'https://t.me/LhieStoreTestBot?start=start'),
        }
        relay = SimpleNamespace(_request=Mock(side_effect=[
            {'ok': False, 'error_code': 400, 'description': 'icon_custom_emoji_id is unavailable'},
            {'ok': True, 'result': {'message_id': 19}},
        ]))
        transport = NotificationTransport()
        transport.relay = relay
        self.assertEqual(transport.execute('sendMessage', payload).message_id, 19)
        first = relay._request.call_args_list[0].args[1]['reply_markup']['inline_keyboard'][0][0]
        self.assertEqual(first['icon_custom_emoji_id'], '987654321')
        self.assertNotIn('_unicode_fallback', first)
        second = relay._request.call_args_list[1].args[1]['reply_markup']['inline_keyboard'][0][0]
        self.assertEqual(second['text'], '🛍️ Shop Now')
        self.assertNotIn('icon_custom_emoji_id', second)

        ambiguous = NotificationTransport()
        ambiguous.relay = SimpleNamespace(_request=Mock(return_value={}))
        self.assertEqual(ambiguous.execute('sendMessage', payload).outcome, 'ambiguous')
        self.assertEqual(ambiguous.relay._request.call_count, 1)
