-- Apply explicitly to the storefront runtime role where these columns are not already granted.
GRANT SELECT (id, customer_id) ON public.customer_balance_accounts TO candidate_runtime;
GRANT SELECT (id, customer_id, created_at, amount, status, provider, provider_create_state,
              expires_at, provider_expires_at)
    ON public.store_balance_top_ups TO candidate_runtime;
-- Quote validation needs the persisted provider snapshot and protected QR URL.
GRANT SELECT (currency, provider_payment_method, merchant_ref, provider_reference,
              provider_fee, provider_total_payment, provider_payload, provider_qris_url)
    ON public.store_balance_top_ups TO candidate_runtime;
GRANT SELECT (id, customer_balance_account_id, created_at, kind, amount, source_type,
              source_id, refund_order_id)
    ON public.store_balance_entries TO candidate_runtime;
GRANT SELECT (id, customer_id, invoice_number) ON public.orders TO candidate_runtime;
