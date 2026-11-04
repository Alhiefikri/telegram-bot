"""Payment sweeps bound each external operation independently."""
import time
import unittest
from dataclasses import replace
from unittest.mock import patch

from lhie_candidate.workers.consumer import BoundedConsumer
from lhie_candidate.workers.contract import queue_spec
from lhie_candidate.workers.metrics import WorkerMetrics
from lhie_candidate.workers.payment import PaymentHandler


class Database:
    def __init__(self, navigation):
        self.navigation = navigation

    def rows(self, sql, parameters):
        if "navigation_updates updates" in sql:
            attempted = parameters[0]
            return [{"update_id": identity} for identity in self.navigation
                    if identity not in attempted][:1]
        if "o.expires_at" in sql:
            return []
        if "payment_events e" in sql:
            return [{"id": 10}]
        return [{"id": 20}]


class Processor:
    family_filter = ""

    def __init__(self, navigation, delay=0):
        self.navigation = navigation
        self.delay = delay
        self.completed = []

    def recover(self, limit):
        return 0

    def process(self, identity):
        self.navigation.remove(identity)
        time.sleep(self.delay)
        self.completed.append(identity)
        return "applied"


class Lifecycle:
    def __init__(self, delay=0):
        self.delay = delay
        self.completed = []

    def consume_callbacks(self, identity):
        time.sleep(self.delay)
        self.completed.append(("hint", identity))

    def recover(self, identity):
        time.sleep(self.delay)
        self.completed.append(("recovery", identity))


class PaymentSweepTimeoutTests(unittest.TestCase):
    def test_each_slow_job_gets_a_fresh_alarm_even_when_redis_is_down(self):
        navigation = [1, 2, 3]
        processor = Processor(navigation, delay=0.09)
        lifecycle = Lifecycle(delay=0.09)
        handler = PaymentHandler(lifecycle, Database(navigation), processor=processor)
        spec = replace(queue_spec("payment"), timeout_seconds=0.25)
        metrics = WorkerMetrics("payment")

        def unavailable(_queue):
            raise OSError("unavailable")

        consumer = BoundedConsumer(spec, handler, metrics, take=unavailable, sleep=lambda _: None)
        with patch("lhie_candidate.workers.consumer.close_connections"):
            snapshot = consumer.run(max_iterations=1)

        self.assertEqual(processor.completed, [1, 2, 3])
        self.assertEqual(lifecycle.completed, [("hint", 10), ("recovery", 20)])
        self.assertEqual(snapshot["counters"]["sweep_timeout"], 0)
        self.assertEqual(snapshot["counters"]["redis_unavailable"], 1)

    def test_timeout_discards_connection_and_next_sweep_can_reach_hints(self):
        navigation = [1]
        processor = Processor(navigation, delay=0.3)
        lifecycle = Lifecycle()
        handler = PaymentHandler(lifecycle, Database(navigation), processor=processor)
        spec = replace(queue_spec("payment"), timeout_seconds=0.08)
        metrics = WorkerMetrics("payment")
        consumer = BoundedConsumer(spec, handler, metrics)
        with patch("lhie_candidate.workers.consumer.close_connections") as close:
            consumer._sweep()
            consumer._sweep()
        self.assertEqual(processor.completed, [])
        self.assertEqual(lifecycle.completed, [("hint", 10), ("recovery", 20)])
        self.assertEqual(metrics.snapshot()["counters"]["sweep_timeout"], 1)
        self.assertTrue(close.call_args_list[0].kwargs["discard"])

    def test_scheduling_budget_finishes_started_job_and_rotates_phases(self):
        now = [0.0]
        order = []
        navigation = [1, 2, 3, 4]
        processor = Processor(navigation)
        lifecycle = Lifecycle()
        process = processor.process

        def navigation_job(identity):
            result = process(identity)
            order.append(("navigation", identity))
            now[0] += 2
            return result

        def hint_job(identity):
            order.append(("hint", identity))
            now[0] += 2

        def recovery_job(identity):
            order.append(("recovery", identity))
            now[0] += 2

        processor.process = navigation_job
        lifecycle.consume_callbacks = hint_job
        lifecycle.recover = recovery_job
        handler = PaymentHandler(lifecycle, Database(navigation), processor=processor)
        consumer = BoundedConsumer(
            queue_spec("payment"), handler, WorkerMetrics("payment"), clock=lambda: now[0],
        )
        with patch("lhie_candidate.workers.consumer.JOB_TIMEOUT_SECONDS", 1), \
             patch("lhie_candidate.workers.consumer.close_connections"):
            for _ in range(4):
                consumer._sweep()
        self.assertEqual(order, [
            ("navigation", 1), ("navigation", 2), ("hint", 10), ("recovery", 20),
        ])
        self.assertEqual(navigation, [3, 4])

    def test_busy_navigation_does_not_block_other_chats_or_payment_work(self):
        navigation = [1, 2]
        processor = Processor(navigation)
        process = processor.process

        def sometimes_busy(identity):
            return "busy" if identity == 1 else process(identity)

        processor.process = sometimes_busy
        lifecycle = Lifecycle()
        handler = PaymentHandler(lifecycle, Database(navigation), processor=processor)
        consumer = BoundedConsumer(queue_spec("payment"), handler, WorkerMetrics("payment"))
        with patch("lhie_candidate.workers.consumer.close_connections"):
            consumer._sweep()
        self.assertEqual(processor.completed, [2])
        self.assertEqual(navigation, [1])
        self.assertEqual(lifecycle.completed, [("hint", 10), ("recovery", 20)])

    def test_shutdown_between_jobs_does_not_start_another_operation(self):
        navigation = [1, 2]
        processor = Processor(navigation)
        lifecycle = Lifecycle()
        handler = PaymentHandler(lifecycle, Database(navigation), processor=processor)
        consumer = BoundedConsumer(queue_spec("payment"), handler, WorkerMetrics("payment"))
        process = processor.process

        def stop_after_job(identity):
            result = process(identity)
            consumer.request_stop()
            return result

        processor.process = stop_after_job
        with patch("lhie_candidate.workers.consumer.close_connections"):
            consumer._sweep()
        self.assertEqual(processor.completed, [1])
        self.assertEqual(navigation, [2])
        self.assertEqual(lifecycle.completed, [])
