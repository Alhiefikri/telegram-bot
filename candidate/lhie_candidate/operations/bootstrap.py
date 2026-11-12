"""Python-only reviewed schema bootstrap, migration history and verification.

This runs no PHP, Composer or Artisan. The checked-in baseline is generated from
the repository migrations by ``tools/migration_compat/operations_baseline.py``;
the runtime command only applies reviewed SQL through ``psql`` and records its
checksum. Existing databases verify the catalog before applying additive deltas.
"""

from dataclasses import dataclass
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
from urllib.parse import urlsplit, urlunsplit

BASELINE_NAME = "schema-baseline.sql"
MANIFEST_NAME = "baseline.json"
MIGRATIONS_NAME = "migrations"
LOCAL_HOSTS = frozenset({"127.0.0.1", "::1", "localhost"})
DELTA_PATTERN = re.compile(r"^(?P<version>[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+)\.sql$")
MIGRATION_HISTORY_DDL = """CREATE SCHEMA IF NOT EXISTS django_candidate;
CREATE TABLE IF NOT EXISTS django_candidate.candidate_schema_migrations (
    version varchar(96) PRIMARY KEY,
    checksum varchar(64) NOT NULL CHECK (checksum ~ '^[a-f0-9]{64}$'),
    kind varchar(16) NOT NULL CHECK (kind IN ('baseline', 'delta')),
    source_revision varchar(64) NULL,
    applied_at timestamptz NOT NULL DEFAULT now()
)"""


class BootstrapError(RuntimeError):
    pass


def _sha256(path):
    digest = hashlib.sha256()
    with open(path, "rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _normalized_sql(text):
    lines = []
    for line in text.splitlines():
        if line.startswith(("\\restrict ", "\\unrestrict ",
                            "-- Dumped from database version ",
                            "-- Dumped by pg_dump version ")):
            continue
        lines.append(line.rstrip())
    return "\n".join(lines).strip() + "\n"


def _dump_majors(text):
    server = re.search(r"(?m)^-- Dumped from database version ([0-9]+)", text)
    client = re.search(r"(?m)^-- Dumped by pg_dump version ([0-9]+)", text)
    if server is None or client is None:
        raise BootstrapError("PostgreSQL dump version is unavailable")
    return int(server.group(1)), int(client.group(1))

def parse_dsn(dsn, *, allow_remote=False):
    if not isinstance(dsn, str) or not dsn or len(dsn) > 1024:
        raise ValueError("Invalid PostgreSQL DSN")
    parsed = urlsplit(dsn)
    if parsed.scheme not in ("postgresql", "postgres"):
        raise ValueError("Only PostgreSQL DSNs are admitted")
    host = parsed.hostname
    if host is None or (host not in LOCAL_HOSTS and not allow_remote):
        raise ValueError("Only a local disposable PostgreSQL endpoint is admitted")
    if not parsed.username or not parsed.path or parsed.path == "/":
        raise ValueError("PostgreSQL DSN requires a user and database")
    if parsed.query or parsed.fragment:
        raise ValueError("PostgreSQL DSN must not carry query or fragment data")
    return parsed


def _connection_parts(dsn, *, allow_remote=False):
    parsed = parse_dsn(dsn, allow_remote=allow_remote)
    password = parsed.password or ""
    netloc = parsed.hostname or ""
    if ":" in netloc and not netloc.startswith("["):
        netloc = f"[{netloc}]"
    if parsed.port is not None:
        netloc += f":{parsed.port}"
    netloc = f"{parsed.username}@{netloc}"
    safe = urlunsplit((parsed.scheme, netloc, parsed.path, "", ""))
    return safe, password, parsed


def _env(password):
    env = {key: os.environ[key] for key in ("PATH", "HOME", "LANG", "LC_ALL") if key in os.environ}
    if password:
        env["PGPASSWORD"] = password
    env["PGOPTIONS"] = "-c statement_timeout=30000 -c lock_timeout=5000"
    return env


def _run(tool, args, *, env, stdin=None, timeout=180):
    try:
        result = subprocess.run([tool, *args], env=env, input=stdin,
                                capture_output=True, text=True, timeout=timeout)
    except (OSError, subprocess.TimeoutExpired):
        raise BootstrapError(f"{tool} is unavailable or timed out") from None
    if result.returncode:
        # Never echo SQL, DSN or row data. The caller gets a stable error code.
        raise BootstrapError(f"{tool} failed")
    return result.stdout


def catalog_counts(dsn, *, allow_remote=False):
    safe, password, _ = _connection_parts(dsn, allow_remote=allow_remote)
    sql = """
        SELECT
          (SELECT count(*) FROM pg_catalog.pg_tables WHERE schemaname='public') AS tables,
          (SELECT count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
             WHERE n.nspname='public' AND c.relkind='S') AS sequences,
          (SELECT count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
             WHERE n.nspname='public' AND c.relkind='i') AS indexes,
          (SELECT count(*) FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
             WHERE n.nspname='public') AS functions,
          (SELECT count(*) FROM pg_catalog.pg_trigger t
             JOIN pg_catalog.pg_class c ON c.oid=t.tgrelid
             JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
             WHERE n.nspname='public' AND NOT t.tgisinternal) AS triggers,
          (SELECT count(*) FROM pg_catalog.pg_constraint c
             JOIN pg_catalog.pg_namespace n ON n.oid=c.connamespace
             WHERE n.nspname='public') AS constraints,
          (SELECT count(*) FROM pg_catalog.pg_extension) AS extensions;
    """
    output = _run("psql", ["-X", "-v", "ON_ERROR_STOP=1", "-At", "-F", ",", "-c", sql, safe],
                  env=_env(password))
    values = [int(value) for value in output.strip().splitlines()[0].split(",")]
    return dict(zip(("tables", "sequences", "indexes", "functions", "triggers",
                     "constraints", "extensions"), values))


def public_schema_sha256(dsn, *, allow_remote=False, expected_major=None):
    safe, password, _ = _connection_parts(dsn, allow_remote=allow_remote)
    dump = _run("pg_dump", ["--schema-only", "--no-owner", "--no-privileges",
                            "--no-comments", "--no-security-labels",
                            "--no-publications", "--no-subscriptions",
                            "--exclude-schema=django_candidate", safe],
                env=_env(password), timeout=300)
    server_major, client_major = _dump_majors(dump)
    if server_major != client_major or (expected_major is not None and server_major != expected_major):
        raise BootstrapError("PostgreSQL server or client major version differs from the baseline")
    return hashlib.sha256(_normalized_sql(dump).encode()).hexdigest()


def user_tables(dsn, *, allow_remote=False):
    safe, password, _ = _connection_parts(dsn, allow_remote=allow_remote)
    sql = """SELECT
        EXISTS (SELECT 1 FROM pg_catalog.pg_namespace
            WHERE nspname NOT IN ('public','information_schema')
              AND left(nspname, 3) <> 'pg_')
        OR EXISTS (SELECT 1 FROM pg_catalog.pg_class c
            JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname='public')
        OR EXISTS (SELECT 1 FROM pg_catalog.pg_proc p
            JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
            WHERE n.nspname='public')
        OR EXISTS (SELECT 1 FROM pg_catalog.pg_type t
            JOIN pg_catalog.pg_namespace n ON n.oid=t.typnamespace
            WHERE n.nspname='public' AND t.typtype <> 'b' AND t.typrelid=0)"""
    output = _run("psql", ["-X", "-v", "ON_ERROR_STOP=1", "-At", "-c", sql, safe],
                  env=_env(password))
    return output.strip() in ("t", "true")


@dataclass(frozen=True, slots=True)
class Baseline:
    path: Path
    manifest: dict

    @property
    def version(self):
        return self.manifest["version"]

    @property
    def schema_sha256(self):
        return self.manifest["schema_sha256"]

    @property
    def sql_sha256(self):
        return self.manifest["sql_sha256"]


def load_baseline(directory):
    directory = Path(directory)
    sql_path = directory / BASELINE_NAME
    manifest_path = directory / MANIFEST_NAME
    try:
        manifest = json.loads(manifest_path.read_text())
    except (OSError, ValueError):
        raise BootstrapError("Baseline manifest is unavailable") from None
    required = {"version", "sql_sha256", "schema_sha256", "postgres_major",
                "counts", "migrations", "created_at"}
    if not isinstance(manifest, dict) or not required <= manifest.keys():
        raise BootstrapError("Baseline manifest is incomplete")
    if (not isinstance(manifest["version"], str)
            or re.fullmatch(r"[a-z0-9._-]{1,96}", manifest["version"]) is None):
        raise BootstrapError("Baseline version is invalid")
    if type(manifest["postgres_major"]) is not int or manifest["postgres_major"] < 1:
        raise BootstrapError("Baseline PostgreSQL major version is invalid")
    for field in ("sql_sha256", "schema_sha256"):
        if (not isinstance(manifest[field], str)
                or re.fullmatch(r"[a-f0-9]{64}", manifest[field]) is None):
            raise BootstrapError("Baseline checksum is invalid")
    try:
        sql_text = sql_path.read_text()
    except (OSError, UnicodeError):
        raise BootstrapError("Baseline SQL is unavailable") from None
    if (_sha256(sql_path) != manifest["sql_sha256"]
            or hashlib.sha256(_normalized_sql(sql_text).encode()).hexdigest() != manifest["schema_sha256"]
            or not isinstance(manifest["counts"], dict)
            or any(not isinstance(key, str) or type(value) is not int or value < 0
                   for key, value in manifest["counts"].items())
            or not isinstance(manifest["migrations"], dict)
            or any(not isinstance(name, str) or not isinstance(checksum, str)
                   or re.fullmatch(r"[a-f0-9]{64}", checksum) is None
                   for name, checksum in manifest["migrations"].items())
            or not isinstance(manifest["created_at"], str)):
        raise BootstrapError("Baseline manifest checksum or inventory is invalid")
    source_revision = manifest.get("source_revision")
    if source_revision is not None and (
            not isinstance(source_revision, str)
            or re.fullmatch(r"[a-f0-9]{7,64}", source_revision) is None):
        raise BootstrapError("Baseline source revision is invalid")
    return Baseline(path=sql_path, manifest=manifest)


def _apply_psql(dsn, path, *, allow_remote=False):
    safe, password, _ = _connection_parts(dsn, allow_remote=allow_remote)
    _run("psql", ["-X", "-v", "ON_ERROR_STOP=1", "--single-transaction", "-f", str(path), safe],
         env=_env(password))


def _history_insert(version, checksum, kind, source_revision):
    if (not isinstance(version, str)
            or re.fullmatch(r"[a-zA-Z0-9._-]{1,96}", version) is None
            or not isinstance(checksum, str)
            or re.fullmatch(r"[a-f0-9]{64}", checksum) is None
            or kind not in ("baseline", "delta")
            or (source_revision is not None
                and (not isinstance(source_revision, str)
                     or re.fullmatch(r"[a-f0-9]{7,64}", source_revision) is None))):
        raise BootstrapError("Invalid migration history value")
    revision = "NULL" if source_revision is None else f"'{source_revision}'"
    return f"""INSERT INTO django_candidate.candidate_schema_migrations
        (version, checksum, kind, source_revision, applied_at)
        VALUES ('{version}', '{checksum}', '{kind}', {revision}, now())
        ON CONFLICT (version) DO NOTHING;"""


def _apply_with_history(dsn, path, version, checksum, kind, source_revision,
                        *, create_metadata=False, allow_remote=False):
    path = Path(path)
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(
                mode="wb", prefix=".p26-migration-", suffix=".sql", delete=False) as script:
            temporary = Path(script.name)
            os.chmod(temporary, 0o600)
            script.write(b"SELECT pg_advisory_xact_lock(hashtextextended("
                         b"'lhie-candidate-python-migrations', 0));\n")
            if kind == "delta":
                script.write(
                    b"SELECT CASE WHEN EXISTS (SELECT 1 FROM "
                    b"django_candidate.candidate_schema_migrations WHERE version='"
                    + version.encode()
                    + b"') THEN 'true' ELSE 'false' END AS p26_already_applied \\gset\n"
                    b"\\if :p26_already_applied\n"
                    b"\\echo migration already applied\n"
                    b"\\else\n")
            if create_metadata:
                script.write(MIGRATION_HISTORY_DDL.encode() + b";\n")
            with path.open("rb") as source:
                shutil.copyfileobj(source, script)
            script.write(b"\n" + _history_insert(
                version, checksum, kind, source_revision).encode() + b"\n")
            if kind == "delta":
                script.write(b"\\endif\n")
        _apply_psql(dsn, temporary, allow_remote=allow_remote)
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)


def _history(dsn, *, allow_remote=False):
    safe, password, _ = _connection_parts(dsn, allow_remote=allow_remote)
    output = _run("psql", ["-X", "-v", "ON_ERROR_STOP=1", "-At", "-F", "\t", "-c",
                           "SELECT version,checksum,kind FROM django_candidate.candidate_schema_migrations ORDER BY version",
                           safe], env=_env(password))
    rows = []
    for line in output.splitlines():
        parts = line.split("\t")
        if len(parts) == 3:
            rows.append({"version": parts[0], "checksum": parts[1], "kind": parts[2]})
    return rows


def bootstrap(dsn, baseline_dir, *, allow_existing=False, allow_remote=False):
    baseline = load_baseline(baseline_dir)
    if not allow_existing and user_tables(dsn, allow_remote=allow_remote):
        raise BootstrapError("Fresh bootstrap requires an empty database")
    public_schema_sha256(dsn, allow_remote=allow_remote,
                         expected_major=baseline.manifest["postgres_major"])
    _apply_with_history(
        dsn, baseline.path, baseline.version, baseline.schema_sha256, "baseline",
        baseline.manifest.get("source_revision"), create_metadata=True,
        allow_remote=allow_remote)
    applied = upgrade(dsn, baseline_dir, allow_remote=allow_remote)
    report = verify(dsn, baseline_dir, allow_remote=allow_remote)
    if report["status"] != "ok":
        raise BootstrapError("Python bootstrap did not reproduce the recorded schema")
    return {"status": "ok", "version": baseline.version, "counts": report["counts"],
            "schema_sha256": report["schema_sha256"], "deltas": applied["applied"]}


def _delta_files(baseline_dir):
    directory = Path(baseline_dir) / MIGRATIONS_NAME
    if not directory.is_dir():
        return {}
    files = {}
    for path in sorted(directory.glob("*.sql")):
        match = DELTA_PATTERN.fullmatch(path.name)
        if match is None:
            raise BootstrapError("Unreviewed migration filename")
        version = match.group("version")
        if version in files:
            raise BootstrapError("Duplicate migration version")
        files[version] = path
    return files


def pending_deltas(baseline_dir, applied_versions):
    files = _delta_files(baseline_dir)
    return [(version, path) for version, path in sorted(files.items())
            if version not in applied_versions]


def _validated_history(baseline, baseline_dir, history):
    rows = {}
    for row in history:
        version = row.get("version")
        checksum = row.get("checksum")
        kind = row.get("kind")
        if (not isinstance(version, str)
                or re.fullmatch(r"[a-zA-Z0-9._-]{1,96}", version) is None
                or version in rows
                or not isinstance(checksum, str)
                or re.fullmatch(r"[a-f0-9]{64}", checksum) is None
                or kind not in ("baseline", "delta")):
            raise BootstrapError("Migration history is invalid")
        rows[version] = row
    baseline_rows = [row for row in rows.values() if row["kind"] == "baseline"]
    if (len(baseline_rows) != 1
            or baseline_rows[0]["version"] != baseline.version
            or baseline_rows[0]["checksum"] != baseline.schema_sha256):
        raise BootstrapError("Recorded baseline checksum does not match this repository")
    files = _delta_files(baseline_dir)
    for version, row in rows.items():
        if row["kind"] == "baseline":
            continue
        path = files.get(version)
        if path is None or row["checksum"] != _sha256(path):
            raise BootstrapError("Recorded migration checksum does not match this repository")
    return rows


def upgrade(dsn, baseline_dir, *, allow_remote=False):
    baseline = load_baseline(baseline_dir)
    public_schema_sha256(dsn, allow_remote=allow_remote,
                         expected_major=baseline.manifest["postgres_major"])
    history = _history(dsn, allow_remote=allow_remote)
    rows = _validated_history(baseline, baseline_dir, history)
    applied = []
    for version, path in pending_deltas(baseline_dir, set(rows)):
        checksum = _sha256(path)
        _apply_with_history(
            dsn, path, version, checksum, "delta", None, allow_remote=allow_remote)
        applied.append(version)
    _validated_history(baseline, baseline_dir, _history(dsn, allow_remote=allow_remote))
    return {"status": "ok", "applied": applied, "pending": 0}


def verify(dsn, baseline_dir, *, allow_remote=False):
    baseline = load_baseline(baseline_dir)
    history = _history(dsn, allow_remote=allow_remote)
    rows = _validated_history(baseline, baseline_dir, history)
    digest = public_schema_sha256(dsn, allow_remote=allow_remote,
                                  expected_major=baseline.manifest["postgres_major"])
    counts = catalog_counts(dsn, allow_remote=allow_remote)
    expected = baseline.manifest["counts"]
    differences = {key: {"expected": expected.get(key), "observed": counts[key]}
                   for key in counts if expected.get(key) is not None and expected[key] != counts[key]}
    pending = pending_deltas(baseline_dir, set(rows))
    report = {"status": "ok", "version": baseline.version,
              "schema_sha256": digest, "schema_matches": digest == baseline.schema_sha256,
              "counts": counts, "count_differences": differences,
              "pending_deltas": [version for version, _ in pending],
              "applied_deltas": [row["version"] for row in history if row["kind"] == "delta"]}
    if not report["schema_matches"] or differences or pending:
        report["status"] = "failed"
    return report
