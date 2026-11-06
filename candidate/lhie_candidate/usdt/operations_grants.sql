-- Explicit disposable operational provisioning. No ledger or receipt history UPDATE/DELETE.
GRANT SELECT ON public.usdt_reorg_monitor,public.usdt_reorg_incidents,
    public.usdt_reorg_recovery_requests,public.usdt_reorg_recovery_cases,
    public.usdt_treasury_movements,public.usdt_balance_reconciliation_runs,
    public.balance_operations_alert_episodes,public.balance_restore_gates TO candidate_runtime;
GRANT SELECT (id,is_active,admin_telegram_chat_id) ON public.manual_payment_settings TO candidate_runtime;
GRANT UPDATE (updated_at) ON public.users TO candidate_runtime;
GRANT SELECT (evidence_payload) ON public.external_money_receipts TO candidate_runtime;
GRANT UPDATE (id) ON public.usdt_reorg_incidents TO candidate_runtime;
GRANT UPDATE (cursor,version,updated_at) ON public.usdt_reorg_monitor TO candidate_runtime;
GRANT INSERT (top_up_id,customer_id,account_id,receipt_id,entry_id,receiver_id,reason,exposure_micro,
    available_exposure_micro,spent_exposure_micro,account_version,checkpoint_version,evidence,created_at)
    ON public.usdt_reorg_incidents TO candidate_runtime;
GRANT INSERT (id,incident_id,owner_user_id,nonce,reason,fingerprint,credential_binding,session_binding,expires_at,created_at)
    ON public.usdt_reorg_recovery_requests TO candidate_runtime;
GRANT INSERT (incident_id,request_id,authorized_by,status,created_at) ON public.usdt_reorg_recovery_cases TO candidate_runtime;
GRANT INSERT (scope,customer_id,cursor_after,receipt_cursor_after,credit_cursor_after,"limit",checked_at,created_at,verdict,
    accounts_inspected,account_finding_count,drift_frozen_count,receipt_finding_count,liability_micro,unresolved_deposit_micro,
    custody_state,custody_assets_micro,movement_in_micro,movement_out_micro,movement_count,aggregate_pending_events,actor,findings)
    ON public.usdt_balance_reconciliation_runs TO candidate_runtime;
GRANT INSERT (scope,reason,paused_by_user_id,paused_at,created_at,updated_at) ON public.customer_usdt_funding_pauses TO candidate_runtime;
GRANT DELETE ON public.customer_usdt_funding_pauses TO candidate_runtime;
GRANT INSERT (alert_key,severity,state,summary,fingerprint,evidence,occurrences,open_count,first_seen_at,last_seen_at,created_at,updated_at)
    ON public.balance_operations_alert_episodes TO candidate_runtime;
GRANT UPDATE (severity,state,summary,fingerprint,evidence,occurrences,open_count,last_seen_at,resolved_at,updated_at)
    ON public.balance_operations_alert_episodes TO candidate_runtime;
GRANT UPDATE (state,window_start,window_end,reason,opened_by,opened_at,replay_completed_at,replay_by,replay_evidence,
    cleared_at,cleared_by,cleared_evidence,updated_at) ON public.balance_restore_gates TO candidate_runtime;
GRANT SELECT ON public.usdt_top_up_review_requests,public.usdt_top_up_external_return_authorizations TO candidate_runtime;
GRANT INSERT (id,top_up_id,owner_user_id,decision,amount_micro,transaction_hash,provider_reference,
    refund_transaction_hash,refund_address,refund_authorization_id,reason,fingerprint,version,work_version,
    checkpoint_version,target_fingerprint,session_binding,credential_binding,chain_evidence,refund_evidence,expires_at,created_at)
    ON public.usdt_top_up_review_requests TO candidate_runtime;
GRANT INSERT (id,top_up_id,customer_id,amount_micro,chain_id,token_contract,destination_address,nonce,created_at)
    ON public.usdt_top_up_external_return_authorizations TO candidate_runtime;
GRANT INSERT (review_request_id,refund_receipt_id,resolved_amount_micro) ON public.customer_usdt_top_up_resolutions TO candidate_runtime;
GRANT USAGE ON SEQUENCE public.usdt_reorg_incidents_id_seq,public.usdt_reorg_recovery_cases_id_seq,
    public.usdt_balance_reconciliation_runs_id_seq,public.customer_usdt_funding_pauses_id_seq,
    public.balance_operations_alert_episodes_id_seq TO candidate_runtime;
