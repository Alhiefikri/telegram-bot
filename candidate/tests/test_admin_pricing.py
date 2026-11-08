"""Pure P22 admin price, credential and bulk-input boundaries."""

import os
import unittest
from unittest.mock import patch

from lhie_candidate.runtime import Runtime


class AdminPricingTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        import django
        os.environ["DJANGO_SETTINGS_MODULE"] = "lhie_candidate.settings"
        with patch("lhie_candidate.runtime.load_runtime",
                   return_value=Runtime("synthetic-empty", "a" * 64)):
            django.setup()

    def test_usdt_input_is_exact_micro_units_and_bounded(self):
        from lhie_candidate.admin.pricing import parse_usdt_input
        self.assertEqual(parse_usdt_input("1"), 1_000_000)
        self.assertEqual(parse_usdt_input("1.8"), 1_800_000)
        self.assertEqual(parse_usdt_input("0.001"), 1_000)
        self.assertEqual(parse_usdt_input("1."), 1_000_000)
        self.assertIsNone(parse_usdt_input(" "))
        for invalid in ("1.0001", "-1", "1e2", ".5", "01"):
            with self.assertRaises(ValueError):
                parse_usdt_input(invalid)

    def test_reseller_usdt_requires_a_positive_base_usdt_price(self):
        from lhie_candidate.admin.pricing import PricingScheduleError, validate_reseller
        validate_reseller(1000, 1_000_000, 900, 900_000)
        for base in (None, 0):
            with self.assertRaises(PricingScheduleError):
                validate_reseller(1000, base, None, 1)
        with self.assertRaises(PricingScheduleError):
            validate_reseller(1000, 1_000_000, 0, None)

    def test_tier_schedule_requires_ordered_lower_prices_and_complete_usdt(self):
        from lhie_candidate.admin.pricing import PricingScheduleError, validate_schedule
        tiers = [
            {"minimum_quantity": 2, "price_amount": 900, "usdt_price_amount": 900_000},
            {"minimum_quantity": 3, "price_amount": 800, "usdt_price_amount": 800_000},
        ]
        validate_schedule(1000, 1_000_000, tiers)
        for broken in (
            [{"minimum_quantity": 2, "price_amount": 1000, "usdt_price_amount": 900_000}],
            [{"minimum_quantity": 3, "price_amount": 900, "usdt_price_amount": 900_000},
             {"minimum_quantity": 3, "price_amount": 800, "usdt_price_amount": 800_000}],
            [{"minimum_quantity": 2, "price_amount": 900, "usdt_price_amount": None}],
            [{"minimum_quantity": 2, "price_amount": 900, "usdt_price_amount": 1_000_000}],
        ):
            with self.assertRaises(PricingScheduleError):
                validate_schedule(1000, 1_000_000, broken)
        with self.assertRaises(PricingScheduleError):
            validate_schedule(1000, None, [
                {"minimum_quantity": 2, "price_amount": 900, "usdt_price_amount": 1}
            ])
        validate_schedule(1000, None, [
            {"minimum_quantity": 2, "price_amount": 900, "usdt_price_amount": None}
        ])
        with self.assertRaises(PricingScheduleError):
            validate_schedule(1000, None, [
                {"minimum_quantity": 2, "price_amount": 900, "usdt_price_amount": 1}
            ])

    def test_prices_reject_quantity_100_overflow_and_store_thousandth_input(self):
        from lhie_candidate.admin.pricing import MAX_PRICE, parse_base_prices
        errors = {}
        idr, usdt, reseller, reseller_usdt = parse_base_prices({
            "price_amount": str(MAX_PRICE),
            "usdt_price_amount": "92233720368.547",
            "reseller_price_amount": "1",
            "reseller_usdt_price_amount": "0.001",
        }, errors)
        self.assertFalse(errors)
        self.assertEqual(idr, MAX_PRICE)
        self.assertEqual(usdt, 92_233_720_368_547_000)
        self.assertEqual(reseller_usdt, 1_000)
        errors = {}
        parse_base_prices({
            "price_amount": str(MAX_PRICE + 1),
            "usdt_price_amount": "92233720368.548",
        }, errors)
        self.assertIn("price_amount", errors)
        self.assertIn("usdt_price_amount", errors)


class AdminCredentialTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        import django
        os.environ["DJANGO_SETTINGS_MODULE"] = "lhie_candidate.settings"
        with patch("lhie_candidate.runtime.load_runtime",
                   return_value=Runtime("synthetic-empty", "a" * 64)):
            django.setup()

    def test_structured_payload_only_claims_explicit_auth_tokens(self):
        from lhie_candidate.admin import credentials
        payload = credentials.structured_payload(
            email_value="  User@Example.INVALID ",
            password_value=" synthetic-password ",
            access_method_value="auth_token",
            access_value="TOKEN-With-Case ",
        )
        self.assertEqual(payload["email"], "user@example.invalid")
        self.assertEqual(payload["password"], " synthetic-password ")
        self.assertEqual(credentials.identity_values(payload),
                         [("auth_token", "TOKEN-With-Case ")])
        otp = credentials.structured_payload(
            email_value="user@example.invalid", password_value="secret",
            access_method_value="otp_mail_link",
            access_value="https://mail.example.invalid/inbox?token=synthetic",
        )
        self.assertEqual(credentials.identity_values(otp), [])
        self.assertEqual(credentials.otp_domain(otp), "mail.example.invalid")
        self.assertEqual(credentials.mask_email("user@example.invalid"), "u•••@example.invalid")

    def test_otp_and_auth_token_rules_fail_closed(self):
        from lhie_candidate.admin import credentials
        for value in ("http://mail.example.invalid/x", "https://user:pass@mail.example.invalid/x",
                      "https://mail.example.invalid/x y", "javascript:alert(1)", "https:///x"):
            with self.assertRaises(credentials.CredentialError):
                credentials.structured_payload(
                    email_value="user@example.invalid", password_value="secret",
                    access_method_value="otp_mail_link", access_value=value)
        with self.assertRaises(credentials.CredentialError):
            credentials.structured_payload(
                email_value="user@example.invalid", password_value="secret",
                access_method_value="auth_token", access_value="   ")
        with self.assertRaises(credentials.CredentialError):
            credentials.structured_payload(
                email_value="not-an-email", password_value="secret",
                access_method_value="none")

    def test_legacy_and_separator_formats_do_not_interpret_email_as_identity(self):
        from lhie_candidate.admin import credentials
        self.assertEqual(credentials.credential_parts("user@example.invalid|secret")["mfa"], None)
        self.assertEqual(credentials.credential_parts(
            "user@example.invalid----secret----123456")["mfa"], "123456")
        self.assertIsNone(credentials.credential_parts("not-an-email|secret"))
        self.assertIsNone(credentials.credential_parts("user@example.invalid|"))

    def test_structured_rows_and_bulk_duplicate_token_are_rejected_without_values(self):
        from lhie_candidate.admin.stock import StockService
        service = StockService(encrypter=object())
        data = {
            "accounts.0.email": "one@example.invalid",
            "accounts.0.password": "synthetic",
            "accounts.0.access_method": "auth_token",
            "accounts.0.access_value": "TOKEN-SAME",
            "accounts.1.email": "two@example.invalid",
            "accounts.1.password": "synthetic",
            "accounts.1.access_method": "auth_token",
            "accounts.1.access_value": "TOKEN-SAME",
        }
        items, canonical, duplicates, errors = service.parse_accounts(data)
        self.assertEqual(len(items), 2)
        self.assertEqual(duplicates, {0: True, 1: True})
        self.assertIn("accounts.0.access_value", errors)
        self.assertIn("accounts.1.access_value", errors)
        self.assertNotIn("TOKEN-SAME", str(errors))

    def test_unpublished_identity_key_never_enables_structured_input(self):
        from django.test import override_settings
        from lhie_candidate.admin.stock import StockService
        with override_settings(CANDIDATE_IDENTITY_FINGERPRINT_KEY=None):
            self.assertFalse(StockService(encrypter=object()).structured_enabled())


if __name__ == "__main__":
    unittest.main()
