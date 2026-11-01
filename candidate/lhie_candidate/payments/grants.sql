-- Applied explicitly by the disposable P08 harness, never at application startup.
GRANT SELECT (id,expires_at,total_amount,currency,order_status,payment_status)
    ON public.orders TO candidate_runtime;
GRANT SELECT (id,order_id,provider,method,currency,amount,merchant_ref,sakurupiah_channel,
    expired_at,status,provider_reference,provider_total_payment,provider_fee,provider_expires_at,
    provider_presentation,provider_create_state,provider_create_attempted_at,provider_payment_method)
    ON public.payments TO candidate_runtime;
GRANT SELECT (id,provider,event_type,merchant_ref,provider_reference,provider_status,
    idempotency_key,received_count,processed_at) ON public.payment_events TO candidate_runtime;
GRANT SELECT (id,canonical_key,fingerprint,status,target_type,target_id) ON public.external_money_receipts TO candidate_runtime;
GRANT SELECT (alias_key,external_money_receipt_id) ON public.external_money_receipt_aliases TO candidate_runtime;
GRANT SELECT (id,order_id,payment_id,state) ON public.manual_fulfillment_reviews TO candidate_runtime;
GRANT INSERT (provider,event_type,merchant_ref,provider_reference,provider_status,raw_payload,
    idempotency_key,received_at,last_received_at,received_count) ON public.payment_events TO candidate_runtime;
GRANT UPDATE (last_received_at,received_count,processed_at) ON public.payment_events TO candidate_runtime;
GRANT INSERT (canonical_key,provider,identity_kind,account_namespace,reference,beneficiary,currency,
    amount_units,decimals,status,target_type,target_id,fingerprint,evidence_payload,claimed_at,created_at,updated_at)
    ON public.external_money_receipts TO candidate_runtime;
GRANT UPDATE (updated_at) ON public.external_money_receipts TO candidate_runtime;
GRANT INSERT (external_money_receipt_id,alias_key,alias_type,created_at)
    ON public.external_money_receipt_aliases TO candidate_runtime;
GRANT INSERT (order_id,payment_id,amount,currency,reason,state,provider_reference,created_at,updated_at)
    ON public.manual_fulfillment_reviews TO candidate_runtime;
GRANT UPDATE (provider_reference,provider_total_payment,provider_fee,provider_expires_at,
    provider_payment_method,provider_presentation,provider_create_state,provider_create_attempted_at,
    provider_create_last_error,updated_at) ON public.payments TO candidate_runtime;
GRANT USAGE, SELECT ON SEQUENCE public.payment_events_id_seq,
    public.external_money_receipts_id_seq, public.external_money_receipt_aliases_id_seq,
    public.manual_fulfillment_reviews_id_seq TO candidate_runtime;
