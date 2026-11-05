"""Checkout supplier commitments. Product locks precede globally ordered resources."""

from datetime import datetime
from zoneinfo import ZoneInfo
from django.db import connections, transaction
from .pricing import PROVIDERS, cost_micros, cost_idr, eligible, maximum_cost_idr, rate_value, retail_price, validate_policy


class SupplierCapacityError(ValueError):
    pass


def _cursor(using):
    db = connections[using]
    if not db.in_atomic_block:
        raise SupplierCapacityError('supplier reservation requires an existing transaction')
    return db.cursor()


def _dicts(cursor):
    names = [column[0] for column in cursor.description]
    return [dict(zip(names, row)) for row in cursor.fetchall()]


def lock_checkout_resources(product_ids, *, using='default', catalog_supplier_ids=()):
    """Lock mapped resources and optional whole supplier catalogs after Products."""
    ids = sorted(set(product_ids))
    extra = sorted(set(catalog_supplier_ids))
    if any(type(value) is not int or value <= 0 for value in ids + extra):
        raise SupplierCapacityError('invalid product identity')
    with _cursor(using) as cursor:
        cursor.execute('''SELECT DISTINCT c.supplier_id FROM public.product_supplier_sources s
            JOIN public.supplier_catalog_items c ON c.id=s.supplier_catalog_item_id
            WHERE s.product_id = ANY(%s) ORDER BY c.supplier_id''', [ids])
        supplier_ids = sorted({row[0] for row in cursor.fetchall()} | set(extra))
        cursor.execute('SELECT id FROM public.suppliers WHERE id = ANY(%s) ORDER BY id FOR UPDATE', [supplier_ids])
        cursor.fetchall()
        cursor.execute('''SELECT c.id FROM public.supplier_catalog_items c
            WHERE c.supplier_id=ANY(%s) OR c.id IN (SELECT supplier_catalog_item_id FROM public.product_supplier_sources
                           WHERE product_id = ANY(%s)) ORDER BY c.id FOR UPDATE''', [extra, ids])
        cursor.fetchall()


def reserve(item_id, product_id, external_quantity, retail_subtotal, cipher, now, *, using='default'):
    """Reserve under caller-held Product and all-cart supplier/SKU locks."""
    if (type(external_quantity) is not int or not 1 <= external_quantity <= 10000
            or type(retail_subtotal) is not int or retail_subtotal < 0):
        raise SupplierCapacityError('invalid reservation')
    with _cursor(using) as cursor:
        cursor.execute('SELECT id, order_id, product_id, quantity, supplier_quantity FROM public.order_items WHERE id=%s', [item_id])
        rows = _dicts(cursor)
        if not rows or rows[0]['product_id'] != product_id or rows[0]['supplier_quantity'] != external_quantity or rows[0]['quantity'] < external_quantity:
            raise SupplierCapacityError('invalid reservation context')
        item = rows[0]
        cursor.execute('SELECT id, quantity, order_id FROM public.supplier_fulfillments WHERE order_item_id=%s', [item_id])
        existing = _dicts(cursor)
        if existing:
            if existing[0]['quantity'] != external_quantity or existing[0]['order_id'] != item['order_id']:
                raise SupplierCapacityError('reservation identity conflict')
            return existing[0]['id']
        cursor.execute('SELECT * FROM public.supplier_price_policies WHERE product_id=%s', [product_id])
        policies = _dicts(cursor)
        if not policies:
            raise SupplierCapacityError('supplier pricing unavailable')
        policy = policies[0]
        external_retail = retail_subtotal * external_quantity // item['quantity']
        cursor.execute('''SELECT s.id AS source_id, c.id AS catalog_id, c.supplier_id
            FROM public.product_supplier_sources s JOIN public.supplier_catalog_items c
              ON c.id=s.supplier_catalog_item_id
            WHERE s.product_id=%s AND s.is_active ORDER BY s.priority,s.id''', [product_id])
        sources = _dicts(cursor)
        snapshot = []
        for source in sources:
            cursor.execute('SELECT * FROM public.suppliers WHERE id=%s', [source['supplier_id']])
            suppliers = _dicts(cursor)
            cursor.execute('SELECT * FROM public.supplier_catalog_items WHERE id=%s', [source['catalog_id']])
            items = _dicts(cursor)
            if not suppliers or not items:
                continue
            supplier, sku = suppliers[0], items[0]
            if eligible(supplier, sku, policy, external_quantity, external_retail, now):
                snapshot.append({'source_id': source['source_id'], 'supplier_id': supplier['id'],
                    'catalog_item_id': sku['id'], 'external_id': sku['external_id'],
                    'currency': supplier['currency'], 'exchange_rate_idr': str(supplier['exchange_rate_idr']),
                    'unit_price_micros': sku['unit_price_micros'],
                    'expected_cost_micros': cost_micros(sku, external_quantity)})
        if not snapshot:
            raise SupplierCapacityError('ordered product is not available')
        first = snapshot[0]
        cursor.execute('''INSERT INTO public.supplier_fulfillments
            (order_id,order_item_id,quantity,state,maximum_cost_idr,sources_snapshot,
             reserved_supplier_id,reserved_catalog_item_id,reserved_cost_micros,reserved_quantity,created_at,updated_at)
            VALUES (%s,%s,%s,'reserved',%s,%s,%s,%s,%s,%s,%s,%s) RETURNING id''',
            [item['order_id'], item_id, external_quantity,
             maximum_cost_idr(policy, external_retail, external_quantity), cipher.encrypt_json(snapshot),
             first['supplier_id'], first['catalog_item_id'], first['expected_cost_micros'], external_quantity, now, now])
        fulfillment_id = cursor.fetchone()[0]
        cursor.execute('''UPDATE public.suppliers SET reserved_balance_micros=reserved_balance_micros+%s,
            version=version+1,updated_at=%s WHERE id=%s''', [first['expected_cost_micros'], now, first['supplier_id']])
        cursor.execute('''UPDATE public.supplier_catalog_items SET reserved_quantity=reserved_quantity+%s,
            updated_at=%s WHERE id=%s''', [external_quantity, now, first['catalog_item_id']])
        cursor.execute('UPDATE public.products SET supplier_available_until=NULL WHERE id=%s', [product_id])
        return fulfillment_id


def _validate_schedule(product, tiers):
    previous = product['price_amount']
    dollars = product['usdt_price_amount']
    if (len(tiers) > 5 or type(previous) is not int or previous <= 0
            or (dollars is not None and (type(dollars) is not int or dollars < 0))):
        raise ValueError('invalid retail schedule')
    previous_min = 1
    for tier in tiers:
        minimum, price, usd = tier['minimum_quantity'], tier['price_amount'], tier['usdt_price_amount']
        if (type(minimum) is not int or type(price) is not int
                or not 2 <= minimum <= 20 or minimum <= previous_min or not 0 < price < previous
                or (dollars is None and usd is not None)
                or (dollars is not None and (type(usd) is not int or not 0 < usd < dollars))):
            raise ValueError('invalid retail schedule')
        previous_min, previous, dollars = minimum, price, usd


def capacity_for_source(product, supplier, item, policy, tiers, local_stock, now):
    """Contiguous safe quantity; a tier gap cannot be advertised as capacity."""
    try:
        validate_policy(policy)
        _validate_schedule(product, tiers)
        limit = min(10000, max(0, item['stock'] - item['reserved_quantity']))
        capacity = 0
        for quantity in range(1, limit + 1):
            unit = product['price_amount']
            for tier in tiers:
                if quantity + local_stock >= tier['minimum_quantity']:
                    unit = tier['price_amount']
            total_cost = cost_micros(item, quantity)
            unit_cost = (total_cost + quantity - 1) // quantity
            if (cost_idr(unit_cost, supplier['currency'], supplier['exchange_rate_idr'])
                    > maximum_cost_idr(policy, unit, 1)
                    or not eligible(supplier, item, policy, quantity, unit * quantity, now)):
                break
            capacity = quantity
        return capacity
    except (KeyError, TypeError, ValueError, OverflowError):
        return 0


def refresh_locked(product_ids, now, *, using='default'):
    """Refresh products whose Product, mapped Supplier and CatalogItem locks are held."""
    ids = sorted(set(product_ids))
    if not ids:
        return
    with _cursor(using) as cursor:
        cursor.execute('''SELECT p.*,
            EXISTS(SELECT 1 FROM public.product_stock_items i WHERE i.product_id=p.id
                   AND i.supplier_purchase_id IS NULL) AS has_local_items,
            (SELECT COUNT(*) FROM public.product_stock_items i WHERE i.product_id=p.id
             AND i.supplier_purchase_id IS NULL AND i.order_item_id IS NULL AND i.retired_at IS NULL) AS local_items_available
            FROM public.products p WHERE p.id=ANY(%s) ORDER BY p.id''', [ids])
        products = _dicts(cursor)
        cursor.execute('SELECT * FROM public.supplier_price_policies WHERE product_id=ANY(%s)', [ids])
        policies = {row['product_id']: row for row in _dicts(cursor)}
        cursor.execute('''SELECT product_id,minimum_quantity,price_amount,usdt_price_amount
            FROM public.product_quantity_price_tiers WHERE product_id=ANY(%s)
            ORDER BY product_id,minimum_quantity,id''', [ids])
        tiers = {}
        for row in _dicts(cursor):
            tiers.setdefault(row['product_id'], []).append(row)
        cursor.execute('''SELECT * FROM public.product_supplier_sources WHERE product_id=ANY(%s)
            ORDER BY product_id,priority,id''', [ids])
        sources = {}
        for row in _dicts(cursor):
            sources.setdefault(row['product_id'], []).append(row)
        cursor.execute('''SELECT * FROM public.supplier_catalog_items WHERE id IN
            (SELECT supplier_catalog_item_id FROM public.product_supplier_sources WHERE product_id=ANY(%s))''', [ids])
        items = {row['id']: row for row in _dicts(cursor)}
        cursor.execute('SELECT * FROM public.suppliers WHERE id=ANY(%s)',
                       [sorted({item['supplier_id'] for item in items.values()})])
        suppliers = {row['id']: row for row in _dicts(cursor)}
        for product in products:
            policy = policies.get(product['id'])
            schedule = tiers.get(product['id'], [])
            local = max(0, product['stock'] - product['reserved_stock'])
            if product['has_local_items']:
                local = min(local, product['local_items_available'])
            before = local + max(0, product['supplier_available_stock'] or 0)
            capacity, until, stale = 0, None, False
            resolved = policy is None or policy['mode'] == 'manual'
            for source in sources.get(product['id'], []):
                item = items.get(source['supplier_catalog_item_id'])
                supplier = suppliers.get(item['supplier_id']) if item else None
                if (policy is None or not source['is_active'] or supplier is None
                        or not supplier['is_active'] or supplier['provider'] not in PROVIDERS
                        or not supplier['currency_confirmed'] or item['currency'] not in (None, supplier['currency'])
                        or not item['orderable'] or item['requires_input']):
                    continue
                try:
                    rate_value(supplier['currency'], supplier['exchange_rate_idr'])
                except ValueError:
                    continue
                if supplier['sync_expires_at'] is None or supplier['sync_expires_at'] <= now:
                    stale = True
                    continue
                if not resolved:
                    try:
                        price = retail_price(policy, cost_idr(cost_micros(item, 1), supplier['currency'], supplier['exchange_rate_idr']))
                        candidate_product = dict(product, price_amount=price)
                        _validate_schedule(candidate_product, schedule)
                    except (KeyError, TypeError, ValueError, OverflowError):
                        break
                    product['price_amount'] = price
                    resolved = True
                candidate = capacity_for_source(product, supplier, item, policy, schedule, local, now)
                if candidate > capacity or (candidate > 0 and candidate == capacity and supplier['sync_expires_at'] > until):
                    capacity, until = candidate, supplier['sync_expires_at']
            if capacity == 0 and stale:
                capacity = max(0, product['supplier_available_stock'] or 0)
            cursor.execute('''UPDATE public.products SET supplier_available_stock=%s,supplier_available_until=%s,
                price_amount=%s,updated_at=%s WHERE id=%s AND
                (supplier_available_stock IS DISTINCT FROM %s OR supplier_available_until IS DISTINCT FROM %s
                 OR price_amount IS DISTINCT FROM %s)''',
                [capacity, until, product['price_amount'], now, product['id'],
                 capacity, until, product['price_amount']])
            after = local + capacity
            if (before == 0) != (after == 0):
                cursor.execute('''INSERT INTO public.inventory_movements
                    (product_id,type,quantity,stock_before,available_before,available_after,became_unavailable,notes,created_at)
                    VALUES (%s,'availability',%s,%s,%s,%s,%s,%s,%s)''',
                    [product['id'], after-before, product['stock'], before, after, after == 0,
                     'External availability projection; no local inventory mutation.', now])


def refresh_products(product_ids, now, *, using='default'):
    """Lock products before their resources and refresh in canonical order."""
    ids = sorted(set(product_ids))
    for start in range(0, len(ids), 200):
        batch = ids[start:start + 200]
        with transaction.atomic(using=using), connections[using].cursor() as cursor:
            cursor.execute('SELECT id FROM public.products WHERE id=ANY(%s) ORDER BY id FOR NO KEY UPDATE', [batch])
            cursor.fetchall()
            lock_checkout_resources(batch, using=using)
            refresh_locked(batch, now, using=using)


def refresh_shared_wallet_after_commit(supplier_ids, *, using='default'):
    """Refresh all products sharing changed wallets after releasing checkout locks."""
    ids = sorted(set(supplier_ids))
    if not ids:
        return
    def refresh():
        with connections[using].cursor() as cursor:
            cursor.execute('''SELECT DISTINCT s.product_id FROM public.product_supplier_sources s
                JOIN public.supplier_catalog_items c ON c.id=s.supplier_catalog_item_id
                WHERE c.supplier_id=ANY(%s) ORDER BY s.product_id''', [ids])
            product_ids = [row[0] for row in cursor.fetchall()]
        now = datetime.now(ZoneInfo('Asia/Jakarta')).replace(tzinfo=None)
        refresh_products(product_ids, now, using=using)
    transaction.on_commit(refresh, using=using, robust=True)
