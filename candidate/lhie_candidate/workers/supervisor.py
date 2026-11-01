"""Bounded child supervision for the disposable candidate runtime.

The parent holds no database or Redis connection. It starts one HTTP process and
one process per queue, restarts an unexpectedly exited child a bounded number of
times, and gives every child the same bounded graceful-shutdown window.
"""

import signal
import subprocess
import sys
import time
from pathlib import Path

from .contract import (QUEUES, RESTART_LIMIT, RESTART_WINDOW_SECONDS,
                       SHUTDOWN_GRACE_SECONDS)


ROOT = Path(__file__).resolve().parents[2]
DEFAULT_METRICS_DIR = Path("/tmp")


def _spawn(command):
    return subprocess.Popen(
        command,
        cwd=ROOT,
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        close_fds=True,
    )


def commands(metrics_dir, *, include_http):
    directory = Path(metrics_dir)
    values = {}
    if include_http:
        values["http"] = [sys.executable, str(ROOT / "serve.py")]
    for queue in QUEUES:
        values[queue] = [
            sys.executable,
            str(ROOT / "workers.py"),
            "--queue",
            queue,
            "--metrics",
            str(directory / f"{queue}.json"),
        ]
    return values


def run(metrics_dir=DEFAULT_METRICS_DIR, *, include_http=True):
    directory = Path(metrics_dir)
    if not directory.is_dir():
        raise ValueError("Metrics directory is unavailable")
    planned = commands(directory, include_http=include_http)
    running = {}
    restarts = {name: [] for name in planned}
    stopping = False
    deadline = None

    def stop(*_):
        nonlocal stopping, deadline
        if stopping:
            return
        stopping = True
        deadline = time.monotonic() + SHUTDOWN_GRACE_SECONDS
        for process in running.values():
            if process.poll() is None:
                process.terminate()

    previous_term = signal.signal(signal.SIGTERM, stop)
    previous_int = signal.signal(signal.SIGINT, stop)
    try:
        for name, command in planned.items():
            running[name] = _spawn(command)
        while True:
            now = time.monotonic()
            for name, process in list(running.items()):
                if process.poll() is None:
                    continue
                del running[name]
                if stopping:
                    continue
                recent = [moment for moment in restarts[name]
                          if 0 <= now - moment < RESTART_WINDOW_SECONDS]
                if len(recent) >= RESTART_LIMIT:
                    stop()
                    break
                restarts[name] = [*recent, now]
                running[name] = _spawn(planned[name])
            if stopping:
                if not running:
                    return 0
                if deadline is not None and now >= deadline:
                    for process in running.values():
                        if process.poll() is None:
                            process.kill()
                    for process in running.values():
                        process.wait()
                    return 0
            else:
                for name, command in planned.items():
                    if name not in running:
                        running[name] = _spawn(command)
            time.sleep(0.1)
    finally:
        signal.signal(signal.SIGTERM, previous_term)
        signal.signal(signal.SIGINT, previous_int)
        for process in running.values():
            if process.poll() is None:
                process.kill()
                process.wait()
