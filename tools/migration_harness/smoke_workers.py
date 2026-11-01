#!/usr/bin/env python3
"""P10 isolated-worker proof using P01 isolation and repository fixtures."""
import hashlib
import json
import os
from pathlib import Path
import shlex
import subprocess
import sys

import harness
from smoke import container, docker
from smoke_checkout import php

ROOT = Path(__file__).resolve().parents[2]
TOOLS = ROOT / "tools/migration_harness"


def cli(*args):
    entry = ("import sys; from pathlib import Path; import harness; "
             "harness.STATE=Path(sys.argv[1]); harness.PREFIX='lhie-p10-'; "
             "harness.IMAGE='lhie-p09-boundary:local'; "
             "harness.BOUNDARY_SOURCE=Path(sys.argv[2]); "
             "sys.argv=[harness.__file__,*sys.argv[3:]]; harness.main()")
    result = subprocess.run([sys.executable, "-c", entry, str(harness.STATE), str(TOOLS / "boundary.py"),
                             *args], cwd=TOOLS, capture_output=True, text=True, timeout=180)
    if result.returncode:
        raise RuntimeError("P10 disposable lifecycle command failed: " + " ".join(args))
    return result.stdout.strip()


def probe(command, fixtures, output):
    directory = harness.STATE / "candidate"
    oracle_key = directory / "p10-oracle-key"
    args = [
        "docker", "run", "--rm", "-i", "--network", "container:" + container("candidate", "boundary"),
        "--read-only", "--cap-drop", "ALL", "--security-opt", "no-new-privileges:true",
        "--user", "1000:1000", "--cpus", "1.25", "--memory", "1216m", "--memory-swap", "1216m",
        "--tmpfs", "/tmp:size=256m",
        "--mount", f"type=bind,src={ROOT / 'candidate'},dst=/opt/candidate,readonly",
        "--mount", f"type=bind,src={directory / 'candidate.json'},dst=/run/harness/candidate.json,readonly",
        "--mount", f"type=bind,src={directory / 'django-key'},dst=/run/harness/django-key,readonly",
        "--mount", f"type=bind,src={oracle_key},dst=/run/harness/app-key,readonly",
        "--mount", f"type=bind,src={output},dst=/evidence",
        "--env", "P10_WORKERS_SMOKE=disposable-local-only",
        "--env", "P07_CHECKOUT_SMOKE=disposable-local-only",
        "--env", "PYTHONDONTWRITEBYTECODE=1",
        "--entrypoint", "python", "lhie-p09-candidate:local", "smoke_workers.py", *command,
    ]
    return subprocess.run(args, input=json.dumps(fixtures), capture_output=True, text=True, timeout=240)


def cgroup_usage(service):
    cid = container("candidate", service)
    if not cid:
        return None
    stats = docker("stats", "--no-stream", "--format", "{{.MemUsage}}|{{.CPUPerc}}", cid)
    inspect = docker("inspect", cid, "--format",
                     "{{.HostConfig.Memory}}|{{.HostConfig.MemorySwap}}|{{.HostConfig.NanoCpus}}|"
                     "{{.State.OOMKilled}}|{{.RestartCount}}")
    return {"usage": stats, "limits": inspect}


def main():
    os.umask(0o077)
    harness.STATE = ROOT / ".local/migration-p10/lifecycle"
    harness.PREFIX = "lhie-p10-"
    harness.IMAGE = "lhie-p09-boundary:local"
    harness.BOUNDARY_SOURCE = TOOLS / "boundary.py"
    directory = harness.STATE / "candidate"
    if not (directory / "config.json").exists():
        cli("init", "candidate")
    config = harness.validate(json.loads((directory / "config.json").read_text()))
    base = harness.default("candidate")
    if config != base:
        # Only this harness's synthetic safe-chat extension may persist across
        # disposable runs; never accept credentials, another mode or dataset.
        if config != {**base, "safe_chat_ids": config["safe_chat_ids"]}:
            raise RuntimeError("P10 requires the default synthetic fake configuration")
        config = base
    if docker("ps", "-q", "--filter", "label=lhie.p01=disposable"):
        raise RuntimeError("Stop existing disposable stack before P10")
    output = ROOT / ".local/migration-p10"
    output.mkdir(parents=True, exist_ok=True)
    cli("reset", "candidate")
    # The repository checkout fixture uses deterministic synthetic telegram IDs;
    # only the families exercised here are added to the disposable safe list.
    config["safe_chat_ids"] = [1, 700001, 700006, 700008, 700010, 700011, 700015]
    (directory / "config.json").write_text(json.dumps(config, indent=2) + "\n")
    harness.candidate_admission(config, directory, workers=True)
    try:
        manifest = cli("start", "candidate", "--quarantine")
        boundary = container("candidate", "boundary")
        database = container("candidate", "database")
        php(boundary, "php artisan migrate --force --no-interaction")
        navigation_fixture = (TOOLS / "navigation_fixture.php").read_text().removeprefix("<?php")
        checkout_fixture = (TOOLS / "checkout_fixture.php").read_text().removeprefix("<?php")
        navigation = json.loads(php(boundary, "php -r " + shlex.quote(navigation_fixture)))
        checkout = json.loads(php(boundary, "php -r " + shlex.quote(checkout_fixture)))
        schema = "\n".join((ROOT / "candidate/lhie_candidate/navigation" / name).read_text()
                           for name in ("schema.sql", "ingress_schema.sql", "assets_schema.sql", "grants.sql"))
        schema += "\n" + (ROOT / "candidate/lhie_candidate/checkout/grants.sql").read_text()
        schema += "\n" + (ROOT / "candidate/lhie_candidate/payments/grants.sql").read_text()
        schema += "\n" + (ROOT / "candidate/lhie_candidate/receipt/grants.sql").read_text()
        docker("exec", database, "psql", "-v", "ON_ERROR_STOP=1", "-U", "harness", "-d", "disposable", "-c", schema)
        # The PHP oracle order is P07 expected-value evidence, not a P10 receipt
        # obligation; mark its projection sent so it cannot shadow this drill.
        docker("exec", database, "psql", "-v", "ON_ERROR_STOP=1", "-U", "harness", "-d", "disposable", "-c",
               "UPDATE public.orders SET telegram_receipt_sent_at = now() "
               "WHERE customer_id IN (SELECT id FROM public.customers WHERE telegram_id = 700001) "
               "AND payment_status = 'paid'")
        oracle_key = directory / "p10-oracle-key"
        oracle_key.write_text("AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\n")
        oracle_key.chmod(0o600)
        fixtures = {"navigation": navigation, "checkout": checkout}

        mixed = probe(["mixed"], fixtures, output)
        if mixed.returncode:
            (output / "mixed.stderr").write_text(mixed.stderr)
            raise RuntimeError("P10 mixed probe failed; private diagnostics in .local/migration-p10")
        mixed_result = json.loads(mixed.stdout)
        assert mixed_result["status"] == "passed"

        harness.run(harness.compose_cmd(directory, "stop", "redis"))
        try:
            recovered = probe(["recovery"], fixtures, output)
        finally:
            harness.run(harness.compose_cmd(directory, "start", "redis"))
        if recovered.returncode:
            (output / "recovery.stderr").write_text(recovered.stderr)
            raise RuntimeError("P10 recovery probe failed; private diagnostics in .local/migration-p10")
        recovery_result = json.loads(recovered.stdout)
        assert recovery_result["status"] == "passed"

        sources = [
            *sorted((ROOT / "candidate/lhie_candidate/workers").glob("*.py")),
            *sorted((ROOT / "candidate/lhie_candidate/receipt").glob("*.py")),
            *sorted((ROOT / "candidate/lhie_candidate/payments").glob("*.py")),
            ROOT / "candidate/lhie_candidate/payments/grants.sql",
            ROOT / "candidate/lhie_candidate/receipt/grants.sql",
            ROOT / "candidate/lhie_candidate/checkout/grants.sql",
            *sorted((ROOT / "candidate/lhie_candidate/navigation").glob("*.sql")),
            ROOT / "candidate/lhie_candidate/navigation/processor.py",
            ROOT / "candidate/lhie_candidate/navigation/ingress.py",
            ROOT / "candidate/lhie_candidate/readiness.py",
            ROOT / "candidate/lhie_candidate/runtime.py",
            ROOT / "candidate/postgres-init.sql",
            ROOT / "candidate/stack.py",
            ROOT / "candidate/workers.py",
            ROOT / "candidate/smoke_workers.py",
            ROOT / "candidate/tests/test_workers.py",
            ROOT / "candidate/tests/test_workers_postgres.py",
            ROOT / "candidate/tests/test_runtime.py",
            ROOT / "candidate/requirements.lock",
            TOOLS / "boundary.py",
            TOOLS / "harness.py",
            Path(__file__),
            TOOLS / "navigation_fixture.php",
            TOOLS / "checkout_fixture.php",
            ROOT / "tests/migration_harness/test_worker_relay.py",
        ]
        observed = {
            "status": "passed",
            "mixed": mixed_result,
            "recovery": recovery_result,
            "source_sha256": {str(path.relative_to(ROOT)): hashlib.sha256(path.read_bytes()).hexdigest()
                              for path in sources if path.is_file()},
            "base_revision": subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip(),
            "dependency_image": docker("image", "inspect", "lhie-p09-candidate:local", "--format", "{{.Id}}"),
            "boundary_image": docker("image", "inspect", "lhie-p09-boundary:local", "--format", "{{.Id}}"),
            "postgres_version": docker("exec", database, "postgres", "--version"),
            "harness_manifest": str(Path(manifest).relative_to(ROOT)),
            "stack_cgroups": {name: cgroup_usage(name) for name in ("boundary", "database", "redis")},
            "limits": [
                "Synthetic fake Telegram relay and fake sandbox only; no real credential or payment",
                "Redis was stopped for the recovery phase; the probe container ran without it",
                "The process and connection ceilings are admission/evidence, not production capacity",
                "cgroup memory.current is not a sum of process RSS; container limits are ceilings, not usage",
                "Notification-family work is not ported in this slice; its ledger row stayed isolated",
            ],
        }
        (output / "verification.json").write_text(json.dumps(observed, indent=2) + "\n")
        for name in ("mixed.stderr", "recovery.stderr", "probe-failure.txt"):
            (output / name).unlink(missing_ok=True)
        print(json.dumps(observed, indent=2))
    finally:
        cli("reset", "candidate")


if __name__ == "__main__":
    main()
