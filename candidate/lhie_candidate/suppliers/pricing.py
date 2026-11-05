"""Exact private supplier economics, in provider micros and whole IDR."""

from decimal import Decimal, InvalidOperation
import json

MAX_AMOUNT = 2**63 - 1
PROVIDERS = frozenset(('prodseller', 'csxstore', 'arrs', 'warzone', 'aivault',
                       'nystore', 'toolorax', 'canboso', 'nastele', 'miraiapi'))


def _integer(value, minimum=0):
    if type(value) is not int or not minimum <= value <= MAX_AMOUNT:
        raise ValueError('invalid supplier amount')
    return value


def _checked(value):
    return _integer(value)


def _rows(value):
    if value is None:
        return []
    if isinstance(value, str):
        value = json.loads(value)
    if not isinstance(value, (list, tuple)):
        raise ValueError('invalid supplier schedule')
    return value


def rate_value(currency, rate):
    if currency not in ('USD', 'USDT', 'IDR', 'VND') or isinstance(rate, (float, bool)):
        raise ValueError('invalid supplier currency or rate')
    try:
        value = Decimal(rate)
    except (InvalidOperation, ValueError, TypeError):
        raise ValueError('invalid supplier conversion') from None
    if not value.is_finite() or value <= 0 or value > 1000000 or value.as_tuple().exponent < -6:
        raise ValueError('invalid supplier conversion')
    if currency == 'VND' and not Decimal('0.1') <= value <= Decimal('0.999999'):
        raise ValueError('invalid VND conversion')
    return value


def cost_idr(micros, currency, rate):
    _integer(micros)
    value = rate_value(currency, rate)
    numerator, denominator = value.as_integer_ratio()
    return _checked((micros * numerator + denominator * 1000000 - 1) // (denominator * 1000000))


def cost_micros(item, quantity):
    _integer(quantity, 1)
    unit = item.get('unit_price_micros')
    for tier in _rows(item.get('price_tiers')):
        minimum = _integer(tier['min'], 1)
        maximum = tier.get('max')
        if maximum is not None:
            _integer(maximum, minimum)
        if quantity >= minimum and (maximum is None or quantity <= maximum):
            unit = tier['unit_price_micros']
    _integer(unit)
    discount = 0
    for row in _rows(item.get('bulk_discounts')):
        minimum = _integer(row['min'], 1)
        percent = _integer(row['percent_basis_points'])
        if percent > 10000:
            raise ValueError('invalid discount')
        if quantity >= minimum:
            discount = max(discount, percent)
    total = _checked(unit * quantity)
    return _checked((total * (10000 - discount) + 9999) // 10000)


def validate_policy(policy):
    if policy.get('mode') not in ('manual', 'percent', 'fixed', 'hybrid'):
        raise ValueError('invalid pricing mode')
    if policy.get('rounding_idr') not in (1, 100, 500, 1000):
        raise ValueError('invalid rounding')
    for name in ('markup_basis_points', 'profit_idr', 'minimum_profit_idr'):
        _integer(policy.get(name))
    if policy['markup_basis_points'] > 100000:
        raise ValueError('invalid markup')


def retail_price(policy, cost):
    validate_policy(policy)
    _integer(cost)
    mode = policy['mode']
    if mode == 'manual':
        raise ValueError('manual policy retains product retail')
    percent = (cost * policy['markup_basis_points'] + 9999) // 10000
    profit = percent if mode == 'percent' else policy['profit_idr'] if mode == 'fixed' else max(percent, policy['profit_idr'])
    amount = _checked(cost + profit)
    rounding = policy['rounding_idr']
    return _checked(((amount + rounding - 1) // rounding) * rounding)


def maximum_cost_idr(policy, retail_subtotal, quantity):
    validate_policy(policy)
    _integer(retail_subtotal)
    _integer(quantity, 1)
    return max(0, retail_subtotal - _checked(policy['minimum_profit_idr'] * quantity))


def validate_retail_schedule(base_idr, base_usdt, tiers):
    if len(tiers) > 5:
        raise ValueError('invalid retail tier schedule')
    previous_minimum, previous_idr, previous_usdt = 1, base_idr, base_usdt
    for minimum, idr, usdt in tiers:
        if (type(minimum) is not int or not 2 <= minimum <= 20 or minimum <= previous_minimum
                or type(idr) is not int or not 0 < idr < previous_idr
                or (base_usdt is None and usdt is not None)
                or (base_usdt is not None and (type(usdt) is not int or not 0 < usdt < previous_usdt))):
            raise ValueError('invalid retail tier schedule')
        previous_minimum, previous_idr, previous_usdt = minimum, idr, usdt


def preview(item, supplier, product, policy, tiers, quantity=1):
    empty = {'quantity': quantity, 'cost_idr': None, 'retail_idr': None, 'profit_idr': None,
             'cost_unit_idr': None, 'retail_unit_idr': None, 'profit_unit_idr': None, 'currency': None}
    if type(quantity) is not int or not 1 <= quantity <= 10000:
        return empty
    try:
        total_cost = cost_idr(cost_micros(item, quantity), supplier['currency'], supplier['exchange_rate_idr'])
        base = product['price_amount']
        if policy['mode'] != 'manual':
            base = retail_price(policy, cost_idr(cost_micros(item, 1), supplier['currency'], supplier['exchange_rate_idr']))
        validate_retail_schedule(base, product.get('usdt_price_amount'), tiers)
        unit = base
        for minimum, idr, _ in tiers:
            if quantity >= minimum:
                unit = idr
        total_retail = _checked(unit * quantity)
        profit = total_retail - total_cost
        return {'quantity': quantity, 'cost_idr': total_cost, 'retail_idr': total_retail,
                'profit_idr': profit, 'cost_unit_idr': (total_cost + quantity - 1) // quantity,
                'retail_unit_idr': unit, 'profit_unit_idr': abs(profit) // quantity * (-1 if profit < 0 else 1),
                'currency': supplier['currency']}
    except (KeyError, TypeError, ValueError, OverflowError):
        return empty


def eligible(supplier, item, policy, quantity, retail_subtotal, now):
    try:
        if (supplier['provider'] not in PROVIDERS or not supplier['is_active']
                or not supplier['currency_confirmed'] or supplier['synced_at'] is None
                or supplier['sync_expires_at'] is None or supplier['sync_expires_at'] <= now
                or item['supplier_id'] != supplier['id'] or item['synced_at'] is None
                or item['currency'] not in (None, supplier['currency'])
                or not item['orderable'] or item['requires_input'] or item['stock'] is None
                or type(quantity) is not int or not 1 <= quantity <= 10000
                or quantity > max(0, item['stock'] - item['reserved_quantity'])
                or supplier['balance_micros'] is None):
            return False
        cost = cost_micros(item, quantity)
        minimum = _checked(policy['minimum_profit_idr'] * quantity)
        return (retail_subtotal >= minimum
                and cost <= max(0, supplier['balance_micros'] - supplier['reserved_balance_micros'])
                and cost_idr(cost, supplier['currency'], supplier['exchange_rate_idr'])
                <= maximum_cost_idr(policy, retail_subtotal, quantity))
    except (KeyError, TypeError, ValueError, OverflowError):
        return False
