import json
from datetime import datetime
from types import SimpleNamespace
import unittest
from unittest.mock import patch
from zoneinfo import ZoneInfo

from lhie_candidate.navigation.presentation import CatalogPresenter
from lhie_candidate.navigation.processor import NavigationProcessor
from tests.test_bot_parity import BOT, BotCatalog, NOW
from tests.test_catalog_presentation import CUSTOMER


class FakeTransport:
    def __init__(self):
        self.calls = []
        self.next_id = 100

    def execute(self, method, payload):
        self.calls.append((method, json.loads(json.dumps(payload))))
        if method == "answerCallbackQuery":
            return SimpleNamespace(outcome="success", message_id=None)
        self.next_id += 1
        return SimpleNamespace(outcome="success", message_id=self.next_id)


class FakeRoots:
    bot_asset_scope = "synthetic"

    def __init__(self):
        self.root = None
        self.rendered = []
        self.established = []
        self.banner_reference = None
        self.banner_version = None

    def current_for_chat(self, customer, chat_id):
        return self.root

    def render(self, customer, root, chat_id, surface, refresh=False):
        self.rendered.append((root, surface))
        return "applied"

    def establish(self, customer, chat_id, surface):
        self.established.append(surface)
        self.root = SimpleNamespace(
            message_id=999, generation=1, screen=surface.screen,
            chat_id=str(chat_id), customer_id=customer["id"])
        return "established"


def message(text, update_id=1, chat_id=41001):
    return {"update_id": update_id,
            "message": {"message_id": 10, "chat": {"id": chat_id, "type": "private"},
                        "from": {"id": chat_id, "first_name": "Ayu", "username": "ayu"},
                        "text": text}}


def callback(data, update_id=2, message_id=999, chat_id=41001):
    return {"update_id": update_id,
            "callback_query": {"id": "cb-%s" % update_id, "from": {"id": chat_id},
                               "message": {"message_id": message_id,
                                           "chat": {"id": chat_id, "type": "private"}},
                               "data": data}}


class ProcessorRoutingTests(unittest.TestCase):
    def setUp(self):
        self.repository = BotCatalog()
        self.repository.banner_asset = lambda scope='synthetic': None
        self.presenter = CatalogPresenter(
            self.repository, signing_key='synthetic-key', bot_identity=BOT,
            clock=lambda: NOW)
        self.transport = FakeTransport()
        self.roots = FakeRoots()
        self.processor = NavigationProcessor(
            self.repository, self.presenter, self.roots, self.transport)
        self.customer = dict(CUSTOMER, balance_payment_preference='idr_first')
        self.patcher = patch(
            'lhie_candidate.navigation.processor.resolve_customer',
            side_effect=lambda repository, actor: self.customer)
        self.patcher.start()
        self.addCleanup(self.patcher.stop)

    def navigate(self, payload):
        return self.processor._navigate(payload, payload["update_id"], datetime.now())

    def test_support_and_language_commands_render_owned_surfaces(self):
        self.assertEqual(self.navigate(message('/support')), 'established')
        self.assertEqual(self.roots.established[-1].screen, 'support')
        self.roots.root = None
        self.assertEqual(self.navigate(message('/language', 2)), 'established')
        self.assertEqual(self.roots.established[-1].screen, 'picker')
        self.assertIn('locale:en', str(self.roots.established[-1].keyboard))

    def test_account_status_and_history_use_one_off_or_orders_surface(self):
        self.repository.latest = {
            'id': '00000000-0000-4000-8000-000000000001',
            'invoice_number': 'INV-1', 'payment_status': 'paid',
            'payment_row_status': 'paid', 'order_status': 'completed',
            'created_at': NOW.replace(tzinfo=None), 'total_amount': 25000,
            'currency': 'IDR', 'payment_amount': 25000,
            'payment_currency': 'IDR', 'items': [],
            'telegram_receipt_sent_at': NOW.replace(tzinfo=None)}
        self.assertEqual(self.navigate(message('/akun')), 'applied')
        self.assertIn('INV-1', self.transport.calls[-1][1]['text'])
        self.assertTrue(self.transport.calls[-1][1]['reply_markup']['inline_keyboard'])
        self.assertEqual(self.navigate(message('/status', 2)), 'applied')
        self.assertIn('PESANAN SAYA', self.transport.calls[-1][1]['text'])
        self.assertEqual(self.navigate(message('/riwayat', 3)), 'established')
        self.assertEqual(self.roots.established[-1].screen, 'orders')

    def test_private_state_and_command_aliases_do_not_fall_through(self):
        self.assertEqual(self.navigate(message('/batal', 2)), 'applied')
        self.assertIn('keranjang', self.transport.calls[-1][1]['text'].lower())
        self.assertIn('Aku belum paham', self.navigate(message('/tidak-dikenal', 3)) and
                      self.transport.calls[-1][1]['text'])
        # A group update is rejected before any customer lookup or send.
        calls = len(self.transport.calls)
        group = message('/stock', 4, chat_id=-100)
        group['message']['from']['id'] = 41001
        self.assertEqual(self.navigate(group), 'foreign')
        self.assertEqual(len(self.transport.calls), calls)

    def test_locale_status_batal_and_stale_callbacks_use_feedback_and_root(self):
        self.roots.root = SimpleNamespace(
            message_id=999, generation=1, screen='home', chat_id='41001',
            customer_id=1)
        self.assertEqual(self.navigate(callback('locale:en')), 'applied')
        self.assertEqual(self.repository.locale_updates[-1], (1, 'en'))
        self.assertEqual(self.navigate(callback('status')), 'applied')
        self.assertTrue(any(call[0] == 'sendMessage' for call in self.transport.calls))
        self.assertEqual(self.navigate(callback('batal', 3)), 'applied')
        # Foreign/stale message roots are reported as stale without a mutation.
        calls = len(self.transport.calls)
        stale = callback('menu:language', 4, message_id=1)
        self.assertEqual(self.navigate(stale), 'stale')
        self.assertEqual(self.transport.calls[-1][0], 'answerCallbackQuery')
        self.assertEqual(len(self.transport.calls), calls + 1)

    def test_admin_stats_is_denied_for_a_non_admin(self):
        self.assertEqual(self.navigate(message('📊 Statistik')), 'applied')
        self.assertIn('Akses ditolak', self.transport.calls[-1][1]['text'])
        outcome = self.navigate(callback('statistik_harian', 2))
        self.assertEqual(outcome, 'denied')
        self.assertEqual(self.transport.calls[-1][0], 'answerCallbackQuery')
        self.assertIn('Akses ditolak', self.transport.calls[-1][1]['text'])

    def test_order_detail_is_scoped_to_the_owned_invoice(self):
        self.assertIn('tidak ditemukan', self.navigate(message('/order INV-404')) and
                      self.transport.calls[-1][1]['text'])
        self.repository.detail = {
            'id': '00000000-0000-4000-8000-000000000002',
            'invoice_number': 'INV-20', 'order_status': 'completed',
            'payment_status': 'paid', 'payment_row_status': 'paid',
            'created_at': NOW.replace(tzinfo=None), 'paid_at': NOW.replace(tzinfo=None),
            'total_amount': 25000, 'currency': 'IDR', 'payment_amount': 25000,
            'payment_currency': 'IDR', 'method': 'qris', 'provider': 'sakurupiah',
            'items': [{'product_name': 'Premium', 'product_icon': '🛍️',
                       'product_custom_emoji_id': None, 'quantity': 1}],
            'telegram_receipt_sent_at': NOW.replace(tzinfo=None),
        }
        self.assertEqual(self.navigate(message('/order INV-20', 2)), 'applied')
        texts = [call[1]["text"] for call in self.transport.calls if call[0] == 'sendMessage']
        self.assertTrue(any('INV-20' in text for text in texts))
        self.assertTrue(any('Pembayaran sudah diterima' in text for text in texts))


if __name__ == '__main__':
    unittest.main()
