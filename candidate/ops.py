"""Operator CLI for scheduler, health, retention, bootstrap and backup.

No command is scheduled by importing this file. Django is configured only for
commands that need the candidate runtime; bootstrap/backup/restore use the
reviewed SQL tooling and never load application secrets.
"""

import argparse
from datetime import datetime
import json
from pathlib import Path
import sys


def _json(value):
    print(json.dumps(value, indent=2, sort_keys=True))


def _configure():
    from lhie_candidate.startup import configure_django
    configure_django()


def _run_scheduler(args):
    _configure()
    from lhie_candidate.operations.runner import Scheduler
    from lhie_candidate.operations.state import OperationStore
    now = datetime.fromisoformat(args.at) if args.at else None
    if now is not None and now.tzinfo is None:
        raise SystemExit("--at requires a timezone offset")
    store = OperationStore()
    scheduler = Scheduler(store=store, metrics_dir=args.metrics_dir,
                          heartbeat_path=args.heartbeat)
    report = scheduler.run_once(now)
    _json(report)
    return 0 if report["status"] in ("ok", "busy") else 1


def _health(args):
    _configure()
    from lhie_candidate.operations.health import health_report, release_health_summary
    if args.release_summary:
        report = release_health_summary()
    else:
        report = health_report(db=not args.no_db, queue_schema=not args.no_queue,
                               queue_depth_check=args.queue_depth,
                               metrics_dir=args.metrics_dir,
                               scheduler_heartbeat=args.scheduler_heartbeat,
                               scheduler_heartbeat_max_age=args.max_age)
    _json(report)
    return 0 if report.get("status") == "ok" else 1


def _retention(args):
    _configure()
    from lhie_candidate.operations.retention import (cleanup_retained_data,
                                                     notification_retention_report)
    if args.report:
        report = notification_retention_report(
            args.family, as_of=args.as_of, horizon=args.horizon,
            page=args.page, roots=args.roots, dependencies=args.dependencies,
            per_root=args.per_root, seconds=args.seconds, details=args.details,
            after=args.after, stop=args.stop, operator_artifact=args.operator_artifact)
    else:
        report = cleanup_retained_data(args.days, chunk=args.chunk,
                                       max_batches=args.max_batches)
    _json(report)
    return 0


def _auth_cleanup(args):
    _configure()
    from lhie_candidate.operations.cleanup import cleanup_auth_challenges
    _json(cleanup_auth_challenges(args.limit, legacy_only=args.legacy_only))
    return 0


def _bootstrap(args):
    from lhie_candidate.operations.bootstrap import bootstrap, upgrade, verify
    if args.command == "bootstrap":
        report = bootstrap(args.dsn, args.baseline_dir,
                           allow_existing=args.allow_existing,
                           allow_remote=args.allow_remote)
    elif args.command == "upgrade":
        report = upgrade(args.dsn, args.baseline_dir, allow_remote=args.allow_remote)
    else:
        report = verify(args.dsn, args.baseline_dir, allow_remote=args.allow_remote)
    _json(report)
    return 0 if report.get("status") == "ok" else 1


def _backup(args):
    from lhie_candidate.operations.backup import create_backup, restore_backup
    passphrase = Path(args.passphrase_file).read_text().strip()
    if args.command == "backup":
        pairs = [(Path(source), relative) for source, relative in args.file]
        keys = [(Path(source), relative) for source, relative in args.key]
        report = create_backup(args.dsn, args.output, passphrase=passphrase,
                               files=pairs, keys=keys, allow_remote=args.allow_remote)
    else:
        report = restore_backup(args.backup, args.dsn, passphrase=passphrase,
                                files_destination=args.files_destination,
                                keys_destination=args.keys_destination,
                                allow_remote=args.allow_remote)
    _json(report)
    return 0


def _matrix(_args):
    from lhie_candidate.operations.contract import OPERATIONS
    _json([{"name": operation.name, "owner": operation.owner,
            "schedule": None if operation.schedule is None else {
                "kind": operation.schedule.kind, "seconds": operation.schedule.seconds,
                "at": None if operation.schedule.at is None else operation.schedule.at.isoformat(),
                "timezone": operation.schedule.timezone},
            "policy": operation.policy, "state": operation.state,
            "limit": operation.limit, "max_seconds": operation.max_seconds,
            "lease_seconds": operation.lease_seconds,
            "evidence": operation.evidence, "condition": operation.condition}
           for operation in OPERATIONS])
    return 0


def _pair(value):
    if "=" not in value:
        raise argparse.ArgumentTypeError("expected SOURCE=ARCHIVE_PATH")
    source, relative = value.split("=", 1)
    return source, relative


def parser():
    root = argparse.ArgumentParser(description=__doc__)
    sub = root.add_subparsers(dest="command", required=True)
    schedule = sub.add_parser("schedule", help="run one bounded scheduler cycle")
    schedule.add_argument("--at", help="fixed ISO8601 instant with an offset")
    schedule.add_argument("--metrics-dir", default=None)
    schedule.add_argument("--heartbeat", default=None)
    schedule.set_defaults(handler=_run_scheduler)

    health = sub.add_parser("health", help="bounded health/readiness evidence")
    health.add_argument("--release-summary", action="store_true")
    health.add_argument("--no-db", action="store_true")
    health.add_argument("--no-queue", action="store_true")
    health.add_argument("--queue-depth", action="store_true")
    health.add_argument("--metrics-dir", default=None)
    health.add_argument("--scheduler-heartbeat", default=None)
    health.add_argument("--max-age", type=int, default=300)
    health.set_defaults(handler=_health)

    retention = sub.add_parser("retention", help="bounded cleanup or read-only report")
    retention.add_argument("--report", action="store_true")
    retention.add_argument("--days", type=int, default=90)
    retention.add_argument("--chunk", type=int, default=500)
    retention.add_argument("--max-batches", type=int, default=10)
    retention.add_argument("--family", default="effects")
    retention.add_argument("--as-of", default="2026-01-01T00:00:00+08:00")
    retention.add_argument("--horizon", type=int, default=90)
    retention.add_argument("--page", type=int, default=100)
    retention.add_argument("--roots", type=int, default=1000)
    retention.add_argument("--dependencies", type=int, default=1000)
    retention.add_argument("--per-root", type=int, default=200)
    retention.add_argument("--seconds", type=int, default=10)
    retention.add_argument("--details", type=int, default=20)
    retention.add_argument("--after", default=None)
    retention.add_argument("--stop", default=None)
    retention.add_argument("--operator-artifact", action="store_true")
    retention.set_defaults(handler=_retention)

    auth = sub.add_parser("auth-cleanup", help="remove consumed/expired auth challenges")
    auth.add_argument("--limit", type=int, default=500)
    auth.add_argument("--legacy-only", action="store_true")
    auth.set_defaults(handler=_auth_cleanup)

    for name in ("bootstrap", "upgrade", "verify"):
        command = sub.add_parser(name, help=f"{name} a Python-owned schema")
        command.add_argument("--dsn", required=True)
        command.add_argument("--baseline-dir", required=True)
        command.add_argument("--allow-existing", action="store_true")
        command.add_argument("--allow-remote", action="store_true")
        command.set_defaults(handler=_bootstrap)

    backup = sub.add_parser("backup", help="create an encrypted DB/files/keys backup")
    backup.add_argument("--dsn", required=True)
    backup.add_argument("--output", required=True)
    backup.add_argument("--passphrase-file", required=True)
    backup.add_argument("--file", action="append", type=_pair, default=[])
    backup.add_argument("--key", action="append", type=_pair, default=[])
    backup.add_argument("--allow-remote", action="store_true")
    backup.set_defaults(handler=_backup)

    restore = sub.add_parser("restore", help="restore an encrypted backup to an empty target")
    restore.add_argument("--dsn", required=True)
    restore.add_argument("backup")
    restore.add_argument("--passphrase-file", required=True)
    restore.add_argument("--files-destination", default=None)
    restore.add_argument("--keys-destination", default=None)
    restore.add_argument("--allow-remote", action="store_true")
    restore.set_defaults(handler=_backup)

    matrix = sub.add_parser("matrix", help="print the operational ownership matrix")
    matrix.set_defaults(handler=_matrix)
    return root


def main(argv=None):
    args = parser().parse_args(argv)
    try:
        return args.handler(args)
    except Exception:
        # Never echo DSNs, SQL, payloads or exception text from a local tool.
        sys.stderr.write("candidate operations command refused\n")
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
