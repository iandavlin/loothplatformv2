-- discovery.featured_history — who was on the front-page featured band, and when.
--
-- Lane: featured-members, backlog 18 (Ian 8/11); design rulings 8/14
-- (docs/IAN-RULINGS-2026-08-14.md item 6). The ADMIN DASH ruling is a pool the
-- admin can see PLUS featured history (who + when) — this table is the "when".
--
-- WRITE DOOR: api/v0/_config.php ONLY. That file already owns the single write
-- path to config.json's `featured_member` key (wp-admin dash AND the front-end
-- editor both go through it — see its own header comment), so it is also the
-- one place a transition in `featured_member.member_uuid` can be observed and
-- logged. No second write door, no drift between "what config.json says now"
-- and "what history says happened" — one file computes both.
--
-- Runs on archive-poc's OWN Postgres role against its OWN `looth` database
-- (same schema as content_item/person), so — unlike featured-member-grants.sql,
-- the SEPARATE file that lets archive-poc READ profile_app — this table needs
-- NO cross-database grant. archive-poc already owns everything in `discovery`.
--
-- ONE AT A TIME (Ian's ruling): `ended_at IS NULL` marks the current stint, and
-- at most one row may be open at a time (partial unique index below). A new
-- Feature closes whatever was open (if anything) and opens a new row; a Remove
-- just closes the open row. No running order, no rotation table.

CREATE SCHEMA IF NOT EXISTS discovery;

CREATE TABLE IF NOT EXISTS discovery.featured_history (
    id            bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_uuid   uuid        NOT NULL,
    -- Denormalized snapshot of what the card showed AT SELECTION TIME, for a
    -- history row to stay legible even if the member later changes their name
    -- or the row is read long after. The LIVE front-page card (index.php) does
    -- NOT read this — it re-resolves from profile_app on every request
    -- ("live, not frozen", Ian's implicit steer per the decision page's
    -- recommendation §3). This column is history-only.
    display_name  text        NOT NULL,
    started_at    timestamptz NOT NULL DEFAULT now(),
    ended_at      timestamptz,
    chosen_by     text,                          -- WP admin user_login, best-effort
    CONSTRAINT featured_history_ended_after_started
        CHECK (ended_at IS NULL OR ended_at >= started_at)
);

-- Enforces "one at a time": a second open row would violate this before it
-- could ever be written, so the invariant holds even if a future caller
-- forgets to close the previous row first.
CREATE UNIQUE INDEX IF NOT EXISTS featured_history_one_open
    ON discovery.featured_history ((true)) WHERE ended_at IS NULL;

-- The dash's "Featured history" table: newest first.
CREATE INDEX IF NOT EXISTS featured_history_recent
    ON discovery.featured_history (started_at DESC);

-- This DDL runs as a superuser (cut/migration convention — see saved-posts.pg.sql's
-- own cutover note), so the table exists but is NOT automatically usable by the
-- "archive-poc" role even though it owns the schema. Confirmed the hard way
-- 2026-08-15: SELECT/INSERT/UPDATE all failed permission-denied from the
-- archive-poc role until this grant was added.
GRANT SELECT, INSERT, UPDATE ON discovery.featured_history TO "archive-poc";
GRANT USAGE, SELECT ON SEQUENCE discovery.featured_history_id_seq TO "archive-poc";
