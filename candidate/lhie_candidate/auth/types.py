"""Transport-neutral authentication outcomes; no provider or financial authority."""
from dataclasses import dataclass
from datetime import datetime
from zoneinfo import ZoneInfo

LEGACY_ZONE = ZoneInfo("Asia/Jakarta")


class AuthError(Exception):
    def __init__(self, code="invalid", status=403):
        self.code, self.status = code, status
        super().__init__(code)


@dataclass(frozen=True, repr=False)
class Browser:
    session_id: str
    ip: str
    customer_id: int | None = None
    auth_version: int | None = None
    authenticated_at: int | None = None
    method: str | None = None


@dataclass(frozen=True, repr=False)
class Principal:
    customer_id: int
    auth_version: int
    authenticated_at: int
    method: str


def legacy_now():
    return datetime.now(LEGACY_ZONE).replace(tzinfo=None, microsecond=0)


def principal(customer, method, at=None):
    at = at or legacy_now()
    return Principal(int(customer["id"]), int(customer["auth_version"]),
                     int(at.replace(tzinfo=LEGACY_ZONE).timestamp()), method)


def require_fresh(browser, customer, at=None):
    at = at or legacy_now()
    timestamp = int(at.replace(tzinfo=LEGACY_ZONE).timestamp())
    if (browser.customer_id != customer["id"] or browser.auth_version != customer["auth_version"]
            or type(browser.authenticated_at) is not int
            or not timestamp - 300 < browser.authenticated_at <= timestamp):
        raise AuthError("fresh_authentication_required")
