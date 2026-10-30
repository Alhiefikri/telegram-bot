import os
import unittest
from datetime import UTC, datetime, timedelta, timezone
from decimal import Decimal, localcontext
from unittest.mock import patch

from lhie_candidate.runtime import Runtime


class LegacyFieldTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        import django
        os.environ["DJANGO_SETTINGS_MODULE"] = "lhie_candidate.settings"
        with patch("lhie_candidate.runtime.load_runtime", return_value=Runtime("synthetic-empty", "a" * 64)):
            django.setup()
        from lhie_candidate.legacy import ExactBigInteger, ExactUnits, LegacyTimestamp
        cls.timestamp = LegacyTimestamp()
        cls.units = ExactUnits(max_digits=78, decimal_places=0)
        cls.money = ExactBigInteger()

    def test_legacy_wall_time_is_not_interpreted_as_utc_or_wita(self):
        instant = datetime(2026, 10, 30, 1, 2, 3, 999999, tzinfo=UTC)
        wall = self.timestamp.get_prep_value(instant)
        self.assertEqual(wall, datetime(2026, 10, 30, 8, 2, 3))
        self.assertEqual(self.timestamp.from_db_value(wall, None, None), instant.replace(microsecond=0))
        offset_instant = instant.astimezone(timezone(timedelta(hours=-4)))
        self.assertEqual(self.timestamp.get_prep_value(offset_instant), wall)
        with self.assertRaises(ValueError):
            self.timestamp.get_prep_value(datetime(2026, 10, 30))

    def test_decimal_context_cannot_round_or_overflow_78_digit_units(self):
        maximum = Decimal("9" * 78)
        with localcontext() as context:
            context.prec = 6
            self.assertEqual(self.units.get_db_prep_value(maximum, None), maximum)
        for value in (float(100), True, "100", Decimal("1.5"), Decimal("1e78"), Decimal("NaN"), Decimal("Infinity")):
            with self.subTest(kind=type(value).__name__), self.assertRaises(ValueError):
                self.units.get_db_prep_value(value, None)

    def test_money_never_accepts_float_bool_or_silent_bigint_overflow(self):
        self.assertEqual(self.money.get_prep_value(2 ** 63 - 1), 2 ** 63 - 1)
        for value in (1.0, True, "100", Decimal("100"), 2 ** 63, -(2 ** 63) - 1):
            with self.subTest(kind=type(value).__name__), self.assertRaises(ValueError):
                self.money.get_prep_value(value)
