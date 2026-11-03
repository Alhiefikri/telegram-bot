"""Manual Testing Bot QA: one persistent consumer fed admitted JSON updates on stdin."""
import json
import queue
import re
import sys
import threading
import time


_INTEGRATED_CALLBACK = re.compile(
    r'(?:katalog|catalog|catalog:refresh|catalog:categories|back:categories|'
    r'menu:home|menu:back|menu:orders|orders:open|family:back:menu|'
    r'orders:(?:older|newer):[0-9]{14}:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|'
    r'category:(?:[1-9][0-9]*|uncategorized)(?::page:[1-9][0-9]*)?|'
    r'family:list:[1-9][0-9]*(?::o[a-f0-9]{16})?|'
    r'family:options:v[A-Za-z0-9_-]+(?::[os][a-f0-9]{16})?|'
    r'family:select:v[A-Za-z0-9_-]+|'
    r'product:[1-9][0-9]*(?::o[a-f0-9]{16})?|'
    r'detail:back:[1-9][0-9]*:[a-f0-9]{16}|'
    r'detail:qty:(?:-|\+|current|tier):[1-9][0-9]*:[a-f0-9]{16}|'
    r'detail:pay:qris:[1-9][0-9]*:[A-Za-z0-9_-]+|'
    r'checkout:check:[0-9a-fA-F-]{36})\Z'
)


def integrated_update_allowed(payload):
    if not isinstance(payload, dict) or (('message' in payload) == ('callback_query' in payload)):
        return False
    if 'message' in payload:
        message = payload['message']
        return (isinstance(message, dict)
                and set(message) <= {'message_id', 'from', 'chat', 'date', 'text', 'entities'}
                and message.get('text') == '/start')
    callback = payload['callback_query']
    if not isinstance(callback, dict) or not isinstance(callback.get('message'), dict):
        return False
    message = callback['message']
    if not set(message) <= {'message_id', 'from', 'chat', 'date', 'edit_date',
                            'text', 'entities', 'photo', 'caption', 'caption_entities',
                            'reply_markup', 'has_protected_content'}:
        return False
    data = callback.get('data')
    return isinstance(data, str) and _INTEGRATED_CALLBACK.fullmatch(data) is not None


class EmptyAckWorker:
    def __init__(self, transport, capacity=1):
        if type(capacity) is not int or capacity < 1:
            raise ValueError('ACK queue capacity must be positive')
        self.transport = transport
        self.pending = queue.Queue(maxsize=capacity)
        self.stopping = threading.Event()
        self.completed = {'success': 0, 'unconfirmed': 0}
        self.lock = threading.Lock()
        self.thread = threading.Thread(target=self._run, daemon=True)
        self.thread.start()

    def submit(self, payload):
        while True:
            with self.lock:
                if self.stopping.is_set() or not self.thread.is_alive():
                    return False
                try:
                    self.pending.put_nowait(payload)
                    return True
                except queue.Full:
                    pass
            self.stopping.wait(0.05)

    def _run(self):
        while not self.stopping.is_set() or not self.pending.empty():
            try:
                payload = self.pending.get(timeout=0.05)
            except queue.Empty:
                continue
            try:
                try:
                    response = self.transport._request('answerCallbackQuery', payload)
                    confirmed = (isinstance(response, dict) and response.get('ok') is True
                                 and response.get('result') is True)
                except Exception:
                    confirmed = False
                with self.lock:
                    self.completed['success' if confirmed else 'unconfirmed'] += 1
            finally:
                self.pending.task_done()

    def close(self, timeout=18):
        with self.lock:
            self.stopping.set()
        self.thread.join(timeout)
        return not self.thread.is_alive()

    def snapshot(self):
        with self.lock:
            return dict(self.completed)


class MeasuredTransport:
    def __init__(self, transport):
        self.transport = transport
        self.reset()

    def reset(self):
        self.started = time.monotonic_ns()
        self.operations = []
        self.callback_answered = False
        self.empty_ack = None

    def execute(self, method, payload):
        if method == 'answerCallbackQuery':
            self.callback_answered = True
        timings = getattr(self.transport, 'relay_timings', None)
        if timings is not None:
            timings.clear()
        started = time.monotonic_ns()
        try:
            result = self.transport.execute(method, payload)
        except Exception:
            self.operations.append({'method': method, 'outcome': 'exception',
                                    'ms': (time.monotonic_ns() - started) / 1e6})
            raise
        operation = {'method': method, 'outcome': result.outcome,
                     'ms': (time.monotonic_ns() - started) / 1e6,
                     'end_ms': (time.monotonic_ns() - self.started) / 1e6}
        if timings:
            operation['relay'] = list(timings)
        self.operations.append(operation)
        return result


def process_update(admitted, processor, transport, payload, ack_worker):
    outcome = processor.process(admitted.update_id)
    callback = payload.get('callback_query')
    if callback and not transport.callback_answered and outcome not in ('duplicate', 'busy', 'waiting'):
        queued = ack_worker.submit({
            'chat_id': callback['message']['chat']['id'], 'callback_query_id': callback['id']})
        transport.empty_ack = 'queued' if queued else 'cancelled'
    return outcome


def main():
    if sys.argv[1:] not in (['--testing-bot-stdin'], ['--testing-bot-workers-stdin']):
        raise ValueError('Explicit interactive QA mode required')
    integrated = sys.argv[1:] == ['--testing-bot-workers-stdin']
    if integrated:
        from lhie_candidate.runtime import load_runtime
        if not load_runtime().workers:
            raise ValueError('Worker admission required')
    from lhie_candidate.startup import configure_django
    configure_django()
    from lhie_candidate.readiness import verify_dependencies
    verify_dependencies()
    from django.db import connection
    from lhie_candidate.navigation.ingress import UpdateInbox, InvalidUpdate
    from lhie_candidate.navigation.repository import CatalogRepository
    from lhie_candidate.navigation.presentation import CatalogPresenter
    from lhie_candidate.navigation.processor import NavigationProcessor
    from lhie_candidate.navigation.roots import NavigationRoots
    from lhie_candidate.navigation.transport import RelayTransport

    class ProfiledRelayTransport(RelayTransport):
        def __init__(self):
            super().__init__()
            self.relay_timings = []

        def _request(self, method, payload):
            response = super()._request(method, payload)
            timing = response.get('_relay_timing') if isinstance(response, dict) else None
            if isinstance(timing, dict):
                self.relay_timings.append(timing)
            return response

    if integrated:
        ack_worker = EmptyAckWorker(RelayTransport())
        try:
            run_worker_stream(InvalidUpdate, UpdateInbox(), ack_worker)
        finally:
            drained = ack_worker.close()
            print(json.dumps({'status': 'ack_summary', 'drained': drained,
                              'completed': ack_worker.snapshot()}), flush=True)
        return

    repository = CatalogRepository()
    transport = MeasuredTransport(ProfiledRelayTransport())
    scope = 'LhieStoreTestBot'
    banner = repository.banner_asset(scope) or {}
    roots = NavigationRoots(transport, bot_asset_scope=scope,
                            banner_reference=banner.get('file_id'), banner_version=banner.get('version'))
    processor = NavigationProcessor(repository, CatalogPresenter(repository, bot_scope=scope, custom_emoji=True), roots, transport)
    inbox = UpdateInbox()
    ack_worker = EmptyAckWorker(RelayTransport())
    try:
        run_stream(connection, InvalidUpdate, inbox, processor, transport, ack_worker)
    finally:
        drained = ack_worker.close()
        print(json.dumps({'status': 'ack_summary', 'drained': drained,
                          'completed': ack_worker.snapshot()}), flush=True)


def run_worker_stream(invalid_update, inbox, ack_worker):
    print(json.dumps({'status': 'ready'}), flush=True)
    while True:
        line = sys.stdin.buffer.readline(16386)
        if not line:
            return
        if len(line) > 16384 or not line.endswith(b'\n'):
            raise ValueError('Bounded update required')
        try:
            payload = json.loads(line)
            if not integrated_update_allowed(payload):
                raise ValueError('Update outside integrated scope')
            admitted = inbox.receive(payload)
        except (invalid_update, ValueError, UnicodeError):
            print(json.dumps({'status': 'rejected'}), flush=True)
            return
        if admitted.response.get('method') == 'answerCallbackQuery':
            callback = payload['callback_query']
            if not ack_worker.submit({'chat_id': callback['message']['chat']['id'],
                                      'callback_query_id': admitted.response['callback_query_id']}):
                raise ValueError('Callback acknowledgement unavailable')
        print(json.dumps({'status': 'admitted'}), flush=True)


def run_stream(connection, invalid_update, inbox, processor, transport, ack_worker):
    print(json.dumps({'status':'ready'}), flush=True)
    while True:
        line = sys.stdin.buffer.readline(16386)
        if not line:
            return
        if len(line) > 16384 or not line.endswith(b'\n'):
            raise ValueError('Bounded update required')
        transport.reset()
        db = {'queries': 0, 'ms': 0.0}

        def measure_query(execute, sql, params, many, context):
            started = time.monotonic_ns()
            try:
                return execute(sql, params, many, context)
            finally:
                db['queries'] += 1
                db['ms'] += (time.monotonic_ns() - started) / 1e6

        try:
            payload = json.loads(line)
        except (ValueError, UnicodeError):
            print(json.dumps({'status':'rejected'}), flush=True)
            continue
        with connection.execute_wrapper(measure_query):
            try:
                admitted = inbox.receive(payload)
            except (invalid_update, ValueError, UnicodeError):
                print(json.dumps({'status':'rejected'}), flush=True)
                continue
            admission_ms = (time.monotonic_ns() - transport.started) / 1e6
            outcome = process_update(admitted, processor, transport, payload, ack_worker)
        print(json.dumps({'status': 'processed', 'outcome': outcome,
                          'total_ms': (time.monotonic_ns() - transport.started) / 1e6,
                          'admission_ms': admission_ms,
                          'database': db, 'telegram': transport.operations,
                          'empty_ack': transport.empty_ack,
                          'empty_ack_completed': ack_worker.snapshot()}), flush=True)


if __name__ == '__main__':
    try:
        main()
    except Exception:
        print('{"status":"interactive_navigation_refused"}', flush=True)
        raise SystemExit(1) from None
