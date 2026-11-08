-- P24 Store Bot admin capture writes. Explicit provisioning only; the normal
-- customer navigation grants stay read-only. Apply after bot_grants.sql when a
-- named Telegram admin is intentionally enabled.
GRANT UPDATE (icon,custom_emoji_id,updated_at) ON public.products TO candidate_runtime;
GRANT UPDATE (description,description_ms,description_en,warranty_policy,
    warranty_policy_ms,warranty_policy_en,telegram_content,updated_at)
    ON public.products TO candidate_runtime;
GRANT UPDATE (icon,custom_emoji_id,updated_at) ON public.product_families TO candidate_runtime;
GRANT UPDATE (fallback_emoji,custom_emoji_id,updated_at) ON public.telegram_ui_icons TO candidate_runtime;
GRANT INSERT (user_id,action,subject_type,subject_id,old_values,new_values,ip_address,created_at)
    ON public.audit_logs TO candidate_runtime;
GRANT USAGE ON SEQUENCE public.audit_logs_id_seq TO candidate_runtime;
