-- Explicit local/disposable provisioning only; startup never changes grants.
-- This is the minimum authority for the #719 sandbox funding slice: one IDR
-- ledger owner, one top-up writer and the existing external-receipt registry.
GRANT USAGE ON SCHEMA public TO candidate_runtime;
GRANT SELECT (id,singleton_key,minimum_amount,preset_amounts,version)
    ON public.store_balance_funding_policies TO candidate_runtime;
GRANT UPDATE (updated_at) ON public.store_balance_funding_policies TO candidate_runtime;
GRANT SELECT (id,is_active,payment_mode,sakurupiah_channel,payment_provider_version,
    qris_payment_ttl_minutes,admin_telegram_chat_id)
    ON public.manual_payment_settings TO candidate_runtime;
GRANT UPDATE (updated_at) ON public.manual_payment_settings TO candidate_runtime;
GRANT SELECT (id,telegram_id,locale) ON public.customers TO candidate_runtime;
GRANT UPDATE (updated_at) ON public.customers TO candidate_runtime;
GRANT SELECT (id,customer_id,balance) ON public.customer_balance_accounts TO candidate_runtime;
GRANT SELECT (id,customer_balance_account_id,kind,amount,balance_after,source_type,source_id,
    refund_order_id,idempotency_key,created_at) ON public.store_balance_entries TO candidate_runtime;

GRANT SELECT (id,customer_id,telegram_navigation_root_id,chat_id,canonical_message_id,
    policy_version,amount,nonce,status,expires_at,claimed_at,created_at,updated_at)
    ON public.store_balance_funding_intents TO candidate_runtime;
GRANT INSERT (customer_id,telegram_navigation_root_id,chat_id,canonical_message_id,policy_version,
    amount,nonce,status,expires_at,claimed_at,created_at,updated_at)
    ON public.store_balance_funding_intents TO candidate_runtime;
GRANT UPDATE (status,claimed_at,updated_at) ON public.store_balance_funding_intents TO candidate_runtime;

GRANT SELECT (id,customer_id,store_balance_funding_intent_id,telegram_navigation_root_id,chat_id,
    canonical_message_id,locale,provider,payment_setting_id,payment_provider_version,merchant_ref,idempotency_key,currency,amount,status,
    provider_reference,provider_qris_url,provider_fee,provider_total_payment,provider_payment_method,
    sakurupiah_channel,provider_created_at,provider_expires_at,expires_at,provider_create_state,
    provider_create_attempted_at,provider_create_last_error,paid_at,settled_at,created_at,updated_at,
    payment_proof_file_id,payment_proof_submitted_at,payment_proof_admin_chat_id)
    ON public.store_balance_top_ups TO candidate_runtime;
GRANT INSERT (customer_id,store_balance_funding_intent_id,telegram_navigation_root_id,chat_id,
    canonical_message_id,locale,provider,payment_setting_id,payment_provider_version,merchant_ref,
    idempotency_key,currency,amount,sakurupiah_channel,status,provider_create_state,
    provider_create_attempted_at,expires_at,created_at,updated_at,provider_payment_method,
    provider_fee,provider_total_payment,provider_created_at,provider_expires_at) ON public.store_balance_top_ups TO candidate_runtime;
GRANT UPDATE (status,provider_reference,provider_payload,provider_qris_url,provider_fee,
    provider_total_payment,provider_payment_method,provider_created_at,provider_expires_at,
    provider_create_state,provider_create_attempted_at,provider_create_last_error,paid_at,settled_at,
    updated_at,payment_proof_file_id,payment_proof_submitted_at,payment_proof_admin_chat_id) ON public.store_balance_top_ups TO candidate_runtime;

GRANT SELECT (id,store_balance_top_up_id,provider,event_type,merchant_ref,provider_reference,
    provider_status,idempotency_key,received_count,received_at,processed_at)
    ON public.store_balance_top_up_events TO candidate_runtime;
GRANT INSERT (store_balance_top_up_id,provider,event_type,merchant_ref,provider_reference,
    provider_status,raw_payload,idempotency_key,received_count,received_at,last_received_at,
    processed_at) ON public.store_balance_top_up_events TO candidate_runtime;

GRANT SELECT (id,canonical_key,fingerprint,status,target_type,target_id,provider,identity_kind,reference,account_namespace)
    ON public.external_money_receipts TO candidate_runtime;
GRANT SELECT (alias_key,external_money_receipt_id) ON public.external_money_receipt_aliases TO candidate_runtime;
GRANT INSERT (canonical_key,provider,identity_kind,account_namespace,reference,beneficiary,currency,
    amount_units,decimals,status,target_type,target_id,fingerprint,evidence_payload,claimed_at,
    created_at,updated_at) ON public.external_money_receipts TO candidate_runtime;
GRANT UPDATE (updated_at) ON public.external_money_receipts TO candidate_runtime;
GRANT INSERT (external_money_receipt_id,alias_key,alias_type,created_at)
    ON public.external_money_receipt_aliases TO candidate_runtime;
GRANT SELECT (legacy_type,legacy_id,reason,resolved_at) ON public.external_money_receipt_reservations TO candidate_runtime;
GRANT UPDATE (resolved_at,updated_at) ON public.external_money_receipt_reservations TO candidate_runtime;
GRANT INSERT (action,subject_type,subject_id,old_values,new_values,created_at) ON public.audit_logs TO candidate_runtime;
GRANT USAGE, SELECT ON SEQUENCE public.audit_logs_id_seq TO candidate_runtime;
GRANT SELECT (effect_key) ON public.telegram_outbound_effects TO candidate_runtime;
GRANT INSERT (id,effect_key,effect_group_key,sequence,effect_type,source_type,source_id,target_type,
    telegram_chat_id,queue,telegram_method,locale,template_version,state,attempts,next_attempt_at,
    outcome_metadata,created_at,updated_at) ON public.telegram_outbound_effects TO candidate_runtime;

GRANT INSERT (customer_id,balance,created_at,updated_at) ON public.customer_balance_accounts TO candidate_runtime;
GRANT INSERT (customer_balance_account_id,kind,amount,balance_after,source_type,source_id,
    idempotency_key,created_at,refund_order_id) ON public.store_balance_entries TO candidate_runtime;
GRANT UPDATE (balance,updated_at) ON public.customer_balance_accounts TO candidate_runtime;

GRANT USAGE, SELECT ON SEQUENCE public.store_balance_funding_intents_id_seq,
    public.store_balance_top_ups_id_seq, public.store_balance_top_up_events_id_seq,
    public.external_money_receipts_id_seq, public.external_money_receipt_aliases_id_seq,
    public.customer_balance_accounts_id_seq, public.store_balance_entries_id_seq TO candidate_runtime;
