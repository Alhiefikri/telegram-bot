"""Bounded supplier recovery; a qualified caller supplies the transport explicitly.

This is not installed in the default supervisor: supplier egress and an extra
runtime connection require separate admission. PostgreSQL remains the queue.
"""
import time

from ..workers.consumer import BoundedConsumer
from ..workers.contract import IDLE_POLL_SECONDS, QueueSpec
from ..workers.metrics import WorkerMetrics


SPEC = QueueSpec(name='supplier', wakeup_queue='candidate:supplier',
                 kinds=('supplier.recovery',), batch=1, sweep_seconds=5,
                 sweep_limit=25, max_attempts=None)


class SupplierRecoveryWorker:
    def __init__(self, acquisition):
        self.acquisition = acquisition
        self.metrics = WorkerMetrics('supplier')
        self.consumer = BoundedConsumer(SPEC, self, self.metrics, take=self._idle)

    @staticmethod
    def _idle(_queue):
        time.sleep(IDLE_POLL_SECONDS)
        return None

    def sweep_bounded(self, limit, bounded, stopped):
        for fulfillment_id in self.acquisition.due_ids(limit):
            if stopped():
                break
            try:
                bounded(self.acquisition.process, fulfillment_id)
            except Exception:
                self.metrics.record('failed')

    def run(self, *, max_iterations=None):
        return self.consumer.run(max_iterations=max_iterations)

    def stop(self, *_):
        self.consumer.request_stop()
