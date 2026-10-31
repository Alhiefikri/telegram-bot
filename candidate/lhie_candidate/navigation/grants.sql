-- Explicit disposable provisioning only, after the reviewed repository-schema oracle.
GRANT USAGE ON SCHEMA public TO candidate_runtime;
GRANT SELECT (id,telegram_id,first_name,username,locale) ON public.customers TO candidate_runtime;
GRANT SELECT (customer_id,provider) ON public.customer_identities TO candidate_runtime;
GRANT INSERT (telegram_id,first_name,username,locale,created_at,updated_at) ON public.customers TO candidate_runtime;
GRANT UPDATE (first_name,username,locale,updated_at) ON public.customers TO candidate_runtime;
GRANT USAGE ON SEQUENCE public.customers_id_seq TO candidate_runtime;
GRANT SELECT (id,category_id,product_family_id,family_sort_order,family_option_label,name,icon,custom_emoji_id,
    description,description_ms,description_en,warranty_policy,warranty_policy_ms,warranty_policy_en,
    telegram_content,price_amount,usdt_price_amount,currency,stock,reserved_stock,telegram_image_path,created_at,
    deleted_at,is_active,supplier_available_stock,supplier_available_until,product_type,access_duration,region,
    account_type,device_limit,included_items,limitations,fulfillment_expectation,support_policy)
    ON public.products TO candidate_runtime;
GRANT SELECT (id,name,slug,description,sort_order,is_active,deleted_at) ON public.categories TO candidate_runtime;
GRANT SELECT (id,name,slug,description,icon,custom_emoji_id,telegram_image_path,sort_order,deleted_at)
    ON public.product_families TO candidate_runtime;
GRANT SELECT (product_id,supplier_purchase_id,order_item_id,retired_at) ON public.product_stock_items TO candidate_runtime;
GRANT SELECT (product_id,order_id,quantity) ON public.order_items TO candidate_runtime;
GRANT SELECT (id,customer_id,payment_status,order_status) ON public.orders TO candidate_runtime;
GRANT SELECT (id,order_id,amount,currency,status,paid_at) ON public.payments TO candidate_runtime;
GRANT SELECT (id,product_id,minimum_quantity,price_amount,usdt_price_amount)
    ON public.product_quantity_price_tiers TO candidate_runtime;
GRANT SELECT (key,fallback_emoji,source_emoji,custom_emoji_id,sort_order,label,id) ON public.telegram_ui_icons TO candidate_runtime;
GRANT SELECT (singleton_key,is_active,public_note) ON public.checkout_maintenance_settings TO candidate_runtime;
GRANT SELECT (id,customer_id,balance) ON public.customer_balance_accounts TO candidate_runtime;
GRANT SELECT (id,customer_balance_account_id,balance_after) ON public.store_balance_entries TO candidate_runtime;
GRANT SELECT (singleton_key,channel_link,channel_username) ON public.store_channel_settings TO candidate_runtime;
GRANT SELECT (singleton_key,enabled) ON public.customer_api_settings TO candidate_runtime;
GRANT SELECT (singleton_key,telegram_welcome_banner_path) ON public.storefront_settings TO candidate_runtime;
GRANT SELECT (id,singleton_key) ON public.customer_usdt_funding_policies TO candidate_runtime;
GRANT SELECT (scope) ON public.customer_usdt_funding_pauses TO candidate_runtime;
GRANT SELECT (id,state) ON public.balance_restore_gates TO candidate_runtime;
GRANT SELECT,INSERT,UPDATE ON django_candidate.navigation_roots,django_candidate.navigation_updates,
    django_candidate.navigation_sessions TO candidate_runtime;
GRANT SELECT ON django_candidate.navigation_assets TO candidate_runtime;
