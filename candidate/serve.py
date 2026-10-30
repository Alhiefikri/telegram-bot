"""Single-process local harness server, not a production or benchmark profile."""

import os
import sys
from wsgiref.simple_server import WSGIRequestHandler, WSGIServer, make_server

from lhie_candidate.startup import FAILURE, create_application, suppress_payload_logging


class BoundedServer(WSGIServer):
    request_queue_size = 8

    def handle_error(self, request, client_address):
        pass


class QuietHandler(WSGIRequestHandler):
    def setup(self):
        self.request.settimeout(3)
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


def main():
    suppress_payload_logging()
    try:
        application = create_application()
        with open(os.devnull, "w") as discard:
            with make_server("0.0.0.0", 8080, application,
                             server_class=BoundedServer, handler_class=QuietHandler) as server:
                server.discard = discard
                server.serve_forever()
    except KeyboardInterrupt:
        return 0
    except Exception:
        sys.stderr.write(FAILURE)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
