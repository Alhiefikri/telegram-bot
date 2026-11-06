-- Provision explicitly for the disposable candidate runtime.
GRANT SELECT (id,telegram_id,balance_payment_preference) ON public.customers TO candidate_runtime;
GRANT UPDATE (balance_payment_preference) ON public.customers TO candidate_runtime;
GRANT SELECT ON public.customer_api_credentials, public.customer_api_usage, public.customer_api_settings TO candidate_runtime;
GRANT INSERT (customer_id,selector,secret_digest,created_at) ON public.customer_api_credentials TO candidate_runtime;
GRANT UPDATE (revoked_at,rotation_digest,rotation_message_id,rotation_expires_at,last_used_at) ON public.customer_api_credentials TO candidate_runtime;
GRANT INSERT (customer_id,request_count) ON public.customer_api_usage TO candidate_runtime;
GRANT UPDATE (request_count,last_used_at) ON public.customer_api_usage TO candidate_runtime;
-- PostgreSQL row-share locks require UPDATE privilege; this does not grant gate updates.
GRANT UPDATE (updated_at) ON public.customer_api_settings TO candidate_runtime;
GRANT USAGE ON SEQUENCE public.customer_api_credentials_id_seq TO candidate_runtime;
