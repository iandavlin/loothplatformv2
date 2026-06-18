-- profile-app — sponsor brand store (sponsor-pages v2, Lane A).
--
-- ⚠️ Apply-ready, idempotent. Borrows profile-app's Postgres as the HOME for
-- structured sponsor brand data lifted out of ACF / WP user-meta (group
-- "Sponsor Brand Information" #33147). Sponsors are INDEPENDENT expanded
-- functionality — NOT member profiles. This table is therefore standalone and
-- is deliberately NOT joined to the member `users` table.
--
-- ⚠️ CUT-DAY "DOESN'T RIDE GIT" INFRA: this table (and api/v0/sponsor.php +
-- its nginx route) must be re-applied to the LIVE box at cutover — schema lands
-- via this file, data via bin/migrate-sponsors.php. Flagged in the cutover doc.
--
-- The two identity keys (Ian):
--   wp_user_id — the CONTENT link. sponsor-product / sponsor-post CPTs are
--                authored by this WP user; the page feeds query WHERE author=it.
--   email      — the cross-system BRIDGE. The Patreon/Stripe poller reconciles a
--                sponsor (a billing identity) by email, so it is the durable key
--                that survives a recycled WP user id.
--   slug       — the public URL key (e.g. 'total-vise').

BEGIN;

CREATE TABLE IF NOT EXISTS sponsor (
    id                BIGSERIAL    PRIMARY KEY,
    slug              TEXT         NOT NULL UNIQUE,      -- public URL key
    wp_user_id        BIGINT       UNIQUE,              -- content link (CPT author)
    email             TEXT,                             -- poller / Stripe / Patreon bridge

    name              TEXT,                             -- long name ("Jeff Howard's Total Vise")
    display_name      TEXT,                             -- short ("TOTAL VISE")

    logo_url          TEXT,                             -- resolved attachment URL
    hero_url          TEXT,                             -- resolved attachment URL
    hero_caption      TEXT,
    hero_title        TEXT,
    hero_youtube      TEXT,

    about             TEXT,                             -- mission copy
    website           TEXT,

    color_primary     TEXT,                             -- hex theming vars
    color_secondary   TEXT,
    color_header      TEXT,

    social_facebook   TEXT,
    social_instagram  TEXT,
    social_youtube    TEXT,

    gallery_urls      JSONB        NOT NULL DEFAULT '[]'::jsonb,  -- resolved attachment URLs, ordered

    tag_url           TEXT,                             -- tagged-content archive link
    forum_url         TEXT,                             -- sponsor forum link

    created_at        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_sponsor_wp_user_id ON sponsor(wp_user_id);
CREATE INDEX IF NOT EXISTS idx_sponsor_email      ON sponsor(lower(email));

-- Reuse the shared updated_at trigger (defined in 0001_init.sql).
DROP TRIGGER IF EXISTS trg_sponsor_touch ON sponsor;
CREATE TRIGGER trg_sponsor_touch BEFORE UPDATE ON sponsor
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

COMMIT;

-- Verify post-apply:
--   \d sponsor
--   SELECT slug, wp_user_id, email, jsonb_array_length(gallery_urls) FROM sponsor ORDER BY slug;
