-- Explicit disposable qualification only; never executed by application startup.
GRANT SELECT (id,category_id,product_family_id,family_sort_order,family_option_label,name,icon,
 custom_emoji_id,description,description_ms,description_en,warranty_policy,warranty_policy_ms,
 warranty_policy_en,telegram_content,price_amount,usdt_price_amount,currency,stock,reserved_stock,
 telegram_image_path,created_at,product_type,access_duration,region,account_type,device_limit,
 included_items,limitations,fulfillment_expectation,support_policy,deleted_at,is_active,
 supplier_available_until,supplier_available_stock,image_path) ON public.products TO candidate_runtime;
GRANT SELECT (id,name,slug,description,icon,custom_emoji_id,telegram_image_path,sort_order,deleted_at,image_path)
 ON public.product_families TO candidate_runtime;
GRANT SELECT (id,name,slug,description,sort_order,is_active,deleted_at) ON public.categories TO candidate_runtime;
GRANT SELECT (product_id,supplier_purchase_id,order_item_id,retired_at) ON public.product_stock_items TO candidate_runtime;
GRANT SELECT (product_id,order_id,quantity) ON public.order_items TO candidate_runtime;
GRANT SELECT (id,payment_status,order_status) ON public.orders TO candidate_runtime;
GRANT SELECT (singleton_key,homepage_banners) ON public.storefront_settings TO candidate_runtime;
GRANT SELECT (singleton_key,is_active,public_note) ON public.checkout_maintenance_settings TO candidate_runtime;
GRANT SELECT (id,product_id,minimum_quantity,price_amount,usdt_price_amount)
 ON public.product_quantity_price_tiers TO candidate_runtime;
GRANT SELECT (old_slug,product_family_id) ON public.product_family_slug_redirects TO candidate_runtime;
GRANT SELECT (customer_id,updated_at) ON public.orders TO candidate_runtime;
GRANT SELECT (id,first_name) ON public.customers TO candidate_runtime;
GRANT SELECT (id,product_name) ON public.order_items TO candidate_runtime;
GRANT SELECT (order_id,state) ON public.supplier_fulfillments TO candidate_runtime;
GRANT SELECT (id,order_id,product_id,type,quantity) ON public.inventory_movements TO candidate_runtime;
GRANT SELECT (id,is_active,whatsapp_support_url,whatsapp_support_number,admin_contact_url,admin_username) ON public.manual_payment_settings TO candidate_runtime;
GRANT SELECT (created_at) ON public.product_families TO candidate_runtime;
GRANT SELECT (created_at) ON public.orders, public.inventory_movements TO candidate_runtime;
