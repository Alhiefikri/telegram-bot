"""Validate and summarize P04 raw benchmark attempts using only the standard library.

``summarize(samples)`` accepts an iterable of schema_version=1 dictionaries and
returns a JSON-serializable dictionary. It raises ValueError on malformed records
or duplicate identities (all DIMENSIONS plus warmup phase, batch and attempt).
The input is never mutated. Missing timestamp keys and explicit nulls
both mean unobserved; they never become zero-duration observations.

``cells`` is sorted by the full DIMENSIONS tuple. Each cell contains ``counts``
(measured attempts/outcomes/retries/batches and separately counted warmups),
``boundaries`` keyed by the ending timestamp in BOUNDARIES, and a navigation
``gate``. Each boundary separates ``all_observed`` from ``successful`` statistics:
observed/unknown counts, nearest-rank p50_ms/p95_ms/max_ms, per-batch p95 values,
and a deterministic batch_p95_range_ms. The range is descriptive uncertainty,
not a statistical confidence interval. Warmups never enter these statistics.
Failures with observed boundaries remain in all_observed, not successful.

Navigation evidence is exploratory, never production-p95 confidence or a
comparison pass. Incomplete attempts/batches, failures, missing boundaries,
near-floor timings or unstable batch p95 remain inconclusive.
Payment cells are descriptive only; planned coverage and completed independent
batches belong to the orchestration layer.

CLI: ``python tools/migration_benchmark/report.py INPUT [-o OUTPUT]`` reads
JSONL and writes JSON; either path can be ``-`` for stdin/stdout. Invalid input
exits nonzero without writing a partial report or echoing raw sample contents.
"""

import argparse
from collections import Counter, defaultdict
import json
import math
from pathlib import Path
import sys


DIMENSIONS = (
    "implementation", "profile", "transport", "dataset", "scenario", "users",
    "traffic", "temperature", "background",
)
NAVIGATION_SCENARIOS = (
    "home_catalog", "catalog_plans", "plan_detail", "back", "home", "quantity",
    "orders", "balance",
)
DIMENSION_VALUES = {
    "implementation": ("laravel", "django"),
    "profile": ("current", "normalized"),
    "transport": ("synthetic", "sandbox", "testing_bot"),
    "dataset": ("small", "large"),
    "scenario": NAVIGATION_SCENARIOS + ("checkout", "paid_delivery"),
    "users": (1, 5, 10),
    "traffic": ("distinct", "same_chat"),
    "temperature": ("cold", "warm"),
    "background": ("idle", "representative"),
}
BOUNDARIES = {
    "ingress_ack": ("ingress", "ingress_ack"),
    "pickup": ("interaction_queued", "pickup"),
    "screen_ack": ("ingress", "screen_ack"),
    "checkout_ready": ("ingress", "checkout_ready"),
    "paid_observed": ("provider_paid", "paid_observed"),
    "paid_committed": ("paid_observed", "paid_committed"),
    "receipt_queued": ("paid_committed", "receipt_queued"),
    "receipt_pickup": ("receipt_queued", "receipt_pickup"),
    "credential_ack": ("paid_observed", "credential_ack"),
    "group_complete": ("paid_observed", "group_complete"),
}
TIMESTAMPS = tuple(dict.fromkeys(name for pair in BOUNDARIES.values() for name in pair))
OUTCOMES = ("success", "error", "timeout", "unknown")
ERROR_CODES = (
    "timeout", "probe_error", "provider_error", "transport_error",
    "scenario_error", "boundary_unknown", "unexpected_response", "interrupted",
)
PROTOCOL = json.loads(Path(__file__).with_name("protocol.json").read_text())
FLOOR_MS = PROTOCOL["floor_ms"]
MAX_BATCH_SPREAD = PROTOCOL["unstable_batch_p95_relative_spread"]


def _counter(value, field):
    if type(value) is not int or value < 0:
        raise ValueError(f"{field} must be a nonnegative integer")


def _validate(sample):
    if not isinstance(sample, dict):
        raise ValueError("sample must be an object")
    required = {"schema_version", *DIMENSIONS, "batch", "attempt", "warmup",
                "outcome", "retries", "timestamps", "db_ms", "error_code"}
    if not required.issubset(sample):
        raise ValueError("sample is missing required fields")
    if type(sample["schema_version"]) is not int or sample["schema_version"] != 1:
        raise ValueError("unsupported schema_version")
    for dimension, allowed in DIMENSION_VALUES.items():
        value = sample[dimension]
        expected_type = int if dimension == "users" else str
        if type(value) is not expected_type or value not in allowed:
            raise ValueError(f"invalid {dimension}")
    for field in ("batch", "attempt", "retries"):
        _counter(sample[field], field)
    if type(sample["warmup"]) is not bool:
        raise ValueError("warmup must be boolean")
    if sample["outcome"] not in OUTCOMES:
        raise ValueError("invalid outcome")
    if sample["error_code"] is not None and sample["error_code"] not in ERROR_CODES:
        raise ValueError("invalid error_code")
    db_ms = sample["db_ms"]
    if db_ms is not None and (type(db_ms) not in (int, float)
                              or not math.isfinite(db_ms) or db_ms < 0):
        raise ValueError("db_ms must be finite and nonnegative or null")
    timestamps = sample["timestamps"]
    if not isinstance(timestamps, dict):
        raise ValueError("timestamps must be an object")
    if not set(timestamps).issubset(TIMESTAMPS):
        raise ValueError("unknown timestamp boundary")
    for value in timestamps.values():
        if value is not None and (type(value) is not int or value < 0):
            raise ValueError("timestamps must be nonnegative integer nanoseconds or null")
    for start, end in BOUNDARIES.values():
        if timestamps.get(start) is not None and timestamps.get(end) is not None:
            if timestamps[end] < timestamps[start]:
                raise ValueError(f"reversed boundary {start} to {end}")


def _duration(sample, boundary):
    if boundary == "db_ms":
        return None if sample["db_ms"] is None else sample["db_ms"] * 1_000_000
    start, end = BOUNDARIES[boundary]
    timestamps = sample["timestamps"]
    if timestamps.get(start) is None or timestamps.get(end) is None:
        return None
    return timestamps[end] - timestamps[start]


def _percentile(sorted_values, percent):
    return sorted_values[(len(sorted_values) * percent + 99) // 100 - 1]


def _distribution(samples, boundary):
    durations = []
    batches = defaultdict(list)
    for sample in samples:
        duration = _duration(sample, boundary)
        if duration is not None:
            durations.append(duration)
            batches[sample["batch"]].append(duration)
    durations.sort()
    batch_p95 = [
        {"batch": batch, "observed": len(values),
         "p95_ms": _percentile(sorted(values), 95) / 1_000_000}
        for batch, values in sorted(batches.items())
    ]
    p95_values = [batch["p95_ms"] for batch in batch_p95]
    return {
        "observed": len(durations),
        "unknown": len(samples) - len(durations),
        "p50_ms": _percentile(durations, 50) / 1_000_000 if durations else None,
        "p95_ms": _percentile(durations, 95) / 1_000_000 if durations else None,
        "max_ms": durations[-1] / 1_000_000 if durations else None,
        "batch_p95": batch_p95,
        "batch_p95_range_ms": [min(p95_values), max(p95_values)] if p95_values else None,
    }


def _gate(dimensions, measured, boundaries):
    screen = boundaries["screen_ack"]["successful"]
    result = {
        "status": "not_applicable",
        "reasons": [], "successful_screen_observations": screen["observed"],
        "successful_screen_batches": len(screen["batch_p95"]),
        "measurement_floor_ms": FLOOR_MS,
        "max_batch_p95_spread_fraction": MAX_BATCH_SPREAD,
        "batch_p95_spread_fraction": None,
    }
    if dimensions["scenario"] not in NAVIGATION_SCENARIOS:
        result["reasons"] = ["requires_navigation"]
        return result
    reasons = result["reasons"]
    if len(measured) < PROTOCOL["measured_batches"] * PROTOCOL["primary_samples_per_batch"]:
        reasons.append("incomplete_measured_attempts")
    if len({sample["batch"] for sample in measured}) < PROTOCOL["measured_batches"]:
        reasons.append("incomplete_measured_batches")
    if any(sample["outcome"] != "success" for sample in measured):
        reasons.append("non_success_attempts")
    if boundaries["screen_ack"]["all_observed"]["unknown"]:
        reasons.append("unknown_screen_boundaries")
    if screen["p50_ms"] is not None and (screen["p50_ms"] <= FLOOR_MS or screen["p95_ms"] <= FLOOR_MS):
        reasons.append("measurement_floor")
    if len(screen["batch_p95"]) >= 2:
        low, high = screen["batch_p95_range_ms"]
        if low == 0:
            if high > 0:
                reasons.append("unstable_batch_p95")
        else:
            result["batch_p95_spread_fraction"] = (high - low) / low
            if high > low * (1 + MAX_BATCH_SPREAD):
                reasons.append("unstable_batch_p95")
    result["status"] = "exploratory" if not reasons else "inconclusive"
    return result


def summarize(samples):
    """Return deterministic, dimension-isolated statistics; reject malformed data."""
    cells = defaultdict(list)
    identities = set()
    for index, sample in enumerate(samples, 1):
        try:
            _validate(sample)
        except ValueError as exc:
            raise ValueError(f"sample {index}: {exc}") from None
        dimensions = tuple(sample[name] for name in DIMENSIONS)
        identity = (*dimensions, sample["warmup"], sample["batch"], sample["attempt"])
        if identity in identities:
            raise ValueError(f"sample {index}: duplicate attempt identity")
        identities.add(identity)
        cells[dimensions].append(sample)
    summaries = []
    for key, attempts in sorted(cells.items()):
        dimensions = dict(zip(DIMENSIONS, key))
        measured = [sample for sample in attempts if not sample["warmup"]]
        warmups = [sample for sample in attempts if sample["warmup"]]
        successful = [sample for sample in measured if sample["outcome"] == "success"]
        boundaries = {
            name: {
                "start": start, "end": end,
                "all_observed": _distribution(measured, name),
                "successful": _distribution(successful, name),
            }
            for name, (start, end) in BOUNDARIES.items()
        }
        counts = {
            "attempts": len(attempts), "measured": len(measured),
            "warmup": len(warmups),
            "outcomes": {outcome: sum(s["outcome"] == outcome for s in measured) for outcome in OUTCOMES},
            "warmup_outcomes": {outcome: sum(s["outcome"] == outcome for s in warmups) for outcome in OUTCOMES},
            "retries": sum(sample["retries"] for sample in measured),
            "warmup_retries": sum(sample["retries"] for sample in warmups),
            "batches": len({sample["batch"] for sample in measured}),
            "warmup_batches": len({sample["batch"] for sample in warmups}),
        }
        errors = Counter(sample["error_code"] for sample in measured if sample["error_code"] is not None)
        summaries.append({
            "dimensions": dimensions, "counts": counts,
            "error_codes": dict(sorted(errors.items())),
            "database": {"all_observed": _distribution(measured, "db_ms"),
                         "successful": _distribution(successful, "db_ms")},
            "boundaries": boundaries, "gate": _gate(dimensions, measured, boundaries),
        })
    return {
        "schema_version": 1,
        "method": {
            "percentile": "nearest_rank", "time_unit": "ms",
            "uncertainty": "deterministic_per_batch_p95_range_not_statistical_ci",
            "evidence_level": "exploratory_not_production_p95_confidence",
            "protocol_schema_version": PROTOCOL["schema_version"],
            "warmup": "excluded_from_measured_statistics_counted_separately",
        },
        "attempts": len(identities),
        "cells": summaries,
        "comparison": {"status": "not_evaluated", "reason": "candidate_absent"
                       if not any(key[0] == "django" for key in cells) else "paired_comparison_required"},
    }


def _read_jsonl(stream):
    for line_number, line in enumerate(stream, 1):
        if not line.strip():
            raise ValueError(f"line {line_number}: empty JSONL record")
        try:
            yield json.loads(line)
        except (json.JSONDecodeError, ValueError):
            raise ValueError(f"line {line_number}: invalid JSON") from None


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__.split("\n", 1)[0])
    parser.add_argument("input", help="raw JSONL file or - for stdin")
    parser.add_argument("-o", "--output", default="-", help="JSON report file or - for stdout")
    args = parser.parse_args(argv)
    try:
        if args.input == "-":
            report = summarize(_read_jsonl(sys.stdin))
        else:
            with Path(args.input).open(encoding="utf-8") as stream:
                report = summarize(_read_jsonl(stream))
        serialized = json.dumps(report, indent=2, sort_keys=True, allow_nan=False) + "\n"
        if args.output == "-":
            sys.stdout.write(serialized)
        else:
            Path(args.output).write_text(serialized, encoding="utf-8")
    except (OSError, ValueError) as exc:
        parser.exit(2, f"benchmark report: {exc}\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
