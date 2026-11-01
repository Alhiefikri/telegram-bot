-- Explicit local/disposable provisioning only; startup never changes grants.
GRANT SELECT ON public.telegram_outbound_effects TO candidate_runtime;
GRANT SELECT (telegram_receipt_sent_at,created_at) ON public.orders TO candidate_runtime;
GRANT SELECT (provider_total_payment,provider_fee,created_at) ON public.payments TO candidate_runtime;
GRANT SELECT (product_name,product_icon,product_custom_emoji_id,delivery_note,delivery_note_snapshotted,
    usage_guide_title,usage_guide_url,usage_guide_snapshotted,usdt_unit_price,usdt_subtotal) ON public.order_items TO candidate_runtime;
GRANT SELECT (delivery_note,usage_guide_title,usage_guide_url) ON public.products TO candidate_runtime;
GRANT SELECT (admin_contact_url,admin_username) ON public.manual_payment_settings TO candidate_runtime;
GRANT SELECT (id,order_id,state) ON public.supplier_fulfillments TO candidate_runtime;
GRANT SELECT (id,supplier_fulfillment_id) ON public.supplier_manual_handoffs TO candidate_runtime;
GRANT SELECT (id,order_id,reason,state) ON public.manual_fulfillment_reviews TO candidate_runtime;
GRANT INSERT (id,effect_key,effect_group_key,supersedes_effect_id,sequence,effect_type,source_type,source_id,
    target_type,telegram_chat_id,queue,telegram_method,locale,template_version,state,attempts,next_attempt_at,
    failed_at,last_error_code,last_error,outcome_metadata,created_at,updated_at)
    ON public.telegram_outbound_effects TO candidate_runtime;
GRANT UPDATE (state,attempts,next_attempt_at,lease_until,claim_token,telegram_message_id,sent_at,unknown_at,
    failed_at,manual_review_at,last_error_code,last_error,outcome_metadata,updated_at)
    ON public.telegram_outbound_effects TO candidate_runtime;
GRANT UPDATE (telegram_receipt_sent_at) ON public.orders TO candidate_runtime;
