-- Explicit disposable P25 provisioning only; no lifecycle or financial writes.
GRANT SELECT ON public.product_waitlist_subscriptions, public.product_waitlist_events,
    public.product_waitlist_notifications TO candidate_runtime;
GRANT INSERT (product_id,email,email_hash,status,confirmation_expires_at,created_at,updated_at)
    ON public.product_waitlist_subscriptions TO candidate_runtime;
GRANT UPDATE (email,status,confirmation_expires_at,confirmation_sent_at,confirmation_delivery_started_at,
    confirmation_attempts,confirmation_last_error,confirmed_at,unsubscribed_at,notified_at,updated_at)
    ON public.product_waitlist_subscriptions TO candidate_runtime;
GRANT INSERT (inventory_movement_id,product_id,available_stock,status,created_at,updated_at)
    ON public.product_waitlist_events TO candidate_runtime;
GRANT UPDATE (materialization_cursor,status,materialized_at,skipped_at,updated_at)
    ON public.product_waitlist_events TO candidate_runtime;
GRANT INSERT (product_waitlist_event_id,subscription_id,status,created_at,updated_at)
    ON public.product_waitlist_notifications TO candidate_runtime;
GRANT UPDATE (status,attempts,delivery_started_at,sent_at,failed_at,next_attempt_at,last_error,updated_at)
    ON public.product_waitlist_notifications TO candidate_runtime;
GRANT USAGE, SELECT ON SEQUENCE public.product_waitlist_subscriptions_id_seq,
    public.product_waitlist_events_id_seq,public.product_waitlist_notifications_id_seq TO candidate_runtime;
