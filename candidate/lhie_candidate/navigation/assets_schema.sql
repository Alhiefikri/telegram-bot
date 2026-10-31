-- Explicit local provisioning only; never run from application startup.
CREATE TABLE IF NOT EXISTS django_candidate.navigation_assets (
    bot_scope text NOT NULL CHECK (bot_scope IN ('synthetic', 'LhieStoreTestBot')),
    source_path text NOT NULL,
    file_id text NOT NULL CHECK (file_id <> '' AND file_id !~ '^(https?://|/)'),
    version bigint NOT NULL DEFAULT 1 CHECK (version > 0),
    PRIMARY KEY (bot_scope, source_path)
);
