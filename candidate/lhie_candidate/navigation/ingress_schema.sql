-- Explicit local candidate metadata only; never executed on application startup.
CREATE TABLE django_candidate.navigation_updates (
    update_id bigint PRIMARY KEY CHECK (update_id > 0),
    chat_id bigint NOT NULL CHECK (chat_id <> 0),
    payload jsonb NOT NULL,
    state varchar(16) NOT NULL DEFAULT 'pending'
        CHECK (state IN ('pending', 'processing', 'processed', 'unknown')),
    accepted_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    claimed_at timestamptz,
    completed_at timestamptz,
    outcome varchar(32)
);
CREATE INDEX navigation_updates_pending ON django_candidate.navigation_updates (update_id)
    WHERE state = 'pending';
CREATE TABLE django_candidate.navigation_sessions (
    customer_id bigint PRIMARY KEY CHECK (customer_id > 0),
    state jsonb NOT NULL DEFAULT '{}'::jsonb
);
