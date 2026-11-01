import json

from unittest import TestCase

from lhie_candidate.payments.sakurupiah import (
    CreateRequest, InvoiceIdentity, OutcomeState, ProviderUnavailable,
    SakurupiahSandboxAdapter,
)


class Relay:
    def __init__(self, responses):
        self.responses = iter(responses)
        self.calls = []

    def post(self, url, **kwargs):
        self.calls.append((url, kwargs))
        response = next(self.responses)
        if isinstance(response, Exception):
            raise response
        return 200, json.dumps(response).encode()


def envelope(*rows):
    return {"status": "200", "data": list(rows)}


class SakurupiahAdapterTests(TestCase):
    def setUp(self):
        self.identity = InvoiceIdentity("checkout-123", 10000, "QRIS")

    def adapter(self, responses):
        relay = Relay(responses)
        return SakurupiahSandboxAdapter(transport=relay), relay

    def history(self, **changes):
        row = {"trx_id": "trx-1", "merchant_ref": "checkout-123", "payment_kode": "QRIS",
               "amount": "10070", "status": "berhasil"}
        row.update(changes)
        return row

    def test_create_accepts_matching_provider_invoice(self):
        row = {"trx_id": "trx-1", "merchant_ref": "checkout-123", "payment_kode": "QRIS",
               "payment_status": "pending", "total": "10070", "fee": "70",
               "expired": "2030-01-01 10:00:00", "qr": "provider observation"}
        adapter, _ = self.adapter([envelope(row)])
        request = CreateRequest(self.identity, "0812345678", "https://example.test/callback",
                                "https://example.test/return")
        result = adapter.create(request)
        self.assertEqual(result.state, OutcomeState.CREATED)
        self.assertEqual((result.total, result.fee), (10070, 70))

    def test_mismatched_fee_payer_is_not_payable(self):
        row = {"trx_id": "trx-1", "merchant_ref": "checkout-123", "payment_kode": "QRIS",
               "payment_status": "pending", "total": "10070", "fee": "70",
               "merchant_fee": "Merchant"}
        adapter, _ = self.adapter([envelope(row)])
        request = CreateRequest(self.identity, "0812345678", "https://example.test/cb", "https://example.test/return")
        self.assertEqual(adapter.create(request).state, OutcomeState.UNKNOWN)

    def test_ambiguous_create_is_not_retried(self):
        adapter, relay = self.adapter([TimeoutError("private information")])
        result = adapter.create(CreateRequest(self.identity, "0812345678", "https://example.test/cb", "https://example.test/return"))
        self.assertEqual(result.state, OutcomeState.UNKNOWN)
        self.assertNotIn("private information", repr(result))
        self.assertEqual(len(relay.calls), 1)

    def test_rate_limit_and_server_failure_leave_create_outcome_unknown(self):
        class FailedRelay:
            def __init__(self, status):
                self.status = status
                self.calls = 0

            def post(self, url, **kwargs):
                self.calls += 1
                return self.status, b'{}'

        request = CreateRequest(self.identity, "0812345678", "https://example.test/cb", "https://example.test/return")
        for status in (429, 503):
            with self.subTest(status=status):
                relay = FailedRelay(status)
                result = SakurupiahSandboxAdapter(transport=relay).create(request)
                self.assertEqual(result.state, OutcomeState.UNKNOWN)
                self.assertEqual(relay.calls, 1)

    def test_status_requires_matching_independent_history(self):
        adapter, _ = self.adapter([envelope({"status": "berhasil"}), envelope(self.history())])
        result = adapter.status(self.identity, "trx-1", provider_total=10070)
        self.assertEqual(result.state, OutcomeState.SUCCESS)
        self.assertEqual(result.provider_ref, "trx-1")
        self.assertIsNone(result.total)

    def test_history_may_report_base_or_snapshotted_payable_amount(self):
        for amount in ("10000", "10070"):
            with self.subTest(amount=amount):
                adapter, _ = self.adapter([envelope({"status": "berhasil"}),
                                            envelope(self.history(amount=amount))])
                self.assertEqual(adapter.status(self.identity, "trx-1", provider_total=10070).state,
                                 OutcomeState.SUCCESS)

    def test_status_identity_mismatch_cannot_authorize_success(self):
        adapter, _ = self.adapter([envelope({"status": "berhasil", "merchant_ref": "foreign"})])
        self.assertEqual(adapter.status(self.identity, "trx-1", provider_total=10070).state,
                         OutcomeState.UNKNOWN)

    def test_mismatched_or_ambiguous_history_is_unknown(self):
        for rows in ([self.history(amount="9999")], [self.history(), self.history()], []):
            with self.subTest(rows=rows):
                adapter, _ = self.adapter([envelope(*rows)])
                self.assertEqual(adapter.recover(self.identity, provider_total=10070).state, OutcomeState.UNKNOWN)

    def test_list_requires_observed_limits(self):
        adapter, _ = self.adapter([envelope({"kode": "QRIS", "status": "Aktif", "minimal": "500", "maksimal": "2000000"})])
        channel, = adapter.list_channels()
        self.assertEqual((channel.code, channel.active, channel.minimum, channel.maximum), ("QRIS", True, 500, 2000000))
        adapter, _ = self.adapter([envelope({"kode": "QRIS", "status": "Aktif"})])
        with self.assertRaises(ProviderUnavailable):
            adapter.list_channels()

    def test_exact_money_rejects_floats_and_booleans(self):
        for amount in (True, 1.0, 0, -1):
            with self.subTest(amount=amount), self.assertRaises(ValueError):
                InvoiceIdentity("ref", amount, "QRIS")
