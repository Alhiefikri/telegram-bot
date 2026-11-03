import importlib.util
import io
import json
from pathlib import Path
import threading
import unittest
from contextlib import nullcontext, redirect_stdout
from types import SimpleNamespace
from unittest.mock import patch


spec = importlib.util.spec_from_file_location(
    'interactive_ack_runner', Path(__file__).resolve().parents[1] / 'interactive_navigation.py')
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


def callback(query='query'):
    return {'callback_query': {'id': query, 'data': 'katalog', 'message': {'chat': {'id': 1}}}}


class BlockingRelay:
    def __init__(self):
        self.started = threading.Event()
        self.release = threading.Event()
        self.calls = []

    def _request(self, method, payload):
        self.calls.append((method, payload))
        self.started.set()
        self.release.wait(5)
        return {'ok': True, 'result': True}


class InteractiveAckTests(unittest.TestCase):
    def test_next_callback_processes_while_previous_ack_is_blocked(self):
        relay = BlockingRelay()
        worker = runner.EmptyAckWorker(relay)
        self.addCleanup(worker.close)
        self.addCleanup(relay.release.set)
        calls = []

        class Processor:
            def process(self, update_id):
                calls.append(update_id)
                return 'applied'

        measured = runner.MeasuredTransport(object())
        self.assertEqual(runner.process_update(SimpleNamespace(update_id=1), Processor(),
                                               measured, callback('one'), worker), 'applied')
        self.assertTrue(relay.started.wait(1))
        measured.reset()
        self.assertEqual(runner.process_update(SimpleNamespace(update_id=2), Processor(),
                                               measured, callback('two'), worker), 'applied')
        self.assertEqual(calls, [1, 2])
        self.assertEqual(measured.empty_ack, 'queued')
        self.assertEqual(len(relay.calls), 1)
        relay.release.set()
        worker.pending.join()
        self.assertEqual([payload['callback_query_id'] for _, payload in relay.calls], ['one', 'two'])
        self.assertEqual(measured.operations, [])

    def test_feedback_never_gets_a_blank_even_on_ambiguous_result(self):
        for outcome in ('success', 'ambiguous'):
            with self.subTest(outcome=outcome):
                sent = []

                class Transport:
                    def execute(self, method, payload):
                        sent.append((method, payload))
                        return SimpleNamespace(outcome=outcome)

                class NoBlank:
                    def submit(self, payload):
                        raise AssertionError('blank ACK was scheduled')

                measured = runner.MeasuredTransport(Transport())

                class Processor:
                    def process(self, update_id):
                        measured.execute('answerCallbackQuery', {
                            'chat_id': 1, 'callback_query_id': 'query', 'text': 'Recovery'})
                        return 'stale'

                runner.process_update(SimpleNamespace(update_id=1), Processor(), measured, callback(), NoBlank())
                self.assertEqual(len(sent), 1)
                self.assertEqual(sent[0][1]['text'], 'Recovery')
                self.assertIsNone(measured.empty_ack)

    def test_feedback_exception_marks_answer_attempted(self):
        class Transport:
            def execute(self, method, payload):
                raise OSError('ambiguous')

        measured = runner.MeasuredTransport(Transport())

        class Processor:
            def process(self, update_id):
                try:
                    measured.execute('answerCallbackQuery', {'text': 'Recovery'})
                except OSError:
                    pass
                return 'stale'

        class NoBlank:
            def submit(self, payload):
                raise AssertionError('blank ACK was scheduled')

        runner.process_update(SimpleNamespace(update_id=1), Processor(), measured, callback(), NoBlank())
        self.assertTrue(measured.callback_answered)

    def test_ack_request_is_not_retried_on_failure_or_ambiguous_response(self):
        for response in ({}, {'ok': False, 'error_code': 400, 'description': 'custom emoji'},
                         OSError('unknown'), RuntimeError('failed')):
            with self.subTest(response=repr(response)):
                done = threading.Event()
                calls = []

                class Relay:
                    def _request(self, method, payload):
                        calls.append((method, payload))
                        done.set()
                        if isinstance(response, BaseException):
                            raise response
                        return response

                worker = runner.EmptyAckWorker(Relay())
                try:
                    self.assertTrue(worker.submit({'chat_id': 1, 'callback_query_id': 'query'}))
                    self.assertTrue(done.wait(1))
                    worker.pending.join()
                    self.assertEqual(len(calls), 1)
                    self.assertEqual(worker.snapshot(), {'success': 0, 'unconfirmed': 1})
                finally:
                    worker.close()

    def test_full_queue_backpressures_until_space_is_available(self):
        relay = BlockingRelay()
        worker = runner.EmptyAckWorker(relay)
        self.addCleanup(worker.close)
        self.addCleanup(relay.release.set)
        self.assertTrue(worker.thread.daemon)
        self.assertTrue(worker.submit({'callback_query_id': 'one'}))
        self.assertTrue(relay.started.wait(1))
        self.assertTrue(worker.submit({'callback_query_id': 'two'}))
        finished = threading.Event()
        results = []

        def submit_third():
            results.append(worker.submit({'callback_query_id': 'three'}))
            finished.set()

        producer = threading.Thread(target=submit_third, daemon=True)
        producer.start()
        self.assertFalse(finished.wait(0.1))
        relay.release.set()
        self.assertTrue(finished.wait(1))
        producer.join(1)
        worker.pending.join()
        self.assertEqual(results, [True])
        self.assertEqual([payload['callback_query_id'] for _, payload in relay.calls], ['one', 'two', 'three'])

    def test_shutdown_is_bounded_and_interrupts_blocked_submission(self):
        relay = BlockingRelay()
        worker = runner.EmptyAckWorker(relay)
        self.addCleanup(relay.release.set)
        self.assertTrue(worker.submit({'callback_query_id': 'one'}))
        self.assertTrue(relay.started.wait(1))
        self.assertTrue(worker.submit({'callback_query_id': 'two'}))
        finished = threading.Event()
        results = []

        def submit_third():
            results.append(worker.submit({'callback_query_id': 'three'}))
            finished.set()

        producer = threading.Thread(target=submit_third, daemon=True)
        producer.start()
        self.assertFalse(finished.wait(0.1))
        self.assertFalse(worker.close(timeout=0.01))
        self.assertTrue(worker.thread.is_alive())
        self.assertTrue(finished.wait(1))
        producer.join(1)
        self.assertEqual(results, [False])
        self.assertFalse(worker.submit({'callback_query_id': 'four'}))
        relay.release.set()
        worker.thread.join(1)
        self.assertFalse(worker.thread.is_alive())
        self.assertEqual([payload['callback_query_id'] for _, payload in relay.calls], ['one', 'two'])
        for capacity in (0, -1, True):
            with self.subTest(capacity=capacity), self.assertRaises(ValueError):
                runner.EmptyAckWorker(relay, capacity=capacity)

    def test_unowned_and_duplicate_updates_do_not_schedule_ack(self):
        class NoBlank:
            def submit(self, payload):
                raise AssertionError('unowned update scheduled an ACK')

        for outcome in ('duplicate', 'busy', 'waiting'):
            with self.subTest(outcome=outcome):
                processor = SimpleNamespace(process=lambda update_id: outcome)
                measured = runner.MeasuredTransport(object())
                self.assertEqual(runner.process_update(SimpleNamespace(update_id=1), processor,
                                                       measured, callback(), NoBlank()), outcome)
                self.assertIsNone(measured.empty_ack)

    def test_stream_emits_one_result_per_update_without_async_events(self):
        relay = BlockingRelay()
        worker = runner.EmptyAckWorker(relay)
        self.addCleanup(worker.close)
        self.addCleanup(relay.release.set)
        connection = SimpleNamespace(execute_wrapper=lambda wrapper: nullcontext())
        inbox = SimpleNamespace(receive=lambda payload: SimpleNamespace(update_id=payload['update_id']))
        processor = SimpleNamespace(process=lambda update_id: 'applied')
        measured = runner.MeasuredTransport(object())
        source = io.TextIOWrapper(io.BytesIO(b''.join(
            (json.dumps({'update_id': n, **callback(str(n))}) + '\n').encode() for n in (1, 2))))
        output = io.StringIO()
        with patch.object(runner.sys, 'stdin', source), redirect_stdout(output):
            runner.run_stream(connection, ValueError, inbox, processor, measured, worker)
            self.assertTrue(relay.started.wait(1))
            relay.release.set()
            worker.pending.join()
        lines = [json.loads(line) for line in output.getvalue().splitlines()]
        self.assertEqual([line['status'] for line in lines], ['ready', 'processed', 'processed'])
        self.assertEqual([line['empty_ack'] for line in lines[1:]], ['queued', 'queued'])
        self.assertEqual([line['telegram'] for line in lines[1:]], [[], []])

    def test_integrated_stream_only_admits_updates_to_worker_inbox(self):
        received = []
        answers = []
        def receive(payload):
            received.append(payload)
            response = ({'method': 'answerCallbackQuery', 'callback_query_id': 'query'}
                        if 'callback_query' in payload else {'ok': True})
            return SimpleNamespace(response=response, duplicate=True)
        inbox = SimpleNamespace(receive=receive)
        ack_worker = SimpleNamespace(submit=lambda payload: answers.append(payload) or True)
        payloads = [{'update_id': 1, **callback()}, {'update_id': 2, 'message': {'text': '/start'}}]
        source = io.TextIOWrapper(io.BytesIO(b''.join(
            (json.dumps(payload) + '\n').encode() for payload in payloads)))
        output = io.StringIO()
        with patch.object(runner.sys, 'stdin', source), redirect_stdout(output):
            runner.run_worker_stream(ValueError, inbox, ack_worker)
        self.assertEqual(received, payloads)
        self.assertEqual(answers, [{'chat_id': 1, 'callback_query_id': 'query'}])
        self.assertEqual([json.loads(line) for line in output.getvalue().splitlines()],
                         [{'status': 'ready'}, {'status': 'admitted'}, {'status': 'admitted'}])

    def test_integrated_stream_stops_on_invalid_admission(self):
        received = []
        def receive(payload):
            received.append(payload)
            raise ValueError('private detail')
        source = io.TextIOWrapper(io.BytesIO(b'{"message":{"text":"/start"}}\n{}\n'))
        output = io.StringIO()
        ack_worker = SimpleNamespace(submit=lambda payload: self.fail('rejected update answered'))
        with patch.object(runner.sys, 'stdin', source), redirect_stdout(output):
            runner.run_worker_stream(ValueError, SimpleNamespace(receive=receive), ack_worker)
        self.assertEqual(received, [{'message': {'text': '/start'}}])
        self.assertEqual([json.loads(line) for line in output.getvalue().splitlines()],
                         [{'status': 'ready'}, {'status': 'rejected'}])

    def test_integrated_admission_sends_callback_answer_through_bounded_relay(self):
        relay = BlockingRelay()
        relay.release.set()
        ack_worker = runner.EmptyAckWorker(relay)
        admitted = []
        def receive(payload):
            admitted.append(payload)
            return SimpleNamespace(response={'method': 'answerCallbackQuery',
                                             'callback_query_id': payload['callback_query']['id']})
        source = io.TextIOWrapper(io.BytesIO((json.dumps({'update_id': 1, **callback()}) + '\n').encode()))
        output = io.StringIO()
        try:
            with patch.object(runner.sys, 'stdin', source), redirect_stdout(output):
                runner.run_worker_stream(ValueError, SimpleNamespace(receive=receive), ack_worker)
        finally:
            drained = ack_worker.close()
        self.assertTrue(drained)
        self.assertEqual(len(admitted), 1)
        self.assertEqual(relay.calls, [('answerCallbackQuery', {'chat_id': 1, 'callback_query_id': 'query'})])
        self.assertEqual(ack_worker.snapshot(), {'success': 1, 'unconfirmed': 0})
        self.assertNotIn('query', output.getvalue())

    def test_integrated_scope_blocks_financial_and_service_events_before_inbox(self):
        for payload in (
            {'message': {'text': '/deposit 1000'}},
            {'message': {'text': '/start', 'pinned_message': {}}},
            {'message': {'text': '/start', 'new_chat_members': []}},
            {'message': {'new_chat_members': []}},
            {'callback_query': {'message': {'new_chat_members': []}, 'data': 'katalog'}},
            {'callback_query': {'message': {}, 'data': 'balance:add'}},
            {'callback_query': {'message': {}, 'data': 'detail:pay:balance:1:token'}},
            {'callback_query': {'message': {}, 'data': 'detail:pay:binance:1:token'}},
        ):
            with self.subTest(payload=payload):
                source = io.TextIOWrapper(io.BytesIO((json.dumps(payload) + '\n').encode()))
                output = io.StringIO()
                inbox = SimpleNamespace(receive=lambda value: self.fail('rejected update admitted'))
                ack = SimpleNamespace(submit=lambda value: self.fail('rejected update answered'))
                with patch.object(runner.sys, 'stdin', source), redirect_stdout(output):
                    runner.run_worker_stream(ValueError, inbox, ack)
                self.assertEqual([json.loads(line)['status'] for line in output.getvalue().splitlines()],
                                 ['ready', 'rejected'])

    def test_integrated_scope_accepts_qris_navigation_and_check(self):
        callbacks = ('katalog', 'category:1', 'family:options:vSigned:o0123456789abcdef',
                     'family:select:vSigned', 'product:1:o0123456789abcdef', 'menu:orders',
                     'orders:open', 'orders:older:20261102232100:00000000-0000-0000-0000-000000000001',
                     'detail:qty:+:1:0123456789abcdef',
                     'detail:pay:qris:1:0123456789abcdef',
                     'checkout:check:00000000-0000-0000-0000-000000000001')
        for data in callbacks:
            with self.subTest(data=data):
                self.assertTrue(runner.integrated_update_allowed(
                    {'callback_query': {'message': {}, 'data': data}}))
