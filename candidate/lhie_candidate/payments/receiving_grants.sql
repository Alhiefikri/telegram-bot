-- Explicit disposable/admin configuration authority; never installed at startup.
GRANT SELECT (id,role) ON public.users TO candidate_runtime;
GRANT UPDATE (updated_at) ON public.users TO candidate_runtime;
GRANT SELECT (id,is_active,payment_provider_version,binance_receiver_type,binance_receiver_value,updated_at)
    ON public.manual_payment_settings TO candidate_runtime;
GRANT UPDATE (binance_receiver_type,binance_receiver_value,payment_provider_version,updated_at)
    ON public.manual_payment_settings TO candidate_runtime;
GRANT INSERT (user_id,action,subject_type,subject_id,new_values,created_at) ON public.audit_logs TO candidate_runtime;
GRANT USAGE ON SEQUENCE public.audit_logs_id_seq TO candidate_runtime;
