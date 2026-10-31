"""Navigation-only relay client. No token, provider endpoint or retry-on-timeout path."""
import copy
import http.client
import json
import re

from .types import TelegramResult


METHODS = frozenset({"sendMessage", "sendPhoto", "sendAnimation", "editMessageText",
                     "editMessageCaption", "editMessageMedia", "editMessageReplyMarkup",
                     "deleteMessage", "answerCallbackQuery"})
DEFINITE_EDIT = ("message to edit not found", "message can't be edited", "message is too old",
                 "there is no text in the message to edit", "there is no caption in the message to edit")


def fallback(payload):
    payload = copy.deepcopy(payload)
    for field in ("text", "caption"):
        if isinstance(payload.get(field), str):
            payload[field] = re.sub(r'<tg-emoji\b[^>]*>(.*?)</tg-emoji>', r'\1', payload[field], flags=re.S)
    for field in ("entities", "caption_entities"):
        if isinstance(payload.get(field), list):
            payload[field] = [item for item in payload[field] if item.get("type") != "custom_emoji"]
    for row in payload.get("reply_markup", {}).get("inline_keyboard", []):
        for button in row:
            button.pop("icon_custom_emoji_id", None)
            if "_unicode_fallback" in button:
                button["text"] = button.pop("_unicode_fallback")
    return payload


def normalize(payload):
    result = copy.deepcopy(payload)
    for row in result.get("reply_markup", {}).get("inline_keyboard", []):
        for button in row:
            button.setdefault("style", "primary")
    return result


def classify(method, response):
    if not isinstance(response, dict):
        return TelegramResult(None, "ambiguous")
    if response.get("ok") is True:
        result = response.get("result")
        if method in ("deleteMessage", "answerCallbackQuery") and result is True:
            return TelegramResult(None, "success")
        if isinstance(result, dict) and type(result.get("message_id")) is int and result["message_id"] > 0:
            return TelegramResult(result["message_id"], "success")
        return TelegramResult(None, "ambiguous")
    description = str(response.get("description", "")).lower()
    code = response.get("error_code")
    if code == 400 and "message is not modified" in description:
        return TelegramResult(None, "not_modified")
    if code == 400 and any(reason in description for reason in DEFINITE_EDIT):
        return TelegramResult(None, "definite")
    # Auth/throttle/server/unknown API failures never authorize a replacement send.
    return TelegramResult(None, "ambiguous")


class RelayTransport:
    def __init__(self):
        self.emoji_unavailable = False

    def _request(self, method, payload):
        client = http.client.HTTPConnection("127.0.0.1", 8025, timeout=8)
        try:
            client.request("POST", "/telegram/navigation", json.dumps({"method": method, "payload": payload}),
                           {"Content-Type": "application/json"})
            response = client.getresponse()
            raw = response.read(65537)
            if len(raw) > 65536:
                return {}
            return json.loads(raw)
        except (OSError, ValueError, http.client.HTTPException):
            return {}
        finally:
            client.close()

    def execute(self, method, payload):
        if method not in METHODS:
            raise ValueError("unsupported navigation operation")
        payload = normalize(payload)
        if self.emoji_unavailable:
            payload = fallback(payload)
        sent = copy.deepcopy(payload)
        for row in sent.get("reply_markup", {}).get("inline_keyboard", []):
            for button in row:
                button.pop("_unicode_fallback", None)
        response = self._request(method, sent)
        description = str(response.get("description", "")).lower() if isinstance(response, dict) else ""
        if (isinstance(response, dict) and response.get("ok") is False and response.get("error_code") == 400
                and ("custom emoji" in description or "icon_custom_emoji_id" in description or "tg-emoji" in description)):
            # Explicit rejection proves no delivery; only this failure permits fallback.
            self.emoji_unavailable = True
            response = self._request(method, fallback(payload))
        return classify(method, response)
