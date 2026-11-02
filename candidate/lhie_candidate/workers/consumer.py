"""One bounded process per queue; no blocking job is shared with another queue."""

import signal
import threading
import time

from . import wakeup
from .contract import IDLE_POLL_SECONDS


class WorkerTimeout(BaseException):
    """Deliberately not an Exception: handlers must not mistake it for a send failure."""


def close_connections(*, discard=False):
    try:
        # Respect CONN_MAX_AGE on success: a busy worker keeps its single
        # admitted connection, while an expired/unusable one is closed before
        # the next task. A timeout/failure discards it so no aborted session
        # state survives; the role budget still reserves one per worker.
        from django.db import close_old_connections, connections
        if discard:
            connections.close_all()
        else:
            close_old_connections()
    except Exception:
        # Cleanup must never replace the recorded job outcome.
        pass


def _alarm_handler(*_):
    raise WorkerTimeout()


class BoundedConsumer:
    def __init__(self, spec, handler, metrics, *, take=None, clock=time.monotonic,
                 sleep=time.sleep):
        self.spec = spec
        self.handler = handler
        self.metrics = metrics
        self.take = take or (lambda queue: wakeup.take(queue, timeout=int(IDLE_POLL_SECONDS)))
        self.clock = clock
        self.sleep = sleep
        self.stopped = threading.Event()

    def request_stop(self, *_):
        self.stopped.set()

    def _bounded(self, function, *args):
        if threading.current_thread() is not threading.main_thread():
            return function(*args)
        previous = signal.signal(signal.SIGALRM, _alarm_handler)
        signal.setitimer(signal.ITIMER_REAL, self.spec.timeout_seconds)
        try:
            return function(*args)
        finally:
            signal.setitimer(signal.ITIMER_REAL, 0)
            signal.signal(signal.SIGALRM, previous)

    def _sweep(self):
        started = self.clock()
        discard = False
        try:
            self._bounded(self.handler.sweep, self.spec.sweep_limit)
        except WorkerTimeout:
            discard = True
            self.metrics.record("sweep_timeout")
        except Exception:
            discard = True
            self.metrics.record("sweep_failed")
        else:
            self.metrics.record("sweep", self.clock() - started)
        finally:
            close_connections(discard=discard)

    def _job(self, raw):
        try:
            wakeup.decode(self.spec.wakeup_queue, raw)
        except wakeup.InvalidWakeup:
            self.metrics.record("rejected")
            return
        self.metrics.record("picked")
        started = self.clock()
        discard = False
        try:
            self._bounded(self.handler.handle, raw)
        except WorkerTimeout:
            discard = True
            self.metrics.record("timeout")
        except Exception:
            discard = True
            self.metrics.record("failed")
        else:
            self.metrics.record("finished")
            self.metrics.record("handle", self.clock() - started)
        finally:
            close_connections(discard=discard)

    def run(self, *, max_iterations=None):
        if max_iterations is not None and (type(max_iterations) is not int or not 1 <= max_iterations <= 10000):
            raise ValueError("Invalid bounded iteration limit")
        iterations = 0
        next_sweep = 0.0
        while not self.stopped.is_set():
            if self.clock() >= next_sweep:
                self._sweep()
                next_sweep = self.clock() + self.spec.sweep_seconds
            try:
                raw = self.take(self.spec.wakeup_queue)
            except (OSError, ValueError):
                # Redis is a hint only. Keep sweeping PostgreSQL instead of
                # spinning or surfacing a payload-bearing exception.
                self.metrics.record("redis_unavailable")
                self.sleep(0.2)
            else:
                if raw is None:
                    self.metrics.record("empty")
                else:
                    self._job(raw)
            iterations += 1
            if max_iterations is not None and iterations >= max_iterations:
                break
        return self.metrics.snapshot()
