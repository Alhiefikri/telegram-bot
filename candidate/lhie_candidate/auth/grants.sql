-- Reviewed disposable provisioning only. Startup never installs these grants.
GRANT SELECT (id,email,email_verified_at,password,password_scheme,auth_version,enrollment_version,telegram_id,first_name,username,created_at,updated_at)
    ON public.customers TO candidate_runtime;
GRANT INSERT (email,email_verified_at,first_name,auth_version,enrollment_version,created_at,updated_at)
    ON public.customers TO candidate_runtime;
GRANT UPDATE (email,email_verified_at,password,password_scheme,auth_version,enrollment_version,telegram_id,updated_at)
    ON public.customers TO candidate_runtime;
GRANT SELECT ON public.customer_auth_settings, public.customer_auth_tokens,
    public.customer_login_links, public.customer_auth_mail_works,
    public.customer_google_auth_claims, public.customer_telegram_auth_claims,
    public.customer_identities TO candidate_runtime;
GRANT INSERT (customer_id,email,purpose,token_hash,challenge_hash,session_binding_hash,proposed_password_hash,
    auth_version,enrollment_version,attempts,expires_at,consumed_at,created_at,updated_at)
    ON public.customer_auth_tokens TO candidate_runtime;
GRANT UPDATE (attempts,consumed_at,updated_at) ON public.customer_auth_tokens TO candidate_runtime;
GRANT INSERT (email,token_hash,expires_at,used_at,requested_ip,created_at,updated_at)
    ON public.customer_login_links TO candidate_runtime;
GRANT UPDATE (used_at,updated_at) ON public.customer_login_links TO candidate_runtime;
GRANT INSERT (auth_token_id,login_link_id,payload,status,attempts,expires_at,next_attempt_at,sent_at,last_error_code,created_at,updated_at)
    ON public.customer_auth_mail_works TO candidate_runtime;
GRANT UPDATE (payload,status,attempts,next_attempt_at,sent_at,last_error_code,updated_at)
    ON public.customer_auth_mail_works TO candidate_runtime;
GRANT INSERT (operation,customer_id,auth_version,authenticated_at,state_hash,session_binding_hash,configuration_fingerprint,expires_at,created_at,updated_at)
    ON public.customer_google_auth_claims TO candidate_runtime;
GRANT INSERT (operation,customer_id,auth_version,authenticated_at,session_binding_hash,capability_hash,expires_at,created_at,updated_at)
    ON public.customer_telegram_auth_claims TO candidate_runtime;
GRANT UPDATE (consumed_at,updated_at) ON public.customer_google_auth_claims TO candidate_runtime;
GRANT UPDATE (actor_id,proof_customer_id,proof_auth_version,confirmation_hash,prepared_at,confirmed_at,consumed_at,failure,updated_at)
    ON public.customer_telegram_auth_claims TO candidate_runtime;
GRANT INSERT (customer_id,provider,provider_subject,provider_email,last_used_at,created_at,updated_at)
    ON public.customer_identities TO candidate_runtime;
GRANT UPDATE (last_used_at,provider_email,updated_at) ON public.customer_identities TO candidate_runtime;
GRANT DELETE ON public.customer_identities TO candidate_runtime;
GRANT SELECT (id,email,password,name,role) ON public.users TO candidate_runtime;
GRANT SELECT ON public.orders TO candidate_runtime;
GRANT UPDATE (customer_id,updated_at) ON public.orders TO candidate_runtime;
GRANT INSERT (action,subject_type,subject_id,new_values,ip_address,created_at) ON public.audit_logs TO candidate_runtime;
GRANT USAGE,SELECT ON SEQUENCE public.customers_id_seq,public.customer_auth_tokens_id_seq,
    public.customer_login_links_id_seq,public.customer_auth_mail_works_id_seq,
    public.customer_google_auth_claims_id_seq,public.customer_telegram_auth_claims_id_seq,
    public.customer_identities_id_seq,public.audit_logs_id_seq TO candidate_runtime;
