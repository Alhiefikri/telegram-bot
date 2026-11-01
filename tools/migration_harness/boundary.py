"""Namespace firewall, bounded HTTP relay and discard-only SMTP sink. No payload logs."""
import http.client
import base64
import hashlib
import hmac
import ipaddress
import json
import os
import pwd
import socket
import socketserver
import stat
import secrets
import re
import ssl
import subprocess
import threading
import time
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import urlencode, urlsplit


def public_https(url):
    parsed = urlsplit(url)
    if (parsed.scheme != "https" or not parsed.hostname or parsed.username
            or parsed.password or parsed.port not in (None, 443) or parsed.fragment
            or parsed.query):
        raise ValueError("invalid approved HTTPS endpoint")
    return parsed


def upstream(url, payload=None, token=None, *, telegram_errors=False, raw_body=None, content_type=None):
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
    if raw_body is not None and payload is not None:
        raise ValueError("conflicting upstream bodies")
    headers = {"Content-Type": content_type or "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    try:
        body = raw_body if raw_body is not None else json.dumps(payload) if payload is not None else None
        conn.request("POST" if body is not None else "GET", parsed.path or "/", body, headers)
        response = conn.getresponse()
        data = response.read(65537)
        if ((response.status != 200 and not (telegram_errors and response.status in (400, 403, 429)))
                or len(data) > 65536):
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


def firewall(mode, callback=False):
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
        if callback and binary == "iptables":
            rule("-A", "INPUT", "-p", "tcp", "--dport", "8026", "-j", "ACCEPT")
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
    if config["mode"] not in ("testing-bot", "p08-sandbox-testing-bot"):
        return {"ok": True, "fake": True}
    result = upstream("https://api.telegram.org/bot" + config["bot_token"] + "/sendMessage", body)
    return {"ok": result.get("ok") is True}  # Never echo upstream customer/secret content.


NAVIGATION_METHODS = {
    "sendMessage", "sendPhoto", "sendAnimation", "editMessageText",
    "editMessageCaption", "editMessageMedia", "editMessageReplyMarkup",
    "deleteMessage", "answerCallbackQuery",
}


def navigation_request(config, body, next_message_id):
    """Only admitted private test chats; never accepts tokens, endpoints or uploads."""
    if (config["mode"] == "testing-bot"
            and (config.get("approval") or {}).get("gate") != "P06-approved-navigation-targets-only"):
        raise ValueError("explicit navigation target approval required")
    if (config["mode"] == "p08-sandbox-testing-bot"
            and (config.get("approval") or {}).get("gate") != "P08-approved-dual-targets-only"):
        raise ValueError("explicit dual target approval required")
    if (not isinstance(body, dict) or set(body) != {"method", "payload"}
            or body["method"] not in NAVIGATION_METHODS
            or not isinstance(body["payload"], dict)):
        raise ValueError("unsupported navigation request")
    method, payload = body["method"], body["payload"]
    allowed = {"chat_id", "message_id", "text", "caption", "parse_mode", "entities",
               "caption_entities", "reply_markup", "photo", "animation", "media",
               "disable_web_page_preview", "link_preview_options",
               "show_caption_above_media", "callback_query_id", "show_alert"}
    if not set(payload) <= allowed:
        raise ValueError("unsupported navigation options")
    chat_id = payload.get("chat_id")
    if isinstance(chat_id, str) and chat_id.isdecimal():
        chat_id = int(chat_id)
    if type(chat_id) is not int or chat_id not in config["safe_chat_ids"]:
        raise ValueError("chat not admitted")
    payload = {**payload, "chat_id": chat_id}
    if method == "answerCallbackQuery":
        if (not isinstance(payload.get("callback_query_id"), str)
                or not 1 <= len(payload["callback_query_id"]) <= 256
                or len(payload.get("text", "")) > 200):
            raise ValueError("invalid callback feedback")
        payload.pop("chat_id")
    if method.startswith("edit") or method == "deleteMessage":
        if type(payload.get("message_id")) is not int or payload["message_id"] < 1:
            raise ValueError("invalid message identity")
    for field in ("photo", "animation"):
        if field in payload:
            value = payload[field]
            if not isinstance(value, str) or not value or not all(c.isalnum() or c in "_-" for c in value):
                raise ValueError("only test-bot file references are supported")
    if "media" in payload:
        media = payload["media"]
        if (not isinstance(media, dict) or media.get("type") not in ("photo", "animation")
                or not isinstance(media.get("media"), str)
                or not all(c.isalnum() or c in "_-" for c in media["media"])):
            raise ValueError("only test-bot media references are supported")
    markup = payload.get("reply_markup", {})
    if not isinstance(markup, dict) or set(markup) - {"inline_keyboard", "remove_keyboard"}:
        raise ValueError("unsupported reply markup")
    for row in markup.get("inline_keyboard", []):
        if not isinstance(row, list):
            raise ValueError("invalid keyboard")
        for button in row:
            if (not isinstance(button, dict)
                    or set(button) - {"text", "callback_data", "url", "style", "icon_custom_emoji_id", "disabled"}
                    or not isinstance(button.get("text"), str)):
                raise ValueError("unsupported button")
            if sum(key in button for key in ("callback_data", "url", "disabled")) != 1:
                raise ValueError("invalid button action")
            if "disabled" in button and button["disabled"] != {}:
                raise ValueError("invalid disabled button")
            if "callback_data" in button and not 1 <= len(button["callback_data"].encode()) <= 64:
                raise ValueError("invalid callback")
            if "url" in button and not button["url"].startswith("https://"):
                raise ValueError("unsupported button URL")
    if config["mode"] == "sandbox":
        raise ValueError("sandbox mode cannot send Telegram navigation")
    if config["mode"] in ("testing-bot", "p08-sandbox-testing-bot"):
        return upstream("https://api.telegram.org/bot" + config["bot_token"] + "/" + method,
                        payload, telegram_errors=True)
    if method in ("deleteMessage", "answerCallbackQuery"):
        return {"ok": True, "result": True}
    return {"ok": True, "result": {"message_id": payload.get("message_id") or next_message_id}}


def receipt_multipart(payload, field, data):
    boundary = "receipt-" + secrets.token_hex(16)
    parts = []
    for key, value in payload.items():
        if key == "filename":
            continue
        if isinstance(value, (dict, list, bool)):
            value = json.dumps(value, ensure_ascii=True, separators=(",", ":"))
        else:
            value = str(value)
        parts.append(("--" + boundary + '\r\nContent-Disposition: form-data; name="' + key
                      + '"\r\n\r\n').encode() + value.encode("utf-8") + b"\r\n")
    filename = "invoice.png" if field == "photo" else payload["filename"]
    content_type = "image/png" if field == "photo" else "text/plain; charset=utf-8"
    parts.append(("--" + boundary + '\r\nContent-Disposition: form-data; name="' + field
                  + '"; filename="' + filename + '"\r\nContent-Type: ' + content_type
                  + "\r\n\r\n").encode() + data + b"\r\n")
    parts.append(("--" + boundary + "--\r\n").encode())
    return b"".join(parts), "multipart/form-data; boundary=" + boundary


def receipt_response(response):
    if not isinstance(response, dict):
        return {"ok": False, "error_code": 502}
    result = response.get("result")
    if response.get("ok") is True and isinstance(result, dict) and type(result.get("message_id")) is int and result["message_id"] > 0:
        return {"ok": True, "result": {"message_id": result["message_id"]}}
    code = response.get("error_code")
    if response.get("ok") is False and type(code) is int and code in (400, 403, 429):
        safe = {"ok": False, "error_code": code}
        description = response.get("description")
        if (code == 400 and isinstance(description, str)
                and any(term in description.lower() for term in ("custom emoji", "icon_custom_emoji_id", "tg-emoji"))):
            safe["emoji_rejected"] = True
        parameters = response.get("parameters")
        if isinstance(parameters, dict) and type(parameters.get("retry_after")) is int:
            safe["parameters"] = {"retry_after": min(300, max(1, parameters["retry_after"]))}
        return safe
    return {"ok": False, "error_code": 502}


def receipt_request(config, body, next_message_id):
    """Receipt uploads are ephemeral and restricted to explicitly admitted targets."""
    approved = (config.get("mode") == "testing-bot"
                and (config.get("approval") or {}).get("gate") == "P09-approved-receipt-targets-only")
    if config.get("mode") != "fake" and not approved:
        return {"ok": False, "error_code": 403}
    if (not isinstance(body, dict) or set(body) != {"method", "payload"}
            or body["method"] not in ("sendMessage", "sendPhoto", "sendDocument")
            or not isinstance(body["payload"], dict)):
        raise ValueError("invalid receipt request")
    method, payload = body["method"], body["payload"]
    common = {"chat_id", "reply_markup", "protect_content"}
    allowed = {
        "sendMessage": common | {"text", "entities"},
        "sendPhoto": common | {"photo", "caption", "caption_entities"},
        "sendDocument": common | {"document", "filename", "caption", "caption_entities"},
    }[method]
    if not set(payload) <= allowed:
        raise ValueError("invalid receipt options")
    if "protect_content" in payload and type(payload["protect_content"]) is not bool:
        raise ValueError("invalid receipt protection")
    chat_id = payload.get("chat_id")
    if isinstance(chat_id, str) and chat_id.isascii() and chat_id.isdecimal() and len(chat_id) <= 20:
        chat_id = int(chat_id)
    if type(chat_id) is not int or chat_id not in config["safe_chat_ids"]:
        raise ValueError("chat not admitted")
    text = payload.get("text" if method == "sendMessage" else "caption", "")
    if (not isinstance(text, str) or (method == "sendMessage" and not text)
            or len(text.encode("utf-16-le")) // 2 > (4096 if method == "sendMessage" else 1024)):
        raise ValueError("invalid receipt text")
    for key in ("entities", "caption_entities"):
        if key in payload and not isinstance(payload[key], list):
            raise ValueError("invalid receipt entities")
    if "reply_markup" in payload and not isinstance(payload["reply_markup"], dict):
        raise ValueError("invalid receipt markup")
    field = {"sendPhoto": "photo", "sendDocument": "document"}.get(method)
    metadata = {key: value for key, value in payload.items() if key != field}
    if len(json.dumps(metadata, ensure_ascii=True)) > 65536:
        raise ValueError("receipt metadata too large")
    if field:
        value = payload.get(field)
        limit = 5 * 1024 * 1024 if field == "photo" else 1024 * 1024
        if not isinstance(value, str) or len(value) > 4 * ((limit + 2) // 3):
            raise ValueError("invalid receipt attachment")
        data = base64.b64decode(value, validate=True)
        if not 0 < len(data) <= limit:
            raise ValueError("invalid receipt attachment")
        if field == "photo" and not data.startswith(b"\x89PNG\r\n\x1a\n"):
            raise ValueError("invalid receipt image")
        if field == "document":
            if (not isinstance(payload.get("filename"), str)
                    or re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,123}\.txt", payload["filename"]) is None):
                raise ValueError("invalid receipt filename")
            data.decode("utf-8")
    if approved:
        url = "https://api.telegram.org/bot" + config["bot_token"] + "/" + method
        try:
            if field:
                raw_body, content_type = receipt_multipart(metadata, field, data)
                response = upstream(url, telegram_errors=True, raw_body=raw_body, content_type=content_type)
            else:
                response = upstream(url, metadata, telegram_errors=True)
            return receipt_response(response)
        except Exception:
            # The upstream may have accepted the request before the connection failed.
            return {"ok": False, "error_code": 502}
    return {"ok": True, "result": {"message_id": next_message_id}}


def callback_handler(api_key, internal_key):
    class CallbackHandler(BaseHTTPRequestHandler):
        def setup(self):
            super().setup()
            self.connection.settimeout(5)

        def log_message(self, *_):
            pass

        def do_POST(self):
            status = 403
            try:
                lengths = self.headers.get_all("Content-Length", [])
                signatures = self.headers.get_all("X-Callback-Signature", [])
                events = self.headers.get_all("X-Callback-Event", [])
                if (self.path != "/callback/sakurupiah" or len(lengths) != 1
                        or not lengths[0].isascii() or not lengths[0].isdigit()
                        or not 0 < int(lengths[0]) <= 16384
                        or self.headers.get("Transfer-Encoding")
                        or self.headers.get_content_type() != "application/json"
                        or len(signatures) != 1 or re.fullmatch(r"[a-f0-9]{64}", signatures[0]) is None
                        or events != ["payment_status"]):
                    raise ValueError("callback denied")
                body = self.rfile.read(int(lengths[0]))
                expected = hmac.new(api_key.encode(), body, hashlib.sha256).hexdigest()
                if len(body) != int(lengths[0]) or not hmac.compare_digest(signatures[0], expected):
                    raise ValueError("callback denied")
                connection = http.client.HTTPConnection("127.0.0.1", 8080, timeout=5)
                try:
                    connection.request("POST", "/callback/sakurupiah", body, {
                        "Content-Type": "application/json",
                        "X-P08-Internal-Secret": internal_key,
                        "X-Callback-Event": "payment_status",
                    })
                    response = connection.getresponse()
                    response.read(1024)
                    status = 200 if response.status == 200 else 503
                finally:
                    connection.close()
            except Exception:
                pass
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            self.wfile.write(b'{"ok":true}' if status == 200 else b'{"ok":false}')
    return CallbackHandler

_P08_ENDPOINTS = {
    "list": ("list-payment.php", {"method"}),
    "create": ("create.php", {"method", "phone", "amount", "merchant_fee", "merchant_ref", "callback_url", "return_url"}),
    "status": ("status-transaction.php", {"method", "trx_id"}),
    "history": ("transaction.php", {"method", "mechant", "merchant_ref"}),
}

_FAKE_PAYMENTS = {}
_FAKE_LOCK = threading.Lock()
_FAKE_SLOW_SECONDS = 4.0


def fake_p08_request(body):
    """Synthetic fake-mode sandbox only; no credential, network or real identity."""
    if not isinstance(body, dict) or set(body) != {"operation", "fields"}:
        raise ValueError("sandbox operation denied")
    operation, fields = body["operation"], body["fields"]
    if operation not in _P08_ENDPOINTS or not isinstance(fields, dict):
        raise ValueError("sandbox operation denied")
    _, expected = _P08_ENDPOINTS[operation]
    if set(fields) != expected or any(type(value) is not str or not value or len(value.encode()) > 2048
                                      for value in fields.values()):
        raise ValueError("sandbox fields denied")
    if operation == "list":
        if fields["method"] != "list":
            raise ValueError("sandbox method denied")
        return {"status": "200", "data": [{"kode": "QRIS", "status": "Aktif",
                                           "minimal": "1", "maksimal": "100000000"}]}
    with _FAKE_LOCK:
        if operation == "create":
            if fields["method"] not in ("QRIS", "QRISC", "QRISMU", "QRIS2"):
                raise ValueError("sandbox method denied")
            if re.fullmatch(r"[1-9][0-9]{0,18}", fields["amount"]) is None:
                raise ValueError("sandbox amount denied")
            reference = fields["merchant_ref"]
            row = _FAKE_PAYMENTS.setdefault(reference, {
                "trx_id": "synthetic-" + reference, "merchant_ref": reference,
                "payment_kode": fields["method"], "amount": fields["amount"],
                "total": fields["amount"], "fee": "0", "expired": "2099-01-01 00:00:00",
                "qr": "https://sakurupiah.id/synthetic-qr",
                "checkout_url": "https://sakurupiah.id/synthetic-checkout",
                "status": "pending",
            })
            return {"status": "200", "data": [dict(row)]}
        if operation == "status":
            if fields["method"] != "status":
                raise ValueError("sandbox method denied")
            matches = [row for row in _FAKE_PAYMENTS.values() if row["trx_id"] == fields["trx_id"]]
            if len(matches) != 1:
                raise ValueError("sandbox reference denied")
            row = matches[0]
            if row["merchant_ref"].startswith("p10-"):
                time.sleep(_FAKE_SLOW_SECONDS)
                state = "berhasil"
            else:
                state = "pending"
            return {"status": "200", "data": [{"trx_id": row["trx_id"], "payment_status": state}]}
        if operation == "history":
            if fields["method"] != "transaction" or fields["mechant"] != "1":
                raise ValueError("sandbox method denied")
            row = _FAKE_PAYMENTS.get(fields["merchant_ref"])
            if row is None:
                raise ValueError("sandbox reference denied")
            state = "berhasil" if row["merchant_ref"].startswith("p10-") else "pending"
            return {"status": "200", "data": [{**row, "status": state, "payment_status": state}]}
    raise ValueError("sandbox operation denied")


def p08_request(config, body):
    if (config.get("mode") not in ("sandbox", "p08-sandbox-testing-bot")
            or config.get("p08_callback") != {"gate": "P08-approved-sandbox-callback-only"}
            or not isinstance(body, dict) or set(body) != {"operation", "fields"}):
        raise ValueError("sandbox operation denied")
    operation = body["operation"]
    if operation not in _P08_ENDPOINTS or not isinstance(body["fields"], dict):
        raise ValueError("sandbox operation denied")
    path, expected = _P08_ENDPOINTS[operation]
    fields = body["fields"]
    if (set(fields) != expected or any(type(v) is not str or not v or len(v.encode()) > 2048
                                       for v in fields.values())):
        raise ValueError("sandbox fields denied")
    if operation == "list" and fields["method"] != "list":
        raise ValueError("sandbox method denied")
    if operation == "status" and fields["method"] != "status":
        raise ValueError("sandbox method denied")
    if operation == "history" and (fields["method"] != "transaction" or fields["mechant"] != "1"):
        raise ValueError("sandbox method denied")
    if operation == "create":
        if (fields["method"] not in ("QRIS", "QRISC", "QRISMU", "QRIS2")
                or fields["merchant_fee"] != "2"
                or re.fullmatch(r"[1-9][0-9]{0,18}", fields["amount"]) is None
                or int(fields["amount"]) > 9223372036854775807
                or fields["phone"] != config["p08_phone"]
                or fields["callback_url"] != config["p08_callback_url"]
                or fields["return_url"] != config["p08_return_url"]):
            raise ValueError("sandbox create denied")
    api_id, api_key = config["p08_api_id"], config["sandbox_token"]
    form = {"api_id": api_id, **fields}
    if operation == "create":
        message = api_id + fields["method"] + fields["merchant_ref"] + fields["amount"]
        form["signature"] = hmac.new(api_key.encode(), message.encode(), hashlib.sha256).hexdigest()
    addresses = socket.getaddrinfo("sakurupiah.id", 443, family=socket.AF_INET, type=socket.SOCK_STREAM)
    if not addresses or any(not ipaddress.ip_address(a[4][0]).is_global for a in addresses):
        raise ValueError("sandbox destination denied")
    conn = http.client.HTTPSConnection("sakurupiah.id", timeout=5)
    address = addresses[0][4]
    conn.connect = lambda: setattr(conn, "sock", ssl.create_default_context().wrap_socket(
        socket.create_connection(address, timeout=5), server_hostname="sakurupiah.id"))
    try:
        conn.request("POST", "/api-sanbox/" + path, urlencode(form), {
            "Content-Type": "application/x-www-form-urlencoded", "Authorization": "Bearer " + api_key,
        })
        response = conn.getresponse()
        data = response.read(1048577)
        if len(data) > 1048576 or 300 <= response.status < 400:
            raise ValueError("sandbox response denied")
        return {"status": response.status, "body": base64.b64encode(data).decode("ascii")}
    finally:
        conn.close()


def handler(config):
    class Handler(BaseHTTPRequestHandler):
        next_message_id = 1000
        def setup(self):
            super().setup()
            self.connection.settimeout(5)

        def log_message(self, *_):
            pass

        def do_GET(self):
            self.send_response(200 if self.path == "/health" else 403)
            self.end_headers()

        def do_POST(self):
            if self.path == "/telegram/receipt":
                try:
                    lengths = self.headers.get_all("Content-Length", [])
                    if (len(lengths) != 1 or not lengths[0].isascii() or not lengths[0].isdigit()
                            or not 0 < int(lengths[0]) <= 7 * 1024 * 1024 + 65536
                            or self.headers.get("Transfer-Encoding")
                            or self.headers.get_content_type() != "application/json"):
                        raise ValueError("invalid receipt request")
                    body = json.loads(self.rfile.read(int(lengths[0])))
                    type(self).next_message_id += 1
                    result = receipt_request(config, body, type(self).next_message_id)
                except Exception:
                    result = {"ok": False, "error_code": 400}
                self.send_response(200)
                self.end_headers()
                self.wfile.write(json.dumps(result).encode())
                return
            try:
                length = int(self.headers.get("Content-Length", "0"))
                if not 0 < length <= 16384 or self.headers.get("Transfer-Encoding"):
                    raise ValueError("invalid request")
                body = json.loads(self.rfile.read(length))
                if self.path == "/telegram" and isinstance(body, dict):
                    result = telegram_request(config, body)
                elif self.path == "/telegram/navigation":
                    type(self).next_message_id += 1
                    result = navigation_request(config, body, type(self).next_message_id)
                elif self.path == "/sandbox/sakurupiah":
                    if config["mode"] == "fake":
                        synthetic = fake_p08_request(body)
                        result = {"status": 200,
                                  "body": base64.b64encode(json.dumps(synthetic).encode()).decode("ascii")}
                    else:
                        result = p08_request(config, body)
                elif (self.path == "/sandbox/status" and body == {} and config["mode"] == "sandbox"
                      and config.get("p08_callback") is None):
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


class BoundedThreadingHTTPServer(socketserver.ThreadingMixIn, HTTPServer):
    """Allow one slow synthetic provider call without starving other relays."""

    daemon_threads = True
    allow_reuse_address = True
    maximum_requests = 8

    def __init__(self, *args, **kwargs):
        self._slots = threading.BoundedSemaphore(self.maximum_requests)
        super().__init__(*args, **kwargs)

    def process_request(self, request, client_address):
        if not self._slots.acquire(blocking=False):
            self.shutdown_request(request)
            return
        try:
            super().process_request(request, client_address)
        except Exception:
            self._slots.release()
            raise

    def process_request_thread(self, request, client_address):
        try:
            super().process_request_thread(request, client_address)
        finally:
            self._slots.release()


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
    callback = config.get("p08_callback") == {"gate": "P08-approved-sandbox-callback-only"}
    internal_key = ""
    if callback:
        if config.get("mode") not in ("sandbox", "p08-sandbox-testing-bot") or not config.get("sandbox_token"):
            raise ValueError("callback configuration denied")
        descriptor = os.open("/run/harness/p08-internal-key", os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        with os.fdopen(descriptor, "rb") as stream:
            info = os.fstat(stream.fileno())
            if not stat.S_ISREG(info.st_mode) or stat.S_IMODE(info.st_mode) not in (0o400, 0o600):
                raise ValueError("callback key denied")
            internal_key = stream.read(65).decode("ascii")
        if re.fullmatch(r"[a-f0-9]{64}", internal_key) is None:
            raise ValueError("callback key denied")
    firewall(config["mode"], callback)
    user = pwd.getpwnam("relay")
    os.setgroups([])
    os.setgid(user.pw_gid)
    os.setuid(user.pw_uid)
    if config["mode"] in ("testing-bot", "p08-sandbox-testing-bot"):
        verify_bot(config)  # Must succeed before health admits DB/application startup.
    smtp = socketserver.TCPServer(("127.0.0.1", 1025), SMTP)
    threading.Thread(target=smtp.serve_forever, daemon=True).start()
    if callback:
        callbacks = HTTPServer(("0.0.0.0", 8026), callback_handler(config["sandbox_token"], internal_key))
        threading.Thread(target=callbacks.serve_forever, daemon=True).start()
    server = BoundedThreadingHTTPServer(("127.0.0.1", 8025), handler(config))
    server.timeout = 5
    with open("/tmp/harness-ready", "w") as ready:
        ready.write("firewall and transports ready\n")
    server.serve_forever()


if __name__ == "__main__":
    try:
        main()
    except Exception:
        raise SystemExit("harness boundary refused startup") from None
