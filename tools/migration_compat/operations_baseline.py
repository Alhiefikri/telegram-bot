#!/usr/bin/env python3
"""Generate the reviewed P26 public-schema baseline from repository migrations.

This is a development/evidence tool. It runs the checked-out Laravel migration
oracle only to produce a SQL artifact; runtime bootstrap/upgrade/restore never
executes PHP. The artifact carries no business rows, roles or passwords.
"""

import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "tools/migration_harness"))
import harness
from smoke import cli, container, docker

OUTPUT = ROOT / "candidate/lhie_candidate/operations"
SCHEMA = OUTPUT / "schema-baseline.sql"
MANIFEST = OUTPUT / "baseline.json"


def run(*args, timeout=300):
    result = subprocess.run(args, cwd=ROOT, capture_output=True, text=True, timeout=timeout)
    if result.returncode:
        raise RuntimeError("Baseline command failed: " + args[0])
    return result.stdout


def docker_input(container, *arguments, stdin, timeout=300):
    result = subprocess.run(["docker", "exec", "-i", container, *arguments],
                            input=stdin, capture_output=True, text=True, timeout=timeout)
    if result.returncode:
        raise RuntimeError("Canonical PostgreSQL schema import failed")
    return result.stdout


def php(boundary, code):
    return run("docker", "run", "--rm", "--network", "container:" + boundary,
               "--cpus", "1.25", "--memory", "1216m", "--memory-swap", "1216m",
               "--cap-drop", "ALL", "--security-opt", "no-new-privileges:true",
               "--user", "1000:1000", "--tmpfs", "/tmp:size=64m",
               "--mount", f"type=bind,src={ROOT / 'src/database/migrations'},dst=/var/www/html/database/migrations,readonly",
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


def normalized_sql(text):
    lines = []
    for line in text.splitlines():
        if line.startswith(("\\restrict ", "\\unrestrict ",
                            "-- Dumped from database version ",
                            "-- Dumped by pg_dump version ")):
            continue
        lines.append(line.rstrip())
    return "\n".join(lines).strip() + "\n"


def main():
    os.umask(0o077)
    config_path = harness.STATE / "laravel/config.json"
    if not config_path.exists():
        cli("init", "laravel")
    config = harness.validate(json.loads(config_path.read_text()))
    if config != harness.default("laravel"):
        raise RuntimeError("P26 baseline requires default fake synthetic configuration")
    if docker("ps", "-q", "--filter", "label=lhie.p01=disposable"):
        raise RuntimeError("Stop existing disposable stacks before generating the baseline")
    cli("reset", "laravel")
    try:
        cli("start", "laravel", "--quarantine")
        boundary = container("laravel", "boundary")
        database = container("laravel", "database")
        php(boundary, "php artisan migrate --force --no-interaction")
        dump = docker("exec", database, "pg_dump", "-U", "harness", "-d", "disposable",
                      "--schema-only", "--no-owner", "--no-privileges", "--no-comments",
                      "--no-security-labels", "--no-publications", "--no-subscriptions",
                      "--exclude-schema=django_candidate")
        version_match = re.search(r"(?m)^-- Dumped from database version ([0-9]+)", dump)
        if version_match is None:
            raise RuntimeError("PostgreSQL server version is unavailable")
        postgres_major = int(version_match.group(1))
        source_schema = normalized_sql(dump)
        docker("exec", database, "createdb", "-U", "harness", "p26_schema_canonical")
        docker_input(database, "psql", "-X", "-v", "ON_ERROR_STOP=1",
                     "--single-transaction", "-U", "harness", "-d", "p26_schema_canonical",
                     "-f", "-", stdin=source_schema)
        canonical_dump = docker("exec", database, "pg_dump", "-U", "harness",
                                "-d", "p26_schema_canonical", "--schema-only",
                                "--no-owner", "--no-privileges", "--no-comments",
                                "--no-security-labels", "--no-publications",
                                "--no-subscriptions", "--exclude-schema=django_candidate")
        canonical_version = re.search(r"(?m)^-- Dumped from database version ([0-9]+)", canonical_dump)
        if canonical_version is None or int(canonical_version.group(1)) != postgres_major:
            raise RuntimeError("Canonical PostgreSQL server version changed")
        schema = normalized_sql(canonical_dump)
        SCHEMA.write_text(schema)
        os.chmod(SCHEMA, 0o644)
        counts_sql = """
            SELECT
              (SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                 WHERE n.nspname='public' AND c.relkind='r') AS tables,
              (SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                 WHERE n.nspname='public' AND c.relkind='S') AS sequences,
              (SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                 WHERE n.nspname='public' AND c.relkind='i') AS indexes,
              (SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                 WHERE n.nspname='public') AS functions,
              (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid
                 JOIN pg_namespace n ON n.oid=c.relnamespace
                 WHERE n.nspname='public' AND NOT t.tgisinternal) AS triggers,
              (SELECT count(*) FROM pg_constraint c JOIN pg_namespace n ON n.oid=c.connamespace
                 WHERE n.nspname='public') AS constraints,
              (SELECT count(*) FROM pg_extension) AS extensions
        """
        raw = docker("exec", database, "psql", "-U", "harness", "-d", "p26_schema_canonical",
                     "-At", "-F", ",", "-c", counts_sql)
        values = [int(value) for value in raw.splitlines()[0].split(",")]
        counts = dict(zip(("tables", "sequences", "indexes", "functions", "triggers",
                           "constraints", "extensions"), values))
        migrations = {str(path.relative_to(ROOT)): hashlib.sha256(path.read_bytes()).hexdigest()
                      for path in sorted((ROOT / "src/database/migrations").glob("*.php"))}
        manifest = {
            "version": "repo-schema-2026_11_12_000001",
            "postgres_major": postgres_major,
            "created_at": "2026-11-12T00:00:00+00:00",
            "source_revision": run("git", "rev-parse", "HEAD").strip(),
            "sql_sha256": hashlib.sha256(schema.encode()).hexdigest(),
            "schema_sha256": hashlib.sha256(schema.encode()).hexdigest(),
            "counts": counts,
            "migrations": migrations,
            "generator": "tools/migration_compat/operations_baseline.py",
            "artifact": str(SCHEMA.relative_to(ROOT)),
            "limits": [
                "Repository-generated schema only; no business rows or roles.",
                "Actual production schema/grants/keys remain HD #684.",
                "PHP is used only by this development generator, never by runtime bootstrap.",
            ],
        }
        MANIFEST.write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n")
        os.chmod(MANIFEST, 0o644)
        print(json.dumps({"status": "ok", "schema_bytes": len(schema.encode()),
                          "sql_sha256": manifest["sql_sha256"], "counts": counts,
                          "migrations": len(migrations)}, indent=2))
    finally:
        cli("reset", "laravel")


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print(json.dumps({"status": "failed", "error_type": type(error).__name__}), file=sys.stderr)
        raise SystemExit(1)
