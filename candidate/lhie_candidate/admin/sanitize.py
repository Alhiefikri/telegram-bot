"""Bounded presentation helpers for operator-visible operational data.

Secrets never leave the domain services. This module only formats already
authorized read models and redacts defence-in-depth values that must not be
echoed through an audit diff or an export.
"""

import json
import re
from datetime import datetime


_SECRET_KEYS = frozenset({
    "password", "passphrase", "secret", "token", "api_key", "apikey",
    "credential", "credential_payload", "private_key", "seed", "signature",
    "provider_signature", "qr", "qris", "qris_url", "provider_payload",
    "raw_payload", "chain_evidence", "refund_evidence", "evidence",
    "payment_proof_file_id", "telegram_file_id", "authorization",
    "receipt_fingerprint", "fingerprint", "session_binding", "credential_binding",
})

_SECRET_FRAGMENT = re.compile(
    r"(?:password|secret|token|api[_-]?key|credential|private[_-]?key|seed|signature"
    r"|provider[_-]?payload|raw[_-]?payload|chain[_-]?evidence|refund[_-]?evidence"
    r"|proof[_-]?file|file[_-]?id|fingerprint|binding|authorization|qris|qr)",
    re.IGNORECASE,
)

_MAX_DEPTH = 3
_MAX_ITEMS = 40
_MAX_TEXT = 300


def _is_secret(key):
    normalized = str(key).lower()
    return normalized in _SECRET_KEYS or _SECRET_FRAGMENT.search(normalized) is not None


def bounded(value, depth=0):
    """Return a JSON-safe, bounded value with secret-shaped keys redacted."""
    if depth >= _MAX_DEPTH:
        return "[dibatasi]"
    if value is None or type(value) in (bool, int):
        return value
    if type(value) is float:
        return str(value)
    if isinstance(value, datetime):
        return value.strftime("%Y-%m-%d %H:%M:%S")
    if isinstance(value, str):
        return value if len(value) <= _MAX_TEXT else value[:_MAX_TEXT] + "…"
    if isinstance(value, dict):
        result = {}
        for index, (key, item) in enumerate(value.items()):
            if index >= _MAX_ITEMS:
                result["…"] = "[dibatasi]"
                break
            result[str(key)] = "[dirahasiakan]" if _is_secret(key) else bounded(item, depth + 1)
        return result
    if isinstance(value, (list, tuple)):
        result = [bounded(item, depth + 1) for item in value[:_MAX_ITEMS]]
        if len(value) > _MAX_ITEMS:
            result.append("[dibatasi]")
        return result
    return "[dibatasi]"


def json_value(value):
    """Decode one bounded JSON column, never raising for corrupt history."""
    if value is None:
        return None
    if isinstance(value, (dict, list)):
        return bounded(value)
    if not isinstance(value, str) or len(value) > 65536:
        return None
    try:
        return bounded(json.loads(value))
    except (ValueError, TypeError):
        return None


def json_text(value):
    bounded_value = json_value(value)
    return "" if bounded_value is None else json.dumps(
        bounded_value, ensure_ascii=False, sort_keys=True, indent=2
    )


def safe_error(code):
    """Provider/domain error codes only; free text is deliberately omitted."""
    if not isinstance(code, str) or not re.fullmatch(r"[A-Za-z0-9_.:-]{1,80}", code):
        return None
    return code


def mask_identifier(value, *, visible=4):
    if value is None:
        return ""
    text = str(value)
    if len(text) <= visible:
        return "•" * len(text)
    return "•" * (len(text) - visible) + text[-visible:]
