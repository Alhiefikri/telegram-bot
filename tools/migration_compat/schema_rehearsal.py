#!/usr/bin/env python3
"""Synthetic repo-schema rehearsal. No source archives or endpoints accepted."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "tools/migration_harness"))
import harness
from smoke import cli, container, docker


def run(*args):
    result = subprocess.run(args, cwd=ROOT, capture_output=True, text=True, timeout=240)
    if result.returncode:
        # Laravel failures may include row values: never print the child exception.
        diagnostic = ROOT / ".local/migration-p03/last-failure.txt"
        diagnostic.parent.mkdir(parents=True, exist_ok=True)
        diagnostic.write_text(result.stdout + "\n" + result.stderr)
        diagnostic.chmod(0o600)
        raise RuntimeError("Synthetic schema subprocess failed: " + args[0])
    return result.stdout.strip()


def php(boundary, code):
    return run("docker", "run", "--rm", "--network", "container:" + boundary,
               "--cpus", "1.25", "--memory", "1216m", "--memory-swap", "1216m",
               "--cap-drop", "ALL", "--security-opt", "no-new-privileges:true",
               "--user", "1000:1000", "--tmpfs", "/tmp:size=64m",
               "--mount", f"type=bind,src={ROOT / 'tools/migration_compat'},dst=/probe,readonly",
               "--env", "APP_ENV=testing", "--env", "APP_DEBUG=false",
               "--env", "APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
               "--env", "DB_CONNECTION=pgsql", "--env", "DB_HOST=127.0.0.1",
               "--env", "DB_PORT=5432", "--env", "DB_DATABASE=disposable",
               "--env", "DB_USERNAME=harness", "--env", "DB_PASSWORD=harness",
               "--env", "LOG_CHANNEL=null", "--env", "CACHE_STORE=array",
               "--env", "SESSION_DRIVER=array", "--env", "MAIL_MAILER=array",
               "--env", "QUEUE_CONNECTION=sync", "--entrypoint", "/bin/sh",
               "lhie-p01-laravel:1", "-c",
               "rm -f .env .env.* bootstrap/cache/*.php; " + code)


def main():
    os.umask(0o077)
    config = json.loads((harness.STATE / "laravel/config.json").read_text())
    if harness.validate(config) != harness.default("laravel"):
        raise RuntimeError("P03 requires default fake synthetic configuration")
    if docker("ps", "-q", "--filter", "label=lhie.p01=disposable"):
        raise RuntimeError("Stop existing disposable stack before P03 rehearsal")
    output = ROOT / ".local/migration-p03"
    output.mkdir(parents=True, exist_ok=True)
    cli("reset", "laravel")
    try:
        cli("start", "laravel", "--quarantine")
        boundary = container("laravel", "boundary")
        database = container("laravel", "database")
        php(boundary, "php artisan migrate --force --no-interaction")
        result = run("docker", "run", "--rm", "--network", "container:" + boundary,
                     "--read-only", "--cap-drop", "ALL", "--security-opt", "no-new-privileges:true",
                     "--user", "1000:1000", "--cpus", "1.25", "--memory", "1216m",
                     "--memory-swap", "1216m", "--tmpfs", "/tmp:size=64m",
                     "--mount", f"type=bind,src={ROOT / 'candidate'},dst=/opt/candidate,readonly",
                     "--mount", f"type=bind,src={ROOT / 'tools/migration_compat'},dst=/probe,readonly",
                     "--env", "P03_SCHEMA_SMOKE=disposable-local-only", "--entrypoint", "python",
                     "lhie-p05-candidate:1", "/probe/schema_probe.py")
        result = json.loads(result)
        rollback = json.loads(php(boundary, "php /probe/rollback_reader.php"))
        assert rollback == {"rollback_reader": "passed"}
        schema_dump = docker("exec", database, "pg_dump", "-U", "harness", "-d", "disposable",
                             "--schema-only", "--no-comments")
        # Repository-generated objects only, no row/key/hash contents.
        (output / "schema-baseline.sql").write_text(schema_dump + "\n")
        migrations = {str(p.relative_to(ROOT)): hashlib.sha256(p.read_bytes()).hexdigest()
                      for p in sorted((ROOT / "src/database/migrations").glob("*.php"))}
        evidence = {"status": "passed", "dataset": "p03-repo-schema-synthetic-v1",
                    "revision": run("git", "rev-parse", "HEAD"),
                    "versions": {"postgres": docker("exec", database, "postgres", "--version"),
                                 "php": php(boundary, "php -r 'echo PHP_VERSION;'"),
                                 "candidate": run("docker", "run", "--rm", "--network", "none",
                                                  "--entrypoint", "python", "lhie-p05-candidate:1",
                                                  "-c", "import sys,django;print(sys.version.split()[0],django.get_version())")},
                    "migration_sources": migrations,
                    "probe_sources": {str(p.relative_to(ROOT)): hashlib.sha256(p.read_bytes()).hexdigest()
                                      for p in [ROOT / "candidate/lhie_candidate/legacy.py",
                                                ROOT / "candidate/lhie_candidate/metadata.py",
                                                ROOT / "candidate/requirements.lock",
                                                *sorted((ROOT / "tools/migration_compat").glob("schema_*.py")),
                                                ROOT / "tools/migration_compat/rollback_reader.php"]},
                    "schema_sha256": hashlib.sha256((schema_dump + "\n").encode()).hexdigest(),
                    "schema_artifact": ".local/migration-p03/schema-baseline.sql",
                    "postgres": result, **rollback,
                    "limits": ["Repository-generated synthetic baseline only; actual schema/keys/roles unknown: HD #684",
                               "No business writer activation, no money/stock race claim",
                               "Ephemeral fixture grants are not production privileges",
                               "PHP migration bootstrap is a P03 oracle, not final Python-only installer"]}
        (output / "schema-result.json").write_text(json.dumps(evidence, indent=2) + "\n")
        (output / "last-failure.txt").unlink(missing_ok=True)
        print(json.dumps(evidence, indent=2))
    finally:
        cli("reset", "laravel")


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print(json.dumps({"status": "failed", "error_type": type(error).__name__}), file=sys.stderr)
        raise SystemExit(1)
