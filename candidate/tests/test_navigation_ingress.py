import unittest
from unittest.mock import patch

from lhie_candidate.navigation.ingress import InvalidUpdate, minimize
from lhie_candidate.navigation.transport import RelayTransport, classify


def message(update_id=1, text="/start"):
    return {"update_id": update_id, "message": {"message_id": 1, "text": text,
            "chat": {"id": 1, "type": "private"},
            "from": {"id": 1, "first_name": "Synthetic", "is_bot": False}}}


class IngressTests(unittest.TestCase):
    def test_minimization_drops_contacts_reply_and_unrelated_entities(self):
        payload = message(text="😀📦")
        payload["message"].update({"contact": {"phone_number": "secret"},
            "reply_to_message": {"text": "secret"}, "entities": [
                {"type": "bold", "offset": 0, "length": 2},
                {"type": "custom_emoji", "offset": 2, "length": 2, "custom_emoji_id": "123"}]})
        stored = minimize(payload)["message"]
        self.assertNotIn("contact", stored)
        self.assertNotIn("reply_to_message", stored)
        self.assertEqual(stored["entities"], [{"type": "custom_emoji", "offset": 2, "length": 2, "custom_emoji_id": "123"}])

    def test_utf16_split_surrogate_is_rejected(self):
        payload = message(text="😀")
        payload["message"]["entities"] = [{"type": "custom_emoji", "offset": 1, "length": 1, "custom_emoji_id": "123"}]
        with self.assertRaises(InvalidUpdate):
            minimize(payload)

    def test_malformed_and_mixed_updates_cannot_enter_inbox(self):
        for payload in ({"update_id": True}, {**message(), "callback_query": {}},
                        message(update_id=2**63), {"update_id": 1, "callback_query": {"from": {"id": 1}}}):
            with self.subTest(payload=payload), self.assertRaises(InvalidUpdate):
                minimize(payload)

    def test_pinned_credential_service_event_has_no_processing_payload(self):
        payload = message()
        payload["message"]["pinned_message"] = {"text": "credential secret"}
        self.assertEqual(minimize(payload), {"update_id": 1, "ignored_service_event": True})


class TransportTests(unittest.TestCase):
    def test_only_explicit_emoji_rejection_retries_with_unicode_and_styles(self):
        client = RelayTransport()
        payload = {"chat_id": 1, "text": '<tg-emoji emoji-id="123">📦</tg-emoji> Goods',
            "parse_mode": "HTML", "reply_markup": {"inline_keyboard": [[
                {"text": "Goods", "callback_data": "katalog", "icon_custom_emoji_id": "123",
                 "_unicode_fallback": "📦 Goods"}]]}}
        with patch.object(client, "_request", side_effect=[
                {"ok": False, "error_code": 400, "description": "Bad Request: custom emoji unavailable"},
                {"ok": True, "result": {"message_id": 10}}]) as request:
            result = client.execute("sendMessage", payload)
        self.assertEqual(result.outcome, "success")
        retry = request.call_args_list[1].args[1]
        self.assertEqual(retry["text"], "📦 Goods")
        self.assertEqual(retry["reply_markup"]["inline_keyboard"][0][0],
                         {"text": "📦 Goods", "callback_data": "katalog", "style": "primary"})
        self.assertIn("icon_custom_emoji_id", payload["reply_markup"]["inline_keyboard"][0][0])

    def test_ambiguous_and_malformed_ack_never_retry(self):
        for response in ({}, {"ok": True}, {"ok": True, "result": True},
                         {"ok": False, "error_code": 500, "description": "server unavailable"}):
            client = RelayTransport()
            with patch.object(client, "_request", return_value=response) as request:
                self.assertEqual(client.execute("sendMessage", {"chat_id": 1, "text": "Hello"}).outcome, "ambiguous")
            self.assertEqual(request.call_count, 1)

    def test_only_known_definite_edit_errors_authorize_replacement(self):
        self.assertEqual(classify("editMessageText", {"ok": False, "error_code": 400,
                         "description": "message to edit not found"}).outcome, "definite")
        self.assertEqual(classify("editMessageText", {"ok": False, "error_code": 400,
                         "description": "message is not modified"}).outcome, "not_modified")
        self.assertEqual(classify("editMessageText", {"ok": False, "error_code": 429,
                         "description": "retry later"}).outcome, "ambiguous")
