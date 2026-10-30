import unittest

from lhie_candidate.domain.money import Currency, MAX_UNITS, Money
from lhie_candidate.domain.purchase import (
    Affordability,
    AssessPurchase,
    PurchaseInputs,
    assess_affordability,
)


class ReadOnlyRequests:
    def __init__(self, requests):
        self.requests = requests
        self.read_ids = []

    def load_affordability(self, request_id):
        self.read_ids.append(request_id)
        return self.requests.get(request_id)


class PurchaseTests(unittest.TestCase):
    def test_affordability_boundaries_in_both_currencies(self):
        for currency in Currency:
            for available, outcome, shortfall in (
                (0, Affordability.INSUFFICIENT_FUNDS, 30),
                (29, Affordability.INSUFFICIENT_FUNDS, 1),
                (30, Affordability.AFFORDABLE, 0),
                (31, Affordability.AFFORDABLE, 0),
                (MAX_UNITS, Affordability.AFFORDABLE, 0),
            ):
                with self.subTest(currency=currency, available=available):
                    inputs = PurchaseInputs(Money(currency, available), Money(currency, 10), 3)
                    assessment = assess_affordability(inputs)
                    self.assertEqual(assessment.outcome, outcome)
                    self.assertEqual(assessment.total, Money(currency, 30))
                    self.assertEqual(assessment.shortfall, Money(currency, shortfall))
                    self.assertEqual(inputs.available.units, available)

    def test_representation_boundary_is_not_a_business_cap(self):
        inputs = PurchaseInputs(Money(Currency.USDT, MAX_UNITS), Money(Currency.USDT, MAX_UNITS), 1)
        self.assertEqual(assess_affordability(inputs).outcome, Affordability.AFFORDABLE)
        with self.assertRaises(ValueError):
            assess_affordability(PurchaseInputs(inputs.available, inputs.unit_price, 2))

    def test_no_implicit_fx_zero_price_or_untrusted_quantity(self):
        with self.assertRaises(ValueError):
            PurchaseInputs(Money(Currency.IDR, 10), Money(Currency.USDT, 1), 1)
        with self.assertRaises(ValueError):
            PurchaseInputs(Money(Currency.IDR, 10), Money(Currency.IDR, 0), 1)
        for quantity in (0, -1, True, 1.0, "1", MAX_UNITS + 1):
            with self.subTest(quantity=quantity), self.assertRaises(ValueError):
                PurchaseInputs(Money(Currency.IDR, 10), Money(Currency.IDR, 1), quantity)
        with self.assertRaises(ValueError):
            PurchaseInputs(10, Money(Currency.IDR, 1), 1)

    def test_injected_reader_resolves_id_and_missing_request(self):
        inputs = PurchaseInputs(Money(Currency.IDR, 20), Money(Currency.IDR, 10), 2)
        reader = ReadOnlyRequests({7: inputs})
        service = AssessPurchase(reader)
        self.assertEqual(service.execute(7), assess_affordability(inputs))
        self.assertIsNone(service.execute(8))
        self.assertEqual(reader.read_ids, [7, 8])
        self.assertEqual(reader.requests, {7: inputs})
        for request_id in (True, 0, -1, "7", MAX_UNITS + 1):
            with self.subTest(request_id=request_id), self.assertRaises(ValueError):
                service.execute(request_id)
        self.assertEqual(reader.read_ids, [7, 8])
