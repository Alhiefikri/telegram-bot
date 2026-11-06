-- P17 disposable provisioning only. Never applied automatically at startup.
-- This file does not enable funding or grant signing/custody authority.
GRANT SELECT ON public.customer_usdt_funding_policies, public.customer_usdt_funding_pauses,
    public.customer_usdt_funding_reservations, public.customer_usdt_funding_intents,
    public.customer_usdt_top_ups, public.customer_usdt_top_up_work,
    public.customer_usdt_top_up_settlement_work, public.customer_usdt_top_up_inbox,
    public.customer_usdt_top_up_evidence, public.customer_usdt_top_up_resolutions,
    public.customer_usdt_top_up_fee_events, public.customer_usdt_deposit_receivers,
    public.usdt_bsc_rail_invoices, public.usdt_bsc_scan_checkpoints,
    public.usdt_bsc_transfer_observations, public.usdt_reorg_receiver_evidence,
    public.telegram_navigation_roots TO candidate_runtime;
GRANT SELECT (locale) ON public.customers TO candidate_runtime;
GRANT UPDATE (updated_at) ON public.customers,public.customer_usdt_deposit_receivers TO candidate_runtime;
GRANT INSERT (customer_id,chat_id,message_id,media_kind,screen,locale,banner_reference,generation,rendered_at,created_at,updated_at)
    ON public.telegram_navigation_roots TO candidate_runtime;
GRANT UPDATE (chat_id,message_id,media_kind,screen,locale,banner_reference,generation,rendered_at,updated_at)
    ON public.telegram_navigation_roots TO candidate_runtime;
GRANT INSERT (customer_id,telegram_navigation_root_id,chat_id,canonical_message_id,root_generation,nonce,currency,rail,
    amount_micro,fee_micro,payable_micro,policy_version,terms_version,receiver_id,payment_snapshot,
    request_fingerprint,state,version,expires_at,created_at,claimed_at)
    ON public.customer_usdt_funding_intents TO candidate_runtime;
GRANT UPDATE (state,version,claimed_at) ON public.customer_usdt_funding_intents TO candidate_runtime;
GRANT INSERT (customer_id,source_type,source_id,rail,amount_micro,fee_micro,policy_version,terms_version,
    request_fingerprint,state,reserved_at,expires_at,created_at,updated_at)
    ON public.customer_usdt_funding_reservations TO candidate_runtime;
GRANT UPDATE (state,resolved_at,resolution_kind,resolution_reference,resolution_actor,settled_entry_id,updated_at)
    ON public.customer_usdt_funding_reservations TO candidate_runtime;
GRANT INSERT (id,intent_id,reservation_id,customer_id,reference,currency,rail,amount_micro,fee_micro,payable_micro,
    policy_version,terms_version,receiver_id,payment_snapshot,request_fingerprint,expires_at,created_at)
    ON public.customer_usdt_top_ups TO candidate_runtime;
GRANT UPDATE (id) ON public.customer_usdt_top_ups TO candidate_runtime;
GRANT INSERT (top_up_id,state,version,attempts,next_attempt_at,updated_at) ON public.customer_usdt_top_up_work TO candidate_runtime;
GRANT UPDATE (state,version,attempts,lease_token,lease_until,rail_invoice_id,last_error,next_attempt_at,updated_at)
    ON public.customer_usdt_top_up_work TO candidate_runtime;
GRANT INSERT (top_up_id,next_check_at,updated_at) ON public.customer_usdt_top_up_settlement_work TO candidate_runtime;
GRANT UPDATE (state,version,evidence_version,observation_cursor,attempts,lease_token,lease_until,next_check_at,last_error,updated_at)
    ON public.customer_usdt_top_up_settlement_work TO candidate_runtime;
GRANT INSERT (top_up_id,tx_hash,provider_reference,state,attempts,next_check_at,created_at)
    ON public.customer_usdt_top_up_inbox TO candidate_runtime;
GRANT UPDATE (state,attempts,next_check_at) ON public.customer_usdt_top_up_inbox TO candidate_runtime;
GRANT INSERT (top_up_id,inbox_id,version,status,reason,chain_evidence,created_at)
    ON public.customer_usdt_top_up_evidence TO candidate_runtime;
GRANT INSERT (top_up_id,kind,receipt_id,entry_id,evidence_id,late,created_at)
    ON public.customer_usdt_top_up_resolutions TO candidate_runtime;
GRANT INSERT (top_up_id,receipt_id,currency,amount_micro,debit_leg,credit_leg,created_at)
    ON public.customer_usdt_top_up_fee_events TO candidate_runtime;
GRANT INSERT (id,reference,customer_id,receiver_id,request_fingerprint,currency,amount_micro,fee_micro,payable_micro,
    chain_id,token_contract,token_decimals,receiver_address,custody_namespace,minimum_confirmations,
    requires_finalized_block,start_block,starts_at_epoch,expires_at_epoch,created_at)
    ON public.usdt_bsc_rail_invoices TO candidate_runtime;
GRANT INSERT (receiver_id,first_block,version,updated_at) ON public.usdt_bsc_scan_checkpoints TO candidate_runtime;
GRANT UPDATE (last_block,last_block_hash,version,review_reason,last_attempt_at,updated_at)
    ON public.usdt_bsc_scan_checkpoints TO candidate_runtime;
GRANT INSERT (receiver_id,chain_id,tx_hash,log_index,block_number,block_hash,token_contract,receiver_address,amount_units,observed_at)
    ON public.usdt_bsc_transfer_observations TO candidate_runtime;
GRANT INSERT (receiver_id,checkpoint_version,reason,evidence,created_at) ON public.usdt_reorg_receiver_evidence TO candidate_runtime;
GRANT USAGE ON SEQUENCE public.telegram_navigation_roots_id_seq,public.customer_usdt_funding_intents_id_seq,
    public.customer_usdt_funding_reservations_id_seq,public.customer_usdt_top_up_inbox_id_seq,
    public.customer_usdt_top_up_evidence_id_seq,public.usdt_bsc_transfer_observations_id_seq,
    public.usdt_reorg_receiver_evidence_id_seq TO candidate_runtime;
