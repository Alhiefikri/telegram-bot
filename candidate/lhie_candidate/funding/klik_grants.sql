-- Explicit disposable KlikQRIS qualification only; never provision at startup.
-- Legacy provider payloads may contain sensitive history; actual data needs HD review.
GRANT SELECT (provider_payload,provider_signature) ON public.store_balance_top_ups TO candidate_runtime;
GRANT INSERT (provider_payload) ON public.store_balance_top_ups TO candidate_runtime;
GRANT UPDATE (provider_signature) ON public.store_balance_top_ups TO candidate_runtime;
GRANT INSERT (action,subject_type,subject_id,old_values,new_values,created_at)
    ON public.audit_logs TO candidate_runtime;
GRANT USAGE,SELECT ON SEQUENCE public.audit_logs_id_seq TO candidate_runtime;
GRANT SELECT (id,store_balance_top_up_id,provider,event_type,merchant_ref,provider_reference,
    provider_status,idempotency_key,received_count,received_at,last_received_at,processed_at)
    ON public.store_balance_top_up_events TO candidate_runtime;
GRANT INSERT (store_balance_top_up_id,provider,event_type,merchant_ref,provider_reference,
    provider_status,raw_payload,idempotency_key,received_count,received_at,last_received_at,processed_at)
    ON public.store_balance_top_up_events TO candidate_runtime;
GRANT UPDATE (received_count,last_received_at,processed_at)
    ON public.store_balance_top_up_events TO candidate_runtime;
GRANT USAGE,SELECT ON SEQUENCE public.store_balance_top_up_events_id_seq TO candidate_runtime;
