"""Exact bootstrap values, not a ledger or a currency conversion service."""

from dataclasses import dataclass
from enum import Enum
import re


MAX_UNITS = (1 << 63) - 1
_DECIMAL = re.compile(r"(0|[1-9][0-9]*)(?:\.([0-9]+))?", re.ASCII)


class Currency(Enum):
    IDR = "IDR"
    USDT = "USDT"


def require_integer(value: object, *, minimum: int = 0) -> int:
    if type(value) is not int or not minimum <= value <= MAX_UNITS:
        raise ValueError("Expected a bounded integer")
    return value


@dataclass(frozen=True, slots=True)
class Money:
    """Nonnegative whole rupiah or micro-USDT, bounded by PostgreSQL bigint."""

    currency: Currency
    units: int

    def __post_init__(self) -> None:
        if type(self.currency) is not Currency:
            raise ValueError("Unsupported currency")
        require_integer(self.units)

    @classmethod
    def from_decimal(cls, currency: Currency, value: str) -> "Money":
        if type(currency) is not Currency:
            raise ValueError("Unsupported currency")
        if type(value) is not str or not 1 <= len(value) <= 32:
            raise ValueError("Expected a bounded decimal string")
        match = _DECIMAL.fullmatch(value)
        if match is None:
            raise ValueError("Expected a canonical unsigned decimal string")
        whole, fraction = match.group(1), match.group(2)
        if currency is Currency.IDR:
            if fraction is not None:
                raise ValueError("IDR requires whole rupiah")
            return cls(currency, int(whole))
        fraction = fraction or ""
        if any(digit != "0" for digit in fraction[6:]):
            raise ValueError("USDT cannot represent sub-micro units")
        units = int(whole) * 1_000_000 + int(fraction[:6].ljust(6, "0"))
        return cls(currency, units)

    def format_decimal(self) -> str:
        if self.currency is Currency.IDR:
            return str(self.units)
        whole, fraction = divmod(self.units, 1_000_000)
        return f"{whole}.{fraction:06d}".rstrip("0").rstrip(".")

    def multiply(self, quantity: int) -> "Money":
        require_integer(quantity, minimum=1)
        if self.units > MAX_UNITS // quantity:
            raise ValueError("Money exceeds bigint representation")
        return Money(self.currency, self.units * quantity)


def self_check() -> None:
    """Fail startup if the exact-money boundary no longer enforces its contract."""
    if (
        Money.from_decimal(Currency.USDT, "0.000001").units != 1
        or Money.from_decimal(Currency.USDT, "9223372036854.775807").units != MAX_UNITS
        or Money(Currency.IDR, MAX_UNITS).format_decimal() != str(MAX_UNITS)
    ):
        raise RuntimeError("Exact-money startup invariant failed")
    for invalid in (True, 1.0, "1", -1, MAX_UNITS + 1):
        try:
            Money(Currency.IDR, invalid)
        except ValueError:
            continue
        raise RuntimeError("Exact-money integer guard failed")
    for invalid in ("0.0000001", "9223372036854.775808", "1e2"):
        try:
            Money.from_decimal(Currency.USDT, invalid)
        except ValueError:
            continue
        raise RuntimeError("Exact-money precision guard failed")
