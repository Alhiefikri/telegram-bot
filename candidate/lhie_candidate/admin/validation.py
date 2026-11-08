"""Explicit form normalization for admin HTML forms.

Every helper records safe, field-indexed messages. No helper reflects the
submitted value in an error, so credentials and payloads cannot leak through a
validation response.
"""

from urllib.parse import urlsplit

from ..compat.identity import PHP_TRIM


USER_MESSAGES = {
    "required": "{label} wajib diisi.",
    "too_long": "{label} maksimal {maximum} karakter.",
    "invalid": "{label} tidak valid.",
}


def raw(data, key, default=""):
    if hasattr(data, "getlist"):
        values = data.getlist(key)
        return values[0] if len(values) == 1 else (default if not values else values[-1])
    value = data.get(key, default)
    return default if value is None else value


def required_text(errors, data, key, label, maximum):
    value = raw(data, key)
    if not isinstance(value, str):
        errors[key] = [USER_MESSAGES["invalid"].format(label=label)]
        return ""
    value = value.strip(PHP_TRIM)
    if value == "":
        errors[key] = [USER_MESSAGES["required"].format(label=label)]
    elif len(value) > maximum:
        errors[key] = [USER_MESSAGES["too_long"].format(label=label, maximum=maximum)]
    return value


def optional_text(errors, data, key, label, maximum):
    value = raw(data, key)
    if value is None or value == "":
        return None
    if not isinstance(value, str):
        errors[key] = [USER_MESSAGES["invalid"].format(label=label)]
        return None
    value = value.strip(PHP_TRIM)
    if value == "":
        return None
    if len(value) > maximum:
        errors[key] = [USER_MESSAGES["too_long"].format(label=label, maximum=maximum)]
    return value


def optional_multiline(errors, data, key, label, maximum_lines=20, maximum_line=160):
    value = raw(data, key)
    if value is None or value == "":
        return None
    if not isinstance(value, str):
        errors[key] = [USER_MESSAGES["invalid"].format(label=label)]
        return None
    value = value.strip(PHP_TRIM)
    if value == "":
        return None
    lines = value.replace("\r\n", "\n").replace("\r", "\n").split("\n")
    lines = [line.strip() for line in lines if line.strip() != ""]
    if len(lines) > maximum_lines:
        errors[key] = [f"{label} maksimal {maximum_lines} baris."]
    elif any(len(line) > maximum_line for line in lines):
        errors[key] = [f"{label} maksimal {maximum_line} karakter per baris."]
    return "\n".join(lines) if lines else None


def boolean(data, key):
    value = raw(data, key, "")
    if isinstance(value, bool):
        return value
    return str(value).strip().lower() in {"1", "true", "on", "yes"}


def safe_usage_guide_url(value):
    if not isinstance(value, str) or len(value) > 2048:
        return False
    if value.startswith("/") and not value.startswith("//"):
        return True
    try:
        parsed = urlsplit(value)
        return (parsed.scheme.lower() in {"http", "https"} and bool(parsed.hostname)
                and not parsed.username and not parsed.password
                and not any(ord(char) <= 32 or ord(char) == 127 for char in value))
    except ValueError:
        return False


def safe_media_path(value):
    """A DB media reference, never an upload or a remote URL."""
    if not isinstance(value, str):
        return False
    value = value.strip()
    if not value or len(value) > 2048:
        return False
    if value.startswith("//") or "\\" in value or any(ord(char) <= 32 or ord(char) == 127 for char in value):
        return False
    if any(part in (".", "..") for part in value.split("/")):
        return False
    if ":" in value.split("/", 1)[0]:
        return False
    return True


def parse_integer(errors, data, key, label, *, minimum=None, maximum=None, required=False):
    value = raw(data, key)
    if isinstance(value, int) and not isinstance(value, bool):
        parsed = value
    else:
        if value is None or value == "":
            if required:
                errors[key] = [USER_MESSAGES["required"].format(label=label)]
            return None
        if not isinstance(value, str) or not value.strip().isdigit():
            errors[key] = [f"{label} harus berupa angka bulat."]
            return None
        try:
            parsed = int(value.strip())
        except ValueError:
            errors[key] = [f"{label} harus berupa angka bulat."]
            return None
    if minimum is not None and parsed < minimum:
        errors[key] = [f"{label} minimal {minimum}."]
    elif maximum is not None and parsed > maximum:
        errors[key] = [f"{label} maksimal {maximum}."]
    return parsed


def parse_featured_rank(errors, data):
    value = raw(data, "featured_rank")
    if value is None or value == "":
        return None
    return parse_integer(errors, data, "featured_rank", "Urutan pilihan utama", minimum=1, maximum=999)
