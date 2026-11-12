"""Cheap, bounded health/readiness and sanitized operator reports.

Health never reads employment/credential payloads and never opens a password,
mail body or API secret. A healthy probe reports dependency/worker/runtime
observations only; it makes no capacity, throughput or provider qualification
claim.
"""

from datetime import datetime
import json
import math
import os
from pathlib import Path
import socket
import stat
import time

from django.db import connections

from .state import OperationStore

JAKARTA = "Asia/Jakarta"
WAKEUP_QUEUES = None


def liveness():
    return {"status": "ok"}


def readiness():
    try:
        from lhie_candidate.readiness import verify_dependencies
        verify_dependencies()
    except Exception:
        return {"status": "not_ready"}
    return {"status": "ready"}


def _db_rows(sql, params=(), using="default"):
    with connections[using].cursor() as cursor:
        cursor.execute(sql, params)
        columns = [column[0] for column in cursor.description]
        return [dict(zip(columns, row)) for row in cursor.fetchall()]


def _db_one(sql, params=(), using="default"):
    rows = _db_rows(sql, params, using)
    return rows[0] if rows else None


def _heartbeat(path, now, max_age):
    if path is None:
        return None
    if type(max_age) is not int or not 1 <= max_age <= 86400:
        raise ValueError("Invalid heartbeat maximum age")
    target = Path(path)
    try:
        info = target.lstat()
        if not stat.S_ISREG(info.st_mode) or info.st_size > 4096:
            return {"state": "invalid"}
        age = max(0, int(now.timestamp() - info.st_mtime))
        return {"state": "ok" if age <= max_age else "stale", "age_seconds": age,
                "max_age_seconds": max_age}
    except OSError:
        return {"state": "missing"}


def _queue_topology():
    from lhie_candidate.workers.contract import QUEUES
    return list(QUEUES)


def _metrics_snapshot(directory, queues):
    if directory is None:
        return None
    from lhie_candidate.workers.metrics import COUNTERS, DURATIONS

    root = Path(directory)
    result = {"files": 0, "invalid": 0, "queues": {}}
    for queue in queues:
        path = root / f"{queue}.json"
        try:
            descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
            with os.fdopen(descriptor, "rb") as stream:
                info = os.fstat(stream.fileno())
                if not stat.S_ISREG(info.st_mode) or info.st_size > 65536:
                    result["invalid"] += 1
                    continue
                raw = stream.read(65537)
            if len(raw) > 65536:
                result["invalid"] += 1
                continue
            value = json.loads(raw)
            counters = value.get("counters") if isinstance(value, dict) else None
            durations = value.get("durations") if isinstance(value, dict) else None
            if (not isinstance(value, dict) or set(value) != {"queue", "counters", "durations"}
                    or value["queue"] != queue or not isinstance(counters, dict)
                    or set(counters) != set(COUNTERS)
                    or any(type(count) is not int or not 0 <= count <= 2**63 - 1
                           for count in counters.values())
                    or not isinstance(durations, dict) or set(durations) != set(DURATIONS)):
                result["invalid"] += 1
                continue
            safe_durations = {}
            for name in DURATIONS:
                sample = durations[name]
                if (not isinstance(sample, dict)
                        or set(sample) != {"count", "max_ms", "min_ms", "total_ms"}):
                    raise ValueError("Invalid worker duration")
                count = sample["count"]
                values = [sample["max_ms"], sample["min_ms"], sample["total_ms"]]
                if (type(count) is not int or not 0 <= count <= 256
                        or any(type(value) not in (int, float)
                               or (type(value) is float and not math.isfinite(value))
                               or not 0 <= value <= 3600000 * max(1, count) for value in values)
                        or sample["min_ms"] > sample["max_ms"]):
                    raise ValueError("Invalid worker duration")
                safe_durations[name] = {"count": count, "max_ms": sample["max_ms"],
                                        "min_ms": sample["min_ms"],
                                        "total_ms": sample["total_ms"]}
            result["files"] += 1
            result["queues"][queue] = {"counters": dict(counters),
                                       "durations": safe_durations}
        except (OSError, ValueError, UnicodeError, TypeError, RecursionError, OverflowError):
            result["invalid"] += 1
    return result


def _redis_bounded(commands, *, timeout=1.0):
    """One bounded pipeline for read-only health commands; no payload values."""
    try:
        with socket.create_connection(("127.0.0.1", 6379), timeout=timeout) as connection:
            connection.settimeout(timeout)
            payload = bytearray()
            for parts in commands:
                payload.extend(f"*{len(parts)}\r\n".encode())
                for part in parts:
                    raw = str(part).encode()
                    payload.extend(f"${len(raw)}\r\n".encode() + raw + b"\r\n")
            connection.sendall(bytes(payload))
            data = bytearray()
            while data.count(b"\r\n") < len(commands):
                chunk = connection.recv(1024)
                if not chunk:
                    raise OSError("redis closed")
                data.extend(chunk)
                if len(data) > 4096:
                    raise OSError("redis reply too large")
            values = []
            for line in bytes(data).split(b"\r\n"):
                if line.startswith(b":"):
                    values.append(int(line[1:]))
                elif line.startswith((b"+", b"$")):
                    values.append(line[1:].decode("ascii", "replace"))
            return values
    except Exception:
        return None


def queue_depth(include_reserved_and_delayed=False, using="default"):
    from lhie_candidate.workers.contract import WAKEUP_QUEUES
    names = list(WAKEUP_QUEUES)
    values = _redis_bounded([["LLEN", queue] for queue in names])
    if (values is None or len(values) != len(names)
            or any(type(value) is not int or value < 0 for value in values)):
        return {"status": "failed", "queues": [], "reason": "redis_unavailable"}
    depths = {queue: value for queue, value in zip(names, values)}
    return {"status": "ok", "queues": names, "depth": depths,
            "mode": "total" if include_reserved_and_delayed else "runnable",
            "read_only": True}


def health_report(*, db=True, queue_schema=True, queue_depth_check=False,
                  metrics_dir=None, scheduler_heartbeat=None,
                  scheduler_heartbeat_max_age=300, using="default"):
    now = datetime.now().astimezone()
    report = {"status": "ok", "checks": {}, "read_only": True}
    if db:
        try:
            _db_one("SELECT 1 AS ok", using=using)
            report["checks"]["db"] = {"status": "ok"}
        except Exception:
            report["checks"]["db"] = {"status": "failed"}
            report["status"] = "failed"
    if queue_schema:
        names = _queue_topology()
        report["checks"]["queue_schema"] = {"status": "ok", "queues": names,
                                            "isolated": True}
    if queue_depth_check:
        depths = queue_depth(using=using)
        report["checks"]["queue_depth"] = depths
        if depths["status"] != "ok":
            report["status"] = "failed"
    metrics = _metrics_snapshot(metrics_dir, _queue_topology())
    if metrics is not None:
        report["checks"]["worker_metrics"] = metrics
        if metrics["invalid"]:
            report["status"] = "failed"
    heartbeat = _heartbeat(scheduler_heartbeat, now, scheduler_heartbeat_max_age)
    if heartbeat is not None:
        report["checks"]["scheduler_heartbeat"] = heartbeat
        if heartbeat["state"] != "ok":
            report["status"] = "failed"
    try:
        state = OperationStore(using).snapshot()
        report["checks"]["scheduler_state"] = {
            "operations": len(state),
            "leased": sum(1 for row in state.values() if row["lease_until"] is not None),
            "failing": sum(1 for row in state.values() if (row["consecutive_failures"] or 0) > 0),
            "stale_leases": len(OperationStore(using).stale_leases(now)),
        }
    except Exception:
        report["checks"]["scheduler_state"] = {"status": "unavailable"}
        report["status"] = "failed"


    return report


def release_health_summary(*, using="default"):
    queues = _queue_topology()
    runs = _db_rows("""SELECT outcome,count(*) AS aggregate
        FROM django_candidate.operations_runs
        WHERE started_at >= CURRENT_TIMESTAMP - INTERVAL '24 hours'
        GROUP BY outcome""", using=using)
    counts = {row["outcome"]: int(row["aggregate"]) for row in runs}
    failed = sum(counts.get(outcome, 0)
                 for outcome in ("failure", "timeout", "partial", "metrics_failed"))
    return {"status": "failed" if failed else "ok",
            "generated_at": datetime.now().astimezone().isoformat(),
            "queue": {"operational_queues": queues, "topology_isolation": True},
            "operations": {"last_24h": counts, "failed_last_24h": failed},
            "failed_jobs": {"last_24h": failed, "candidate_release": failed},
            "limits": ["worker metrics are process counters, not a capacity guarantee",
                       "no provider/worker liveness probe is performed"]}


def check_failed_jobs(max_failures=0, *, baseline=None, using="default"):
    if type(max_failures) is not int or not 0 <= max_failures <= 10000:
        raise ValueError("failed job threshold must be between zero and 10000")
    if baseline is not None and (type(baseline) is not int or baseline < 0):
        raise ValueError("failed job baseline must be a non-negative integer")
    conditions = "outcome IN ('failure','timeout','partial','metrics_failed')"
    params = []
    if baseline is not None:
        conditions += " AND id > %s"
        params.append(baseline)
    params.append(max_failures + 1)
    row = _db_one(f"""SELECT count(*) AS aggregate FROM (
        SELECT id FROM django_candidate.operations_runs
        WHERE {conditions} ORDER BY id DESC LIMIT %s) bounded""",
        tuple(params), using=using) or {"aggregate": 0}
    count = int(row["aggregate"])
    return {"status": "ok" if count <= max_failures else "failed",
            "count": count, "count_capped": count > max_failures,
            "max": max_failures,
            "scope": "failures after baseline" if baseline is not None else "retained failures"}




def assert_database_queue_empty(using="default"):
    table = _db_one("SELECT to_regclass('public.jobs') AS relation", using=using)
    has_jobs = False
    if table and table["relation"]:
        row = _db_one("SELECT EXISTS (SELECT 1 FROM public.jobs) AS present", using=using)
        has_jobs = bool(row and row["present"])
    return {"status": "blocked" if has_jobs else "ok", "has_jobs": has_jobs}


def _bounded_count(limit, table, where, params, using):
    row = _db_one(f"""SELECT count(*) AS aggregate FROM (
        SELECT id FROM public.{table} WHERE {where} LIMIT %s) bounded""",
        (*params, limit + 1), using=using) or {"aggregate": 0}
    count = int(row["aggregate"])
    return {"count": min(limit, count), "capped": count > limit}


def auth_mail_health(limit=1000, *, using="default"):
    if type(limit) is not int or not 1 <= limit <= 10000:
        raise ValueError("Health limit is out of range")
    now_sql = "CURRENT_TIMESTAMP AT TIME ZONE %s"
    counts = {
        "due": _bounded_count(limit, "customer_auth_mail_works",
                              f"status='pending' AND expires_at > {now_sql} AND next_attempt_at <= {now_sql}",
                              (JAKARTA, JAKARTA), using),
        "deferred": _bounded_count(limit, "customer_auth_mail_works",
                                   f"status='pending' AND expires_at > {now_sql} AND next_attempt_at > {now_sql}",
                                   (JAKARTA, JAKARTA), using),
        "expired_pending": _bounded_count(limit, "customer_auth_mail_works",
                                          f"status='pending' AND expires_at <= {now_sql}",
                                          (JAKARTA,), using),
        "retrying_failed": _bounded_count(limit, "customer_auth_mail_works",
                                          "status='pending' AND last_error_code IS NOT NULL", (), using),
    }
    for status in ("sent", "cancelled", "failed"):
        counts[status] = _bounded_count(limit, "customer_auth_mail_works",
                                        "status=%s", (status,), using)
    unhealthy = any(counts[name]["count"] for name in
                    ("failed", "retrying_failed", "expired_pending"))
    return {"status": "failed" if unhealthy else "ok", "queue": "notifications",
            "counts": counts, "count_limit": limit,
            "provider_delivery": "unverified",
            "sent_means": "transport_accepted_not_inbox_delivery"}


def auth_readiness(limit=1000, *, using="default"):
    if type(limit) is not int or not 1 <= limit <= 10000:
        raise ValueError("Readiness limit is out of range")
    now_sql = "CURRENT_TIMESTAMP AT TIME ZONE %s"
    counts = {}
    for table, terminal in (("customer_auth_tokens", "consumed_at"),
                            ("customer_login_links", "used_at"),
                            ("customer_google_auth_claims", "consumed_at"),
                            ("customer_telegram_auth_claims", "consumed_at")):
        where = f"{terminal} IS NULL AND expires_at > {now_sql}"
        if table == "customer_telegram_auth_claims":
            where += " AND failure IS NULL"
        counts[table] = _bounded_count(limit, table, where, (JAKARTA,), using)
    counts["expired_pending_mail"] = _bounded_count(
        limit, "customer_auth_mail_works",
        f"status='pending' AND expires_at <= {now_sql}", (JAKARTA,), using)
    counts["retrying_failed_mail"] = _bounded_count(
        limit, "customer_auth_mail_works",
        "status='pending' AND last_error_code IS NOT NULL", (), using)
    counts["failed_mail"] = _bounded_count(
        limit, "customer_auth_mail_works", "status='failed'", (), using)
    return {"new_methods_enabled": "unverified", "magic_link_guest_recovery": "retained",
            "counts": counts, "count_limit": limit,
            "live_evidence": {"resend_inbox": "unverified", "google_oauth": "unverified",
                              "private_store_bot": "unverified"},
            "release_ready": False,
            "scope": "local_configuration_only_no_provider_or_worker_probe"}
