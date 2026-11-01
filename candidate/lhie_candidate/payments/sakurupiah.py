"""Non-activated Sakurupiah sandbox protocol for an explicitly supplied relay.

The trusted P01/P08 relay restricts requests to the fixed sandbox endpoints,
adds credentials and the create signature, bounds responses and disables redirects.
No provider activation is provided here.
"""

from dataclasses import dataclass
from enum import Enum
import json
import re
from typing import Protocol
from urllib.parse import urlsplit

from django.conf import settings
from django.db import connections

from ..domain.money import MAX_UNITS

_SANDBOX = "https://sakurupiah.id/api-sanbox/"
_MAX_BODY = 65_536
_MAX_RESPONSE = 1_048_576
_TIMEOUT = 5.0
_CHANNELS = frozenset({"QRIS", "QRISC", "QRISMU", "QRIS2"})


class SandboxTransport(Protocol):
    def post(self, url: str, *, fields: dict[str, str],
             timeout: float, max_response_bytes: int) -> tuple[int, bytes]:
        """Authenticate and send once, without redirects, only to this sandbox URL."""
        ...


class CallbackAuthenticator(Protocol):
    def authenticate(self, raw_body: bytes, signature: str | None) -> bool:
        """Verify original bytes within the credential-holding boundary."""
        ...


def _text(value: object, maximum: int = 255) -> bool:
    if type(value) is not str or not value or value.strip() != value:
        return False
    try:
        return len(value.encode("utf-8")) <= maximum
    except UnicodeEncodeError:
        return False


def _amount(value: object, *, zero: bool = False) -> int:
    if type(value) is int:
        result = value
    elif type(value) is str and re.fullmatch(r"[0-9]{1,19}", value, re.ASCII):
        result = int(value)
    else:
        raise ValueError
    if not (0 if zero else 1) <= result <= MAX_UNITS:
        raise ValueError
    return result


def _unique(pairs: list[tuple[str, object]]) -> dict:
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError
        result[key] = value
    return result


@dataclass(frozen=True, slots=True)
class InvoiceIdentity:
    merchant_ref: str
    amount: int
    channel: str

    def __post_init__(self) -> None:
        if not _text(self.merchant_ref) or type(self.amount) is not int:
            raise ValueError("Invalid invoice identity")
        if not 1 <= self.amount <= MAX_UNITS or self.channel not in _CHANNELS:
            raise ValueError("Invalid invoice identity")


@dataclass(frozen=True, slots=True, repr=False)
class CreateRequest:
    identity: InvoiceIdentity
    phone: str
    callback_url: str
    return_url: str
    merchant_fee: int = 2

    def __post_init__(self) -> None:
        if type(self.identity) is not InvoiceIdentity or not _text(self.phone, 32):
            raise ValueError("Invalid create request")
        if type(self.merchant_fee) is not int or self.merchant_fee not in (1, 2):
            raise ValueError("Invalid create request")
        for value in (self.callback_url, self.return_url):
            if not _text(value, 2048):
                raise ValueError("Invalid create request")
            try:
                url = urlsplit(value)
                if (url.scheme != "https" or not url.hostname or url.username is not None
                        or url.password is not None or url.fragment or url.port not in (None, 443)):
                    raise ValueError
            except ValueError:
                raise ValueError("Invalid create request") from None


class OutcomeState(Enum):
    CREATED = "created"
    PENDING = "pending"
    SUCCESS = "success"
    EXPIRED = "expired"
    UNKNOWN = "unknown"


@dataclass(frozen=True, slots=True, repr=False)
class ProviderOutcome:
    identity: InvoiceIdentity
    state: OutcomeState
    provider_ref: str | None = None
    total: int | None = None
    fee: int | None = None
    expiry: str | None = None
    qr: str | None = None
    checkout_url: str | None = None
    # These are provider observations, not validated payable presentation.
    reason: str | None = None


@dataclass(frozen=True, slots=True)
class Channel:
    code: str
    active: bool
    minimum: int
    maximum: int


class ProviderUnavailable(Exception):
    pass


class SakurupiahSandboxAdapter:
    def __init__(self, *, transport: SandboxTransport):
        self._transport = transport

    def _post(self, path: str, fields: dict[str, str]) -> list[dict]:
        if settings.configured and any(c.in_atomic_block or not c.get_autocommit()
                                       for c in connections.all(initialized_only=True)):
            raise RuntimeError("Provider calls cannot run inside a database transaction")
        if len(json.dumps(fields, ensure_ascii=True).encode("ascii")) > _MAX_BODY:
            raise ProviderUnavailable("Provider request exceeds limit")
        try:
            status, raw = self._transport.post(
                _SANDBOX + path, fields=fields,
                timeout=_TIMEOUT, max_response_bytes=_MAX_RESPONSE,
            )
            if type(status) is not int or not 200 <= status < 300:
                raise ValueError
            if type(raw) is not bytes or len(raw) > _MAX_RESPONSE:
                raise ValueError
            payload = json.loads(raw, object_pairs_hook=_unique)
            if type(payload) is not dict or payload.get("status") not in ("200", 200):
                raise ValueError
            rows = payload.get("data")
            if type(rows) is not list or any(type(row) is not dict for row in rows):
                raise ValueError
            return rows
        except Exception:
            raise ProviderUnavailable("Provider response unavailable or invalid") from None

    def list_channels(self) -> tuple[Channel, ...]:
        try:
            rows = self._post("list-payment.php", {"method": "list"})
            channels = []
            seen = set()
            for row in rows:
                code = row.get("kode")
                if code not in _CHANNELS:
                    continue
                if code in seen or row.get("status") not in ("Aktif", "Tidak Aktif"):
                    raise ValueError
                minimum, maximum = _amount(row.get("minimal")), _amount(row.get("maksimal"))
                if minimum > maximum:
                    raise ValueError
                seen.add(code)
                channels.append(Channel(code, row["status"] == "Aktif", minimum, maximum))
            return tuple(channels)
        except (ValueError, TypeError):
            raise ProviderUnavailable("Invalid channel observations") from None

    def create(self, request: CreateRequest) -> ProviderOutcome:
        identity = request.identity
        fields = {"method": identity.channel, "phone": request.phone,
                  "amount": str(identity.amount), "merchant_fee": str(request.merchant_fee),
                  "merchant_ref": identity.merchant_ref, "callback_url": request.callback_url,
                  "return_url": request.return_url}
        try:
            rows = self._post("create.php", fields)
            if len(rows) != 1:
                raise ValueError
            row = rows[0]
            ref = row.get("trx_id")
            if (not _text(ref) or row.get("merchant_ref") != identity.merchant_ref
                    or row.get("payment_kode") != identity.channel
                    or row.get("payment_status", row.get("status")) != "pending"):
                raise ValueError
            if "amount" in row and _amount(row["amount"]) != identity.amount:
                raise ValueError
            payer = row.get("merchant_fee")
            if payer is not None:
                if type(payer) not in (int, str):
                    raise ValueError
                payer = str(payer).strip().lower()
                normalized = {"1": 1, "merchant": 1, "2": 2, "buyer": 2,
                              "customer": 2, "pelanggan": 2}.get(payer)
                if normalized != request.merchant_fee:
                    raise ValueError
            total = _amount(row.get("total"))
            fee = _amount(row.get("fee"), zero=True)
            if total < identity.amount:
                raise ValueError
            expiry, qr, url = row.get("expired"), row.get("qr"), row.get("checkout_url")
            if any(v is not None and not _text(v, _MAX_RESPONSE) for v in (expiry, qr, url)):
                raise ValueError
            return ProviderOutcome(identity, OutcomeState.CREATED, ref, total, fee, expiry, qr, url)
        except (ProviderUnavailable, ValueError, TypeError):
            return ProviderOutcome(identity, OutcomeState.UNKNOWN, reason="create_outcome_unknown")

    def status(self, identity: InvoiceIdentity, provider_ref: str, *, provider_total: int) -> ProviderOutcome:
        if (type(identity) is not InvoiceIdentity or not _text(provider_ref)
                or type(provider_total) is not int or not 1 <= provider_total <= MAX_UNITS):
            raise ValueError("Invalid lookup identity")
        try:
            status = self._post("status-transaction.php", {"method": "status", "trx_id": provider_ref})
            if len(status) != 1:
                raise ValueError
            row = status[0]
            if "trx_id" in row and row["trx_id"] != provider_ref:
                raise ValueError
            if (("merchant_ref" in row and row["merchant_ref"] != identity.merchant_ref)
                    or ("payment_kode" in row and row["payment_kode"] != identity.channel)
                    or ("currency" in row and row["currency"] not in (None, "IDR"))
                    or ("amount" in row and _amount(row["amount"]) not in (identity.amount, provider_total))
                    or ("total" in row and _amount(row["total"]) != provider_total)):
                raise ValueError
            observed = row.get("payment_status", row.get("status"))
            history = self._history(identity, provider_total)
            if history.provider_ref != provider_ref or history.reason != observed:
                raise ValueError
            return history
        except (ProviderUnavailable, ValueError, TypeError):
            return ProviderOutcome(identity, OutcomeState.UNKNOWN, provider_ref, reason="lookup_unknown")

    def recover(self, identity: InvoiceIdentity, *, provider_total: int) -> ProviderOutcome:
        if (type(identity) is not InvoiceIdentity or type(provider_total) is not int
                or not 1 <= provider_total <= MAX_UNITS):
            raise ValueError("Invalid lookup identity")
        try:
            return self._history(identity, provider_total)
        except (ProviderUnavailable, ValueError, TypeError):
            return ProviderOutcome(identity, OutcomeState.UNKNOWN, reason="recovery_unknown")

    def _history(self, identity: InvoiceIdentity, provider_total: int) -> ProviderOutcome:
        rows = self._post("transaction.php", {"method": "transaction", "mechant": "1",
                                             "merchant_ref": identity.merchant_ref})
        if len(rows) != 1:
            raise ValueError
        row = rows[0]
        ref, state = row.get("trx_id"), row.get("status")
        states = {"pending": OutcomeState.PENDING, "berhasil": OutcomeState.SUCCESS,
                  "expired": OutcomeState.EXPIRED}
        if (not _text(ref) or row.get("merchant_ref") != identity.merchant_ref
                or row.get("payment_kode") != identity.channel
                or _amount(row.get("amount")) not in (identity.amount, provider_total) or state not in states):
            raise ValueError
        if row.get("currency") not in (None, "IDR"):
            raise ValueError
        return ProviderOutcome(identity, states[state], ref, reason=state)


