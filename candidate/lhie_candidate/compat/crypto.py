"""Bounded Laravel AES-256-CBC compatibility, without PHP object loading."""

import base64
import binascii
import hashlib
import hmac
import json
import math
import os
import re

from cryptography.hazmat.primitives import padding
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes


MAX_PLAINTEXT_BYTES = 1_048_576
MAX_ENVELOPE_BYTES = 2_097_152
MAX_DEPTH = 32
MAX_ITEMS = 10_000
_INT = re.compile(rb"-?(?:0|[1-9][0-9]*)\Z")
_FLOAT = re.compile(rb"-?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[Ee][+-]?[0-9]+)?\Z")


class CryptoError(ValueError):
    """Invalid, unauthenticated, unsupported or over-limit legacy value."""


def _fail():
    raise CryptoError("Invalid or unsupported legacy encrypted value") from None


def _b64decode(value):
    if not isinstance(value, str):
        _fail()
    try:
        decoded = base64.b64decode(value, validate=True)
    except (ValueError, binascii.Error):
        _fail()
    if base64.b64encode(decoded).decode("ascii") != value:
        _fail()
    return decoded


def _key(value):
    if isinstance(value, str):
        if len(value) > 51:
            _fail()
        try:
            value = _b64decode(value[7:]) if value.startswith("base64:") else value.encode("utf-8")
        except UnicodeError:
            _fail()
    if type(value) is not bytes or len(value) != 32:
        _fail()
    return value


def _utf8(value):
    if type(value) is not str or len(value) > MAX_PLAINTEXT_BYTES:
        _fail()
    try:
        raw = value.encode("utf-8")
    except UnicodeError:
        _fail()
    if len(raw) > MAX_PLAINTEXT_BYTES:
        _fail()
    return raw


def _pairs(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            _fail()
        result[key] = value
    return result


def _json_load(raw):
    try:
        if type(raw) is bytes:
            raw = raw.decode("utf-8")
        return json.loads(raw, object_pairs_hook=_pairs, parse_constant=lambda _: _fail())
    except (ValueError, UnicodeError, RecursionError):
        _fail()


def _numeric_php_key(value):
    return (
        len(value) <= 20
        and _INT.fullmatch(_utf8(value))
        and -(2**63) <= int(value) < 2**63
        and value != "-0"
    )


def _bounded(value, depth=0, budget=None, *, php=False):
    if budget is None:
        budget = [MAX_ITEMS, MAX_PLAINTEXT_BYTES]
    budget[0] -= 1
    if depth > MAX_DEPTH or budget[0] < 0:
        _fail()
    if value is None or type(value) is bool:
        return
    if type(value) is int:
        if not -(2**63) <= value < 2**63:
            _fail()
    elif type(value) is float:
        if not math.isfinite(value):
            _fail()
    elif type(value) is str:
        budget[1] -= len(_utf8(value))
        if budget[1] < 0:
            _fail()
    elif type(value) in (list, dict):
        items = enumerate(value) if type(value) is list else value.items()
        for key, child in items:
            if type(value) is dict:
                if php:
                    if type(key) not in (str, int):
                        _fail()
                    if type(key) is str and _numeric_php_key(key):
                        # PHP silently converts numeric string keys into integers.
                        _fail()
                elif type(key) is not str:
                    _fail()
                _bounded(key, depth + 1, budget, php=php)
            elif php:
                _bounded(key, depth + 1, budget, php=php)
            _bounded(child, depth + 1, budget, php=php)
    else:
        _fail()


def _php_encode(value):
    if value is None:
        return b"N;"
    if type(value) is bool:
        return b"b:1;" if value else b"b:0;"
    if type(value) is int:
        return b"i:" + str(value).encode("ascii") + b";"
    if type(value) is float:
        return b"d:" + repr(value).encode("ascii") + b";"
    if type(value) is str:
        raw = _utf8(value)
        return b's:' + str(len(raw)).encode("ascii") + b':"' + raw + b'";'
    pairs = enumerate(value) if type(value) is list else value.items()
    body = b"".join(_php_encode(key) + _php_encode(child) for key, child in pairs)
    return b"a:" + str(len(value)).encode("ascii") + b":{" + body + b"}"


class _PHPReader:
    def __init__(self, raw):
        self.raw = raw
        self.pos = 0
        self.remaining = MAX_ITEMS

    def take(self, expected):
        if not self.raw.startswith(expected, self.pos):
            _fail()
        self.pos += len(expected)

    def number(self, delimiter):
        end = self.raw.find(delimiter, self.pos)
        if end < 0 or end - self.pos > 32:
            _fail()
        token = self.raw[self.pos:end]
        self.pos = end + len(delimiter)
        return token

    def read(self, depth=0):
        self.remaining -= 1
        if depth > MAX_DEPTH or self.remaining < 0:
            _fail()
        kind = self.raw[self.pos:self.pos + 1]
        self.pos += 1
        if kind == b"N":
            self.take(b";")
            return None
        self.take(b":")
        if kind in (b"b", b"i", b"d"):
            token = self.number(b";")
            if kind == b"b":
                if token not in (b"0", b"1"):
                    _fail()
                return token == b"1"
            if kind == b"i":
                if not _INT.fullmatch(token):
                    _fail()
                value = int(token)
                if not -(2**63) <= value < 2**63:
                    _fail()
                return value
            if not _FLOAT.fullmatch(token):
                _fail()
            value = float(token)
            if not math.isfinite(value):
                _fail()
            return value
        if kind not in (b"s", b"a"):
            # Includes objects, custom serializers, enums and reference tokens.
            _fail()
        token = self.number(b":")
        if not token.isdigit() or len(token) > 8:
            _fail()
        size = int(token)
        if kind == b"s":
            if size > MAX_PLAINTEXT_BYTES:
                _fail()
            self.take(b'"')
            raw = self.raw[self.pos:self.pos + size]
            self.pos += size
            self.take(b'";')
            try:
                return raw.decode("utf-8")
            except UnicodeError:
                _fail()
        if size > MAX_ITEMS or size * 2 > self.remaining:
            _fail()
        self.take(b"{")
        result = {}
        for _ in range(size):
            key = self.read(depth + 1)
            if type(key) not in (str, int) or key in result:
                _fail()
            if type(key) is str and _numeric_php_key(key):
                _fail()
            result[key] = self.read(depth + 1)
        self.take(b"}")
        if list(result) == list(range(size)):
            return list(result.values())
        return result


class LaravelEncrypter:
    """Current-key writes; authenticated current/previous-key reads.

    Keys are raw 32-byte material, literal UTF-8 key strings, or Laravel
    ``base64:`` configuration strings. No configuration is read implicitly.
    """

    def __init__(self, current_key, previous_keys=()):
        if type(previous_keys) not in (tuple, list) or len(previous_keys) > 16:
            _fail()
        self._keys = (_key(current_key), *(_key(key) for key in previous_keys))

    def _encrypt(self, raw):
        if len(raw) > MAX_PLAINTEXT_BYTES:
            _fail()
        iv = os.urandom(16)
        padder = padding.PKCS7(128).padder()
        padded = padder.update(raw) + padder.finalize()
        encryptor = Cipher(algorithms.AES(self._keys[0]), modes.CBC(iv)).encryptor()
        ciphertext = encryptor.update(padded) + encryptor.finalize()
        iv_text = base64.b64encode(iv).decode("ascii")
        value = base64.b64encode(ciphertext).decode("ascii")
        mac = hmac.new(self._keys[0], (iv_text + value).encode("ascii"), hashlib.sha256).hexdigest()
        payload = json.dumps({"iv": iv_text, "value": value, "mac": mac, "tag": ""}, separators=(",", ":"))
        return base64.b64encode(payload.encode("ascii")).decode("ascii")

    def _decrypt(self, envelope):
        if type(envelope) is not str or len(envelope) > MAX_ENVELOPE_BYTES:
            _fail()
        payload = _json_load(_b64decode(envelope))
        if type(payload) is not dict or set(payload) not in ({"iv", "value", "mac"}, {"iv", "value", "mac", "tag"}):
            _fail()
        if any(type(value) is not str for value in payload.values()) or payload.get("tag", "") != "":
            _fail()
        iv = _b64decode(payload["iv"])
        ciphertext = _b64decode(payload["value"])
        if len(iv) != 16 or not ciphertext or len(ciphertext) % 16 or len(ciphertext) > MAX_PLAINTEXT_BYTES + 16:
            _fail()
        if not re.fullmatch(r"[0-9a-f]{64}", payload["mac"]):
            _fail()
        message = (payload["iv"] + payload["value"]).encode("ascii")
        valid_key = None
        for key in self._keys:
            valid = hmac.compare_digest(hmac.new(key, message, hashlib.sha256).hexdigest(), payload["mac"])
            if valid and valid_key is None:
                valid_key = key
        if valid_key is None:
            _fail()
        # No cipher or unpadder is constructed until a configured key authenticates.
        try:
            decryptor = Cipher(algorithms.AES(valid_key), modes.CBC(iv)).decryptor()
            padded = decryptor.update(ciphertext) + decryptor.finalize()
            unpadder = padding.PKCS7(128).unpadder()
            raw = unpadder.update(padded) + unpadder.finalize()
        except ValueError:
            _fail()
        if len(raw) > MAX_PLAINTEXT_BYTES:
            _fail()
        return raw

    def encrypt_string(self, value):
        return None if value is None else self._encrypt(_utf8(value))

    def decrypt_string(self, envelope):
        if envelope is None:
            return None
        raw = self._decrypt(envelope)
        try:
            return raw.decode("utf-8")
        except UnicodeError:
            _fail()

    def encrypt_json(self, value):
        if value is None:
            return None
        _bounded(value)
        try:
            raw = json.dumps(value, ensure_ascii=True, allow_nan=False, separators=(",", ":"))
        except (ValueError, RecursionError):
            _fail()
        return self._encrypt(_utf8(raw))

    def decrypt_json(self, envelope):
        if envelope is None:
            return None
        value = _json_load(self._decrypt(envelope))
        _bounded(value)
        return value

    def encrypt_php(self, value):
        _bounded(value, php=True)
        return self._encrypt(_php_encode(value))

    def decrypt_php(self, envelope):
        reader = _PHPReader(self._decrypt(envelope))
        value = reader.read()
        if reader.pos != len(reader.raw):
            _fail()
        return value
