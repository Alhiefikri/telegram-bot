-- Explicit disposable qualification only; startup does not provision privileges.
GRANT SELECT (id,telegram_id,username,locale) ON public.customers TO candidate_runtime;
GRANT SELECT (id,email,name,password,role) ON public.users TO candidate_runtime;
GRANT SELECT (id,customer_id,amount,currency,status,provider_created_at,provider_reference,provider,chat_id)
    ON public.store_balance_top_ups TO candidate_runtime;
GRANT SELECT (id,order_id,payment_id,customer_id,warranty_claim_id,supplier_account_unit_id,
    supplier_fulfillment_id,missing_quantity,amount,currency,state,external_reference,external_reference_hash)
    ON public.order_refunds TO candidate_runtime;
GRANT SELECT (id,customer_id,owner_user_id,telegram_id,username,session_binding,credential_binding,
    mode,currency,amount,reason,category,source_id,receipt_fingerprint,fingerprint,version,state,
    ledger_entry_id,expires_at,resolved_at,created_at,updated_at)
    ON public.balance_adjustment_requests TO candidate_runtime;
GRANT INSERT (id,customer_id,owner_user_id,telegram_id,username,session_binding,credential_binding,
    mode,currency,amount,reason,category,source_id,receipt_fingerprint,fingerprint,version,state,
    expires_at,created_at,updated_at)
    ON public.balance_adjustment_requests TO candidate_runtime;
GRANT UPDATE (state,ledger_entry_id,resolved_at,updated_at) ON public.balance_adjustment_requests TO candidate_runtime;
GRANT SELECT (request_id,debit_account) ON public.balance_adjustment_accounting_events TO candidate_runtime;
GRANT INSERT (request_id,ledger_entry_id,currency,amount,debit_account,credit_account,source_fingerprint,created_at)
    ON public.balance_adjustment_accounting_events TO candidate_runtime;
GRANT SELECT (user_id,failed_at,locked_until,episode,verified_request_id,verified_fingerprint,lease_token,lease_until)
    ON public.balance_adjustment_auth_states TO candidate_runtime;
GRANT INSERT (user_id,failed_at) ON public.balance_adjustment_auth_states TO candidate_runtime;
GRANT UPDATE (failed_at,locked_until,episode,verified_request_id,verified_fingerprint,lease_token,lease_until)
    ON public.balance_adjustment_auth_states TO candidate_runtime;
GRANT SELECT (id,store_balance_top_up_id,customer_id,owner_user_id,ledger_entry_id,resolution_type,
    idempotency_key,provider_reference,reason,amount,resolved_at,created_at)
    ON public.store_balance_compensations TO candidate_runtime;
GRANT INSERT (store_balance_top_up_id,customer_id,owner_user_id,ledger_entry_id,resolution_type,
    idempotency_key,provider_reference,reason,amount,resolved_at,created_at)
    ON public.store_balance_compensations TO candidate_runtime;
GRANT INSERT (source,legacy_type,legacy_id,provider,reason,details,reserved_at,created_at,updated_at)
    ON public.external_money_receipt_reservations TO candidate_runtime;
GRANT SELECT (source,legacy_type,legacy_id,provider,reason,resolved_at)
    ON public.external_money_receipt_reservations TO candidate_runtime;
GRANT SELECT (id,canonical_key,fingerprint,status,target_type,target_id,provider,identity_kind,
    reference,account_namespace) ON public.external_money_receipts TO candidate_runtime;
GRANT INSERT (canonical_key,provider,identity_kind,account_namespace,reference,chain_id,tx_hash,log_index,
    beneficiary,currency,amount_units,decimals,status,target_type,target_id,fingerprint,evidence_payload,
    claimed_at,created_at,updated_at) ON public.external_money_receipts TO candidate_runtime;
GRANT UPDATE (updated_at,status,review_reason,claimed_at) ON public.external_money_receipts TO candidate_runtime;
GRANT SELECT (alias_key,external_money_receipt_id) ON public.external_money_receipt_aliases TO candidate_runtime;
GRANT INSERT (external_money_receipt_id,alias_key,alias_type,created_at)
    ON public.external_money_receipt_aliases TO candidate_runtime;
GRANT SELECT (id,chain_id,tx_hash,status,target_type,target_id) ON public.external_money_receipt_parent_claims TO candidate_runtime;
GRANT INSERT (chain_id,tx_hash,external_money_receipt_id,status,target_type,target_id,created_at,updated_at)
    ON public.external_money_receipt_parent_claims TO candidate_runtime;
GRANT UPDATE (status,review_reason,updated_at) ON public.external_money_receipt_parent_claims TO candidate_runtime;
GRANT INSERT (user_id,action,subject_type,subject_id,old_values,new_values,created_at)
    ON public.audit_logs TO candidate_runtime;
GRANT SELECT (id,effect_key,effect_type,source_id,target_type,telegram_chat_id,outcome_metadata)
    ON public.telegram_outbound_effects TO candidate_runtime;
GRANT INSERT (id,effect_key,effect_group_key,sequence,effect_type,source_type,source_id,target_type,
    telegram_chat_id,queue,telegram_method,locale,template_version,state,attempts,next_attempt_at,
    outcome_metadata,created_at,updated_at) ON public.telegram_outbound_effects TO candidate_runtime;
GRANT USAGE, SELECT ON SEQUENCE public.balance_adjustment_accounting_events_id_seq,
    public.store_balance_compensations_id_seq, public.external_money_receipt_reservations_id_seq,
    public.external_money_receipts_id_seq, public.external_money_receipt_aliases_id_seq,
    public.external_money_receipt_parent_claims_id_seq, public.audit_logs_id_seq TO candidate_runtime;
