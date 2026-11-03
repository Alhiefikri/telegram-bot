"""In-process Unix-socket HTTP sandbox, with no routable targets or credentials."""
import http.client
import json
import os
import socket
import socketserver
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler


class UnixConnection(http.client.HTTPConnection):
    def __init__(self, path):
        super().__init__('localhost', timeout=5)
        self.path = path

    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(self.timeout)
        self.sock.connect(self.path)


class FixtureClient:
    def __init__(self, path):
        self.path = path

    def post(self, url, *, fields, timeout, max_response_bytes):
        prefix = 'https://sakurupiah.id/api-sanbox/'
        if not url.startswith(prefix) or url[len(prefix):] not in (
                'list-payment.php', 'create.php', 'status-transaction.php', 'transaction.php'):
            raise ValueError('synthetic fixture rejects endpoint')
        conn = UnixConnection(self.path)
        try:
            conn.request('POST', '/' + url[len(prefix):], json.dumps(fields).encode(),
                         {'Content-Type': 'application/json'})
            response = conn.getresponse()
            return response.status, response.read(max_response_bytes + 1)
        finally:
            conn.close()


class Sandbox:
    def __init__(self):
        self.directory = tempfile.TemporaryDirectory(prefix='p08-synthetic-')
        self.path = os.path.join(self.directory.name, 'sandbox.sock')
        self.rows = {}
        self.calls = []
        self.timeouts = 0
        self.ambiguous = False
        self.omit_presentation = False
        self.block_create = False
        self.create_received = threading.Event()
        self.release_create = threading.Event()
        sandbox = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_):
                pass

            def do_POST(self):
                length = int(self.headers['Content-Length'])
                fields = json.loads(self.rfile.read(length))
                sandbox.calls.append((self.path, fields))
                if self.path.endswith('/list-payment.php'):
                    rows = [{'kode': 'QRIS', 'status': 'Aktif', 'minimal': '1', 'maksimal': '100000000'}]
                elif self.path.endswith('/create.php'):
                    ref = fields['merchant_ref']
                    row = sandbox.rows.setdefault(ref, {'trx_id': 'synthetic-' + ref,
                        'merchant_ref': ref, 'payment_kode': fields['method'],
                        'amount': fields['amount'], 'total': fields['amount'], 'fee': '0',
                        'expired': '2099-01-01 00:00:00', 'qr': 'https://sakurupiah.id/assets/synthetic-qr.png',
                        'checkout_url': 'https://sakurupiah.id/sanbox-payout.php?token=synthetic', 'status': 'pending'})
                    if sandbox.block_create:
                        sandbox.create_received.set()
                        if not sandbox.release_create.wait(timeout=8):
                            return
                    if sandbox.ambiguous:
                        sandbox.ambiguous = False
                        time.sleep(5.2)
                        return
                    rows = [{**row, 'payment_status': row['status']}]
                    if sandbox.omit_presentation:
                        rows[0].pop('qr')
                        rows[0].pop('checkout_url')
                elif self.path.endswith('/status-transaction.php'):
                    rows = [{'trx_id': row['trx_id'], 'payment_status': row['status']}
                            for row in sandbox.rows.values() if row['trx_id'] == fields['trx_id']]
                elif self.path.endswith('/transaction.php'):
                    rows = [row for ref, row in sandbox.rows.items() if ref == fields['merchant_ref']]
                else:
                    self.send_response(404)
                    self.end_headers()
                    return
                body = json.dumps({'status': '200', 'data': rows}).encode()
                self.send_response(200)
                self.send_header('Content-Length', str(len(body)))
                self.end_headers()
                self.wfile.write(body)

        self.server = socketserver.UnixStreamServer(self.path, Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()

    def post(self, url, *, fields, timeout, max_response_bytes):
        prefix = 'https://sakurupiah.id/api-sanbox/'
        if not url.startswith(prefix) or url[len(prefix):] not in (
                'list-payment.php', 'create.php', 'status-transaction.php', 'transaction.php'):
            raise ValueError('synthetic fixture rejects endpoint')
        conn = UnixConnection(self.path)
        try:
            conn.request('POST', '/' + url[len(prefix):], json.dumps(fields).encode(),
                         {'Content-Type': 'application/json'})
            response = conn.getresponse()
            return response.status, response.read(max_response_bytes + 1)
        except TimeoutError:
            self.timeouts += 1
            raise
        finally:
            conn.close()

    def close(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join()
        self.directory.cleanup()
