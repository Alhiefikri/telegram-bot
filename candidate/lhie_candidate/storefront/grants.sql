-- Apply explicitly for P21 after the existing auth, checkout and payment grants.
-- Customer, Cart and CartItem writes use the already-provisioned P19/P07 columns.
GRANT UPDATE (credential_access_key_hash, notification_email, support_contact_channel, support_contact_value) ON public.orders TO candidate_runtime;
GRANT INSERT (order_id, event_type, status, created_at, updated_at) ON public.order_notification_events TO candidate_runtime;
GRANT USAGE, SELECT ON SEQUENCE public.order_notification_events_id_seq TO candidate_runtime;
