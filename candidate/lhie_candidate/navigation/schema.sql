-- Explicit disposable/local provisioning only. Never executed by candidate startup.
CREATE SCHEMA IF NOT EXISTS django_candidate;

CREATE TABLE django_candidate.navigation_roots (
    bot_asset_scope varchar(191) NOT NULL,
    customer_id bigint NOT NULL REFERENCES public.customers(id),
    chat_id varchar(32) NOT NULL,
    message_id bigint NULL CHECK (message_id > 0),
    media_kind varchar(16) NULL CHECK (media_kind IN ('text', 'photo', 'animation')),
    screen varchar(32) NULL,
    locale varchar(8) NULL,
    banner_reference text NULL,
    banner_version bigint NULL,
    banner_kind varchar(16) NOT NULL DEFAULT 'photo' CHECK (banner_kind IN ('photo', 'animation')),
    generation bigint NOT NULL DEFAULT 0 CHECK (generation >= 0),
    render_token uuid NULL,
    lease_expires_at timestamptz NULL,
    rendered_at timestamptz NULL,
    PRIMARY KEY (bot_asset_scope, customer_id),
    UNIQUE (bot_asset_scope, chat_id),
    CHECK ((render_token IS NULL) = (lease_expires_at IS NULL)),
    CHECK ((message_id IS NULL AND media_kind IS NULL AND screen IS NULL AND rendered_at IS NULL)
        OR (message_id IS NOT NULL AND media_kind IS NOT NULL AND screen IS NOT NULL AND rendered_at IS NOT NULL))
);
