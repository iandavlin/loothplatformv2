-- archive-poc/sql/article-blobs.pg.sql
--
-- Standalone-render blob store (layout-standalone lane). One row per managed-CPT
-- post; the materializer (WP save hook → /archive-api/v0/_materialize) writes it,
-- the standalone renderer reads it. Lives in the `discovery` schema alongside
-- archive-poc's content_item (coord §3i — one postgres, per-strangler schema).
--
-- The blob is SERVER-SIDE ONLY. It contains ALL blocks including gated payloads;
-- the renderer gates per-viewer at render and never serves the raw blob (design §5).
--
-- Apply as the schema OWNER (archive-poc) so it owns the table (read side) and the
-- looth-dev write grants below attach. search_path assumed `discovery, public`:
--   sudo -u archive-poc psql -d looth -v ON_ERROR_STOP=1 \
--     -c 'SET search_path = discovery, public' -f sql/article-blobs.pg.sql

CREATE TABLE IF NOT EXISTS article_blobs (
  post_id         BIGINT PRIMARY KEY,                 -- mirrors wp_posts.ID
  post_type       TEXT        NOT NULL,               -- raw WP post_type (managed CPT)
  slug            TEXT        NOT NULL,               -- post_name, for slug routing
  blob            JSONB       NOT NULL,               -- { layout, post_context } — never served raw
  materialized_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  checksum        TEXT        NOT NULL                -- sha256(blob json); change-detection / observability
);

CREATE INDEX IF NOT EXISTS idx_article_blobs_slug ON article_blobs (slug);
CREATE INDEX IF NOT EXISTS idx_article_blobs_type ON article_blobs (post_type);

-- Write-side role: `looth-dev` runs the _materialize endpoint (looth-dev FPM pool,
-- peer auth) + the backfill (sudo -u looth-dev). Same shared write-side role
-- archive-poc's _sync uses (coord §3i). The schema owner (archive-poc) keeps
-- ownership → it reads the table for renders via its own role.
GRANT USAGE  ON SCHEMA discovery TO "looth-dev";
GRANT SELECT, INSERT, UPDATE, DELETE ON article_blobs TO "looth-dev";

-- profile-app stays a read-only cross-schema consumer (future joins), matching
-- the existing discovery grants. Harmless if it never reads this table.
GRANT SELECT ON article_blobs TO "profile-app";
