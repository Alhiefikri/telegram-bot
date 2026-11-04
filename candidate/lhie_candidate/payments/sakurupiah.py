"""Non-activated Sakurupiah sandbox protocol for an explicitly supplied relay.

The trusted P01/P08 relay restricts requests to the fixed sandbox endpoints,
adds credentials and the create signature, bounds responses and disables redirects.
No provider activation is provided here.
"""

from contextlib import nullcontext
from dataclasses import dataclass, replace
from enum import Enum
import json
import re
import time
from typing import Protocol
from urllib.parse import urlsplit

from django.conf import settings
from django.db import connections

from ..domain.money import MAX_UNITS

from .sakurupiah_coordination import CoordinationUnavailable, SakurupiahCoordination
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
    REJECTED = "rejected"


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
    observed_amount: int | None = None
    merchant_fee: int | None = None
    retry_at: int | None = None
    success_indicated: bool = False


@dataclass(frozen=True, slots=True)
class Channel:
    code: str
    active: bool
    minimum: int
    maximum: int
    checked_at: int | None = None


class ProviderUnavailable(Exception):
    def __init__(self, reason="provider_unavailable", *, rejected=False, dispatched=True, retry_at=None):
        self.reason = reason
        self.rejected = rejected
        self.dispatched = dispatched
        self.retry_at = retry_at
        super().__init__(reason)


def _payer(value):
    if value is None:
        return None
    if type(value) not in (int, str):
        raise ValueError
    result = {"1": 1, "merchant": 1, "2": 2, "buyer": 2,
              "customer": 2, "pelanggan": 2}.get(str(value).strip().lower())
    if result is None:
        raise ValueError
    return result


class SakurupiahSandboxAdapter:
    def __init__(self, *, transport: SandboxTransport, coordination=None):
        self._transport = transport
        self.coordination = coordination

    def enable_coordination(self, *, using='default'):
        if self.coordination is None:
            self.coordination = SakurupiahCoordination(using=using)

    def _post(self, path: str, fields: dict[str, str], *, background=False) -> list[dict]:
        if settings.configured and any(c.in_atomic_block or not c.get_autocommit()
                                       for c in connections.all(initialized_only=True)):
            raise RuntimeError("Provider calls cannot run inside a database transaction")
        if len(json.dumps(fields, ensure_ascii=True).encode("ascii")) > _MAX_BODY:
            raise ProviderUnavailable("request_invalid", dispatched=False)
        try:
            guard = self.coordination.request(background=background) if self.coordination else nullcontext()
            with guard:
                response = self._transport.post(
                    _SANDBOX + path, fields=fields,
                    timeout=_TIMEOUT, max_response_bytes=_MAX_RESPONSE,
                )
                status, raw = response[:2]
                if type(status) is not int or type(raw) is not bytes or len(raw) > _MAX_RESPONSE:
                    raise ValueError
                try:
                    payload = json.loads(raw, object_pairs_hook=_unique)
                except (ValueError, UnicodeError, RecursionError):
                    payload = None
                application = payload.get("status") if type(payload) is dict else None
                if type(application) is int:
                    application = str(application)
                elif type(application) is str and re.fullmatch(r"[0-9]{1,19}", application):
                    application = str(int(application))
                else:
                    application = None
                message = payload.get("message") if type(payload) is dict else None
                limited = (status == 429 or application == "429"
                           or (application == "400" and type(message) is str
                               and re.fullmatch(r"permintaan\s+terlalu\s+cepat\s+silahkan\s+coba\s+lagi\s+nanti\.?",
                                                message.strip(), re.IGNORECASE) is not None))
                if limited and self.coordination:
                    headers = response[2] if len(response) == 3 and type(response[2]) is dict else {}
                    retry = headers.get("Retry-After", "")
                    retry = int(retry) if type(retry) is str and re.fullmatch(r"[0-9]{1,6}", retry) else 0
                    self.coordination.throttle(retry)
                if not 200 <= status < 300:
                    if status not in (408, 425, 429) and status < 500 and application not in (None, "200"):
                        raise ProviderUnavailable("provider_rejected", rejected=True)
                    raise ProviderUnavailable("rate_limited" if limited else "http_unavailable")
                if application is None:
                    raise ValueError
                if application != "200":
                    raise ProviderUnavailable("provider_rejected", rejected=True)
                rows = payload.get("data")
                if type(rows) is not list or any(type(row) is not dict for row in rows):
                    raise ValueError
                return rows
        except CoordinationUnavailable as error:
            raise ProviderUnavailable(error.reason, dispatched=False, retry_at=error.retry_at) from None
        except ProviderUnavailable:
            raise
        except Exception:
            raise ProviderUnavailable("response_unavailable") from None

    def list_channels(self) -> tuple[Channel, ...]:
        try:
            if self.coordination:
                cached = self.coordination.cached_channels()
                if cached is not None:
                    channels = tuple(Channel(*value) for value in cached)
                    self._validate_channels(channels)
                    return channels
            rows = self._post("list-payment.php", {"method": "list"})
            channels = []
            for row in rows:
                code = row.get("kode")
                if code not in _CHANNELS:
                    continue
                if row.get("status") not in ("Aktif", "Tidak Aktif"):
                    raise ValueError
                channels.append(Channel(code, row["status"] == "Aktif",
                                        _amount(row.get("minimal")), _amount(row.get("maksimal")),
                                        int(self.coordination.clock() if self.coordination else time.time())))
            channels = tuple(channels)
            self._validate_channels(channels)
            if self.coordination:
                self.coordination.cache_channels(channels)
            return channels
        except (CoordinationUnavailable, ValueError, TypeError):
            raise ProviderUnavailable("channel_observation_unavailable", dispatched=False) from None

    @staticmethod
    def _validate_channels(channels):
        seen = set()
        for channel in channels:
            if (channel.code not in _CHANNELS or channel.code in seen or type(channel.active) is not bool
                    or type(channel.minimum) is not int or type(channel.maximum) is not int
                    or not 1 <= channel.minimum <= channel.maximum <= MAX_UNITS):
                raise ValueError
            seen.add(channel.code)

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
            if _payer(row.get("merchant_fee")) not in (None, request.merchant_fee):
                raise ValueError
            total = _amount(row.get("total"))
            fee = _amount(row.get("fee"), zero=True)
            if total != identity.amount + (fee if request.merchant_fee == 2 else 0):
                raise ValueError
            expiry, qr, url = row.get("expired"), row.get("qr"), row.get("checkout_url")
            if any(v is not None and not _text(v, _MAX_RESPONSE) for v in (expiry, qr, url)):
                raise ValueError
            return ProviderOutcome(identity, OutcomeState.CREATED, ref, total, fee, expiry, qr, url,
                                   merchant_fee=request.merchant_fee)
        except ProviderUnavailable as error:
            state = OutcomeState.REJECTED if error.rejected or not error.dispatched else OutcomeState.UNKNOWN
            return ProviderOutcome(identity, state, reason=error.reason, retry_at=error.retry_at)
        except (ValueError, TypeError):
            return ProviderOutcome(identity, OutcomeState.UNKNOWN, reason="create_outcome_unknown")

    def status(self, identity: InvoiceIdentity, provider_ref: str, *, provider_total: int | None = None,
               merchant_fee: int | None = None, background=False) -> ProviderOutcome:
        self._lookup_identity(identity, provider_total, merchant_fee)
        if not _text(provider_ref):
            raise ValueError("Invalid lookup identity")
        success_indicated = False
        try:
            status = self._post("status-transaction.php", {"method": "status", "trx_id": provider_ref},
                                background=background)
            if len(status) != 1:
                raise ValueError
            row = status[0]
            if (("trx_id" in row and row["trx_id"] != provider_ref)
                    or ("merchant_ref" in row and row["merchant_ref"] != identity.merchant_ref)
                    or ("payment_kode" in row and row["payment_kode"] != identity.channel)
                    or row.get("currency") not in (None, "IDR")):
                raise ValueError
            observed = row.get("payment_status", row.get("status"))
            self._merge_observation(ProviderOutcome(identity, OutcomeState.UNKNOWN, provider_ref),
                                    row, provider_total, merchant_fee)
            success_indicated = observed == "berhasil"
            history = self._history(identity, provider_total, merchant_fee, background=background)
            if history.provider_ref != provider_ref or history.reason != observed:
                raise ValueError
            return self._merge_observation(history, row, provider_total, merchant_fee)
        except (ProviderUnavailable, ValueError, TypeError):
            return ProviderOutcome(identity, OutcomeState.UNKNOWN, provider_ref, reason="lookup_unknown",
                                   success_indicated=success_indicated)

    def recover(self, identity: InvoiceIdentity, *, provider_total: int | None = None,
                merchant_fee: int | None = None, background=False) -> ProviderOutcome:
        self._lookup_identity(identity, provider_total, merchant_fee)
        try:
            return self._history(identity, provider_total, merchant_fee, background=background)
        except (ProviderUnavailable, ValueError, TypeError):
            return ProviderOutcome(identity, OutcomeState.UNKNOWN, reason="recovery_unknown")

    @staticmethod
    def _lookup_identity(identity, total, merchant_fee):
        if (type(identity) is not InvoiceIdentity
                or (total is not None and (type(total) is not int or not 1 <= total <= MAX_UNITS))
                or (merchant_fee is not None and (type(merchant_fee) is not int or merchant_fee not in (1, 2)))):
            raise ValueError("Invalid lookup identity")

    def _history(self, identity, provider_total, merchant_fee=None, *, background=False):
        rows = self._post("transaction.php", {"method": "transaction", "mechant": "1",
                                             "merchant_ref": identity.merchant_ref}, background=background)
        if len(rows) != 1:
            raise ValueError
        row = rows[0]
        ref, state = row.get("trx_id"), row.get("status")
        states = {"pending": OutcomeState.PENDING, "berhasil": OutcomeState.SUCCESS,
                  "expired": OutcomeState.EXPIRED}
        if (not _text(ref) or row.get("merchant_ref") != identity.merchant_ref
                or row.get("payment_kode") != identity.channel or state not in states
                or row.get("currency") not in (None, "IDR")):
            raise ValueError
        outcome = ProviderOutcome(identity, states[state], ref, reason=state)
        return self._merge_observation(outcome, row, provider_total, merchant_fee, require_amount=True)

    @staticmethod
    def _merge_observation(outcome, row, provider_total, merchant_fee, *, require_amount=False):
        total = _amount(row["total"]) if row.get("total") is not None else outcome.total
        fee = _amount(row["fee"], zero=True) if row.get("fee") is not None else outcome.fee
        payer = _payer(row.get("merchant_fee"))
        if payer is not None and merchant_fee is not None and payer != merchant_fee:
            raise ValueError
        payer = merchant_fee if merchant_fee is not None else payer
        if outcome.total is not None and total != outcome.total:
            raise ValueError
        if outcome.fee is not None and fee != outcome.fee:
            raise ValueError
        if total is not None:
            if provider_total is not None:
                if total != provider_total:
                    raise ValueError
            elif total != outcome.identity.amount:
                if fee is None or payer != 2 or total != outcome.identity.amount + fee:
                    raise ValueError
            if fee is not None and payer is not None:
                if total != outcome.identity.amount + (fee if payer == 2 else 0):
                    raise ValueError
        amount = _amount(row["amount"]) if row.get("amount") is not None else outcome.observed_amount
        if require_amount and amount is None:
            raise ValueError
        allowed = {outcome.identity.amount, provider_total}
        if total is not None:
            allowed.add(total)
        if amount is not None and amount not in allowed:
            raise ValueError
        values = {}
        for field, key in (("expiry", "expired"), ("qr", "qr"), ("checkout_url", "checkout_url")):
            value = row.get(key)
            previous = getattr(outcome, field)
            if value is not None:
                if not _text(value, _MAX_RESPONSE) or (previous is not None and previous != value):
                    raise ValueError
                values[field] = value
        return replace(outcome, total=total, fee=fee, observed_amount=amount,
                       merchant_fee=payer, **values)


