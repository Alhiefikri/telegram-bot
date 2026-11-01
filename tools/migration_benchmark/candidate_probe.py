#!/usr/bin/env python3
"""P11 Django-side paired benchmark probe. Disposable candidate namespace only.

The probe mirrors ``probe.php``'s attempt contract without changing candidate
business code: the admitted WSGI application serves real HTTP ingress on
loopback, synchronized client processes submit one Telegram update per attempt,
and the real bounded interaction worker processes it. Instrumentation is
installed only in this probe process tree (``execute_wrapper`` plus two
per-process method wrappers); no product module gains a benchmark branch.

Evidence on disk is limited to protocol dimensions, monotonic timestamps,
outcome and error code. Update payloads, callbacks, customer identities and
rows stay in private ``/tmp`` state and are never printed or copied into the
evidence directory.
"""

import fcntl
import http.client
import json
import os
from pathlib import Path
import re
import signal
import socket
import subprocess
import sys
import threading
import time

EVIDENCE_PATTERN = re.compile(r"/evidence/[a-z0-9][a-z0-9_.-]{0,180}\.jsonl")
DATASETS = {
    "small": {"categories": 4, "families": 12, "products": 36, "customers": 20,
              "orders": 60, "payments": 60, "stock_items": 1840},
    "large": {"categories": 20, "families": 120, "products": 360, "customers": 200,
              "orders": 600, "payments": 600, "stock_items": 9616},
}
SCENARIOS = ("home_catalog", "catalog_plans", "plan_detail", "back", "home", "quantity", "orders",
             "balance")
SCREEN_METHODS = frozenset({
    "sendMessage", "sendPhoto", "sendAnimation", "editMessageText",
    "editMessageCaption", "editMessageMedia", "editMessageReplyMarkup",
})
SUCCESS_OUTCOMES = frozenset({"applied", "replaced", "established"})
BOUNDARIES = ("ingress", "ingress_ack", "interaction_queued", "pickup", "screen_ack")
QUEUES = ("interaction",)
DEFERRED_QUEUES = ("payment", "receipt")
WAKEUP_QUEUES = ("candidate:interaction", "candidate:payments", "candidate:telegram-receipts")
WEBHOOK_PATH = "/webhook/telegram"
BOT_SCOPE = "LhieStoreTestBot"
MAX_SAMPLES = 1000
ATTEMPT_TIMEOUT = 45.0
SETUP_TIMEOUT = 30.0
READY_TIMEOUT = 30.0


class Refused(Exception):
    """Sanitized probe failure with a stable error code."""

    def __init__(self, code="probe_error"):
        super().__init__(code)
        self.code = code


def now_ns():
    return time.monotonic_ns()


def append_json(path, record, mode=0o600):
    data = (json.dumps(record, separators=(",", ":"), allow_nan=False) + "\n").encode()
    descriptor = os.open(path, os.O_WRONLY | os.O_APPEND | os.O_CREAT, mode)
    try:
        fcntl.flock(descriptor, fcntl.LOCK_EX)
        os.write(descriptor, data)
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def write_private(path, text):
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    try:
        os.write(descriptor, text.encode())
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def evidence_path(payload):
    value = payload.get("evidence_path") if isinstance(payload, dict) else None
    if not isinstance(value, str) or EVIDENCE_PATTERN.fullmatch(value) is None:
        raise Refused()
    return value


def event_path(evidence):
    return "/tmp/p11-events-" + Path(evidence).stem + ".jsonl"


def write_failure(payload, error):
    """Private, sanitized trace location; never message text or row values."""
    try:
        import traceback
        evidence = evidence_path(payload)
        frames = traceback.extract_tb(error.__traceback__, limit=100)
        record = {'error_type': type(error).__name__,
                  'frames': [{'file': os.path.basename(frame.filename), 'line': frame.lineno}
                             for frame in frames]}
        write_private(evidence.removesuffix('.jsonl') + '.failure.json',
                      json.dumps(record, separators=(",", ":")))
    except Exception:
        pass


def write_trace(path, error):
    try:
        import traceback
        frames = traceback.extract_tb(error.__traceback__, limit=100)
        write_private(path, json.dumps({
            'error_type': type(error).__name__,
            'frames': [{'file': os.path.basename(frame.filename), 'line': frame.lineno}
                       for frame in frames]}, separators=(",", ":")))
    except Exception:
        pass


def validate_batch(payload):
    if not isinstance(payload, dict) or payload.get("mode") != "batch":
        raise Refused()
    if payload.get("implementation") != "django" or payload.get("transport") != "synthetic":
        raise Refused()
    if payload.get("dataset") not in DATASETS or payload.get("profile") != "current":
        raise Refused()
    if payload.get("scenario") not in SCENARIOS:
        raise Refused("scenario_error")
    if payload.get("users") not in (1, 5, 10):
        raise Refused()
    # Cold, same-chat and representative-background cells are deliberately not
    # in the P11 primary plan; a silent substitution would change the identity.
    if payload.get("traffic") != "distinct" or payload.get("temperature") != "warm":
        raise Refused("scenario_error")
    if payload.get("background") != "idle":
        raise Refused("scenario_error")
    for key in ("batch", "samples"):
        if type(payload.get(key)) is not int or not 1 <= payload[key] <= MAX_SAMPLES:
            raise Refused()
    if type(payload.get("warmup")) is not bool or type(payload.get("fresh_clone")) is not bool:
        raise Refused()
    evidence_path(payload)
    return payload


def bootstrap():
    os.environ.setdefault("DJANGO_SETTINGS_MODULE", "lhie_candidate.settings")
    from lhie_candidate.startup import configure_django
    configure_django()
    from lhie_candidate.readiness import verify_dependencies
    verify_dependencies()
    import django
    return django.get_version()


def database_counts():
    from django.db import connection
    with connection.cursor() as cursor:
        cursor.execute(
            "SELECT (SELECT count(id) FROM public.categories),"
            "(SELECT count(id) FROM public.product_families),"
            "(SELECT count(id) FROM public.products),"
            "(SELECT count(id) FROM public.customers),"
            "(SELECT count(id) FROM public.orders),"
            "(SELECT count(id) FROM public.payments),"
            "(SELECT count(product_id) FROM public.product_stock_items)"
        )
        row = cursor.fetchone()
    keys = ("categories", "families", "products", "customers", "orders", "payments", "stock_items")
    return dict(zip(keys, (int(value) for value in row)))


def navigation_state():
    from django.db import connection
    with connection.cursor() as cursor:
        cursor.execute(
            "SELECT count(*) FILTER (WHERE state='pending'),"
            "count(*) FILTER (WHERE state='processing'),"
            "count(*) FILTER (WHERE state='processed'),"
            "count(*) FILTER (WHERE state='unknown') "
            "FROM django_candidate.navigation_updates"
        )
        pending, processing, processed, unknown = cursor.fetchone()
        cursor.execute("SELECT count(*) FROM django_candidate.navigation_roots")
        roots = cursor.fetchone()[0]
        cursor.execute(
            "SELECT count(*) FROM pg_catalog.pg_stat_activity "
            "WHERE datname = pg_catalog.current_database()"
        )
        connections = cursor.fetchone()[0]
    return {"pending": int(pending), "processing": int(processing),
            "processed": int(processed), "unknown": int(unknown),
            "roots": int(roots), "db_connections": int(connections)}


def population():
    """Only tables the admitted navigation grants can read; never a projection edit."""
    from django.db import connection
    with connection.cursor() as cursor:
        cursor.execute(
            "SELECT (SELECT count(id) FROM public.orders),"
            "(SELECT count(id) FROM public.payments),"
            "(SELECT count(product_id) FROM public.product_stock_items)"
        )
        orders, payments, stock_items = cursor.fetchone()
        cursor.execute(
            "SELECT count(*), count(*) FILTER (WHERE state='unknown') "
            "FROM django_candidate.navigation_updates"
        )
        updates, unknown = cursor.fetchone()
    return {"orders": int(orders), "payments": int(payments),
            "stock_items": int(stock_items), "updates": int(updates),
            "unknown_updates": int(unknown)}


def admission(dataset, fresh_clone):
    try:
        from django.db import connection
        with connection.cursor() as cursor:
            cursor.execute("SELECT current_database(), current_user")
            if cursor.fetchone() != ("disposable", "candidate_runtime"):
                raise Refused()
    except Refused:
        raise
    except Exception:
        raise Refused() from None
    actual = database_counts()
    if actual != DATASETS[dataset]:
        raise Refused("scenario_error")
    if fresh_clone:
        state = navigation_state()
        if any(state[key] for key in ("pending", "processing", "processed", "unknown", "roots")):
            raise Refused("scenario_error")
    return actual


class Events:
    """Incremental reader over the private per-update event spool."""

    def __init__(self, path):
        self.path = path
        self.offset = 0
        self.records = {}

    def read(self):
        try:
            with open(self.path, "rb") as stream:
                stream.seek(self.offset)
                data = stream.read()
        except FileNotFoundError:
            return
        end = data.rfind(b"\n")
        if end < 0:
            return
        self.offset += end + 1
        for line in data[:end + 1].splitlines():
            try:
                record = json.loads(line)
            except (ValueError, UnicodeError):
                continue
            if not isinstance(record, dict) or type(record.get("update_id")) is not int:
                continue
            merged = self.records.setdefault(record["update_id"], {
                "timestamps": dict.fromkeys(BOUNDARIES), "db_ms": None,
                "outcome": None, "completed": False, "callbacks": [],
            })
            boundary = record.get("boundary")
            if boundary in BOUNDARIES and type(record.get("ns")) is int:
                merged["timestamps"][boundary] = record["ns"]
            elif boundary == "db" and type(record.get("db_ms")) in (int, float):
                merged["db_ms"] = float(record["db_ms"])
            elif boundary == "completed":
                merged["completed"] = True
                value = record.get("outcome")
                # A stale wakeup after the sweep already processed the row is a
                # duplicate no-op, not the update's terminal outcome.
                if merged["outcome"] in (None, "duplicate") and value != "duplicate":
                    merged["outcome"] = value
                elif merged["outcome"] is None:
                    merged["outcome"] = value
            elif boundary == "error" and isinstance(record.get("error_type"), str):
                merged["error_type"] = record["error_type"][:64]
            elif boundary == "callbacks" and isinstance(record.get("callbacks"), list):
                merged["callbacks"] = [value for value in record["callbacks"]
                                       if isinstance(value, str) and 1 <= len(value.encode()) <= 64]

    def get(self, update_id):
        return self.records.get(update_id)


def instrument(handler, events):
    """Wrap the real processor/transport inside this worker process only."""
    from django.db import connection
    from lhie_candidate.navigation.processor import NavigationProcessor
    from lhie_candidate.navigation.transport import RelayTransport

    current = {"update_id": None}
    original_process = NavigationProcessor.process
    original_execute = RelayTransport.execute

    def process(self, update_id):
        current["update_id"] = update_id
        append_json(events, {"update_id": update_id, "boundary": "pickup", "ns": now_ns()})
        total = 0.0

        def wrapper(execute, sql, params, many, context):
            nonlocal total
            started = time.monotonic()
            try:
                return execute(sql, params, many, context)
            finally:
                total += time.monotonic() - started

        outcome = "exception"
        try:
            with connection.execute_wrapper(wrapper):
                outcome = original_process(self, update_id)
            return outcome
        except BaseException as error:
            append_json(events, {"update_id": update_id, "boundary": "error",
                                 "error_type": type(error).__name__})
            raise
        finally:
            current["update_id"] = None
            append_json(events, {"update_id": update_id, "boundary": "db", "db_ms": total * 1000.0})
            append_json(events, {"update_id": update_id, "boundary": "completed", "outcome": outcome})

    def execute(self, method, payload):
        result = original_execute(self, method, payload)
        update_id = current["update_id"]
        if update_id is not None and method in SCREEN_METHODS and getattr(result, "outcome", None) == "success":
            append_json(events, {"update_id": update_id, "boundary": "screen_ack", "ns": now_ns()})
            callbacks = []
            markup = payload.get("reply_markup") if isinstance(payload, dict) else None
            rows = markup.get("inline_keyboard") if isinstance(markup, dict) else None
            for row in rows if isinstance(rows, list) else []:
                for button in row if isinstance(row, list) else []:
                    value = button.get("callback_data") if isinstance(button, dict) else None
                    if isinstance(value, str) and 1 <= len(value.encode()) <= 64:
                        callbacks.append(value)
            append_json(events, {"update_id": update_id, "boundary": "callbacks", "callbacks": callbacks})
        return result

    NavigationProcessor.process = process
    RelayTransport.execute = execute


def worker_main(queue, events, ready_path, failure_path):
    if queue not in QUEUES:
        raise Refused()
    bootstrap()
    from lhie_candidate.workers.consumer import BoundedConsumer
    from lhie_candidate.workers.contract import queue_spec
    from lhie_candidate.workers.metrics import WorkerMetrics
    from lhie_candidate.workers.services import build_handler
    handler = build_handler(queue)
    if queue == "interaction":
        instrument(handler, events)
    consumer = BoundedConsumer(queue_spec(queue), handler, WorkerMetrics(queue))
    signal.signal(signal.SIGTERM, consumer.request_stop)
    signal.signal(signal.SIGINT, consumer.request_stop)
    write_private(ready_path, str(os.getpid()))
    consumer.run()


def install_queue_recorder():
    from lhie_candidate.workers import wakeup
    original = wakeup.enqueue
    queued = {}

    def enqueue(kind, identity, **kwargs):
        if kind == "navigation.update" and type(identity) is int:
            queued.setdefault(identity, now_ns())
        return original(kind, identity, **kwargs)

    wakeup.enqueue = enqueue
    return queued


class TimedApplication:
    """Measures ingress query time per attempt without changing the view path."""

    def __init__(self, application):
        self.application = application
        self.db_ms = {}
        self.lock = threading.Lock()

    def __call__(self, environ, start_response):
        from django.db import connections
        attempt = environ.get("HTTP_X_P11_ATTEMPT", "")
        total = 0.0

        def wrapper(execute, sql, params, many, context):
            nonlocal total
            started = time.monotonic()
            try:
                return execute(sql, params, many, context)
            finally:
                total += time.monotonic() - started

        with connections["default"].execute_wrapper(wrapper):
            result = self.application(environ, start_response)
        if attempt.isdigit():
            with self.lock:
                self.db_ms[int(attempt)] = total * 1000.0
        return result


class ProbeServer:
    """Same WSGI request path as serve.py, with a probe-sized accept backlog."""

    request_queue_size = 64

    def __init__(self, application):
        from wsgiref.simple_server import WSGIRequestHandler, WSGIServer

        class Server(WSGIServer):
            request_queue_size = 64

            def handle_error(self, request, client_address):
                pass

        class Handler(WSGIRequestHandler):
            def setup(self):
                self.request.settimeout(5)
                super().setup()

            def log_message(self, *args):
                pass

            def get_stderr(self):
                return self.server.discard

            def send_error(self, code, message=None, explain=None):
                body = b'{"status":"bad_request"}'
                self.send_response(code)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(body)))
                self.send_header("Connection", "close")
                self.end_headers()
                if getattr(self, "command", "") != "HEAD":
                    self.wfile.write(body)
                self.close_connection = True

        self.server = Server(("127.0.0.1", 8080), Handler)
        self.server.set_app(application)
        self.server.discard = open(os.devnull, "w")
        self.thread = threading.Thread(
            target=self.server.serve_forever, kwargs={"poll_interval": 0.05}, daemon=True)

    def start(self):
        self.thread.start()

    def stop(self):
        try:
            self.server.shutdown()
            self.server.server_close()
        finally:
            self.thread.join(timeout=5)


def client_main():
    """Persistent warm client: one loopback POST per job; no database connection."""
    for line in sys.stdin.buffer:
        try:
            job = json.loads(line)
            if not isinstance(job, dict) or not isinstance(job.get("payload"), dict):
                return 0
            body = json.dumps(job["payload"], separators=(",", ":"))
            headers = {"Content-Type": "application/json",
                       "X-Telegram-Bot-Api-Secret-Token": str(job["secret"]),
                       "X-P11-Attempt": str(job["ordinal"])}
            go_path = job["go_path"]
            while not os.path.exists(go_path):
                time.sleep(0.001)
            connection = http.client.HTTPConnection("127.0.0.1", 8080, timeout=15)
            started = now_ns()
            try:
                connection.request("POST", WEBHOOK_PATH, body, headers)
                response = connection.getresponse()
                response.read(4096)
                status = response.status
            except (OSError, http.client.HTTPException):
                status = 0
            finally:
                connection.close()
            finished = now_ns()
            sys.stdout.write(json.dumps({
                "ordinal": job["ordinal"], "update_id": job["update_id"],
                "ingress": started, "ingress_ack": finished, "status": status,
            }, separators=(",", ":")) + "\n")
            sys.stdout.flush()
        except (ValueError, KeyError, TypeError):
            sys.stdout.write('{"error_code":"probe_error"}\n')
            sys.stdout.flush()
            return 1
    return 0


def spawn_client():
    return subprocess.Popen([sys.executable, __file__, "--client"], stdin=subprocess.PIPE,
                            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True, bufsize=1)


def spawn_worker(queue, events, ready_path, failure_path):
    return subprocess.Popen([sys.executable, __file__, "--worker", queue, events, ready_path,
                             failure_path],
                            stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                            stderr=subprocess.DEVNULL)


def wait_ready(ready_paths, processes, deadline=READY_TIMEOUT):
    end = time.monotonic() + deadline
    while time.monotonic() < end:
        if all(os.path.isfile(path) for path in ready_paths):
            return
        for process in processes:
            if process.poll() is not None:
                raise Refused()
        time.sleep(0.01)
    raise Refused("timeout")


def stop_processes(processes):
    for process in processes:
        if process.poll() is None:
            process.terminate()
    for process in processes:
        try:
            process.wait(timeout=15)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait(timeout=5)


class Driver:
    def __init__(self, events, queued, timed):
        self.events = events
        self.queued = queued
        self.timed = timed
        self.sequence = 1000000000
        self.secret = None
        self.client = None
        self.customers = []

    def next_update(self):
        self.sequence += 1
        return self.sequence

    def post_direct(self, body):
        response = self.client.post(
            WEBHOOK_PATH, data=json.dumps(body, separators=(",", ":")),
            content_type="application/json",
            HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN=self.secret,
            HTTP_ACCEPT="application/json")
        if response.status_code != 200:
            raise Refused("unexpected_response")

    def root_message_id(self, chat):
        from django.db import connection
        with connection.cursor() as cursor:
            cursor.execute(
                "SELECT message_id FROM django_candidate.navigation_roots "
                "WHERE bot_asset_scope=%s AND chat_id=%s", [BOT_SCOPE, str(chat)])
            row = cursor.fetchone()
        if row is None or type(row[0]) is not int or row[0] < 1:
            raise Refused("scenario_error")
        return row[0]

    def await_update(self, update_id, timeout=SETUP_TIMEOUT):
        from django.db import connection
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            self.events.read()
            record = self.events.get(update_id)
            with connection.cursor() as cursor:
                cursor.execute(
                    "SELECT state FROM django_candidate.navigation_updates WHERE update_id=%s",
                    [update_id])
                row = cursor.fetchone()
            if row is None or row[0] == "unknown":
                raise Refused("scenario_error")
            if row[0] == "processed" and record is not None and record["completed"]:
                return record
            time.sleep(0.002)
        raise Refused("timeout")

    def message(self, chat, text):
        update_id = self.next_update()
        self.post_direct({
            "update_id": update_id,
            "message": {"message_id": update_id,
                        "chat": {"id": chat, "type": "private"},
                        "from": {"id": chat, "is_bot": False, "first_name": "P04 Buyer"},
                        "text": text},
        })
        return self.await_update(update_id)

    def callback(self, chat, data, update_id=None):
        update_id = update_id or self.next_update()
        self.post_direct({
            "update_id": update_id,
            "callback_query": {"id": "p11-" + str(update_id),
                               "from": {"id": chat, "is_bot": False, "first_name": "P04 Buyer"},
                               "message": {"message_id": self.root_message_id(chat),
                                           "chat": {"id": chat, "type": "private"}},
                               "data": data},
        })
        return self.await_update(update_id)

    @staticmethod
    def control(record, prefix, exact=False):
        for value in (record or {}).get("callbacks", []):
            if value == prefix if exact else value.startswith(prefix):
                return value
        raise Refused("scenario_error")

    def setup(self, chat, scenario):
        home = self.message(chat, "/start")
        if scenario == "home_catalog":
            return self.control(home, "katalog", exact=True)
        if scenario == "orders":
            return self.control(home, "menu:orders", exact=True)
        if scenario == "balance":
            return self.control(home, "balance", exact=True)
        catalog = self.callback(chat, "katalog")
        options = self.control(catalog, "family:options:")
        if scenario == "catalog_plans":
            return options
        if scenario == "home":
            return "family:back:menu"
        family = self.callback(chat, options)
        select = self.control(family, "family:select:")
        if scenario == "plan_detail":
            return select
        detail = self.callback(chat, select)
        prefix = {"back": "detail:back:", "quantity": "detail:qty:+:"}[scenario]
        return self.control(detail, prefix)

    def wait_idle(self, timeout=90.0):
        from django.db import connection
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            with connection.cursor() as cursor:
                cursor.execute(
                    "SELECT count(*) FROM django_candidate.navigation_updates "
                    "WHERE state IN ('pending','processing')")
                if cursor.fetchone()[0] == 0:
                    return
            time.sleep(0.005)
        raise Refused("timeout")

    def measured_payload(self, chat, callback):
        update_id = self.next_update()
        return update_id, {
            "update_id": update_id,
            "callback_query": {"id": "p11-" + str(update_id),
                               "from": {"id": chat, "is_bot": False, "first_name": "P04 Buyer"},
                               "message": {"message_id": self.root_message_id(chat),
                                           "chat": {"id": chat, "type": "private"}},
                               "data": callback},
        }


def record(payload, attempt, outcome, error_code):
    return {
        "schema_version": 1, "implementation": "django", "transport": "synthetic",
        "dataset": payload["dataset"], "profile": payload["profile"],
        "scenario": payload["scenario"], "users": payload["users"],
        "traffic": payload["traffic"], "temperature": payload["temperature"],
        "background": payload["background"], "batch": payload["batch"],
        "attempt": attempt, "warmup": payload["warmup"], "outcome": outcome,
        "retries": 0, "timestamps": dict.fromkeys(BOUNDARIES), "db_ms": None,
        "error_code": error_code,
    }


def wakeup_depths():
    depths = {}
    for queue in WAKEUP_QUEUES:
        try:
            with socket.create_connection(("127.0.0.1", 6379), timeout=1) as connection:
                command = b"*2\r\n$4\r\nLLEN\r\n$" + str(len(queue)).encode() + b"\r\n" + queue.encode() + b"\r\n"
                connection.sendall(command)
                response = connection.recv(64)
                depths[queue] = int(response[1:-2]) if response.startswith(b":") else None
        except (OSError, ValueError):
            depths[queue] = None
    return depths


def batch(payload):
    from django.test import Client
    from lhie_candidate.navigation.webhook import webhook_secret
    evidence = evidence_path(payload)
    events_path = event_path(evidence)
    write_private(events_path, "")
    for queue in QUEUES:
        try:
            os.unlink("/tmp/p11-ready-" + queue)
        except FileNotFoundError:
            pass
    actual = admission(payload["dataset"], payload["fresh_clone"])
    queued = install_queue_recorder()
    from lhie_candidate.startup import create_application
    timed = TimedApplication(create_application())
    server = ProbeServer(timed)
    server.start()
    driver = Driver(Events(events_path), queued, timed)
    driver.secret = webhook_secret()
    driver.client = Client(SERVER_NAME="127.0.0.1")
    from django.db import connection
    with connection.cursor() as cursor:
        cursor.execute(
            "SELECT id, telegram_id FROM public.customers "
            "WHERE telegram_id IS NOT NULL ORDER BY telegram_id LIMIT 10")
        rows = cursor.fetchall()
    if len(rows) < payload["users"]:
        raise Refused("scenario_error")
    driver.customers = [{"id": row[0], "telegram_id": row[1]} for row in rows]
    # Warmup and measurement share one clone; a repeated update identity would be
    # acknowledged as a duplicate and never re-rendered.
    with connection.cursor() as cursor:
        cursor.execute("SELECT COALESCE(MAX(update_id), 0) FROM django_candidate.navigation_updates")
        driver.sequence = max(driver.sequence, int(cursor.fetchone()[0]))

    ready_paths = ["/tmp/p11-ready-" + queue for queue in QUEUES]
    failure_paths = [evidence.removesuffix('.jsonl') + '.worker-' + queue + '.failure.json'
                     for queue in QUEUES]
    workers = [spawn_worker(queue, events_path, ready, failure)
               for queue, ready, failure in zip(QUEUES, ready_paths, failure_paths)]
    clients = []
    phases = {}
    phase_started = now_ns()

    def mark(name):
        nonlocal phase_started
        moment = now_ns()
        phases[name] = phases.get(name, 0) + (moment - phase_started) / 1_000_000
        phase_started = moment

    samples = []
    worker_outcomes = {}
    handler_errors = {}

    def note_outcome(update_id):
        event = driver.events.get(update_id)
        if event is None:
            return
        worker_outcomes[event.get("outcome") or "unrecorded"] = (
            worker_outcomes.get(event.get("outcome") or "unrecorded", 0) + 1)
        if event.get("error_type"):
            handler_errors[event["error_type"]] = handler_errors.get(event["error_type"], 0) + 1

    def persist(sample):
        samples.append(sample)
        append_json(evidence, sample)

    try:
        wait_ready(ready_paths, workers)
        mark("start_workers")
        before = population()
        clients = [spawn_client() for _ in range(min(payload["users"], payload["samples"]))]
        for first in range(0, payload["samples"], payload["users"]):
            width = min(payload["users"], payload["samples"] - first)
            try:
                callbacks = [driver.setup(driver.customers[lane]["telegram_id"], payload["scenario"])
                             for lane in range(width)]
            except Refused:
                for lane in range(width):
                    persist(record(payload, first + lane + 1, "error", "scenario_error"))
                mark("context_setup")
                continue
            mark("context_setup")
            try:
                driver.wait_idle()
            except Refused:
                for lane in range(width):
                    persist(record(payload, first + lane + 1, "timeout", "timeout"))
                mark("drain_before")
                continue
            mark("drain_before")
            go_path = "/tmp/p11-go-" + Path(evidence).stem + "-" + str(first)
            try:
                os.unlink(go_path)
            except FileNotFoundError:
                pass
            jobs = []
            for lane in range(width):
                update_id, body = driver.measured_payload(
                    driver.customers[lane]["telegram_id"], callbacks[lane])
                jobs.append({"ordinal": first + lane + 1, "lane": lane,
                             "update_id": update_id, "body": body})
            for job in jobs:
                process = clients[job["lane"]]
                process.stdin.write(json.dumps({
                    "ordinal": job["ordinal"], "update_id": job["update_id"],
                    "payload": job["body"], "secret": driver.secret, "go_path": go_path,
                }, separators=(",", ":")) + "\n")
                process.stdin.flush()
            Path(go_path).write_text("1", encoding="ascii")
            results = {}
            for job in jobs:
                line = clients[job["lane"]].stdout.readline()
                if not line:
                    results[job["ordinal"]] = {"status": 0}
                    continue
                try:
                    value = json.loads(line)
                except (ValueError, UnicodeError):
                    value = {"status": 0}
                results[value.get("ordinal", job["ordinal"])] = value
            mark("request_wave")
            for job in jobs:
                sample = record(payload, job["ordinal"], "unknown", "boundary_unknown")
                result = results.get(job["ordinal"], {})
                if result.get("status") != 200:
                    sample["outcome"] = "error"
                    sample["error_code"] = "unexpected_response"
                    persist(sample)
                    continue
                sample["timestamps"]["ingress"] = result.get("ingress")
                sample["timestamps"]["ingress_ack"] = result.get("ingress_ack")
                sample["timestamps"]["interaction_queued"] = driver.queued.get(job["update_id"])
                try:
                    event = driver.await_update(job["update_id"], timeout=ATTEMPT_TIMEOUT)
                except Refused as error:
                    note_outcome(job["update_id"])
                    sample["outcome"] = "timeout" if error.code == "timeout" else "error"
                    sample["error_code"] = error.code
                    persist(sample)
                    continue
                for name in ("pickup", "screen_ack"):
                    sample["timestamps"][name] = event["timestamps"][name]
                sample["db_ms"] = timed.db_ms.get(job["ordinal"], 0.0) + (event["db_ms"] or 0.0)
                note_outcome(job["update_id"])
                if sample["timestamps"]["screen_ack"] is not None and event.get("outcome") in SUCCESS_OUTCOMES:
                    sample["outcome"] = "success"
                    sample["error_code"] = None
                else:
                    sample["outcome"] = "error"
                    sample["error_code"] = "scenario_error"
                persist(sample)
            mark("attempt_collection")
            try:
                driver.wait_idle()
            except Refused:
                pass
            mark("drain_after")
        after = population()
        state = navigation_state()
        mark("completed")
    finally:
        stop_processes(clients)
        stop_processes(workers)
        server.stop()
    return {"samples": samples, "accounting": {
        "harness_phase_ms": phases,
        "attempts": len(samples),
        "successes": sum(row["outcome"] == "success" for row in samples),
        "worker_processes": len(QUEUES),
        "worker_policy": {"queues": list(QUEUES), "deferred_queues": list(DEFERRED_QUEUES),
                          "deferred_reason": "payment and receipt handlers require money/effect authority outside the navigation grant envelope",
                          "connection": "one-per-worker", "timeout_seconds": 60,
                          "retry_after_seconds": 180,
                          "daemon": "bounded-probe-loop-not-supervisor-daemon"},
        "request_model": "admitted-single-threaded-wsgi-request-path-on-loopback-with-synchronized-client-processes",
        "warm_model": "persistent-client-processes-and-persistent-queue-workers-per-batch",
        "cold_model": "not_planned_for_p11_primary_cells",
        "profile_model": "source-candidate-runtime-single-profile",
        "background_model": "idle-real-workers-with-source-sweeps-no-injected-job",
        "worker_outcomes": dict(sorted(worker_outcomes.items())),
        "handler_errors": dict(sorted(handler_errors.items())),
        "dataset": payload["dataset"],
        "dataset_counts": actual,
        "state_model": "php-seeded-pristine-postgresql-clone-with-explicit-candidate-navigation-schema",
        "population_before": before,
        "population_after": after,
        "navigation_state": state,
        "wakeup_depths": wakeup_depths(),
    }}


def main():
    if os.environ.get("P11_BENCHMARK") != "disposable-local-only":
        raise Refused()
    argv = sys.argv[1:]
    os.umask(0o077)
    if argv == ["--client"]:
        return client_main()
    if len(argv) == 5 and argv[0] == "--worker":
        try:
            worker_main(argv[1], argv[2], "/tmp/p11-ready-" + argv[1], argv[3])
        except BaseException as error:
            write_trace(argv[3], error)
            raise
        return 0
    if argv:
        raise Refused()
    try:
        payload = json.loads(sys.stdin.read(65537))
    except (ValueError, UnicodeError):
        raise Refused() from None
    try:
        version = bootstrap()
        payload = validate_batch(payload)
        result = batch(payload)
    except Refused as error:
        write_failure(payload, error)
        raise
    except Exception as error:
        write_failure(payload, error)
        raise Refused() from None
    result["accounting"]["django_version"] = version
    print(json.dumps(result, separators=(",", ":"), allow_nan=False))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except SystemExit:
        raise
    except Refused as error:
        print(json.dumps({"error_code": error.code}))
        raise SystemExit(1) from None
    except Exception:
        print('{"error_code":"probe_error"}')
        raise SystemExit(1) from None
