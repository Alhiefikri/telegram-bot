"""Pure P23 admin reporting/sanitisation boundaries (no database)."""

import os
import unittest
from unittest.mock import patch

from lhie_candidate.runtime import Runtime


class AdminOperationsUnitTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        import django
        os.environ["DJANGO_SETTINGS_MODULE"] = "lhie_candidate.settings"
        with patch("lhie_candidate.runtime.load_runtime",
                   return_value=Runtime("synthetic-empty", "a" * 64)):
            django.setup()

    def test_secret_shaped_values_are_redacted_recursively(self):
        from lhie_candidate.admin.sanitize import bounded, json_value
        payload = json_value('{"password":"secret-x","nested":{"api_key":"secret-y","amount":7},'
                             '"provider_payload":{"token":"secret-z"},"safe":"ok"}')
        self.assertEqual(payload["password"], "[dirahasiakan]")
        self.assertEqual(payload["nested"]["api_key"], "[dirahasiakan]")
        self.assertEqual(payload["nested"]["amount"], 7)
        self.assertEqual(payload["provider_payload"], "[dirahasiakan]")
        self.assertEqual(payload["safe"], "ok")
        self.assertNotIn("secret-", str(payload))
        self.assertEqual(bounded("x" * 500), "x" * 300 + "…")

    def test_invoice_search_removes_wildcards_and_requires_three_characters(self):
        from lhie_candidate.admin.orders import invoice_search
        self.assertEqual(invoice_search("inv-abc"), ("INV-ABC", False))
        self.assertEqual(invoice_search("a_%"), ("", True))
        self.assertEqual(invoice_search("a%' OR 1=1--"), ("AOR11--", False))
        self.assertEqual(invoice_search(None), ("", False))

    def test_reporting_filters_are_bounded(self):
        from lhie_candidate.admin.reporting import normalize_filters
        self.assertEqual(normalize_filters({"currency": "USD", "period": "x",
                                            "days": "999"})["currency"], "IDR")
        self.assertEqual(normalize_filters({"currency": "USDT",
                                            "period": "mingguan"})["period"], "mingguan")
        self.assertEqual(normalize_filters({"days": "30"})["days"], 30)
        month = normalize_filters({"month": "1999-01"})["month"]
        self.assertRegex(month, r"^20[0-9]{2}-(0[1-9]|1[0-2])$")
        self.assertEqual(normalize_filters({"provider": "evil"})["provider"], "")

    def test_export_filename_is_currency_scoped_and_bounded(self):
        from lhie_candidate.admin.reporting import export_filename
        name = export_filename({"currency": "USDT"})
        self.assertTrue(name.startswith("lhie-earnings-usdt-"))
        self.assertTrue(name.endswith(".csv"))


if __name__ == "__main__":
    unittest.main()
