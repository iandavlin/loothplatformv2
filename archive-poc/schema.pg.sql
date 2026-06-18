-- archive-poc index schema, postgres port
-- Target: `discovery` schema in the shared `looth` postgres database.
-- Source: schema.sql (SQLite). Migration approach: skip pgloader; bin/backfill.php
-- regenerates the index from WP. See SESSION-HANDOFF.md.

-- All DDL assumes search_path=discovery,public (set per-session by the bootstrap or
-- via ALTER ROLE archive_poc SET search_path).

CREATE TABLE content_item (
  id              BIGINT PRIMARY KEY,                       -- mirrors wp_posts.ID (not generated; we use the WP id)
  source          TEXT NOT NULL DEFAULT 'wp',
  kind            TEXT NOT NULL,                            -- article|video|loothprint|event|discussion|profile|benefit|misc
  subkind         TEXT,
  cpt             TEXT NOT NULL,                            -- raw WP post_type
  title           TEXT NOT NULL,
  slug            TEXT NOT NULL,
  url             TEXT NOT NULL,
  excerpt         TEXT,
  body_text       TEXT,                                     -- plaintext, drives FTS
  thumb_url       TEXT,
  thumb_broken    BOOLEAN NOT NULL DEFAULT FALSE,
  author_id       BIGINT,                                   -- wp_users.ID
  author_name     TEXT,                                     -- denormalized for fast render
  tier            TEXT,                                     -- public|lite|pro
  published_at    TIMESTAMPTZ NOT NULL,
  last_activity   TIMESTAMPTZ,
  reply_count     INTEGER NOT NULL DEFAULT 0,
  like_count      INTEGER NOT NULL DEFAULT 0,
  view_count      INTEGER NOT NULL DEFAULT 0,
  duration_min    INTEGER,
  has_download    BOOLEAN NOT NULL DEFAULT FALSE,
  event_start_at  TIMESTAMPTZ,
  event_end_at    TIMESTAMPTZ,
  event_region    TEXT,
  event_join_url  TEXT,
  forum_label     TEXT,
  subforum_label  TEXT,
  -- Resolved YouTube id (videos only) for the inline play-button facade.
  yt_id           TEXT,
  -- Denormalized tag text (concat of tag labels) for FTS coverage.
  -- Maintained by backfill.php after content_tag rows land.
  tag_text        TEXT NOT NULL DEFAULT '',
  -- Generated FTS column. STORED so the GIN index reads precomputed values.
  tsv             tsvector GENERATED ALWAYS AS (
    setweight(to_tsvector('english', coalesce(title, '')),       'A') ||
    setweight(to_tsvector('english', coalesce(tag_text, '')),    'B') ||
    setweight(to_tsvector('english', coalesce(author_name, '')), 'C') ||
    setweight(to_tsvector('english', coalesce(body_text, '')),   'D')
  ) STORED
);

CREATE INDEX idx_content_kind          ON content_item (kind, published_at DESC);
CREATE INDEX idx_content_tier          ON content_item (tier);
CREATE INDEX idx_content_author        ON content_item (author_id);
CREATE INDEX idx_content_last_activity ON content_item (last_activity DESC) WHERE last_activity IS NOT NULL;
CREATE INDEX idx_content_tsv           ON content_item USING GIN (tsv);

CREATE TABLE tag (
  id    INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  slug  TEXT NOT NULL UNIQUE,
  label TEXT NOT NULL
);

CREATE TABLE content_tag (
  content_id BIGINT  NOT NULL REFERENCES content_item(id) ON DELETE CASCADE,
  tag_id     INTEGER NOT NULL REFERENCES tag(id)          ON DELETE CASCADE,
  PRIMARY KEY (content_id, tag_id)
);
CREATE INDEX idx_content_tag_tag ON content_tag (tag_id);

CREATE TABLE person (
  id           BIGINT PRIMARY KEY,                          -- mirrors wp_users.ID
  display_name TEXT NOT NULL,
  slug         TEXT NOT NULL,
  avatar_url   TEXT
);

-- Shared sync-writer + cross-schema reader grants (coord §3i).
-- `looth-dev` is the WP-side sync writer (runs the _sync.php endpoint via
-- the looth-dev FPM pool, peer-auths to its own pg role). `profile-app`
-- is a read-only consumer for future cross-schema joins.
-- Apply as the schema owner (archive-poc) so default privileges attach
-- to this role's future table creations.
GRANT USAGE ON SCHEMA discovery TO "looth-dev";
GRANT SELECT, INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES
  ON ALL TABLES IN SCHEMA discovery TO "looth-dev";
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA discovery TO "looth-dev";
ALTER DEFAULT PRIVILEGES IN SCHEMA discovery
  GRANT SELECT, INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES ON TABLES TO "looth-dev";
ALTER DEFAULT PRIVILEGES IN SCHEMA discovery
  GRANT USAGE, SELECT ON SEQUENCES TO "looth-dev";

GRANT USAGE ON SCHEMA discovery TO "profile-app";
GRANT SELECT ON ALL TABLES IN SCHEMA discovery TO "profile-app";
ALTER DEFAULT PRIVILEGES IN SCHEMA discovery
  GRANT SELECT ON TABLES TO "profile-app";
