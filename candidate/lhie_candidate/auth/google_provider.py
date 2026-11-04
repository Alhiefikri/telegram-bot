"""Authorization-code evidence from fixed Google endpoints; disabled unless admitted."""
from dataclasses import dataclass
import http.client
import json
import re
import socket
import time
from urllib.parse import urlencode, urlsplit

from ..compat.identity import application_hmac, normalize_credential_identity, normalize_customer_email
from .types import AuthError

_SECRET = re.compile(r"[\x21-\x7e]{1,4096}", re.ASCII)
MAX_RESPONSE_BYTES = 16_384


@dataclass(frozen=True, repr=False)
class GoogleIdentity:
    subject: str
    email: str

    def __post_init__(self):
        if not isinstance(self.subject, str) or re.fullmatch(r"[\x21-\x7e]{1,255}", self.subject) is None:
            raise AuthError()
        try:
            email = normalize_customer_email(self.email)
            normalize_credential_identity("email", email)
            if len(email.encode()) > 255:
                raise ValueError()
        except (ValueError, TypeError, AttributeError):
            raise AuthError() from None
        object.__setattr__(self, "email", email)


def _object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError()
        result[key] = value
    return result


def _depth(value, depth=0):
    if depth > 16:
        raise ValueError()
    if isinstance(value, dict):
        for child in value.values():
            _depth(child, depth + 1)
    elif isinstance(value, list):
        for child in value:
            _depth(child, depth + 1)


class GoogleOAuthProvider:
    def __init__(self, *, app_key, client_id="", client_secret="", base_url="", enabled=False):
        self.app_key = app_key
        self.client_id = client_id
        self.client_secret = client_secret
        self.base_url = base_url
        self.enabled = enabled is True

    def callback_url(self):
        try:
            if not isinstance(self.base_url, str) or any(ord(c) < 33 or ord(c) > 126 for c in self.base_url):
                return None
            parts = urlsplit(self.base_url)
            if (parts.scheme != "https" or not parts.hostname or parts.username is not None
                    or parts.password is not None or parts.query or parts.fragment
                    or parts.path not in ("", "/") or "?" in self.base_url or "#" in self.base_url):
                return None
            parts.port
            return self.base_url.rstrip("/") + "/auth/google/callback"
        except ValueError:
            return None

    def configuration_fingerprint(self):
        callback = self.callback_url()
        if (not self.enabled or not callback or not isinstance(self.app_key, str) or not self.app_key
                or not isinstance(self.client_id, str) or not self.client_id.strip()
                or not isinstance(self.client_secret, str) or not self.client_secret.strip()):
            return None
        value = (str(len(self.client_id.encode())) + ":" + self.client_id
                 + str(len(self.client_secret.encode())) + ":" + self.client_secret + callback)
        return application_hmac(value, self.app_key)

    def ready(self):
        return self.configuration_fingerprint() is not None

    def authorization_url(self, state):
        if not self.ready():
            raise AuthError("unavailable", 503)
        return "https://accounts.google.com/o/oauth2/v2/auth?" + urlencode({
            "client_id": self.client_id, "redirect_uri": self.callback_url(),
            "response_type": "code", "scope": "openid email", "state": state,
            "access_type": "online", "prompt": "select_account",
        })

    def _request(self, host, path, *, body=None, bearer=None):
        # No redirects, proxies, configurable hosts, provider retries, or unbounded reads.
        client = http.client.HTTPSConnection(host, timeout=3)
        deadline = time.monotonic() + 8
        try:
            client.connect()
            sock = client.sock
            sock.settimeout(max(0.001, deadline - time.monotonic()))
            headers = {"Accept": "application/json", "Accept-Encoding": "identity"}
            if body is not None:
                headers["Content-Type"] = "application/x-www-form-urlencoded"
            if bearer is not None:
                headers["Authorization"] = "Bearer " + bearer
            client.request("POST" if body is not None else "GET", path, body, headers)
            response = client.getresponse()
            length = response.getheader("Content-Length")
            if (response.status != 200 or response.getheader("Content-Encoding", "identity") != "identity"
                    or (length is not None and (not length.isascii() or not length.isdecimal()
                                              or len(length) > 8 or int(length) > MAX_RESPONSE_BYTES))):
                raise AuthError("unavailable", 503)
            raw = bytearray()
            while len(raw) <= MAX_RESPONSE_BYTES:
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise TimeoutError()
                sock.settimeout(remaining)
                chunk = response.read1(min(4096, MAX_RESPONSE_BYTES + 1 - len(raw)))
                if not chunk:
                    break
                raw.extend(chunk)
            if len(raw) > MAX_RESPONSE_BYTES:
                raise AuthError("unavailable", 503)
            try:
                payload = json.loads(raw, object_pairs_hook=_object,
                                     parse_constant=lambda value: (_ for _ in ()).throw(ValueError()))
                if not isinstance(payload, dict):
                    raise ValueError()
                _depth(payload)
            except (ValueError, UnicodeError, RecursionError):
                raise AuthError() from None
            return payload
        except (OSError, socket.timeout, http.client.HTTPException):
            raise AuthError("unavailable", 503) from None
        finally:
            client.close()

    def user(self, code):
        if not self.ready():
            raise AuthError("unavailable", 503)
        if not isinstance(code, str) or _SECRET.fullmatch(code) is None:
            raise AuthError()
        tokens = self._request("oauth2.googleapis.com", "/token", body=urlencode({
            "client_id": self.client_id, "client_secret": self.client_secret,
            "redirect_uri": self.callback_url(), "grant_type": "authorization_code", "code": code,
        }).encode("ascii"))
        token = tokens.get("access_token")
        if (not isinstance(token, str) or _SECRET.fullmatch(token) is None
                or not isinstance(tokens.get("token_type"), str) or tokens["token_type"].lower() != "bearer"):
            raise AuthError()
        profile = self._request("www.googleapis.com", "/oauth2/v3/userinfo", bearer=token)
        if profile.get("email_verified") is not True:
            raise AuthError()
        return GoogleIdentity(profile.get("sub"), profile.get("email"))
