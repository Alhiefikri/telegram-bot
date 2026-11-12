"""Health stays cheap, bounded and secret-free; it never claims capacity."""
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from lhie_candidate.operations import health
from lhie_candidate.workers.metrics import WorkerMetrics


class HealthTests(unittest.TestCase):
    def test_liveness_does_no_dependency_or_secret_work(self):
        with patch("lhie_candidate.readiness.verify_dependencies") as verify:
            self.assertEqual(health.liveness(), {"status": "ok"})
        verify.assert_not_called()

    def test_readiness_fails_closed_without_leaking_the_exception(self):
        with patch("lhie_candidate.readiness.verify_dependencies",
                   side_effect=ValueError("sensitive-sentinel")):
            self.assertEqual(health.readiness(), {"status": "not_ready"})
        with patch("lhie_candidate.readiness.verify_dependencies") as verify:
            self.assertEqual(health.readiness(), {"status": "ready"})
        verify.assert_called_once_with()

    def test_report_has_no_capacity_claim_and_does_not_decrypt_keys(self):
        with patch("lhie_candidate.operations.health.OperationStore.snapshot", return_value={}), \
                patch("lhie_candidate.operations.health.OperationStore.stale_leases", return_value=[]), \
                patch("lhie_candidate.runtime.application_key",
                      side_effect=AssertionError("key must not be read")) as key:
            report = health.health_report(db=False, queue_schema=True)
        self.assertEqual(report["status"], "ok")
        self.assertNotIn("capacity", report)
        self.assertNotIn("throughput", report)
        key.assert_not_called()

    def test_metrics_reader_rejects_payload_bearing_files_and_symlinks(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            valid = WorkerMetrics("interaction").snapshot()
            (root / "interaction.json").write_text(json.dumps(valid))
            invalid = WorkerMetrics("payment").snapshot()
            invalid["durations"]["handle"]["secret"] = "sentinel"
            (root / "payment.json").write_text(json.dumps(invalid))
            (root / "candidate:payments.json").symlink_to(root / "interaction.json")
            snapshot = health._metrics_snapshot(
                root, ["interaction", "payment", "candidate:payments"])
        self.assertEqual(snapshot["files"], 1)
        self.assertEqual(snapshot["invalid"], 2)
        self.assertEqual(set(snapshot["queues"]), {"interaction"})
        self.assertNotIn("secret", json.dumps(snapshot))

    def test_heartbeat_is_stat_only_and_bounded(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "heartbeat"
            path.write_text("never used as content\n")
            os.chmod(path, 0o600)
            now = datetime.fromtimestamp(path.stat().st_mtime + 10, timezone.utc)
            self.assertEqual(health._heartbeat(path, now, 60)["state"], "ok")
            self.assertEqual(health._heartbeat(path, now, 5)["state"], "stale")
            self.assertEqual(health._heartbeat(Path(directory) / "missing", now, 60)["state"], "missing")
            with self.assertRaises(ValueError):
                health._heartbeat(path, now, 0)

    def test_queue_depth_is_bounded_to_the_declared_topology(self):
        with patch("lhie_candidate.operations.health._redis_bounded", return_value=[0, 2, 0, 0, 1, 0, 0]) as redis:
            report = health.queue_depth()
        self.assertEqual(report["status"], "ok")
        self.assertEqual(report["depth"]["candidate:payments"], 2)
        commands = redis.call_args.args[0]
        self.assertEqual(len(commands), 7)
        self.assertTrue(all(command[0] == "LLEN" for command in commands))

    def test_missing_scheduler_metadata_fails_the_operational_report(self):
        with patch("lhie_candidate.operations.health.OperationStore.snapshot",
                   side_effect=OSError("sensitive")):
            report = health.health_report(db=False, queue_schema=False)
        self.assertEqual(report["status"], "failed")
        self.assertEqual(report["checks"]["scheduler_state"], {"status": "unavailable"})

    def test_release_summary_includes_partial_failure_counts(self):
        with patch("lhie_candidate.operations.health._queue_topology", return_value=[]), \
                patch("lhie_candidate.operations.health._db_rows",
                      return_value=[{"outcome": "success", "aggregate": 3},
                                    {"outcome": "failure", "aggregate": 1},
                                    {"outcome": "partial", "aggregate": 2}]):
            report = health.release_health_summary()
        self.assertEqual(report["status"], "failed")
        self.assertEqual(report["failed_jobs"]["last_24h"], 3)

    def test_failed_job_threshold_scans_only_the_bounded_failure_index_prefix(self):
        with patch("lhie_candidate.operations.health._db_one",
                   return_value={"aggregate": 2}) as query:
            report = health.check_failed_jobs(1, baseline=50)
        self.assertEqual(report, {
            "status": "failed", "count": 2, "count_capped": True, "max": 1,
            "scope": "failures after baseline"})
        self.assertIn("LIMIT %s", query.call_args.args[0])
        self.assertIn("'partial','metrics_failed'", query.call_args.args[0])
        self.assertEqual(query.call_args.args[1], (50, 2))
        with patch("lhie_candidate.operations.health._db_one") as query:
            with self.assertRaises(ValueError):
                health.check_failed_jobs(10001)
        query.assert_not_called()
    def test_terminal_report_errors_are_safe(self):
        with patch("lhie_candidate.operations.health._redis_bounded", return_value=None):
            self.assertEqual(health.queue_depth()["status"], "failed")


if __name__ == "__main__":
    unittest.main()
