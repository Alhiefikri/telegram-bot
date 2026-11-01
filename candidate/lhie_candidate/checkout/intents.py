from dataclasses import dataclass
from datetime import datetime, timedelta
import hashlib
import hmac
from typing import Protocol

from .errors import CheckoutError
from .pricing import PricingSnapshot
from lhie_candidate.domain.money import require_integer


@dataclass(frozen=True, slots=True)
class PaymentSetting:
    id: int
    version: int
    active: bool


@dataclass(frozen=True, slots=True)
class PaymentPreflight:
    provider: str
    method: str
    setting: PaymentSetting | None
    observation: str | None = None


@dataclass(frozen=True, slots=True)
class PaymentPlan:
    provider: str
    method: str
    amount: int
    expires_at: datetime
    balance_paid: bool = False


class PaymentPreparation(Protocol):
    """Trusted rail implementation: preflight may use I/O; prepare must be DB-local."""

    provider: str
    method: str

    def preflight(self, setting: PaymentSetting | None) -> PaymentPreflight: ...

    def prepare(self, pricing: PricingSnapshot, setting: PaymentSetting | None,
                preflight: PaymentPreflight, now: datetime) -> PaymentPlan: ...


class StoreBalancePreparation:
    provider = 'store_balance'
    method = 'STORE_BALANCE'

    def preflight(self, setting):
        return PaymentPreflight(self.provider, self.method, setting)

    def prepare(self, pricing, setting, preflight, now):
        require_integer(pricing.idr_total, minimum=1)
        if (preflight.provider, preflight.method, preflight.setting) != (self.provider, self.method, setting):
            raise CheckoutError('stale_preflight')
        return PaymentPlan(self.provider, self.method, pricing.idr_total,
                           now + timedelta(minutes=15), balance_paid=True)


def telegram_checkout_key(key: str, customer_id: int, telegram_id: int, chat_id: str,
                          message_id: int, data: str) -> str:
    """PHP-compatible encoded application-key HMAC; update ID is deliberately absent."""
    require_integer(customer_id, minimum=1)
    require_integer(telegram_id, minimum=1)
    require_integer(message_id, minimum=1)
    if data in ('checkout:current', 'checkout:balance', 'checkout:binance'):
        data = 'checkout:choice'
    payload = '|'.join(map(str, ('telegram-direct-checkout', customer_id, telegram_id,
                                chat_id, message_id, data)))
    return hmac.new(key.encode(), payload.encode(), hashlib.sha256).hexdigest()


def telegram_detail_checkout_key(key: str, customer_id: int, telegram_id: int, chat_id: str,
                                 message_id: int | None, product_id: int, token: str) -> str:
    require_integer(customer_id, minimum=1)
    require_integer(telegram_id, minimum=1)
    require_integer(product_id, minimum=1)
    if message_id is not None:
        require_integer(message_id, minimum=1)
    payload = '|'.join(map(str, ('telegram-detail-checkout', customer_id, telegram_id, chat_id,
                                message_id if message_id is not None else 'no-message', product_id, token)))
    return hmac.new(key.encode(), payload.encode(), hashlib.sha256).hexdigest()
