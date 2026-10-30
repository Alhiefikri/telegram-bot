"""Reviewed PHP identity primitives, not a login backend or session authority."""

import base64
import hashlib
import hmac
import ipaddress
import json
import re

import bcrypt
from argon2.exceptions import VerificationError
from argon2.low_level import Type, verify_secret


PHP_TRIM = " \t\n\r\0\v"
_ASCII_LOWER = str.maketrans("ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")
_ASCII_UPPER = str.maketrans("abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ")
_BCRYPT = re.compile(r"\$2[aby]\$[0-9]{2}\$[./A-Za-z0-9]{53}")
_API_AUTHORIZATION = re.compile(r"Bearer capi_([a-f0-9]{32})\.([a-f0-9]{64})", re.IGNORECASE | re.ASCII)
_EMAIL_ATOM = r"(?:[!#-'*+\-/0-9=?^-~]+|\"(?:[\x01-\x08\x0b\x0c\x0e-\x1f!#-\[\]-\x7f]|\\[\x00-\x7f])*\")"
_EMAIL_LOCAL = re.compile(_EMAIL_ATOM + r"(?:\." + _EMAIL_ATOM + r")*", re.ASCII)
# PHP FILTER_VALIDATE_EMAIL counts quoted/escaped local characters, not just bytes.
_EMAIL_COUNT_UNIT = r'(?:"?\\[\x00-\x7e]"?|"?[^\\"]"?)'
_EMAIL_LENGTH = re.compile(r"(?!(?:" + _EMAIL_COUNT_UNIT + r"){255,})(?!(?:" + _EMAIL_COUNT_UNIT + r"){65,}@)")
_DOMAIN_LABEL = re.compile(r"[a-z0-9]+(?:-+[a-z0-9]+)*", re.ASCII)
_DOMAIN_TLD = re.compile(r"(?:[a-z][a-z0-9]*|xn--[a-z0-9]+)(?:-+[a-z0-9]+)*", re.ASCII)


def _bytes(value: str | bytes) -> bytes:
    if isinstance(value, bytes):
        return value
    if isinstance(value, str):
        return value.encode("utf-8")
    raise TypeError("Expected text or bytes.")


def sha256_digest(value: str | bytes) -> str:
    """Digest exact token/session/hash bytes; no normalization or decoding."""
    return hashlib.sha256(_bytes(value)).hexdigest()


def application_hmac(value: str | bytes, app_key: str) -> str:
    """APP_KEY is the literal configuration text, including any base64: prefix."""
    return hmac.new(app_key.encode("utf-8"), _bytes(value), "sha256").hexdigest()


def customer_password_input(password: str | bytes) -> bytes:
    return base64.b64encode(hashlib.sha384(b"customer-password-v1\0" + _bytes(password)).digest())


def verify_legacy_password(password: str | bytes, stored_hash: str, *, algorithm: str) -> bool:
    """Verify a PHP KDF without rewriting it. The reviewed driver is explicit."""
    value = _bytes(password)
    try:
        encoded = stored_hash.encode("ascii")
        if algorithm == "bcrypt":
            if _BCRYPT.fullmatch(stored_hash) is None:
                return False
            # PHP crypt consumes a C string and bcrypt truncates at 72 bytes.
            return bcrypt.checkpw(value.split(b"\0", 1)[0][:72], encoded)
        argon_type = {"argon2i": Type.I, "argon2id": Type.ID}.get(algorithm)
        if argon_type is None:
            raise ValueError("Unsupported legacy password algorithm.")
        if not stored_hash.startswith("$" + algorithm + "$"):
            return False
        return verify_secret(encoded, value, argon_type)
    except (UnicodeError, VerificationError):
        return False
    except ValueError:
        if algorithm not in ("bcrypt", "argon2i", "argon2id"):
            raise
        return False


def verify_customer_password(password: str | bytes, stored_hash: str, scheme: str, *, algorithm: str) -> bool:
    if scheme == "sha384-v1":
        value = customer_password_input(password)
    elif scheme == "raw":
        value = _bytes(password)
        if stored_hash.startswith("$2") and len(value) > 72:
            return False
    else:
        return False
    return verify_legacy_password(value, stored_hash, algorithm=algorithm)


def verify_recovery_key(key: str, stored_hash: str, *, algorithm: str) -> bool:
    return verify_legacy_password(key.strip(PHP_TRIM).translate(_ASCII_UPPER), stored_hash, algorithm=algorithm)


def _valid_credential_email(value: str) -> bool:
    if not value.isascii() or len(value) > 320 or _EMAIL_LENGTH.match(value) is None:
        return False
    local, separator, domain = value.rpartition("@")
    if not separator or _EMAIL_LOCAL.fullmatch(local) is None:
        return False
    if domain.startswith("[") and domain.endswith("]"):
        address = domain[1:-1]
        ipv6 = address.startswith("ipv6:")
        if ipv6:
            address = address[5:]
        if "%" in address:
            return False
        try:
            parsed = ipaddress.ip_address(address)
        except ValueError:
            return False
        return parsed.version == (6 if ipv6 else 4)
    labels = domain.split(".")
    return (len(labels) >= 2 and all(len(label) <= 63 and _DOMAIN_LABEL.fullmatch(label) for label in labels)
            and _DOMAIN_TLD.fullmatch(labels[-1]) is not None)


def normalize_credential_identity(kind: str, value: str) -> str:
    if kind == "email":
        normalized = value.strip(PHP_TRIM).translate(_ASCII_LOWER)
        if not _valid_credential_email(normalized):
            raise ValueError("Credential email is invalid.")
        return normalized
    if kind == "auth_token":
        if value.strip(PHP_TRIM) == "":
            raise ValueError("Credential Auth Token is empty.")
        return value
    raise ValueError("Credential identity kind is invalid.")


def credential_fingerprint(kind: str, value: str, fingerprint_key: str) -> str:
    key = fingerprint_key.strip(PHP_TRIM)
    if key == "":
        raise ValueError("Credential identity key is unavailable.")
    # Kind is a separate registry column: adding it to the MAC would break lookup.
    return application_hmac(normalize_credential_identity(kind, value), key)


def credential_key_digest(fingerprint_key: str) -> str | None:
    key = fingerprint_key.strip(PHP_TRIM)
    return sha256_digest(key) if key else None


def normalize_customer_email(email: str) -> str:
    """Customer/waitlist use Laravel Str::lower, unlike credential ASCII lower."""
    return email.strip(PHP_TRIM).lower()


def waitlist_email_hash(email: str, app_key: str, *, waitlist_key: str = "") -> str:
    normalized = normalize_customer_email(email)
    if normalized == "":
        raise ValueError("Waitlist email is empty.")
    return application_hmac(normalized, waitlist_key.strip(PHP_TRIM) or app_key)


def parse_api_authorization(authorization: str | None) -> tuple[str, str] | None:
    """Return case-preserved selector and secret; no database authorization."""
    if not isinstance(authorization, str):
        return None
    match = _API_AUTHORIZATION.fullmatch(authorization)
    return (match[1], match[2]) if match else None


def api_confirmation_digest(nonce: str, *, action: str) -> str:
    if len(nonce.encode("utf-8")) != 32:
        raise ValueError("API confirmation must be 32 bytes.")
    if action not in ("rotate", "disable"):
        raise ValueError("Unsupported API confirmation action.")
    return sha256_digest(("disable:" if action == "disable" else "") + nonce)


def email_otp_digest(email: str, challenge_hash: str, code: str, app_key: str) -> str:
    """Inputs are already normalized/stored values, as in codeDigest()."""
    if app_key == "":
        raise ValueError("Customer authentication key unavailable.")
    return application_hmac("customer-email-otp:v1\0email_otp\0" + email + "\0" + challenge_hash + "\0" + code, app_key)


def browser_nonce_binding(session_id: str, nonce: str) -> str:
    return sha256_digest(session_id + "\0" + nonce)


def signed_url_digest(unsigned_url: str, app_key: str) -> str:
    """MAC an already canonical Laravel URL; not a route/expiry verifier."""
    return application_hmac(unsigned_url, app_key)


def admin_cursor_scope(actor_id: str, *, section: str, currency: str, telegram_id: str | None,
                       state: str | None, limit: int) -> str:
    """Hash PHP's ordered JSON with default slash/Unicode escaping."""
    payload = {"actor": actor_id, "section": section, "currency": currency,
               "telegram_id": telegram_id, "state": state, "limit": limit}
    encoded = json.dumps(payload, ensure_ascii=True, separators=(",", ":"), allow_nan=False).replace("/", "\\/")
    return sha256_digest(encoded)
