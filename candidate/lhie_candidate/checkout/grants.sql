-- Explicit local/disposable provisioning after the P03 repository-schema oracle.
-- No startup grants; no DDL or UPDATE/DELETE on immutable history.
GRANT USAGE ON SCHEMA public TO candidate_runtime;
GRANT SELECT ON public.products, public.carts, public.cart_items, public.customer_balance_accounts,
    public.store_balance_entries, public.inventory_movements TO candidate_runtime;
GRANT SELECT (id,telegram_id) ON public.customers TO candidate_runtime;
GRANT SELECT (id,is_active,deleted_at) ON public.categories TO candidate_runtime;
GRANT SELECT (id,name,icon,custom_emoji_id) ON public.product_families TO candidate_runtime;
GRANT SELECT (id,customer_id,cart_id,checkout_key,invoice_number,order_status,payment_status,total_amount,currency)
    ON public.orders TO candidate_runtime;
GRANT SELECT (id,order_id,provider,method,amount,currency,status,paid_at,created_at)
    ON public.payments TO candidate_runtime;
GRANT SELECT (id,order_id,product_id,product_name,quantity,local_quantity,supplier_quantity,unit_price,subtotal,quantity_price_tier_minimum)
    ON public.order_items TO candidate_runtime;
GRANT SELECT (id,product_id,order_item_id,supplier_purchase_id,supplier_unit_index,retired_at,retired_reason,
    credential_format,secret,credential_payload) ON public.product_stock_items TO candidate_runtime;
GRANT SELECT (id,product_id,product_stock_item_id,identity_kind) ON public.credential_identities TO candidate_runtime;
GRANT SELECT (product_id,minimum_quantity,price_amount,usdt_price_amount)
    ON public.product_quantity_price_tiers TO candidate_runtime;
GRANT SELECT (id,singleton_key) ON public.customer_usdt_funding_policies TO candidate_runtime;
GRANT SELECT (id,scope) ON public.customer_usdt_funding_pauses TO candidate_runtime;
GRANT SELECT (id,state) ON public.balance_restore_gates TO candidate_runtime;
GRANT SELECT (id,singleton_key,is_active) ON public.checkout_maintenance_settings TO candidate_runtime;
GRANT SELECT (id,is_active,payment_provider_version,payment_mode,sakurupiah_channel,qris_payment_ttl_minutes)
    ON public.manual_payment_settings TO candidate_runtime;
GRANT SELECT (id,order_id) ON public.supplier_fulfillments TO candidate_runtime;

GRANT INSERT (id,customer_id,cart_id,checkout_key,invoice_number,order_status,payment_status,total_amount,currency,
    expires_at,terms_version,terms_accepted_at,balance_checkout_snapshot,created_at,updated_at)
    ON public.orders TO candidate_runtime;
GRANT INSERT (customer_id,origin,status,expires_at,created_at,updated_at)
    ON public.carts TO candidate_runtime;
GRANT INSERT (cart_id,product_id,quantity,created_at,updated_at)
    ON public.cart_items TO candidate_runtime;
GRANT INSERT (id,order_id,provider,merchant_ref,method,amount,currency,status,expired_at,
    sakurupiah_channel,binance_auto_verification_enabled,binance_verification_mode,
    binance_receiver_type,binance_receiver_value,binance_verification_status,created_at,updated_at)
    ON public.payments TO candidate_runtime;
GRANT INSERT (order_id,product_id,product_name,product_icon,product_custom_emoji_id,family_id,family_name,
    delivery_note,delivery_note_snapshotted,usage_guide_title,usage_guide_url,usage_guide_snapshotted,
    quantity,local_quantity,supplier_quantity,quantity_price_tier_minimum,unit_price,subtotal,created_at,updated_at)
    ON public.order_items TO candidate_runtime;
GRANT INSERT (product_id,order_id,type,quantity,stock_before,became_unavailable,notes,created_at)
    ON public.inventory_movements TO candidate_runtime;
GRANT INSERT (customer_id,balance,created_at,updated_at) ON public.customer_balance_accounts TO candidate_runtime;
GRANT INSERT (customer_balance_account_id,kind,amount,balance_after,source_type,source_id,idempotency_key,created_at,refund_order_id)
    ON public.store_balance_entries TO candidate_runtime;
GRANT UPDATE (status,updated_at) ON public.carts TO candidate_runtime;
GRANT UPDATE (updated_at) ON public.cart_items TO candidate_runtime;
GRANT UPDATE (stock,reserved_stock,updated_at) ON public.products TO candidate_runtime;
GRANT UPDATE (order_item_id,retired_at,retired_reason,updated_at) ON public.product_stock_items TO candidate_runtime;
GRANT UPDATE (balance,updated_at) ON public.customer_balance_accounts TO candidate_runtime;
GRANT UPDATE (order_status,payment_status,updated_at) ON public.orders TO candidate_runtime;
GRANT UPDATE (status,paid_at,updated_at) ON public.payments TO candidate_runtime;
GRANT UPDATE (updated_at) ON public.customer_usdt_funding_policies,public.balance_restore_gates,
    public.checkout_maintenance_settings,public.manual_payment_settings TO candidate_runtime;
GRANT USAGE ON SEQUENCE public.order_items_id_seq,public.inventory_movements_id_seq,
    public.customer_balance_accounts_id_seq,public.store_balance_entries_id_seq,
    public.carts_id_seq,public.cart_items_id_seq TO candidate_runtime;

-- P12 lifecycle operations on the same legacy authority, provisioned explicitly.
GRANT SELECT (expires_at,checkout_key) ON public.orders TO candidate_runtime;
GRANT SELECT (merchant_ref,sakurupiah_channel,expired_at,provider_reference,provider_total_payment,
    provider_fee,provider_expires_at,provider_presentation,provider_create_state,provider_payment_method,
    payment_proof_submitted_at,binance_auto_verification_enabled,binance_verification_grace_until,
    binance_receiver_type,binance_receiver_value)
    ON public.payments TO candidate_runtime;
GRANT SELECT (id,role) ON public.users TO candidate_runtime;
GRANT SELECT (id,order_id,reason,state,payment_id,refund_provider_reference) ON public.manual_fulfillment_reviews TO candidate_runtime;
GRANT SELECT (id,order_id,state,sources_snapshot,reserved_supplier_id,reserved_catalog_item_id,
    reserved_cost_micros,reserved_quantity,current_purchase_id) ON public.supplier_fulfillments TO candidate_runtime;
GRANT SELECT (product_id,supplier_catalog_item_id) ON public.product_supplier_sources TO candidate_runtime;
GRANT SELECT (id,reserved_balance_micros,version) ON public.suppliers TO candidate_runtime;
GRANT SELECT (id,supplier_id,reserved_quantity) ON public.supplier_catalog_items TO candidate_runtime;
GRANT SELECT (id,supplier_fulfillment_id,supplier_id,supplier_catalog_item_id,state,lease_expires_at)
    ON public.supplier_purchases TO candidate_runtime;
GRANT SELECT (id,order_id,confirmation_state) ON public.supplier_account_units TO candidate_runtime;
GRANT SELECT (id,order_id,settlement_state) ON public.warranty_claims TO candidate_runtime;
GRANT SELECT (payment_id,verification_grace_until,token_contract,receiver_address,expected_token_units,
    chain_id,token_decimals,payable_amount_micro)
    ON public.payment_bsc_details TO candidate_runtime;
GRANT SELECT (id,payment_id,token_contract,receiver_address,amount_units,receipt_status,
    verification_status,block_timestamp) ON public.bsc_payment_transfer_evidence TO candidate_runtime;
GRANT SELECT (id,target_type,target_id,status,beneficiary,currency,amount_units,canonical_key,provider,
    identity_kind,account_namespace,reference,chain_id,tx_hash,log_index,decimals,fingerprint)
    ON public.external_money_receipts TO candidate_runtime;
GRANT SELECT (id,chain_id,tx_hash,external_money_receipt_id,status,target_type,target_id)
    ON public.external_money_receipt_parent_claims TO candidate_runtime;
GRANT INSERT (user_id,action,subject_type,subject_id,new_values,created_at) ON public.audit_logs TO candidate_runtime;
GRANT INSERT (order_id,payment_id,provider,state,finished_at,created_at,updated_at)
    ON public.provider_cancellation_attempts TO candidate_runtime;
GRANT UPDATE (checkout_key) ON public.orders TO candidate_runtime;
GRANT UPDATE (state,resolution_reason,actor_user_id,resolved_at,refund_provider_reference,
    refund_provider_result,refund_requested_at,updated_at) ON public.manual_fulfillment_reviews TO candidate_runtime;
GRANT UPDATE (state,next_attempt_at,reserved_cost_micros,reserved_quantity,updated_at)
    ON public.supplier_fulfillments TO candidate_runtime;
GRANT UPDATE (state,updated_at) ON public.supplier_purchases TO candidate_runtime;
GRANT UPDATE (reserved_balance_micros,version,updated_at) ON public.suppliers TO candidate_runtime;
GRANT UPDATE (reserved_quantity,updated_at) ON public.supplier_catalog_items TO candidate_runtime;
GRANT UPDATE (verification_status,last_verified_at) ON public.payment_bsc_details TO candidate_runtime;
GRANT USAGE ON SEQUENCE public.audit_logs_id_seq,public.provider_cancellation_attempts_id_seq TO candidate_runtime;

GRANT SELECT ON public.order_refunds,public.customer_usdt_balance_entries TO candidate_runtime;
GRANT SELECT (id,customer_id,currency,balance_micro,version,last_entry_id,frozen_reason)
    ON public.customer_usdt_balance_accounts TO candidate_runtime;
GRANT SELECT (customer_usdt_balance_entry_id,currency,amount_micro,debit_account,credit_account,source_fingerprint)
    ON public.customer_usdt_balance_accounting_events TO candidate_runtime;
GRANT SELECT (id,order_id,customer_id,supplier_account_unit_id,state,verified_at,settlement_choice,settlement_state)
    ON public.warranty_claims TO candidate_runtime;
GRANT SELECT (id,order_id,order_item_id,product_stock_item_id,retail_amount,retail_currency,unit_number,confirmation_state)
    ON public.supplier_account_units TO candidate_runtime;
GRANT SELECT (order_item_id,quantity,refunded_quantity) ON public.supplier_fulfillments TO candidate_runtime;
GRANT SELECT (usdt_subtotal) ON public.order_items TO candidate_runtime;
GRANT SELECT (warranty_claim_id,observed_quantity,committed_at) ON public.supplier_purchases TO candidate_runtime;
GRANT SELECT (supplier_fulfillment_id,quantity) ON public.supplier_manual_handoffs TO candidate_runtime;
GRANT INSERT (id,order_id,payment_id,customer_id,amount,currency,state,external_reference,
    external_reference_hash,actor_user_id,confirmed_at,created_at,updated_at)
    ON public.order_refunds TO candidate_runtime;
GRANT INSERT (customer_usdt_balance_account_id,account_sequence,kind,amount_micro,balance_after_micro,
    source_type,source_id,idempotency_key,request_fingerprint,original_entry_id,refund_order_id,created_at)
    ON public.customer_usdt_balance_entries TO candidate_runtime;
GRANT INSERT (customer_usdt_balance_entry_id,currency,amount_micro,debit_account,credit_account,
    source_fingerprint,created_at) ON public.customer_usdt_balance_accounting_events TO candidate_runtime;
GRANT UPDATE (balance_micro,version,last_entry_id,updated_at) ON public.customer_usdt_balance_accounts TO candidate_runtime;
GRANT UPDATE (state,confirmed_at,updated_at,external_reference,external_reference_hash,actor_user_id)
    ON public.order_refunds TO candidate_runtime;
GRANT UPDATE (settlement_state,updated_at) ON public.warranty_claims TO candidate_runtime;
GRANT UPDATE (updated_at) ON public.supplier_account_units,public.order_items TO candidate_runtime;
GRANT UPDATE (refunded_quantity,last_error) ON public.supplier_fulfillments TO candidate_runtime;
GRANT USAGE ON SEQUENCE public.customer_usdt_balance_entries_id_seq,
    public.customer_usdt_balance_accounting_events_id_seq TO candidate_runtime;

-- Public receiving settings are independent of signing/API credentials.
GRANT SELECT (binance_pay_enabled,binance_payment_ttl_minutes,binance_verification_mode,
    binance_receiver_type,binance_receiver_value,bsc_payment_enabled,bsc_payment_ttl_minutes,
    bsc_min_confirmations,bsc_require_finalized_block,bsc_confirmation_grace_minutes,updated_at,
    binance_order_id_compatibility_ready_at)
    ON public.manual_payment_settings TO candidate_runtime;
GRANT SELECT ON public.payment_bsc_details TO candidate_runtime;
GRANT SELECT (id,address_normalized,chain_id,token_contract,token_decimals,address_claim_id,
    verification_status,verified_at,is_active,last_assigned_at) ON public.bsc_payment_receivers TO candidate_runtime;
GRANT SELECT (id,channel,chain_id,address_normalized) ON public.usdt_receiver_address_claims TO candidate_runtime;
GRANT SELECT (rail,state,expires_at,payable_micro) ON public.customer_usdt_funding_intents TO candidate_runtime;
GRANT SELECT (rail,created_at,payable_micro) ON public.customer_usdt_top_ups TO candidate_runtime;
GRANT INSERT (payment_id,receiver_id,receiver_address,chain_id,token_contract,token_decimals,
    base_amount_micro,payable_amount_micro,expected_token_units,verification_status,verification_mode,
    required_confirmations,requires_finalized_block,confirmation_grace_minutes,created_at,updated_at)
    ON public.payment_bsc_details TO candidate_runtime;
GRANT UPDATE (last_assigned_at) ON public.bsc_payment_receivers TO candidate_runtime;
GRANT INSERT (usdt_unit_price,usdt_subtotal) ON public.order_items TO candidate_runtime;
