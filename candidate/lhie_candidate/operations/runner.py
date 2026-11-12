"""Bounded, idempotent scheduler over the operations registry.

One scheduler process is sufficient. It runs due jobs sequentially, under a
PostgreSQL advisory cycle lock and one advisory lock per operation. A timeout or
crash releases those connection-scoped locks; the durable lease expires before a
takeover. Jobs are bounded by registry limits and cannot block one another past
the scheduler timeout.
"""

from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
import os
from pathlib import Path
import signal
import threading
import time

from .contract import SCHEDULE, SCHEDULED
from .jobs import JobContext
from .metrics import OperationMetrics
from .state import ERROR_CODE_PATTERN, OperationStore, close_connections, safe_summary


class OperationTimeout(BaseException):
    """Not an Exception so a job cannot mistake a hard deadline for a retry."""


def _alarm(*_):
    raise OperationTimeout()


def _metrics_path(root, operation):
    safe = "".join(character if character.isalnum() or character in "._-" else "_"
                   for character in operation)
    return root / f"{safe}.json"


@dataclass
class SchedulerReport:
    status: str
    executed: list
    skipped: list
    now: str
    runtime_alert: object = None

    def as_dict(self):
        report = {"status": self.status, "executed": self.executed,
                  "skipped": self.skipped, "now": self.now}
        if self.runtime_alert is not None:
            report["runtime_alert"] = self.runtime_alert
        return report


class Scheduler:
    def __init__(self, *, store=None, operations=None, executor=None, clock=None,
                 metrics_dir=None, heartbeat_path=None, use_alarm=True):
        self.store = store or OperationStore()
        self.operations = tuple(operations if operations is not None else SCHEDULE)
        if any(operation.state != SCHEDULED for operation in self.operations):
            raise ValueError("Scheduler accepts only scheduled operations")
        self.executor = executor
        self.clock = clock or (lambda: datetime.now(timezone.utc))
        self.metrics_dir = Path(metrics_dir) if metrics_dir is not None else None
        self.heartbeat_path = Path(heartbeat_path) if heartbeat_path is not None else None
        self.use_alarm = use_alarm

    def _now(self):
        now = self.clock()
        if not isinstance(now, datetime) or now.tzinfo is None:
            raise ValueError("Scheduler clock must return timezone-aware instants")
        return now

    def _execute(self, operation, context):
        if self.executor is None:
            from . import jobs
            function = getattr(jobs, operation.owner.split(":", 1)[1])
            return function(context)
        return self.executor(operation, context)

    def _bounded(self, operation, context):
        if (not self.use_alarm or threading.current_thread() is not threading.main_thread()):
            return self._execute(operation, context)
        previous = signal.signal(signal.SIGALRM, _alarm)
        signal.setitimer(signal.ITIMER_REAL, operation.max_seconds)
        try:
            return self._execute(operation, context)
        finally:
            signal.setitimer(signal.ITIMER_REAL, 0)
            signal.signal(signal.SIGALRM, previous)

    def _heartbeat(self, now):
        if self.heartbeat_path is None:
            return
        try:
            self.heartbeat_path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            descriptor = os.open(self.heartbeat_path,
                                 os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
            with os.fdopen(descriptor, "w") as stream:
                stream.write(now.isoformat())
                stream.write("\n")
        except OSError:
            pass

    def run_once(self, now=None):
        now = self._now() if now is None else now
        if not isinstance(now, datetime) or now.tzinfo is None:
            raise ValueError("Scheduler run requires a timezone-aware instant")
        if not self.store.claim_cycle():
            return SchedulerReport("busy", [], [], now.isoformat()).as_dict()
        executed, skipped = [], []
        degraded = False
        try:
            state = self.store.snapshot([operation.name for operation in self.operations])
            for operation in self.operations:
                row = state.get(operation.name) or {}
                if not operation.schedule.due(now, row.get("slot")):
                    continue
                lease = self.store.claim(operation.name, operation.schedule.slot(now), now,
                                         operation.lease_seconds)
                if lease is None:
                    skipped.append({"operation": operation.name, "reason": "overlap"})
                    continue
                started = time.monotonic()
                metrics = OperationMetrics(operation.name)
                outcome, summary, cursor, cursor_set, error_code = "success", {}, None, False, None
                try:
                    result = self._bounded(operation, JobContext(
                        now=now, limit=operation.limit, cursor=row.get("cursor")))
                    if not isinstance(result, dict):
                        raise ValueError("Operation returned no summary")
                    summary = safe_summary(result)
                    status = result.get("status")
                    if status == "deferred":
                        outcome = "deferred"
                    elif status == "failed":
                        outcome = "failure"
                        candidate = result.get("error")
                        error_code = (candidate if type(candidate) is str and
                                      ERROR_CODE_PATTERN.fullmatch(candidate) else "job_failed")
                    elif status == "partial":
                        outcome, error_code = "partial", "job_partial"
                    elif status == "skipped":
                        outcome = "skipped"
                    cursor_set = "next_cursor" in result
                    cursor = result.get("next_cursor")
                except OperationTimeout:
                    outcome, error_code = "timeout", "timeout"
                    if operation.owner == "operations.jobs:receipt_recover":
                        try:
                            from .alerts import scheduler_runtime
                            scheduler_runtime("outbound-recovery", "failed", "recovery_timeout")
                        except Exception:
                            pass
                    close_connections()
                except Exception:
                    outcome, error_code = "failure", "job_failed"
                    if operation.owner == "operations.jobs:receipt_recover":
                        try:
                            from .alerts import scheduler_runtime
                            scheduler_runtime("outbound-recovery", "failed", "recovery_exit")
                        except Exception:
                            pass
                    close_connections()
                duration_ms = max(0, int((time.monotonic() - started) * 1000))
                metrics.record("runs")
                metrics.record(outcome if outcome in metrics._counters else "failure")
                metrics.record("job", duration_ms / 1000)
                metrics_error = None
                if self.metrics_dir is not None:
                    try:
                        directory = self.metrics_dir / "operations"
                        directory.mkdir(mode=0o700, parents=True, exist_ok=True)
                        metrics.write(_metrics_path(directory, operation.name))
                    except Exception:
                        metrics_error = "metrics_write_failed"
                        summary = safe_summary({**summary, "metrics_error": metrics_error})
                state_error = False
                try:
                    self.store.finish(lease, outcome=outcome, summary=summary,
                                      cursor_value=cursor, cursor_set=cursor_set,
                                      error_code=error_code,
                                      now=now + timedelta(milliseconds=duration_ms),
                                      duration_ms=duration_ms)
                except Exception:
                    state_error = True
                    metrics_error = metrics_error or "state_record_failed"
                if outcome in ("partial", "failure", "timeout", "metrics_failed") or metrics_error:
                    degraded = True
                executed.append({"operation": operation.name, "outcome": outcome,
                                 "duration_ms": duration_ms,
                                 "metrics_error": metrics_error,
                                 "summary": summary})
                if state_error:
                    executed[-1]["state_recorded"] = False
        except Exception:
            degraded = True
        finally:
            self.store.release_cycle()
        if degraded:
            try:
                from .alerts import scheduler_runtime
                alert = scheduler_runtime("scheduler", "failed", "schedule_exit")
                runtime_alert = {"status": alert.get("status", "failed"),
                                 "reason": alert.get("reason")}
            except Exception:
                runtime_alert = {"status": "failed", "reason": "runtime_alert_failed"}
        else:
            try:
                from .alerts import scheduler_runtime
                alert = scheduler_runtime("scheduler", "recovered")
                runtime_alert = {"status": alert.get("status", "failed"),
                                 "reason": alert.get("reason")}
            except Exception:
                runtime_alert = {"status": "failed", "reason": "runtime_alert_failed"}
                degraded = True
        if runtime_alert["status"] == "failed":
            degraded = True
        if self.heartbeat_path is not None and not degraded:
            self._heartbeat(now)
        return SchedulerReport("degraded" if degraded else "ok", executed, skipped,
                               now.isoformat(), runtime_alert).as_dict()


def run_group(operations, *, now=None, executor=None, metrics_dir=None, heartbeat_path=None):
    return Scheduler(operations=operations, executor=executor, metrics_dir=metrics_dir,
                     heartbeat_path=heartbeat_path).run_once(now)
