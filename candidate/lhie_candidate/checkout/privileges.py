"""Column write allowlist for explicit disposable provisioning, never startup grants."""
NAVIGATION_INSERT = {'customers': {'telegram_id', 'first_name', 'username', 'locale', 'created_at', 'updated_at'}}
NAVIGATION_UPDATE = {'customers': {'first_name', 'username', 'locale', 'updated_at'}}
CHECKOUT_INSERT = {
    'orders': set('id customer_id cart_id checkout_key invoice_number order_status payment_status total_amount currency expires_at terms_version terms_accepted_at balance_checkout_snapshot created_at updated_at'.split()),
    'payments': set('id order_id provider merchant_ref method amount currency status expired_at sakurupiah_channel binance_verification_status created_at updated_at'.split()),
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

P08_INSERT = {
    'payment_events': set('provider event_type merchant_ref provider_reference provider_status raw_payload idempotency_key received_at last_received_at received_count'.split()),
    'external_money_receipts': set('canonical_key provider identity_kind account_namespace reference beneficiary currency amount_units decimals status target_type target_id fingerprint evidence_payload claimed_at created_at updated_at'.split()),
    'external_money_receipt_aliases': set('external_money_receipt_id alias_key alias_type created_at'.split()),
    'manual_fulfillment_reviews': set('order_id payment_id amount currency reason state provider_reference created_at updated_at'.split()),
}
P08_UPDATE = {
    'payment_events': {'last_received_at', 'received_count', 'processed_at'},
    'external_money_receipts': {'updated_at'},
    'payments': set('provider_reference provider_total_payment provider_fee provider_expires_at provider_payment_method provider_presentation provider_create_state provider_create_attempted_at provider_create_last_error updated_at'.split()),
}

P09_INSERT = {
    'telegram_outbound_effects': set('id effect_key effect_group_key supersedes_effect_id sequence effect_type source_type source_id target_type telegram_chat_id queue telegram_method locale template_version state attempts next_attempt_at failed_at last_error_code last_error outcome_metadata created_at updated_at'.split()),
}
P09_UPDATE = {
    'telegram_outbound_effects': set('state attempts next_attempt_at lease_until claim_token telegram_message_id sent_at unknown_at failed_at manual_review_at last_error_code last_error outcome_metadata updated_at'.split()),
    'orders': {'telegram_receipt_sent_at'},
}


def verify_public_writes(cursor):
    cursor.execute("SELECT has_column_privilege(current_user,'public.orders','checkout_key','INSERT')")
    checkout = cursor.fetchone()[0]
    allowed_insert = NAVIGATION_INSERT | (CHECKOUT_INSERT if checkout else {})
    allowed_update = NAVIGATION_UPDATE | (CHECKOUT_UPDATE if checkout else {})
    cursor.execute("SELECT has_column_privilege(current_user,'public.payments','provider_reference','UPDATE')")
    if cursor.fetchone()[0]:
        allowed_insert = {**allowed_insert, **P08_INSERT}
        allowed_update = {**allowed_update, **{
            table: allowed_update.get(table, set()) | columns for table, columns in P08_UPDATE.items()
        }}
    cursor.execute("SELECT has_column_privilege(current_user,'public.telegram_outbound_effects','effect_key','INSERT')")
    if cursor.fetchone()[0]:
        allowed_insert = {**allowed_insert, **P09_INSERT}
        allowed_update = {**allowed_update, **{
            table: allowed_update.get(table, set()) | columns for table, columns in P09_UPDATE.items()
        }}
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
