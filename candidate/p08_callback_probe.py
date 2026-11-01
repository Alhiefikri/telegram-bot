"""Exercise the real callback boundary against the real Django inbox in quarantine."""
from dataclasses import replace
import hashlib
import hmac
import importlib.util
import json
import os
import socketserver
import sys
import tempfile
import threading
from types import SimpleNamespace

from p08_fixture import UnixConnection


class CallbackProbe:
    def __init__(self):
        from django.conf import settings
        from django.test import RequestFactory
        from lhie_candidate.payments.callback import receive
        settings.RUNTIME = replace(settings.RUNTIME, p08_internal_key='b' * 64)
        self.key = 'synthetic-callback-key'
        spec = importlib.util.spec_from_file_location('p08_boundary', '/opt/harness/boundary.py')
        boundary = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(boundary)
        factory = RequestFactory()

        class Forward:
            def __init__(self, host, port, timeout):
                assert (host, port) == ('127.0.0.1', 8080)

            def request(self, method, path, body, headers):
                from django.db import connections
                try:
                    request = factory.generic(method, path, body, content_type=headers['Content-Type'],
                        HTTP_X_P08_INTERNAL_SECRET=headers['X-P08-Internal-Secret'],
                        HTTP_X_CALLBACK_EVENT=headers['X-Callback-Event'])
                    result = receive(request)
                    self.status = result.status_code
                finally:
                    connections.close_all()

            def getresponse(self):
                return SimpleNamespace(status=self.status, read=lambda limit: b'')

            def close(self):
                pass

        boundary.http = SimpleNamespace(client=SimpleNamespace(HTTPConnection=Forward))
        self.directory = tempfile.TemporaryDirectory(prefix='p08-callback-')
        self.path = os.path.join(self.directory.name, 'callback.sock')
        self.server = socketserver.UnixStreamServer(self.path, boundary.callback_handler(self.key, 'b' * 64))
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()

    def send(self, payload, *, valid=True):
        body = json.dumps(payload, separators=(',', ':')).encode()
        signature = hmac.new(self.key.encode(), body, hashlib.sha256).hexdigest() if valid else '0' * 64
        conn = UnixConnection(self.path)
        try:
            conn.request('POST', '/callback/sakurupiah', body, {'Content-Type': 'application/json',
                'X-Callback-Signature': signature, 'X-Callback-Event': 'payment_status'})
            response = conn.getresponse()
            response.read()
            return response.status
        finally:
            conn.close()

    def close(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join()
        self.directory.cleanup()
