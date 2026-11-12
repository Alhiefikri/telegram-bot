"""Fake-clock due/overlap/cursor/failure tests for the bounded scheduler."""
from copy import deepcopy
from datetime import datetime, timedelta, timezone
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from lhie_candidate.operations import contract
from lhie_candidate.operations.runner import OperationTimeout, Scheduler
from lhie_candidate.operations.state import Lease, safe_summary


def operation(name, *, owner="operations.jobs:fake", schedule=None, max_seconds=2, lease_seconds=10):
    return contract.Operation(
        name=name, owner=owner, schedule=schedule or contract.interval(60),
        policy="bounded_retry", state=contract.SCHEDULED, limit=5,
        max_seconds=max_seconds, lease_seconds=lease_seconds,
        evidence="tests.test_operations_scheduler")


class FakeStore:
    def __init__(self):
        self.state = {}
        self.finished = []
        self.lease = None
        self.cycle = False
        self.fail_finish = False
    def claim_cycle(self):
        if self.cycle:
            return False
        self.cycle = True
        return True

    def release_cycle(self):
        self.cycle = False

    def snapshot(self, names=None):
        return {name: deepcopy(self.state.get(name, {})) for name in (names or ())}

    def claim(self, name, slot, now, lease_seconds):
        row = self.state.setdefault(name, {"slot": None, "cursor": None, "lease_until": None})
        if row.get("lease_until") is not None and row["lease_until"] > now:
            return None
        row["slot"] = slot
        row["lease_until"] = now + timedelta(seconds=lease_seconds)
        self.lease = Lease(name, slot, "owner-" + name, now)
        return self.lease

    def finish(self, lease, *, outcome, summary=None, cursor_value=None, cursor_set=False,
               error_code=None, now=None, duration_ms=None, release=True):
        if self.fail_finish:
            raise OSError("synthetic state failure")
        row = self.state[lease.operation]
        row.pop("lease_until", None)
        row["last_outcome"] = outcome
        row["last_summary"] = summary
        if outcome == "success":
            row["consecutive_failures"] = 0
        elif outcome in ("partial", "failure", "timeout", "metrics_failed"):
            row["consecutive_failures"] = row.get("consecutive_failures", 0) + 1
        if cursor_set:
            row["cursor"] = cursor_value
        self.finished.append({"operation": lease.operation, "outcome": outcome,
                              "cursor": cursor_value, "error_code": error_code,
                              "duration_ms": duration_ms})

class SchedulerTests(unittest.TestCase):
    def setUp(self):
        self.start = datetime(2026, 11, 12, 10, 0, 5, tzinfo=timezone.utc)
        self.calls = []
        self.alert_calls = []
        self.alert_patch = patch(
            "lhie_candidate.operations.alerts.scheduler_runtime",
            side_effect=self.runtime_alert)
        self.alert_patch.start()
        self.addCleanup(self.alert_patch.stop)

    def runtime_alert(self, component, state, reason=None):
        self.alert_calls.append((component, state, reason))
        return {"status": "opened" if state == "failed" else "recovered"}

    def executor(self, operation, context):
        self.calls.append((operation.name, context.cursor))
        return {"handled": 1}

    def test_due_slot_runs_once_then_waits_for_the_next_slot(self):
        store, job = FakeStore(), operation("alpha")
        scheduler = Scheduler(store=store, operations=(job,), executor=self.executor,
                              clock=lambda: self.start, use_alarm=False)
        first = scheduler.run_once(self.start)
        second = scheduler.run_once(self.start + timedelta(seconds=10))
        third = scheduler.run_once(self.start + timedelta(seconds=60))
        self.assertEqual([entry["outcome"] for entry in first["executed"]], ["success"])
        self.assertEqual(second["executed"], [])
        self.assertEqual(len(third["executed"]), 1)
        self.assertEqual(len(self.calls), 2)

    def test_an_active_lease_is_skipped_without_replaying_work(self):
        store, job = FakeStore(), operation("alpha")
        store.state["alpha"] = {"slot": None, "cursor": None,
                                "lease_until": self.start + timedelta(seconds=30)}
        scheduler = Scheduler(store=store, operations=(job,), executor=self.executor,
                              clock=lambda: self.start, use_alarm=False)
        report = scheduler.run_once(self.start)
        self.assertEqual(report["executed"], [])
        self.assertEqual(report["skipped"], [{"operation": "alpha", "reason": "overlap"}])
        self.assertEqual(self.calls, [])

    def test_failure_is_isolated_and_does_not_stop_later_jobs(self):
        def executor(job, context):
            if job.name == "alpha":
                raise RuntimeError("synthetic-sentinel")
            return {"handled": 1}

        store = FakeStore()
        jobs = (operation("alpha"), operation("beta"))
        scheduler = Scheduler(store=store, operations=jobs, executor=executor,
                              clock=lambda: self.start, use_alarm=False)
        report = scheduler.run_once(self.start)
        self.assertEqual([row["operation"] for row in store.finished], ["alpha", "beta"])
        self.assertEqual(report["status"], "degraded")
        self.assertEqual(self.alert_calls[-1], ("scheduler", "failed", "schedule_exit"))
    def test_timeout_is_recorded_without_a_job_exception_message(self):
        def executor(job, context):
            raise OperationTimeout()

        store = FakeStore()
        scheduler = Scheduler(store=store, operations=(operation("alpha"),),
                              executor=executor, clock=lambda: self.start, use_alarm=False)
        report = scheduler.run_once(self.start)
        self.assertEqual(report["executed"][0]["outcome"], "timeout")
        self.assertEqual(store.finished[0]["error_code"], "timeout")

    def test_outbound_recovery_timeout_opens_its_own_runtime_alert(self):
        def executor(_job, _context):
            raise OperationTimeout()

        job = operation("telegram:recover-outbound",
                        owner="operations.jobs:receipt_recover",
                        schedule=contract.interval(60))
        scheduler = Scheduler(store=FakeStore(), operations=(job,), executor=executor,
                              clock=lambda: self.start, use_alarm=False)
        report = scheduler.run_once(self.start)
        self.assertEqual(report["executed"][0]["outcome"], "timeout")
        self.assertIn(("outbound-recovery", "failed", "recovery_timeout"), self.alert_calls)

    def test_cursor_and_summary_persist_and_explicitly_clear(self):
        def executor(job, context):
            self.calls.append(context.cursor)
            if context.cursor is None:
                return {"next_cursor": 42, "handled": 2}
            return {"next_cursor": None, "handled": 0}

        store = FakeStore()
        scheduler = Scheduler(store=store, operations=(operation("alpha"),),
                              executor=executor, clock=lambda: self.start, use_alarm=False)
        scheduler.run_once(self.start)
        scheduler.run_once(self.start + timedelta(seconds=60))
        self.assertEqual(self.calls, [None, 42])
        self.assertIsNone(store.state["alpha"]["cursor"])
    def test_metrics_write_failure_never_changes_the_job_outcome(self):
        store = FakeStore()
        with tempfile.TemporaryDirectory() as directory:
            scheduler = Scheduler(store=store, operations=(operation("alpha"),),
                                  executor=self.executor, clock=lambda: self.start,
                                  metrics_dir=directory, use_alarm=False)
            with patch("lhie_candidate.operations.runner.OperationMetrics.write",
                       side_effect=OSError("synthetic")):
                report = scheduler.run_once(self.start)
        self.assertEqual(report["executed"][0]["outcome"], "success")
        self.assertEqual(report["executed"][0]["metrics_error"], "metrics_write_failed")
        self.assertEqual(report["status"], "degraded")

    def test_state_record_failure_degrades_without_rewriting_job_result(self):
        store = FakeStore()
        store.fail_finish = True
        scheduler = Scheduler(store=store, operations=(operation("alpha"),),
                              executor=self.executor, clock=lambda: self.start, use_alarm=False)
        report = scheduler.run_once(self.start)
        self.assertEqual(report["executed"][0]["outcome"], "success")
        self.assertEqual(report["executed"][0]["metrics_error"], "state_record_failed")
        self.assertFalse(report["executed"][0]["state_recorded"])
        self.assertEqual(report["status"], "degraded")

    def test_expected_defer_is_not_a_scheduler_failure(self):
        scheduler = Scheduler(store=FakeStore(), operations=(operation("alpha"),),
                              executor=lambda *_: {"status": "deferred", "reason": "adapter_missing"},
                              clock=lambda: self.start, use_alarm=False)
        report = scheduler.run_once(self.start)
        self.assertEqual(report["executed"][0]["outcome"], "deferred")
        self.assertEqual(report["status"], "ok")
        self.assertEqual(report["runtime_alert"]["status"], "recovered")

    def test_cycle_lock_prevents_a_second_cycle(self):
        store, job = FakeStore(), operation("alpha")
        store.cycle = True
        scheduler = Scheduler(store=store, operations=(job,), executor=self.executor,
                              clock=lambda: self.start, use_alarm=False)
        self.assertEqual(scheduler.run_once(self.start)["status"], "busy")
        self.assertEqual(self.calls, [])

    def test_heartbeat_is_private_and_only_written_by_a_clean_cycle(self):
        store = FakeStore()
        with tempfile.TemporaryDirectory() as directory:
            heartbeat = Path(directory) / "scheduler-heartbeat"
            scheduler = Scheduler(store=store, operations=(operation("alpha"),),
                                  executor=self.executor, clock=lambda: self.start,
                                  heartbeat_path=heartbeat, use_alarm=False)
            scheduler.run_once(self.start)
            self.assertTrue(heartbeat.is_file())
            self.assertEqual(heartbeat.stat().st_mode & 0o777, 0o600)

            def failing(job, context):
                raise RuntimeError("synthetic")

            failing_store = FakeStore()
            failing_heartbeat = Path(directory) / "failing-heartbeat"
            failing_scheduler = Scheduler(store=failing_store, operations=(operation("beta"),),
                                          executor=failing, clock=lambda: self.start,
                                          heartbeat_path=failing_heartbeat, use_alarm=False)
            failing_scheduler.run_once(self.start)
            self.assertFalse(failing_heartbeat.exists())

    def test_summary_is_scalar_only_and_bounded(self):
        self.assertEqual(safe_summary({"count": 1, "states": ["a", "b"]}),
                         {"count": 1, "states": ["a", "b"]})
        self.assertEqual(safe_summary({"x": {"a": {"b": {"c": 1}}}}),
                         {"summary": "unrepresentable"})
        self.assertEqual(safe_summary({"x": "a" * 513}), {"summary": "unrepresentable"})
        self.assertEqual(safe_summary({"x": object()}), {"summary": "unrepresentable"})
        self.assertEqual(safe_summary({"x": ["a" * 512 for _ in range(10)]}),
                         {"summary": "truncated"})


if __name__ == "__main__":
    unittest.main()
