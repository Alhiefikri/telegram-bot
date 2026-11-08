import copy
from datetime import datetime, timedelta
from types import SimpleNamespace
import unittest
from unittest.mock import patch
from zoneinfo import ZoneInfo

from lhie_candidate.navigation.intents import is_private_only_text, message_intent
from lhie_candidate.navigation.presentation import CatalogPresenter
from lhie_candidate.navigation.admin_capture import AdminCapture, _custom_entities, column, utc16_units
from lhie_candidate.receipt.historical import normalize_unit, render_order_credentials
from tests.test_catalog_presentation import CUSTOMER, MemoryCatalog, buttons, order, product


BOT = SimpleNamespace(username=lambda: 'LhieStoreTestBot')
NOW = datetime(2026, 11, 8, 10, tzinfo=ZoneInfo('Asia/Jakarta'))


class BotCatalog(MemoryCatalog):
    def __init__(self):
        super().__init__()
        self.support = {'url': 'https://t.me/lhie_support', 'username': 'lhie_support'}
        self.admin = False
        self.latest = None
        self.detail = None
        self.month_totals = {}
        self.cart = False
        self.stats = {'new_orders': 3, 'paid_orders': 2, 'sold_products': 4,
                      'sales_idr': 125000, 'received_idr': 125000, 'received_usdt': 0}
        self.locale_updates = []
        self.state = {}

    def customer(self, telegram_id):
        return dict(CUSTOMER, balance_payment_preference='idr_first')

    def session_state(self, customer_id):
        return copy.deepcopy(self.state)

    def save_session_state(self, customer_id, state):
        self.state = copy.deepcopy(state)

    def banner_asset(self, bot_scope='synthetic'):
        return None

    def support_contact(self):
        return copy.deepcopy(self.support)

    def customer_is_admin(self, customer_id, telegram_id, chat_id):
        return self.admin

    def home_data(self, customer_id, bot_scope='synthetic'):
        data = super().home_data(customer_id, bot_scope)
        data['admin_chat_id'] = 41001 if self.admin else None
        return data

    def account_summary(self, customer_id):
        return {'total_orders': 7, 'latest_order': self.latest}

    def latest_order_status(self, customer_id):
        return copy.deepcopy(self.latest)

    def order_month_totals(self, customer_id, start, end):
        return self.month_totals.get(start[:7], {'paid_count': 0, 'paid_idr': 0,
                                                 'paid_usdt': 0})

    def order_detail_data(self, customer_id, invoice):
        return copy.deepcopy(self.detail) if (
            self.detail and self.detail['invoice_number'] == invoice) else None

    def active_cart_exists(self, customer_id):
        return self.cart

    def abandon_customer_carts(self, customer_id):
        self.cart = False
        return 1

    def admin_day_stats(self):
        return dict(self.stats)

    def set_locale(self, customer_id, code):
        self.locale_updates.append((customer_id, code))
        return True

    def order_history(self, customer_id, **values):
        return super().order_history(customer_id, **values)


class BotIntentTests(unittest.TestCase):
    def test_aliases_are_classified_and_private_only_commands_stay_private(self):
        cases = {
            '/support': 'support', '💬 Sokongan': 'support', '/akun': 'account',
            '/orders': 'order_status', '📦 Pesanan': 'order_status',
            '/riwayat': 'order_history', '/search': 'search', '/cari kopi': 'search',
            '/cancel INV-1': 'cancel', '/batal': 'batal', '/order INV-1': 'order_detail',
            '/stock@LhieStoreTestBot': 'stock', '/stok': 'admin_stock',
            '📦 Cek Stok': 'admin_stock', '🛠️ Admin Dashboard': 'admin_dashboard',
            '📊 Statistik Harian': 'admin_stats', '/admin': 'admin',
            '🛍️ Lihat Produk': 'catalog', '☰ Menu': 'menu',
            '/start product_5': 'start', '/start family_9': 'start',
        }
        for text, kind in cases.items():
            with self.subTest(text=text):
                self.assertEqual(message_intent(text).kind, kind)
        self.assertEqual(message_intent('/start product_5').value, ('product', 5))
        self.assertEqual(message_intent('/cari dua kata').value, 'dua kata')
        for text in ('/akun', '/status', '/riwayat', '/payment INV', '/batal', '/admin'):
            self.assertTrue(is_private_only_text(text), text)
        self.assertFalse(is_private_only_text('/produk'))
        self.assertFalse(is_private_only_text('/stock'))
        self.assertEqual(message_intent('/bukan').kind, 'unsupported')


class BotAdminCaptureTests(unittest.TestCase):
    def test_utf16_columns_and_entity_validation(self):
        self.assertEqual(utc16_units('😀a'), 3)
        self.assertEqual(column('description', 'id'), 'description')
        self.assertEqual(column('description', 'en'), 'description_en')
        with self.assertRaises(ValueError):
            column('name', 'id')
        entities = _custom_entities('A😀B', [
            {'type': 'custom_emoji', 'offset': 1, 'length': 2,
             'custom_emoji_id': '123'}])
        self.assertEqual(entities[0]['glyph'], '😀')
        self.assertEqual(entities[0]['custom_emoji_id'], '123')
        with self.assertRaises(ValueError):
            _custom_entities('A😀B', [
                {'type': 'custom_emoji', 'offset': 1, 'length': 2,
                 'custom_emoji_id': '123'},
                {'type': 'custom_emoji', 'offset': 2, 'length': 1,
                 'custom_emoji_id': '456'}])
        with self.assertRaises(ValueError):
            _custom_entities('A', [{'type': 'custom_emoji', 'offset': 0,
                                    'length': 2, 'custom_emoji_id': '1'}])

    def test_plain_emoji_rejects_words_and_accepts_one_emoji(self):
        repository = BotCatalog()
        presenter = CatalogPresenter(repository, signing_key='x', bot_identity=BOT)
        admin = AdminCapture(presenter, repository)
        self.assertEqual(admin._plain_emoji('😀'), '😀')
        self.assertIsNone(admin._plain_emoji('hello'))
        self.assertEqual(admin._plain_emoji('😀😀'), '😀😀')


class BotPresentationTests(unittest.TestCase):
    def setUp(self):
        self.repository = BotCatalog()
        self.presenter = CatalogPresenter(
            self.repository, signing_key='synthetic-key', custom_emoji=True,
            bot_identity=BOT, clock=lambda: NOW)
        self.customer = dict(CUSTOMER, balance_payment_preference='idr_first')

    def test_language_picker_uses_three_locale_buttons_and_navigation(self):
        surface = self.presenter.language(self.customer)
        callbacks = [button['callback_data'] for button in buttons(surface)
                     if button.get('callback_data')]
        self.assertEqual(callbacks[:3], ['locale:id', 'locale:en', 'locale:ms'])
        self.assertIn('menu:back', callbacks)
        self.assertIn('menu:home', callbacks)

    def test_support_uses_database_url_and_never_invents_a_contact(self):
        self.assertIn('https://t.me/lhie_support',
                      [button.get('url') for button in buttons(
                          self.presenter.support(self.customer))])
        self.repository.support = {'url': '', 'username': ''}
        surface = self.presenter.support(self.customer)
        self.assertFalse(any('url' in button for button in buttons(surface)))
        self.assertTrue(any(button.get('callback_data') == 'menu:home'
                            for button in buttons(surface)))

    def test_account_summary_and_order_detail_follow_owned_order(self):
        self.repository.latest = {'invoice_number': 'INV-9', 'payment_status': 'paid'}
        surface = self.presenter.account(self.customer)
        self.assertIn('INV-9', surface.caption)
        self.assertIn('Dibayar', surface.caption)
        self.assertEqual([button['callback_data'] for button in buttons(surface)],
                         ['status'])
        self.repository.latest = None
        self.assertNotIn('INV-9', self.presenter.account(self.customer).caption)

    def test_order_status_hides_payment_for_terminal_and_shows_owned_total(self):
        self.repository.latest = None
        empty = self.presenter.order_status(self.customer)
        self.assertIn('Belum ada pesanan', empty.caption)
        callbacks = [button['callback_data'] for button in buttons(empty)]
        self.assertIn('om:202611', callbacks)
        self.assertIn('om:202610', callbacks)
        self.assertIn('status:home', callbacks)

        self.repository.latest = {
            'id': '00000000-0000-4000-8000-000000000001',
            'invoice_number': 'INV-10', 'order_status': 'pending_payment',
            'payment_status': 'pending', 'payment_row_status': 'pending',
            'created_at': NOW.replace(tzinfo=None), 'expires_at': NOW + timedelta(hours=1),
            'payment_expires_at': NOW + timedelta(hours=1),
            'total_amount': 25000, 'currency': 'IDR', 'payment_amount': 25000,
            'payment_currency': 'IDR', 'method': 'qris', 'provider': 'sakurupiah',
            'items': [{'product_name': 'Premium', 'quantity': 1}],
            'telegram_receipt_sent_at': None,
        }
        with patch('lhie_candidate.navigation.presentation.settings',
                   SimpleNamespace(CANDIDATE_LEGACY_APP_URL='https://store.example',
                                   CANDIDATE_INVOICE_URL_TTL_DAYS=7)):
            surface = self.presenter.order_status(self.customer)
        self.assertIn('INV-10', surface.caption)
        urls = [button.get('url') for button in buttons(surface) if button.get('url')]
        self.assertTrue(urls and urls[0].startswith('https://store.example/shop/orders/'))
        self.assertIn('order:INV-10', [button.get('callback_data') for button in buttons(surface)])
        self.repository.latest['order_status'] = 'expired'
        self.repository.latest['payment_row_status'] = 'expired'
        terminal = self.presenter.order_status(self.customer)
        self.assertNotIn('https://store.example/shop/orders/', str(terminal.keyboard))
        self.assertIn('Kedaluwarsa', terminal.caption)

    def test_month_history_cursor_is_signed_by_month_and_keeps_pagination(self):
        self.repository.order_rows = [
            order(i, 'INV-%s' % i, created_token='202611%02d100000' % i)
            for i in range(1, 13)]
        first = self.presenter.orders(self.customer, month='202611')
        self.assertIn('Nov 2026', first.caption)
        self.assertIn('order:INV-12', [button.get('callback_data') for button in buttons(first)])
        self.assertFalse(any(button.get('callback_data', '').startswith('orders:')
                             for button in buttons(first)))
        self.assertTrue(any(button.get('callback_data', '').startswith('om:202611:')
                            for button in buttons(first)))
        # A cursor for another month is stale, never silently applied.
        self.assertIsNone(self.presenter.orders(
            self.customer, 'om:202610:o:20261001100000:'
            '00000000-0000-4000-8000-000000000001', month='202611'))

    def test_order_detail_splits_header_guidance_and_keeps_credentials_atomic(self):
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
        header, instruction = self.presenter.order_detail_parts(
            self.customer, self.repository.detail)
        text = '\n'.join(message.text for message in header)
        self.assertIn('INV-20', text)
        self.assertIn('Selesai', text)
        self.assertIn('Pembayaran sudah diterima', instruction.text)
        allocation = {
            'order_id': self.repository.detail['id'], 'customer_id': 1, 'chat_id': '41001',
            'invoice_number': 'INV-20', 'locale': 'id',
            'items': [{'product_name': 'Premium', 'icon': '🛍️', 'custom_emoji_id': None,
                       'quantity': 1}],
            'groups': [{'product_name': 'Premium', 'icon': '🛍️', 'custom_emoji_id': None,
                        'default_tutorial': None, 'guide': None,
                        'accounts': [{'format': 'structured_account',
                                      'email': 'one@example.invalid',
                                      'password': 'synthetic-pass',
                                      'access_method': 'otp_mail_link',
                                      'access_value': 'https://example.invalid/otp',
                                      'tutorial': None, 'number': 1}]}],
            'allocated_count': 1, 'amount_paid': 'Rp 25.000', 'payment_method': 'QRIS',
            'paid_at': '08 Nov 2026 10:00',
        }
        units = [normalize_unit(unit) for unit in render_order_credentials(allocation)]
        body = '\n'.join(unit.text for unit in units)
        self.assertIn('one@example.invalid', body)
        self.assertIn('OTP Mail', body)
        self.assertNotIn('Pembayaran diterima', body)
        otp_buttons = [button for unit in units
                       for row in unit.reply_markup.get('inline_keyboard', [])
                       for button in row if button.get('url') == 'https://example.invalid/otp']
        self.assertTrue(otp_buttons)
        self.assertIn('Buka OTP Mail #1', otp_buttons[0]['text'])

    def test_stock_only_lists_available_products_with_buy_links_and_chunks(self):
        self.repository.product_rows = [product(1, 'Kosong', stock=0),
                                        product(2, 'Ada', stock=4, price=25000)]
        messages = self.presenter.stock_messages(self.customer)
        self.assertEqual(len(messages), 1)
        self.assertIn('Ada', messages[0].text)
        self.assertNotIn('Kosong', messages[0].text)
        self.assertIn('https://t.me/LhieStoreTestBot?start=product_2', messages[0].text)
        self.assertIn('Rp25.000', messages[0].text)
        self.repository.product_rows = [product(i, 'P%d' % i, stock=1,
                                                price=1000) for i in range(1, 120)]
        messages = self.presenter.stock_messages(self.customer)
        self.assertGreater(len(messages), 1)
        for message in messages:
            self.assertLessEqual(len(message.text.encode('utf-16-le')) // 2, 3900)

    def test_api_management_surfaces_never_contain_a_secret(self):
        summary = {'active': False, 'masked_identifier': None, 'created_at': None,
                   'last_used_at': None, 'account_request_count': '0'}
        surface = self.presenter.api_management(self.customer, summary)
        self.assertIn('Belum ada kunci API aktif', surface.caption)
        self.assertIn('api:generate', [button.get('callback_data') for button in buttons(surface)])
        summary.update(active=True, masked_identifier='capi_12345678…',
                       created_at='2026-11-08T03:00:00+00:00',
                       last_used_at=None, account_request_count='4')
        active = self.presenter.api_management(self.customer, summary)
        callbacks = [button.get('callback_data') for button in buttons(active)]
        self.assertIn('api:rotate', callbacks)
        self.assertIn('api:disable', callbacks)
        self.assertNotIn('api:generate', callbacks)
        self.assertIn('capi_12345678…', active.caption)
        self.assertNotIn('capi_12345678.', active.caption)
        secret = self.presenter.api_secret_message(self.customer, 'capi_once.secret')
        self.assertIn('<code>capi_once.secret</code>', secret)
        self.assertNotIn('capi_once.secret', repr(active))

    def test_admin_home_affordances_are_fail_closed(self):
        self.assertFalse(any('producticon:list' in str(button.get('callback_data'))
                             for button in buttons(self.presenter.home(self.customer))))
        self.repository.admin = True
        surface = self.presenter.home(self.customer)
        self.assertTrue(any(button.get('callback_data') == 'producticon:list'
                            for button in buttons(surface)))
        self.assertTrue(any(button.get('callback_data') == 'statistik_harian'
                            for button in buttons(surface)))

    def test_maintenance_notice_is_appended_to_home_and_tokens_are_not_echoed(self):
        self.repository.paused = True
        surface = self.presenter.home(self.customer)
        self.assertIn('maintenance', surface.caption.lower())
        self.assertNotIn('synthetic-key', repr(surface))


if __name__ == '__main__':
    unittest.main()
