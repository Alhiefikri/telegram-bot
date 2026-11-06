import unittest
from types import SimpleNamespace
from uuid import uuid4

from lhie_candidate.navigation.presentation import CatalogPresenter
from tests.test_catalog_presentation import CUSTOMER, MemoryCatalog, buttons


ROOT = SimpleNamespace(bot_asset_scope='synthetic', customer_id=1, chat_id='41001',
                       message_id=7, generation=3)


class Funding:
    def __init__(self, ready=True):
        self.ready = ready
        self.version = 1
        self.calls = []
        self.target = {'id': str(uuid4()), 'reference': 'USDT-1', 'rail': 'binance_pay',
                       'amount_micro': 300000, 'payable_micro': 300000,
                       'payment_snapshot': {}, 'state': 'preparing'}

    def availability(self, rail):
        return self.ready

    def presets(self):
        return self.version, [300000, 1000000]

    def open_confirmed(self, *args, policy_version=None):
        self.calls.append(args)
        return {'status': 'confirmed', 'target': self.target}

    def status(self, customer_id, target_id):
        return self.target if customer_id == CUSTOMER['id'] and str(target_id) == self.target['id'] else None

    def cancel(self, customer_id, target_id):
        self.calls.append(('cancel', customer_id, target_id))
        return True


class UsdtFundingNavigationTests(unittest.TestCase):
    def presenter(self, funding=None):
        return CatalogPresenter(MemoryCatalog(), signing_key='test-secret', usdt_funding=funding)

    def test_disabled_rail_is_visible_but_cannot_open(self):
        funding = Funding(False)
        presenter = self.presenter(funding)
        chooser = presenter.route(CUSTOMER, 'balance:currency:usd', {}, root=ROOT, chat_id='41001')
        self.assertTrue(any(b.get('callback_data') == 'usdt:rail:binance_pay' for b in buttons(chooser)))
        presenter.route(CUSTOMER, 'usdt:rail:binance_pay', {}, root=ROOT, chat_id='41001')
        self.assertEqual(funding.calls, [])

    def test_preset_is_customer_and_policy_bound(self):
        funding = Funding()
        presenter = self.presenter(funding)
        surface = presenter.route(CUSTOMER, 'usdt:rail:binance_pay', {}, root=ROOT, chat_id='41001')
        callback = next(b['callback_data'] for b in buttons(surface) if b.get('callback_data', '').startswith('usdt:p:'))
        self.assertIsNone(presenter.route(dict(CUSTOMER, id=CUSTOMER['id'] + 1), callback, {}, root=ROOT, chat_id='41001'))
        funding.version += 1
        presenter.route(CUSTOMER, callback, {}, root=ROOT, chat_id='41001')
        self.assertEqual(funding.calls, [])
        funding.version -= 1
        presenter.route(CUSTOMER, callback, {}, root=ROOT, chat_id='41001')
        self.assertEqual(funding.calls[0][-2:], (300000, 'binance_pay'))

    def test_custom_amount_rejects_stale_root_and_preserves_exact_micro_units(self):
        funding = Funding()
        presenter = self.presenter(funding)
        state = {'usdt_amount': {'rail': 'binance_pay', 'chat_id': '41001', 'message_id': 7, 'generation': 3}}
        presenter.usdt_amount_input(CUSTOMER, '0,000001', state, ROOT, '41001')
        self.assertEqual(funding.calls[0][-2:], (1, 'binance_pay'))
        state = {'usdt_amount': {'rail': 'binance_pay', 'chat_id': '41001', 'message_id': 8, 'generation': 3}}
        presenter.usdt_amount_input(CUSTOMER, '1', state, ROOT, '41001')
        self.assertEqual(len(funding.calls), 1)

    def test_forged_customer_action_is_not_dispatched(self):
        funding = Funding()
        presenter = self.presenter(funding)
        presenter.route(dict(CUSTOMER, id=CUSTOMER['id'] + 1), 'usdt:close:' + funding.target['id'], {}, root=ROOT, chat_id='41001')
        self.assertEqual(funding.calls, [])

    def test_settled_without_ledger_entry_is_not_presented_paid(self):
        presenter = self.presenter()
        target = Funding().target
        target['state'] = 'settled'
        surface = presenter.usdt_status(CUSTOMER, target)
        self.assertNotIn('balance was credited', surface.caption)
        self.assertNotIn('Saldo berhasil', surface.caption)

    def test_history_cursor_is_customer_bound_and_tamper_evident(self):
        repository = MemoryCatalog()
        row = {'kind': 'funding_credit', 'amount_micro': 1, 'reference': 'ref',
               'occurred_at': '2026-01-01T00:00:00.000000', 'occurred_micros': 1767225600000000,
               'rank': 2, 'item_id': 1}
        calls = []
        def history(customer_id, *, direction=None, cursor=None):
            calls.append((customer_id, direction, cursor))
            return [row] * 26
        repository.usdt_history = history
        presenter = CatalogPresenter(repository, signing_key='test-secret')
        surface = presenter.usdt_history(CUSTOMER)
        cursor = next(b['callback_data'] for b in buttons(surface)
                      if b.get('callback_data', '').startswith('usdt:h:o:'))
        self.assertIsNone(presenter.usdt_history(dict(CUSTOMER, id=2), cursor))
        changed = cursor[:9] + ('A' if cursor[9] != 'A' else 'B') + cursor[10:]
        self.assertIsNone(presenter.usdt_history(CUSTOMER, changed))
        self.assertEqual(len(calls), 1)
        presenter.usdt_history(CUSTOMER, cursor)
        self.assertEqual(calls[-1][1], 'older')
