"""Customer-owned, credential-free API projections over the existing read owners."""
import re
from collections import defaultdict
from uuid import UUID
from zoneinfo import ZoneInfo

from lhie_candidate.checkout.db import Database
from lhie_candidate.checkout.pricing import PricingSnapshot
from lhie_candidate.domain.money import Currency, Money
from lhie_candidate.navigation.repository import CatalogRepository, _PRODUCT_SELECT
from .admission import ApiFailure


def _invalid():
    return ApiFailure(422, 'invalid_input', 'The request contains unsupported or malformed inputs.')


def _not_found():
    return ApiFailure(404, 'not_found', 'Resource not found.')


def _integer(value, minimum, maximum):
    if not isinstance(value, str) or not re.fullmatch(r'0|[1-9][0-9]*', value) or len(value) > 19:
        raise _invalid()
    number = int(value)
    if not minimum <= number <= maximum:
        raise _invalid()
    return number


def _money(amount, currency):
    if amount is None:
        return None
    return Money(Currency.USDT if currency == 'USDT' else Currency.IDR, int(amount)).format_decimal()


def balance(principal, *, using='default'):
    repository = CatalogRepository(using)
    customer = repository._one('SELECT balance_payment_preference FROM public.customers WHERE id=%s', (principal.customer_id,))
    if customer is None:
        raise _not_found()
    idr = repository.balance_snapshot(principal.customer_id)
    usd = repository.usdt_balance_snapshot(principal.customer_id)
    usd_available = usd['available'] and not usd['frozen']
    return {'data': {'payment_preference': customer['balance_payment_preference'], 'wallets': [
        {'currency': 'IDR', 'available': idr['available'], 'frozen': False,
         'amount': str(idr['balance']) if idr['available'] else None},
        {'currency': 'USD', 'available': usd_available, 'frozen': usd['frozen'],
         'amount': _money(usd['balance_micro'], 'USDT') if usd_available else None},
    ]}}


_METADATA = 'product_type access_duration region account_type device_limit included_items limitations fulfillment_expectation warranty_policy warranty_policy_ms warranty_policy_en support_policy delivery_note usage_guide_title usage_guide_url'.split()
_EXTRA_COLUMNS = ('reseller_price_amount', 'reseller_usdt_price_amount', 'delivery_note', 'usage_guide_title', 'usage_guide_url')
_CATALOG_SQL = _PRODUCT_SELECT.replace('SELECT p.id,', 'SELECT ' + ', '.join('p.' + c for c in _EXTRA_COLUMNS) + ', c.description AS category_description, p.id,', 1)


def _product_rows(repository, suffix, params):
    return repository._enrich_products(repository._rows(_CATALOG_SQL + suffix, params))


def _serialize_products(repository, products, quantity, fail=False):
    ids = [p['id'] for p in products]
    tiers = defaultdict(list)
    if ids:
        for tier in repository._rows('SELECT product_id,minimum_quantity,price_amount,usdt_price_amount FROM public.product_quantity_price_tiers WHERE product_id=ANY(%s) ORDER BY product_id,minimum_quantity,id', (ids,)):
            tiers[tier['product_id']].append(tier)
    result = []
    for product in products:
        try:
            quote = PricingSnapshot.build([{'product_id': product['id'], 'quantity': quantity}], {product['id']: product}, tiers, reseller=True).quotes[0]
        except (ValueError, OverflowError):
            if fail:
                raise ApiFailure(422, 'quote_unavailable', 'The requested quantity cannot be quoted safely.')
            quote = None
        quotes = []
        for currency in ('IDR', 'USD'):
            usd = currency == 'USD'
            base = product['usdt_price_amount' if usd else 'price_amount'] if quote else None
            effective = (quote.usdt_unit_price if usd else quote.unit_price) if quote else None
            total = (quote.usdt_subtotal if usd else quote.subtotal) if quote else None
            internal = 'USDT' if usd else 'IDR'
            quotes.append({'currency': currency, 'eligible': base is not None and (not usd or base > 0),
                           'regular_unit': _money(base, internal), 'effective_unit': _money(effective, internal),
                           'regular_total': _money(base * quantity if base is not None else None, internal),
                           'total': _money(total, internal)})
        family = product['family']
        category = None if product['category_id'] is None else {'id': str(product['category_id']), 'name': product['category_name'], 'slug': product['category_slug'], 'description': product['category_description']}
        family = None if family is None else {k: str(family[k]) if k == 'id' else family[k] for k in ('id', 'name', 'slug', 'description', 'icon', 'custom_emoji_id')}
        stock = product['available_stock']
        result.append({'id': str(product['id']), 'name': product['name'], 'plan_label': product['family_option_label'],
                       'description': product['description'], 'description_ms': product['description_ms'], 'description_en': product['description_en'],
                       'icon': product['icon'], 'custom_emoji_id': product['custom_emoji_id'], 'category': category, 'family': family,
                       'stock': {'available': stock, 'is_available': stock > 0, 'quantity_available': stock >= quantity},
                       'quantity': quantity, 'quotes': quotes, 'input_requirements': [],
                       'metadata': {key: product[key] for key in _METADATA}, 'informational': True})
    return result


def products(principal, filters, *, using='default'):
    if set(filters) - {'limit', 'cursor', 'quantity', 'category_id', 'family_id', 'available'}:
        raise _invalid()
    limit = _integer(filters.get('limit', '20'), 1, 100)
    after = _integer(filters.get('cursor', '0'), 0, 2**63-1)
    quantity = _integer(filters.get('quantity', '1'), 1, 100)
    suffix, params = ' AND p.id>%s', [after]
    for key, column in (('category_id', 'category_id'), ('family_id', 'product_family_id')):
        if key in filters:
            suffix += ' AND p.' + column + '=%s'
            params.append(_integer(filters[key], 1, 2**63-1))
    if 'available' in filters:
        if filters['available'] not in ('true', 'false'):
            raise _invalid()
        # Reuse the shared catalog stock projection for filtering as well as display.
        sql = 'SELECT * FROM (' + _CATALOG_SQL + suffix + ') api_products WHERE available_stock ' + ('> 0' if filters['available'] == 'true' else '<= 0') + ' ORDER BY id LIMIT %s'
    else:
        sql = _CATALOG_SQL + suffix + ' ORDER BY p.id LIMIT %s'
    params.append(limit + 1)
    repository = CatalogRepository(using)
    rows = repository._enrich_products(repository._rows(sql, params))
    more = len(rows) > limit
    rows = rows[:limit]
    return {'data': _serialize_products(repository, rows, quantity), 'pagination': {'next_cursor': str(rows[-1]['id']) if more else None, 'has_more': more}}


def product(principal, product_id, filters, *, using='default'):
    if set(filters) - {'quantity', 'currency'}:
        raise _invalid()
    product_id = _integer(product_id, 1, 2**63-1)
    quantity = _integer(filters.get('quantity', '1'), 1, 100)
    currency = filters.get('currency')
    if 'currency' in filters and currency not in ('IDR', 'USD'):
        raise _invalid()
    repository = CatalogRepository(using)
    rows = _product_rows(repository, ' AND p.id=%s', (product_id,))
    if not rows:
        raise _not_found()
    if currency == 'USD' and (rows[0]['usdt_price_amount'] is None or rows[0]['usdt_price_amount'] <= 0):
        raise ApiFailure(422, 'currency_unavailable', 'This Product has no USD price.')
    return {'data': _serialize_products(repository, rows, quantity, fail=True)[0]}


def _uuid(value):
    if not isinstance(value, str) or not re.fullmatch(
            r'[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}', value):
        return None
    return str(UUID(value))


def owned_order(principal, order_id, *, using='default', lock=False):
    order_id = _uuid(order_id)
    if order_id is None:
        raise _not_found()
    row = Database(using).one('SELECT id,customer_id,invoice_number,order_status,payment_status,currency,created_at FROM public.orders WHERE customer_id=%s AND id=%s AND api_order_claim_id IS NOT NULL' + (' FOR SHARE' if lock else ''), (principal.customer_id, order_id))
    if row is None:
        raise _not_found()
    return row


def order_summaries(orders, *, using='default'):
    if not orders:
        return []
    db = Database(using)
    ids = [str(o['id']) for o in orders]
    items = defaultdict(list)
    for row in db.rows('SELECT id,order_id,product_id,product_name,quantity,delivery_note,delivery_note_snapshotted,usage_guide_title,usage_guide_url,usage_guide_snapshotted FROM public.order_items WHERE order_id=ANY(%s::uuid[]) ORDER BY id', (ids,)):
        items[str(row['order_id'])].append(row)
    payments = {}
    for row in db.rows('SELECT id,order_id,status,currency,amount,paid_at,created_at FROM public.payments WHERE order_id=ANY(%s::uuid[]) ORDER BY created_at DESC NULLS LAST,id DESC', (ids,)):
        payments.setdefault(str(row['order_id']), row)
    units = {str(row['order_id']): row for row in db.rows('SELECT i.order_id,COUNT(*) AS allocated,COUNT(*) FILTER (WHERE s.retired_at IS NULL) AS active FROM public.product_stock_items s JOIN public.order_items i ON i.id=s.order_item_id WHERE i.order_id=ANY(%s::uuid[]) GROUP BY i.order_id', (ids,))}
    fulfillments = defaultdict(list)
    for row in db.rows('SELECT order_id,state FROM public.supplier_fulfillments WHERE order_id=ANY(%s::uuid[])', (ids,)):
        fulfillments[str(row['order_id'])].append(row['state'])
    reviews = defaultdict(list)
    for row in db.rows("SELECT order_id,reason FROM public.manual_fulfillment_reviews WHERE order_id=ANY(%s::uuid[]) AND state IN ('pending','refund_uncertain')", (ids,)):
        reviews[str(row['order_id'])].append(row['reason'])
    refunds = defaultdict(list)
    for row in db.rows('SELECT order_id,amount,currency,state FROM public.order_refunds WHERE order_id=ANY(%s::uuid[])', (ids,)):
        refunds[str(row['order_id'])].append(row)
    result = []
    for order in orders:
        oid = str(order['id'])
        payment = payments.get(oid)
        currency = payment['currency'] if payment else order['currency']
        amount = int(payment['amount']) if payment else None
        quantity = sum(int(i['quantity']) for i in items[oid])
        allocated = int(units.get(oid, {}).get('allocated', 0))
        eligible = (payment is not None and payment['status'] == 'paid' and payment['paid_at'] is not None
                    and order['payment_status'] == 'paid' and order['order_status'] in ('processing', 'completed')
                    and all(state == 'delivered' for state in fulfillments[oid]) and 'late_payment' not in reviews[oid])
        released = int(units.get(oid, {}).get('active', 0)) if eligible else 0
        relevant = [r for r in refunds[oid] if r['currency'] == currency]
        confirmed = amount if order['payment_status'] == 'refunded' else sum(int(r['amount']) for r in relevant if r['state'] == 'confirmed')
        pending = sum(int(r['amount']) for r in relevant if r['state'] in ('requested', 'uncertain'))
        refund_state = 'refunded' if order['payment_status'] == 'refunded' else 'pending' if pending else 'partial' if confirmed else 'none'
        processing = any(s not in ('delivered', 'cancelled') for s in fulfillments[oid])
        review = bool(reviews[oid])
        state = ('refunded' if refund_state == 'refunded' else 'review' if review else 'partial' if 0 < released < quantity else 'complete' if quantity > 0 and released == quantity else 'processing' if processing else 'not_ready')
        result.append({'id': oid, 'invoice_number': order['invoice_number'], 'origin': 'api', 'status': order['order_status'],
                       'payment_status': order['payment_status'], 'currency': 'USD' if currency == 'USDT' else currency, 'amount': _money(amount, currency),
                       'quantity': quantity, 'items': [{'product_id': str(i['product_id']), 'name': i['product_name'], 'quantity': int(i['quantity'])} for i in items[oid]],
                       'fulfillment': {'state': state, 'ordered_units': quantity, 'allocated_units': allocated, 'released_units': released, 'processing': processing, 'review_required': review},
                       'delivery': {'available': released > 0, 'state': 'not_ready' if not released else 'ready' if released == quantity else 'partial'},
                       'refund': {'state': refund_state, 'currency': 'USD' if currency == 'USDT' else currency, 'confirmed_amount': _money(confirmed, currency), 'pending_amount': _money(pending, currency)},
                       'created_at': (order['created_at'].replace(tzinfo=ZoneInfo('Asia/Jakarta'))
                                      if order['created_at'].tzinfo is None else
                                      order['created_at'].astimezone(ZoneInfo('Asia/Jakarta'))).isoformat()
                       if order['created_at'] else None})
    return result


def order_detail(principal, order_id, *, using='default'):
    return {'data': order_summaries([owned_order(principal, order_id, using=using)], using=using)[0]}


def orders(principal, filters, *, using='default'):
    if set(filters) - {'limit', 'cursor', 'status'}:
        raise _invalid()
    limit = _integer(filters.get('limit', '20'), 1, 100)
    cursor = _uuid(filters['cursor']) if 'cursor' in filters else None
    if 'cursor' in filters and cursor is None:
        raise _invalid()
    status = filters.get('status')
    if 'status' in filters and status not in ('draft', 'pending_payment', 'processing', 'completed', 'cancelled', 'expired'):
        raise _invalid()
    sql = 'SELECT id,customer_id,invoice_number,order_status,payment_status,currency,created_at FROM public.orders WHERE customer_id=%s AND api_order_claim_id IS NOT NULL'
    params = [principal.customer_id]
    if cursor is not None:
        sql += ' AND id>%s'
        params.append(cursor)
    if status is not None:
        sql += ' AND order_status=%s'
        params.append(status)
    sql += ' ORDER BY id LIMIT %s'
    params.append(limit + 1)
    rows = Database(using).rows(sql, params)
    more = len(rows) > limit
    rows = rows[:limit]
    return {'data': order_summaries(rows, using=using), 'pagination': {'next_cursor': str(rows[-1]['id']) if more else None, 'has_more': more}}
