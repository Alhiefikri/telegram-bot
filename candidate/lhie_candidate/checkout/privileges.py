"""Column write allowlist for explicit disposable provisioning, never startup grants."""
NAVIGATION_INSERT = {'customers': {'telegram_id', 'first_name', 'username', 'locale', 'created_at', 'updated_at'}}
NAVIGATION_UPDATE = {'customers': {'first_name', 'username', 'locale', 'updated_at'}}
CHECKOUT_INSERT = {
    'orders': set('id customer_id cart_id checkout_key invoice_number order_status payment_status total_amount currency expires_at terms_version terms_accepted_at balance_checkout_snapshot created_at updated_at'.split()),
    'payments': set('id order_id provider merchant_ref method amount currency status expired_at binance_verification_status created_at updated_at'.split()),
    'order_items': set('order_id product_id product_name product_icon product_custom_emoji_id family_id family_name delivery_note delivery_note_snapshotted usage_guide_title usage_guide_url usage_guide_snapshotted quantity local_quantity supplier_quantity quantity_price_tier_minimum unit_price subtotal created_at updated_at'.split()),
    'inventory_movements': set('product_id order_id type quantity stock_before became_unavailable notes created_at'.split()),
    'customer_balance_accounts': set('customer_id balance created_at updated_at'.split()),
    'store_balance_entries': set('customer_balance_account_id kind amount balance_after source_type source_id idempotency_key created_at refund_order_id'.split()),
}
CHECKOUT_UPDATE = {
    'carts': {'status', 'updated_at'},
    'cart_items': {'updated_at'},
    'products': {'stock', 'reserved_stock', 'updated_at'},
    'product_stock_items': {'order_item_id', 'retired_at', 'retired_reason', 'updated_at'},
    'customer_balance_accounts': {'balance', 'updated_at'},
    'orders': {'order_status', 'payment_status', 'updated_at'},
    'payments': {'status', 'paid_at', 'updated_at'},
    # PostgreSQL row locking requires UPDATE on at least one column. No setting
    # value, guard state, identity, or version is writable by the candidate.
    'customer_usdt_funding_policies': {'updated_at'},
    'balance_restore_gates': {'updated_at'},
    'checkout_maintenance_settings': {'updated_at'},
    'manual_payment_settings': {'updated_at'},
}


def verify_public_writes(cursor):
    cursor.execute("SELECT has_column_privilege(current_user,'public.orders','checkout_key','INSERT')")
    checkout = cursor.fetchone()[0]
    allowed_insert = NAVIGATION_INSERT | (CHECKOUT_INSERT if checkout else {})
    allowed_update = NAVIGATION_UPDATE | (CHECKOUT_UPDATE if checkout else {})
    cursor.execute('''SELECT c.relname FROM pg_catalog.pg_class c
        JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname='public' AND c.relkind IN ('r','p')
        AND has_table_privilege(current_user,c.oid,'INSERT,UPDATE,DELETE,TRUNCATE,TRIGGER')''')
    if cursor.fetchone() is not None:
        return False
    cursor.execute('''SELECT c.relname,a.attname,
        has_column_privilege(current_user,c.oid,a.attnum,'INSERT'),
        has_column_privilege(current_user,c.oid,a.attnum,'UPDATE')
        FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
        JOIN pg_catalog.pg_attribute a ON a.attrelid=c.oid
        WHERE n.nspname='public' AND c.relkind IN ('r','p')
        AND a.attnum>0 AND NOT a.attisdropped''')
    for table, column, insert, update in cursor.fetchall():
        if insert and column not in allowed_insert.get(table, ()):
            return False
        if update and column not in allowed_update.get(table, ()):
            return False
    return True
