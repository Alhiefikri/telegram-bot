-- Apply explicitly; the activity reader does not change ledger or funding rows.
GRANT SELECT (id, customer_id, intent_id, created_at, amount_micro, reference)
    ON public.customer_usdt_top_ups TO candidate_runtime;
GRANT SELECT (top_up_id) ON public.customer_usdt_top_up_resolutions TO candidate_runtime;
GRANT SELECT (id, customer_id) ON public.customer_usdt_balance_accounts TO candidate_runtime;
GRANT SELECT (id, customer_usdt_balance_account_id, created_at, amount_micro, kind,
              source_type, source_id, refund_order_id)
    ON public.customer_usdt_balance_entries TO candidate_runtime;
GRANT SELECT (id, customer_id, invoice_number) ON public.orders TO candidate_runtime;
