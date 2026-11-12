#!/usr/bin/env python3
"""Disposable PostgreSQL P26 install, recovery, retention and restore smoke."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import time

ROOT = Path(__file__).resolve().parents[2]
CANDIDATE = ROOT / "candidate"
sys.path.insert(0, str(CANDIDATE))
OPS = CANDIDATE / "ops.py"
BASELINE = CANDIDATE / "lhie_candidate" / "operations"
IMAGE = "postgres:16-alpine"
SOURCE_FILES = (
    "candidate/ops.py",
    "candidate/lhie_candidate/operations/contract.py",
    "candidate/lhie_candidate/operations/jobs.py",
    "candidate/lhie_candidate/operations/runner.py",
    "candidate/lhie_candidate/operations/state.py",
    "candidate/lhie_candidate/operations/health.py",
    "candidate/lhie_candidate/operations/retention.py",
    "candidate/lhie_candidate/operations/cleanup.py",
    "candidate/lhie_candidate/operations/bootstrap.py",
    "candidate/lhie_candidate/operations/backup.py",
    "candidate/lhie_candidate/operations/baseline.json",
    "candidate/lhie_candidate/operations/schema-baseline.sql",
    "candidate/lhie_candidate/operations/migrations/2026_11_12_000001_operations_metadata.sql",
    "candidate/lhie_candidate/checkout/lifecycle.py",
    "candidate/tests/test_operations_backup.py",
    "candidate/tests/test_operations_bootstrap.py",
    "candidate/tests/test_operations_health.py",
    "candidate/tests/test_operations_jobs.py",
    "candidate/tests/test_operations_postgres.py",
    "candidate/tests/test_operations_retention.py",
    "candidate/tests/test_operations_scheduler.py",
    "candidate/tests/test_operations_contract.py",
    "candidate/tests/test_order_lifecycle_postgres.py",
    "tools/migration_compat/operations_baseline.py",
    "tools/migration_harness/smoke_operations.py",
)


def _run(command, *, expected=0, env=None, timeout=600):
    result = subprocess.run(command, cwd=CANDIDATE, env=env, capture_output=True,
                            text=True, timeout=timeout)
    if result.returncode != expected:
        raise RuntimeError("P26 local smoke command failed: " + Path(command[0]).name + "\n" +
                           (result.stderr or result.stdout)[-4000:])
    return result.stdout.strip()


def _ops(*arguments, expected=0, env=None):
    output = _run([str(CANDIDATE / ".venv" / "bin" / "python"), str(OPS), *map(str, arguments)],
                  expected=expected, env=env, timeout=900)
    if expected:
        if output:
            raise RuntimeError("Refused P26 operator command emitted unexpected output")
        return None
    return json.loads(output)


def _docker(*arguments):
    return subprocess.check_output(["docker", *map(str, arguments)], text=True).strip()


def _client_env(root, container):
    directory = root / "pg16-client-bin"
    directory.mkdir(mode=0o700)
    wrapper_source = (
        "#!/usr/bin/env python3\n"
        "import os,subprocess,sys\n"
        "from pathlib import Path\n"
        "from urllib.parse import urlsplit,urlunsplit\n"
        f"container={container!r}\n"
        f"root=Path({str(root)!r})\n"
        "tool=Path(sys.argv[0]).name\n"
        "arguments=sys.argv[1:]\n"
        "for index,value in enumerate(arguments):\n"
        "    parsed=urlsplit(value)\n"
        "    if parsed.scheme in ('postgresql','postgres'):\n"
        "        arguments[index]=urlunsplit((parsed.scheme,f'{parsed.username}@127.0.0.1:5432',parsed.path,'',''))\n"
        "    elif Path(value).is_absolute():\n"
        "        try: relative=Path(value).relative_to(root)\n"
        "        except ValueError: continue\n"
        "        arguments[index]='/p26-smoke/'+relative.as_posix()\n"
        "command=['docker','exec']\n"
        "for key in ('PGOPTIONS','PGPASSWORD'):\n"
        "    if os.environ.get(key): command.extend(['--env',f'{key}={os.environ[key]}'])\n"
        "command.extend([container,tool,*arguments])\n"
        "raise SystemExit(subprocess.run(command).returncode)\n")
    for tool in ("pg_dump", "pg_dumpall", "pg_restore", "psql"):
        wrapper = directory / tool
        wrapper.write_text(wrapper_source)
        wrapper.chmod(0o700)
    return {**os.environ, "PATH": str(directory) + os.pathsep + os.environ.get("PATH", ""),
            "TMPDIR": str(root)}


def _dsn(port, database):
    return f"postgresql://p26_admin:p26_local_only@127.0.0.1:{port}/{database}"


def _database(dsn, name):
    import psycopg
    with psycopg.connect(dsn, autocommit=True) as connection:
        connection.execute(f"CREATE DATABASE {name} OWNER p26_admin")


def _assert_restored_rows(dsn):
    import psycopg
    with psycopg.connect(dsn) as connection:
        ledger = connection.execute("""SELECT amount,balance_after,source_type,source_id,
            idempotency_key FROM public.store_balance_entries""").fetchone()
        event = connection.execute("""SELECT provider,event_type,raw_payload,processed_at
            FROM public.payment_events""").fetchone()
        effect = connection.execute("""SELECT state,last_error_code,unknown_at
            FROM public.telegram_outbound_effects""").fetchone()
        update = connection.execute("""SELECT update_id,payload,processed_at,customer_id
            FROM public.telegram_updates""").fetchone()
        assert ledger == (1, 49, "order", "p26-synthetic", "p26-synthetic-entry"), ledger
        assert event[0:2] == ("p26-synthetic", "callback") and event[2] == {"sentinel": "p26-payment-event"}, event
        assert event[3] is None, event
        assert effect[0:2] == ("unknown", "delivery_uncertain") and effect[2] is not None, effect
        assert update[0] == 269991123 and update[1] == {}, update
        assert update[2] is not None and update[3] is None, update


def _source_hashes():
    return {name: hashlib.sha256((ROOT / name).read_bytes()).hexdigest()
            for name in SOURCE_FILES}


def main():
    python = CANDIDATE / ".venv" / "bin" / "python"
    if not python.is_file():
        raise RuntimeError("Install the checked-in candidate virtual environment first")
    name = f"lhie-p26-operations-{os.getpid()}"
    existing = subprocess.run(["docker", "inspect", name], capture_output=True, text=True)
    if existing.returncode == 0:
        raise RuntimeError("P26 disposable container name already exists; refusing to touch it")
    labels = ["--label", "lhie.p26=disposable", "--label", "lhie.p26.scope=operations-smoke"]
    with tempfile.TemporaryDirectory(prefix="p26-operations-") as temporary:
        root = Path(temporary)
        os.chmod(root, 0o700)
        started = False
        try:
            _run(["docker", "run", "--detach", "--rm", "--name", name, *labels,
                  "--cpus=1", "--memory=1024m", "--memory-swap=1024m", "--pids-limit=64",
                  "--mount", f"type=bind,source={root},target=/p26-smoke",
                  "--publish", "127.0.0.1::5432", "--env", "POSTGRES_USER=p26_admin",
                  "--env", "POSTGRES_PASSWORD=p26_local_only", "--env", "POSTGRES_DB=p26_source",
                  IMAGE], timeout=180)
            started = True
            mapping = _docker("port", name, "5432/tcp").splitlines()[0]
            port = int(mapping.rsplit(":", 1)[1])
            source_dsn = _dsn(port, "p26_source")
            import psycopg
            for _ in range(60):
                try:
                    with psycopg.connect(source_dsn, connect_timeout=2):
                        break
                except psycopg.OperationalError:
                    time.sleep(1)
            else:
                raise RuntimeError("P26 disposable PostgreSQL did not become ready")
            client_env = _client_env(root, name)
            baseline = _ops("bootstrap", "--dsn", source_dsn, "--baseline-dir", BASELINE, env=client_env)
            assert baseline["status"] == "ok" and baseline["schema_sha256"], baseline
            upgraded = _ops("upgrade", "--dsn", source_dsn, "--baseline-dir", BASELINE, env=client_env)
            assert upgraded == {"status": "ok", "applied": [], "pending": 0}, upgraded
            assert _ops("verify", "--dsn", source_dsn, "--baseline-dir", BASELINE,
                        env=client_env)["status"] == "ok"

            admission = {**os.environ, "P26_OPERATIONS_SMOKE": "disposable-local-only",
                         "P26_OPERATIONS_DSN": source_dsn}
            evidence = json.loads(_run([
                str(python), "-c", "from tests.test_operations_postgres import exercise_operations; "
                "import json,os; print(json.dumps(exercise_operations(os.environ['P26_OPERATIONS_DSN'])))"],
                env=admission, timeout=300))
            assert evidence["status"] == "passed", evidence

            passphrase = root / "passphrase"
            passphrase.write_text("p26-local-backup-passphrase")
            passphrase.chmod(0o600)
            file_source = root / "synthetic-file.txt"
            file_source.write_text("p26 synthetic data\n")
            file_source.chmod(0o600)
            key_source = root / "synthetic-key.bin"
            key_source.write_bytes(b"p26 synthetic key material\n")
            key_source.chmod(0o600)
            archive = root / "p26-backup.gpg"
            backed_up = _ops("backup", "--dsn", source_dsn, "--output", archive,
                             "--passphrase-file", passphrase,
                             "--file", f"{file_source}=storage/synthetic-file.txt",
                             "--key", f"{key_source}=application/synthetic-key.bin", env=client_env)
            assert backed_up["status"] == "ok", backed_up
            assert backed_up["counts"]["store_balance_entries"] == 1, backed_up
            assert backed_up["counts"]["unresolved_effects"] == 1, backed_up
            from lhie_candidate.operations.backup import _extracted
            with _extracted(archive, "p26-local-backup-passphrase") as (payload, _manifest):
                roles = (payload / "roles.sql").read_text()
                assert not re.search(r"(?im)^\s*(?:CREATE|ALTER) ROLE .*\bPASSWORD\s", roles)

            _database(source_dsn, "p26_restore")
            _database(source_dsn, "p26_refusal")
            target_dsn = _dsn(port, "p26_restore")
            _ops("restore", "--dsn", _dsn(port, "p26_refusal"), archive,
                 "--passphrase-file", passphrase, expected=1, env=client_env)
            with psycopg.connect(_dsn(port, "p26_refusal")) as connection:
                assert connection.execute("SELECT count(*) FROM pg_catalog.pg_tables WHERE schemaname='public'").fetchone()[0] == 0

            files_destination = root / "restored-files"
            keys_destination = root / "restored-keys"
            restored = _ops("restore", "--dsn", target_dsn, archive,
                            "--passphrase-file", passphrase,
                            "--files-destination", files_destination,
                            "--keys-destination", keys_destination, env=client_env)
            assert restored["schema_sha256"] == backed_up["schema_sha256"], restored
            assert restored["grants_sha256"] == backed_up["grants_sha256"], restored
            assert (files_destination / "storage" / "synthetic-file.txt").read_text() == "p26 synthetic data\n"
            assert (files_destination / "storage" / "synthetic-file.txt").stat().st_mode & 0o777 == 0o644
            assert (keys_destination / "application" / "synthetic-key.bin").read_bytes() == b"p26 synthetic key material\n"
            assert (keys_destination / "application" / "synthetic-key.bin").stat().st_mode & 0o777 == 0o600
            _assert_restored_rows(target_dsn)
            assert _ops("verify", "--dsn", target_dsn, "--baseline-dir", BASELINE,
                        env=client_env)["status"] == "ok"

            retry_files, retry_keys = root / "retry-files", root / "retry-keys"
            _ops("restore", "--dsn", target_dsn, archive, "--passphrase-file", passphrase,
                 "--files-destination", retry_files, "--keys-destination", retry_keys,
                 expected=1, env=client_env)
            assert not retry_files.exists() and not retry_keys.exists()
            _assert_restored_rows(target_dsn)
            import django
            with psycopg.connect(source_dsn) as connection:
                postgres_version = connection.execute("SHOW server_version").fetchone()[0]
            report = {
                "status": "passed",
                "command": "candidate/.venv/bin/python tools/migration_harness/smoke_operations.py",
                "dataset": "synthetic P26 disposable PostgreSQL; no production or historical rows",
                "repository_revision": subprocess.check_output(
                    ["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip(),
                "baseline_source_revision": json.loads((BASELINE / "baseline.json").read_text())["source_revision"],
                "source_sha256": _source_hashes(),
                "environment": {"python": sys.version.split()[0], "django": django.get_version(),
                                "postgres": postgres_version},
                "bootstrap_upgrade_verify": "passed",
                "operations": evidence,
                "backup_restore": "passed",
                "roles_acl_extensions_functions": "verified",
                "files_and_keys_permissions": "verified",
                "nonempty_restore_refused": "passed",
                "schema_sha256": backed_up["schema_sha256"],
                "grants_sha256": backed_up["grants_sha256"],
                "catalog": backed_up["catalog"],
                "counts": backed_up["counts"],
                "unknowns": ["production roles/grants, original keys/files and historical data remain HD #684"],
                "limits": ["no production operation, CI/CD, provider activation, Telegram send or real-money test",
                           "role passwords are excluded and remain operator-managed"],
                "artifact": ".local/migration-p26/verification.json",
            }
            artifact = ROOT / report["artifact"]
            artifact.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            temporary_artifact = artifact.with_name(f".{artifact.name}.{os.getpid()}.tmp")
            temporary_artifact.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n")
            os.chmod(temporary_artifact, 0o600)
            os.replace(temporary_artifact, artifact)
            print(json.dumps(report, indent=2, sort_keys=True))
        finally:
            if started:
                inspect = subprocess.run(["docker", "inspect", name], capture_output=True, text=True)
                if inspect.returncode == 0:
                    metadata = json.loads(inspect.stdout)[0]
                    container_labels = metadata["Config"].get("Labels") or {}
                    if (container_labels.get("lhie.p26") != "disposable"
                            or container_labels.get("lhie.p26.scope") != "operations-smoke"):
                        raise RuntimeError("Refusing to remove a container without P26 ownership labels")
                    _run(["docker", "rm", "--force", name], timeout=60)


if __name__ == "__main__":
    main()
