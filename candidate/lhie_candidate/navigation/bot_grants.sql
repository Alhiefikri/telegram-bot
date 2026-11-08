-- P24 Store Bot command authority. Apply only in a disposable or explicitly
-- qualified runtime, after navigation/grants.sql. Startup installs nothing.
GRANT SELECT (id,customer_id,origin,status,expires_at) ON public.carts TO candidate_runtime;
GRANT UPDATE (status,updated_at) ON public.carts TO candidate_runtime;
