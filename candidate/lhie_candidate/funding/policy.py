"""DB-backed Store Balance funding policy and PHP-compatible preset identities.

The policy row is presentation/authorization input only. It never moves money
and never rewrites an existing funding intent; the intent snapshots
``policy_version`` and the amount so a later Admin change cannot retarget it.
"""

from dataclasses import dataclass
import base64
import hashlib
import hmac
import json
import re

from lhie_candidate.domain.money import MAX_UNITS, require_integer


SINGLETON_KEY = 'store_balance_funding_policy'
MAX_PRESETS = 12
_PRESET = re.compile(r'balance:p:(0|[1-9][0-9]*):([A-Za-z0-9_-]{11})\Z')
_AMOUNT = re.compile(r'[1-9][0-9]*\Z')


class PolicyError(ValueError):
    pass


@dataclass(frozen=True, slots=True)
class FundingPolicy:
    version: int
    minimum_amount: int
    preset_amounts: tuple[int, ...]

    def __post_init__(self):
        require_integer(self.version)
        require_integer(self.minimum_amount, minimum=1)
        if (not isinstance(self.preset_amounts, tuple) or not 1 <= len(self.preset_amounts) <= MAX_PRESETS
                or any(type(amount) is not int or amount < self.minimum_amount
                       or amount > MAX_UNITS for amount in self.preset_amounts)
                or list(self.preset_amounts) != sorted(set(self.preset_amounts))):
            raise PolicyError('invalid policy presets')

    def selection(self, index, version, amount):
        """Resolve an exact stored preset; a stale version/index fails closed."""
        require_integer(index)
        if (type(version) is not int or version != self.version
                or not 0 <= index < len(self.preset_amounts)
                or type(amount) is not int or self.preset_amounts[index] != amount):
            return None
        return PresetSelection(self.version, index, amount)


@dataclass(frozen=True, slots=True)
class PresetSelection:
    policy_version: int
    index: int
    amount: int


def parse_amount(value):
    if type(value) is int:
        raw = str(value)
    elif type(value) is str and len(value) <= 128:
        raw = value.strip()
    else:
        return None
    if _AMOUNT.fullmatch(raw) is None:
        return None
    amount = int(raw)
    return amount if amount <= MAX_UNITS else None


def policy_from_row(row):
    if row is None:
        return None
    try:
        presets = row['preset_amounts']
        if isinstance(presets, str):
            presets = json.loads(presets)
        if not isinstance(presets, list):
            raise PolicyError('invalid policy presets')
        policy = FundingPolicy(
            version=require_integer(row['version']),
            minimum_amount=require_integer(row['minimum_amount'], minimum=1),
            preset_amounts=tuple(require_integer(amount, minimum=1) for amount in presets),
        )
    except (KeyError, TypeError, ValueError, json.JSONDecodeError) as error:
        raise PolicyError('invalid funding policy') from error
    if not policy.preset_amounts or policy.preset_amounts[0] < policy.minimum_amount:
        raise PolicyError('invalid funding policy')
    return policy


def callback_identity(key, policy_version, index, amount):
    """PHP ``StoreBalanceFundingPolicyManager::callbackIdentity`` parity.

    PHP hashes ``version|index|amount`` with ``config('app.key')`` literally and
    keeps the first eight digest bytes as unpadded base64url (11 characters).
    """
    if type(key) not in (str, bytes) or not key:
        raise PolicyError('callback key required')
    if (type(policy_version) is not int or policy_version < 0
            or type(index) is not int or index < 0
            or type(amount) is not int or amount < 1):
        raise PolicyError('invalid preset identity')
    raw = key.encode() if isinstance(key, str) else key
    digest = hmac.new(raw, f'{policy_version}|{index}|{amount}'.encode('ascii'), hashlib.sha256).digest()
    return base64.urlsafe_b64encode(digest[:8]).decode('ascii').rstrip('=')


def preset_callback(key, policy, index):
    if type(index) is not int or not 0 <= index < len(policy.preset_amounts):
        raise PolicyError('invalid preset index')
    return f'balance:p:{policy.version}:{callback_identity(key, policy.version, index, policy.preset_amounts[index])}'


def parse_preset_callback(key, policy, data):
    """Return the current preset or ``None`` for stale/tampered identities."""
    if policy is None or type(data) is not str:
        return None
    match = _PRESET.fullmatch(data)
    if match is None:
        return None
    try:
        version = int(match.group(1))
    except ValueError:
        return None
    if version != policy.version:
        return None
    for index, amount in enumerate(policy.preset_amounts):
        expected = callback_identity(key, policy.version, index, amount)
        if hmac.compare_digest(match.group(2), expected):
            return policy.selection(index, version, amount)
    return None


def is_preset_callback(data):
    return type(data) is str and _PRESET.fullmatch(data) is not None
