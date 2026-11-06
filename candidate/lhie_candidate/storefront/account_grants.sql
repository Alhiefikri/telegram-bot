-- Explicit local qualification only; do not apply at startup.
GRANT SELECT (id,order_id,event_type,recipient_email,status,last_error,delivery_started_at)
    ON public.order_notification_events TO candidate_runtime;
GRANT UPDATE (status,attempts,next_attempt_at,delivery_started_at,failed_at,last_error,updated_at)
    ON public.order_notification_events TO candidate_runtime;
GRANT SELECT (id,first_name,username,email,created_at) ON public.customers TO candidate_runtime;
