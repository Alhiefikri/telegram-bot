import unittest
from types import SimpleNamespace
from uuid import uuid4

from lhie_candidate.checkout.navigation import CheckoutView, _checkout_state
from lhie_candidate.navigation.presentation import CatalogPresenter
from tests.test_catalog_presentation import CUSTOMER, MemoryCatalog, buttons


ROOT = SimpleNamespace(bot_asset_scope='synthetic', customer_id=1, chat_id='41001', message_id=7)


def callback(surface, prefix):
    return next(button['callback_data'] for button in buttons(surface)
                if button.get('callback_data', '').startswith(prefix))


class FakeCheckout:
    def __init__(self, *views):
        self.views = list(views)
        self.calls = []
        self.checks = []

    def checkout(self, customer, root, *, product_id, quantity, token, rail):
        self.calls.append((customer, root, product_id, quantity, token, rail))
        return self.views.pop(0)

    def check(self, customer, root, *, payment_id):
        self.checks.append((customer, root, payment_id))
        return self.views.pop(0)


def pending(**values):
    return CheckoutView(
        'pending', order_id=str(uuid4()), payment_id=str(uuid4()),
        invoice_number='INV-720', amount=25000, fee=200, total=25200,
        qris_url='https://sakurupiah.id/qr/synthetic.png', rail='qris', **values)


def paid(**values):
    return CheckoutView('paid', order_id=str(uuid4()), payment_id=str(uuid4()),
                        invoice_number='INV-720', amount=25000, total=25000,
                        rail='balance', **values)


class NavigationCheckoutPresentationTests(unittest.TestCase):
    def presenter(self, checkout):
        return CatalogPresenter(MemoryCatalog(), signing_key='synthetic-key',
                                checkout=checkout)

    def open_detail(self, presenter):
        state = {}
        surface = presenter.detail(CUSTOMER, 2, state=state)
        return state, surface

    def test_detail_qris_creates_one_checkout_and_shows_payment_instructions(self):
        gateway = FakeCheckout(pending())
        presenter = self.presenter(gateway)
        state, detail = self.open_detail(presenter)
        selection = state['selection']
        surface = presenter.route(
            CUSTOMER, callback(detail, 'detail:pay:qris:'), state,
            root=ROOT, chat_id='41001')
        self.assertEqual(surface.screen, 'checkout')
        self.assertEqual(presenter.callback_handled, True)
        self.assertNotIn('selection', state)
        self.assertEqual(len(gateway.calls), 1)
        _, _, product_id, quantity, token, rail = gateway.calls[0]
        self.assertEqual((product_id, quantity, rail, token),
                         (2, 1, 'qris', selection['interaction_token']))
        self.assertIn('QRIS berhasil dibuat', surface.caption)
        self.assertIn('INV-720', surface.caption)
        self.assertIn('Rp 25.000', surface.caption)
        self.assertIn('Rp 25.200', surface.caption)
        check = callback(surface, 'checkout:check:')
        self.assertRegex(check, r'^checkout:check:[0-9a-f-]{36}$')
        self.assertTrue(any(button.get('url') == 'https://sakurupiah.id/qr/synthetic.png'
                            for button in buttons(surface)))
        self.assertTrue(any(button.get('callback_data') == 'menu:orders'
                            for button in buttons(surface)))

    def test_balance_success_and_insufficient_copy_are_localized(self):
        gateway = FakeCheckout(paid(), CheckoutView('balance_insufficient', rail='balance'))
        presenter = self.presenter(gateway)
        state, detail = self.open_detail(presenter)
        balance = callback(detail, 'detail:pay:balance:')
        surface = presenter.route(CUSTOMER, balance, state, root=ROOT, chat_id='41001')
        self.assertIn('Pembayaran dengan Saldo berhasil', surface.caption)
        self.assertIn('INV-720', surface.caption)
        self.assertTrue(any(button.get('callback_data') == 'menu:orders'
                            for button in buttons(surface)))
        state, detail = self.open_detail(presenter)
        surface = presenter.route(
            CUSTOMER, callback(detail, 'detail:pay:balance:'), state,
            root=ROOT, chat_id='41001')
        self.assertIn('Saldo tidak cukup', surface.caption)
        self.assertFalse(any(button.get('callback_data') == 'balance:add'
                             for button in buttons(surface)))

    def test_check_uses_the_injected_owner_and_answers_without_duplicate_surface(self):
        payment_id = str(uuid4())
        gateway = FakeCheckout(CheckoutView(
            'paid', order_id=str(uuid4()), payment_id=payment_id,
            invoice_number='INV-720', amount=25000, total=25000, replayed=True, rail='qris'))
        presenter = self.presenter(gateway)
        surface = presenter.route(
            CUSTOMER, f'checkout:check:{payment_id}', {}, root=ROOT, chat_id='41001')
        self.assertEqual(gateway.checks[0][0], CUSTOMER)
        self.assertEqual(gateway.checks[0][2], payment_id)
        self.assertIn('Pembayaran sudah diterima', surface.caption)
        self.assertIn('terverifikasi', presenter.callback_notice)

    def test_stale_checkout_identifier_gets_explicit_feedback(self):
        presenter = self.presenter(FakeCheckout(CheckoutView('stale')))
        surface = presenter.route(
            CUSTOMER, 'checkout:check:' + str(uuid4()), {}, root=ROOT, chat_id='41001')
        self.assertIsNone(surface)
        self.assertTrue(presenter.callback_handled)
        self.assertIn('tidak berlaku', presenter.callback_notice)

    def test_unwired_or_stale_selection_never_calls_checkout(self):
        presenter = self.presenter(None)
        surface = presenter.route(
            CUSTOMER, 'detail:pay:qris:2:' + 'a' * 16, {}, root=ROOT, chat_id='41001')
        self.assertIsNone(surface)
        self.assertTrue(presenter.callback_handled)
        self.assertIn('tidak berlaku', presenter.callback_notice)
        gateway = FakeCheckout(pending())
        presenter = self.presenter(gateway)
        surface = presenter.route(
            CUSTOMER, 'detail:pay:qris:2:' + 'a' * 16, {}, root=ROOT, chat_id='41001')
        self.assertIsNone(surface)
        self.assertEqual(gateway.calls, [])
        self.assertIn('tidak berlaku', presenter.callback_notice)

    def test_provider_unavailable_keeps_a_same_payment_retry_action(self):
        payment_id = str(uuid4())
        gateway = FakeCheckout(CheckoutView(
            'provider_unavailable', order_id=str(uuid4()), payment_id=payment_id,
            invoice_number='INV-720', amount=25000, rail='qris'))
        presenter = self.presenter(gateway)
        state, detail = self.open_detail(presenter)
        surface = presenter.route(
            CUSTOMER, callback(detail, 'detail:pay:qris:'), state,
            root=ROOT, chat_id='41001')
        self.assertIn('belum siap', surface.caption)
        self.assertTrue(any(button.get('callback_data') == f'checkout:check:{payment_id}'
                            for button in buttons(surface)))
        self.assertIn('selection', state)

    def test_stock_and_maintenance_errors_never_render_a_payable_surface(self):
        for state, copy in (
            (CheckoutView('stock_conflict', rail='qris'), 'Stok produk'),
            (CheckoutView('maintenance', rail='qris'), 'Checkout sedang dalam maintenance'),
        ):
            with self.subTest(state=state.state):
                presenter = self.presenter(FakeCheckout(state))
                selection, detail = self.open_detail(presenter)
                surface = presenter.route(
                    CUSTOMER, callback(detail, 'detail:pay:qris:'), selection,
                    root=ROOT, chat_id='41001')
                self.assertIn(copy, surface.caption)
                self.assertFalse(any(button.get('callback_data', '').startswith('checkout:check:')
                                     for button in buttons(surface)))
                self.assertNotIn('selection', selection)

    def test_checkout_state_mapping_is_fail_closed(self):
        self.assertEqual(_checkout_state('insufficient_store_balance'), 'balance_insufficient')
        self.assertEqual(_checkout_state('insufficient_stock'), 'stock_conflict')
        self.assertEqual(_checkout_state('checkout_maintenance'), 'maintenance')
        self.assertEqual(_checkout_state('provider_unavailable'), 'provider_unavailable')
        self.assertEqual(_checkout_state('foreign_checkout'), 'stale')
        self.assertEqual(_checkout_state('provider_amount_outside_limits'), 'invalid')
        self.assertEqual(_checkout_state('unknown_new_code'), 'invalid')


if __name__ == '__main__':
    unittest.main()
