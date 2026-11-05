"""Operator-only private supplier configuration and Product mappings."""

import json
from django.db import connections, transaction
from .pricing import PROVIDERS, cost_idr, cost_micros, preview, rate_value, retail_price, validate_policy, validate_retail_schedule


class SupplierManagementError(ValueError):
    pass


def _authorize(cursor, actor):
    cursor.execute("SELECT 1 FROM public.users WHERE id=%s AND role IN ('owner','admin')", [actor])
    if cursor.fetchone() is None:
        raise SupplierManagementError('operator authorization required')


def _audit(cursor, actor, action, model, identity, values, now):
    cursor.execute('''INSERT INTO public.audit_logs(user_id,action,subject_type,subject_id,new_values,created_at)
        VALUES (%s,%s,%s,%s,%s::json,%s)''',
        [actor, action, 'App\\Models\\' + model, str(identity), json.dumps(values), now])


def save_connection(supplier_id, attributes, expected_version, actor, cipher, now, *, using='default'):
    """Create or update a connection; no credential or history is ever logged."""
    provider, currency = attributes['provider'], attributes['currency']
    if provider not in PROVIDERS:
        raise SupplierManagementError('retired or unsupported provider')
    if currency not in ('USD', 'USDT', 'VND') or (provider in ('nastele', 'miraiapi')) != (currency == 'VND'):
        raise SupplierManagementError('invalid provider currency')
    if provider == 'prodseller' and currency != 'USDT' or provider == 'canboso' and currency != 'USD':
        raise SupplierManagementError('invalid provider currency')
    if not isinstance(attributes.get('name'), str) or not 1 <= len(attributes['name']) <= 120:
        raise SupplierManagementError('invalid supplier name')
    if type(attributes.get('is_active')) is not bool or type(attributes.get('currency_confirmed')) is not bool:
        raise SupplierManagementError('invalid supplier state')
    threshold = attributes.get('low_balance_threshold_micros')
    if threshold is not None and (type(threshold) is not int or not 0 <= threshold <= 2**63 - 1):
        raise SupplierManagementError('invalid balance threshold')
    key = attributes.get('api_key')
    if supplier_id is None and (not isinstance(key, str) or not key):
        raise SupplierManagementError('credential required')
    with transaction.atomic(using=using), connections[using].cursor() as cursor:
        _authorize(cursor, actor)
        cursor.execute('SELECT exchange_rate_idr FROM public.supplier_currency_rates WHERE currency=%s FOR UPDATE',
                       ['USD' if currency in ('USD', 'USDT') else currency])
        rate = cursor.fetchone()
        if rate is None:
            raise SupplierManagementError('currency rate unavailable')
        rate_value(currency, rate[0])
        if supplier_id is None:
            cursor.execute('''INSERT INTO public.suppliers(name,provider,api_key,currency,currency_confirmed,
                exchange_rate_idr,is_active,low_balance_threshold_micros,version,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,1,%s,%s) RETURNING id''',
                [attributes['name'],provider,cipher.encrypt_string(key),currency,attributes['currency_confirmed'],rate[0],attributes['is_active'],threshold if threshold is not None else 1000000,now,now])
            supplier_id = cursor.fetchone()[0]
            _audit(cursor,actor,'supplier.created','Supplier',supplier_id,{'supplier_id':supplier_id,'version':1},now)
            return supplier_id
        cursor.execute('''SELECT DISTINCT s.product_id FROM public.product_supplier_sources s JOIN public.supplier_catalog_items c
            ON c.id=s.supplier_catalog_item_id WHERE c.supplier_id=%s ORDER BY s.product_id''', [supplier_id])
        products = [row[0] for row in cursor.fetchall()]
        cursor.execute('SELECT id FROM public.products WHERE id=ANY(%s) ORDER BY id FOR UPDATE', [products]); cursor.fetchall()
        cursor.execute('SELECT provider,currency,currency_confirmed,version,reserved_balance_micros,api_key,low_balance_threshold_micros FROM public.suppliers WHERE id=%s FOR UPDATE', [supplier_id])
        old = cursor.fetchone()
        if old is None or old[3] != expected_version:
            raise SupplierManagementError('stale supplier version')
        if old[0] not in PROVIDERS:
            raise SupplierManagementError('retired supplier connection cannot be reactivated or repurposed')
        if threshold is None:
            threshold = old[6]
        cursor.execute('''SELECT DISTINCT s.product_id FROM public.product_supplier_sources s JOIN public.supplier_catalog_items c
            ON c.id=s.supplier_catalog_item_id WHERE c.supplier_id=%s ORDER BY s.product_id''', [supplier_id])
        if [row[0] for row in cursor.fetchall()] != products:
            raise SupplierManagementError('mapping changed')
        protected = (old[0] != provider or old[1] != currency or old[2] != attributes['currency_confirmed']
                     or bool(key and cipher.decrypt_string(old[5]) != key))
        if protected:
            cursor.execute('''SELECT EXISTS(SELECT 1 FROM public.supplier_deposits WHERE supplier_id=%s AND state IN
                ('requested','sending','unknown','pending','cancel_requested','review')) OR EXISTS
                (SELECT 1 FROM public.supplier_fulfillments WHERE reserved_supplier_id=%s AND reserved_quantity>0) OR EXISTS
                (SELECT 1 FROM public.supplier_purchases WHERE supplier_id=%s AND state IN
                 ('reserved','sending','ambiguous','awaiting_delivery','review'))''', [supplier_id]*3)
            if old[4] or cursor.fetchone()[0]:
                raise SupplierManagementError('supplier has open commitments')
        if old[0] != provider or old[1] != currency:
            cursor.execute('SELECT EXISTS(SELECT 1 FROM public.supplier_account_units WHERE supplier_id=%s)', [supplier_id])
            if cursor.fetchone()[0]:
                raise SupplierManagementError('supplier has account history')
        cursor.execute('''UPDATE public.suppliers SET name=%s,provider=%s,currency=%s,currency_confirmed=%s,
            exchange_rate_idr=%s,is_active=%s,low_balance_threshold_micros=%s,api_key=%s,version=version+1,synced_at=NULL,sync_expires_at=NULL,
            sync_token=NULL,sync_lease_expires_at=NULL,last_error=NULL,updated_at=%s WHERE id=%s''',
            [attributes['name'],provider,currency,attributes['currency_confirmed'],rate[0],attributes['is_active'],threshold,
             cipher.encrypt_string(key) if key else old[5],now,supplier_id])
        cursor.execute('UPDATE public.products SET supplier_available_until=NULL WHERE id=ANY(%s)', [products])
        _audit(cursor,actor,'supplier.updated','Supplier',supplier_id,{'supplier_id':supplier_id,'version':expected_version+1},now)
        return supplier_id


def disable_connection(supplier_id, expected_version, actor, now, *, using='default'):
    """Disable new purchases without changing retained purchases, holds or history."""
    with transaction.atomic(using=using), connections[using].cursor() as cursor:
        _authorize(cursor, actor)
        cursor.execute('''SELECT DISTINCT s.product_id FROM public.product_supplier_sources s
            JOIN public.supplier_catalog_items c ON c.id=s.supplier_catalog_item_id
            WHERE c.supplier_id=%s ORDER BY s.product_id''', [supplier_id])
        products = [row[0] for row in cursor.fetchall()]
        cursor.execute('SELECT id FROM public.products WHERE id=ANY(%s) ORDER BY id FOR NO KEY UPDATE', [products])
        cursor.fetchall()
        cursor.execute('SELECT version,is_active FROM public.suppliers WHERE id=%s FOR UPDATE', [supplier_id])
        supplier = cursor.fetchone()
        if supplier is None or supplier[0] != expected_version:
            raise SupplierManagementError('stale supplier version')
        cursor.execute('''SELECT DISTINCT s.product_id FROM public.product_supplier_sources s
            JOIN public.supplier_catalog_items c ON c.id=s.supplier_catalog_item_id
            WHERE c.supplier_id=%s ORDER BY s.product_id''', [supplier_id])
        if [row[0] for row in cursor.fetchall()] != products:
            raise SupplierManagementError('mapping changed')
        if not supplier[1]:
            return
        cursor.execute('''UPDATE public.suppliers SET is_active=false,version=version+1,
            sync_token=NULL,sync_lease_expires_at=NULL,updated_at=%s WHERE id=%s''', [now, supplier_id])
        cursor.execute('UPDATE public.products SET supplier_available_until=NULL WHERE id=ANY(%s)', [products])
        _audit(cursor, actor, 'supplier.disabled', 'Supplier', supplier_id,
               {'supplier_id': supplier_id, 'version': expected_version + 1}, now)


def map_source(product_id, catalog_id, priority, active, equivalent_confirmed, supplier_version, version, actor, now, *, using='default'):
    if equivalent_confirmed is not True or type(active) is not bool or type(priority) is not int or priority < 0:
        raise SupplierManagementError('invalid mapping')
    with transaction.atomic(using=using), connections[using].cursor() as cursor:
        _authorize(cursor,actor)
        cursor.execute('SELECT id FROM public.products WHERE id=%s FOR UPDATE', [product_id])
        if cursor.fetchone() is None:
            raise SupplierManagementError('unknown product')
        cursor.execute('SELECT supplier_id FROM public.supplier_catalog_items WHERE id=%s', [catalog_id])
        found = cursor.fetchone()
        if not found:
            raise SupplierManagementError('unknown catalog item')
        supplier_id = found[0]
        cursor.execute('SELECT version,provider FROM public.suppliers WHERE id=%s FOR UPDATE', [supplier_id])
        supplier = cursor.fetchone()
        if not supplier or supplier[0] != supplier_version:
            raise SupplierManagementError('stale supplier version')
        cursor.execute('SELECT supplier_id,orderable,requires_input FROM public.supplier_catalog_items WHERE id=%s FOR UPDATE', [catalog_id])
        sku = cursor.fetchone()
        if not sku or sku[0] != supplier_id or (active and (supplier[1] not in PROVIDERS or not sku[1] or sku[2])):
            raise SupplierManagementError('supplier SKU unavailable')
        cursor.execute('SELECT id,version,priority,is_active FROM public.product_supplier_sources WHERE product_id=%s AND supplier_catalog_item_id=%s FOR UPDATE', [product_id,catalog_id])
        current = cursor.fetchone()
        if (current[1] if current else 0) != version:
            raise SupplierManagementError('stale mapping version')
        if current and current[2:] == (priority, active):
            return current[0]
        if current:
            source_id = current[0]
            cursor.execute('UPDATE public.product_supplier_sources SET priority=%s,is_active=%s,version=version+1,updated_at=%s WHERE id=%s', [priority,active,now,source_id])
        else:
            cursor.execute('''INSERT INTO public.product_supplier_sources(product_id,supplier_catalog_item_id,priority,is_active,version,created_at,updated_at)
                VALUES (%s,%s,%s,%s,1,%s,%s) RETURNING id''',[product_id,catalog_id,priority,active,now,now])
            source_id = cursor.fetchone()[0]
        cursor.execute('''INSERT INTO public.supplier_price_policies(product_id,mode,markup_basis_points,profit_idr,minimum_profit_idr,rounding_idr,version,created_at,updated_at)
            VALUES (%s,'manual',0,0,0,1,1,%s,%s) ON CONFLICT (product_id) DO NOTHING''',[product_id,now,now])
        cursor.execute('UPDATE public.suppliers SET version=version+1,updated_at=%s WHERE id=%s',[now,supplier_id])
        cursor.execute('UPDATE public.products SET supplier_available_until=NULL WHERE id=%s',[product_id])
        _audit(cursor,actor,'supplier.source_saved','ProductSupplierSource',source_id,{'product_id':product_id,'catalog_item_id':catalog_id,'priority':priority,'is_active':active,'version':version+1},now)
        return source_id


def save_policy(product_id, policy, version, actor, now, *, using='default'):
    validate_policy(policy)
    with transaction.atomic(using=using), connections[using].cursor() as cursor:
        _authorize(cursor,actor)
        cursor.execute('SELECT id,usdt_price_amount FROM public.products WHERE id=%s FOR UPDATE',[product_id])
        product = cursor.fetchone()
        if not product:
            raise SupplierManagementError('unknown product')
        cursor.execute('''SELECT id FROM public.suppliers WHERE id IN (SELECT c.supplier_id FROM public.supplier_catalog_items c
            JOIN public.product_supplier_sources s ON s.supplier_catalog_item_id=c.id WHERE s.product_id=%s) ORDER BY id FOR UPDATE''',[product_id])
        supplier_ids = [row[0] for row in cursor.fetchall()]
        cursor.execute('SELECT id,version,mode,markup_basis_points,profit_idr,minimum_profit_idr,rounding_idr FROM public.supplier_price_policies WHERE product_id=%s FOR UPDATE',[product_id])
        old=cursor.fetchone()
        if (old[1] if old else 0) != version:
            raise SupplierManagementError('stale policy version')
        if old and old[2:] == tuple(policy[name] for name in ('mode','markup_basis_points','profit_idr','minimum_profit_idr','rounding_idr')):
            return old[0]
        if policy['mode'] != 'manual':
            cursor.execute('''SELECT c.unit_price_micros,c.price_tiers,c.bulk_discounts,s.currency,s.exchange_rate_idr
                FROM public.product_supplier_sources ps
                JOIN public.supplier_catalog_items c ON c.id=ps.supplier_catalog_item_id
                JOIN public.suppliers s ON s.id=c.supplier_id
                WHERE ps.product_id=%s AND ps.is_active AND s.is_active AND s.currency_confirmed
                AND s.sync_expires_at>%s AND c.orderable AND NOT c.requires_input
                AND (c.currency IS NULL OR c.currency=s.currency)
                ORDER BY ps.priority,ps.id''', [product_id,now])
            for unit, tiers, discounts, currency, rate in cursor.fetchall():
                try:
                    micros = cost_micros({'unit_price_micros':unit,'price_tiers':tiers,'bulk_discounts':discounts}, 1)
                except (ValueError, TypeError, KeyError):
                    continue
                base = retail_price(policy, cost_idr(micros, currency, rate))
                cursor.execute('''SELECT minimum_quantity,price_amount,usdt_price_amount FROM public.product_quantity_price_tiers
                    WHERE product_id=%s ORDER BY minimum_quantity,id''', [product_id])
                validate_retail_schedule(base, product[1], cursor.fetchall())
                break
        if not old:
            cursor.execute('''INSERT INTO public.supplier_price_policies(product_id,mode,markup_basis_points,profit_idr,minimum_profit_idr,rounding_idr,version,created_at,updated_at)
                VALUES (%s,%s,%s,%s,%s,%s,1,%s,%s) RETURNING id''', [product_id,policy['mode'],policy['markup_basis_points'],policy['profit_idr'],policy['minimum_profit_idr'],policy['rounding_idr'],now,now])
            policy_id=cursor.fetchone()[0]
        else:
            policy_id=old[0]
            cursor.execute('''UPDATE public.supplier_price_policies SET mode=%s,markup_basis_points=%s,profit_idr=%s,
                minimum_profit_idr=%s,rounding_idr=%s,version=version+1,updated_at=%s WHERE id=%s''',
                [policy['mode'],policy['markup_basis_points'],policy['profit_idr'],policy['minimum_profit_idr'],policy['rounding_idr'],now,policy_id])
        cursor.execute('UPDATE public.suppliers SET version=version+1,updated_at=%s WHERE id=ANY(%s)',[now,supplier_ids])
        cursor.execute('UPDATE public.products SET supplier_available_until=NULL WHERE id=%s',[product_id])
        _audit(cursor,actor,'supplier.pricing_saved','SupplierPricePolicy',policy_id,{'product_id':product_id,'mode':policy['mode'],'version':version+1},now)
        return policy_id


def preview_price(product_id, catalog_id, policy, quantity, actor, *, using='default'):
    validate_policy(policy)
    with transaction.atomic(using=using), connections[using].cursor() as cursor:
        _authorize(cursor, actor)
        cursor.execute('SELECT price_amount,usdt_price_amount FROM public.products WHERE id=%s', [product_id])
        product = cursor.fetchone()
        cursor.execute('''SELECT c.unit_price_micros,c.price_tiers,c.bulk_discounts,s.currency,s.exchange_rate_idr
            FROM public.supplier_catalog_items c JOIN public.suppliers s ON s.id=c.supplier_id WHERE c.id=%s''', [catalog_id])
        row = cursor.fetchone()
        if not product or not row:
            raise SupplierManagementError('unknown product or catalog item')
        cursor.execute('''SELECT minimum_quantity,price_amount,usdt_price_amount FROM public.product_quantity_price_tiers
            WHERE product_id=%s ORDER BY minimum_quantity,id''', [product_id])
        return preview({'unit_price_micros':row[0],'price_tiers':row[1],'bulk_discounts':row[2]},
                       {'currency':row[3],'exchange_rate_idr':row[4]},
                       {'price_amount':product[0],'usdt_price_amount':product[1]}, policy, cursor.fetchall(), quantity)


def import_draft(catalog_id, supplier_version, name, description, price_amount, actor, now, *, using='default'):
    """Import only operator-authored copy into an inactive store-owned Product."""
    if (not isinstance(name, str) or not name or len(name) > 255
            or not isinstance(description, str) or type(price_amount) is not int or price_amount <= 0):
        raise SupplierManagementError('invalid draft')
    with transaction.atomic(using=using), connections[using].cursor() as cursor:
        _authorize(cursor, actor)
        cursor.execute('SELECT supplier_id FROM public.supplier_catalog_items WHERE id=%s', [catalog_id])
        found = cursor.fetchone()
        if not found:
            raise SupplierManagementError('unknown catalog item')
        supplier_id = found[0]
        cursor.execute('SELECT product_id FROM public.product_supplier_sources WHERE supplier_catalog_item_id=%s ORDER BY product_id', [catalog_id])
        product_ids = [row[0] for row in cursor.fetchall()]
        cursor.execute('SELECT id FROM public.products WHERE id=ANY(%s) ORDER BY id FOR UPDATE', [product_ids])
        cursor.fetchall()
        cursor.execute('SELECT version FROM public.suppliers WHERE id=%s FOR UPDATE', [supplier_id])
        supplier = cursor.fetchone()
        cursor.execute('SELECT supplier_id FROM public.supplier_catalog_items WHERE id=%s FOR UPDATE', [catalog_id])
        sku = cursor.fetchone()
        if not supplier or not sku or sku[0] != supplier_id:
            raise SupplierManagementError('unknown catalog item')
        cursor.execute('SELECT product_id FROM public.product_supplier_sources WHERE supplier_catalog_item_id=%s ORDER BY product_id', [catalog_id])
        current = [row[0] for row in cursor.fetchall()]
        if current != product_ids:
            raise SupplierManagementError('mapping changed')
        if current:
            return current[0]
        if supplier[0] != supplier_version:
            raise SupplierManagementError('stale supplier version')
        cursor.execute('''INSERT INTO public.products(name,description,price_amount,currency,stock,reserved_stock,is_active,created_at,updated_at)
            VALUES (%s,%s,%s,'IDR',0,0,false,%s,%s) RETURNING id''', [name,description,price_amount,now,now])
        product_id = cursor.fetchone()[0]
        cursor.execute('''INSERT INTO public.product_supplier_sources(product_id,supplier_catalog_item_id,priority,is_active,version,created_at,updated_at)
            VALUES (%s,%s,100,false,1,%s,%s) RETURNING id''', [product_id,catalog_id,now,now])
        source_id = cursor.fetchone()[0]
        cursor.execute('''INSERT INTO public.supplier_price_policies(product_id,mode,markup_basis_points,profit_idr,minimum_profit_idr,rounding_idr,version,created_at,updated_at)
            VALUES (%s,'manual',0,0,0,1,1,%s,%s)''', [product_id,now,now])
        cursor.execute('UPDATE public.suppliers SET version=version+1,updated_at=%s WHERE id=%s',[now,supplier_id])
        _audit(cursor,actor,'supplier.product_imported','Product',product_id,{'catalog_item_id':catalog_id,'source_id':source_id},now)
        return product_id


def detach_source(product_id, catalog_id, source_id, version, supplier_version, actor, cipher, now, *, using='default'):
    """Detach only when no retained Product fulfillment mentions this SKU."""
    with transaction.atomic(using=using), connections[using].cursor() as cursor:
        _authorize(cursor, actor)
        cursor.execute('SELECT id FROM public.products WHERE id=%s FOR UPDATE', [product_id])
        if not cursor.fetchone():
            raise SupplierManagementError('unknown product')
        cursor.execute('SELECT supplier_id FROM public.supplier_catalog_items WHERE id=%s', [catalog_id])
        sku = cursor.fetchone()
        if not sku:
            raise SupplierManagementError('unknown catalog item')
        supplier_id = sku[0]
        cursor.execute('SELECT version FROM public.suppliers WHERE id=%s FOR UPDATE', [supplier_id])
        supplier = cursor.fetchone()
        if not supplier or supplier[0] != supplier_version:
            raise SupplierManagementError('stale supplier version')
        cursor.execute('SELECT id FROM public.supplier_catalog_items WHERE id=%s AND supplier_id=%s FOR UPDATE', [catalog_id,supplier_id])
        if not cursor.fetchone():
            raise SupplierManagementError('unknown catalog item')
        cursor.execute('''SELECT id,version,supplier_catalog_item_id FROM public.product_supplier_sources
            WHERE product_id=%s ORDER BY priority,id FOR UPDATE''', [product_id])
        sources = cursor.fetchall()
        target = next((row for row in sources if row[0] == source_id and row[2] == catalog_id), None)
        if not target or target[1] != version:
            raise SupplierManagementError('stale mapping version')
        cursor.execute('''SELECT f.sources_snapshot, f.reserved_catalog_item_id FROM public.supplier_fulfillments f
            JOIN public.order_items i ON i.id=f.order_item_id WHERE i.product_id=%s ORDER BY f.id''', [product_id])
        for encrypted, reserved_id in cursor.fetchall():
            if reserved_id == catalog_id:
                raise SupplierManagementError('mapping has fulfillment history')
            try:
                snapshot = cipher.decrypt_json(encrypted)
                if not isinstance(snapshot, list):
                    raise ValueError()
                for entry in snapshot:
                    value = entry['catalog_item_id']
                    if isinstance(value, bool) or not str(value).isdigit() or int(value) <= 0:
                        raise ValueError()
                    if int(value) == catalog_id:
                        raise SupplierManagementError('mapping has fulfillment history')
            except SupplierManagementError:
                raise
            except Exception:
                raise SupplierManagementError('unreadable fulfillment history') from None
        cursor.execute('''SELECT EXISTS(SELECT 1 FROM public.supplier_purchases p
            JOIN public.supplier_fulfillments f ON f.id=p.supplier_fulfillment_id
            JOIN public.order_items i ON i.id=f.order_item_id WHERE i.product_id=%s AND p.supplier_catalog_item_id=%s)''', [product_id,catalog_id])
        if cursor.fetchone()[0]:
            raise SupplierManagementError('mapping has purchase history')
        _audit(cursor,actor,'supplier.source_detached','ProductSupplierSource',source_id,
               {'source_id':source_id,'product_id':product_id,'supplier_id':supplier_id,'catalog_item_id':catalog_id,'version':version},now)
        cursor.execute('DELETE FROM public.product_supplier_sources WHERE id=%s', [source_id])
        for rank, row in enumerate((row for row in sources if row[0] != source_id), 1):
            cursor.execute('''UPDATE public.product_supplier_sources SET priority=%s,version=version+1,updated_at=%s
                WHERE id=%s AND priority<>%s''', [rank*10,now,row[0],rank*10])
        cursor.execute('UPDATE public.suppliers SET version=version+1,updated_at=%s WHERE id=%s',[now,supplier_id])
        cursor.execute('UPDATE public.products SET supplier_available_until=NULL WHERE id=%s',[product_id])


def move_source(product_id, catalog_id, source_id, version, supplier_version, direction, actor, now, *, using='default'):
    if direction not in ('up', 'down'):
        raise SupplierManagementError('invalid move direction')
    with transaction.atomic(using=using), connections[using].cursor() as cursor:
        _authorize(cursor, actor)
        cursor.execute('SELECT id FROM public.products WHERE id=%s FOR UPDATE', [product_id])
        if not cursor.fetchone():
            raise SupplierManagementError('unknown product')
        cursor.execute('SELECT supplier_id FROM public.supplier_catalog_items WHERE id=%s', [catalog_id])
        sku = cursor.fetchone()
        if not sku:
            raise SupplierManagementError('unknown catalog item')
        supplier_id = sku[0]
        cursor.execute('SELECT version FROM public.suppliers WHERE id=%s FOR UPDATE', [supplier_id])
        supplier = cursor.fetchone()
        if not supplier or supplier[0] != supplier_version:
            raise SupplierManagementError('stale supplier version')
        cursor.execute('SELECT id FROM public.supplier_catalog_items WHERE id=%s AND supplier_id=%s FOR UPDATE', [catalog_id, supplier_id])
        if not cursor.fetchone():
            raise SupplierManagementError('unknown catalog item')
        cursor.execute('''SELECT id,version,supplier_catalog_item_id FROM public.product_supplier_sources
            WHERE product_id=%s ORDER BY priority,id FOR UPDATE''', [product_id])
        sources = list(cursor.fetchall())
        index = next((i for i, row in enumerate(sources) if row[0] == source_id and row[2] == catalog_id), None)
        if index is None or sources[index][1] != version:
            raise SupplierManagementError('stale mapping version')
        neighbor = index + (-1 if direction == 'up' else 1)
        if not 0 <= neighbor < len(sources):
            return
        sources[index], sources[neighbor] = sources[neighbor], sources[index]
        for rank, row in enumerate(sources, 1):
            cursor.execute('''UPDATE public.product_supplier_sources SET priority=%s,version=version+1,updated_at=%s
                WHERE id=%s AND priority<>%s''', [rank*10,now,row[0],rank*10])
        cursor.execute('UPDATE public.suppliers SET version=version+1,updated_at=%s WHERE id=%s',[now,supplier_id])
        cursor.execute('UPDATE public.products SET supplier_available_until=NULL WHERE id=%s',[product_id])
        _audit(cursor,actor,'supplier.source_moved','ProductSupplierSource',source_id,
               {'product_id':product_id,'catalog_item_id':catalog_id,'direction':direction,'position':neighbor+1},now)


def delete_connection(supplier_id, version, actor, cipher, now, *, using='default'):
    with transaction.atomic(using=using), connections[using].cursor() as cursor:
        _authorize(cursor, actor)
        cursor.execute('''SELECT provider,version,reserved_balance_micros,sync_token,sync_lease_expires_at
            FROM public.suppliers WHERE id=%s FOR UPDATE''', [supplier_id])
        supplier = cursor.fetchone()
        if not supplier or supplier[1] != version:
            raise SupplierManagementError('stale supplier version')
        if supplier[3] is not None and supplier[4] is not None and supplier[4] > now:
            raise SupplierManagementError('supplier sync is running')
        cursor.execute('SELECT id,reserved_quantity FROM public.supplier_catalog_items WHERE supplier_id=%s ORDER BY id FOR UPDATE', [supplier_id])
        catalog = cursor.fetchall()
        ids = [row[0] for row in catalog]
        if supplier[2] or any(row[1] for row in catalog):
            raise SupplierManagementError('supplier has reservations')
        cursor.execute('''SELECT EXISTS(SELECT 1 FROM public.product_supplier_sources WHERE supplier_catalog_item_id=ANY(%s))
            OR EXISTS(SELECT 1 FROM public.supplier_purchases WHERE supplier_id=%s OR supplier_catalog_item_id=ANY(%s))
            OR EXISTS(SELECT 1 FROM public.supplier_fulfillments WHERE reserved_supplier_id=%s OR reserved_catalog_item_id=ANY(%s))
            OR EXISTS(SELECT 1 FROM public.supplier_account_units WHERE supplier_id=%s)
            OR EXISTS(SELECT 1 FROM public.supplier_deposits WHERE supplier_id=%s)
            OR EXISTS(SELECT 1 FROM public.supplier_webhook_events WHERE supplier_id=%s)''',
            [ids,supplier_id,ids,supplier_id,ids,supplier_id,supplier_id,supplier_id])
        if cursor.fetchone()[0]:
            raise SupplierManagementError('supplier has mappings or retained history')
        if ids:
            cursor.execute('SELECT sources_snapshot FROM public.supplier_fulfillments ORDER BY id')
            while batch := cursor.fetchmany(200):
                for (encrypted,) in batch:
                    try:
                        snapshot = cipher.decrypt_json(encrypted)
                        if not isinstance(snapshot, list):
                            raise ValueError()
                        for entry in snapshot:
                            value = entry['catalog_item_id']
                            if isinstance(value, bool) or not (isinstance(value, int) or isinstance(value, str) and value.isascii() and value.isdigit()) or int(value) <= 0:
                                raise ValueError()
                            if int(value) in ids:
                                raise SupplierManagementError('supplier has retained history')
                    except SupplierManagementError:
                        raise
                    except Exception:
                        raise SupplierManagementError('unreadable fulfillment history') from None
        _audit(cursor,actor,'supplier.deleted','Supplier',supplier_id,
               {'supplier_id':supplier_id,'provider':supplier[0],'version':version},now)
        cursor.execute('DELETE FROM public.supplier_catalog_items WHERE supplier_id=%s', [supplier_id])
        cursor.execute('DELETE FROM public.suppliers WHERE id=%s', [supplier_id])


def save_rates(rates, actor, now, *, using='default', source='admin'):
    if not isinstance(rates, dict) or not rates or set(rates) - {'USD','VND'}:
        raise SupplierManagementError('unsupported currency rates')
    values = {}
    for currency, entry in rates.items():
        if not isinstance(entry, dict) or type(entry.get('version')) is not int or entry['version'] < 0:
            raise SupplierManagementError('invalid rate version')
        values[currency] = rate_value(currency, entry.get('exchange_rate_idr'))
    with transaction.atomic(using=using), connections[using].cursor() as cursor:
        _authorize(cursor, actor)
        stored = {}
        for currency in sorted(values):
            cursor.execute('''INSERT INTO public.supplier_currency_rates(currency,exchange_rate_idr,version,created_at,updated_at)
                VALUES (%s,%s,0,%s,%s) ON CONFLICT (currency) DO NOTHING''', [currency,values[currency],now,now])
            cursor.execute('SELECT exchange_rate_idr,version FROM public.supplier_currency_rates WHERE currency=%s FOR UPDATE', [currency])
            stored[currency] = cursor.fetchone()
            if stored[currency][1] != rates[currency]['version']:
                raise SupplierManagementError('stale currency rate version')
        currencies = ['USD','USDT'] if 'USD' in values else []
        if 'VND' in values:
            currencies.append('VND')
        cursor.execute('SELECT id,currency,exchange_rate_idr FROM public.suppliers WHERE currency=ANY(%s) ORDER BY id', [currencies])
        pre = cursor.fetchall()
        ids = [row[0] for row in pre]
        cursor.execute('''SELECT DISTINCT ps.product_id FROM public.product_supplier_sources ps
            JOIN public.supplier_catalog_items c ON c.id=ps.supplier_catalog_item_id
            WHERE c.supplier_id=ANY(%s) ORDER BY ps.product_id''', [ids])
        products = [row[0] for row in cursor.fetchall()]
        cursor.execute('SELECT id FROM public.products WHERE id=ANY(%s) ORDER BY id FOR UPDATE', [products])
        cursor.fetchall()
        cursor.execute('SELECT id,currency,exchange_rate_idr FROM public.suppliers WHERE id=ANY(%s) ORDER BY id FOR UPDATE', [ids])
        suppliers = cursor.fetchall()
        cursor.execute('''SELECT DISTINCT ps.product_id FROM public.product_supplier_sources ps
            JOIN public.supplier_catalog_items c ON c.id=ps.supplier_catalog_item_id
            WHERE c.supplier_id=ANY(%s) ORDER BY ps.product_id''', [ids])
        if [row[0] for row in cursor.fetchall()] != products:
            raise SupplierManagementError('mapping changed')
        touched = 0
        for currency in sorted(values):
            value = values[currency]
            old, version = stored[currency]
            targets = [row for row in suppliers if row[1] in (('USD','USDT') if currency == 'USD' else ('VND',))]
            drift = [row for row in targets if row[2] != value]
            changed = version == 0 or old != value
            if not changed and not drift:
                continue
            if changed:
                cursor.execute('''UPDATE public.supplier_currency_rates SET exchange_rate_idr=%s,version=version+1,updated_at=%s
                    WHERE currency=%s''', [value,now,currency])
            if drift:
                cursor.execute('''UPDATE public.suppliers SET exchange_rate_idr=%s,sync_token=NULL,last_error=NULL,
                    version=version+1,updated_at=%s WHERE id=ANY(%s)''', [value,now,[row[0] for row in drift]])
            _audit(cursor,actor,'supplier.rate_corrected','SupplierCurrencyRate',currency,
                   {'exchange_rate_idr':str(value),'version':version+int(changed),'command':source},now)
            touched += 1
        if touched:
            cursor.execute('UPDATE public.products SET supplier_available_until=NULL WHERE id=ANY(%s)', [products])
        return touched
