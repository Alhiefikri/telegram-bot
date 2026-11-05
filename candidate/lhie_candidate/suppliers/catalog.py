"""Fenced supplier catalog observations, without provider calls under DB locks."""

from datetime import timedelta
import hmac
import json
from uuid import uuid4
from django.db import connections, transaction
from .pricing import PROVIDERS
from .capacity import lock_checkout_resources, refresh_locked


class CatalogSyncError(ValueError):
    pass


def sync(supplier_id, client, now, *, encrypter, using='default'):
    """Read a snapshot outside transactions, then apply it under a durable lease.

    The client must match the persisted private connection, including its key.
    Returns False when the lease or identity fence loses; transport errors propagate.
    """
    db = connections[using]
    if db.in_atomic_block:
        raise CatalogSyncError('supplier HTTP cannot run inside a transaction')
    token = str(uuid4())
    with transaction.atomic(using=using), db.cursor() as cursor:
        cursor.execute('''UPDATE public.suppliers SET sync_token=%s,sync_attempted_at=%s,
            sync_lease_expires_at=%s WHERE id=%s AND is_active
            AND provider = ANY(%s) AND (sync_token IS NULL OR sync_lease_expires_at<=%s)
            AND (sync_failures=0 OR sync_next_attempt_at IS NULL OR sync_next_attempt_at<=%s)
            RETURNING version,currency,provider,api_key''', [token, now, now + timedelta(minutes=1), supplier_id,
                                                   list(PROVIDERS), now, now])
        row = cursor.fetchone()
    if row is None:
        return False
    version, currency, provider, encrypted_key = row
    try:
        config = getattr(client, 'config', None)
        key = encrypter.decrypt_string(encrypted_key)
        if (config is None or config.provider != provider or config.currency != currency or not config.active
                or not isinstance(config.api_key, str) or not isinstance(key, str)
                or not hmac.compare_digest(config.api_key.encode(), key.encode())):
            raise CatalogSyncError('supplier connection mismatch')
        snapshot = client.snapshot()
        if snapshot.currency != currency:
            raise CatalogSyncError('supplier currency mismatch')
        products = tuple(snapshot.products)
        ids = [item.external_id for item in products]
        if (len(set(ids)) != len(ids) or any(type(value) is not str or not value or len(value) > 150 for value in ids)
                or type(snapshot.balance_micros) is not int or snapshot.balance_micros < 0):
            raise CatalogSyncError('invalid catalog observation')
        with db.cursor() as cursor:
            cursor.execute('''SELECT DISTINCT s.product_id FROM public.product_supplier_sources s
                JOIN public.supplier_catalog_items c ON c.id=s.supplier_catalog_item_id
                WHERE c.supplier_id=%s ORDER BY s.product_id''', [supplier_id])
            product_ids = [row[0] for row in cursor.fetchall()]
        with transaction.atomic(using=using), db.cursor() as cursor:
            cursor.execute('SELECT id FROM public.products WHERE id=ANY(%s) ORDER BY id FOR NO KEY UPDATE', [product_ids])
            cursor.fetchall()
            lock_checkout_resources(product_ids, using=using, catalog_supplier_ids=[supplier_id])
            cursor.execute('''SELECT id FROM public.suppliers WHERE id=%s AND is_active AND version=%s
                AND sync_token=%s AND sync_lease_expires_at>CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta' FOR UPDATE''', [supplier_id, version, token])
            if cursor.fetchone() is None:
                return False
            cursor.execute('''SELECT DISTINCT s.product_id FROM public.product_supplier_sources s
                JOIN public.supplier_catalog_items c ON c.id=s.supplier_catalog_item_id
                WHERE c.supplier_id=%s ORDER BY s.product_id''', [supplier_id])
            if [row[0] for row in cursor.fetchall()] != product_ids:
                cursor.execute('''UPDATE public.suppliers SET sync_token=NULL,sync_lease_expires_at=NULL
                    WHERE id=%s AND version=%s AND sync_token=%s''', [supplier_id, version, token])
                return False
            cursor.execute("SELECT CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'")
            observed_at = cursor.fetchone()[0]
            for item in products:
                cursor.execute('''INSERT INTO public.supplier_catalog_items
                    (supplier_id,external_id,name,description,currency,unit_price_micros,stock,orderable,
                     requires_input,price_tiers,bulk_discounts,synced_at,created_at,updated_at)
                    VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s::json,%s::json,%s,%s,%s)
                    ON CONFLICT (supplier_id,external_id) DO UPDATE SET name=EXCLUDED.name,
                    description=EXCLUDED.description,currency=EXCLUDED.currency,unit_price_micros=EXCLUDED.unit_price_micros,
                    stock=EXCLUDED.stock,orderable=EXCLUDED.orderable,requires_input=EXCLUDED.requires_input,
                    price_tiers=EXCLUDED.price_tiers,bulk_discounts=EXCLUDED.bulk_discounts,
                    synced_at=EXCLUDED.synced_at,updated_at=EXCLUDED.updated_at''',
                    [supplier_id,item.external_id,item.name,item.description,currency,item.unit_price_micros,
                     item.stock,item.orderable,item.requires_input,
                     json.dumps([{'min': low, 'max': high, 'unit_price_micros': price}
                                 for low, high, price in item.tiers]),
                     json.dumps([{'min': minimum, 'percent_basis_points': percent}
                                 for minimum, percent in item.discounts]),observed_at,observed_at,observed_at])
            cursor.execute('''UPDATE public.supplier_catalog_items SET stock=0,orderable=false,synced_at=%s,updated_at=%s
                WHERE supplier_id=%s AND NOT (external_id = ANY(%s))''', [observed_at,observed_at,supplier_id,ids])
            cursor.execute('''SELECT EXISTS(SELECT 1 FROM public.supplier_purchases WHERE supplier_id=%s
                AND committed_at IS NULL AND state IN ('sending','ambiguous','awaiting_delivery','review'))''', [supplier_id])
            blocked = cursor.fetchone()[0]
            cursor.execute('''UPDATE public.suppliers SET balance_micros=CASE WHEN %s THEN balance_micros ELSE %s END,
                balance_observed_at=CASE WHEN %s THEN balance_observed_at ELSE %s END,
                synced_at=%s,sync_expires_at=%s,sync_next_attempt_at=%s,sync_failures=0,
                sync_token=NULL,sync_lease_expires_at=NULL,last_error=NULL,version=version+1,updated_at=%s WHERE id=%s''',
                [blocked,snapshot.balance_micros,blocked,observed_at,observed_at,observed_at+timedelta(seconds=300),
                 observed_at+timedelta(seconds=max(60, ((len(products) + 2) * 5) if provider == 'prodseller' else 60)),
                 observed_at,supplier_id])
            refresh_locked(product_ids, observed_at, using=using)
        return True
    except Exception:
        with transaction.atomic(using=using), db.cursor() as cursor:
            cursor.execute('''UPDATE public.suppliers SET sync_token=NULL,sync_lease_expires_at=NULL,
                sync_failures=LEAST(10,sync_failures+1),
                sync_next_attempt_at=(CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')
                    + LEAST(1800,120 * (1 << LEAST(9,sync_failures))) * INTERVAL '1 second',
                last_error='sync_failed',updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
                WHERE id=%s AND version=%s AND sync_token=%s''',
                [supplier_id,version,token])
        raise
