CREATE TABLE IF NOT EXISTS django_candidate.browser_sessions (
    session_digest varchar(64) PRIMARY KEY CHECK (session_digest ~ '^[a-f0-9]{64}$'),
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(metadata) = 'object' AND octet_length(metadata::text) <= 16384),
    expires_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS browser_sessions_expiry ON django_candidate.browser_sessions (expires_at);
REVOKE ALL ON django_candidate.browser_sessions FROM PUBLIC;
GRANT SELECT, INSERT, UPDATE, DELETE ON django_candidate.browser_sessions TO candidate_runtime;
