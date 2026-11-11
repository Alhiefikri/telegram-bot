"""Sales Proof copy, privacy, immutable snapshots and retained fake boundaries."""
from datetime import datetime
from html import unescape
import re
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch
from zoneinfo import ZoneInfo

from lhie_candidate.checkout.pricing import PricingSnapshot
from lhie_candidate.notifications.fake_proof import synthetic_presentation
from lhie_candidate.notifications.policy import NotEligible, PROOF
from lhie_candidate.notifications.proof_publication import (
    LEGACY_TEMPLATE, PHOTO_TEMPLATE, SalesProofPublication, _length, format_caption,
    format_topup, shopping_markup, shopping_url)


def presentation(**changes):
    value = {'invoice_number': 'INV-SYNTHETIC', 'buyer_name': 'Pri***', 'chat_id': None,
        'paid_at': datetime(2026, 11, 11, 12, 34, tzinfo=ZoneInfo('Asia/Jakarta')),
        'payment_method': 'QRIS', 'currency': 'IDR', 'provider_fee': 100,
        'total_paid': 10100, 'items': [{'product_name': 'Product', 'product_icon': '📦',
            'product_custom_emoji_id': '123', 'quantity': 2, 'unit_price': 5000,
            'subtotal': 10000, 'quantity_price_tier_minimum': 2}]}
    return {**value, **changes}


class ProofPublicationTests(unittest.TestCase):
    def adapter(self):
        db = SimpleNamespace(using='default', one=Mock(), execute=Mock(return_value=1))
        service = SimpleNamespace(db=db, recipients=SimpleNamespace(telegram=frozenset({'1'}), permits=lambda v: v == '1'),
                                  bot_username='LhieStoreTestBot')
        return SalesProofPublication(service)


    def test_caption_never_invents_scarcity_at_zero_or_no_signal(self):
        for remaining in (None, 0, -1):
            self.assertNotIn('left in stock', format_caption(presentation(), 1, remaining))
        adapter = self.adapter()
        for stock, expected in ((0, None), (1, 1), (5, 5), (6, None)):
            adapter.db.one.return_value = {'available_stock': stock}
            self.assertEqual(adapter._stock(1), expected)
        adapter.db.one.return_value = None
        self.assertIsNone(adapter._stock(1))

    def test_long_caption_is_utf16_bounded_without_breaking_custom_emoji_or_footer(self):
        item = presentation()['items'][0]
        items = [{**item, 'product_name': '<' + '🧪' * 100 + '>&',
                  'product_custom_emoji_id': str(index + 100)} for index in range(20)]
        text = format_caption(presentation(items=items), 900)
        self.assertLessEqual(_length(text), 1024)
        self.assertNotIn('<🧪', text)
        self.assertEqual(text.count('<tg-emoji'), text.count('</tg-emoji>'))
        self.assertIn('#900', text)
        self.assertTrue(text.endswith('.'))

    def test_static_icons_do_not_rewrite_product_or_customer_values(self):
        icons = [{'key': 'message.users', 'source_emoji': '👥', 'fallback_emoji': '👤', 'custom_emoji_id': '77'},
                 {'key': 'message.celebrate', 'source_emoji': '🎉', 'fallback_emoji': '🥳', 'custom_emoji_id': '88'}]
        text = format_caption(presentation(buyer_name='🎉***', items=[{**presentation()['items'][0], 'product_name': '🎉 Product'}]), 2, icons=icons)
        self.assertIn('<tg-emoji emoji-id="88">🥳</tg-emoji>', text)
        self.assertIn('<tg-emoji emoji-id="77">👤</tg-emoji>', text)
        self.assertIn('🎉***', text)
        self.assertIn('🎉 Product', text)
        self.assertIn('× 2', text)
        button = shopping_markup(icons, shopping_url('LhiestoreTestBot'))['inline_keyboard'][0][0]
        self.assertEqual(button['style'], 'primary')
        self.assertTrue(button['text'].startswith('🛒'))
        self.assertEqual(button['url'], 'https://t.me/LhiestoreTestBot?start=start')
        with self.assertRaises(NotEligible):
            shopping_url('https://evil.invalid')

    def test_topup_has_no_order_counter_and_usdt_is_integer_micro_units(self):
        text = format_topup(presentation(currency='USDT', total_paid=1_000_001))
        self.assertIn('INV-SYNTHETIC', text)
        self.assertIn('1.000001 USDT', text)
        self.assertNotIn('#', text)

    def test_payload_uses_frozen_identity_copy_and_invoice_after_live_reauthorization(self):
        adapter = self.adapter()
        adapter._channel = Mock(return_value='1')
        adapter.eligibility.load = Mock(return_value=presentation(buyer_name='Cha***'))
        old = presentation()
        old['paid_at'] = old['paid_at'].isoformat()
        effect = {'effect_type': PROOF, 'source_type': 'App\\Models\\Order', 'source_id': 'order',
                  'target_type': 'store_channel', 'telegram_chat_id': '1', 'template_version': PHOTO_TEMPLATE,
                  'telegram_method': 'sendPhoto', 'outcome_metadata': {'proof_publication': {
                      'snapshot': old, 'caption': 'Immutable public caption', 'reply_markup': {}}}}
        with patch('lhie_candidate.notifications.proof_publication.render_invoice', return_value=b'PNG') as render:
            result = adapter.payload(effect)
        self.assertEqual(result['caption'], 'Immutable public caption')
        self.assertEqual(render.call_args.args[0]['buyer_name'], 'Pri***')
        self.assertIsInstance(effect['outcome_metadata']['proof_publication']['snapshot']['paid_at'], str)
        adapter.eligibility.load.side_effect = NotEligible()
        with self.assertRaises(NotEligible):
            adapter.payload(effect)

    def test_historical_v1_stays_text_and_mismatched_contract_is_blocked(self):
        adapter = self.adapter()
        adapter._channel = Mock(return_value='1')
        adapter.eligibility.load = Mock()
        effect = {'effect_type': PROOF, 'source_type': 'App\\Models\\Order', 'source_id': 'order',
                  'target_type': 'store_channel', 'telegram_chat_id': '1', 'template_version': LEGACY_TEMPLATE,
                  'telegram_method': 'sendMessage', 'outcome_metadata': {'proof_publication': {
                      'caption': '🎉 SUCCESSFUL PURCHASE 🎉', 'reply_markup': {}}}}
        self.assertEqual(adapter.payload(effect)['text'], '🎉 SUCCESSFUL PURCHASE 🎉')
        effect['telegram_method'] = 'sendPhoto'
        with self.assertRaises(NotEligible):
            adapter.payload(effect)

    def test_disabled_materialization_and_retry_have_no_writes(self):
        adapter = self.adapter()
        adapter.service.recipients.telegram = frozenset()
        self.assertEqual(adapter.materialize(10), 0)
        adapter.db.one.assert_not_called()
        adapter.db.execute.assert_not_called()

    def test_api_publication_requires_complete_allocation_and_no_open_review(self):
        from lhie_candidate.notifications.proof import SalesProofEligibility
        eligibility = SalesProofEligibility()
        order = {'id': 'order', 'invoice_number': 'INV-API', 'customer_id': 7,
                 'order_status': 'completed', 'payment_status': 'paid', 'api_order_claim_id': 1,
                 'currency': 'IDR', 'total_amount': 100, 'first_name': 'Private API Buyer', 'username': ''}
        payment = {'id': 'payment', 'method': 'QRIS', 'currency': 'IDR', 'amount': 100,
                   'provider_fee': 0, 'provider_total_payment': 100, 'status': 'paid',
                   'paid_at': datetime(2026, 11, 11, 12, 34)}
        item = {'id': 1, 'product_id': 2, 'product_name': 'Plan', 'product_icon': None,
                'product_custom_emoji_id': None, 'quantity': 2, 'sales': 1, 'allocated': 2,
                'quantity_price_tier_minimum': None, 'unit_price': 50, 'subtotal': 100,
                'usdt_unit_price': None, 'usdt_subtotal': None}
        for allocated, review, allowed in ((1, None, False), (2, {'id': 9}, False), (2, None, True)):
            def read_rows(sql, _params):
                if "FROM public.payments" in sql:
                    return [{"status": "paid", "paid_at": payment["paid_at"]}]
                if "FROM public.order_items" in sql:
                    return [{**item, "allocated": allocated}]
                self.fail(f"Unexpected Sales Proof query: {sql}")
            eligibility.db = SimpleNamespace(
                one=Mock(side_effect=[order, payment, None] + ([review] if allocated == 2 else [])),
                rows=Mock(side_effect=read_rows))
            if allowed:
                public = eligibility.load('order')
                self.assertEqual(public['buyer_name'], 'Pri***')
                self.assertNotIn('Private API Buyer', str(public))
            else:
                with self.assertRaises(NotEligible):
                    eligibility.load('order')
        paid_at = datetime(2026, 11, 11, 12, 34)
        invalid_payment_attempts = (
            [],
            [{"status": "pending", "paid_at": paid_at}],
            [{"status": "paid", "paid_at": None}],
            [{"status": "pending", "paid_at": paid_at},
             {"status": "paid", "paid_at": datetime(2026, 11, 11, 12, 33)}],
        )
        for attempts in invalid_payment_attempts:
            eligibility.db = SimpleNamespace(one=Mock(return_value=order),
                                             rows=Mock(return_value=attempts))
            with self.subTest(attempts=attempts):
                with self.assertRaises(NotEligible):
                    eligibility.load('order')


    def test_payment_method_labels_match_legacy_paid_invoice_rails(self):
        from lhie_candidate.notifications.proof import SalesProofEligibility

        eligibility = SalesProofEligibility()
        order = {'id': 'order', 'invoice_number': 'INV-SYNTHETIC', 'customer_id': 7,
                 'order_status': 'completed', 'payment_status': 'paid', 'api_order_claim_id': None,
                 'currency': 'IDR', 'total_amount': 100, 'first_name': 'Synthetic Buyer', 'username': ''}
        item = {'id': 1, 'product_id': 2, 'product_name': 'Plan', 'product_icon': None,
                'product_custom_emoji_id': None, 'quantity': 1, 'sales': 1, 'allocated': 1,
                'quantity_price_tier_minimum': None, 'unit_price': 100, 'subtotal': 100,
                'usdt_unit_price': 1_000_000, 'usdt_subtotal': 1_000_000}
        for method, currency in (('USDT_BEP20', 'USDT'), ('STORE_BALANCE', 'IDR')):
            with self.subTest(method=method):
                payment = {'id': 'payment', 'method': method, 'currency': currency, 'amount': 100,
                           'provider_fee': 0, 'provider_total_payment': 100, 'status': 'paid',
                           'paid_at': datetime(2026, 11, 11, 12, 34)}
                queries = [order, payment, None] + ([None] if method == 'USDT_BEP20' else [])
                def read_rows(sql, _params):
                    if "FROM public.payments" in sql:
                        return [{"status": "paid", "paid_at": payment["paid_at"]}]
                    if "FROM public.order_items" in sql:
                        return [item]
                    self.fail(f"Unexpected Sales Proof query: {sql}")
                eligibility.db = SimpleNamespace(one=Mock(side_effect=queries),
                                                  rows=Mock(side_effect=read_rows))
                self.assertEqual(eligibility.load('order')['payment_method'], method)


    def test_synthetic_presentation_preserves_source_quantity_pricing_fee_and_mask(self):
        product = {'id': 7, 'name': 'Synthetic product', 'price_amount': 10001, 'usdt_price_amount': None, 'currency': 'IDR'}
        quote = PricingSnapshot.build([{'product_id': 7, 'quantity': 3}], {7: product},
            {7: [{'minimum_quantity': 3, 'price_amount': 9000, 'usdt_price_amount': None}]}).quotes[0]
        value = synthetic_presentation(product, quote, presentation()['paid_at'], 'INV-SYNTHETIC')
        self.assertEqual(value['items'][0]['quantity'], 3)
        self.assertEqual(value['items'][0]['subtotal'], 27000)
        self.assertEqual(value['items'][0]['quantity_price_tier_minimum'], 3)
        self.assertEqual(value['provider_fee'], 270)
        self.assertEqual(value['total_paid'], 27270)
        self.assertRegex(value['buyer_name'], r'^.{3}\*\*\*$')
        self.assertEqual(value['payment_method'], 'QRIS')
        self.assertIsNone(value['chat_id'])
        self.assertEqual(set(value['items'][0]), {'product_name','quantity','quantity_price_tier_minimum','unit_price','subtotal'})


if __name__ == '__main__':
    unittest.main()
