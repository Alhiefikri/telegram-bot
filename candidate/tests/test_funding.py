import unittest
import ast
from datetime import datetime, timedelta
from types import SimpleNamespace
from zoneinfo import ZoneInfo

from lhie_candidate.funding.policy import (FundingPolicy, PolicyError, callback_identity,
                                           parse_amount, parse_preset_callback, preset_callback)
from lhie_candidate.funding.service import FundingService
from lhie_candidate.navigation.presentation import CatalogPresenter
from lhie_candidate.payments.sakurupiah import CreateRequest, InvoiceIdentity, OutcomeState, ProviderOutcome


CUSTOMER = {'id': 1, 'telegram_id': 1, 'first_name': 'Ayu', 'username': 'ayu', 'locale': 'id'}
KEY = 'a' * 64
NOW = datetime(2026, 11, 1, 10, 0)


class MemoryCatalog:
    def balance_snapshot(self, customer_id):
        return {'available': True, 'balance': 50000}

    def usdt_balance_snapshot(self, customer_id):
        return {'available': True, 'balance_micro': 0, 'frozen': False}

    def icons(self):
        return []

    def maintenance(self):
        return {'is_active': False}

    def asset(self, source_path, bot_scope='synthetic'):
        return None

    def balance_account_id(self, customer_id):
        return None

    def balance_activity(self, *args, **kwargs):
        return []

    def usdt_activity(self, *args, **kwargs):
        return []

    def orders_for_balance_entries(self, *args, **kwargs):
        return {}


class FakeEncrypter:
    def encrypt_json(self, value):
        return 'encrypted:' + repr(value)

    def decrypt_json(self, value):
        if not str(value).startswith('encrypted:'):
            raise ValueError('invalid')
        return ast.literal_eval(str(value)[len('encrypted:'):])


class FakeFunding:
    callback_key = KEY
    encrypter = FakeEncrypter()

    def __init__(self):
        self.policy = FundingPolicy(2, 5000, (5000, 10000, 25000))
        self.intents = {}
        self.topups = {}
        self.views = {}

    def chooser(self):
        return {'available': True, 'policy': self.policy, 'channel': 'QRIS'}

    def parse_preset(self, data):
        return parse_preset_callback(KEY, self.policy, data)

    def open_intent(self, customer, root, *, preset=None, raw_amount=None):
        amount = preset.amount if preset is not None else parse_amount(raw_amount)
        if amount is None:
            return SimpleNamespace(state='invalid', intent=None)
        if amount < self.policy.minimum_amount:
            return SimpleNamespace(state='below_minimum', intent=None)
        intent = {'id': 1, 'amount': amount, 'nonce': 'a' * 32,
                  'expires_at': NOW + timedelta(minutes=10)}
        self.intents['a' * 32] = intent
        return SimpleNamespace(state='opened', intent=intent)

    def claim(self, customer, root, *, nonce, action):
        intent = self.intents.get(nonce)
        if action == 'confirm':
            return SimpleNamespace(state='confirmed', intent=intent)
        return SimpleNamespace(state='cancelled', intent=intent)

    def create_top_up(self, customer, root, intent_id):
        topup = {'id': 9, 'amount': 5000, 'status': 'payable', 'provider_fee': 200,
                 'provider_total_payment': 5200, 'provider_expires_at': NOW + timedelta(minutes=10),
                 'expires_at': NOW + timedelta(minutes=15), 'provider_qris_url': None,
                 'provider_create_state': 'created', 'provider_payment_method': 'QRIS',
                 'sakurupiah_channel': 'QRIS', 'merchant_ref': 'BAL-1-x',
                 'provider_reference': 'trx-1', 'provider_create_attempted_at': NOW,
                 'provider_create_last_error': None, 'paid_at': None, 'settled_at': None,
                 'chat_id': '1', 'canonical_message_id': 7, 'customer_id': 1,
                 'store_balance_funding_intent_id': 1}
        self.topups[9] = topup
        return SimpleNamespace(state='payable', topup=topup)

    def top_up_for_action(self, customer, root, topup_id):
        topup = self.topups.get(int(topup_id))
        if topup is None or str(getattr(root, 'chat_id', '')) != topup['chat_id']:
            return None
        return topup

    def retry(self, topup):
        return SimpleNamespace(state='payable', topup=topup)

    def check(self, topup):
        return topup

    def presentation(self, topup):
        return {'state': 'payable', 'paid': False, 'payable': True, 'expired': False,
                'retryable': False, 'manual_review': False, 'recovered': False,
                'amount': 5000, 'fee': 200, 'total': 5200,
                'expires': NOW + timedelta(minutes=10),
                'qris_url': 'https://sakurupiah.id/qr.png', 'topup_id': '9'}


def buttons(surface):
    return [button for row in surface.keyboard['inline_keyboard'] for button in row]


class PolicyTests(unittest.TestCase):
    def test_signed_preset_round_trip_and_stale_version(self):
        policy = FundingPolicy(2, 5000, (5000, 10000))
        self.assertEqual(len(callback_identity(KEY, 2, 0, 5000)), 11)
        callback = preset_callback(KEY, policy, 1)
        self.assertEqual(callback, f'balance:p:2:{callback_identity(KEY, 2, 1, 10000)}')
        self.assertEqual(parse_preset_callback(KEY, policy, callback).amount, 10000)
        self.assertIsNone(parse_preset_callback(KEY, FundingPolicy(3, 5000, (5000, 10000)), callback))
        self.assertIsNone(parse_preset_callback(KEY, policy, callback[:-1] + 'A'))

    def test_policy_rejects_unsorted_or_below_minimum_presets(self):
        for presets in ((), (10000, 5000), (1000,)):
            with self.subTest(presets=presets):
                with self.assertRaises(PolicyError):
                    FundingPolicy(1, 5000, presets)

    def test_amounts_are_whole_positive_bounded_rupiah(self):
        self.assertEqual(parse_amount('25000'), 25000)
        self.assertEqual(parse_amount(25000), 25000)
        for invalid in ('0', '-1', '1.5', '01', 0, True, None):
            self.assertIsNone(parse_amount(invalid))


class FundingPresentationTests(unittest.TestCase):
    def setUp(self):
        self.funding = FakeFunding()
        self.presenter = CatalogPresenter(MemoryCatalog(), signing_key='synthetic-key', funding=self.funding)
        self.customer = dict(CUSTOMER)
        self.root = SimpleNamespace(bot_asset_scope='synthetic', customer_id=1, chat_id='1', message_id=7)

    def route(self, data):
        return self.presenter.route(self.customer, data, {}, root=self.root, chat_id='1')

    def test_chooser_and_presets_use_php_callback_identities(self):
        chooser = self.route('balance:add')
        self.assertIn('Pilih metode top-up', chooser.caption)
        qris = next(button for button in buttons(chooser) if button.get('callback_data') == 'balance:currency:idr')
        self.assertEqual(qris['style'], 'primary')
        presets = self.route('balance:currency:idr')
        self.assertIn('Minimum tambah saldo: Rp 5.000', presets.caption)
        amount_buttons = [button for button in buttons(presets)
                          if button.get('callback_data', '').startswith('balance:p:2:')]
        self.assertEqual([button['text'] for button in amount_buttons],
                         ['Rp 5.000', 'Rp 10.000', 'Rp 25.000'])
        confirmation = self.route(amount_buttons[1]['callback_data'])
        self.assertEqual(confirmation.screen, 'balance')
        self.assertEqual([button['callback_data'] for button in buttons(confirmation)],
                         ['balance:confirm:' + 'a' * 32, 'balance:cancel:' + 'a' * 32,
                          'balance:back:' + 'a' * 32])

    def test_confirm_creates_payable_topup_and_check_stays_idempotent(self):
        self.route('balance:currency:idr')
        confirmation = self.route('balance:p:2:' + callback_identity(KEY, 2, 1, 10000))
        self.assertIn('Rp 10.000', confirmation.caption)
        payable = self.route('balance:confirm:' + 'a' * 32)
        self.assertIn('Instruksi pembayaran tambah saldo', payable.caption)
        self.assertIn('Total akhir yang harus dibayar: Rp 5.200', payable.caption)
        self.assertIn('Instruksi pembayaran', payable.caption)
        qr = next(button for button in buttons(payable) if button.get('url'))
        self.assertEqual(qr['url'], 'https://sakurupiah.id/qr.png')
        self.assertTrue(any(button.get('callback_data') == 'balance:topup:9:check'
                            for button in buttons(payable)))
        self.assertEqual(self.presenter.route(
            self.customer, 'balance:topup:9:check', {}, root=SimpleNamespace(
                bot_asset_scope='synthetic', customer_id=1, chat_id='2', message_id=7), chat_id='2'), None)

    def test_cancel_and_deposit_copy_are_localized(self):
        cancelled = self.route('balance:cancel:' + 'a' * 32)
        self.assertIn('Konfirmasi top-up dibatalkan.', cancelled.caption)
        self.customer['locale'] = 'en'
        invalid = self.presenter.funding_deposit(self.customer, '/deposit 1.5', root=self.root, chat_id='1')
        self.assertIn('Enter one whole-IDR amount', invalid.caption)
        below = self.presenter.funding_deposit(self.customer, '/deposit 100', root=self.root, chat_id='1')
        self.assertIn('The minimum top-up is Rp 5.000', below.caption)

    def test_unwired_or_inactive_funding_keeps_explicit_refusal(self):
        presenter = CatalogPresenter(MemoryCatalog(), signing_key='synthetic-key')
        self.assertIsNone(presenter.route(self.customer, 'balance:add', {}))
        self.assertTrue(presenter.callback_handled)
        self.assertIn('belum tersedia', presenter.callback_notice)

    def test_default_funding_service_is_inactive_and_never_mutates(self):
        from lhie_candidate.funding.service import FundingService
        presenter = CatalogPresenter(MemoryCatalog(), signing_key='synthetic-key', funding=FundingService())
        chooser = presenter.route(self.customer, 'balance:add', {}, root=self.root, chat_id='1')
        self.assertIn('Metode pembayaran yang aktif sedang belum siap', chooser.caption)
        self.assertFalse(any(button.get('callback_data') == 'balance:currency:idr'
                             for button in buttons(chooser)))
        fallback = presenter.route(self.customer, 'balance:currency:idr', {},
                                   root=self.root, chat_id='1')
        self.assertIn('Metode pembayaran yang aktif sedang belum siap', fallback.caption)
        self.assertFalse(any(button.get('callback_data', '').startswith('balance:p:')
                             for button in buttons(fallback)))

    def test_provider_outcome_validation_rejects_fee_total_deadline_and_url_drift(self):
        service = FundingService(clock=lambda: NOW)
        identity = InvoiceIdentity('BAL-1-x', 5000, 'QRIS')
        request = CreateRequest(identity, '08123456789', 'https://sakurupiah.id/cb',
                                'https://sakurupiah.id/return')
        topup = {'amount': 5000}
        good = ProviderOutcome(identity, OutcomeState.CREATED, 'trx-1', 5200, 200,
                               (NOW + timedelta(minutes=10)).isoformat(),
                               'https://sakurupiah.id/qr.png')
        self.assertTrue(service._valid_created(good, topup, request))
        self.assertFalse(service._valid_created(
            ProviderOutcome(identity, OutcomeState.CREATED, 'trx-1', 5199, 200,
                            good.expiry, good.qr), topup, request))
        self.assertFalse(service._valid_created(
            ProviderOutcome(identity, OutcomeState.CREATED, 'trx-1', 5200, 200,
                            good.expiry, 'https://evil.example/qr.png'), topup, request))
        self.assertFalse(service._valid_created(
            ProviderOutcome(identity, OutcomeState.CREATED, 'trx-1', 5200, 200,
                            (NOW - timedelta(seconds=1)).isoformat(), good.qr), topup, request))


if __name__ == '__main__':
    unittest.main()
