"""PHP lifecycle presentation and ambiguity/read-projection contracts."""
from copy import deepcopy
from datetime import datetime, timedelta
from types import SimpleNamespace
import unittest
from unittest.mock import Mock

from lhie_candidate.notifications.lifecycle import (
    EXPIRED, MANUAL, PAID, PENDING, REVIEW, LifecycleNotices,
    admin_payload, manual_payload, paid_outcome, usdt,
)
from lhie_candidate.notifications.policy import NotEligible, TestRecipients
from lhie_candidate.notifications.snapshot import frozen_payload, latest_sent


class LifecyclePresentationTests(unittest.TestCase):
    def setUp(self):
        self.at = datetime(2026, 11, 11, 10, 0)
        self.order = {'id': 'synthetic-order', 'invoice_number': 'INV-SYNTHETIC', 'username': 'buyer',
                      'telegram_id': 1, 'first_name': 'Buyer', 'order_status': 'completed',
                      'payment_status': 'paid', 'total_amount': 123456, 'updated_at': self.at,
                      'expires_at': self.at, 'api_order_claim_id': None, 'telegram_receipt_sent_at': None}
        self.payment = {'id': 'payment', 'status': 'paid', 'method': 'QR_MANUAL', 'provider': 'manual',
                        'payment_method_label': 'QR_MANUAL', 'currency': 'IDR', 'amount': 123456,
                        'provider_fee': 456, 'provider_total_payment': 123456, 'paid_at': self.at,
                        'expired_at': self.at, 'payment_proof_file_id': 'synthetic-photo'}
        self.items = [{'id': 7, 'product_name': '<Plan>&', 'quantity': 2, 'allocated': 2,
                       'product_icon': '📦', 'product_custom_emoji_id': '12345'}]
        self.icons = [{'key': 'message.receipt', 'source_emoji': '🧾', 'fallback_emoji': '📄', 'custom_emoji_id': '98765'},
                      {'key': 'message.success', 'source_emoji': '✅', 'fallback_emoji': '☑️', 'custom_emoji_id': '98766'}]

    def render(self, kind, **kwargs):
        return admin_payload(self.order, self.payment, self.items, self.icons, kind, '1',
                             'https://store.invalid/admin/orders/synthetic-order', **kwargs)

    def outcome(self, **kwargs):
        args = {'order': self.order, 'payment': self.payment, 'items': self.items, 'fulfillments': [],
                'receipts': [], 'complete': False, 'review_open': False, 'at': self.at}
        args.update(kwargs)
        return paid_outcome(**args)

    def test_pending_and_expired_preserve_php_fields_and_dashboard(self):
        pending = self.render(PENDING)
        self.assertIn('<tg-emoji emoji-id="98765">📄</tg-emoji> Checkout belum bayar', pending['text'])
        self.assertIn('Pembeli: @buyer (1)', pending['text'])
        self.assertIn('Metode: QR Manual\nTotal: Rp123.456', pending['text'])
        self.assertIn('&lt;Plan&gt;&amp; x 2', pending['text'])
        self.assertIn('Batas bayar: 11 Nov 2026, 10:00', pending['text'])
        self.assertEqual(pending['reply_markup']['inline_keyboard'][0][0]['text'], 'Buka Dashboard')
        expired = self.render(EXPIRED)
        self.assertIn('Status: Expired / belum bayar', expired['text'])
        self.assertIn('Expired at: 11 Nov 2026, 10:00', expired['text'])
        self.assertIn('Stok sudah dilepas kembali.', expired['text'])

    def test_manual_proof_has_complete_caption_and_live_actions(self):
        result = manual_payload(self.order, self.payment, self.items, self.icons, '1')
        self.assertEqual(result['photo'], 'synthetic-photo')
        self.assertIn('Bukti pembayaran masuk', result['caption'])
        self.assertIn('Customer: @buyer (1)', result['caption'])
        self.assertIn('Metode: QR_MANUAL', result['caption'])
        self.assertIn('Total: Rp 123.456', result['caption'])
        self.assertIn('Item:\n- <tg-emoji emoji-id="12345">📦</tg-emoji> &lt;Plan&gt;&amp; x 2', result['caption'])
        self.assertIn('Kalau belum cocok, tolak agar stok balik lagi.', result['caption'])
        approve, reject = result['reply_markup']['inline_keyboard'][0]
        self.assertEqual(approve['callback_data'], 'admin:approve-payment:synthetic-order')
        self.assertEqual(approve['icon_custom_emoji_id'], '98766')
        self.assertEqual(approve['text'], 'Setujui & Kirim Produk')
        self.assertEqual(reject['callback_data'], 'admin:reject-payment:synthetic-order')
        self.assertEqual(approve['style'], 'primary')

    def test_late_binance_proof_has_no_automatic_approval(self):
        self.order['order_status'] = 'expired'
        self.payment.update(method='BINANCE_PAY_ID', status='expired', currency='USDT', amount=1_000_001)
        result = manual_payload(self.order, self.payment, self.items, self.icons, '1')
        self.assertIn('1.000001 USDT', result['caption'])
        self.assertIn('Jangan approve lewat tombol otomatis.', result['caption'])
        self.assertNotIn('reply_markup', result)
        self.assertEqual(usdt(10_000_000), '10 USDT')
        self.assertEqual(usdt(10_000_000, fixed=True), '10.000000 USDT')

    def test_paid_net_is_exact_and_payment_does_not_imply_delivery(self):
        result = self.render(PAID, fulfillment_lines=['Pengiriman pelanggan: belum terkonfirmasi'],
                             stocks=['- Plan: 3'])
        self.assertIn('Earning: Rp123.000 (setelah fee)', result['text'])
        self.assertIn('Pengiriman pelanggan: belum terkonfirmasi', result['text'])
        self.assertIn('Sisa Stok:\n- Plan: 3', result['text'])
        self.assertNotIn('Order selesai.', result['text'])

    def test_paid_waits_for_outcome_or_escalation_without_inventing_delivery(self):
        outcome = self.outcome()
        self.assertFalse(outcome['ready'])
        self.assertFalse(outcome['resolved'])
        outcome = self.outcome(at=self.at + timedelta(seconds=120))
        self.assertTrue(outcome['ready'])
        self.assertFalse(outcome['resolved'])
        self.assertIn('Alasan: belum selesai setelah 120 detik', outcome['lines'])
        self.assertIn('jangan kirim ulang', outcome['lines'][0])

    def test_unknown_credential_delivery_is_review_not_retry_or_success(self):
        receipt = {'state': 'unknown', 'sequence': 1, 'template_version': 'receipt-photo-caption-v1',
                   'outcome_metadata': {'contains_credentials': True}, 'telegram_message_id': None,
                   'terminal_failure': False}
        outcome = self.outcome(receipts=[receipt])
        self.assertTrue(outcome['ready'])
        self.assertFalse(outcome['resolved'])
        self.assertIn('hasil pengiriman Telegram belum dapat dipastikan; jangan kirim ulang', outcome['lines'][0])
        result = self.render(PAID, outcome=outcome)
        self.assertIn('Order perlu ditinjau', result['text'])
        self.assertIn('Jangan beli ulang, refund, atau kirim ulang sebelum diperiksa.', result['text'])

    def test_resolved_supplier_and_buyer_are_separate_truths(self):
        fulfillment = {'order_item_id': 7, 'state': 'delivered', 'handoff': 0, 'refunded_quantity': 0,
                       'purchases': [{'state': 'settled', 'committed_at': self.at, 'supplier_order_id': 'supplier-order',
                                      'actual_cost_micros': 1, 'observed_quantity': 2, 'quantity': 2,
                                      'supplier_name': 'Supplier'}]}
        outcome = self.outcome(complete=True, fulfillments=[fulfillment])
        self.assertTrue(outcome['resolved'])
        self.assertIn('2/2 unit terkirim', outcome['lines'][0])
        self.assertIn('pembelian berhasil (2/2 unit)', outcome['lines'][1])
        fulfillment['purchases'][0]['state'] = 'ambiguous'
        uncertain = self.outcome(complete=True, fulfillments=[fulfillment])
        self.assertFalse(uncertain['resolved'])
        self.assertIn('perlu diperiksa', uncertain['lines'][1])

    def test_open_review_keeps_complete_receipt_unresolved(self):
        outcome = self.outcome(complete=True, review_open=True)
        self.assertFalse(outcome['resolved'])
        self.assertTrue(outcome['ready'])
        self.order['api_order_claim_id'] = 7
        result = self.render(REVIEW, review_open=True, fulfillment_lines=['Supplier: tidak digunakan'])
        self.assertIn('Review fulfillment API', result['text'])
        self.assertIn('Sumber: Customer API', result['text'])
        self.assertIn('Review operator: diperlukan; buka dashboard', result['text'])


class LifecycleProjectionTests(unittest.TestCase):
    def adapter(self):
        db = SimpleNamespace(using='default', one=Mock(), rows=Mock(), execute=Mock(return_value=1))
        return LifecycleNotices(SimpleNamespace(db=db, recipients=TestRecipients(frozenset({'1'}))))

    def test_old_sent_version_cannot_overwrite_latest_projection(self):
        db = SimpleNamespace(one=Mock(return_value={'id': 'new'}))
        effect = {'id': 'old', 'state': 'sent', 'sent_at': datetime(2026, 11, 11),
                  'telegram_message_id': 8, 'source_type': 'App\\Models\\Order', 'source_id': 'order', 'effect_type': PENDING}
        self.assertFalse(latest_sent(db, effect))
        self.assertIn('ORDER BY sequence DESC,created_at DESC,id DESC', db.one.call_args.args[0])

    def test_pending_repair_preserves_existing_cleanup_marker(self):
        adapter = self.adapter()
        adapter.db.one.return_value = {'id': 'effect'}
        effect = {'id': 'effect', 'state': 'sent', 'sent_at': datetime(2026, 11, 11), 'telegram_message_id': 9,
                  'telegram_chat_id': '1', 'source_type': 'App\\Models\\Order', 'source_id': 'order', 'effect_type': PENDING}
        self.assertEqual(adapter.project(effect), 1)
        sql = adapter.db.execute.call_args.args[0]
        self.assertIn('admin_pending_order_notification_message_id=%s', sql)
        self.assertNotIn('admin_pending_order_notification_deleted_at', sql)
        self.assertNotIn('claim_token', sql)

    def test_missing_paid_snapshot_defers_before_claim(self):
        adapter = self.adapter()
        adapter.db.one.return_value = {'order_status': 'completed', 'payment_status': 'paid'}
        effect = {'effect_type': PAID, 'source_id': 'order', 'outcome_metadata': {}}
        self.assertFalse(adapter.ready(effect))
        effect['outcome_metadata']['notification_payload'] = {'text': 'snapshot'}
        self.assertTrue(adapter.ready(effect))

    def test_frozen_payload_is_immutable_and_target_bound(self):
        effect = {'telegram_method': 'sendMessage', 'telegram_chat_id': '1',
                  'outcome_metadata': {'notification_payload': {'chat_id': '1', 'text': 'original',
                      'reply_markup': {'inline_keyboard': [[{'text': 'Open', 'callback_data': 'home'}]]}}}}
        result = frozen_payload(effect)
        result['reply_markup']['inline_keyboard'][0][0]['text'] = 'changed'
        self.assertEqual(frozen_payload(effect)['reply_markup']['inline_keyboard'][0][0]['text'], 'Open')
        changed = deepcopy(effect)
        changed['telegram_chat_id'] = '2'
        with self.assertRaises(NotEligible):
            frozen_payload(changed)

    def test_disabled_materialization_writes_nothing(self):
        adapter = self.adapter()
        adapter.service.recipients = TestRecipients()
        self.assertEqual(adapter.materialize(), 0)
        adapter.db.one.assert_not_called()
        adapter.db.execute.assert_not_called()
