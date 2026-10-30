"""Namespace firewall, bounded HTTP relay and discard-only SMTP sink. No payload logs."""
import http.client
import ipaddress
import json
import os
import pwd
import socket
import socketserver
import ssl
import subprocess
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import urlsplit


def public_https(url):
    parsed = urlsplit(url)
    if (parsed.scheme != "https" or not parsed.hostname or parsed.username
            or parsed.password or parsed.port not in (None, 443) or parsed.fragment
            or parsed.query):
        raise ValueError("invalid approved HTTPS endpoint")
    return parsed


def upstream(url, payload=None, token=None):
    """Pin the validated public address; preserve TLS hostname, refuse redirects."""
    parsed = public_https(url)
    addresses = socket.getaddrinfo(parsed.hostname, 443, family=socket.AF_INET,
                                   type=socket.SOCK_STREAM)
    if not addresses or any(not ipaddress.ip_address(a[4][0]).is_global for a in addresses):
        raise ValueError("non-public upstream")
    conn = http.client.HTTPSConnection(parsed.hostname, timeout=8)
    address = addresses[0][4]
    conn.connect = lambda: setattr(conn, "sock", ssl.create_default_context().wrap_socket(
        socket.create_connection(address, timeout=8), server_hostname=parsed.hostname))
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    try:
        conn.request("POST" if payload is not None else "GET", parsed.path or "/",
                     json.dumps(payload) if payload is not None else None, headers)
        response = conn.getresponse()
        data = response.read(65537)
        if response.status != 200 or len(data) > 65536:
            raise ValueError("upstream refused")
        return json.loads(data)
    finally:
        conn.close()


def verify_bot(config):
    token = config["bot_token"]
    result = upstream("https://api.telegram.org/bot" + token + "/getMe")
    bot = result.get("result", {})
    if (result.get("ok") is not True or bot.get("id") != config["bot_id"]
            or bot.get("username") != config["bot_username"] or not bot.get("is_bot")):
        raise ValueError("testing bot identity mismatch")


def firewall(mode):
    # Rules are installed before any dependent container is admitted. IPv6 also denied.
    for binary in ("iptables", "ip6tables"):
        def rule(*args):
            subprocess.run([binary, *args], check=True, stdout=subprocess.DEVNULL,
                           stderr=subprocess.DEVNULL)
        rule("-P", "OUTPUT", "DROP")
        rule("-P", "INPUT", "DROP")
        rule("-P", "FORWARD", "DROP")
        rule("-A", "INPUT", "-m", "conntrack", "--ctstate", "ESTABLISHED,RELATED", "-j", "ACCEPT")
        rule("-A", "OUTPUT", "-m", "conntrack", "--ctstate", "ESTABLISHED,RELATED", "-j", "ACCEPT")
        rule("-A", "INPUT", "-i", "lo", "-j", "ACCEPT")
        rule("-A", "INPUT", "-p", "tcp", "--dport", "8080", "-j", "ACCEPT")
        rule("-A", "OUTPUT", "-o", "lo", "-p", "tcp", "-m", "multiport",
             "--dports", "5432,6379,1025,8025,8080", "-j", "ACCEPT")
        if mode != "fake":
            rule("-A", "OUTPUT", "-m", "owner", "--uid-owner", "10001", "-p", "tcp",
                 "--dport", "443", "-j", "ACCEPT")
            if binary == "iptables":
                rule("-A", "OUTPUT", "-m", "owner", "--uid-owner", "10001", "-d", "127.0.0.11",
                     "-p", "udp", "-j", "ACCEPT")
                rule("-A", "OUTPUT", "-m", "owner", "--uid-owner", "10001", "-d", "127.0.0.11",
                     "-p", "tcp", "-j", "ACCEPT")


def telegram_request(config, body):
    # No URLs, media uploads, webhook mutation, arbitrary methods, channel/group routing,
    # inline_message_id or reply_markup (which could include live purchase URLs).
    if set(body) != {"chat_id", "text"} or type(body["chat_id"]) is not int:
        raise ValueError("unsupported request")
    if body["chat_id"] not in config["safe_chat_ids"]:
        raise ValueError("chat not admitted")
    if not isinstance(body["text"], str) or len(body["text"]) > 4096:
        raise ValueError("invalid text")
    if config["mode"] != "testing-bot":
        return {"ok": True, "fake": True}
    result = upstream("https://api.telegram.org/bot" + config["bot_token"] + "/sendMessage", body)
    return {"ok": result.get("ok") is True}  # Never echo upstream customer/secret content.


def handler(config):
    class Handler(BaseHTTPRequestHandler):
        def setup(self):
            super().setup()
            self.connection.settimeout(5)

        def log_message(self, *_):
            pass

        def do_GET(self):
            self.send_response(200 if self.path == "/health" else 403)
            self.end_headers()

        def do_POST(self):
            try:
                length = int(self.headers.get("Content-Length", "0"))
                if not 0 < length <= 16384 or self.headers.get("Transfer-Encoding"):
                    raise ValueError("invalid request")
                body = json.loads(self.rfile.read(length))
                if self.path == "/telegram" and isinstance(body, dict):
                    result = telegram_request(config, body)
                elif self.path == "/sandbox/status" and body == {} and config["mode"] == "sandbox":
                    # Only a pre-approved, exact GET endpoint. No create/payment/purchase API.
                    upstream(config["sandbox_status_url"], token=config["sandbox_token"])
                    result = {"ok": True}
                else:
                    raise ValueError("route not admitted")
                self.send_response(200)
            except Exception:
                result = {"ok": False, "error": "transport denied or unavailable"}
                self.send_response(403)
            self.end_headers()
            self.wfile.write(json.dumps(result).encode())
    return Handler


class SMTP(socketserver.StreamRequestHandler):
    def handle(self):
        self.request.settimeout(5)
        self.wfile.write(b"220 disposable mail sink\r\n")
        data = False
        total = 0
        for _ in range(1000):
            line = self.rfile.readline(8193)
            total += len(line)
            if not line or len(line) > 8192 or total > 1048576:
                return
            if data:
                if line == b".\r\n":
                    data = False
                    self.wfile.write(b"250 discarded\r\n")
                continue
            command = line.split(b" ", 1)[0].strip().upper()
            if command == b"QUIT":
                self.wfile.write(b"221 bye\r\n")
                return
            if command == b"DATA":
                data = True
                self.wfile.write(b"354 discard mode\r\n")
            elif command in (b"EHLO", b"HELO", b"MAIL", b"RCPT", b"RSET", b"NOOP"):
                self.wfile.write(b"250 OK\r\n")
            else:
                self.wfile.write(b"502 unsupported\r\n")


def main():
    config = json.load(open("/run/harness/config.json"))
    firewall(config["mode"])
    user = pwd.getpwnam("relay")
    os.setgroups([])
    os.setgid(user.pw_gid)
    os.setuid(user.pw_uid)
    if config["mode"] == "testing-bot":
        verify_bot(config)  # Must succeed before health admits DB/application startup.
    smtp = socketserver.TCPServer(("127.0.0.1", 1025), SMTP)
    threading.Thread(target=smtp.serve_forever, daemon=True).start()
    server = HTTPServer(("127.0.0.1", 8025), handler(config))
    server.timeout = 5
    with open("/tmp/harness-ready", "w") as ready:
        ready.write("firewall and transports ready\n")
    server.serve_forever()


if __name__ == "__main__":
    try:
        main()
    except Exception:
        raise SystemExit("harness boundary refused startup") from None
