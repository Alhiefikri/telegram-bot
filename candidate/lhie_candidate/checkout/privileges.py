"""Column write allowlist for explicit disposable provisioning, never startup grants."""
NAVIGATION_INSERT = {'customers': {'telegram_id', 'first_name', 'username', 'locale', 'created_at', 'updated_at'}}
NAVIGATION_UPDATE = {'customers': {'first_name', 'username', 'locale', 'updated_at'}}
CHECKOUT_INSERT = {
    'carts': set('customer_id origin status expires_at created_at updated_at'.split()),
    'cart_items': set('cart_id product_id quantity created_at updated_at'.split()),
    'orders': set('id customer_id cart_id checkout_key invoice_number order_status payment_status total_amount currency expires_at terms_version terms_accepted_at balance_checkout_snapshot created_at updated_at'.split()),
    'payments': set('id order_id provider merchant_ref method amount currency status expired_at sakurupiah_channel binance_auto_verification_enabled binance_verification_mode binance_receiver_type binance_receiver_value binance_verification_status created_at updated_at'.split()),
    'order_items': set('order_id product_id product_name product_icon product_custom_emoji_id family_id family_name delivery_note delivery_note_snapshotted usage_guide_title usage_guide_url usage_guide_snapshotted quantity local_quantity supplier_quantity quantity_price_tier_minimum unit_price usdt_unit_price subtotal usdt_subtotal created_at updated_at'.split()),
    'inventory_movements': set('product_id order_id type quantity stock_before became_unavailable notes created_at'.split()),
    'customer_balance_accounts': set('customer_id balance created_at updated_at'.split()),
    'store_balance_entries': set('customer_balance_account_id kind amount balance_after source_type source_id idempotency_key created_at refund_order_id'.split()),
    'customer_usdt_balance_accounts': set('customer_id currency balance_micro version created_at updated_at'.split()),
    'customer_usdt_balance_entries': set('customer_usdt_balance_account_id account_sequence kind amount_micro balance_after_micro source_type source_id idempotency_key request_fingerprint original_entry_id refund_order_id created_at'.split()),
    'customer_usdt_balance_accounting_events': set('customer_usdt_balance_entry_id currency amount_micro debit_account credit_account source_fingerprint created_at'.split()),
    'payment_bsc_details': set('payment_id receiver_id receiver_address chain_id token_contract token_decimals base_amount_micro payable_amount_micro expected_token_units verification_status verification_mode required_confirmations requires_finalized_block confirmation_grace_minutes created_at updated_at'.split()),
}
CHECKOUT_UPDATE = {
    'carts': {'status', 'updated_at'},
    'cart_items': {'updated_at'},
    'products': {'stock', 'reserved_stock', 'updated_at'},
    'product_stock_items': {'order_item_id', 'retired_at', 'retired_reason', 'updated_at'},
    'customer_balance_accounts': {'balance', 'updated_at'},
    'customer_usdt_balance_accounts': {'balance_micro', 'version', 'last_entry_id', 'updated_at'},
    'orders': {'order_status', 'payment_status', 'updated_at'},
    'payments': set('status paid_at updated_at binance_verification_status'.split()),
    'bsc_payment_receivers': {'last_assigned_at'},
    # PostgreSQL row locking requires UPDATE on at least one column. No setting
    # value, guard state, identity, or version is writable by the candidate.
    'customer_usdt_funding_policies': {'updated_at'},
    'balance_restore_gates': {'updated_at'},
    'checkout_maintenance_settings': {'updated_at'},
    'manual_payment_settings': {'updated_at'},
}

P08_INSERT = {
    'payment_events': set('payment_id provider event_type merchant_ref provider_reference provider_status raw_payload idempotency_key received_at last_received_at received_count processed_at'.split()),
    'external_money_receipts': set('canonical_key provider identity_kind account_namespace reference chain_id tx_hash log_index beneficiary currency amount_units decimals status target_type target_id fingerprint evidence_payload claimed_at created_at updated_at'.split()),
    'external_money_receipt_aliases': set('external_money_receipt_id alias_key alias_type created_at'.split()),
    'external_money_receipt_parent_claims': set('chain_id tx_hash external_money_receipt_id status target_type target_id created_at updated_at'.split()),
    'external_money_receipt_reservations': set('source legacy_type legacy_id provider reason details reserved_at created_at updated_at'.split()),
    'bsc_payment_transfer_evidence': set('payment_id tx_hash token_contract receiver_address from_address amount_units block_number block_hash block_timestamp receipt_status log_index confirmations verification_status evidence_payload first_seen_at last_verified_at created_at updated_at'.split()),
    'manual_fulfillment_reviews': set('order_id payment_id amount currency reason state provider_reference created_at updated_at'.split()),
}
P08_UPDATE = {
    'payment_events': {'payment_id', 'last_received_at', 'received_count', 'processed_at'},
    'external_money_receipts': {'updated_at', 'status', 'review_reason', 'claimed_at'},
    'external_money_receipt_parent_claims': {'status', 'review_reason', 'updated_at'},
    'payments': set('provider_reference provider_total_payment provider_fee provider_expires_at provider_payment_method provider_presentation provider_create_state provider_create_attempted_at provider_create_last_error provider_payload provider_qris_url provider_created_at klikqris_transaction_signature klikqris_status_failure_count klikqris_status_next_check_at klikqris_status_last_error payment_proof_file_id payment_proof_submitted_at payment_proof_admin_chat_id binance_order_id binance_order_id_attempts binance_order_id_submission_version binance_order_id_submitted_at binance_verification_grace_until binance_verification_status binance_last_verified_at expired_at updated_at'.split()),
    'payment_bsc_details': set('current_tx_hash tx_submission_version tx_attempts verification_status last_verified_at current_confirmations current_block_number current_from_address evidence_payload verification_grace_until updated_at'.split()),
    'provider_cancellation_attempts': set('state attempt_count provider_response last_error started_at finished_at updated_at'.split()),
}

P09_INSERT = {
    'telegram_outbound_effects': set('id effect_key effect_group_key supersedes_effect_id sequence effect_type source_type source_id target_type telegram_chat_id queue telegram_method locale template_version state attempts next_attempt_at failed_at last_error_code last_error outcome_metadata created_at updated_at'.split()),
}
P09_UPDATE = {
    'telegram_outbound_effects': set('state attempts next_attempt_at lease_until claim_token telegram_message_id sent_at unknown_at failed_at manual_review_at last_error_code last_error outcome_metadata updated_at'.split()),
    'orders': {'telegram_receipt_sent_at'},
}

FUNDING_INSERT = {
    'store_balance_funding_intents': set('customer_id telegram_navigation_root_id chat_id canonical_message_id policy_version amount nonce status expires_at claimed_at created_at updated_at'.split()),
    'store_balance_top_ups': set('customer_id store_balance_funding_intent_id telegram_navigation_root_id chat_id canonical_message_id locale provider payment_setting_id payment_provider_version merchant_ref idempotency_key currency amount sakurupiah_channel status provider_create_state provider_create_attempted_at expires_at created_at updated_at provider_payment_method provider_payload provider_fee provider_total_payment provider_created_at provider_expires_at'.split()),
    'store_balance_top_up_events': set('store_balance_top_up_id provider event_type merchant_ref provider_reference provider_status raw_payload idempotency_key received_count received_at last_received_at processed_at'.split()),
    'external_money_receipts': set('canonical_key provider identity_kind account_namespace reference beneficiary currency amount_units decimals status target_type target_id fingerprint evidence_payload claimed_at created_at updated_at'.split()),
    'external_money_receipt_aliases': set('external_money_receipt_id alias_key alias_type created_at'.split()),
    'customer_balance_accounts': set('customer_id balance created_at updated_at'.split()),
    'store_balance_entries': set('customer_balance_account_id kind amount balance_after source_type source_id idempotency_key created_at refund_order_id'.split()),
    'audit_logs': set('action subject_type subject_id old_values new_values created_at'.split()),
}
FUNDING_UPDATE = {
    'store_balance_funding_policies': {'updated_at'},
    'manual_payment_settings': {'updated_at'},
    'store_balance_funding_intents': {'status', 'claimed_at', 'updated_at'},
    'store_balance_top_ups': set('status provider_reference provider_payload provider_signature provider_qris_url provider_fee provider_total_payment provider_payment_method provider_created_at provider_expires_at provider_create_state provider_create_attempted_at provider_create_last_error paid_at settled_at updated_at payment_proof_file_id payment_proof_submitted_at payment_proof_admin_chat_id'.split()),
    'store_balance_top_up_events': {'received_count', 'last_received_at', 'processed_at'},
    'external_money_receipts': {'updated_at'},
    'external_money_receipt_reservations': {'resolved_at', 'updated_at'},
    'customer_balance_accounts': {'balance', 'updated_at'},
    'customers': {'updated_at'},
}

ADJUSTMENT_INSERT = {
    'balance_adjustment_requests': set('id customer_id owner_user_id telegram_id username session_binding credential_binding mode currency amount reason category source_id receipt_fingerprint fingerprint version state expires_at created_at updated_at'.split()),
    'balance_adjustment_accounting_events': set('request_id ledger_entry_id currency amount debit_account credit_account source_fingerprint created_at'.split()),
    'balance_adjustment_auth_states': {'user_id', 'failed_at'},
    'store_balance_compensations': set('store_balance_top_up_id customer_id owner_user_id ledger_entry_id resolution_type idempotency_key provider_reference reason amount resolved_at created_at'.split()),
    'external_money_receipt_reservations': set('source legacy_type legacy_id provider reason details reserved_at created_at updated_at'.split()),
    'external_money_receipts': set('canonical_key provider identity_kind account_namespace reference chain_id tx_hash log_index beneficiary currency amount_units decimals status target_type target_id fingerprint evidence_payload claimed_at created_at updated_at'.split()),
    'external_money_receipt_aliases': set('external_money_receipt_id alias_key alias_type created_at'.split()),
    'external_money_receipt_parent_claims': set('chain_id tx_hash external_money_receipt_id status target_type target_id created_at updated_at'.split()),
    'audit_logs': set('user_id action subject_type subject_id old_values new_values created_at'.split()),
}
ADJUSTMENT_UPDATE = {
    'balance_adjustment_requests': {'state', 'ledger_entry_id', 'resolved_at', 'updated_at'},
    'balance_adjustment_auth_states': set('failed_at locked_until episode verified_request_id verified_fingerprint lease_token lease_until'.split()),
    'external_money_receipts': {'updated_at', 'status', 'review_reason', 'claimed_at'},
    'external_money_receipt_parent_claims': {'status', 'review_reason', 'updated_at'},
}


LIFECYCLE_INSERT = {
    'audit_logs': set('user_id action subject_type subject_id new_values created_at'.split()),
    'provider_cancellation_attempts': set('order_id payment_id provider state finished_at created_at updated_at'.split()),
    'order_refunds': set('id order_id payment_id customer_id amount currency state external_reference external_reference_hash actor_user_id confirmed_at created_at updated_at'.split()),
    'customer_usdt_balance_entries': set('customer_usdt_balance_account_id account_sequence kind amount_micro balance_after_micro source_type source_id idempotency_key request_fingerprint original_entry_id refund_order_id created_at'.split()),
    'customer_usdt_balance_accounting_events': set('customer_usdt_balance_entry_id currency amount_micro debit_account credit_account source_fingerprint created_at'.split()),
}
LIFECYCLE_UPDATE = {
    'orders': {'checkout_key'},
    'manual_fulfillment_reviews': set('state resolution_reason actor_user_id resolved_at refund_provider_reference refund_provider_result refund_requested_at updated_at'.split()),
    'supplier_fulfillments': set('state next_attempt_at reserved_cost_micros reserved_quantity refunded_quantity last_error updated_at'.split()),
    'supplier_purchases': {'state', 'updated_at'},
    'suppliers': {'reserved_balance_micros', 'version', 'updated_at'},
    'supplier_catalog_items': {'reserved_quantity', 'updated_at'},
    'payment_bsc_details': {'verification_status', 'last_verified_at'},
    'customer_usdt_balance_accounts': {'balance_micro', 'version', 'last_entry_id', 'updated_at'},
    'order_refunds': set('state confirmed_at updated_at external_reference external_reference_hash actor_user_id'.split()),
    'warranty_claims': {'settlement_state', 'updated_at'},
    'supplier_account_units': {'updated_at'},
    'order_items': {'updated_at'},
}


SUPPLIER_INSERT = {
    'supplier_fulfillments': set('order_id order_item_id quantity state maximum_cost_idr sources_snapshot reserved_supplier_id reserved_catalog_item_id reserved_cost_micros reserved_quantity created_at updated_at'.split()),
    'supplier_purchases': set('supplier_fulfillment_id supplier_id supplier_catalog_item_id reference quantity expected_cost_micros exchange_rate_idr state delivery_payload last_error created_at updated_at'.split()),
    'supplier_purchase_dispatches': set('supplier_purchase_id claim_token started_at outcome'.split()),
    'supplier_purchase_evidence': set('supplier_purchase_id actor_user_id kind evidence_source provider_reference note history_reconciliation_id observed_at created_at updated_at'.split()),
    'supplier_manual_handoffs': set('supplier_fulfillment_id actor_user_id idempotency_key quantity note handed_off_at created_at updated_at'.split()),
    'supplier_order_history_reconciliations': set('supplier_purchase_id actor_user_id outcome error_code requested_limit row_count dispatch_at requested_at observed_at history_timezone purchase_version purchase_claim_token coverage_kind created_at updated_at'.split()),
    'product_stock_items': set('product_id order_item_id secret supplier_purchase_id supplier_unit_index created_at updated_at'.split()),
    'order_refunds': set('warranty_claim_id supplier_account_unit_id supplier_fulfillment_id missing_quantity'.split()),
    'inventory_movements': {'available_before', 'available_after'},
    'audit_logs': {'old_values', 'ip_address'},
}
SUPPLIER_UPDATE = {
    'suppliers': set('balance_micros reserved_balance_micros version sync_token sync_attempted_at sync_lease_expires_at synced_at sync_expires_at sync_failures sync_next_attempt_at balance_observed_at is_active last_error updated_at'.split()),
    'supplier_catalog_items': set('stock reserved_quantity updated_at'.split()),
    'supplier_fulfillments': set('reserved_supplier_id reserved_catalog_item_id reserved_cost_micros reserved_quantity current_purchase_id state next_attempt_at last_error delivered_at refunded_quantity updated_at'.split()),
    'supplier_purchases': set('supplier_order_id observed_quantity actual_cost_micros state claim_token lease_expires_at dispatched_at replay_count next_attempt_at delivery_payload trusted_delivery_payload last_error committed_at updated_at'.split()),
    'supplier_purchase_dispatches': {'finished_at', 'outcome', 'error_code'},
    'supplier_order_history_reconciliations': set('outcome error_code requested_limit reported_count row_count coverage_start coverage_end history_timezone response_sha256 candidate_order_ids candidate_facts coverage_kind observed_at updated_at'.split()),
    'products': {'supplier_available_stock', 'supplier_available_until', 'price_amount', 'updated_at'},
    'warranty_claims': {'customer_id', 'settlement_choice', 'settlement_state', 'replacement_next_attempt_at', 'updated_at'},
}


AUTH_INSERT = {
    'customers': set('email email_verified_at first_name auth_version enrollment_version created_at updated_at'.split()),
    'customer_auth_tokens': set('customer_id email purpose token_hash challenge_hash session_binding_hash proposed_password_hash auth_version enrollment_version attempts expires_at consumed_at created_at updated_at'.split()),
    'customer_login_links': set('email token_hash expires_at used_at requested_ip created_at updated_at'.split()),
    'customer_auth_mail_works': set('auth_token_id login_link_id payload status attempts expires_at next_attempt_at sent_at last_error_code created_at updated_at'.split()),
    'customer_google_auth_claims': set('operation customer_id auth_version authenticated_at state_hash session_binding_hash configuration_fingerprint expires_at created_at updated_at'.split()),
    'customer_telegram_auth_claims': set('operation customer_id auth_version authenticated_at session_binding_hash capability_hash expires_at created_at updated_at'.split()),
    'customer_identities': set('customer_id provider provider_subject provider_email last_used_at created_at updated_at'.split()),
    'audit_logs': set('action subject_type subject_id new_values ip_address created_at'.split()),
}
AUTH_UPDATE = {
    'customers': set('email email_verified_at password password_scheme auth_version enrollment_version telegram_id updated_at'.split()),
    'customer_auth_tokens': {'attempts', 'consumed_at', 'updated_at'},
    'customer_login_links': {'used_at', 'updated_at'},
    'customer_auth_mail_works': set('payload status attempts next_attempt_at sent_at last_error_code updated_at'.split()),
    'customer_google_auth_claims': {'consumed_at', 'updated_at'},
    'customer_telegram_auth_claims': set('actor_id proof_customer_id proof_auth_version confirmation_hash prepared_at confirmed_at consumed_at failure updated_at'.split()),
    'customer_identities': {'last_used_at', 'provider_email', 'updated_at'},
    'orders': {'customer_id', 'updated_at'},
}


def _merge(*mappings):
    merged = {}
    for mapping in mappings:
        for table, columns in mapping.items():
            merged.setdefault(table, set()).update(columns)
    return merged


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
    cursor.execute("SELECT has_column_privilege(current_user,'public.store_balance_top_ups','provider_reference','UPDATE')")
    if cursor.fetchone()[0]:
        allowed_insert = _merge(allowed_insert, FUNDING_INSERT)
        allowed_update = _merge(allowed_update, FUNDING_UPDATE)
    cursor.execute("SELECT has_column_privilege(current_user,'public.store_balance_funding_policies','minimum_amount','UPDATE')")
    if cursor.fetchone()[0]:
        allowed_update = _merge(allowed_update, {
            'store_balance_funding_policies': {'minimum_amount', 'preset_amounts', 'version', 'updated_at'},
            'users': {'updated_at'}})
        allowed_insert = _merge(allowed_insert, {'audit_logs': {
            'user_id', 'action', 'subject_type', 'subject_id', 'old_values', 'new_values',
            'ip_address', 'created_at'}})
    cursor.execute("SELECT has_column_privilege(current_user,'public.balance_adjustment_requests','id','INSERT')")
    if cursor.fetchone()[0]:
        allowed_insert = _merge(allowed_insert, ADJUSTMENT_INSERT)
        allowed_update = _merge(allowed_update, ADJUSTMENT_UPDATE)
    cursor.execute("SELECT has_column_privilege(current_user,'public.audit_logs','action','INSERT')")
    if cursor.fetchone()[0]:
        allowed_insert = _merge(allowed_insert, LIFECYCLE_INSERT)
        allowed_update = _merge(allowed_update, LIFECYCLE_UPDATE)
    cursor.execute("SELECT has_column_privilege(current_user,'public.supplier_purchases','reference','INSERT')")
    if cursor.fetchone()[0]:
        allowed_insert = _merge(allowed_insert, SUPPLIER_INSERT)
        allowed_update = _merge(allowed_update, SUPPLIER_UPDATE)
    cursor.execute("SELECT has_column_privilege(current_user,'public.manual_payment_settings','payment_mode','UPDATE')")
    if cursor.fetchone()[0]:
        allowed_update = _merge(allowed_update, {'manual_payment_settings':
            {'payment_mode', 'payment_provider_version', 'is_active', 'sakurupiah_channel', 'updated_at'}})
    cursor.execute("SELECT has_column_privilege(current_user,'public.manual_payment_settings','binance_receiver_value','UPDATE')")
    if cursor.fetchone()[0]:
        allowed_update = _merge(allowed_update, {
            'manual_payment_settings': {'binance_receiver_type', 'binance_receiver_value',
                                        'payment_provider_version', 'updated_at'},
            'users': {'updated_at'}})
    cursor.execute("SELECT has_column_privilege(current_user,'public.customer_auth_tokens','token_hash','INSERT')")
    auth = cursor.fetchone()[0]
    if auth:
        allowed_insert = _merge(allowed_insert, AUTH_INSERT)
        allowed_update = _merge(allowed_update, AUTH_UPDATE)
    cursor.execute('''SELECT c.relname FROM pg_catalog.pg_class c
        JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname='public' AND c.relkind IN ('r','p')
        AND (has_table_privilege(current_user,c.oid,'INSERT,UPDATE,TRUNCATE,TRIGGER')
        OR (has_table_privilege(current_user,c.oid,'DELETE') AND NOT (%s AND c.relname='customer_identities')))''', (auth,))
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
