"""P01 runtime slot; Django application bootstrap belongs to P05 (#683)."""
from http.server import BaseHTTPRequestHandler, HTTPServer


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        self.send_response(200 if self.path == "/health" else 503)
        self.end_headers()
        self.wfile.write(b"P01 candidate slot; application not bootstrapped\n")

    def log_message(self, *_):
        pass


HTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
