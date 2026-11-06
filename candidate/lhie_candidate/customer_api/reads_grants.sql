-- Explicit local/disposable provisioning only; never applied by application startup.
GRANT USAGE ON SCHEMA public TO candidate_runtime;
GRANT SELECT (id,balance_payment_preference,telegram_id,locale,first_name) ON public.customers TO candidate_runtime;
GRANT SELECT (id,category_id,product_family_id,family_sort_order,family_option_label,name,icon,custom_emoji_id,
    description,description_ms,description_en,warranty_policy,warranty_policy_ms,warranty_policy_en,
    telegram_content,price_amount,usdt_price_amount,reseller_price_amount,reseller_usdt_price_amount,
    currency,stock,reserved_stock,telegram_image_path,created_at,deleted_at,is_active,
    supplier_available_stock,supplier_available_until,product_type,access_duration,region,account_type,
    device_limit,included_items,limitations,fulfillment_expectation,support_policy,delivery_note,
    usage_guide_title,usage_guide_url) ON public.products TO candidate_runtime;
GRANT SELECT (id,name,slug,description,is_active,deleted_at) ON public.categories TO candidate_runtime;
GRANT SELECT (id,name,slug,description,icon,custom_emoji_id,telegram_image_path,sort_order,deleted_at)
    ON public.product_families TO candidate_runtime;
GRANT SELECT (id,product_id,minimum_quantity,price_amount,usdt_price_amount)
    ON public.product_quantity_price_tiers TO candidate_runtime;
GRANT SELECT (id,customer_id,balance) ON public.customer_balance_accounts TO candidate_runtime;
GRANT SELECT (id,customer_balance_account_id,balance_after) ON public.store_balance_entries TO candidate_runtime;
GRANT SELECT (id,customer_id,balance_micro,version,frozen_reason) ON public.customer_usdt_balance_accounts TO candidate_runtime;
GRANT SELECT (id,customer_usdt_balance_account_id,account_sequence,balance_after_micro)
    ON public.customer_usdt_balance_entries TO candidate_runtime;
GRANT SELECT (id,customer_id,invoice_number,order_status,payment_status,currency,created_at,
    api_order_claim_id,telegram_receipt_sent_at,total_amount) ON public.orders TO candidate_runtime;
GRANT SELECT (id,order_id,status,currency,amount,paid_at,created_at,method,provider_total_payment,provider_fee)
    ON public.payments TO candidate_runtime;
GRANT SELECT (id,order_id,product_id,product_name,product_icon,product_custom_emoji_id,quantity,unit_price,
    subtotal,usdt_unit_price,usdt_subtotal,delivery_note,delivery_note_snapshotted,usage_guide_title,
    usage_guide_url,usage_guide_snapshotted) ON public.order_items TO candidate_runtime;
-- The application selects and decrypts these fields only after the owned delivery gate.
GRANT SELECT (id,product_id,order_item_id,supplier_purchase_id,retired_at,credential_format,secret,credential_payload)
    ON public.product_stock_items TO candidate_runtime;
GRANT SELECT (id,order_id,state,order_item_id,refunded_quantity) ON public.supplier_fulfillments TO candidate_runtime;
GRANT SELECT (id,order_id,reason,state) ON public.manual_fulfillment_reviews TO candidate_runtime;
GRANT SELECT (id,order_id,amount,currency,state,payment_id,customer_id,missing_quantity,supplier_fulfillment_id,
    supplier_account_unit_id,warranty_claim_id) ON public.order_refunds TO candidate_runtime;
GRANT SELECT (id,order_id,order_item_id,product_stock_item_id,unit_number) ON public.supplier_account_units TO candidate_runtime;
GRANT SELECT (id,supplier_fulfillment_id,quantity) ON public.supplier_manual_handoffs TO candidate_runtime;
