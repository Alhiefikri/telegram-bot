import copy
import importlib.util
import io
import json
from pathlib import Path
import random
import tempfile
import unittest
from unittest.mock import patch


spec = importlib.util.spec_from_file_location(
    "benchmark_report", Path(__file__).resolve().parents[2] / "tools/migration_benchmark/report.py"
)
benchmark = importlib.util.module_from_spec(spec)
spec.loader.exec_module(benchmark)


def sample(attempt=1, batch=1, duration_ms=10, **changes):
    timestamps = dict.fromkeys(benchmark.TIMESTAMPS)
    timestamps.update(ingress=1_000_000, screen_ack=1_000_000 + int(duration_ms * 1_000_000))
    result = {
        "schema_version": 1, "implementation": "laravel", "profile": "current",
        "transport": "synthetic", "dataset": "small", "scenario": "home_catalog",
        "users": 1, "traffic": "distinct", "temperature": "warm", "background": "idle",
        "batch": batch, "attempt": attempt, "warmup": False, "outcome": "success",
        "retries": 0, "timestamps": timestamps, "db_ms": None, "error_code": None,
    }
    result.update(changes)
    return result


def enough_samples(batch_durations=(10, 10, 10)):
    return [sample(attempt=i + 1, batch=i % 3 + 1, duration_ms=batch_durations[i % 3])
            for i in range(200)]


class BenchmarkReportTest(unittest.TestCase):
    def cell(self, samples):
        return benchmark.summarize(samples)["cells"][0]

    def distribution(self, samples, boundary="screen_ack", population="all_observed"):
        return self.cell(samples)["boundaries"][boundary][population]

    def test_boundaries_use_observed_monotonic_nanoseconds(self):
        row = sample(timestamps={
            "ingress": 100_000_000, "ingress_ack": 101_000_000,
            "interaction_queued": 102_000_000,
            "pickup": 103_000_000, "screen_ack": 107_000_000,
            "checkout_ready": 111_000_000, "provider_paid": 120_000_000,
            "paid_observed": 133_000_000, "paid_committed": 150_000_000,
            "receipt_queued": 151_000_000, "receipt_pickup": 170_000_000,
            "credential_ack": 156_000_000, "group_complete": 162_000_000,
        })
        cell = self.cell([row])
        expected = {"ingress_ack": 1, "pickup": 1, "screen_ack": 7, "checkout_ready": 11,
                    "paid_observed": 13, "paid_committed": 17, "receipt_queued": 1,
                    "receipt_pickup": 19, "credential_ack": 23, "group_complete": 29}
        for name, milliseconds in expected.items():
            with self.subTest(boundary=name):
                boundary = cell["boundaries"][name]
                self.assertEqual((boundary["start"], boundary["end"]), benchmark.BOUNDARIES[name])
                for population in ("all_observed", "successful"):
                    stats = boundary[population]
                    self.assertEqual((stats["observed"], stats["unknown"]), (1, 0))
                    self.assertEqual((stats["p50_ms"], stats["p95_ms"], stats["max_ms"]),
                                     (milliseconds,) * 3)

    def test_missing_and_null_timestamps_are_unknown_not_zero(self):
        rows = [sample(1, timestamps={}), sample(2, timestamps={"ingress": 0, "screen_ack": None}),
                sample(3, timestamps={"screen_ack": 1_000_000}),
                sample(4, timestamps={"ingress": 0, "screen_ack": 0})]
        result = self.distribution(rows)
        self.assertEqual((result["observed"], result["unknown"]), (1, 3))
        self.assertEqual(result["p50_ms"], 0)
        unknown = self.distribution(rows, "paid_observed")
        self.assertEqual((unknown["observed"], unknown["unknown"]), (0, 4))
        self.assertIsNone(unknown["p95_ms"])
        self.assertIsNone(unknown["max_ms"])
        self.assertIsNone(unknown["batch_p95_range_ms"])
        self.assertEqual(unknown["batch_p95"], [])

    def test_failures_timeouts_and_unknowns_are_counted_and_keep_observations(self):
        rows = [sample(1, duration_ms=1),
                sample(2, duration_ms=100, outcome="error", retries=2, error_code="probe_error"),
                sample(3, duration_ms=500, outcome="timeout", retries=1, error_code="timeout"),
                sample(4, timestamps={}, outcome="unknown", error_code="boundary_unknown")]
        cell = self.cell(rows)
        self.assertEqual(cell["counts"]["outcomes"], dict(success=1, error=1, timeout=1, unknown=1))
        self.assertEqual(cell["counts"]["retries"], 3)
        self.assertEqual(cell["counts"]["measured"], 4)
        self.assertEqual(cell["error_codes"], dict(probe_error=1, timeout=1, boundary_unknown=1))
        stats = cell["boundaries"]["screen_ack"]
        self.assertEqual((stats["all_observed"]["observed"], stats["all_observed"]["unknown"]), (3, 1))
        self.assertEqual(stats["all_observed"]["p50_ms"], 100)
        self.assertEqual(stats["all_observed"]["p95_ms"], 500)
        self.assertEqual(stats["successful"]["p95_ms"], 1)
        self.assertEqual((stats["successful"]["observed"], stats["successful"]["unknown"]), (1, 0))

    def test_warmup_is_counted_separately_never_measured(self):
        rows = [sample(1, duration_ms=2), sample(2, batch=5, duration_ms=999, warmup=True),
                sample(3, batch=6, outcome="timeout", warmup=True, retries=3, error_code="timeout")]
        cell = self.cell(rows)
        counts = cell["counts"]
        self.assertEqual((counts["attempts"], counts["measured"], counts["warmup"]), (3, 1, 2))
        self.assertEqual((counts["batches"], counts["warmup_batches"]), (1, 2))
        self.assertEqual((counts["retries"], counts["warmup_retries"]), (0, 3))
        self.assertEqual(counts["warmup_outcomes"], dict(success=1, error=0, timeout=1, unknown=0))
        self.assertEqual(cell["boundaries"]["screen_ack"]["all_observed"]["max_ms"], 2)
        self.assertEqual(cell["error_codes"], {})
        warmup_only = self.cell(rows[1:])
        self.assertEqual(warmup_only["counts"]["measured"], 0)
        self.assertEqual(warmup_only["boundaries"]["screen_ack"]["all_observed"]["unknown"], 0)

    def test_database_timing_preserves_failed_queries_and_unknowns(self):
        cell = self.cell([sample(1, db_ms=0.5), sample(2, db_ms=None),
                          sample(3, db_ms=900, outcome="error", error_code="probe_error"),
                          sample(4, db_ms=9999, warmup=True)])
        all_queries = cell["database"]["all_observed"]
        self.assertEqual((all_queries["observed"], all_queries["unknown"]), (2, 1))
        self.assertEqual((all_queries["p50_ms"], all_queries["p95_ms"]), (0.5, 900))
        self.assertEqual(cell["database"]["successful"]["max_ms"], 0.5)

    def test_nearest_rank_does_not_trim_outliers_or_interpolate(self):
        rows = [sample(i + 1, duration_ms=i + 1) for i in range(19)]
        rows.append(sample(20, duration_ms=1_000_000))
        stats = self.distribution(rows)
        self.assertEqual((stats["p50_ms"], stats["p95_ms"], stats["max_ms"]), (10, 19, 1_000_000))
        self.assertEqual(stats["observed"], 20)

    def test_full_dimensions_never_mix_and_report_is_deterministic(self):
        rows = enough_samples()
        variants = {"profile": "normalized", "dataset": "large", "scenario": "balance",
                    "users": 5, "traffic": "same_chat", "temperature": "cold", "background": "representative"}
        rows += [sample(**{name: value}) for name, value in variants.items()]
        original = copy.deepcopy(rows)
        expected = benchmark.summarize(rows)
        self.assertEqual(rows, original)
        random.Random(83).shuffle(rows)
        actual = benchmark.summarize(iter(rows))
        self.assertEqual(actual, expected)
        self.assertEqual(json.dumps(actual, sort_keys=True, allow_nan=False),
                         json.dumps(expected, sort_keys=True, allow_nan=False))
        self.assertEqual(len(actual["cells"]), 8)
        self.assertEqual(sum(cell["counts"]["attempts"] for cell in actual["cells"]), 207)
        self.assertEqual(actual["comparison"], {"status": "not_evaluated", "reason": "candidate_absent"})
        self.assertEqual(actual["method"]["uncertainty"],
                         "deterministic_per_batch_p95_range_not_statistical_ci")

    def test_candidate_and_live_evidence_are_never_pooled_with_fixture_baseline(self):
        rows = [sample(), sample(implementation="django", duration_ms=2),
                sample(transport="sandbox", duration_ms=100),
                sample(transport="testing_bot", duration_ms=200)]
        result = benchmark.summarize(rows)
        distributions = {(cell["dimensions"]["implementation"], cell["dimensions"]["transport"]):
                         cell["boundaries"]["screen_ack"]["all_observed"]["p95_ms"]
                         for cell in result["cells"]}
        self.assertEqual(distributions, {("laravel", "synthetic"): 10, ("django", "synthetic"): 2,
                                        ("laravel", "sandbox"): 100, ("laravel", "testing_bot"): 200})
        self.assertEqual(result["comparison"]["status"], "not_evaluated")

    def test_exploratory_samples_never_hide_failures_or_missing_boundaries(self):
        rows = [sample(i + 1, batch=i // 25 + 1) for i in range(50)]
        self.assertEqual(self.cell(rows)["gate"]["status"], "exploratory")
        self.assertEqual(self.cell(rows[:-1])["gate"]["status"], "inconclusive")
        unknown = rows + [sample(51, batch=3, timestamps={})]
        self.assertIn("unknown_screen_boundaries", self.cell(unknown)["gate"]["reasons"])
        for outcome in ("error", "timeout", "unknown"):
            with self.subTest(outcome=outcome):
                failed = rows + [sample(51, batch=3, outcome=outcome)]
                failed_gate = self.cell(failed)["gate"]
                self.assertEqual(failed_gate["status"], "inconclusive")
                self.assertIn("non_success_attempts", failed_gate["reasons"])

    def test_gate_excludes_payment_cells_and_keeps_cold_separate(self):
        self.assertEqual(self.cell([dict(row, temperature="cold") for row in enough_samples()])["gate"]["status"], "exploratory")
        for changes in ({"scenario": "checkout"}, {"scenario": "paid_delivery"}):
            with self.subTest(changes=changes):
                rows = [dict(row, **changes) for row in enough_samples()]
                gate = self.cell(rows)["gate"]
                self.assertEqual(gate["status"], "not_applicable")
        warmups = [dict(row, warmup=True) for row in enough_samples()]
        self.assertEqual(self.cell(warmups)["gate"]["successful_screen_observations"], 0)

    def test_floor_applies_to_p50_even_with_slow_tail_and_to_exact_floor(self):
        for duration in (0, 0.05, 0.1):
            with self.subTest(duration=duration):
                gate = self.cell(enough_samples((duration,) * 3))["gate"]
                self.assertEqual(gate["status"], "inconclusive")
                self.assertIn("measurement_floor", gate["reasons"])
        rows = enough_samples((0.05, 0.05, 10))
        self.assertIn("measurement_floor", self.cell(rows)["gate"]["reasons"])

    def test_deterministic_batch_range_and_strict_twenty_percent_spread(self):
        rows = enough_samples((10, 12, 10))
        cell = self.cell(rows)
        stats = cell["boundaries"]["screen_ack"]["successful"]
        self.assertEqual(stats["batch_p95_range_ms"], [10, 12])
        self.assertEqual(stats["batch_p95"], [
            {"batch": 1, "observed": 67, "p95_ms": 10},
            {"batch": 2, "observed": 67, "p95_ms": 12},
            {"batch": 3, "observed": 66, "p95_ms": 10},
        ])
        self.assertEqual(cell["gate"]["status"], "exploratory")
        unstable = self.cell(enough_samples((10, 12.001, 10)))["gate"]
        self.assertEqual(unstable["status"], "inconclusive")
        self.assertIn("unstable_batch_p95", unstable["reasons"])
        self.assertGreater(unstable["batch_p95_spread_fraction"], 0.2)

    def test_duplicate_identity_is_rejected_within_each_warmup_phase(self):
        for second in (sample(), sample(duration_ms=100), sample(outcome="error")):
            with self.subTest(second=second), self.assertRaisesRegex(ValueError, "duplicate attempt identity"):
                benchmark.summarize([sample(), second])
        self.assertEqual(benchmark.summarize([sample(), sample(batch=2)])["attempts"], 2)
        self.assertEqual(benchmark.summarize([sample(), sample(warmup=True)])["attempts"], 2)
        with self.assertRaisesRegex(ValueError, "duplicate attempt identity"):
            benchmark.summarize([sample(warmup=True), sample(warmup=True)])

    def test_invalid_timestamps_and_every_reversed_boundary_are_rejected(self):
        for value in (-1, 1.5, "100", True, float("nan")):
            with self.subTest(value=value), self.assertRaises(ValueError):
                benchmark.summarize([sample(timestamps={"ingress": value})])
        for start, end in benchmark.BOUNDARIES.values():
            with self.subTest(start=start, end=end), self.assertRaisesRegex(ValueError, "reversed boundary"):
                benchmark.summarize([sample(timestamps={start: 2, end: 1})])
        with self.assertRaises(ValueError):
            benchmark.summarize([sample(timestamps={"payload": 1})])
        with self.assertRaises(ValueError):
            benchmark.summarize([sample(timestamps=[])])

    def test_invalid_dimensions_outcomes_counters_and_envelopes_fail_closed(self):
        invalid = {"schema_version": [0, 2, True], "implementation": ["candidate"],
                   "profile": ["production"], "transport": ["live"], "dataset": ["medium"],
                   "scenario": ["navigation"], "users": [True, 0, 2, "1"], "traffic": ["mixed"],
                   "temperature": ["hot"], "background": ["unknown"], "outcome": ["ok", None],
                   "batch": [-1, True, 1.5], "attempt": [-1, False, "1"], "retries": [-1, True],
                   "warmup": [0, "false"], "error_code": ["raw-secret-error", 1],
                   "db_ms": [-1, True, float("nan"), float("inf"), "1"]}
        for field, values in invalid.items():
            for value in values:
                with self.subTest(field=field, value=value), self.assertRaises(ValueError):
                    benchmark.summarize([sample(**{field: value})])
        for field in sample():
            missing = sample()
            del missing[field]
            with self.subTest(missing=field), self.assertRaises(ValueError):
                benchmark.summarize([missing])
        with self.assertRaises(ValueError):
            benchmark.summarize([None])

    def test_empty_input_and_safe_extra_fields(self):
        self.assertEqual(benchmark.summarize([])["cells"], [])
        self.assertEqual(benchmark.summarize([])["attempts"], 0)
        report = benchmark.summarize([sample(extra_safe_counter=7)])
        self.assertNotIn("extra_safe_counter", json.dumps(report))

    def test_cli_reads_jsonl_and_writes_json(self):
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / "samples.jsonl"
            destination = Path(directory) / "report.json"
            source.write_text(json.dumps(sample()) + "\n", encoding="utf-8")
            self.assertEqual(benchmark.main([str(source), "-o", str(destination)]), 0)
            self.assertEqual(json.loads(destination.read_text()), benchmark.summarize([sample()]))
        stdout = io.StringIO()
        with patch("sys.stdin", io.StringIO(json.dumps(sample()) + "\n")), patch("sys.stdout", stdout):
            self.assertEqual(benchmark.main(["-"]), 0)
        self.assertEqual(json.loads(stdout.getvalue())["attempts"], 1)

    def test_cli_rejects_bad_json_duplicates_and_blank_records_without_partial_output(self):
        for source in ('{"secret-payload": broken}\n', json.dumps(sample()) + "\n\n",
                       (json.dumps(sample()) + "\n") * 2):
            stdout, stderr = io.StringIO(), io.StringIO()
            with self.subTest(source=source), patch("sys.stdin", io.StringIO(source)), \
                    patch("sys.stdout", stdout), patch("sys.stderr", stderr):
                with self.assertRaises(SystemExit) as error:
                    benchmark.main(["-"])
                self.assertEqual(error.exception.code, 2)
                self.assertEqual(stdout.getvalue(), "")
                self.assertNotIn("secret-payload", stderr.getvalue())


if __name__ == "__main__":
    unittest.main()
