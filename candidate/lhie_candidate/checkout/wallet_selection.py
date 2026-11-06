"""Consent and full-payment selection for the existing IDR and USDT ledgers."""
from dataclasses import dataclass

from lhie_candidate.domain.money import require_integer
from .errors import CheckoutError


_CURRENCIES = {
    'idr_only': ('IDR',),
    'usd_only': ('USDT',),
    'idr_first': ('IDR', 'USDT'),
    'usd_first': ('USDT', 'IDR'),
}


@dataclass(frozen=True, slots=True)
class BalanceCheckoutRequest:
    preference: str | None = None
    expected_idr_total: int | None = None
    expected_usdt_total: int | None = None
    preference_source: str | None = None

    def __post_init__(self):
        if self.preference is not None and self.preference not in _CURRENCIES:
            raise ValueError('Invalid balance payment preference')
        if self.preference_source not in (None, 'account', 'override'):
            raise ValueError('Invalid preference source')
        if self.preference_source is not None and self.preference is None:
            raise ValueError('Preference source requires a resolved preference')
        for amount in (self.expected_idr_total, self.expected_usdt_total):
            if amount is not None:
                require_integer(amount, minimum=1)

    def currencies(self, account_preference):
        preference = self.preference if self.preference is not None else account_preference
        if preference not in _CURRENCIES:
            raise CheckoutError('balance_preference_invalid')
        return preference, _CURRENCIES[preference]

    def select(self, currencies, pricing, idr_account, usdt_account):
        totals = {'IDR': pricing.idr_total, 'USDT': pricing.usdt_total}
        expected = {'IDR': self.expected_idr_total, 'USDT': self.expected_usdt_total}
        for index, currency in enumerate(currencies):
            if expected[currency] is not None and expected[currency] != totals[currency]:
                raise CheckoutError('price_changed')
            if index == 0 and totals[currency] is not None and expected[currency] is None:
                raise CheckoutError('balance_price_consent_required')
        accounts = {'IDR': idr_account, 'USDT': usdt_account}
        for currency in currencies:
            total = totals[currency]
            if total is None or expected[currency] is None:
                continue
            require_integer(total, minimum=1)
            account = accounts[currency]
            if account is None:
                raise CheckoutError('balance_account_unavailable')
            balance = account['balance'] if currency == 'IDR' else account['balance_micro']
            if require_integer(balance) >= total:
                return currency
        raise CheckoutError('insufficient_balance')
