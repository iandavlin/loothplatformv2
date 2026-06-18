-- profile-app — slice zero schema.
--
-- Principles (cribbed from lg-stripe-billing):
--   1. Identity separated from entitlement. `users` is who.
--   2. UUIDv5(LOOTH_IDENTITY_NAMESPACE, lower(trim(email))) as external ref.
--      Email is the BOOTSTRAP; once a row exists, uuid is frozen forever.
--   3. WP coupling lives in wp_user_bridge — dropped at cutover.
--   4. Lazy profiles: this slice ONLY ships `users`. `profiles` table comes later.

BEGIN;

CREATE TABLE users (
    id                 BIGSERIAL    PRIMARY KEY,
    uuid               UUID         NOT NULL UNIQUE,
    primary_email      TEXT         NOT NULL UNIQUE,
    billing_email      TEXT,
    contact_email      TEXT,
    display_name       TEXT,
    slug               TEXT         UNIQUE,
    avatar_url         TEXT,
    location_text      TEXT,
    place_id           TEXT,
    lat                NUMERIC(9,6),
    lng                NUMERIC(9,6),
    location_country   TEXT,
    location_region    TEXT,
    location_city      TEXT,
    location_postcode  TEXT,
    location_precision TEXT         NOT NULL DEFAULT 'address',
    tier               TEXT,
    member_since       TIMESTAMPTZ,
    created_at         TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at         TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE TABLE email_aliases (
    email_normalized TEXT        PRIMARY KEY,
    user_id          BIGINT      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    source           TEXT        NOT NULL,    -- 'wp' | 'stripe' | 'patreon' | 'manual'
    first_seen_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_email_aliases_user ON email_aliases(user_id);

CREATE TABLE wp_user_bridge (
    user_id     BIGINT      NOT NULL PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    wp_user_id  BIGINT      NOT NULL UNIQUE,
    synced_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE OR REPLACE FUNCTION touch_updated_at() RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = now(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER users_touch BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

COMMIT;
