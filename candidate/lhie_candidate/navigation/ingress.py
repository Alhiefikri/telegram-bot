"""Bounded, minimized navigation ingress; accepted updates are durable identities."""
import json
import re
from dataclasses import dataclass

from django.db import connection


class InvalidUpdate(ValueError):
    pass


def integer(value, *, positive=True):
    if type(value) is str and re.fullmatch(r"-?[0-9]{1,19}", value.strip()):
        value = int(value)
    if type(value) is not int or not -(2**63) < value < 2**63 or (value < 1 if positive else value == 0):
        raise InvalidUpdate("invalid update identity")
    return value


def text(value, maximum):
    if not isinstance(value, str) or not value.strip() or len(value.encode()) > maximum:
        raise InvalidUpdate("invalid update content")
    return value


def minimize(payload):
    if not isinstance(payload, dict):
        raise InvalidUpdate("unsupported update")
    update_id = integer(payload.get("update_id"))
    if ("message" in payload) == ("callback_query" in payload):
        raise InvalidUpdate("unsupported update")
    callback = "callback_query" in payload
    source = payload["callback_query" if callback else "message"]
    if not isinstance(source, dict):
        raise InvalidUpdate("unsupported update")
    message = source.get("message") if callback else source
    actor = source.get("from")
    if not isinstance(message, dict) or not isinstance(actor, dict) or not isinstance(message.get("chat"), dict):
        raise InvalidUpdate("unsupported update")
    chat = message["chat"]
    chat_id = integer(chat.get("id"), positive=False)
    actor_id = integer(actor.get("id"))
    kind = chat.get("type", "private" if actor_id == chat_id else "group")
    if kind not in ("private", "group", "supergroup") or actor.get("is_bot") is True:
        raise InvalidUpdate("unsupported update actor")
    compact = {"message_id": integer(message.get("message_id")),
               "chat": {"id": chat_id, "type": kind}}
    sender = {"id": actor_id, "is_bot": False}
    for field in ("first_name", "username"):
        if isinstance(actor.get(field), str):
            sender[field] = actor[field][:256]
    if callback:
        return {"update_id": update_id, "callback_query": {
            "id": text(source.get("id"), 256), "from": sender,
            "message": compact, "data": text(source.get("data"), 64)}}
    compact["from"] = sender
    if "pinned_message" in source:
        return {"update_id": update_id, "ignored_service_event": True}
    for field in ("text", "caption"):
        if field in source:
            compact[field] = text(source[field], 16384)
    entities = []
    for field in ("entities", "caption_entities"):
        raw = source.get(field, [])
        if not isinstance(raw, list) or len(raw) > 100:
            raise InvalidUpdate("invalid entities")
        for entity in raw:
            if not isinstance(entity, dict) or entity.get("type") != "custom_emoji":
                continue
            identity = text(entity.get("custom_emoji_id"), 64)
            offset, length = entity.get("offset"), entity.get("length")
            if type(offset) is not int or offset < 0 or type(length) is not int or length < 1:
                raise InvalidUpdate("invalid entities")
            content = source.get("text", source.get("caption", ""))
            encoded = content.encode("utf-16-le")
            try:
                if (offset + length) * 2 > len(encoded):
                    raise ValueError()
                encoded[:offset * 2].decode("utf-16-le")
                encoded[offset * 2:(offset + length) * 2].decode("utf-16-le")
            except (UnicodeError, ValueError):
                raise InvalidUpdate("invalid entities") from None
            entities.append({"type": "custom_emoji", "custom_emoji_id": identity,
                             "offset": offset, "length": length})
    if entities:
        compact["entities"] = entities
    for media_kind in ("photo", "video", "animation", "document", "audio", "voice", "sticker"):
        if media_kind in source:
            compact["media_kind"] = media_kind
            break
    if isinstance(source.get("media_group_id"), (str, int)) and not isinstance(source["media_group_id"], bool):
        compact["media_group_id"] = str(source["media_group_id"])[:256]
    def photos(raw):
        if not isinstance(raw, list) or len(raw) > 20:
            raise InvalidUpdate("invalid photo")
        result = []
        for photo in raw:
            if not isinstance(photo, dict) or not isinstance(photo.get("file_id"), str) or not photo["file_id"].strip():
                continue
            item = {"file_id": text(photo["file_id"], 512)}
            for key in ("file_size", "width", "height"):
                number = photo.get(key)
                if isinstance(number, str) and number.isascii() and number.isdecimal() and len(number) <= 19:
                    number = int(number)
                if type(number) is int and 0 <= number < 2**63:
                    item[key] = number
            result.append(item)
        return result
    if "photo" in source:
        compact["photo"] = photos(source["photo"])
    reply = source.get("reply_to_message")
    if isinstance(reply, dict):
        retained = {}
        if type(reply.get("message_id")) is int and reply["message_id"] > 0:
            retained["message_id"] = reply["message_id"]
        if "photo" in reply:
            retained["photo"] = photos(reply["photo"])
        if retained:
            compact["reply_to_message"] = retained
    return {"update_id": update_id, "message": compact}


@dataclass(frozen=True)
class Admission:
    update_id: int
    duplicate: bool
    response: dict


class UpdateInbox:
    def receive(self, payload):
        payload = minimize(payload)
        update_id = payload["update_id"]
        if payload.get("ignored_service_event"):
            return Admission(update_id, False, {"ok": True})
        source = payload.get("callback_query", payload.get("message"))
        message = source.get("message", source)
        with connection.cursor() as cursor:
            cursor.execute(
                "INSERT INTO django_candidate.navigation_updates (update_id,chat_id,payload) "
                "VALUES (%s,%s,%s::jsonb) ON CONFLICT (update_id) DO NOTHING RETURNING update_id",
                [update_id, message["chat"]["id"], json.dumps(payload)],
            )
            duplicate = cursor.fetchone() is None
        acknowledgement = ({"method": "answerCallbackQuery", "callback_query_id": source["id"]}
                           if "callback_query" in payload else {"ok": True})
        return Admission(update_id, duplicate, acknowledgement)
