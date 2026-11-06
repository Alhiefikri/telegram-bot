"""Explicit watch-only custody observations; no provider is installed by default."""
from dataclasses import dataclass
from datetime import datetime
import re

from ..domain.money import MAX_UNITS


@dataclass(frozen=True, slots=True)
class CustodyObservation:
    provider: str
    state: str
    assets_micro: int | None = None
    observed_at: datetime | None = None
    reason: str | None = None

    def __post_init__(self):
        if (not isinstance(self.provider, str) or re.fullmatch(r'[a-z0-9][a-z0-9_-]{1,31}', self.provider) is None
                or self.state not in ('available', 'unavailable', 'unsupported')):
            raise ValueError('Invalid custody observation')
        if self.state == 'available':
            if (type(self.assets_micro) is not int or not 0 <= self.assets_micro <= MAX_UNITS
                    or not isinstance(self.observed_at, datetime) or self.reason is not None):
                raise ValueError('Invalid available custody evidence')
        elif (self.assets_micro is not None or self.observed_at is not None or not isinstance(self.reason, str)
              or re.fullmatch(r'[a-z0-9][a-z0-9_:.-]{0,63}', self.reason) is None):
            raise ValueError('Invalid unavailable custody evidence')


class CustodyRegistry:
    def __init__(self, providers=()):
        self.providers = tuple(providers)
        keys = []
        for provider in self.providers:
            key = provider.provider_key()
            if not isinstance(key, str) or re.fullmatch(r'[a-z0-9][a-z0-9_-]{1,31}', key) is None or key in keys:
                raise ValueError('Invalid or duplicated custody provider')
            keys.append(key)
        self.keys = tuple(keys)

    def observe_all(self):
        observations = []
        for key, provider in zip(self.keys, self.providers):
            try:
                result = provider.observe()
            except Exception:
                result = CustodyObservation(key, 'unavailable', reason='adapter_error')
            if not isinstance(result, CustodyObservation) or result.provider != key:
                raise ValueError('Custody provider identity mismatch')
            observations.append(result)
        return tuple(observations)
