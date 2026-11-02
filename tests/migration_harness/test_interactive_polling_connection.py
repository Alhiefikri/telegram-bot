import http.client
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import threading
import unittest
from unittest.mock import MagicMock, patch


TOOLS = Path(__file__).resolve().parents[2] / 'tools/migration_harness'
sys.path.insert(0, str(TOOLS))
spec = importlib.util.spec_from_file_location('p06_polling_runner', TOOLS / 'interactive_navigation.py')
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class PollingConnectionTests(unittest.TestCase):
    def test_reuses_one_connection_and_preserves_payload_and_bot_scope(self):
        requests = []

        class Handler(BaseHTTPRequestHandler):
            protocol_version = 'HTTP/1.1'

            def do_POST(self):
                body = self.rfile.read(int(self.headers['Content-Length']))
                requests.append((self.client_address, self.path, json.loads(body)))
                response = b'{"ok":true,"result":[]}'
                self.send_response(200)
                self.send_header('Content-Length', str(len(response)))
                self.end_headers()
                self.wfile.write(response)

            def log_message(self, *args):
                pass

        server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        connection = http.client.HTTPConnection('127.0.0.1', server.server_port, timeout=2)
        try:
            with patch.object(runner.http.client, 'HTTPSConnection', return_value=connection) as factory:
                polling = runner.PollingConnection({'bot_token':'synthetic'})
                try:
                    for offset in (0, 42):
                        self.assertEqual(polling.get_updates({'offset':offset,'timeout':10,'limit':25,
                            'allowed_updates':['message','callback_query']}), [])
                finally:
                    polling.close()
            factory.assert_called_once_with('api.telegram.org', timeout=25)
            self.assertEqual(len(requests), 2)
            self.assertEqual(requests[0][0], requests[1][0])
            self.assertEqual([request[1] for request in requests],
                             ['/botsynthetic/getUpdates', '/botsynthetic/getUpdates'])
            self.assertEqual([request[2] for request in requests], [
                {'offset':offset,'timeout':10,'limit':25,'allowed_updates':['message','callback_query']}
                for offset in (0, 42)])
        finally:
            server.shutdown()
            server.server_close()
            thread.join()

    def test_oversized_response_is_bounded_and_connection_is_discarded(self):
        connection = MagicMock()
        response = connection.getresponse.return_value
        response.status = 200
        response.read.return_value = b'x' * 262145
        with patch.object(runner.http.client, 'HTTPSConnection', return_value=connection):
            polling = runner.PollingConnection({'bot_token':'synthetic'})
            with self.assertRaisesRegex(ValueError, 'Oversized'):
                polling.get_updates({'offset':0})
        response.read.assert_called_once_with(262145)
        connection.request.assert_called_once()
        connection.close.assert_called_once()

    def test_request_and_response_failures_are_not_retried(self):
        for operation in ('request', 'getresponse', 'read'):
            with self.subTest(operation=operation):
                connection = MagicMock()
                response = connection.getresponse.return_value
                response.status = 200
                target = response if operation == 'read' else connection
                getattr(target, operation).side_effect = OSError('disconnected')
                with patch.object(runner.http.client, 'HTTPSConnection', return_value=connection) as factory:
                    polling = runner.PollingConnection({'bot_token':'synthetic'})
                    with self.assertRaises(OSError):
                        polling.get_updates({'offset':0})
                factory.assert_called_once()
                connection.request.assert_called_once()
                connection.close.assert_called_once()

    def test_http_and_api_rejections_do_not_retry(self):
        for status, body in ((500, b'{}'), (200, b'{"ok":false}'), (200, b'not json')):
            with self.subTest(status=status, body=body):
                connection = MagicMock()
                response = connection.getresponse.return_value
                response.status = status
                response.read.return_value = body
                with patch.object(runner.http.client, 'HTTPSConnection', return_value=connection):
                    polling = runner.PollingConnection({'bot_token':'synthetic'})
                    with self.assertRaises(ValueError):
                        polling.get_updates({'offset':0})
                connection.request.assert_called_once()
                connection.close.assert_called_once()

    def test_polling_failure_restores_webhook_and_closes_connection(self):
        self._assert_run_cleanup(ready=True)

    def test_startup_failure_closes_connection_without_switching_webhook(self):
        self._assert_run_cleanup(ready=False)

    def test_webhook_is_restored_when_polling_close_fails(self):
        self._assert_run_cleanup(ready=True, close_failure=True)

    def test_webhook_is_restored_when_worker_stdin_is_broken(self):
        self._assert_run_cleanup(ready=True, broken_stdin=True)

    def _assert_run_cleanup(self, ready, close_failure=False, broken_stdin=False):
        config = {'mode':'testing-bot', 'approval':{'gate':'P06-approved-navigation-targets-only'},
                  'bot_id':123, 'bot_token':'synthetic', 'safe_chat_ids':[11]}
        restore = {'url':'https://example.test/webhook', 'secret_token':'synthetic-secret',
                   'allowed_updates':['message'], 'max_connections':20}
        worker = MagicMock()
        worker.stdout.readline.return_value = json.dumps({'status':'ready' if ready else 'refused'})
        if broken_stdin:
            worker.stdin.close.side_effect = BrokenPipeError('worker exited')
        connection = MagicMock()
        connection.request.side_effect = OSError('disconnected')
        if close_failure:
            connection.close.side_effect = OSError('close failed')
        calls = []

        def management(_config, method, payload):
            calls.append((method, payload))
            return {'getMe':{'id':123}, 'getWebhookInfo':{'url':restore['url']},
                    'deleteWebhook':True, 'setWebhook':True}[method]

        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory)
            (state / 'candidate').mkdir()
            (state / 'candidate/config.json').write_text(json.dumps(config))
            restore_path = state / 'restore.json'
            restore_path.write_text(json.dumps(restore))
            with patch.object(runner.harness, 'STATE', state), \
                 patch.object(runner.harness, 'validate', return_value=config), \
                 patch.object(runner, 'container', return_value='candidate'), \
                 patch.object(runner.subprocess, 'Popen', return_value=worker), \
                 patch.object(runner, 'telegram', side_effect=management), \
                 patch.object(runner.http.client, 'HTTPSConnection', return_value=connection):
                with self.assertRaises(OSError if ready else ValueError):
                    runner.run(1, restore_path)

        worker.stdin.close.assert_called_once()
        worker.wait.assert_called_once_with(timeout=20)
        self.assertGreaterEqual(connection.close.call_count, 1)
        if ready:
            connection.request.assert_called_once()
            self.assertEqual(calls[-2:], [
                ('deleteWebhook', {'drop_pending_updates':False}),
                ('setWebhook', {**restore, 'drop_pending_updates':False}),
            ])
        else:
            connection.request.assert_not_called()
            self.assertEqual([method for method, _ in calls], ['getMe', 'getWebhookInfo'])


if __name__ == '__main__':
    unittest.main()
