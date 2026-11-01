"""Bounded, secret-free worker timing and resource counters.

Durable timestamps remain authoritative: navigation accepted/claimed/completed,
payment received/paid, and receipt effect created/first pickup/sent. These
counters explain a run without carrying identities, payloads or credentials.
"""

from collections import deque
import json
import os
from pathlib import Path
import tempfile


COUNTERS = (
    "empty",
    "failed",
    "finished",
    "picked",
    "rejected",
    "redis_unavailable",
    "sweep",
    "sweep_failed",
    "sweep_timeout",
    "timeout",
)
DURATIONS = ("handle", "sweep")


class WorkerMetrics:
    def __init__(self, queue, *, maximum=256):
        if not isinstance(queue, str) or not queue:
            raise ValueError("A worker queue is required")
        if type(maximum) is not int or not 8 <= maximum <= 4096:
            raise ValueError("Invalid metrics bound")
        self.queue = queue
        self.maximum = maximum
        self._counters = dict.fromkeys(COUNTERS, 0)
        self._durations = {name: deque(maxlen=maximum) for name in DURATIONS}

    def record(self, name, seconds=None):
        if name in self._counters:
            self._counters[name] += 1
        elif name not in self._durations:
            raise ValueError("Unknown worker metric")
        if seconds is not None:
            if name not in self._durations:
                raise ValueError("Unknown worker duration")
            if type(seconds) not in (int, float) or not 0 <= seconds <= 3600:
                raise ValueError("Invalid worker duration")
            self._durations[name].append(round(float(seconds) * 1000, 3))

    def snapshot(self):
        durations = {}
        for name, values in self._durations.items():
            ordered = sorted(values)
            durations[name] = {
                "count": len(ordered),
                "max_ms": ordered[-1] if ordered else 0.0,
                "min_ms": ordered[0] if ordered else 0.0,
                "total_ms": round(sum(ordered), 3),
            }
        return {"queue": self.queue, "counters": dict(self._counters), "durations": durations}

    def write(self, path):
        target = Path(path)
        directory = target.parent
        if not directory.is_dir():
            raise ValueError("Metrics directory is unavailable")
        descriptor, temporary = tempfile.mkstemp(prefix=".worker-metrics-", dir=directory)
        try:
            with os.fdopen(descriptor, "w") as stream:
                json.dump(self.snapshot(), stream, sort_keys=True, separators=(",", ":"))
                stream.write("\n")
            os.chmod(temporary, 0o600)
            os.replace(temporary, target)
        except Exception:
            try:
                os.unlink(temporary)
            except OSError:
                pass
            raise
