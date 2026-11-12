"""Disposable PostgreSQL + files + keys backup and restore.

The backup keeps database ownership, ACLs, extensions, functions and triggers
inside a custom-format dump. Roles are captured without passwords; secret
material travels only inside the encrypted archive. Restore refuses a non-empty
target, then verifies schema, ACL and protected financial row counts against
the manifest. Role passwords remain operator-managed.
"""

from contextlib import contextmanager
from datetime import datetime, timezone
import hashlib
import json
import os
import re
from pathlib import Path
import shutil
import stat
import subprocess
import tarfile
import tempfile

from .bootstrap import (_connection_parts, _env, catalog_counts,
                        parse_dsn, public_schema_sha256, user_tables)

PROTECTED_COUNTS = (
    "orders", "payments", "store_balance_entries", "customer_usdt_balance_entries",
    "external_money_receipts", "external_money_receipt_reservations",
    "telegram_outbound_effects", "manual_fulfillment_reviews", "audit_logs",
)
UNKNOWN_EFFECT_STATES = ("pending", "sending", "failed", "unknown", "manual_review")
MANIFEST = "manifest.json"
MAX_FILE_BYTES = 512 * 1024 * 1024


class BackupError(RuntimeError):
    pass


def _sha256(path):
    digest = hashlib.sha256()
    with open(path, "rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _safe_relative(value):
    path = Path(value)
    if path.is_absolute() or not path.parts or any(part in ("", ".", "..") for part in path.parts):
        raise BackupError("Unsafe archive path")
    return path


def _private_directory(path):
    path.mkdir(mode=0o700, parents=True)
    os.chmod(path, 0o700)


def _copy_regular(source, target):
    source = Path(source)
    info = source.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_size > MAX_FILE_BYTES:
        raise BackupError("Only bounded regular files are admitted")
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(source, target)
    os.chmod(target, 0o600)


def _file_manifest(root):
    rows = []
    for path in sorted(root.rglob("*")):
        if path.is_dir():
            continue
        info = path.lstat()
        if not stat.S_ISREG(info.st_mode):
            raise BackupError("Archive contains a non-regular file")
        rows.append({"path": str(path.relative_to(root)), "bytes": info.st_size,
                     "sha256": _sha256(path), "mode": stat.S_IMODE(info.st_mode)})
    return rows


def _run(command, *, env, timeout=300):
    try:
        result = subprocess.run(command, env=env, capture_output=True, timeout=timeout)
    except (OSError, subprocess.TimeoutExpired):
        raise BackupError("Backup tool is unavailable or timed out") from None
    if result.returncode:
        raise BackupError("Backup tool failed")
    return result.stdout


def _gpg_encrypt(source, destination, passphrase):
    if not isinstance(passphrase, str) or not 12 <= len(passphrase) <= 256:
        raise BackupError("Backup encryption requires a bounded passphrase")
    with tempfile.NamedTemporaryFile("w", prefix=".p26-pass-", dir=destination.parent,
                                     delete=False) as stream:
        passphrase_path = Path(stream.name)
        stream.write(passphrase)
        stream.write("\n")
    os.chmod(passphrase_path, 0o600)
    try:
        _run(["gpg", "--batch", "--yes", "--pinentry-mode", "loopback",
              "--passphrase-file", str(passphrase_path), "--symmetric",
              "--cipher-algo", "AES256", "--output", str(destination), str(source)],
             env={"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "HOME": os.environ.get("HOME", "/tmp")})
    finally:
        passphrase_path.unlink(missing_ok=True)
    os.chmod(destination, 0o600)


def _gpg_decrypt(source, destination, passphrase):
    if not isinstance(passphrase, str) or not 12 <= len(passphrase) <= 256:
        raise BackupError("Backup decryption requires a bounded passphrase")
    with tempfile.NamedTemporaryFile("w", prefix=".p26-pass-", dir=destination.parent,
                                     delete=False) as stream:
        passphrase_path = Path(stream.name)
        stream.write(passphrase)
        stream.write("\n")
    os.chmod(passphrase_path, 0o600)
    try:
        _run(["gpg", "--batch", "--yes", "--pinentry-mode", "loopback",
              "--passphrase-file", str(passphrase_path), "--decrypt",
              "--output", str(destination), str(source)],
             env={"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "HOME": os.environ.get("HOME", "/tmp")})
    finally:
        passphrase_path.unlink(missing_ok=True)


def _pg_dump(dsn, destination, *, allow_remote=False):
    safe, password, _ = _connection_parts(dsn, allow_remote=allow_remote)
    _run(["pg_dump", "--format=custom", "--no-password", "--lock-wait-timeout=5s",
          "--file", str(destination), safe], env=_env(password))
    if not destination.is_file() or destination.stat().st_size == 0:
        raise BackupError("Database dump is empty")


def _pg_dump_roles(dsn, destination, *, allow_remote=False):
    safe, password, _ = _connection_parts(dsn, allow_remote=allow_remote)
    output = _run(["pg_dumpall", "--roles-only", "--no-role-passwords", "--no-password", "--database", safe],
                  env=_env(password))
    destination.write_bytes(output)
    os.chmod(destination, 0o600)
    if not output.strip():
        raise BackupError("Role dump is empty")


def _query(dsn, sql, params=(), *, allow_remote=False):
    import psycopg
    parsed = parse_dsn(dsn, allow_remote=allow_remote)
    with psycopg.connect(dsn, connect_timeout=3) as connection:
        with connection.cursor() as cursor:
            cursor.execute(sql, params)
            return cursor.fetchall()


def financial_counts(dsn, *, allow_remote=False):
    counts = {}
    for table in PROTECTED_COUNTS:
        rows = _query(dsn, f"SELECT count(*) FROM public.{table}", allow_remote=allow_remote)
        counts[table] = int(rows[0][0])
    statuses = ",".join(f"'{state}'" for state in UNKNOWN_EFFECT_STATES)
    rows = _query(dsn, f"""SELECT count(*) FROM public.telegram_outbound_effects
        WHERE state IN ({statuses})""", allow_remote=allow_remote)
    counts["unresolved_effects"] = int(rows[0][0])
    return counts


def grants_sha256(dsn, *, allow_remote=False):
    queries = (
        ("database", """SELECT pg_get_userbyid(datdba),COALESCE(datacl::text,'')
            FROM pg_database WHERE datname=current_database()"""),
        ("schema", """SELECT nspname,pg_get_userbyid(nspowner),COALESCE(nspacl::text,'')
            FROM pg_namespace
            WHERE nspname NOT IN ('pg_catalog','information_schema') AND left(nspname,3) <> 'pg_'
            ORDER BY nspname"""),
        ("relation", """SELECT n.nspname,c.relname,c.relkind,pg_get_userbyid(c.relowner),
                COALESCE(c.relacl::text,'')
            FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname NOT IN ('pg_catalog','information_schema') AND left(n.nspname,3) <> 'pg_'
            ORDER BY n.nspname,c.relname,c.relkind"""),
        ("routine", """SELECT n.nspname,p.proname,pg_get_function_identity_arguments(p.oid),
                pg_get_userbyid(p.proowner),COALESCE(p.proacl::text,'')
            FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
            WHERE n.nspname NOT IN ('pg_catalog','information_schema') AND left(n.nspname,3) <> 'pg_'
            ORDER BY n.nspname,p.proname,pg_get_function_identity_arguments(p.oid)"""),
        ("type", """SELECT n.nspname,t.typname,pg_get_userbyid(t.typowner),COALESCE(t.typacl::text,'')
            FROM pg_type t JOIN pg_namespace n ON n.oid=t.typnamespace
            WHERE n.nspname NOT IN ('pg_catalog','information_schema') AND left(n.nspname,3) <> 'pg_'
            ORDER BY n.nspname,t.typname"""),
        ("default", """SELECT pg_get_userbyid(d.defaclrole),
                COALESCE((SELECT nspname FROM pg_namespace WHERE oid=d.defaclnamespace),''),
                d.defaclobjtype,d.defaclacl::text
            FROM pg_default_acl d
            ORDER BY d.defaclrole,d.defaclnamespace,d.defaclobjtype"""),
        ("large_object", """SELECT oid,pg_get_userbyid(lomowner),COALESCE(lomacl::text,'')
            FROM pg_largeobject_metadata ORDER BY oid"""),
        ("language", """SELECT lanname,pg_get_userbyid(lanowner),COALESCE(lanacl::text,'')
            FROM pg_language ORDER BY lanname"""),
        ("foreign_data_wrapper", """SELECT fdwname,pg_get_userbyid(fdwowner),COALESCE(fdwacl::text,'')
            FROM pg_foreign_data_wrapper ORDER BY fdwname"""),
        ("foreign_server", """SELECT srvname,pg_get_userbyid(srvowner),COALESCE(srvacl::text,'')
            FROM pg_foreign_server ORDER BY srvname"""),
        ("membership", """SELECT granted.rolname,member.rolname,grantor.rolname,m.admin_option
            FROM pg_auth_members m
            JOIN pg_roles granted ON granted.oid=m.roleid
            JOIN pg_roles member ON member.oid=m.member
            JOIN pg_roles grantor ON grantor.oid=m.grantor
            ORDER BY granted.rolname,member.rolname,grantor.rolname"""),
        ("role", """SELECT rolname,rolsuper,rolinherit,rolcreaterole,rolcreatedb,
                rolcanlogin,rolreplication,rolconnlimit,rolvaliduntil,rolbypassrls,
                COALESCE(rolconfig::text,'')
            FROM pg_roles ORDER BY rolname"""),
    )
    digest = hashlib.sha256()
    for kind, sql in queries:
        for row in _query(dsn, sql, allow_remote=allow_remote):
            digest.update((kind + "\t" + "\t".join(
                "" if value is None else str(value) for value in row) + "\n").encode())
    return digest.hexdigest()


def create_backup(dsn, destination, *, passphrase, files=(), keys=(), allow_remote=False):
    """Create one encrypted archive; no secret is written outside it."""
    destination = Path(destination)
    if destination.exists():
        raise BackupError("Refusing to overwrite an existing backup")
    destination.parent.mkdir(parents=True, exist_ok=True)
    temp = Path(tempfile.mkdtemp(prefix=".p26-backup-", dir=destination.parent))
    os.chmod(temp, 0o700)
    try:
        database = temp / "database.dump"
        roles = temp / "roles.sql"
        _pg_dump(dsn, database, allow_remote=allow_remote)
        _pg_dump_roles(dsn, roles, allow_remote=allow_remote)
        files_root = temp / "files"
        keys_root = temp / "keys"
        _private_directory(files_root)
        _private_directory(keys_root)
        for source, archive_path in files:
            relative = _safe_relative(archive_path)
            _copy_regular(source, files_root / relative)
        for source, archive_path in keys:
            relative = _safe_relative(archive_path)
            _copy_regular(source, keys_root / relative)
        manifest = {
            "schema": "python-operations-backup-v1",
            "created_at": datetime.now(timezone.utc).isoformat(),
            "database": {"bytes": database.stat().st_size, "sha256": _sha256(database)},
            "roles": {"bytes": roles.stat().st_size, "sha256": _sha256(roles)},
            "files": _file_manifest(files_root),
            "keys": _file_manifest(keys_root),
            "counts": financial_counts(dsn, allow_remote=allow_remote),
            "catalog": catalog_counts(dsn, allow_remote=allow_remote),
            "schema_sha256": public_schema_sha256(dsn, allow_remote=allow_remote),
            "grants_sha256": grants_sha256(dsn, allow_remote=allow_remote),
            "limits": ["roles are dumped without passwords",
                       "no production endpoint is admitted without explicit override"],
        }
        (temp / MANIFEST).write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n")
        os.chmod(temp / MANIFEST, 0o600)
        archive = temp / "backup.tar"
        with tarfile.open(archive, "w") as tar:
            for name in ("manifest.json", "database.dump", "roles.sql", "files", "keys"):
                path = temp / name
                tar.add(path, arcname=name, recursive=True)
        os.chmod(archive, 0o600)
        _gpg_encrypt(archive, destination, passphrase)
        return {"status": "ok", "path": str(destination), "bytes": destination.stat().st_size,
                "counts": manifest["counts"], "catalog": manifest["catalog"],
                "schema_sha256": manifest["schema_sha256"],
                "grants_sha256": manifest["grants_sha256"]}
    finally:
        shutil.rmtree(temp, ignore_errors=True)


@contextmanager
def _extracted(archive, passphrase):
    temp = Path(tempfile.mkdtemp(prefix=".p26-restore-", dir=archive.parent))
    os.chmod(temp, 0o700)
    try:
        clear = temp / "backup.tar"
        _gpg_decrypt(archive, clear, passphrase)
        root = temp / "payload"
        root.mkdir(mode=0o700)
        with tarfile.open(clear, "r:") as tar:
            members = tar.getmembers()
            seen = set()
            for member in members:
                try:
                    relative = _safe_relative(member.name)
                except (TypeError, ValueError, BackupError):
                    raise BackupError("Unsafe archive member") from None
                if relative.parts[0] not in {
                        MANIFEST, "database.dump", "roles.sql", "files", "keys"}:
                    raise BackupError("Unexpected archive member")
                normalized = relative.as_posix()
                if normalized in seen:
                    raise BackupError("Duplicate archive member")
                seen.add(normalized)
                if member.issym() or member.islnk() or not (member.isfile() or member.isdir()):
                    raise BackupError("Archive member type is refused")
                if member.isfile() and member.size > MAX_FILE_BYTES:
                    raise BackupError("Archive member is too large")
                if (relative.parts[0] in {MANIFEST, "database.dump", "roles.sql"}
                        and len(relative.parts) != 1):
                    raise BackupError("Invalid archive root member")
            tar.extractall(root, filter="data")
        manifest_path = root / MANIFEST
        try:
            manifest = json.loads(manifest_path.read_text())
        except (OSError, ValueError, UnicodeError):
            raise BackupError("Backup manifest is unavailable") from None
        if not isinstance(manifest, dict) or manifest.get("schema") != "python-operations-backup-v1":
            raise BackupError("Backup manifest schema is unsupported")
        for key, filename in (("database", "database.dump"), ("roles", "roles.sql")):
            expected = manifest.get(key)
            path = root / filename
            if (not isinstance(expected, dict)
                    or set(expected) != {"bytes", "sha256"}
                    or type(expected["bytes"]) is not int or expected["bytes"] < 0
                    or not isinstance(expected["sha256"], str)
                    or re.fullmatch(r"[a-f0-9]{64}", expected["sha256"]) is None
                    or not path.is_file() or path.is_symlink()
                    or path.stat().st_size != expected["bytes"]
                    or _sha256(path) != expected["sha256"]):
                raise BackupError("Backup payload checksum mismatch")
        for name in ("schema_sha256", "grants_sha256"):
            if (not isinstance(manifest.get(name), str)
                    or re.fullmatch(r"[a-f0-9]{64}", manifest[name]) is None):
                raise BackupError("Backup manifest checksum is invalid")
        if not isinstance(manifest.get("counts"), dict) or not isinstance(manifest.get("catalog"), dict):
            raise BackupError("Backup manifest inventory is invalid")
        for name in ("files", "keys"):
            entries = manifest.get(name)
            sidecar = root / name
            if not isinstance(entries, list) or not sidecar.is_dir() or sidecar.is_symlink():
                raise BackupError("Backup sidecar manifest is invalid")
            for entry in entries:
                if (not isinstance(entry, dict)
                        or set(entry) != {"path", "bytes", "sha256", "mode"}
                        or type(entry["bytes"]) is not int or not 0 <= entry["bytes"] <= MAX_FILE_BYTES
                        or type(entry["mode"]) is not int
                        or not isinstance(entry["sha256"], str)
                        or re.fullmatch(r"[a-f0-9]{64}", entry["sha256"]) is None):
                    raise BackupError("Backup sidecar manifest is invalid")
                _safe_relative(entry["path"])
            if _file_manifest(sidecar) != entries:
                raise BackupError("Backup sidecar checksum mismatch")
        yield root, manifest
    finally:
        shutil.rmtree(temp, ignore_errors=True)


def _restore_roles(dsn, path, *, allow_remote=False):
    safe, password, _ = _connection_parts(dsn, allow_remote=allow_remote)
    source = Path(path)
    try:
        text = source.read_text()
    except (OSError, UnicodeError):
        raise BackupError("Role dump is unavailable") from None
    pattern = re.compile(
        r'(?im)^(?P<indent>[ \t]*)(?P<statement>CREATE ROLE[ \t]+'
        r'(?P<identifier>"(?:""|[^"])*"|[A-Za-z_][A-Za-z0-9_$]*)'
        r'(?:[ \t]+WITH\b[^;\n]*)?;)[ \t]*$')

    def conditional_create(match):
        identifier = match.group("identifier")
        role_name = (identifier[1:-1].replace('""', '"') if identifier.startswith('"')
                     else identifier.lower())
        role_literal = role_name.replace("'", "''")
        statement_literal = match.group("statement").replace("'", "''")
        return (f"{match.group('indent')}DO $p26$ BEGIN "
                f"IF NOT EXISTS (SELECT 1 FROM pg_catalog.pg_roles "
                f"WHERE rolname='{role_literal}') THEN "
                f"EXECUTE '{statement_literal}'; END IF; END $p26$;")

    role_script, _ = pattern.subn(conditional_create, text)
    if re.search(r"(?im)^[ \t]*CREATE ROLE\b", role_script):
        raise BackupError("Role dump contains an unsupported role statement")
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(
                mode="w", prefix=".p26-roles-", suffix=".sql", dir=source.parent,
                delete=False) as script:
            temporary = Path(script.name)
            os.chmod(temporary, 0o600)
            script.write(role_script)
        _run(["psql", "-X", "-v", "ON_ERROR_STOP=1", "--single-transaction",
              "-f", str(temporary), safe], env=_env(password))
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)


def _restore_database(dsn, path, *, allow_remote=False):
    safe, password, _ = _connection_parts(dsn, allow_remote=allow_remote)
    _run(["pg_restore", "--exit-on-error", "--single-transaction", "--no-password",
          "--dbname", safe, str(path)], env=_env(password), timeout=600)


def _restore_destination(value):
    try:
        destination = Path(value)
    except (TypeError, ValueError):
        raise BackupError("Invalid sidecar destination") from None
    if destination.name in ("", ".", "..") or destination.exists() or destination.is_symlink():
        raise BackupError("Sidecar destination must not already exist")
    parent = destination.parent.resolve()
    if parent.exists() and not parent.is_dir():
        raise BackupError("Sidecar destination parent is not a directory")
    return parent / destination.name


def _stage_tree(source, destination, *, mode):
    destination = _restore_destination(destination)
    destination.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    stage = Path(tempfile.mkdtemp(
        prefix=".p26-restore-sidecar-", dir=destination.parent))
    os.chmod(stage, 0o700)
    try:
        for path in sorted(Path(source).rglob("*")):
            relative = _safe_relative(str(path.relative_to(source)))
            target = stage / relative
            info = path.lstat()
            if stat.S_ISDIR(info.st_mode):
                target.mkdir(mode=0o700, parents=True, exist_ok=True)
                os.chmod(target, 0o700)
            elif stat.S_ISREG(info.st_mode):
                target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                shutil.copyfile(path, target)
                os.chmod(target, mode)
            else:
                raise BackupError("Restored sidecar is not a regular file")
        return destination, stage
    except Exception:
        shutil.rmtree(stage, ignore_errors=True)
        raise


def _publish_stages(staged):
    published = []
    try:
        for destination, stage in staged:
            if destination.exists() or destination.is_symlink():
                raise BackupError("Sidecar destination appeared during restore")
            os.rename(stage, destination)
            published.append((destination, stage))
    except Exception:
        for destination, stage in reversed(published):
            try:
                os.rename(destination, stage)
            except OSError:
                pass
        raise


def restore_backup(backup, dsn, *, passphrase, files_destination=None,
                   keys_destination=None, allow_remote=False):
    archive = Path(backup)
    if not archive.is_file():
        raise BackupError("Backup file is unavailable")
    with _extracted(archive, passphrase) as (root, manifest):
        if manifest["files"] and files_destination is None:
            raise BackupError("File sidecars require a restore destination")
        if manifest["keys"] and keys_destination is None:
            raise BackupError("Key sidecars require a restore destination")
        files_target = (_restore_destination(files_destination)
                        if files_destination is not None else None)
        keys_target = (_restore_destination(keys_destination)
                       if keys_destination is not None else None)
        if files_target is not None and keys_target is not None:
            if (files_target == keys_target or files_target.is_relative_to(keys_target)
                    or keys_target.is_relative_to(files_target)):
                raise BackupError("File and key destinations must not overlap")
        staged = []
        try:
            if files_target is not None:
                staged.append(_stage_tree(root / "files", files_target, mode=0o644))
            if keys_target is not None:
                staged.append(_stage_tree(root / "keys", keys_target, mode=0o600))
            if user_tables(dsn, allow_remote=allow_remote):
                raise BackupError("Restore target must be empty")
            admin = _query(dsn, "SELECT rolsuper FROM pg_roles WHERE rolname=current_user",
                           allow_remote=allow_remote)
            if not admin or not admin[0][0]:
                raise BackupError("Restore requires a PostgreSQL superuser")
            _restore_roles(dsn, root / "roles.sql", allow_remote=allow_remote)
            _restore_database(dsn, root / "database.dump", allow_remote=allow_remote)
            counts = financial_counts(dsn, allow_remote=allow_remote)
            if counts != manifest["counts"]:
                raise BackupError("Restored protected counts differ from the backup")
            digest = public_schema_sha256(dsn, allow_remote=allow_remote)
            if digest != manifest["schema_sha256"]:
                raise BackupError("Restored public schema differs from the backup")
            catalog = catalog_counts(dsn, allow_remote=allow_remote)
            if catalog != manifest["catalog"]:
                raise BackupError("Restored catalog differs from the backup")
            if grants_sha256(dsn, allow_remote=allow_remote) != manifest["grants_sha256"]:
                raise BackupError("Restored grants differ from the backup")
            _publish_stages(staged)
            return {"status": "ok", "counts": counts, "catalog": catalog,
                    "schema_sha256": digest, "grants_sha256": manifest["grants_sha256"],
                    "files_restored": files_target is not None,
                    "keys_restored": keys_target is not None}
        finally:
            for _, stage in staged:
                shutil.rmtree(stage, ignore_errors=True)
