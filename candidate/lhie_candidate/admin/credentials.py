"""PHP-compatible structured credential payloads and identity fingerprints."""

import hashlib
import ipaddress
import re
from urllib.parse import urlsplit

from django.conf import settings

from ..compat.identity import (
    PHP_TRIM,
    credential_fingerprint,
    normalize_credential_identity,
)


ACCESS_METHODS = ("none", "otp_mail_link", "auth_token")
_HOST_LABEL = re.compile(r"[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\Z", re.ASCII)


class CredentialError(ValueError):
    def __init__(self, field, message):
        self.field, self.message = field, message
        super().__init__(message)


def fingerprint_key():
    value = getattr(settings, "CANDIDATE_IDENTITY_FINGERPRINT_KEY", None)
    if not isinstance(value, str):
        return ""
    return value.strip(PHP_TRIM)


def fingerprint_key_digest():
    key = fingerprint_key()
    return hashlib.sha256(key.encode("utf-8")).hexdigest() if key else None


def fingerprint(kind, value):
    key = fingerprint_key()
    if not key:
        raise CredentialError("access_value", "Pemeriksaan identitas belum tersedia.")
    return credential_fingerprint(kind, value, key)


def email(value):
    if not isinstance(value, str) or len(value) > 254:
        raise CredentialError("email", "Email wajib diisi dan maksimal 254 karakter.")
    try:
        normalized = normalize_credential_identity("email", value)
    except ValueError:
        raise CredentialError("email", "Email tidak valid.") from None
    if len(normalized) > 254:
        raise CredentialError("email", "Email maksimal 254 karakter.")
    return normalized


def password(value):
    if not isinstance(value, str) or value.strip() == "":
        raise CredentialError("password", "Password wajib diisi.")
    if len(value) > 1024:
        raise CredentialError("password", "Password maksimal 1024 karakter.")
    return value


def _https(value, *, maximum=2048):
    if not isinstance(value, str) or not value or len(value) > maximum:
        return None
    if value != value.strip(PHP_TRIM) or any(ord(char) <= 32 or ord(char) == 127 for char in value):
        return None
    try:
        parsed = urlsplit(value)
        port = parsed.port
    except ValueError:
        return None
    host = parsed.hostname
    if (parsed.scheme.lower() != "https" or not host or parsed.username or parsed.password
            or (port is not None and not 0 < port < 65536)):
        return None
    if host.startswith("[") and host.endswith("]"):
        try:
            return value if ipaddress.ip_address(host[1:-1]).version == 6 else None
        except ValueError:
            return None
    if re.fullmatch(r"[0-9.]+", host, re.ASCII):
        try:
            return value if ipaddress.ip_address(host).version == 4 else None
        except ValueError:
            return None
    labels = host.rstrip(".").split(".")
    if any(not label or _HOST_LABEL.fullmatch(label) is None for label in labels):
        return None
    return value


def access_method(value):
    if value not in ACCESS_METHODS:
        raise CredentialError("access_method", "Metode akses tidak valid.")
    return value


def structured_payload(*, email_value=None, password_value=None, access_method_value="none",
                       access_value=None, tutorial_override=None, reader_mail_link=None):
    method = access_method(access_method_value)
    if method == "none":
        access = None
        if access_value is not None and isinstance(access_value, str) and access_value.strip():
            raise CredentialError("access_value", "Nilai metode akses harus kosong saat memilih Tidak ada.")
        if access_value is not None and not isinstance(access_value, str):
            raise CredentialError("access_value", "Nilai metode akses tidak valid.")
    elif method == "otp_mail_link":
        if not isinstance(access_value, str) or access_value.strip() == "":
            raise CredentialError("access_value", "Nilai metode akses wajib diisi.")
        access = _https(access_value)
        if access is None:
            raise CredentialError("access_value", "OTP Mail Link harus berupa URL HTTPS yang aman.")
    else:
        if not isinstance(access_value, str) or access_value.strip() == "":
            raise CredentialError("access_value", "Auth Token wajib diisi.")
        if len(access_value) > 3500:
            raise CredentialError("access_value", "Auth Token maksimal 3500 karakter.")
        access = access_value

    tutorial = None
    if tutorial_override is not None:
        if not isinstance(tutorial_override, str):
            raise CredentialError("tutorial_override", "Tutorial tambahan tidak valid.")
        tutorial = tutorial_override if tutorial_override.strip(PHP_TRIM) else None
        if tutorial is not None and len(tutorial) > 2000:
            raise CredentialError("tutorial_override", "Tutorial tambahan maksimal 2000 karakter.")

    reader = None
    if reader_mail_link is not None:
        if not isinstance(reader_mail_link, str):
            raise CredentialError("reader_mail_link", "Link reader mail tidak valid.")
        reader = _https(reader_mail_link) if reader_mail_link.strip() else None
        if reader_mail_link.strip() and reader is None:
            raise CredentialError("reader_mail_link", "Link reader mail harus berupa URL HTTPS yang aman.")

    payload = {
        "version": 1,
        "email": email(email_value),
        "password": password(password_value),
        "access_method": method,
        "access_value": access,
        "tutorial_override": tutorial,
    }
    if reader is not None:
        payload["reader_mail_link"] = reader
    return payload


def decode_structured_payload(value):
    if not isinstance(value, dict):
        raise CredentialError("payload", "Data akun tidak valid.")
    version = value.get("version", value.get("payload_version"))
    if type(version) is not int or version != 1:
        raise CredentialError("payload", "Data akun tidak valid.")
    if ("version" in value and "payload_version" in value
            and value["version"] != value["payload_version"]):
        raise CredentialError("payload", "Data akun tidak valid.")
    return structured_payload(
        email_value=value.get("email"),
        password_value=value.get("password"),
        access_method_value=value.get("access_method"),
        access_value=value.get("access_value"),
        tutorial_override=value.get("tutorial_override"),
        reader_mail_link=value.get("reader_mail_link"),
    )


def identity_values(payload):
    if payload.get("access_method") == "auth_token" and payload.get("access_value") is not None:
        return [("auth_token", payload["access_value"])]
    return []


def otp_domain(payload):
    if payload.get("access_method") != "otp_mail_link" or not payload.get("access_value"):
        return None
    try:
        return (urlsplit(payload["access_value"]).hostname or "").lower() or None
    except ValueError:
        return None


def mask_email(value):
    if not isinstance(value, str) or "@" not in value:
        return "•••"
    local, domain = value.split("@", 1)
    return (local[:1] or "•") + "•••@" + domain


def credential_parts(value):
    """PHP CredentialDeliveryFormatter::parts, including the ---- variant."""
    if not isinstance(value, str):
        return None
    dash = False
    if "----" in value:
        head = value.split("----", 1)[0]
        try:
            dash = bool(normalize_credential_identity("email", head))
        except ValueError:
            dash = False
    parts = value.split("----" if dash else "|")
    if dash and len(parts) != 3:
        return None
    if len(parts) not in (2, 3):
        return None
    address, secret = (parts[0] or "").strip(PHP_TRIM), parts[1] or ""
    mfa = (parts[2] or "") if len(parts) == 3 else None
    try:
        normalize_credential_identity("email", address)
    except ValueError:
        return None
    if not secret.strip() or (mfa is not None and not mfa.strip()):
        return None
    if "\r" in address + secret + (mfa or "") or "\n" in address + secret + (mfa or ""):
        return None
    return {"email": address, "password": secret, "mfa": mfa}
