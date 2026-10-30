#!/usr/bin/env python3
"""P02 synthetic-only acquisition/restore/sanitization rehearsal; never imports operator data."""
import argparse
import base64
import copy
from datetime import datetime, timedelta, timezone
import fcntl
import hashlib
import hmac
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys

from cryptography.exceptions import InvalidTag
from cryptography.hazmat.primitives import padding
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "migration_harness"))
import harness

TOOLS = Path(__file__).resolve().parent
STATE = harness.ROOT / ".local/migration-p02"
AAD = b"lhie-p02/synthetic-pg-dump/v1"
TABLES = {
    "customers": ("id", "email", "telegram_id", "password_hash", "created_at"),
    "credentials": ("id", "format", "secret", "identity_fingerprint", "state", "created_at"),
    "orders": ("id", "customer_id", "credential_id", "amount", "currency", "status", "provider_target", "created_at", "deadline"),
    "identities": ("id", "credential_id", "kind", "fingerprint"),
    "ledger": ("id", "customer_id", "order_id", "currency", "amount", "direction", "balance_after", "source", "created_at"),
    "accounts": ("customer_id", "currency", "balance"),
    "effects": ("id", "order_id", "state", "chat_id", "stable_key", "created_at"),
}
MUTABLE = {"customers": {"email", "telegram_id", "password_hash"},
           "credentials": {"secret", "identity_fingerprint"},
           "identities": {"fingerprint"}, "orders": {"provider_target"}, "effects": {"chat_id"}}


class Refused(Exception):
    pass


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode()


def digest(value):
    return hashlib.sha256(value).hexdigest()


def seal(data, key):
    nonce = secrets.token_bytes(12)
    return nonce + AESGCM(key).encrypt(nonce, data, AAD)


def unseal(data, key):
    try:
        return AESGCM(key).decrypt(data[:12], data[12:], AAD)
    except (InvalidTag, ValueError):
        raise Refused("archive authentication failed") from None


def encrypt(value, key):
    """Synthetic Laravel encrypted-string envelope; not a P03 compatibility claim."""
    iv = secrets.token_bytes(16)
    pad = padding.PKCS7(128).padder()
    padded = pad.update(value.encode()) + pad.finalize()
    enc = Cipher(algorithms.AES(key), modes.CBC(iv)).encryptor()
    iv64 = base64.b64encode(iv).decode()
    value64 = base64.b64encode(enc.update(padded) + enc.finalize()).decode()
    mac = hmac.new(key, (iv64 + value64).encode(), "sha256").hexdigest()
    return base64.b64encode(canonical(dict(iv=iv64, value=value64, mac=mac, tag=""))).decode()


def decrypt(value, key):
    try:
        obj = json.loads(base64.b64decode(value, validate=True))
        if set(obj) != {"iv", "value", "mac", "tag"} or obj["tag"] != "":
            raise ValueError()
        mac = hmac.new(key, (obj["iv"] + obj["value"]).encode(), "sha256").hexdigest()
        if not hmac.compare_digest(mac, obj["mac"]):
            raise ValueError()
        dec = Cipher(algorithms.AES(key), modes.CBC(base64.b64decode(obj["iv"], validate=True))).decryptor()
        padded = dec.update(base64.b64decode(obj["value"], validate=True)) + dec.finalize()
        unpad = padding.PKCS7(128).unpadder()
        return (unpad.update(padded) + unpad.finalize()).decode()
    except (ValueError, KeyError, TypeError, UnicodeError):
        raise Refused("credential authentication or format failed") from None


def fingerprint(email, key):
    return hmac.new(key, email.strip().lower().encode(), "sha256").hexdigest()


def seed(key, fpkey):
    stamp = "2026-01-01T00:00:00"
    data = {name: [] for name in TABLES}
    for n in (1, 2):
        data["customers"].append(dict(id=n, email=f"original{n}@example.invalid", telegram_id=9000+n,
                                      password_hash="synthetic-unusable-source-hash", created_at=stamp))
    for n, fmt in ((1, "legacy"), (2, "structured")):
        email = f"Original{n}@example.invalid"
        secret = email + "|source-password" if fmt == "legacy" else canonical({"email": email, "password": "source-password", "auth_token": "source-token", "tutorial": "https://source.invalid/tutorial"}).decode()
        fp = fingerprint(email, fpkey)
        data["credentials"].append(dict(id=n, format=fmt, secret=encrypt(secret, key), identity_fingerprint=fp,
                                        state="allocated" if n == 1 else "retired", created_at=stamp))
        data["identities"].append(dict(id=n, credential_id=n, kind="email", fingerprint=fp))
    for n, status in enumerate(("paid", "pending", "creation_unknown", "late_review"), 1):
        data["orders"].append(dict(id=n, customer_id=1 if n < 3 else 2, credential_id=n if n < 3 else None,
                                   amount=10000 if n < 3 else 1000001, currency="IDR" if n < 3 else "USDT",
                                   status=status, provider_target="https://provider.invalid/receiver", created_at=stamp,
                                   deadline="2026-01-01T00:10:00"))
        data["effects"].append(dict(id=n, order_id=n, state="unknown" if n == 3 else "pending",
                                    chat_id=9001 if n < 3 else 9002, stable_key=f"effect:{n}", created_at=stamp))
    for n, (customer, currency, amount, direction, balance, order) in enumerate(
            ((1, "IDR", 20000, 1, 20000, None), (1, "IDR", 10000, -1, 10000, 1),
             (2, "USDT", 1000001, 1, 1000001, None)), 1):
        data["ledger"].append(dict(id=n, customer_id=customer, order_id=order, currency=currency, amount=amount,
                                   direction=direction, balance_after=balance, source=f"economic:{n}", created_at=stamp))
    data["accounts"] = [dict(customer_id=1, currency="IDR", balance=10000), dict(customer_id=2, currency="USDT", balance=1000001)]
    return data


def validate_shape(data):
    if set(data) != set(TABLES):
        raise Refused("unclassified table")
    for table, columns in TABLES.items():
        if any(set(row) != set(columns) for row in data[table]):
            raise Refused("unclassified field")


def audit(data, key, fpkey):
    validate_shape(data)
    identities = {r["credential_id"]: r for r in data["identities"]}
    for row in data["credentials"]:
        plain = decrypt(row["secret"], key)
        if row["format"] == "legacy":
            email, password = plain.split("|")
        elif row["format"] == "structured":
            fields = json.loads(plain)
            if set(fields) != {"email", "password", "auth_token", "tutorial"}:
                raise Refused("unclassified structured credential field")
            email, password = fields["email"], fields["password"]
        else:
            raise Refused("unsupported credential format")
        fp = fingerprint(email, fpkey)
        if not password or fp != row["identity_fingerprint"] or fp != identities[row["id"]]["fingerprint"]:
            raise Refused("credential identity divergence")
    balances = {}
    for row in sorted(data["ledger"], key=lambda r: r["id"]):
        identity = (row["customer_id"], row["currency"])
        balances[identity] = balances.get(identity, 0) + row["direction"] * row["amount"]
        if balances[identity] != row["balance_after"]:
            raise Refused("ledger chronology divergence")
    if balances != {(r["customer_id"], r["currency"]): r["balance"] for r in data["accounts"]}:
        raise Refused("projection divergence")
    return {table: len(rows) for table, rows in data.items()}


def sanitize(data, key, fpkey, derived_key, derived_fpkey):
    audit(data, key, fpkey)
    result = copy.deepcopy(data)
    for row in result["customers"]:
        row.update(email=f"customer-{row['id']}@example.invalid", telegram_id=row["id"], password_hash="!p02-disabled-login")
    for row in result["credentials"]:
        email = f"credential-{row['id']}@example.invalid"
        plain = email + "|synthetic-disabled-password" if row["format"] == "legacy" else canonical({
            "email": email, "password": "synthetic-disabled-password", "auth_token": f"synthetic-token-{row['id']}",
            "tutorial": "https://example.invalid/tutorial"}).decode()
        row.update(secret=encrypt(plain, derived_key), identity_fingerprint=fingerprint(email, derived_fpkey))
    by_id = {r["id"]: r for r in result["credentials"]}
    for row in result["identities"]:
        row["fingerprint"] = by_id[row["credential_id"]]["identity_fingerprint"]
    for row in result["orders"]:
        row["provider_target"] = "http://127.0.0.1:8025/denied"
    for row in result["effects"]:
        row["chat_id"] = 1
    audit(result, derived_key, derived_fpkey)
    if invariant(data) != invariant(result):
        raise Refused("historical invariant changed")
    return result


def invariant(data):
    return {t: [{k: v for k, v in r.items() if k not in MUTABLE.get(t, set())} for r in rows]
            for t, rows in data.items()}


def quote(value):
    if value is None:
        return "NULL"
    if type(value) is int:
        return str(value)
    if not isinstance(value, str):
        raise Refused("unsupported SQL fixture value")
    return "'" + value.replace("'", "''") + "'"


def inserts(data):
    validate_shape(data)
    statements = ["SET LOCAL standard_conforming_strings=on; SET LOCAL ROLE p02_runtime;"]
    for table, columns in TABLES.items():
        for row in data[table]:
            statements.append(f"INSERT INTO fixture.{table} ({','.join(columns)}) VALUES ({','.join(quote(row[c]) for c in columns)});")
    statements.append("RESET ROLE; SELECT setval('fixture.ledger_id_seq',3,true);")
    return "\n".join(statements)


class Database:
    def __init__(self, container):
        self.container = container

    def command(self, args, data=None):
        result = subprocess.run(["docker", "exec", "-i", self.container, *args], input=data,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                env={"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "HOME": os.environ["HOME"]})
        if result.returncode:
            raise Refused("disposable database operation failed; details suppressed")
        return result.stdout

    def sql(self, database, text):
        return self.command(["psql", "-X", "-qAt", "-v", "ON_ERROR_STOP=1", "-U", "harness", "-d", database], text.encode()).decode().strip()

    def dump(self, database, schema=False):
        return self.command(["pg_dump", "-U", "harness", "-d", database, "-Fc", *(["--schema-only"] if schema else [])])

    def restore(self, database, archive):
        self.command(["pg_restore", "-U", "harness", "-d", database, "--single-transaction", "--exit-on-error"], archive)

    def rows(self, database):
        observed = self.sql(database, "SELECT table_name,column_name FROM information_schema.columns WHERE table_schema='fixture' ORDER BY table_name,ordinal_position")
        expected = "\n".join(f"{table}|{column}" for table in sorted(TABLES) for column in TABLES[table])
        if observed != expected:
            raise Refused("unclassified database schema")
        result = {}
        for table in TABLES:
            order = "customer_id,currency" if table == "accounts" else "id"
            rows = self.sql(database, f"SELECT row_to_json(t) FROM (SELECT * FROM fixture.{table} ORDER BY {order}) t")
            result[table] = [json.loads(row) for row in rows.splitlines()]
        return result

    def guards(self, database):
        return self.sql(database, """
SELECT json_build_object(
 'triggers', (SELECT json_agg(x) FROM (SELECT tgname,tgenabled,pg_get_triggerdef(oid) AS definition FROM pg_trigger WHERE tgrelid IN (SELECT oid FROM pg_class WHERE relnamespace='fixture'::regnamespace) AND NOT tgisinternal ORDER BY tgname) x),
 'constraints', (SELECT json_agg(x) FROM (SELECT conname,pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE connamespace='fixture'::regnamespace ORDER BY conname) x),
 'grants', (SELECT json_agg(x) FROM (SELECT table_name,grantee,privilege_type FROM information_schema.table_privileges WHERE table_schema='fixture' ORDER BY table_name,grantee,privilege_type) x),
 'indexes', (SELECT json_agg(x) FROM (SELECT indexname,indexdef FROM pg_indexes WHERE schemaname='fixture' ORDER BY indexname) x),
 'functions', (SELECT json_agg(x) FROM (SELECT proname,pg_get_functiondef(oid) AS definition FROM pg_proc WHERE pronamespace='fixture'::regnamespace ORDER BY proname) x));
""")


def mapping_file_id(entry, target_bot, asset_sha256):
    if (entry.get("target_bot") != target_bot or target_bot != "synthetic-testing-bot"
            or entry.get("asset_sha256") != asset_sha256 or entry.get("status") != "uploaded"
            or not entry.get("target_file_id") or entry.get("target_file_id") == entry.get("source_file_id")):
        raise Refused("testing-bot asset reupload mapping required")
    return entry["target_file_id"]


def private_write(path, data, mode=0o600):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, mode)
    with os.fdopen(fd, "wb") as stream:
        stream.write(data)


def admission():
    harness.docker_admission()
    directory = harness.STATE / "candidate"
    config = harness.validate(json.loads((directory / "config.json").read_text()))
    if config != harness.default("candidate") or not (directory / "quarantine").is_file():
        raise Refused("requires default candidate fake-mode sticky quarantine")
    harness.owned_volumes("candidate")
    ids = harness.run(["docker", "ps", "-q", "--filter", "label=lhie.p01=disposable"], True).split()
    containers = json.loads(harness.run(["docker", "inspect", *ids], True)) if ids else []
    services = {c["Config"]["Labels"].get("com.docker.compose.service"): c for c in containers}
    if (len(containers) != 3 or set(services) != {"boundary", "database", "redis"}
            or any(c["Config"]["Labels"].get("lhie.p01.stack") != "candidate" for c in containers)
            or services["boundary"]["State"].get("Health", {}).get("Status") != "healthy"):
        raise Refused("quarantine only: no app, workers, alternate stack or unhealthy firewall")
    return Database(services["database"]["Id"])


def expect_refused(action):
    try:
        action()
    except Refused:
        return
    raise Refused("negative safety probe unexpectedly succeeded")


def rehearse():
    os.umask(0o077)
    db = admission()
    # A second rehearsal must reset P01 explicitly; never drop an existing database.
    if db.sql("disposable", "SELECT count(*) FROM pg_database WHERE datname IN ('p02_source','p02_raw','p02_derived')") != "0":
        raise Refused("existing P02 databases; explicit disposable P01 reset required")
    STATE.mkdir(mode=0o700, parents=True, exist_ok=True)
    run_id = "synthetic-" + secrets.token_hex(8)
    directory = STATE / run_id
    directory.mkdir(mode=0o700)
    keys = STATE / (run_id + "-keys")
    keys.mkdir(mode=0o700)
    key, fpkey, derived_key, derived_fpkey, backup_key = [secrets.token_bytes(32) for _ in range(5)]
    private_write(keys / "source.json", canonical({k: base64.b64encode(v).decode() for k, v in
                  dict(source=key, source_fingerprint=fpkey, archive=backup_key).items()}))
    private_write(keys / "derived.json", canonical({k: base64.b64encode(v).decode() for k, v in
                  dict(derived=derived_key, derived_fingerprint=derived_fpkey).items()}))
    for name in ("p02_source", "p02_raw", "p02_derived"):
        db.sql("disposable", f"CREATE DATABASE {name}")
    db.sql("p02_source", (TOOLS / "schema.sql").read_text())
    db.sql("p02_source", "BEGIN;\n" + inserts(seed(key, fpkey)) + "\nCOMMIT;")
    source = db.rows("p02_source")
    audit(source, key, fpkey)
    archive = seal(db.dump("p02_source"), backup_key)
    private_write(directory / "source.dump.aesgcm", archive, 0o400)
    raw_hash = digest(archive)
    # Authentication happens before the first restore byte is passed to PostgreSQL.
    expect_refused(lambda: unseal(archive[:-1] + bytes([archive[-1] ^ 1]), backup_key))
    expect_refused(lambda: unseal(archive, secrets.token_bytes(32)))
    db.restore("p02_raw", unseal((directory / "source.dump.aesgcm").read_bytes(), backup_key))
    db.sql("disposable", "ALTER DATABASE p02_raw SET default_transaction_read_only=on")
    if db.sql("p02_raw", "SHOW default_transaction_read_only") != "on":
        raise Refused("raw database not read-only")
    raw = db.rows("p02_raw")
    if raw != source:
        raise Refused("raw restoration mismatch")
    before = audit(raw, key, fpkey)
    schema = db.dump("p02_raw", schema=True)
    db.restore("p02_derived", schema)
    if db.guards("p02_raw") != db.guards("p02_derived"):
        raise Refused("restored guards/grants mismatch")
    for role in ("p02_runtime", "p02_migrator"):
        if db.sql("p02_derived", f"SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication FROM pg_roles WHERE rolname='{role}'") != "f":
            raise Refused("unsafe fixture role")
    expect_refused(lambda: db.sql("p02_derived", "SET ROLE p02_runtime; UPDATE fixture.ledger SET amount=1"))
    broken = copy.deepcopy(raw)
    broken["credentials"][0]["identity_fingerprint"] = "0" * 64
    expect_refused(lambda: sanitize(broken, key, fpkey, derived_key, derived_fpkey))
    broken = copy.deepcopy(raw)
    broken["customers"][0]["unclassified"] = "secret"
    expect_refused(lambda: sanitize(broken, key, fpkey, derived_key, derived_fpkey))
    expect_refused(lambda: sanitize(raw, secrets.token_bytes(32), fpkey, derived_key, derived_fpkey))
    clean = sanitize(raw, key, fpkey, derived_key, derived_fpkey)
    invalid = copy.deepcopy(clean)
    invalid["orders"][0]["customer_id"] = 999
    expect_refused(lambda: db.sql("p02_derived", "BEGIN;\n" + inserts(invalid) + "\nCOMMIT;"))
    if any(db.rows("p02_derived").values()):
        raise Refused("failed sanitization did not roll back")
    # Database failures roll back; any failed post-load audit retains sticky quarantine.
    db.sql("p02_derived", "BEGIN;\n" + inserts(clean) + "\nCOMMIT;")
    actual = db.rows("p02_derived")
    after = audit(actual, derived_key, derived_fpkey)
    if before != after or invariant(raw) != invariant(actual):
        raise Refused("persisted historical invariant changed")
    expect_refused(lambda: db.sql("p02_derived", "UPDATE fixture.ledger SET amount=1 WHERE id=1"))
    expect_refused(lambda: db.sql("p02_derived", "DELETE FROM fixture.identities WHERE id=1"))
    if db.guards("p02_raw") != db.guards("p02_derived") or db.rows("p02_raw") != raw:
        raise Refused("raw source or schema guards changed")
    sequence = db.sql("p02_derived", "SELECT last_value,is_called FROM fixture.ledger_id_seq")
    if sequence != db.sql("p02_raw", "SELECT last_value,is_called FROM fixture.ledger_id_seq"):
        raise Refused("sequence restoration mismatch")
    assets = []
    for name, classification, content in (("banner.txt", "public-test", b"synthetic test banner"),
                                           ("receipt.txt", "customer-private-redacted", b"synthetic redacted receipt")):
        private_write(directory / name, content)
        assets.append(dict(path=name, classification=classification, asset_sha256=digest(content), bytes=len(content),
                           source_bot="synthetic-source-bot", target_bot="synthetic-testing-bot", source_file_id="synthetic-old-"+name,
                           target_file_id=None, status="requires-reupload"))
    expect_refused(lambda: mapping_file_id(assets[0], "synthetic-testing-bot", assets[0]["asset_sha256"]))
    uploaded = dict(assets[0], status="uploaded", target_file_id="synthetic-new-banner")
    if mapping_file_id(uploaded, "synthetic-testing-bot", uploaded["asset_sha256"]) != "synthetic-new-banner":
        raise Refused("synthetic file mapping mismatch")
    expect_refused(lambda: mapping_file_id(uploaded, "another-bot", uploaded["asset_sha256"]))
    private_write(directory / "files.json", canonical(assets))
    derived_archive = db.dump("p02_derived")
    private_write(directory / "derived.dump", derived_archive, 0o400)
    if digest((directory / "source.dump.aesgcm").read_bytes()) != raw_hash:
        raise Refused("original archive changed")
    now = datetime.now(timezone.utc)
    manifest = dict(version=1, scope="synthetic-only-not-production-compatible", dataset=run_id,
        revision=harness.run(["git", "rev-parse", "HEAD"], True).strip(),
        tool_sha256={p.name: digest(p.read_bytes()) for p in (Path(__file__), TOOLS / "schema.sql", TOOLS / "requirements.txt")},
        postgres=db.sql("p02_raw", "SHOW server_version"), python=sys.version.split()[0],
        database_settings=db.sql("p02_raw", "SELECT json_build_object('timezone',current_setting('TimeZone'),'encoding',pg_encoding_to_char(encoding),'collation',datcollate,'ctype',datctype) FROM pg_database WHERE datname=current_database()"),
        extensions=db.sql("p02_raw", "SELECT json_agg(x) FROM (SELECT extname,extversion FROM pg_extension ORDER BY extname) x"),
        cryptography=__import__("cryptography").__version__, created_at=now.isoformat(),
        provenance=dict(source="generated mixed legacy/structured fixture", source_archive_sha256=raw_hash,
                        derived_archive_sha256=digest(derived_archive), files_sha256=digest(canonical(assets))),
        retention=dict(raw_expires_at=(now+timedelta(days=7)).isoformat(), derived_expires_at=(now+timedelta(days=30)).isoformat(),
                       owner="local operator", action="P01 reset DB volumes after rehearsal; at raw expiry delete source.dump.aesgcm and source.json; at derived expiry delete this run and remaining key directory; record deletion"),
        cardinality=after, invariant_sha256=digest(canonical(invariant(actual))), schema_guard_sha256=digest(db.guards("p02_derived").encode()),
        sequence=sequence, status="audited-quarantine-not-released", workers=0, transport="P01 fake default-deny",
        negative_probes=["archive-tamper", "wrong-archive-key", "wrong-field-key", "fingerprint-divergence", "unknown-field", "FK-rollback", "runtime-update-denied", "ledger-trigger", "identity-trigger", "unmapped-file", "cross-bot-file"],
        limits=["synthetic schema only; H0 actual source/schema/files/keys not acquired", "P03 owns PHP/Python crypto compatibility", "no bot upload; pending reupload remains blocked", "no app release; sticky quarantine remains", "not money/stock concurrency evidence", "trusted local Docker administrator; DB owner can override read-only policy", "host driver/crypto memory outside P01 cgroup budget"])
    private_write(directory / "manifest.json", canonical(manifest))
    private_write(directory / "manifest.sha256", (digest(canonical(manifest))+"\n").encode(), 0o400)
    return directory / "manifest.json"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.parse_args()
    harness.STATE.mkdir(parents=True, exist_ok=True)
    os.umask(0o077)
    with (harness.STATE / "lifecycle.lock").open("w") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        print(rehearse())


if __name__ == "__main__":
    try:
        main()
    except (Refused, harness.Refused):
        print("P02 refused; quarantine retained; no dataset admission (details suppressed)", file=sys.stderr)
        sys.exit(1)
    except (OSError, ValueError, TypeError, KeyError):
        print("P02 refused; invalid local material; quarantine retained (details suppressed)", file=sys.stderr)
        sys.exit(1)
