"""Durable scheduler state, leases and PostgreSQL advisory locks.

The advisory lock is the live overlap authority. The state row is operator
evidence and the crash lease: a dead process closes its connection and loses the
advisory lock immediately, while a stale lease expires before a takeover. Redis
is never used for scheduling, so a queue loss cannot replay or authorize work.
"""

from dataclasses import dataclass
from datetime import timedelta
import json
import re
import uuid

from django.db import connections, transaction

from .contract import SCHEDULE_NAMES

CYCLE_LOCK = "lhie-candidate-scheduler-cycle"
OPERATION_PREFIX = "lhie-candidate-operation:"
OUTCOMES = frozenset({"success", "partial", "failure", "timeout", "deferred",
                      "skipped", "metrics_failed"})
ERROR_CODE_PATTERN = re.compile(r"[a-z][a-z0-9_:-]{0,63}\Z")


class StateError(RuntimeError):
    pass


@dataclass(frozen=True, slots=True)
class Lease:
    operation: str
    slot: int | None
    owner: str
    started_at: object


def _safe_json(value, *, depth=0):
    """Keep run evidence scalar-only, bounded and free of arbitrary payloads."""
    if depth > 3:
        raise ValueError("Operation summary is too deep")
    if value is None or type(value) in (bool, int, float, str):
        if type(value) is str and len(value) > 512:
            raise ValueError("Operation summary string is too long")
        return value
    if type(value) in (list, tuple):
        if len(value) > 50:
            raise ValueError("Operation summary list is too long")
        return [_safe_json(item, depth=depth + 1) for item in value]
    if type(value) is dict:
        if len(value) > 50 or any(type(key) is not str or len(key) > 64 for key in value):
            raise ValueError("Operation summary object is invalid")
        return {key: _safe_json(item, depth=depth + 1) for key, item in value.items()}
    raise ValueError("Operation summary must be JSON scalar/object/list")


def safe_summary(value):
    try:
        encoded = json.dumps(_safe_json(value), sort_keys=True, separators=(",", ":"))
    except (TypeError, ValueError):
        return {"summary": "unrepresentable"}
    if len(encoded.encode("utf-8")) > 4096:
        return {"summary": "truncated"}
    return json.loads(encoded)


class OperationStore:
    def __init__(self, using="default"):
        self.using = using

    def _connection(self):
        return connections[self.using]

    def _cursor(self):
        return self._connection().cursor()

    def _lock(self, cursor, name):
        cursor.execute("SELECT pg_catalog.pg_try_advisory_lock(hashtextextended(%s, 0))", (name,))
        return cursor.fetchone()[0] is True

    def _unlock(self, cursor, name):
        try:
            cursor.execute("SELECT pg_catalog.pg_advisory_unlock(hashtextextended(%s, 0))", (name,))
        except Exception:
            # Closing the connection also releases a session lock. Never let a
            # cleanup failure replace the recorded operation outcome.
            pass

    def claim_cycle(self):
        with self._cursor() as cursor:
            return self._lock(cursor, CYCLE_LOCK)

    def release_cycle(self):
        try:
            with self._cursor() as cursor:
                self._unlock(cursor, CYCLE_LOCK)
        except Exception:
            pass

    def snapshot(self, names=None):
        names = tuple(SCHEDULE_NAMES if names is None else names)
        if not names:
            return {}
        with self._cursor() as cursor:
            cursor.execute("""SELECT operation, slot, cursor::text AS cursor, lease_owner, lease_until,
                last_started_at, last_finished_at, last_outcome, consecutive_failures,
                last_failure_code, last_summary
                FROM django_candidate.operations_state WHERE operation = ANY(%s)""", (list(names),))
            columns = [column[0] for column in cursor.description]
            states = {}
            for row in cursor.fetchall():
                state = dict(zip(columns, row))
                if state["cursor"] is not None:
                    state["cursor"] = json.loads(state["cursor"])
                states[state["operation"]] = state
            return states

    def claim(self, operation, slot, now, lease_seconds):
        owner = str(uuid.uuid4())
        lock_name = OPERATION_PREFIX + operation
        with self._cursor() as cursor:
            if not self._lock(cursor, lock_name):
                return None
            try:
                lease_until = now + timedelta(seconds=lease_seconds)
                cursor.execute("""INSERT INTO django_candidate.operations_state
                    (operation, slot, lease_owner, lease_until, last_started_at, updated_at)
                    VALUES (%s, %s, %s, %s, %s, %s)
                    ON CONFLICT (operation) DO UPDATE SET
                        slot=EXCLUDED.slot,
                        lease_owner=EXCLUDED.lease_owner,
                        lease_until=EXCLUDED.lease_until,
                        last_started_at=EXCLUDED.last_started_at,
                        updated_at=EXCLUDED.updated_at
                    WHERE django_candidate.operations_state.lease_until IS NULL
                       OR django_candidate.operations_state.lease_until <= %s
                    RETURNING operation""", (operation, slot, owner, lease_until, now, now, now))
                if cursor.fetchone() is None:
                    self._unlock(cursor, lock_name)
                    return None
                return Lease(operation=operation, slot=slot, owner=owner, started_at=now)
            except Exception:
                self._unlock(cursor, lock_name)
                raise

    def finish(self, lease, *, outcome, summary=None, cursor_value=None, cursor_set=False,
               error_code=None, now=None, duration_ms=None, release=True):
        try:
            if outcome not in OUTCOMES:
                raise ValueError("Invalid operation outcome")
            if type(cursor_set) is not bool:
                raise ValueError("Invalid cursor update flag")
            summary = safe_summary({} if summary is None else summary)
            if cursor_set:
                cursor_value = safe_summary(cursor_value)
            if error_code is not None and (
                    type(error_code) is not str or ERROR_CODE_PATTERN.fullmatch(error_code) is None):
                raise ValueError("Invalid operation error code")
            with transaction.atomic(using=self.using), self._cursor() as db:
                db.execute("""UPDATE django_candidate.operations_state SET
                    lease_owner=NULL,lease_until=NULL,last_finished_at=%s,last_outcome=%s,
                    consecutive_failures=CASE
                        WHEN %s='success' THEN 0
                        WHEN %s IN ('partial','failure','timeout','metrics_failed')
                            THEN consecutive_failures+1
                        ELSE consecutive_failures END,
                    last_failure_code=CASE
                        WHEN %s='success' THEN NULL
                        WHEN %s IN ('partial','failure','timeout','metrics_failed') THEN %s
                        ELSE last_failure_code END,
                    last_summary=%s::jsonb,
                    cursor=CASE WHEN %s THEN %s::jsonb ELSE cursor END,updated_at=%s
                    WHERE operation=%s AND lease_owner=%s""",
                    (now, outcome, outcome, outcome, outcome, outcome, error_code,
                     json.dumps(summary), cursor_set,
                     json.dumps(cursor_value) if cursor_set else None, now,
                     lease.operation, lease.owner))
                if db.rowcount != 1:
                    raise StateError("Operation lease ownership changed before finish")
                db.execute("""INSERT INTO django_candidate.operations_runs
                    (operation, slot, lease_owner, started_at, finished_at, outcome,
                     duration_ms, summary, error_code)
                    VALUES (%s,%s,%s,%s,%s,%s,%s,%s::jsonb,%s)""",
                    (lease.operation, lease.slot, lease.owner, lease.started_at, now,
                     outcome, duration_ms, json.dumps(summary), error_code))
        finally:
            if release:
                self.release(lease)
    def release(self, lease):
        try:
            with self._cursor() as cursor:
                self._unlock(cursor, OPERATION_PREFIX + lease.operation)
        except Exception:
            pass

    def recent_runs(self, operation, limit=20):
        if type(limit) is not int or not 1 <= limit <= 100:
            raise ValueError("Invalid run limit")
        with self._cursor() as cursor:
            cursor.execute("""SELECT operation, slot, started_at, finished_at, outcome,
                duration_ms, summary, error_code FROM django_candidate.operations_runs
                WHERE operation=%s ORDER BY started_at DESC, id DESC LIMIT %s""",
                (operation, limit))
            columns = [column[0] for column in cursor.description]
            return [dict(zip(columns, row)) for row in cursor.fetchall()]

    def stale_leases(self, now):
        with self._cursor() as cursor:
            cursor.execute("""SELECT operation, lease_owner, lease_until, last_started_at
                FROM django_candidate.operations_state
                WHERE lease_until IS NOT NULL AND lease_until <= %s ORDER BY operation""", (now,))
            columns = [column[0] for column in cursor.description]
            return [dict(zip(columns, row)) for row in cursor.fetchall()]


def close_connections():
    """Used after a timeout so an aborted session cannot be reused."""
    try:
        connections.close_all()
    except Exception:
        pass
