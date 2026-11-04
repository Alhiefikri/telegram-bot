"""Provider evidence and reserved-ingress boundaries; PostgreSQL races live in the auth smoke."""
from io import BytesIO
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch
from urllib.parse import parse_qs, urlsplit

from lhie_candidate.auth.google_provider import GoogleIdentity, GoogleOAuthProvider, MAX_RESPONSE_BYTES
from lhie_candidate.auth.telegram import TelegramAuthentication, canonical_actor, private_actor
from lhie_candidate.auth.telegram_ingress import ENCRYPTED_FIELD, handle, reserved, seal
from lhie_candidate.auth.types import AuthError
from lhie_candidate.compat.crypto import LaravelEncrypter
from lhie_candidate.compat.identity import application_hmac
from lhie_candidate.navigation.ingress import minimize
from lhie_candidate.navigation.processor import NavigationProcessor
from lhie_candidate.navigation.types import TelegramResult
from lhie_candidate.auth.bot_identity import TelegramBotIdentity


class BotIdentityTests(unittest.TestCase):
    def test_identity_requires_verified_bounded_bot_response_without_redirects(self):
        valid = {"ok": True, "result": {"id": 123, "username": "LhieStoreTestBot", "is_bot": True}}
        cases = [(200, valid, "LhieStoreTestBot"), (302, valid, None),
                 (200, {"ok": True, "result": {**valid["result"], "is_bot": 1}}, None),
                 (200, {"ok": True, "result": {**valid["result"], "id": True}}, None),
                 (503, {"ok": False}, None)]
        import json
        for status, payload, expected in cases:
            client = Mock()
            client.getresponse.return_value = Mock(status=status, read=Mock(return_value=json.dumps(payload).encode()))
            with patch("lhie_candidate.auth.bot_identity.http.client.HTTPConnection", return_value=client):
                self.assertEqual(TelegramBotIdentity().username(), expected)
            client.close.assert_called_once()
        client.getresponse.return_value = Mock(status=200, read=Mock(return_value=b" " * 1025))
        with patch("lhie_candidate.auth.bot_identity.http.client.HTTPConnection", return_value=client):
            self.assertIsNone(TelegramBotIdentity().username())


class GoogleProviderTests(unittest.TestCase):
    def provider(self, **changes):
        config = {"app_key": "synthetic-key", "client_id": "fixture-client", "client_secret": "fixture-secret",
                  "base_url": "https://store.example.test", "enabled": True}
        return GoogleOAuthProvider(**{**config, **changes})

    def test_default_provider_is_unavailable_without_network(self):
        with patch("lhie_candidate.auth.google_provider.http.client.HTTPSConnection") as connection:
            provider = GoogleOAuthProvider(app_key="synthetic-key")
            self.assertFalse(provider.ready())
            with self.assertRaises(AuthError):
                provider.user("authorization-code")
            connection.assert_not_called()

    def test_callback_origin_is_https_exact_and_not_an_arbitrary_redirect(self):
        for url in ("http://store.example.test", "https://user:pass@store.example.test", "https://store.example.test/path",
                    "https://store.example.test/?next=elsewhere", "https://store.example.test/#fragment",
                    "https://store.example.test?", "https://store.example.test#", "https://store.example.test\n"):
            with self.subTest(url=url):
                self.assertFalse(self.provider(base_url=url).ready())
        query = parse_qs(urlsplit(self.provider().authorization_url("state")).query)
        self.assertEqual(query["redirect_uri"], ["https://store.example.test/auth/google/callback"])
        self.assertEqual(query["scope"], ["openid email"])
        self.assertEqual(query["response_type"], ["code"])

    def test_configuration_binding_matches_literal_php_hmac(self):
        provider = self.provider()
        expected = application_hmac("14:fixture-client14:fixture-secrethttps://store.example.test/auth/google/callback", "synthetic-key")
        self.assertEqual(provider.configuration_fingerprint(), expected)
        self.assertNotEqual(expected, self.provider(client_secret="rotated").configuration_fingerprint())

    def test_verified_subject_and_normalized_email_only(self):
        provider = self.provider()
        with patch.object(provider, "_request", side_effect=[
            {"access_token": "access-token", "token_type": "Bearer", "id_token": "deliberately-unused"},
            {"sub": "stable-subject", "email": " NEW@Example.TEST ", "email_verified": True, "name": "Ignored"},
        ]) as request:
            identity = provider.user("code")
        self.assertEqual(identity, GoogleIdentity("stable-subject", "new@example.test"))
        self.assertEqual(request.call_args_list[0].args, ("oauth2.googleapis.com", "/token"))
        self.assertEqual(request.call_args_list[1].args, ("www.googleapis.com", "/oauth2/v3/userinfo"))
        self.assertEqual(request.call_args_list[1].kwargs, {"bearer": "access-token"})
        for flag in (False, "true", 1, None):
            with patch.object(provider, "_request", side_effect=[
                {"access_token": "access-token", "token_type": "Bearer"},
                {"sub": "subject", "email": "new@example.test", "email_verified": flag},
            ]), self.assertRaises(AuthError):
                provider.user("code")

    def test_subject_is_exact_and_never_trimmed_or_coerced(self):
        for subject in (1, "", " subject", "subject\n", "s" * 256):
            with self.subTest(subject=subject), self.assertRaises(AuthError):
                GoogleIdentity(subject, "customer@example.test")
        self.assertNotEqual(GoogleIdentity("Subject", "customer@example.test"), GoogleIdentity("subject", "customer@example.test"))

    def response(self, raw, *, length=None, status=200, encoding="identity"):
        stream = BytesIO(raw)
        response = Mock(status=status)
        response.getheader.side_effect = lambda key, default=None: {"Content-Length": length, "Content-Encoding": encoding}.get(key, default)
        response.read1.side_effect = stream.read1
        return response

    def test_bounded_http_rejects_redirects_oversize_and_duplicate_json(self):
        provider = self.provider()
        for response in (self.response(b"{}", status=302), self.response(b"{}", length=str(MAX_RESPONSE_BYTES + 1)),
                         self.response(b" " * (MAX_RESPONSE_BYTES + 1)), self.response(b'{"sub":"a","sub":"b"}'),
                         self.response(b'{"sub":"a"}', encoding="gzip")):
            client = Mock()
            client.getresponse.return_value = response
            with patch("lhie_candidate.auth.google_provider.http.client.HTTPSConnection", return_value=client) as factory:
                with self.assertRaises(AuthError):
                    provider._request("www.googleapis.com", "/oauth2/v3/userinfo", bearer="fixture")
                factory.assert_called_once_with("www.googleapis.com", timeout=3)
                client.close.assert_called_once()

    def test_http_uses_retained_socket_for_total_deadline_after_connection_close(self):
        provider = self.provider()
        client = Mock()
        sock = client.sock
        response = self.response(b'{"sub":"stable"}')
        def detached_response():
            client.sock = None
            return response
        client.getresponse.side_effect = detached_response
        with patch("lhie_candidate.auth.google_provider.http.client.HTTPSConnection", return_value=client):
            self.assertEqual(provider._request("www.googleapis.com", "/oauth2/v3/userinfo"), {"sub": "stable"})
        self.assertGreaterEqual(sock.settimeout.call_count, 2)
        self.assertFalse(client.request.call_args.kwargs)


class TelegramIngressTests(unittest.TestCase):
    def setUp(self):
        self.crypto = LaravelEncrypter(b"S" * 32)
        self.authentication = Mock()
        self.authentication.prepare.return_value = "wa_confirm:" + "B" * 43
        self.authentication.confirm.return_value = True
        self.transport = Mock()
        self.transport.execute.return_value = TelegramResult(123, "success")

    def message(self, *, text=None):
        return {"update_id": 60001, "message": {"message_id": 1,
                "from": {"id": 12345, "is_bot": False, "first_name": "Ignored name"},
                "chat": {"id": 12345, "type": "private"}, "text": text or "/start wa_" + "A" * 43}}

    def callback(self):
        original = self.message()
        return {"update_id": 60002, "callback_query": {"id": "callback-id", "data": "wa_confirm:" + "B" * 43,
                "from": original["message"]["from"], "message": {"message_id": 2, "chat": original["message"]["chat"]}}}

    def protected(self, original):
        return seal(minimize(original), original, crypto=self.crypto)

    def process(self, protected):
        return handle(protected, self.transport, authentication=self.authentication, crypto=self.crypto)

    def test_private_start_is_encrypted_and_intercepted_before_customer_resolution(self):
        protected = self.protected(self.message())
        self.assertEqual(set(protected), {"update_id", ENCRYPTED_FIELD})
        self.assertNotIn("wa_" + "A" * 43, str(protected))
        processor = NavigationProcessor(None, None, None, self.transport,
                                        authentication=self.authentication, auth_encrypter=self.crypto)
        with patch("lhie_candidate.navigation.processor.resolve_customer") as resolve:
            self.assertEqual(processor._navigate(protected, 60001, None), "auth_proof")
            resolve.assert_not_called()
        self.authentication.prepare.assert_called_once_with("wa_" + "A" * 43, "12345", "12345", "private")
        self.authentication.consume.assert_not_called()
        method, sent = self.transport.execute.call_args.args
        self.assertEqual(method, "sendMessage")
        self.assertEqual(sent["reply_markup"]["inline_keyboard"][0][0]["callback_data"], "wa_confirm:" + "B" * 43)

    def test_bot_confirmation_cannot_consume_a_browser_or_mutate_roots(self):
        self.assertEqual(self.process(self.protected(self.callback())), "auth_proof")
        self.authentication.confirm.assert_called_once_with("wa_confirm:" + "B" * 43, "12345", "12345", "private")
        self.authentication.consume.assert_not_called()
        self.assertEqual([call.args[0] for call in self.transport.execute.call_args_list], ["answerCallbackQuery", "sendMessage"])

    def test_foreign_missing_actor_provenance_and_noncanonical_ids_never_prepare(self):
        changes = [lambda p: p["message"]["from"].pop("is_bot"),
                   lambda p: p["message"]["chat"].pop("type"),
                   lambda p: p["message"]["from"].update(id="012345"),
                   lambda p: p["message"]["chat"].update(id=67890),
                   lambda p: p["message"]["chat"].update(id=-12345, type="supergroup")]
        for change in changes:
            original = self.message()
            change(original)
            self.assertEqual(self.process(self.protected(original)), "auth_invalid")
        self.authentication.prepare.assert_not_called()
        self.transport.execute.assert_not_called()

    def test_ciphertext_swapping_tampering_and_expiry_fail_closed(self):
        protected = self.protected(self.message())
        swapped = {**protected, "update_id": 60099}
        tampered = {**protected, ENCRYPTED_FIELD: protected[ENCRYPTED_FIELD][:-8] + "broken!!"}
        expired = seal(minimize(self.message()), self.message(), crypto=self.crypto, clock=lambda: 1)
        for value in (swapped, tampered, expired):
            self.assertEqual(self.process(value), "auth_invalid")
        self.authentication.prepare.assert_not_called()
        self.transport.execute.assert_not_called()

    def test_unknown_actor_and_false_confirmation_do_not_send_success(self):
        self.authentication.prepare.return_value = None
        self.authentication.confirm.return_value = False
        self.process(self.protected(self.message()))
        self.process(self.protected(self.callback()))
        for call in self.transport.execute.call_args_list:
            self.assertNotIn("Telegram dikonfirmasi", call.args[1].get("text", ""))
        self.authentication.consume.assert_not_called()

    def test_malformed_reserved_and_plaintext_legacy_payloads_never_resolve_customer(self):
        processor = NavigationProcessor(None, None, None, self.transport,
                                        authentication=self.authentication, auth_encrypter=self.crypto)
        malformed = self.protected(self.message(text="/start wa_short"))
        with patch("lhie_candidate.navigation.processor.resolve_customer") as resolve:
            self.assertEqual(processor._navigate(malformed, 60001, None), "auth_proof")
            self.assertEqual(processor._navigate(minimize(self.message()), 60001, None), "auth_invalid")
            resolve.assert_not_called()
        self.authentication.prepare.assert_not_called()

    def test_unrelated_start_keeps_normal_customer_resolution(self):
        original = minimize(self.message(text="/start"))
        self.assertFalse(reserved(original))
        self.assertEqual(seal(original, original, crypto=self.crypto), original)
        processor = NavigationProcessor(None, None, None, self.transport)
        with patch("lhie_candidate.navigation.processor.resolve_customer", return_value=None) as resolve:
            self.assertEqual(processor._navigate(original, 60001, None), "unknown_customer")
            resolve.assert_called_once()

    def test_provider_exception_cannot_echo_confirmation_secrets(self):
        self.transport.execute.side_effect = RuntimeError("wa_confirm:" + "B" * 43)
        with self.assertRaises(RuntimeError) as failure:
            self.process(self.protected(self.message()))
        self.assertNotIn("B" * 43, str(failure.exception))
        self.assertTrue(failure.exception.__suppress_context__)

    def test_canonical_numeric_actor_range_and_exact_private_chat(self):
        for value in ("0", "-1", "012345", "12345 ", "9223372036854775808", "1e3", "١٢٣", 12345, True):
            self.assertFalse(canonical_actor(value))
        self.assertTrue(canonical_actor("9223372036854775807"))
        self.assertFalse(private_actor("12345", "67890", "private"))
        self.assertFalse(private_actor("12345", "12345", "group"))
        self.assertTrue(private_actor("12345", "12345", "private"))

    def test_unverified_bot_identity_never_becomes_a_deep_link(self):
        identity = Mock()
        authentication = TelegramAuthentication(app_key="synthetic", limits=Mock(), identity=identity)
        for value in (None, "bad/name", "Not A Bot", True):
            identity.username.return_value = value
            self.assertFalse(authentication.ready())
        identity.username.return_value = "LhieStoreTestBot"
        self.assertTrue(authentication.ready())
