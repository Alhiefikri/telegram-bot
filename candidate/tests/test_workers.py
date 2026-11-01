"""Worker contract, wakeup, metrics and bounded-loop tests without PostgreSQL."""
import os
from pathlib import Path
import tempfile
import time
import unittest
from dataclasses import replace
from unittest.mock import patch

from lhie_candidate.workers import contract, wakeup
from lhie_candidate.workers.consumer import BoundedConsumer
from lhie_candidate.workers.metrics import WorkerMetrics
from lhie_candidate.workers.supervisor import commands, run


class ContractTests(unittest.TestCase):
    def test_each_family_has_one_isolated_process(self):
        self.assertEqual(contract.QUEUES, ("interaction", "payment", "receipt"))
        self.assertEqual(len({spec.wakeup_queue for spec in contract.TOPOLOGY.values()}), 3)
        self.assertEqual(contract.TOPOLOGY["receipt"].kinds, ("effect", "order"))
        self.assertEqual(contract.TOPOLOGY["payment"].kinds, ("payment.event",))
        self.assertEqual(contract.TOPOLOGY["interaction"].kinds, ("navigation.update",))
        families = [kind for spec in contract.TOPOLOGY.values() for kind in spec.kinds]
        self.assertEqual(len(families), len(set(families)))
        self.assertFalse(set(families) & set(contract.RESERVED_FAMILIES))
        with self.assertRaises(ValueError):
            contract.queue_spec("notifications")
        with self.assertRaises(ValueError):
            contract.queue_spec(None)

    def test_timeout_lease_and_batches_stay_bounded(self):
        for spec in contract.TOPOLOGY.values():
            with self.subTest(queue=spec.name):
                self.assertEqual(spec.timeout_seconds, 60)
                self.assertEqual(spec.retry_after_seconds, 180)
                self.assertLess(spec.timeout_seconds, spec.retry_after_seconds)
                self.assertEqual(contract.LEASE_SECONDS, spec.retry_after_seconds)
                self.assertLessEqual(spec.batch, 25)
                self.assertLessEqual(spec.sweep_limit, 50)
                self.assertLessEqual(spec.sweep_seconds, 5)
                self.assertLess(contract.SHUTDOWN_GRACE_SECONDS, 180)
                self.assertIsNone(spec.max_attempts) if spec.name == "interaction" else self.assertEqual(spec.max_attempts, 3)

    def test_runtime_budget_matches_process_ownership(self):
        self.assertEqual(contract.worker_connection_budget(),
                         {"processes": 5, "threads": 5, "db_connections": 4})
        contract.assert_admitted_limits({"processes": 5, "threads": 5, "db_connections": 4})
        for limits in ({"processes": 4, "threads": 5, "db_connections": 4},
                       {"processes": 5, "threads": 5, "db_connections": 5},
                       None):
            with self.subTest(limits=limits), self.assertRaises(ValueError):
                contract.assert_admitted_limits(limits)

    def test_restart_budget_is_time_bounded(self):
        self.assertFalse(contract.restart_budget_exceeded([0, 1], 2))
        self.assertTrue(contract.restart_budget_exceeded([0, 1, 2], 3))
        self.assertFalse(contract.restart_budget_exceeded([0, 1, 2], 61))


class WakeupTests(unittest.TestCase):
    def test_integer_and_uuid_identities_round_trip(self):
        for kind, identity in (("navigation.update", 7), ("payment.event", 9),
                               ("order", "123e4567-e89b-12d3-a456-426614174000"),
                               ("effect", "123E4567-E89B-12D3-A456-426614174000")):
            with self.subTest(kind=kind):
                wire = wakeup.encode(kind, identity)
                queue = contract.KIND_TO_QUEUE[kind]
                decoded = wakeup.decode(queue, wire)
                self.assertEqual(decoded[0], kind)
                self.assertEqual(decoded[1], identity if type(identity) is int
                                 else "123e4567-e89b-12d3-a456-426614174000")
                self.assertNotIn('"version": 1', wire)

    def test_rejects_php_payloads_foreign_families_and_extra_data(self):
        for raw in (
            'O:16:"App\\Jobs\\Purchase":0:{}', 'a:1:{s:2:"id";i:1;}',
            '{"version":1,"kind":"payment.event","id":1}',
            '{"version":1,"kind":"navigation.update","id":1,"amount":15000}',
            '{"version":1,"kind":"navigation.update","id":true}',
            '{"version":1,"kind":"navigation.update","id":0}',
            '{"version":1,"kind":"navigation.update","id":-1}',
            '{"version":1,"kind":"navigation.update","id":1.0}',
            '{"version":1,"kind":"navigation.update","id":"1"}',
            '{"version":2,"kind":"navigation.update","id":1}',
            '{"version":1,"kind":"navigation.update","id":1,"id":2}',
            '{"version":1,"kind":"navigation.update","id":1} ',
            '{"version":1,"kind":"notification.admin","id":1}',
            '{"version":1,"kind":"navigation.update","id":1' + ', "x": "' + "a" * 200 + '"}',
            " " * 161, "", b"\xff", None,
        ):
            with self.subTest(raw=raw), self.assertRaises(wakeup.InvalidWakeup):
                wakeup.decode("candidate:interaction", raw)
        with self.assertRaises(wakeup.InvalidWakeup):
            wakeup.decode("candidate:receipt", '{"version":1,"kind":"navigation.update","id":1}')
        with self.assertRaises(wakeup.InvalidWakeup):
            wakeup.decode("candidate:unknown", '{"version":1,"kind":"navigation.update","id":1}')

    def test_enqueue_targets_only_the_owned_queue(self):
        class Connection:
            def __init__(self):
                self.sent = b""
                self.closed = False
            def __enter__(self):
                return self
            def __exit__(self, *_):
                self.closed = True
            def settimeout(self, _):
                pass
            def sendall(self, value):
                self.sent = value
            def recv(self, _):
                return b":1\r\n"

        connection = Connection()
        with patch.object(wakeup, "_connect", return_value=connection):
            self.assertTrue(wakeup.enqueue("navigation.update", 42))
        self.assertIn(b"LPUSH", connection.sent)
        self.assertIn(b"candidate:interaction", connection.sent)
        self.assertIn(b'{"version":1,"kind":"navigation.update","id":42}', connection.sent)
        self.assertNotIn(b"candidate:receipt", connection.sent)
        self.assertTrue(connection.closed)
        for kind, identity in (("effect", "not-a-uuid"), ("navigation.update", True),
                               ("navigation.update", 0), ("notification.admin", 1)):
            with self.subTest(kind=kind), self.assertRaises(wakeup.InvalidWakeup):
                wakeup.enqueue(kind, identity)


class MetricTests(unittest.TestCase):
    def test_bounded_secret_free_snapshot_and_private_file(self):
        metrics = WorkerMetrics("receipt", maximum=8)
        for index in range(20):
            metrics.record("handle", index / 1000)
        metrics.record("rejected")
        snapshot = metrics.snapshot()
        self.assertEqual(snapshot["queue"], "receipt")
        self.assertEqual(snapshot["counters"]["rejected"], 1)
        self.assertEqual(snapshot["counters"]["finished"], 0)
        self.assertEqual(snapshot["durations"]["handle"]["count"], 8)
        self.assertEqual(snapshot["durations"]["sweep"]["count"], 0)
        metrics.record("picked")
        metrics.record("handle", 0.01)
        self.assertEqual(metrics.snapshot()["counters"]["picked"], 1)
        self.assertEqual(metrics.snapshot()["durations"]["handle"]["count"], 8)
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "metrics.json"
            metrics.write(path)
            self.assertEqual(os.stat(path).st_mode & 0o777, 0o600)
            self.assertNotIn("synthetic-sentinel", path.read_text())
        for name, seconds in (("unknown", None), ("finished", -1), ("finished", 5000), ("finished", "1")):
            with self.subTest(name=name), self.assertRaises(ValueError):
                metrics.record(name, seconds)


class StubHandler:
    def __init__(self, outcomes=None):
        self.calls = []
        self.sweeps = 0
        self.outcomes = list(outcomes or [])

    def handle(self, raw):
        self.calls.append(raw)
        if self.outcomes:
            outcome = self.outcomes.pop(0)
            if outcome == "sleep":
                time.sleep(2)
            if outcome == "raise":
                raise RuntimeError("synthetic-sentinel")
        return "ok"

    def sweep(self, limit):
        self.sweeps += 1
        return {"limit": limit}


class ConsumerTests(unittest.TestCase):
    def test_bounded_loop_processes_only_valid_owned_wakeups(self):
        handler = StubHandler()
        metrics = WorkerMetrics("interaction")
        payloads = [wakeup.encode("navigation.update", 1).encode(), b'O:8:"stdClass":0:{}',
                    wakeup.encode("navigation.update", 2).encode()]
        deliver = iter(payloads)
        consumer = BoundedConsumer(contract.queue_spec("interaction"), handler, metrics,
                                   take=lambda queue: next(deliver, None))
        snapshot = consumer.run(max_iterations=4)
        self.assertEqual(handler.calls, [payloads[0], payloads[2]])
        self.assertEqual(snapshot["counters"]["finished"], 2)
        self.assertEqual(snapshot["counters"]["rejected"], 1)
        self.assertGreaterEqual(snapshot["counters"]["empty"], 1)
        self.assertEqual(snapshot["counters"]["sweep"], 1)

    def test_timeout_is_bounded_and_does_not_look_like_send_failure(self):
        spec = replace(contract.queue_spec("interaction"), timeout_seconds=1, retry_after_seconds=2)
        handler = StubHandler(["sleep"])
        metrics = WorkerMetrics("interaction")
        payloads = iter([wakeup.encode("navigation.update", 1).encode()])
        consumer = BoundedConsumer(spec, handler, metrics, take=lambda queue: next(payloads, None))
        started = time.monotonic()
        snapshot = consumer.run(max_iterations=2)
        self.assertLess(time.monotonic() - started, 1.8)
        self.assertEqual(snapshot["counters"]["timeout"], 1)
        self.assertEqual(snapshot["counters"]["finished"], 0)

    def test_handler_failure_is_bounded_and_stop_finishes_current_job(self):
        handler = StubHandler(["raise"])
        metrics = WorkerMetrics("interaction")
        consumer = BoundedConsumer(contract.queue_spec("interaction"), handler, metrics,
                                   take=lambda queue: wakeup.encode("navigation.update", 1).encode())
        handler.handle = lambda raw: (consumer.request_stop(), "ok")[1]
        snapshot = consumer.run(max_iterations=100)
        self.assertEqual(snapshot["counters"]["picked"], 1)
        self.assertEqual(snapshot["counters"]["finished"], 1)
        metrics = WorkerMetrics("interaction")
        payloads = iter([wakeup.encode("navigation.update", 1).encode(), wakeup.encode("navigation.update", 2).encode()])
        consumer = BoundedConsumer(contract.queue_spec("interaction"), StubHandler(["raise"]), metrics,
                                   take=lambda queue: next(payloads, None))
        snapshot = consumer.run(max_iterations=3)
        self.assertEqual(snapshot["counters"]["failed"], 1)

    def test_redis_unavailable_falls_back_to_durable_sweeps(self):
        handler = StubHandler()
        metrics = WorkerMetrics("receipt")
        spec = replace(contract.queue_spec("receipt"), sweep_seconds=1)
        moments = iter(range(1, 1000))
        consumer = BoundedConsumer(spec, handler, metrics,
                                   take=lambda queue: (_ for _ in ()).throw(OSError("synthetic")),
                                   clock=lambda: next(moments), sleep=lambda _: None)
        snapshot = consumer.run(max_iterations=3)
        self.assertEqual(snapshot["counters"]["redis_unavailable"], 3)
        self.assertGreaterEqual(handler.sweeps, 2)

    def test_invalid_iteration_bound_is_rejected(self):
        consumer = BoundedConsumer(contract.queue_spec("interaction"), StubHandler(),
                                   WorkerMetrics("interaction"), take=lambda queue: None)
        for value in (0, -1, 1.0, True, "1"):
            with self.subTest(value=value), self.assertRaises(ValueError):
                consumer.run(max_iterations=value)


class SupervisorTests(unittest.TestCase):
    def test_commands_include_requested_processes_only(self):
        self.assertEqual(set(commands("/tmp", include_http=True)), {"http", *contract.QUEUES})
        self.assertEqual(set(commands("/tmp", include_http=False)), set(contract.QUEUES))
        for queue in contract.QUEUES:
            self.assertIn("--queue", commands("/tmp", include_http=False)[queue])
            self.assertIn(queue, commands("/tmp", include_http=False)[queue])
        with tempfile.TemporaryDirectory() as directory:
            values = commands(directory, include_http=False)
            self.assertTrue(all(str(directory) in " ".join(command) for command in values.values()))

    def test_runner_rejects_missing_metrics_directory(self):
        with self.assertRaises(ValueError):
            run("/definitely/not/a/directory", include_http=False)


if __name__ == "__main__":
    unittest.main()
