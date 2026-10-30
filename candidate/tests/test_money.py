from dataclasses import FrozenInstanceError
import unittest

from lhie_candidate.domain.money import Currency, MAX_UNITS, Money


class MoneyTests(unittest.TestCase):
    def test_exact_decimal_round_trips(self):
        cases = (
            (Currency.IDR, "0", 0, "0"),
            (Currency.IDR, "9223372036854775807", MAX_UNITS, str(MAX_UNITS)),
            (Currency.USDT, "0", 0, "0"),
            (Currency.USDT, "0.000001", 1, "0.000001"),
            (Currency.USDT, "1.250000", 1_250_000, "1.25"),
            (Currency.USDT, "1.250000000", 1_250_000, "1.25"),
            (Currency.USDT, "9223372036854.775807", MAX_UNITS, "9223372036854.775807"),
        )
        for currency, text, units, formatted in cases:
            with self.subTest(currency=currency, text=text):
                money = Money.from_decimal(currency, text)
                self.assertEqual(money.units, units)
                self.assertEqual(money.format_decimal(), formatted)
                self.assertEqual(Money.from_decimal(currency, formatted), money)

    def test_rejects_ambiguous_or_inexact_decimals(self):
        invalid = (
            "", " 1", "1 ", "01", "+1", "-1", "1e2", "NaN", "Infinity",
            ".5", "1.", "1,5", "1_000", "١", "1\n", "0.0000001", "1.00000001",
            "9223372036854.775808", "0." + "0" * 31,
            None, True, 1, 1.0, b"1",
        )
        for value in invalid:
            with self.subTest(value=value), self.assertRaises(ValueError):
                Money.from_decimal(Currency.USDT, value)
        for value in ("1.0", "0.1", "9223372036854775808"):
            with self.subTest(value=value), self.assertRaises(ValueError):
                Money.from_decimal(Currency.IDR, value)

    def test_units_require_native_bounded_integer(self):
        for value in (True, False, 1.0, "1", None, -1, MAX_UNITS + 1):
            with self.subTest(value=value), self.assertRaises(ValueError):
                Money(Currency.IDR, value)
        for currency in ("IDR", "USD", None):
            with self.subTest(currency=currency), self.assertRaises(ValueError):
                Money(currency, 1)
            with self.assertRaises(ValueError):
                Money.from_decimal(currency, "1")

    def test_multiplication_checks_bigint_without_rounding(self):
        self.assertEqual(Money(Currency.USDT, MAX_UNITS).multiply(1).units, MAX_UNITS)
        self.assertEqual(Money(Currency.IDR, MAX_UNITS // 2).multiply(2).units, MAX_UNITS - 1)
        self.assertEqual(Money(Currency.USDT, 0).multiply(MAX_UNITS).units, 0)
        with self.assertRaises(ValueError):
            Money(Currency.IDR, MAX_UNITS // 2 + 1).multiply(2)
        for quantity in (0, -1, True, 2.0, "2", MAX_UNITS + 1):
            with self.subTest(quantity=quantity), self.assertRaises(ValueError):
                Money(Currency.IDR, 1).multiply(quantity)

    def test_values_are_immutable(self):
        money = Money(Currency.IDR, 1)
        with self.assertRaises(FrozenInstanceError):
            money.units = 2

