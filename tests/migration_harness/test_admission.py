"""Risk tests for admission, transport routing, redaction and quarantine boundaries."""
import hashlib
import io
from http.client import HTTPConnection
from http.server import HTTPServer
import json
from pathlib import Path
import smtplib
import socketserver
import sys
import threading
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "tools/migration_harness"))
import boundary
import harness


class AdmissionTests(unittest.TestCase):
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
