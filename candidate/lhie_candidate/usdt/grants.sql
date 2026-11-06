-- Explicit disposable provisioning; startup never grants financial privileges.
GRANT UPDATE (frozen_reason,updated_at) ON public.customer_usdt_balance_accounts TO candidate_runtime;
GRANT SELECT (id) ON public.customer_usdt_balance_accounting_events TO candidate_runtime;
GRANT SELECT ON public.usdt_balance_accounting_aggregate_claims,public.usdt_balance_accounting_aggregates TO candidate_runtime;
GRANT INSERT (consumer,currency,customer_usdt_balance_accounting_event_id,created_at)
    ON public.usdt_balance_accounting_aggregate_claims TO candidate_runtime;
GRANT INSERT (consumer,currency,debit_account,credit_account,entries_count,amount_micro,first_event_id,last_event_id,created_at,updated_at)
    ON public.usdt_balance_accounting_aggregates TO candidate_runtime;
GRANT UPDATE (entries_count,amount_micro,first_event_id,last_event_id,updated_at)
    ON public.usdt_balance_accounting_aggregates TO candidate_runtime;
GRANT USAGE ON SEQUENCE public.usdt_balance_accounting_aggregate_claims_id_seq,
    public.usdt_balance_accounting_aggregates_id_seq TO candidate_runtime;
GRANT INSERT (chain_id,address_normalized,channel,claimed_at) ON public.usdt_receiver_address_claims TO candidate_runtime;
GRANT USAGE ON SEQUENCE public.usdt_receiver_address_claims_id_seq TO candidate_runtime;

GRANT SELECT (id,customer_id,address_claim_id,chain_id,address_normalized,token_contract,token_decimals,
    custody_namespace,verification_status,retired_at) ON public.customer_usdt_deposit_receivers TO candidate_runtime;