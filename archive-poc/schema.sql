-- archive-poc index schema (Scope A)
-- SQLite. Throwaway-grade. Built by bin/backfill.php.

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

-- One row per surfaceable thing
CREATE TABLE content_item (
  id              INTEGER PRIMARY KEY,        -- mirrors wp_posts.ID for now
  source          TEXT NOT NULL DEFAULT 'wp',
  kind            TEXT NOT NULL,              -- article|video|loothprint|event|discussion|profile|benefit|misc
  subkind         TEXT,                       -- how-to|profile|opinion|review|... (article only for v0)
  cpt             TEXT NOT NULL,              -- raw WP post_type, for debugging
  title           TEXT NOT NULL,
  slug            TEXT NOT NULL,
  url             TEXT NOT NULL,              -- public URL
  excerpt         TEXT,
  body_text       TEXT,                       -- plain text for FTS (extracted from post_content / lg-layout-v2 / ACF)
  thumb_url       TEXT,                       -- resolved with fallback chain
  thumb_broken    INTEGER DEFAULT 0,          -- flag for R2-broken images
  author_id       INTEGER,                    -- wp_users.ID
  author_name     TEXT,                       -- denormalized for fast rendering
  tier            TEXT,                       -- public|lite|pro
  published_at    INTEGER NOT NULL,           -- unix timestamp
  last_activity   INTEGER,                    -- for discussions
  reply_count     INTEGER DEFAULT 0,          -- for discussions
  like_count      INTEGER DEFAULT 0,          -- from wp_ulike
  view_count      INTEGER DEFAULT 0,          -- from burst_statistics (aggregate)
  duration_min    INTEGER,                    -- for videos
  has_download    INTEGER DEFAULT 0,          -- for loothprints/documents
  event_start_at  INTEGER,                    -- unix ts, NULL for non-events
  event_end_at    INTEGER,                    -- unix ts, NULL for non-events
  event_region    TEXT,                       -- e.g. "North America"
  event_join_url  TEXT,                       -- Zoom URL etc.
  forum_label     TEXT,                       -- top-level bbPress forum, NULL for non-discussions
  subforum_label  TEXT,                       -- immediate sub-forum if nested, else NULL
  yt_id           TEXT                        -- resolved YouTube id (videos) for the inline play facade
);

CREATE INDEX idx_content_kind          ON content_item(kind, published_at DESC);
CREATE INDEX idx_content_tier          ON content_item(tier);
CREATE INDEX idx_content_author        ON content_item(author_id);
CREATE INDEX idx_content_last_activity ON content_item(last_activity DESC) WHERE last_activity IS NOT NULL;

-- FTS5 virtual table for search. Standalone (not external-content) so we
-- can also index tag_text, which isn't a column on content_item. rowid is
-- set to content_item.id explicitly at insert time so we can JOIN.
CREATE VIRTUAL TABLE content_fts USING fts5(
  title, body_text, author_name, tag_text,
  tokenize='porter'
);

-- Tags (flat for now; tag.kind comes in Scope B)
CREATE TABLE tag (
  id    INTEGER PRIMARY KEY,
  slug  TEXT NOT NULL UNIQUE,
  label TEXT NOT NULL
);

CREATE TABLE content_tag (
  content_id INTEGER NOT NULL,
  tag_id     INTEGER NOT NULL,
  PRIMARY KEY (content_id, tag_id)
);
CREATE INDEX idx_content_tag_tag ON content_tag(tag_id);

-- Person (minimal — author byline data only for v0)
CREATE TABLE person (
  id           INTEGER PRIMARY KEY,           -- mirrors wp_users.ID
  display_name TEXT NOT NULL,
  slug         TEXT NOT NULL,
  avatar_url   TEXT
);
