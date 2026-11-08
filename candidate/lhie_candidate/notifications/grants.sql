-- Explicit P25 disposable provisioning only. No startup installation or money writes.
GRANT INSERT (sent_at,unknown_at) ON public.telegram_outbound_effects TO candidate_runtime;
GRANT SELECT ON public.telegram_broadcasts, public.telegram_broadcast_recipients,
    public.stock_notification_events, public.stock_notification_recipients,
    public.product_price_change_events, public.product_price_change_notification_recipients,
    public.order_notification_events TO candidate_runtime;
GRANT SELECT (notification_email) ON public.orders TO candidate_runtime;
GRANT SELECT (payment_proof_file_id,payment_proof_admin_chat_id,payment_proof_submitted_at,
    payment_proof_forwarded_at) ON public.payments TO candidate_runtime;
GRANT UPDATE (payment_proof_forwarded_at) ON public.payments TO candidate_runtime;
GRANT INSERT (broadcast_id,customer_id,telegram_id,locale,created_at,updated_at)
    ON public.telegram_broadcast_recipients TO candidate_runtime;
GRANT USAGE, SELECT ON SEQUENCE public.telegram_broadcast_recipients_id_seq,
    public.order_notification_events_id_seq TO candidate_runtime;
GRANT UPDATE (recipient_count,sent_count,failed_count,pending_count,terminal_failed_count,status,
    completed_at,updated_at) ON public.telegram_broadcasts TO candidate_runtime;
GRANT UPDATE (sent_at,failed_at,terminal_failed_at,retry_at,last_error,attempts,updated_at)
    ON public.telegram_broadcast_recipients TO candidate_runtime;
GRANT UPDATE (sent_at,failed_at,terminal_failed_at,last_error,attempts,updated_at)
    ON public.stock_notification_recipients TO candidate_runtime;
GRANT UPDATE (sent_at,failed_at,last_error,attempts,updated_at)
    ON public.product_price_change_notification_recipients TO candidate_runtime;
GRANT INSERT (order_id,event_type,recipient_email,status,attempts,next_attempt_at,created_at,updated_at)
    ON public.order_notification_events TO candidate_runtime;
GRANT UPDATE (status,attempts,delivery_started_at,next_attempt_at,sent_at,failed_at,last_error,updated_at)
    ON public.order_notification_events TO candidate_runtime;
