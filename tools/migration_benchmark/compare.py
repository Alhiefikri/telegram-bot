#!/usr/bin/env python3
"""P11 paired PHP<->Django comparison. Deterministic, per-case, never pooled.

Inputs are two completed runner directories that both used ``--protocol p11``:
one ``laravel`` run and one ``django`` run. The module refuses to pair runs
whose protocol, dataset contract, dataset logical checksums or limits differ.

Each navigation case is judged independently from successful ``screen_ack``
p50/p95; scenario or user counts are never averaged. Declared candidate gaps
(checkout and paid delivery in the P11-fix revision) stay ``not_implemented``;
missing or non-exploratory evidence is ``inconclusive``; a case fails only when a
quality-qualified Django distribution is slower than 70% of its PHP pair.

Payment-stage numbers come from retained PHP-only diagnostic smoke evidence.
The budget proposal is marked unratified and is never derived from Django
payment results, because that implementation has no payment surface yet.
"""

import argparse
import gzip
import json
import math
from pathlib import Path
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
DIMENSIONS = ("dataset", "profile", "scenario", "users", "traffic", "temperature", "background")
TARGET = {"p50_ratio_max": 0.70, "p95_ratio_max": 0.70}
PAYMENT_BOUNDARIES = ("checkout_ready", "paid_observed", "paid_committed", "receipt_queued",
                      "receipt_pickup", "credential_ack", "group_complete")
RETAINED_PAYMENT_EVIDENCE = (ROOT / "docs/migration/python-django/p04/evidence/"
                             "lightweight-smoke-samples.jsonl.gz")


class Mismatch(Exception):
    pass


def load(directory):
    directory = Path(directory)
    try:
        run = json.loads((directory / "run.json").read_text())
        report = json.loads((directory / "report.json").read_text())
    except (OSError, ValueError):
        raise Mismatch("unreadable run directory") from None
    return run, report


def index(report):
    cells = {}
    for cell in report.get("cells", []):
        dimensions = cell.get("dimensions") or {}
        key = tuple(dimensions.get(name) for name in DIMENSIONS)
        if any(value is None for value in key):
            raise Mismatch("cell dimensions incomplete")
        if key in cells:
            raise Mismatch("duplicate cell identity")
        cells[key] = cell
    return cells


def pairing_identity(php_run, django_run):
    for label, run in (("php", php_run), ("django", django_run)):
        if run.get("schema_version") != 2 or run.get("protocol_file") != "p11":
            raise Mismatch(label + " run is not a p11 protocol run")
        if run.get("protocol", {}).get("schema_version") != 5:
            raise Mismatch(label + " run protocol schema is not 5")
        if run.get("smoke") is not False:
            raise Mismatch(label + " run is a smoke, not a paired measurement")
        if run.get("status") not in ("complete", "complete_with_unknowns"):
            raise Mismatch(label + " run did not complete")
    if php_run.get("implementation") != "laravel" or django_run.get("implementation") != "django":
        raise Mismatch("implementation roles are reversed")
    if php_run["protocol"] != django_run["protocol"]:
        raise Mismatch("protocol documents differ")
    if php_run.get("dataset_contract") != django_run.get("dataset_contract"):
        raise Mismatch("dataset contracts differ")
    if php_run.get("limits") != django_run.get("limits"):
        raise Mismatch("excluded-claim limits differ")
    php_hashes = php_run.get("dataset_logical_sha256") or {}
    django_hashes = django_run.get("dataset_logical_sha256") or {}
    if not php_hashes or php_hashes != django_hashes:
        raise Mismatch("dataset logical checksums differ")
    for key in ("max_connections", "shared_buffers", "synchronous_commit", "fsync",
                "full_page_writes", "TimeZone"):
        php_settings = (php_run.get("postgres_settings") or [{}])[-1].get(key)
        django_settings = (django_run.get("postgres_settings") or [{}])[-1].get(key)
        if php_settings != django_settings:
            raise Mismatch("postgres setting differs: " + key)
    php_sources = php_run.get("source_sha256") or {}
    django_sources = django_run.get("source_sha256") or {}
    source_delta = sorted(key for key in set(php_sources) | set(django_sources)
                          if php_sources.get(key) != django_sources.get(key))
    measurement_files = {"tools/migration_benchmark/probe.php",
                         "tools/migration_benchmark/protocol_p11.json",
                         "supervisord.conf"}
    measurement_delta = [key for key in source_delta
                         if key.startswith("src/") or key.startswith("tools/migration_harness/")
                         or key in measurement_files]
    if measurement_delta:
        raise Mismatch("php measurement-path sources differ: " + ", ".join(measurement_delta[:3]))
    return {
        "protocol_sha256": php_run.get("protocol_sha256"),
        "dataset_logical_sha256": php_hashes,
        "revision": {"php": php_run.get("revision"), "django": django_run.get("revision")},
        "source_sha256_equal": not source_delta,
        "source_delta": source_delta,
        "php_measurement_path_delta": measurement_delta,
        "source_reuse_basis": ("no source delta" if not source_delta else
                                "only non-PHP-measurement tooling differs; probe.php, protocol and src are identical"),
        "host": php_run.get("host"),
    }


def screen(cell):
    return ((cell or {}).get("boundaries") or {}).get("screen_ack", {}).get("successful", {})


def database(cell):
    return ((cell or {}).get("database") or {}).get("successful", {})


def ratio(value, baseline):
    if value is None or baseline is None or baseline <= 0:
        return None
    return round(value / baseline, 4)


def case(cell_key, php, django, unimplemented):
    key = dict(zip(DIMENSIONS, cell_key))
    scenario = key["scenario"]
    result = {"scenario": scenario, "users": key["users"], "conditions": key,
              "php": None, "django": None, "ratio": {"p50": None, "p95": None},
              "verdict": None, "reasons": []}
    if php is not None:
        php_screen = screen(php)
        result["php"] = {
            "p50_ms": php_screen.get("p50_ms"), "p95_ms": php_screen.get("p95_ms"),
            "attempts": php.get("counts", {}).get("measured"),
            "successes": php.get("counts", {}).get("outcomes", {}).get("success"),
            "errors": php.get("counts", {}).get("outcomes", {}).get("error"),
            "unknowns": php.get("counts", {}).get("outcomes", {}).get("unknown"),
            "gate": php.get("gate", {}).get("status"),
            "gate_reasons": php.get("gate", {}).get("reasons"),
            "sampling_completed": (php.get("sampling") or {}).get("completed"),
            "batch_p95_range_ms": php_screen.get("batch_p95_range_ms"),
            "db_p50_ms": database(php).get("p50_ms"), "db_p95_ms": database(php).get("p95_ms"),
        }
    if django is not None:
        django_screen = screen(django)
        result["django"] = {
            "p50_ms": django_screen.get("p50_ms"), "p95_ms": django_screen.get("p95_ms"),
            "attempts": django.get("counts", {}).get("measured"),
            "successes": django.get("counts", {}).get("outcomes", {}).get("success"),
            "errors": django.get("counts", {}).get("outcomes", {}).get("error"),
            "unknowns": django.get("counts", {}).get("outcomes", {}).get("unknown"),
            "gate": django.get("gate", {}).get("status"),
            "gate_reasons": django.get("gate", {}).get("reasons"),
            "sampling_completed": (django.get("sampling") or {}).get("completed"),
            "batch_p95_range_ms": django_screen.get("batch_p95_range_ms"),
            "db_p50_ms": database(django).get("p50_ms"), "db_p95_ms": database(django).get("p95_ms"),
        }
    if php is None or django is None:
        if django is None and scenario in unimplemented:
            result["verdict"] = "not_implemented"
            result["reasons"] = ["candidate_feature_absent"]
            return result
        result["verdict"] = "inconclusive"
        result["reasons"] = ["missing_cell"]
        return result
    php_screen, django_screen = screen(php), screen(django)
    if (php.get("gate", {}).get("status") != "exploratory"
            or django.get("gate", {}).get("status") != "exploratory"):
        result["verdict"] = "inconclusive"
        result["reasons"] = ["php_" + str(php.get("gate", {}).get("status")),
                             "django_" + str(django.get("gate", {}).get("status"))]
        return result
    if not (php.get("sampling") or {}).get("completed") or not (django.get("sampling") or {}).get("completed"):
        result["verdict"] = "inconclusive"
        result["reasons"] = ["incomplete_independent_batches"]
        return result
    p50 = ratio(django_screen.get("p50_ms"), php_screen.get("p50_ms"))
    p95 = ratio(django_screen.get("p95_ms"), php_screen.get("p95_ms"))
    result["ratio"] = {"p50": p50, "p95": p95}
    if p50 is None or p95 is None:
        result["verdict"] = "inconclusive"
        result["reasons"] = ["missing_screen_distribution"]
        return result
    breaches = []
    if p50 > TARGET["p50_ratio_max"]:
        breaches.append("p50_above_target")
    if p95 > TARGET["p95_ratio_max"]:
        breaches.append("p95_above_target")
    result["verdict"] = "fail" if breaches else "pass"
    result["reasons"] = breaches
    return result


def read_accounting(directory):
    path = Path(directory) / "accounting.jsonl"
    records = []
    if not path.exists():
        return None
    for line in path.read_text().splitlines():
        try:
            records.append(json.loads(line))
        except (ValueError, TypeError):
            raise Mismatch("invalid accounting record") from None
    return records


def authority_summary(directory):
    records = read_accounting(directory)
    if records is None:
        return {"audits": 0, "violations": None, "changed_tables": []}
    audits = []
    for record in records:
        accounting = record.get("accounting")
        if isinstance(accounting, dict) and isinstance(accounting.get("authority_audit"), dict):
            audits.append(accounting["authority_audit"])
    changed = sorted({name for audit in audits for name in audit.get("changed_tables", [])})
    violations = sum(int(audit.get("violations") or 0) for audit in audits)
    return {"audits": len(audits), "violations": violations, "changed_tables": changed}


def payment_stages():
    if not RETAINED_PAYMENT_EVIDENCE.exists():
        return {"status": "unavailable", "reason": "retained_php_smoke_missing"}
    sys.path.insert(0, str(HERE))
    from report import summarize
    with gzip.open(RETAINED_PAYMENT_EVIDENCE, "rt") as stream:
        samples = [json.loads(line) for line in stream if line.strip()]
    report = summarize(samples)
    cells = {}
    for cell in report["cells"]:
        dimensions = cell["dimensions"]
        if dimensions["scenario"] not in ("checkout", "paid_delivery"):
            continue
        key = dimensions["profile"] + "/" + dimensions["scenario"]
        boundaries = {}
        for name in PAYMENT_BOUNDARIES:
            successful = cell["boundaries"][name]["successful"]
            boundaries[name] = {"observed": successful["observed"],
                                "p50_ms": successful["p50_ms"],
                                "p95_ms": successful["p95_ms"]}
        cells[key] = {"dimensions": dimensions, "boundaries": boundaries,
                      "sampling": {"attempts": cell["counts"]["measured"],
                                   "batches": cell["counts"]["batches"]}}
    proposal = {}
    for key, cell in cells.items():
        if not key.startswith("current/"):
            continue
        anchors = {}
        for name, values in cell["boundaries"].items():
            if values["p95_ms"] is None:
                continue
            anchors[name] = {
                "current_php_diagnostic_p95_ms": values["p95_ms"],
                "proposed_unratified_budget_ms": math.ceil(values["p95_ms"] * TARGET["p95_ratio_max"] * 10) / 10,
            }
        proposal[key] = anchors
    return {
        "status": "php_only_diagnostic",
        "source": str(RETAINED_PAYMENT_EVIDENCE.relative_to(ROOT)),
        "conditions": "small dataset, 1 user, warm/distinct/idle, one diagnostic batch of 2 attempts; not a p95 confidence",
        "cells": cells,
        "candidate_counterpart": "not_implemented",
        "candidate_reason": "no candidate navigation-to-checkout, paid detection or credential delivery surface exists in the P06-P10 slice",
        "budget_proposal": {
            "status": "proposed_unratified",
            "rule": "0.70 x current-PHP p95 per boundary, rounded up to 0.1 ms",
            "anchors": proposal,
            "authority": "H1 must ratify or replace these numbers after a qualified PHP checkout/paid run and candidate parity; no Django payment result was observed or used",
        },
    }


def build(php_directory, django_directory, findings_path):
    php_run, php_report = load(php_directory)
    django_run, django_report = load(django_directory)
    identity = pairing_identity(php_run, django_run)
    php_cells = index(php_report)
    django_cells = index(django_report)
    protocol = php_run["protocol"]
    unimplemented = set(protocol.get("candidate_unimplemented_scenarios", []))
    scenarios = protocol["navigation"]
    conditions = protocol["primary_conditions"]
    cases = []
    for scenario in scenarios:
        for users in protocol["users"]:
            key = (conditions["dataset"], "current", scenario, users,
                   conditions["traffic"], conditions["temperature"], conditions["background"])
            cases.append(case(key, php_cells.get(key), django_cells.get(key), unimplemented))
    counts = {verdict: sum(item["verdict"] == verdict for item in cases)
              for verdict in ("pass", "fail", "inconclusive", "not_implemented")}
    expected = len(scenarios) * len(protocol["users"])
    candidate_expected = (len(scenarios) - len(unimplemented)) * len(protocol["users"])
    findings = json.loads(Path(findings_path).read_text())
    unresolved = [item for item in findings.get("findings", [])
                  if item.get("severity") in ("critical", "high") and item.get("status") != "resolved"]
    php_authority = {"status": "not_applicable", "reason": "PHP comparator is not the candidate under audit"}
    django_authority = authority_summary(django_directory)
    if django_authority["audits"] == 0:
        django_authority["violations"] = None
    reasons = []
    if django_authority["violations"] is None:
        reasons.append("authority_audit_missing")
    elif django_authority["violations"]:
        reasons.append("authority_violation")
    if unresolved:
        reasons.append("unresolved_critical_or_high_security_finding")
    if counts["fail"]:
        reasons.append("case_below_quality_target")
    if counts["inconclusive"] or django_authority["violations"] is None:
        reasons.append("inconclusive_evidence")
    if counts["not_implemented"] or len(cases) < expected:
        reasons.append("candidate_feature_absent")
    if reasons and any(value in reasons for value in
                       ("authority_violation", "unresolved_critical_or_high_security_finding")):
        decision = "stop"
    elif reasons:
        decision = "fix"
    else:
        decision = "continue"
    return {
        "schema_version": 1,
        "issue": 692,
        "comparison": {
            "scope": "navigation screen acknowledgement",
            "statistic": "per-case successful screen_ack p50/p95; no pooling or averaging",
            "target": TARGET,
            "case_identity": list(DIMENSIONS),
            "non_aggregation": "every scenario/user pair is judged separately",
        },
        "identity": identity,
        "baseline_reuse": {
            "php_baseline_reused": True,
            "reason": identity["source_reuse_basis"],
            "php_measurement_path_delta": identity["php_measurement_path_delta"],
        },
        "cases": cases,
        "summary": {
            "expected_cases": expected,
            "candidate_expected_cases": candidate_expected,
            "paired_cases": counts["pass"] + counts["fail"] + counts["inconclusive"],
            **counts,
            "candidate_coverage_fraction": round(
                (counts["pass"] + counts["fail"] + counts["inconclusive"]) / candidate_expected, 4)
            if candidate_expected else None,
        },
        "authority": {
            "php": php_authority,
            "django": django_authority,
            "expectation": "zero public money/stock/effect/history row changes in candidate cells",
        },
        "security": {
            "review": findings.get("review", []),
            "unresolved_critical_high": len(unresolved),
            "findings": unresolved,
        },
        "payment_stages": payment_stages(),
        "verdict": {
            "decision": decision,
            "reasons": reasons,
            "owner_decision": False,
            "note": "This is measured evidence for H1, not the owner's continue/fix/stop decision.",
        },
        "limits": protocol.get("limit"),
    }


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__.split("\n", 1)[0])
    parser.add_argument("--php-run", required=True, help="completed p11 laravel run directory")
    parser.add_argument("--django-run", required=True, help="completed p11 django run directory")
    parser.add_argument("--findings", required=True, help="security review JSON with a findings list")
    parser.add_argument("-o", "--output", default="-", help="paired report path or - for stdout")
    args = parser.parse_args(argv)
    try:
        report = build(args.php_run, args.django_run, args.findings)
    except Mismatch as error:
        parser.exit(2, "p11 compare: " + str(error) + "\n")
    serialized = json.dumps(report, indent=2, sort_keys=True, allow_nan=False) + "\n"
    if args.output == "-":
        sys.stdout.write(serialized)
    else:
        Path(args.output).write_text(serialized)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
