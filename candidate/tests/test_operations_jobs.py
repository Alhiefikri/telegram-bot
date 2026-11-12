"""Scheduled provider work stays inert until an adapter is explicitly admitted."""
from datetime import datetime, timezone
import unittest
from unittest.mock import patch

from lhie_candidate.operations import jobs


class JobAdapterAdmissionTests(unittest.TestCase):
    def setUp(self):
        self.context = jobs.JobContext(
            datetime(2026, 11, 12, tzinfo=timezone.utc), limit=25)

    def test_missing_supplier_and_custody_adapters_defer_without_database_or_keys(self):
        with patch.dict(jobs.SUPPLIER_CLIENTS, {}, clear=True), \
                patch.object(jobs, "SUPPLIER_CLIENT_FACTORY", None), \
                patch.dict(jobs.FUNDING_ADAPTERS, {}, clear=True), \
                patch.object(jobs, "_db", side_effect=AssertionError("DB must not be used")), \
                patch.object(jobs, "_cipher", side_effect=AssertionError("key must not be read")):
            results = (
                jobs.suppliers_sync(self.context),
                jobs.suppliers_recover(self.context),
                jobs.usdt_funding_recover(self.context),
            )
        self.assertEqual([result["status"] for result in results],
                         ["deferred", "deferred", "deferred"])
        self.assertEqual([result["reason"] for result in results], [
            "supplier_transport_unavailable",
            "supplier_transport_unavailable",
            "custody_adapter_unavailable",
        ])

    def test_missing_payment_and_topup_adapters_defer_without_loading_payment_state(self):
        with patch.dict(jobs.PAYMENT_ADAPTERS, {}, clear=True), \
                patch.dict(jobs.EXTERNAL_ADAPTERS, {}, clear=True), \
                patch.dict(jobs.FUNDING_ADAPTERS, {}, clear=True), \
                patch.object(jobs, "_db", side_effect=AssertionError("DB must not be used")), \
                patch.object(jobs, "_cipher", side_effect=AssertionError("key must not be read")):
            results = (
                jobs.klikqris_reconcile(self.context),
                jobs.sakurupiah_reconcile(self.context),
                jobs.pakasir_reconcile(self.context),
                jobs.binance_reconcile(self.context),
                jobs.bsc_reconcile(self.context),
                jobs.klikqris_funding_reconcile(self.context),
                jobs.sakurupiah_funding_reconcile(self.context),
                jobs.klikqris_backfill_in_flight(self.context),
            )
        self.assertEqual([result["status"] for result in results], ["deferred"] * len(results))
        self.assertEqual({result["reason"] for result in results}, {"provider_adapter_unavailable"})

    def test_payment_cursor_is_qualified_and_exhaustion_does_not_rewind(self):
        class RecordingDatabase:
            def __init__(self):
                self.calls = []

            def rows(self, sql, params):
                self.calls.append((sql, params))
                return []

        database = RecordingDatabase()
        context = jobs.JobContext(
            datetime(2026, 11, 12, tzinfo=timezone.utc), limit=25, cursor=100)
        with patch.object(jobs, "_db", return_value=database):
            rows = jobs._payment_page("manual", context)
        self.assertEqual(rows, [])
        self.assertEqual(len(database.calls), 1)
        sql, params = database.calls[0]
        self.assertIn("p.id > %s ORDER BY p.id", sql)
        self.assertNotIn("ORDER BY id", sql)
        self.assertEqual(params[-2:], (100, 25))

    def test_caught_order_expiry_errors_make_the_run_partial(self):
        from types import SimpleNamespace

        database = SimpleNamespace(rows=lambda *args: [{"id": "order-1"}])
        lifecycle = SimpleNamespace(
            apply_pending_expiry=lambda _order_id: (_ for _ in ()).throw(RuntimeError()))
        with patch.object(jobs, "_db", return_value=database), \
                patch("lhie_candidate.checkout.lifecycle.OrderLifecycle", return_value=lifecycle):
            result = jobs.orders_expire_pending(self.context)
        self.assertEqual(result["status"], "partial")
        self.assertEqual(result["failed"], 1)

    def test_outbound_recovery_failures_open_the_component_alert(self):
        from types import SimpleNamespace

        service = SimpleNamespace(
            recover=lambda _limit: {"source_failures": 1},
            protocol=SimpleNamespace(ready_effects=lambda _limit: []))
        with patch("lhie_candidate.receipt.runtime.receipt_service", return_value=service), \
                patch.object(jobs, "scheduler_runtime") as alert:
            result = jobs.receipt_recover(self.context)
        self.assertEqual(result["status"], "partial")
        alert.assert_called_once_with(
            "outbound-recovery", "failed", "recovery_exit")


if __name__ == "__main__":
    unittest.main()
