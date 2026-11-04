from dataclasses import FrozenInstanceError
from types import MappingProxyType
import unittest

from lhie_candidate.checkout.projection import (
    OrderLifecycleProjection, paid_order_status,
)


class OrderLifecycleProjectionTests(unittest.TestCase):
    def project(self, order_status, payment_status, *, summary='pending'):
        return OrderLifecycleProjection.from_rows(
            {'order_status': order_status, 'payment_status': summary},
            {'status': payment_status})

    def test_paid_requires_paid_payment_and_fulfillable_order_state(self):
        for order_status in ('draft', 'pending_payment', 'processing', 'completed',
                             'cancelled', 'expired'):
            for payment_status in ('pending', 'paid', 'failed', 'expired', 'refunded'):
                with self.subTest(order=order_status, payment=payment_status):
                    projection = self.project(order_status, payment_status)
                    self.assertEqual(projection.is_paid,
                                     payment_status == 'paid' and order_status in (
                                         'processing', 'completed'))
                    self.assertEqual(projection.is_pending_payment,
                                     payment_status == 'pending'
                                     and order_status == 'pending_payment')
                    self.assertEqual(projection.is_terminal,
                                     payment_status in ('failed', 'expired')
                                     or order_status in ('cancelled', 'expired'))
                    self.assertEqual(projection.order_status, order_status)
                    self.assertEqual(projection.payment_status, payment_status)

    def test_missing_payment_only_falls_back_for_display_status(self):
        for state, summary in (('completed', 'paid'), ('pending_payment', 'pending'),
                               ('processing', 'failed')):
            with self.subTest(state=state, summary=summary):
                projection = OrderLifecycleProjection.from_rows(
                    {'order_status': state, 'payment_status': summary})
                self.assertEqual(projection.payment_status, summary)
                self.assertFalse(projection.is_paid)
                self.assertFalse(projection.is_pending_payment)
                self.assertFalse(projection.is_terminal)
                self.assertEqual(projection.terminal_cause, 'terminal')
        projection = OrderLifecycleProjection.from_rows(
            {'order_status': 'completed', 'payment_status': 'paid'}, {'status': None})
        self.assertEqual(projection.payment_status, 'paid')
        self.assertFalse(projection.is_paid)

    def test_terminal_cause_precedence_matches_laravel(self):
        cases = (
            ('cancelled', 'expired', 'expired'),
            ('expired', 'failed', 'expired'),
            ('cancelled', 'failed', 'cancelled'),
            ('pending_payment', 'failed', 'provider_failed'),
            ('completed', 'refunded', 'terminal'),
            ('processing', 'paid', 'terminal'),
        )
        for order_status, payment_status, cause in cases:
            with self.subTest(order=order_status, payment=payment_status):
                self.assertEqual(self.project(order_status, payment_status).terminal_cause,
                                 cause)

    def test_late_money_never_projects_a_released_order_as_fulfillable(self):
        for provider in ('pakasir', 'sakurupiah', 'klikqris', 'manual', 'balance'):
            for state in ('expired', 'cancelled'):
                with self.subTest(provider=provider, state=state):
                    order = {'order_status': state, 'payment_status': 'paid'}
                    payment = {'provider': provider, 'status': 'paid'}
                    projection = OrderLifecycleProjection.from_rows(order, payment)
                    self.assertFalse(projection.is_paid)
                    self.assertFalse(projection.is_pending_payment)
                    self.assertTrue(projection.is_terminal)
                    self.assertEqual(projection.terminal_cause, state)
                    self.assertEqual(projection.order_status, state)

    def test_paid_review_is_processing_not_a_terminal_failure(self):
        for provider in ('manual', 'sakurupiah', 'balance'):
            with self.subTest(provider=provider):
                status = paid_order_status({'provider': provider}, has_open_review=True,
                                           local_credentials_complete=True)
                self.assertEqual(status, 'processing')
                projection = self.project(status, 'paid')
                self.assertTrue(projection.is_paid)
                self.assertFalse(projection.is_terminal)

    def test_historical_pakasir_completion_is_not_applied_to_modern_rails(self):
        for provider in ('pakasir', 'Pakasir', 'PAKASIR'):
            with self.subTest(provider=provider):
                self.assertEqual(paid_order_status({'provider': provider}), 'completed')
                self.assertEqual(paid_order_status({'provider': provider},
                                                  has_open_review=True), 'completed')
        for provider in ('sakurupiah', 'klikqris', 'balance', 'usdt_balance', 'manual'):
            with self.subTest(provider=provider):
                self.assertEqual(paid_order_status({'provider': provider}), 'processing')
        # Laravel lowercases historical provider strings without trimming them.
        self.assertEqual(paid_order_status({'provider': ' pakasir '}), 'processing')

    def test_supplier_pending_precedes_pakasir_and_local_completion(self):
        for provider in ('pakasir', 'manual', 'balance', 'sakurupiah'):
            with self.subTest(provider=provider):
                self.assertEqual(paid_order_status(
                    {'provider': provider}, supplier_pending=True,
                    local_credentials_complete=True), 'processing')

    def test_automatic_completion_requires_credentials_without_open_review(self):
        for provider in ('balance', 'sakurupiah', 'klikqris', 'usdt_balance'):
            for complete in (False, True):
                for review in (False, True):
                    with self.subTest(provider=provider, complete=complete, review=review):
                        self.assertEqual(paid_order_status(
                            {'provider': provider}, has_open_review=review,
                            local_credentials_complete=complete),
                            'completed' if complete and not review else 'processing')
        self.assertEqual(paid_order_status({'provider': 'manual'},
                                          local_credentials_complete=True), 'processing')

    def test_projection_cannot_mutate_or_retarget_historical_payment(self):
        order_values = {'id': 'original-order', 'order_status': 'completed',
                        'payment_status': 'paid', 'currency': 'IDR'}
        payment_values = {'id': 'original-payment', 'provider': 'Pakasir', 'status': 'paid',
                          'provider_reference': 'old-reference', 'amount': 12345,
                          'policy_snapshot': {'provider': 'pakasir'}}
        order = MappingProxyType(order_values.copy())
        payment = MappingProxyType(payment_values.copy())
        projection = OrderLifecycleProjection.from_rows(order, payment)
        self.assertEqual(paid_order_status(payment), 'completed')
        self.assertTrue(projection.is_paid)
        self.assertEqual(dict(order), order_values)
        self.assertEqual(dict(payment), payment_values)
        with self.assertRaises(FrozenInstanceError):
            projection.order_status = 'pending_payment'


class HistoricalOrderStatusReaderTests(unittest.TestCase):
    def setUp(self):
        from lhie_candidate.navigation.presentation import CatalogPresenter
        from tests.test_catalog_presentation import CUSTOMER, MemoryCatalog

        self.customer = dict(CUSTOMER)
        self.repository = MemoryCatalog()
        self.presenter = CatalogPresenter(self.repository)

    def test_history_consumes_projection_and_preserves_existing_locales(self):
        from tests.test_catalog_presentation import buttons, order

        historical = order(1, 'INV-PAKASIR', created_token='20261101120000',
                           receipt_sent=False)
        historical['provider'] = 'Pakasir'
        modern = order(2, 'INV-MODERN', created_token='20261101115900',
                       order_status='processing', receipt_sent=False)
        modern['provider'] = 'sakurupiah'
        acknowledged = order(3, 'INV-ACK', created_token='20261101115800',
                             order_status='processing')
        self.repository.order_rows = [historical, modern, acknowledged]
        for code, delivered, processing in (
                ('id', 'Terkirim', 'Diproses'), ('en', 'Delivered', 'Processing'),
                ('ms', 'Dihantar', 'Sedang diproses')):
            with self.subTest(locale=code):
                self.customer['locale'] = code
                surface = self.presenter.orders(self.customer)
                labels = [button['text'] for button in buttons(surface)
                          if button.get('callback_data', '').startswith('order:')]
                self.assertIn('INV-PAKASIR · ' + delivered, labels[0])
                self.assertIn('INV-MODERN · ' + processing, labels[1])
                self.assertIn('INV-ACK · ' + delivered, labels[2])

    def test_reader_uses_persisted_status_not_provider_completion_rule(self):
        for provider in ('Pakasir', 'sakurupiah'):
            with self.subTest(provider=provider):
                row = {'order_status': 'processing', 'payment_status': 'paid',
                       'provider': provider, 'receipt_sent': False}
                self.assertEqual(self.presenter._order_status(self.customer, row),
                                 self.presenter.t(self.customer, 'status.order.processing'))
                row['order_status'] = 'completed'
                self.assertEqual(self.presenter._order_status(self.customer, row),
                                 self.presenter.t(self.customer, 'order.history.delivered'))
                row['payment_status'] = 'refunded'
                self.assertEqual(self.presenter._order_status(self.customer, row),
                                 self.presenter.t(self.customer, 'status.order.completed'))

    def test_owned_history_excludes_late_foreign_and_pending_without_activation(self):
        from copy import deepcopy
        from tests.test_catalog_presentation import buttons, order

        rows = [
            order(1, 'INV-OWNED', created_token='20261101120000', receipt_sent=False),
            order(2, 'INV-FOREIGN', created_token='20261101120100', customer_id=2),
            order(3, 'INV-LATE', created_token='20261101120200', order_status='expired'),
            order(4, 'INV-PENDING', created_token='20261101120300',
                  order_status='pending_payment', payment_status='pending'),
        ]
        self.repository.order_rows = rows
        before = deepcopy(rows)
        surface = self.presenter.orders(self.customer)
        controls = buttons(surface)
        self.assertEqual([button['callback_data'] for button in controls],
                         ['order:INV-OWNED', 'menu:back', 'menu:home'])
        self.assertFalse(any('url' in button for button in controls))
        self.assertEqual(rows, before)

    def test_history_receipt_mapping_preserves_laravel_summary_precedence(self):
        # Display parity is not fulfillment authority: is_paid stays false.
        for state in ('expired', 'cancelled'):
            projection = OrderLifecycleProjection.from_rows(
                {'order_status': state, 'payment_status': 'paid'})
            self.assertTrue(projection.is_terminal)
            self.assertFalse(projection.is_paid)
            self.assertEqual(projection.history_status(), state)
            self.assertEqual(projection.history_status(receipt_sent=True), 'delivered')


if __name__ == '__main__':
    unittest.main()
