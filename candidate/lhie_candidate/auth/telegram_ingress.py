"""Reserved web-auth ingress, intercepted before Store Bot Customer resolution."""
import re
import time

from ..compat.crypto import LaravelEncrypter
from .telegram import TelegramAuthentication, canonical_actor, private_actor

ENCRYPTED_FIELD = "_encrypted_web_auth"
CONSUMED_FIELD = "_web_auth_consumed"
_INVALID = "Permintaan akun web tidak dapat digunakan. Mulai lagi dari situs toko."


def reserved(payload):
    if not isinstance(payload, dict):
        return False
    callback = payload.get("callback_query")
    if isinstance(callback, dict):
        data = callback.get("data")
        return isinstance(data, str) and data.startswith("wa_confirm:")
    message = payload.get("message")
    text = message.get("text") if isinstance(message, dict) else None
    return isinstance(text, str) and re.match(r"/start(?:@[A-Za-z0-9_]+)?\s+wa_", text.strip()) is not None


def trusted_private(payload):
    source = payload.get("callback_query", payload.get("message", {}))
    if not isinstance(source, dict):
        return False
    message = source.get("message", source)
    actor = source.get("from")
    chat = message.get("chat") if isinstance(message, dict) else None
    if not isinstance(actor, dict) or not isinstance(chat, dict) or actor.get("is_bot") is not False:
        return False
    actor_id, chat_id = actor.get("id"), chat.get("id")
    if type(actor_id) not in (int, str) or type(chat_id) not in (int, str):
        return False
    return private_actor(str(actor_id), str(chat_id), chat.get("type"))


def encrypter():
    from ..runtime import application_key
    return LaravelEncrypter(application_key())


def seal(payload, original, *, crypto=None, clock=time.time):
    if not reserved(payload):
        return payload
    value = {"payload": payload, "trusted_private": trusted_private(original), "expires_at": int(clock()) + 300}
    try:
        encrypted = (crypto or encrypter()).encrypt_json(value)
    except Exception:
        raise ValueError("Authentication ingress unavailable") from None
    return {"update_id": payload["update_id"], ENCRYPTED_FIELD: encrypted}


def _authentication():
    from ..runtime import application_key
    from .limits import RateLimits
    key = application_key()
    return TelegramAuthentication(app_key=key, limits=RateLimits(key))


def _send(transport, method, payload):
    try:
        result = transport.execute(method, payload)
        if result.outcome not in ("success", "not_modified"):
            return "ambiguous"
        return "auth_proof"
    except Exception:
        # Relay failures may echo callback_data; never retain their exception context.
        raise RuntimeError("Telegram authentication response unavailable") from None


def handle(payload, transport, *, authentication=None, crypto=None, clock=time.time):
    if not isinstance(payload, dict):
        return None
    if ENCRYPTED_FIELD not in payload:
        return "auth_invalid" if reserved(payload) or payload.get(CONSUMED_FIELD) else None
    try:
        value = (crypto or encrypter()).decrypt_json(payload[ENCRYPTED_FIELD])
        decoded = value.get("payload") if isinstance(value, dict) else None
        if (not isinstance(decoded, dict) or decoded.get("update_id") != payload.get("update_id")
                or type(value.get("expires_at")) is not int or value["expires_at"] <= int(clock())
                or value.get("trusted_private") is not True or not trusted_private(decoded) or not reserved(decoded)):
            return "auth_invalid"
    except Exception:
        return "auth_invalid"
    authentication = authentication or _authentication()
    callback = decoded.get("callback_query")
    source = callback or decoded["message"]
    message = source["message"] if callback else source
    actor_id, chat_id = str(source["from"]["id"]), str(message["chat"]["id"])
    if not canonical_actor(actor_id):
        return "auth_invalid"
    if callback:
        confirmed = authentication.confirm(callback["data"], actor_id, chat_id, "private")
        outcome = _send(transport, "answerCallbackQuery", {"callback_query_id": callback["id"],
                        "text": "Telegram dikonfirmasi. Kembali ke browser yang memulai permintaan." if confirmed else _INVALID})
        if outcome == "ambiguous" or not confirmed:
            return outcome
        return _send(transport, "sendMessage", {"chat_id": chat_id,
                     "text": 'Telegram dikonfirmasi. Kembali ke situs toko di browser yang memulai permintaan, lalu klik "Periksa konfirmasi Telegram" untuk menyelesaikannya.'})
    match = re.fullmatch(r"/start(?:@[A-Za-z0-9_]+)?\s+(wa_[A-Za-z0-9_-]{43})", source["text"].strip())
    confirmation = authentication.prepare(match[1], actor_id, chat_id, "private") if match else None
    if confirmation is None:
        return _send(transport, "sendMessage", {"chat_id": chat_id, "text": _INVALID})
    return _send(transport, "sendMessage", {
        "chat_id": chat_id,
        "text": "Konfirmasi akun web\n\nLanjutkan hanya jika Anda baru saja meminta masuk atau menghubungkan Telegram dari situs toko di browser Anda sendiri. Tombol ini membuktikan kepemilikan akun Telegram untuk permintaan tersebut.\n\nJika bukan Anda yang memulai, abaikan pesan ini. Jangan teruskan tautan atau tombol ini kepada siapa pun. Permintaan berakhir dalam 5 menit. Setelah konfirmasi, kembali ke browser yang memulai permintaan untuk menyelesaikannya.",
        "reply_markup": {"inline_keyboard": [[{"text": "Konfirmasi permintaan akun web", "style": "primary", "callback_data": confirmation}]]},
    })
