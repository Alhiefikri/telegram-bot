-- Optional operator surface only; not installed by payment-worker provisioning/startup.
GRANT UPDATE (payment_mode,payment_provider_version,is_active,sakurupiah_channel,updated_at)
    ON public.manual_payment_settings TO candidate_runtime;
