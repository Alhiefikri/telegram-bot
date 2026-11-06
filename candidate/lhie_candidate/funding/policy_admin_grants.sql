-- Explicit local provisioning for the authenticated policy administration owner.
GRANT SELECT (id,role) ON public.users TO candidate_runtime;
GRANT UPDATE (updated_at) ON public.users TO candidate_runtime;
GRANT SELECT (id,singleton_key,minimum_amount,preset_amounts,version)
    ON public.store_balance_funding_policies TO candidate_runtime;
GRANT UPDATE (minimum_amount,preset_amounts,version,updated_at)
    ON public.store_balance_funding_policies TO candidate_runtime;
GRANT INSERT (user_id,action,subject_type,subject_id,old_values,new_values,ip_address,created_at)
    ON public.audit_logs TO candidate_runtime;
GRANT USAGE,SELECT ON SEQUENCE public.audit_logs_id_seq TO candidate_runtime;
