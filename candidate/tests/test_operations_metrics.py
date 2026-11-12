"""Metrics are bounded, private and unable to participate in money writes."""
import json
import tempfile
from pathlib import Path
import unittest

from lhie_candidate.operations.metrics import BalanceMetrics, OperationMetrics, metrics_snapshot


class OperationMetricTests(unittest.TestCase):
    def test_process_metrics_are_bounded_and_written_private(self):
        metrics = OperationMetrics("orders:expire-pending", maximum=8)
        metrics.record("runs")
        metrics.record("success")
        metrics.record("job", 1.25)
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "metrics.json"
            metrics.write(target)
            self.assertEqual(target.stat().st_mode & 0o777, 0o600)
            value = json.loads(target.read_text())
        self.assertEqual(value["counters"]["runs"], 1)
        self.assertEqual(value["durations"]["job"]["max_ms"], 1250.0)
        self.assertEqual(metrics_snapshot({"count": 1, "state": "pending"}),
                         {"count": 1, "state": "pending"})

    def test_invalid_metric_names_values_and_bounds_are_rejected(self):
        metrics = OperationMetrics("test", maximum=8)
        with self.assertRaises(ValueError):
            metrics.record("secret")
        with self.assertRaises(ValueError):
            metrics.record("job", 3601)
        with self.assertRaises(ValueError):
            OperationMetrics("test", maximum=7)
        with self.assertRaises(ValueError):
            BalanceMetrics().report(0)
        with self.assertRaises(ValueError):
            BalanceMetrics().prune_metric_events(30, chunk=5001)


if __name__ == "__main__":
    unittest.main()
