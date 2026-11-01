"""Risk tests for admission, transport routing, redaction and quarantine boundaries."""
import hashlib
import hmac
import io
from http.client import HTTPConnection
from http.server import HTTPServer
import json
from pathlib import Path
import os
import smtplib
import socketserver
import sys
import threading
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "tools/migration_harness"))
import boundary
import harness


class AdmissionTests(unittest.TestCase):
    def test_p08_draft_is_private_unapproved_and_never_overwrites(self):
        env = {"SAKURUPIAH_SANDBOX_API_ID": "synthetic-id",
               "SAKURUPIAH_SANDBOX_API_KEY": "synthetic-key",
               "SAKURUPIAH_PRODUCTION_API_KEY": "must-not-copy"}
        targets = {"phone": "628000000000",
                   "callback_url": "https://synthetic.ngrok-free.app/callback/sakurupiah",
                   "return_url": "https://synthetic.ngrok-free.app/return"}
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            active = directory / "config.json"
            active.write_text("unchanged")
            harness.p08_draft(directory, env, targets)
            draft = directory / "p08-draft.json"
            self.assertEqual(draft.stat().st_mode & 0o777, 0o600)
            content = draft.read_text()
            self.assertNotIn("must-not-copy", content)
            config = json.loads(content)
            self.assertIsNone(config["approval"])
            self.assertIsNone(config["p08_callback"])
            with self.assertRaises(harness.Refused):
                harness.validate(config)
            with self.assertRaises(FileExistsError):
                harness.p08_draft(directory, env, targets)
            self.assertEqual(active.read_text(), "unchanged")

    def test_p08_draft_rejects_missing_inputs_and_nonprivate_directory(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            targets = {"phone": "628000000000",
                       "callback_url": "https://synthetic.ngrok-free.app/callback/sakurupiah",
                       "return_url": "https://synthetic.ngrok-free.app/return"}
            with self.assertRaises(harness.Refused):
                harness.p08_draft(directory, {}, targets)
            env = dict.fromkeys(("SAKURUPIAH_SANDBOX_API_ID", "SAKURUPIAH_SANDBOX_API_KEY"), "x")
            for invalid in ({**targets, "callback_url": "https://sakurupiah.id/api/production"},
                            {**targets, "callback_url": "http://synthetic.ngrok-free.app/callback/sakurupiah"},
                            {**targets, "return_url": "https://production.example/return"},
                            {**targets, "callback_url": "https://synthetic.ngrok-free.app/callback/sakurupiah?x=1"},
                            {**targets, "phone": "invalid"}):
                with self.subTest(targets=invalid), self.assertRaises(harness.Refused):
                    harness.p08_draft(directory, env, invalid)
            os.chmod(directory, 0o755)
            with self.assertRaises(harness.Refused):
                harness.p08_draft(directory, env, targets)
            self.assertFalse((directory / "p08-draft.json").exists())

    def test_production_ambiguous_and_credential_configs_rejected(self):
        cases = [("db_url", "postgresql://prod/production"), ("db_url", "postgresql://localhost/test"),
                 ("redis_url", "redis://host.docker.internal/0"), ("redis_url", "redis://[::1]/0"),
                 ("bot_token", "production-secret-marker"), ("dataset", "raw-snapshot"),
                 ("safe_chat_ids", [-100123]), ("safe_chat_ids", [True]),
                 ("mode", "production"), ("mode", "testing-bot"), ("mode", "sandbox")]
        for field, value in cases:
            with self.subTest(field=field, value=value):
                config = harness.default("candidate")
                config[field] = value
                with self.assertRaises(harness.Refused) as error:
                    harness.validate(config)
                self.assertNotIn("production-secret-marker", str(error.exception))
        config = harness.default("candidate")
        config["ENV"] = "production-secret-marker"
        with self.assertRaises(harness.Refused):
            harness.validate(config)

    def test_bot_token_requires_exact_testing_identity_and_scope(self):
        config = harness.default("candidate")
        config.update(mode="testing-bot", bot_token="123:" + "a" * 35,
                      bot_id=123, bot_username="LhieStoreTestBot")
        def attest():
            scope = {key: config[key] for key in sorted(harness.FIELDS - {"approval"})}
            config["approval"] = {"gate": "H0-approved-test-targets-only", "scope_sha256":
                                  hashlib.sha256(json.dumps(scope, sort_keys=True).encode()).hexdigest()}
        attest()
        harness.validate(config)
        with patch.object(boundary, "upstream", return_value={"ok": True, "result": {
                "id": 999, "username": "ProductionBot", "is_bot": True}}):
            with self.assertRaises(ValueError):
                boundary.verify_bot(config)
        config["safe_chat_ids"] = [2]
        with self.assertRaises(harness.Refused):
            harness.validate(config)
        config["bot_username"] = "ProductionBot"
        attest()
        with self.assertRaises(harness.Refused):
            harness.validate(config)

    def test_local_and_redirect_style_upstreams_denied(self):
        for url in ["http://sandbox.invalid/status", "https://user:secret@example.com/status",
                    "https://example.com/status?target=prod", "https://example.com:8443/status"]:
            with self.assertRaises(ValueError):
                boundary.public_https(url)
        with patch.object(boundary.socket, "getaddrinfo", return_value=[
                (2, 1, 6, "", ("127.0.0.1", 443))]):
            with self.assertRaises(ValueError):
                boundary.upstream("https://sandbox.example/status")

    def test_attested_production_provider_url_still_rejected(self):
        config = harness.default("candidate")
        config.update(mode="sandbox", sandbox_token="synthetic-token")
        for url in ("https://klikqris.com/api/status", "https://sakurupiah.id/api/status",
                    "https://evil.example/api/sandbox/status",
                    "https://klikqris.com/api/sandbox/../status"):
            config["sandbox_status_url"] = url
            scope = {key: config[key] for key in sorted(harness.FIELDS - {"approval"})}
            config["approval"] = {"gate": "H0-approved-test-targets-only", "scope_sha256":
                                  hashlib.sha256(json.dumps(scope, sort_keys=True).encode()).hexdigest()}
            with self.assertRaises(harness.Refused):
                harness.validate(config)
    def test_p08_requires_explicit_sandbox_scope_and_loopback_publication(self):
        config = harness.default("candidate")
        config.update(mode="sandbox", sandbox_token="test-key", sandbox_status_url="https://sakurupiah.id/api-sanbox/",
                      p08_callback={"gate": "P08-approved-sandbox-callback-only"}, p08_api_id="test-id",
                      p08_phone="628000000000", p08_callback_url="https://callback.example/callback/sakurupiah",
                      p08_return_url="https://callback.example/return")
        scope = {key: config[key] for key in sorted(set(config) - {"approval"})}
        config["approval"] = {"gate": "H0-approved-test-targets-only", "scope_sha256":
                              hashlib.sha256(json.dumps(scope, sort_keys=True).encode()).hexdigest()}
        harness.validate(config)
        spec = harness.compose(config, Path("/tmp/test-p08"))
        self.assertIn("127.0.0.1:18803:8026", spec["services"]["boundary"]["ports"])
        self.assertNotIn("sandbox_token", spec["services"]["app"].get("environment", {}))
        config["p08_phone"] = "628000000001"
        with self.assertRaises(harness.Refused):
            harness.validate(config)
        config.update(mode="p08-sandbox-testing-bot", bot_id=123, bot_token="123:" + "a" * 35,
                      bot_username="LhieStoreTestBot")
        scope = {key: config[key] for key in sorted(set(config) - {"approval"})}
        config["approval"] = {"gate": "P08-approved-dual-targets-only", "scope_sha256":
                              hashlib.sha256(json.dumps(scope, sort_keys=True).encode()).hexdigest()}
        harness.validate(config)
        config["approval"]["gate"] = "H0-approved-test-targets-only"
        with self.assertRaises(harness.Refused):
            harness.validate(config)

    def test_callback_relay_authenticates_raw_body_before_forwarding(self):
        server = HTTPServer(("127.0.0.1", 0), boundary.callback_handler("sandbox-key", "internal-key"))
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        body = b'{"status":"pending"}'
        signature = hmac.new(b"sandbox-key", body, hashlib.sha256).hexdigest()
        try:
            with patch.object(boundary.http.client, "HTTPConnection") as upstream:
                upstream.return_value.getresponse.return_value.status = 200
                for supplied, expected in (("bad", 403), (signature, 200)):
                    conn = HTTPConnection(*server.server_address, timeout=2)
                    conn.request("POST", "/callback/sakurupiah", body, {
                        "Content-Type": "application/json", "X-Callback-Event": "payment_status",
                        "X-Callback-Signature": supplied})
                    reply = conn.getresponse()
                    self.assertEqual(reply.status, expected)
                    reply.read()
                    conn.close()
                self.assertEqual(upstream.call_count, 1)
                headers = upstream.return_value.request.call_args.args[3]
                self.assertEqual(headers["X-P08-Internal-Secret"], "internal-key")
                self.assertNotIn("sandbox-key", str(headers))
        finally:
            server.shutdown()
            server.server_close()

    def test_callback_relay_denies_other_routes_methods_and_oversize(self):
        server = HTTPServer(("127.0.0.1", 0), boundary.callback_handler("sandbox-key", "internal-key"))
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        body = b'{}'
        headers = {"Content-Type": "application/json", "X-Callback-Event": "payment_status",
                   "X-Callback-Signature": hmac.new(b"sandbox-key", body, hashlib.sha256).hexdigest()}
        cases = [("POST", "/callback/sakurupiah?x=1", body, headers),
                 ("POST", "/callback/other", body, headers),
                 ("GET", "/callback/sakurupiah", None, {}),
                 ("POST", "/callback/sakurupiah", b"x" * 16385, headers),
                 ("POST", "/callback/sakurupiah", body, {**headers, "Transfer-Encoding": "chunked"})]
        try:
            with patch.object(boundary.http.client, "HTTPConnection") as upstream:
                for method, path, payload, request_headers in cases:
                    conn = HTTPConnection(*server.server_address, timeout=2)
                    conn.request(method, path, payload, request_headers)
                    reply = conn.getresponse()
                    self.assertNotEqual(reply.status, 200)
                    reply.read()
                    conn.close()
                upstream.assert_not_called()
        finally:
            server.shutdown()
            server.server_close()


    def test_wrong_bot_stops_boundary_before_transports_become_ready(self):
        config = harness.default("candidate")
        config.update(mode="testing-bot", bot_token="synthetic-marker", bot_id=123,
                      bot_username="LhieStoreTestBot")
        with (patch("builtins.open", return_value=io.StringIO(json.dumps(config))),
              patch.object(boundary, "firewall"), patch.object(boundary.os, "setgroups"),
              patch.object(boundary.os, "setgid"), patch.object(boundary.os, "setuid"),
              patch.object(boundary.pwd, "getpwnam") as user,
              patch.object(boundary, "upstream", return_value={"ok": True, "result": {
                  "id": 999, "username": "ProductionBot", "is_bot": True}}),
              patch.object(boundary.socketserver, "TCPServer") as smtp):
            user.return_value.pw_gid = user.return_value.pw_uid = 10001
            with self.assertRaises(ValueError):
                boundary.main()
            smtp.assert_not_called()

    def test_transport_rejects_customer_routing_and_purchase_without_upstream(self):
        server = HTTPServer(("127.0.0.1", 0), boundary.handler(harness.default("candidate")))
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            with patch.object(boundary, "upstream", side_effect=AssertionError("unexpected upstream")):
                for path, payload, status in [
                    ("/telegram", {"chat_id": 1, "text": "synthetic"}, 200),
                    ("/telegram", {"chat_id": 42, "text": "secret-marker"}, 403),
                    ("/telegram", {"chat_id": 1, "text": "x", "reply_markup": {}}, 403),
                    ("/supplier/purchase", {}, 403), ("/sandbox/status", {}, 403),
                    ("/setWebhook", {}, 403)]:
                    conn = HTTPConnection(*server.server_address, timeout=2)
                    conn.request("POST", path, json.dumps(payload))
                    response = conn.getresponse()
                    self.assertEqual(response.status, status)
                    self.assertNotIn(b"secret-marker", response.read())
                    conn.close()
        finally:
            server.shutdown()
            server.server_close()

    def test_mail_sink_accepts_and_discards_message(self):
        server = socketserver.TCPServer(("127.0.0.1", 0), boundary.SMTP)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        try:
            with smtplib.SMTP(*server.server_address, timeout=2) as smtp:
                self.assertEqual(smtp.sendmail("test@example.invalid", ["customer@example.invalid"],
                                               "Subject: synthetic\n\nsecret-marker"), {})
        finally:
            server.shutdown()
            server.server_close()

    def test_live_transport_error_is_redacted(self):
        config = harness.default("candidate")
        config.update(mode="testing-bot", bot_token="secret-marker")
        server = HTTPServer(("127.0.0.1", 0), boundary.handler(config))
        threading.Thread(target=server.serve_forever, daemon=True).start()
        try:
            with patch.object(boundary, "upstream", side_effect=ValueError("secret-marker")):
                conn = HTTPConnection(*server.server_address, timeout=2)
                conn.request("POST", "/telegram", json.dumps({"chat_id": 1, "text": "synthetic"}))
                response = conn.getresponse()
                self.assertEqual(response.status, 403)
                self.assertNotIn(b"secret-marker", response.read())
                conn.close()
        finally:
            server.shutdown()
            server.server_close()

    def test_reset_refuses_unowned_volume_before_deletion(self):
        volume = harness.PREFIX + "candidate_database"
        with patch.object(harness, "run", side_effect=[json.dumps({"Name": volume}) + "\n",
                                                       json.dumps([{"Labels": {}}])]) as command:
            with self.assertRaises(harness.Refused):
                harness.owned_volumes("candidate")
            self.assertEqual(command.call_count, 2)
            self.assertNotIn("down", str(command.call_args_list))


if __name__ == "__main__":
    unittest.main()
