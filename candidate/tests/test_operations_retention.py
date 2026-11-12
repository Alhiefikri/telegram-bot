"""Retention bounds, resume isolation and non-destructive report admission."""
import base64
import json
import unittest
from unittest.mock import patch

from lhie_candidate.operations import retention


class RetentionContractTests(unittest.TestCase):
    def test_cleanup_rejects_unbounded_or_invalid_cursor_before_database_access(self):
        for kwargs in (
            {"days": 0},
            {"chunk": 5001},
            {"max_batches": 21},
            {"after": -1},
            {"after": "not-an-id"},
        ):
            with self.subTest(kwargs=kwargs), patch.object(retention, "_cursor") as cursor:
                with self.assertRaises(ValueError):
                    retention.cleanup_retained_data(**kwargs)
                cursor.assert_not_called()

    def test_report_requires_a_numeric_offset_and_known_family(self):
        for value in ("2026-11-12T00:00:00", "not-a-date"):
            with self.subTest(value=value), self.assertRaises(ValueError):
                retention._parse_as_of(value)
        with self.assertRaises(ValueError):
            retention.notification_retention_report(
                "unknown", as_of="2026-11-12T00:00:00+07:00", horizon=90)

    def test_resume_manifest_cannot_change_family_or_window(self):
        manifest = retention._manifest(
            "effects", retention.POLICY, "2026-11-12T00:00:00+07:00",
            retention.JAKARTA_TZ, 90, None, None)
        token = base64.b64encode(json.dumps(manifest).encode()).decode()
        with patch("lhie_candidate.operations.retention.transaction.atomic") as atomic:
            with self.assertRaises(ValueError):
                retention.notification_retention_report(
                    "broadcasts", as_of="2026-11-12T00:00:00+07:00", horizon=90,
                    resume=token)
        atomic.assert_not_called()

    def test_resume_manifest_rejects_trailing_range_conflicts(self):
        manifest = retention._manifest(
            "effects", retention.POLICY, "2026-11-12T00:00:00+07:00",
            retention.JAKARTA_TZ, 90, "5", "10")
        token = base64.b64encode(json.dumps(manifest).encode()).decode()
        with patch("lhie_candidate.operations.retention.transaction.atomic") as atomic:
            with self.assertRaises(ValueError):
                retention.notification_retention_report(
                    "effects", as_of="2026-11-12T00:00:00+07:00", horizon=90,
                    after="7", resume=token)
        atomic.assert_not_called()

    def test_retention_report_caps_and_rejects_invalid_inputs_before_database_access(self):
        invalid = (
            {"horizon": 365},
            {"page": 101},
            {"roots": 1001},
            {"dependencies": 1},
            {"per_root": 1},
            {"seconds": 11},
            {"details": 21},
            {"timezone": "UTC"},
        )
        for kwargs in invalid:
            arguments = {"horizon": 90}
            arguments.update(kwargs)
            with self.subTest(kwargs=kwargs), patch.object(retention, "_cursor") as cursor:
                with self.assertRaises(ValueError):
                    retention.notification_retention_report(
                        "effects", as_of="2026-11-12T00:00:00+07:00", **arguments)
                cursor.assert_not_called()

    def test_root_budget_reports_a_resumable_continuation(self):
        from contextlib import nullcontext
        import base64
        from uuid import UUID

        identifier = "00000000-0000-0000-0000-000000000001"

        class Cursor:
            def __init__(self):
                self.description = ()
                self.result = []
                self.sql = []

            def __enter__(self):
                return self

            def __exit__(self, *_args):
                return False

            def execute(self, sql, params=()):
                self.sql.append((sql, params))
                if sql.startswith("SELECT * FROM public.telegram_outbound_effects"):
                    self.description = (("id",), ("state",))
                    self.result = [(identifier, "sent")]
                elif sql.startswith("SELECT 1 FROM public.telegram_outbound_effects"):
                    self.result = [(1,)]
                else:
                    self.result = []

            def fetchall(self):
                rows, self.result = self.result, []
                return rows

            def fetchone(self):
                rows, self.result = self.result, []
                return rows[0] if rows else None

        cursor = Cursor()
        with patch("lhie_candidate.operations.retention.transaction.atomic",
                   return_value=nullcontext()), \
                patch.object(retention, "_cursor", return_value=cursor):
            report = retention.notification_retention_report(
                "effects", as_of="2026-11-12T00:00:00+07:00", horizon=90,
                page=1, roots=1, dependencies=10, per_root=2, seconds=10,
                details=1, operator_artifact=True)
        self.assertEqual(report["scanned_roots"], 1)
        self.assertTrue(report["truncated"])
        self.assertEqual(report["next_cursor"], identifier)
        self.assertEqual(report["operator_artifact"]["after"], identifier)
        decoded = json.loads(base64.b64decode(report["resume"]).decode())
        self.assertEqual(decoded["after"], str(UUID(identifier)))
        self.assertTrue(any("ORDER BY id LIMIT 1" in sql for sql, _ in cursor.sql))


if __name__ == "__main__":
    unittest.main()
