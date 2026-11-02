import json
import socket
import threading
import http.client
from concurrent.futures import ThreadPoolExecutor
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from types import SimpleNamespace
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'tools/migration_harness'))
import boundary
from harness import default
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'candidate'))
from lhie_candidate.navigation import transport as navigation_transport


class TelegramEndpoint(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def log_message(self, *_):
        pass

    def do_POST(self):
        payload = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        self.server.requests.append((self.client_address, payload['text']))
        if payload['text'] == 'response lost':
            self.connection.shutdown(socket.SHUT_RDWR)
            self.connection.close()
            self.close_connection = True
            return
        if payload['text'].startswith('concurrent:'):
            self.server.barrier.wait(timeout=5)
            message_id = int(payload['text'].split(':')[1])
        else:
            message_id = 1
        body = json.dumps({'ok': True, 'result': {'message_id': message_id}}).encode()
        self.send_response(200)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)


class NavigationConnectionTests(unittest.TestCase):
    def setUp(self):
        self.server = ThreadingHTTPServer(('127.0.0.1', 0), TelegramEndpoint)
        self.server.daemon_threads = True
        self.server.requests = []
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.config = {**default('candidate'), 'mode': 'testing-bot', 'bot_token': 'test-token',
                       'approval': {'gate': 'P06-approved-navigation-targets-only'}}
        self.patchers = [
            patch.object(boundary.socket, 'getaddrinfo', return_value=[(2, 1, 6, '', ('8.8.8.8', 443))]),
            patch.object(boundary.socket, 'create_connection', side_effect=self.connect_local),
            patch.object(boundary.ssl, 'create_default_context', return_value=SimpleNamespace(
                wrap_socket=lambda sock, server_hostname: sock)),
        ]
        for patcher in self.patchers:
            patcher.start()

    def tearDown(self):
        boundary.navigation_telegram.close()
        for patcher in reversed(self.patchers):
            patcher.stop()
        self.server.shutdown()
        self.server.server_close()
        self.thread.join()

    def connect_local(self, *args, **kwargs):
        sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        sock.settimeout(2)
        address = args[0]
        sock.connect(self.server.server_address if address[0] in ('8.8.8.8', '1.1.1.1') else address)
        return sock

    def send(self, text):
        request = {'method': 'editMessageText', 'payload': {
            'chat_id': 1, 'message_id': 1, 'text': text}}
        return boundary.navigation_request(self.config, request, 1)

    def test_navigation_reuses_connection_after_complete_telegram_response(self):
        first, second = self.send('first'), self.send('second')
        self.assertTrue(first['ok'])
        self.assertTrue(second['ok'])
        self.assertFalse(first['_relay_timing']['reused'])
        self.assertTrue(second['_relay_timing']['reused'])
        self.assertTrue({'dns_ms', 'tls_context_ms', 'tcp_ms', 'tls_ms', 'response_ms'}
                        <= first['_relay_timing'].keys())
        self.assertEqual([text for _, text in self.server.requests], ['first', 'second'])
        self.assertEqual(self.server.requests[0][0], self.server.requests[1][0])

    def test_active_connection_survives_dns_revalidation_without_reopening(self):
        clock = [100.0]
        fake_time = SimpleNamespace(monotonic=lambda: clock[0], monotonic_ns=boundary.time.monotonic_ns)
        with patch.object(boundary, 'time', fake_time):
            self.assertTrue(self.send('first')['ok'])
            clock[0] = 129.0
            self.assertTrue(self.send('second')['ok'])
            clock[0] = 131.0
            self.assertTrue(self.send('third')['ok'])
        self.assertEqual([text for _, text in self.server.requests], ['first', 'second', 'third'])
        self.assertEqual(len({address for address, _ in self.server.requests}), 1)

    def test_idle_connection_is_not_reused_after_thirty_seconds(self):
        clock = [100.0]
        fake_time = SimpleNamespace(monotonic=lambda: clock[0], monotonic_ns=boundary.time.monotonic_ns)
        with patch.object(boundary, 'time', fake_time):
            self.assertTrue(self.send('first')['ok'])
            clock[0] = 131.0
            self.assertTrue(self.send('later')['ok'])
        self.assertNotEqual(self.server.requests[0][0], self.server.requests[1][0])

    def test_dns_change_replaces_the_active_connection_before_sending(self):
        clock = [100.0]
        fake_time = SimpleNamespace(monotonic=lambda: clock[0], monotonic_ns=boundary.time.monotonic_ns)
        with patch.object(boundary, 'time', fake_time):
            self.assertTrue(self.send('first')['ok'])
            clock[0] = 129.0
            self.assertTrue(self.send('second')['ok'])
            clock[0] = 131.0
            with patch.object(boundary.socket, 'getaddrinfo',
                              return_value=[(2, 1, 6, '', ('1.1.1.1', 443))]):
                self.assertTrue(self.send('third')['ok'])
        self.assertEqual([text for _, text in self.server.requests], ['first', 'second', 'third'])
        self.assertEqual(self.server.requests[0][0], self.server.requests[1][0])
        self.assertNotEqual(self.server.requests[1][0], self.server.requests[2][0])

    def test_private_dns_on_revalidation_denies_and_discards_connection(self):
        clock = [100.0]
        fake_time = SimpleNamespace(monotonic=lambda: clock[0], monotonic_ns=boundary.time.monotonic_ns)
        with patch.object(boundary, 'time', fake_time):
            self.assertTrue(self.send('first')['ok'])
            clock[0] = 129.0
            self.assertTrue(self.send('second')['ok'])
            clock[0] = 131.0
            with patch.object(boundary.socket, 'getaddrinfo',
                              return_value=[(2, 1, 6, '', ('127.0.0.1', 443))]):
                with self.assertRaises(ValueError):
                    self.send('denied')
            self.assertTrue(self.send('later')['ok'])
        self.assertEqual([text for _, text in self.server.requests], ['first', 'second', 'later'])
        self.assertEqual(self.server.requests[0][0], self.server.requests[1][0])
        self.assertNotEqual(self.server.requests[1][0], self.server.requests[2][0])

    def test_concurrent_requests_do_not_share_or_crosswire_connections(self):
        self.server.barrier = threading.Barrier(2)
        with ThreadPoolExecutor(max_workers=2) as executor:
            futures = [executor.submit(self.send, f'concurrent:{identity}') for identity in (11, 22)]
            results = [future.result(timeout=10) for future in futures]
        self.assertEqual([result['result']['message_id'] for result in results], [11, 22])
        self.assertEqual(len(self.server.requests), 2)
        self.assertNotEqual(self.server.requests[0][0], self.server.requests[1][0])

    def test_private_dns_answer_is_denied_before_any_request(self):
        with patch.object(boundary.socket, 'getaddrinfo',
                          return_value=[(2, 1, 6, '', ('127.0.0.1', 443))]):
            with self.assertRaises(ValueError):
                self.send('first')
        self.assertEqual(self.server.requests, [])

    def test_lost_response_is_not_replayed_and_next_request_uses_new_connection(self):
        self.assertTrue(self.send('first')['ok'])
        with self.assertRaises((OSError, boundary.http.client.HTTPException)):
            self.send('response lost')
        self.assertEqual([text for _, text in self.server.requests], ['first', 'response lost'])
        self.assertTrue(self.send('later')['ok'])
        self.assertEqual([text for _, text in self.server.requests], ['first', 'response lost', 'later'])
        self.assertEqual(self.server.requests[0][0], self.server.requests[1][0])
        self.assertNotEqual(self.server.requests[1][0], self.server.requests[2][0])

    def test_lost_response_after_dns_revalidation_is_not_replayed(self):
        clock = [100.0]
        fake_time = SimpleNamespace(monotonic=lambda: clock[0], monotonic_ns=boundary.time.monotonic_ns)
        with patch.object(boundary, 'time', fake_time):
            self.assertTrue(self.send('first')['ok'])
            clock[0] = 129.0
            self.assertTrue(self.send('second')['ok'])
            clock[0] = 131.0
            with self.assertRaises((OSError, boundary.http.client.HTTPException)):
                self.send('response lost')
            self.assertTrue(self.send('later')['ok'])
        self.assertEqual([text for _, text in self.server.requests],
                         ['first', 'second', 'response lost', 'later'])
        self.assertEqual(self.server.requests[1][0], self.server.requests[2][0])
        self.assertNotEqual(self.server.requests[2][0], self.server.requests[3][0])

    def test_lost_response_is_ambiguous_through_real_relay_without_resend(self):
        relay = ThreadingHTTPServer(('127.0.0.1', 0), boundary.handler(self.config))
        relay.daemon_threads = True
        thread = threading.Thread(target=relay.serve_forever, daemon=True)
        thread.start()
        http_connection = http.client.HTTPConnection
        try:
            with patch.object(navigation_transport.http.client, 'HTTPConnection',
                              side_effect=lambda host, port, timeout: http_connection(
                                  '127.0.0.1', relay.server_port, timeout=timeout)):
                transport = navigation_transport.RelayTransport()
                payload = {'chat_id': 1, 'message_id': 1, 'text': 'response lost'}
                self.assertEqual(transport.execute('editMessageText', payload).outcome, 'ambiguous')
                self.assertEqual([text for _, text in self.server.requests], ['response lost'])
                payload['text'] = 'later'
                self.assertEqual(transport.execute('editMessageText', payload).outcome, 'success')
                self.assertEqual([text for _, text in self.server.requests], ['response lost', 'later'])
        finally:
            relay.shutdown()
            relay.server_close()
            thread.join()
