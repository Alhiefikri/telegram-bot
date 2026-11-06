-- Apply only to the disposable runtime, together with the shared checkout grants.
GRANT SELECT ON public.customer_api_order_claims TO candidate_runtime;
GRANT INSERT (customer_id,operation,idempotency_key,fingerprint,credential_id,state,attempt_token,
    lease_expires_at,preference,preference_source,product_id,quantity,expected_idr_total,
    expected_usd_total_micro,created_at,updated_at) ON public.customer_api_order_claims TO candidate_runtime;
GRANT UPDATE (state,order_id,attempt_token,lease_expires_at,updated_at) ON public.customer_api_order_claims TO candidate_runtime;
GRANT INSERT (api_order_claim_id) ON public.orders TO candidate_runtime;
GRANT USAGE ON SEQUENCE public.customer_api_order_claims_id_seq TO candidate_runtime;
