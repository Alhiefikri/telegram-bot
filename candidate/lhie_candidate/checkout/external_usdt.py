"""External checkout targets: observe readiness before locks, persist public snapshots under them."""
from dataclasses import dataclass
from datetime import datetime, timedelta
import re
from zoneinfo import ZoneInfo

from django.db import connections

from lhie_candidate.domain.money import require_integer
from .db import Database
from .errors import CheckoutError
from .intents import PaymentPlan, PaymentPreflight

APPROVED_USDT = '0x55d398326f99059ff775485246999027b3197955'


def _now():
    return datetime.now(ZoneInfo('Asia/Jakarta')).replace(tzinfo=None)


def _ready(adapter):
    try:
        observation = adapter.readiness()
    except Exception:
        raise CheckoutError('provider_unavailable') from None
    if type(observation) is not dict or observation.get('ready') is not True:
        raise CheckoutError('provider_unavailable')
    return observation


def _base(pricing):
    if pricing.usdt_total is None:
        raise CheckoutError('usdt_price_missing')
    return require_integer(pricing.usdt_total, minimum=1)


def _assert_preflight(preparation, setting, preflight, now):
    preparation.db.require_transaction()
    if (setting is None or not setting.active or preflight.setting != setting
            or (preflight.provider, preflight.method) != (preparation.provider, preparation.method)):
        raise CheckoutError('stale_preflight')
    checked_at = preflight.observation[0]
    if not timedelta(0) <= now - checked_at < timedelta(seconds=60):
        raise CheckoutError('stale_preflight')


def _allocate(db, method, base, now):
    """The caller holds the canonical setting lock through the Payment insert."""
    start = base
    while True:
        end = require_integer(start + 999_000, minimum=1)
        if method == 'BINANCE_PAY_ID':
            occupied = db.rows("""SELECT amount FROM public.payments WHERE provider='manual'
                AND method='BINANCE_PAY_ID' AND status='pending' AND binance_auto_verification_enabled
                AND amount BETWEEN %s AND %s""", (start, end))
        else:
            occupied = db.rows("""SELECT p.amount FROM public.payments p JOIN public.orders o ON o.id=p.order_id
                JOIN public.payment_bsc_details d ON d.payment_id=p.id WHERE p.provider='manual'
                AND p.method='USDT_BEP20' AND p.status='pending' AND o.order_status='pending_payment'
                AND p.amount BETWEEN %s AND %s
                UNION SELECT payable_micro AS amount FROM public.customer_usdt_funding_intents
                WHERE rail='bsc_shared' AND state='pending' AND expires_at>%s AND payable_micro BETWEEN %s AND %s
                UNION SELECT payable_micro AS amount FROM public.customer_usdt_top_ups
                WHERE rail='bsc_shared' AND created_at>=%s AND payable_micro BETWEEN %s AND %s""",
                (start, end, now, start, end, now - timedelta(hours=24), start, end))
        values = {row['amount'] for row in occupied}
        for amount in range(start, end + 1, 1000):
            if amount not in values:
                return amount
        start = require_integer(end + 1000, minimum=1)


@dataclass(frozen=True, slots=True)
class BinanceSnapshot:
    mode: str
    receiver_type: str
    receiver_value: str



class BinancePreparation:
    provider = 'manual'
    method = 'BINANCE_PAY_ID'

    def __init__(self, adapter=None, *, using='default', clock=None):
        self.adapter = adapter
        self.using = using
        self.db = Database(using)
        self.clock = clock or _now

    def preflight(self, setting):
        if connections[self.using].in_atomic_block:
            raise RuntimeError('Readiness must precede checkout locks')
        if (setting is None or not setting.active or not setting.binance_enabled
                or setting.binance_receiver_type not in ('pay_id', 'uid')
                or not isinstance(setting.binance_receiver_value, str)
                or not re.fullmatch(r'[0-9]{1,57}', setting.binance_receiver_value)):
            raise CheckoutError('provider_unavailable')
        if (setting.binance_verification_mode == 'order_id'
                and setting.binance_order_id_compatibility_ready_at is None):
            raise CheckoutError('provider_unavailable')
        observation = _ready(self.adapter)
        return PaymentPreflight(self.provider, self.method, setting, (self.clock(), observation))

    def prepare(self, pricing, setting, preflight, now):
        _assert_preflight(self, setting, preflight, now)
        if not setting.binance_enabled:
            raise CheckoutError('provider_unavailable')
        mode = setting.binance_verification_mode
        if mode not in ('amount_unique', 'order_id'):
            raise CheckoutError('provider_unavailable')
        base = _base(pricing)
        amount = _allocate(self.db, self.method, base, now) if mode == 'amount_unique' else base
        return PaymentPlan(self.provider, self.method, amount,
                           now + timedelta(minutes=setting.binance_ttl_minutes), currency='USDT',
                           rail_snapshot=BinanceSnapshot(mode, setting.binance_receiver_type,
                                                         setting.binance_receiver_value))


@dataclass(frozen=True, slots=True)
class BscSnapshot:
    receiver_id: int
    receiver_address: str
    token_decimals: int
    base_amount_micro: int
    payable_amount_micro: int
    required_confirmations: int
    requires_finalized_block: bool
    confirmation_grace_minutes: int

    def persist(self, db, payment_id, now):
        db.require_transaction()
        db.execute('''INSERT INTO public.payment_bsc_details
            (payment_id,receiver_id,receiver_address,chain_id,token_contract,token_decimals,
             base_amount_micro,payable_amount_micro,expected_token_units,verification_status,
             verification_mode,required_confirmations,requires_finalized_block,confirmation_grace_minutes,
             created_at,updated_at) VALUES (%s,%s,%s,56,%s,%s,%s,%s,%s,'awaiting_transfer',
             'amount_unique',%s,%s,%s,%s,%s)''',
            (payment_id, self.receiver_id, self.receiver_address, APPROVED_USDT, self.token_decimals,
             self.base_amount_micro, self.payable_amount_micro,
             str(self.payable_amount_micro * 10 ** (self.token_decimals - 6)), self.required_confirmations,
             self.requires_finalized_block, self.confirmation_grace_minutes, now, now))


class BscPreparation:
    provider = 'manual'
    method = 'USDT_BEP20'

    def __init__(self, adapter=None, *, using='default', clock=None):
        self.adapter = adapter
        self.using = using
        self.db = Database(using)
        self.clock = clock or _now

    def _receivers(self, *, lock=False):
        return self.db.rows('''SELECT r.id,r.address_normalized,r.chain_id,r.token_contract,r.token_decimals,
            r.address_claim_id,r.verification_status,r.verified_at FROM public.bsc_payment_receivers r
            JOIN public.usdt_receiver_address_claims c ON c.id=r.address_claim_id
            WHERE r.is_active AND r.verification_status IN ('verified','manual_verified')
              AND r.chain_id=56 AND r.token_contract=%s AND r.token_decimals=18
              AND c.channel='checkout' AND c.chain_id=r.chain_id AND c.address_normalized=r.address_normalized
            ORDER BY r.last_assigned_at NULLS FIRST,r.id''' + (' FOR UPDATE OF r' if lock else ''),
            (APPROVED_USDT,))

    def preflight(self, setting):
        if connections[self.using].in_atomic_block:
            raise RuntimeError('Readiness must precede checkout locks')
        if setting is None or not setting.active or not setting.bsc_enabled:
            raise CheckoutError('provider_unavailable')
        receivers = self._receivers()
        if not receivers:
            raise CheckoutError('provider_unavailable')
        observed = _ready(self.adapter)
        if (type(observed.get('chain_id')) is not int or observed['chain_id'] != 56
                or observed.get('token_contract') != APPROVED_USDT
                or type(observed.get('token_decimals')) is not int or observed['token_decimals'] != 18
                or observed.get('transfer_logs') is not True):
            raise CheckoutError('provider_unavailable')
        identity = tuple(sorted(tuple(row.items()) for row in receivers))
        return PaymentPreflight(self.provider, self.method, setting, (self.clock(), identity))

    def prepare(self, pricing, setting, preflight, now):
        _assert_preflight(self, setting, preflight, now)
        # Legacy receiver-ownership triggers resolve public tables by name.
        self.db.execute('SET LOCAL search_path = pg_catalog, public, pg_temp')
        if (not setting.bsc_enabled or not 3 <= setting.bsc_min_confirmations <= 100
                or type(setting.bsc_confirmation_grace_minutes) is not int
                or not 0 <= setting.bsc_confirmation_grace_minutes <= 60):
            raise CheckoutError('provider_unavailable')
        receivers = self._receivers(lock=True)
        if not receivers or tuple(sorted(tuple(row.items()) for row in receivers)) != preflight.observation[1]:
            raise CheckoutError('stale_preflight')
        receiver = receivers[0]
        if re.fullmatch(r'0x[0-9a-f]{40}', receiver['address_normalized']) is None:
            raise CheckoutError('provider_unavailable')
        base = _base(pricing)
        amount = _allocate(self.db, self.method, base, now)
        self.db.execute('UPDATE public.bsc_payment_receivers SET last_assigned_at=%s WHERE id=%s',
                        (now, receiver['id']))
        return PaymentPlan(self.provider, self.method, amount,
                           now + timedelta(minutes=setting.bsc_ttl_minutes), currency='USDT',
                           rail_snapshot=BscSnapshot(receiver['id'], receiver['address_normalized'],
                               receiver['token_decimals'], base, amount, setting.bsc_min_confirmations,
                               setting.bsc_require_finalized_block, setting.bsc_confirmation_grace_minutes))
