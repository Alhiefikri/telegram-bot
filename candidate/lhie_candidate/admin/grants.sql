-- P22 admin catalog/stock grants. Apply explicitly after the P03 repository
-- schema, auth, checkout and storefront grants. Startup never installs these.
GRANT SELECT ON public.products, public.categories, public.product_families,
    public.product_quantity_price_tiers, public.product_stock_items,
    public.credential_identities, public.credential_identity_backfill_readiness,
    public.product_stock_intake_operations, public.product_family_slug_redirects,
    public.storefront_settings, public.audit_logs
    TO candidate_runtime;
GRANT SELECT (id,order_id,product_id) ON public.order_items TO candidate_runtime;
GRANT SELECT (id,invoice_number,order_status,payment_status,expires_at,created_at)
    ON public.orders TO candidate_runtime;
GRANT SELECT (id,order_id,status,paid_at) ON public.payments TO candidate_runtime;

GRANT INSERT (category_id,product_family_id,family_sort_order,family_option_label,
    name,icon,custom_emoji_id,description,description_ms,description_en,
    product_type,access_duration,region,account_type,device_limit,included_items,
    limitations,fulfillment_expectation,warranty_policy,warranty_policy_ms,
    warranty_policy_en,support_policy,delivery_note,usage_guide_title,usage_guide_url,
    price_amount,usdt_price_amount,reseller_price_amount,reseller_usdt_price_amount,
    currency,stock,reserved_stock,image_path,telegram_image_path,
    telegram_channel_image_path,is_active,featured_rank,created_at,updated_at)
    ON public.products TO candidate_runtime;
GRANT UPDATE (category_id,product_family_id,family_sort_order,family_option_label,
    name,icon,custom_emoji_id,description,description_ms,description_en,
    product_type,access_duration,region,account_type,device_limit,included_items,
    limitations,fulfillment_expectation,warranty_policy,warranty_policy_ms,
    warranty_policy_en,support_policy,delivery_note,usage_guide_title,usage_guide_url,
    price_amount,usdt_price_amount,reseller_price_amount,reseller_usdt_price_amount,
    stock,reserved_stock,image_path,telegram_image_path,
    telegram_channel_image_path,is_active,featured_rank,deleted_at,updated_at)
    ON public.products TO candidate_runtime;

GRANT INSERT (name,slug,description,banner_image_path,sort_order,is_active,created_at,updated_at)
    ON public.categories TO candidate_runtime;
GRANT UPDATE (name,description,sort_order,is_active,deleted_at,updated_at)
    ON public.categories TO candidate_runtime;

GRANT INSERT (name,slug,description,icon,custom_emoji_id,image_path,
    telegram_image_path,sort_order,created_at,updated_at)
    ON public.product_families TO candidate_runtime;
GRANT UPDATE (name,slug,description,icon,custom_emoji_id,image_path,
    telegram_image_path,sort_order,deleted_at,updated_at)
    ON public.product_families TO candidate_runtime;
GRANT INSERT (old_slug,product_family_id,created_at,updated_at)
    ON public.product_family_slug_redirects TO candidate_runtime;
GRANT UPDATE (product_family_id,updated_at)
    ON public.product_family_slug_redirects TO candidate_runtime;
GRANT DELETE ON public.product_family_slug_redirects TO candidate_runtime;

GRANT INSERT (product_id,minimum_quantity,price_amount,usdt_price_amount,created_at,updated_at)
    ON public.product_quantity_price_tiers TO candidate_runtime;
GRANT DELETE ON public.product_quantity_price_tiers TO candidate_runtime;

GRANT INSERT (product_id,order_item_id,supplier_purchase_id,supplier_unit_index,
    credential_format,credential_payload,secret,created_at,updated_at)
    ON public.product_stock_items TO candidate_runtime;
GRANT UPDATE (credential_payload,secret,updated_at)
    ON public.product_stock_items TO candidate_runtime;
GRANT DELETE ON public.product_stock_items TO candidate_runtime;

GRANT INSERT (identity_kind,fingerprint,product_id,product_stock_item_id,
    first_seen_at,created_at,updated_at) ON public.credential_identities
    TO candidate_runtime;
GRANT UPDATE (updated_at) ON public.credential_identities TO candidate_runtime;

GRANT INSERT (operation_key,product_id,inventory_movement_id,actor_id,payload_hash,
    mode,item_count,stock_before,stock_after,available_before,available_after,
    ip_address,created_at,updated_at) ON public.product_stock_intake_operations
    TO candidate_runtime;
GRANT UPDATE (inventory_movement_id,available_after,updated_at)
    ON public.product_stock_intake_operations TO candidate_runtime;

GRANT INSERT (product_id,order_id,type,quantity,stock_before,became_unavailable,
    available_before,available_after,notes,created_at) ON public.inventory_movements
    TO candidate_runtime;
GRANT INSERT (user_id,action,subject_type,subject_id,old_values,new_values,ip_address,created_at)
    ON public.audit_logs TO candidate_runtime;

GRANT UPDATE (homepage_banners,homepage_banners_version,updated_at)
    ON public.storefront_settings TO candidate_runtime;

GRANT USAGE ON SEQUENCE public.products_id_seq,public.categories_id_seq,
    public.product_families_id_seq,public.product_family_slug_redirects_id_seq,
    public.product_quantity_price_tiers_id_seq,public.product_stock_items_id_seq,
    public.credential_identities_id_seq,public.product_stock_intake_operations_id_seq,
    public.inventory_movements_id_seq,public.audit_logs_id_seq
    TO candidate_runtime;

-- P23 operational reads and operator-only adapters. Apply explicitly after the
-- P03/P15/P17/P18 grants; startup never installs these.
GRANT SELECT (id,customer_id,invoice_number,order_status,payment_status,total_amount,
    currency,expires_at,created_at,updated_at,telegram_receipt_sent_at,notification_email)
    ON public.orders TO candidate_runtime;
GRANT SELECT (id,telegram_id,username,first_name,locale) ON public.customers TO candidate_runtime;
GRANT SELECT (id,order_id,product_name,product_icon,quantity,local_quantity,supplier_quantity,
    unit_price,subtotal,usdt_subtotal) ON public.order_items TO candidate_runtime;
GRANT SELECT ON public.payments, public.payment_events, public.manual_fulfillment_reviews,
    public.store_balance_top_ups, public.balance_adjustment_requests,
    public.balance_adjustment_accounting_events, public.balance_adjustment_auth_states,
    public.store_balance_compensations, public.store_balance_funding_policies,
    public.customer_usdt_funding_policies, public.customer_usdt_funding_terms,
    public.customer_usdt_funding_pauses, public.customer_usdt_funding_reservations,
    public.customer_usdt_top_ups, public.customer_usdt_top_up_work,
    public.customer_usdt_top_up_settlement_work, public.customer_usdt_top_up_evidence,
    public.customer_usdt_top_up_resolutions, public.usdt_top_up_review_requests,
    public.usdt_top_up_external_return_authorizations, public.usdt_reorg_incidents,
    public.usdt_balance_reconciliation_runs, public.balance_operations_alert_episodes,
    public.balance_restore_gates, public.bsc_payment_receivers,
    public.checkout_maintenance_settings, public.customer_api_settings,
    public.suppliers, public.supplier_catalog_items, public.product_supplier_sources,
    public.supplier_price_policies, public.supplier_currency_rates,
    public.supplier_fulfillments, public.supplier_purchases, public.supplier_manual_handoffs,
    public.supplier_account_units, public.warranty_claims, public.order_refunds
    TO candidate_runtime;
GRANT SELECT ON public.telegram_outbound_effects TO candidate_runtime;
GRANT INSERT (id,effect_key,effect_group_key,supersedes_effect_id,sequence,effect_type,
    source_type,source_id,target_type,telegram_chat_id,queue,telegram_method,locale,
    template_version,state,attempts,next_attempt_at,outcome_metadata,created_at,updated_at)
    ON public.telegram_outbound_effects TO candidate_runtime;
GRANT UPDATE (state,telegram_message_id,sent_at,unknown_at,failed_at,manual_review_at,next_attempt_at,
    lease_until,claim_token,last_error_code,last_error,outcome_metadata,updated_at)
    ON public.telegram_outbound_effects TO candidate_runtime;
GRANT INSERT (user_id,action,subject_type,subject_id,old_values,new_values,ip_address,created_at)
    ON public.audit_logs TO candidate_runtime;

GRANT UPDATE (secret,credential_payload,updated_at) ON public.product_stock_items TO candidate_runtime;
GRANT INSERT (product_id,order_item_id,secret,supplier_purchase_id,supplier_unit_index,created_at,updated_at)
    ON public.product_stock_items TO candidate_runtime;
GRANT UPDATE (state,last_error,next_attempt_at,delivered_at,refunded_quantity,updated_at)
    ON public.supplier_fulfillments TO candidate_runtime;
GRANT UPDATE (state,last_error,next_attempt_at,claim_token,lease_expires_at,updated_at)
    ON public.supplier_purchases TO candidate_runtime;
GRANT INSERT (supplier_fulfillment_id,actor_user_id,idempotency_key,quantity,note,handed_off_at,created_at,updated_at)
    ON public.supplier_manual_handoffs TO candidate_runtime;
GRANT INSERT (supplier_purchase_id,actor_user_id,kind,evidence_source,provider_reference,note,observed_at,created_at,updated_at)
    ON public.supplier_purchase_evidence TO candidate_runtime;

GRANT UPDATE (is_active,public_note,version,activated_at,updated_at)
    ON public.checkout_maintenance_settings TO candidate_runtime;
GRANT UPDATE (enabled,version,changed_at,updated_at)
    ON public.customer_api_settings TO candidate_runtime;
GRANT INSERT (singleton_key,enabled,version,changed_at,created_at,updated_at)
    ON public.customer_api_settings TO candidate_runtime;
GRANT UPDATE (funding_enabled,confirmation_ttl_seconds,application_fee_micro,
    preset_amounts_micro,terms_version,version,updated_at)
    ON public.customer_usdt_funding_policies TO candidate_runtime;
GRANT INSERT (version,body,created_by_user_id,created_at)
    ON public.customer_usdt_funding_terms TO candidate_runtime;
GRANT INSERT (label,address,address_normalized,chain_id,token_contract,token_decimals,
    is_active,verification_status,created_at,updated_at)
    ON public.bsc_payment_receivers TO candidate_runtime;
GRANT UPDATE (is_active,updated_at) ON public.bsc_payment_receivers TO candidate_runtime;
GRANT INSERT (user_id,action,subject_type,subject_id,old_values,new_values,ip_address,created_at)
    ON public.audit_logs TO candidate_runtime;

GRANT USAGE ON SEQUENCE public.customer_usdt_funding_terms_id_seq,
    public.bsc_payment_receivers_id_seq, public.supplier_manual_handoffs_id_seq,
    public.supplier_purchase_evidence_id_seq, public.audit_logs_id_seq
    TO candidate_runtime;
GRANT UPDATE (status,attempts,next_attempt_at,delivery_started_at,failed_at,last_error,updated_at)
    ON public.order_notification_events TO candidate_runtime;
GRANT SELECT ON public.manual_payment_settings, public.customer_api_settings,
    public.customer_usdt_funding_terms, public.order_notification_events
    TO candidate_runtime;
GRANT UPDATE (payment_mode,payment_provider_version,is_active,sakurupiah_channel,updated_at)
    ON public.manual_payment_settings TO candidate_runtime;
GRANT SELECT (product_icon,usdt_subtotal) ON public.order_items TO candidate_runtime;
GRANT USAGE, SELECT ON SEQUENCE public.customer_api_settings_id_seq TO candidate_runtime;
GRANT UPDATE (telegram_receipt_sent_at,updated_at) ON public.orders TO candidate_runtime;
