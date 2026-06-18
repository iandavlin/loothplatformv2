-- bb-mirror postgres schema (forums schema in looth database)
--
-- Apply via:
--   sudo -u bb-mirror psql -d looth -f schema.pg.sql
--
-- Idempotent: CREATE TABLE IF NOT EXISTS + DO blocks for types/indexes.
-- Re-running on an existing schema is a no-op except for adding missing
-- pieces.
--
-- Differences from the SQLite schema (schema.sql, kept for reference until
-- the env flag retires):
--   * BIGINT PKs (mirror wp_posts.ID, future-proof for growth)
--   * TIMESTAMPTZ time columns (was unix int)
--   * ENUM for the few truly bounded discriminators; CHECK for the rest
--   * tsvector + GIN replaces SQLite FTS5
--   * parent_reply_id (rename of reply_to_id)
--   * NEW: attachment table (image URLs only, no blobs)
--   * NEW: forum_read_state table (unread tracking — per coordinator briefing)
--   * NEW: topic.featured_image_url (denormalized, every topic has at most one)

SET client_min_messages = WARNING;
SET search_path = forums, public;

-- ============================================================================
-- Types
-- ============================================================================

DO $$ BEGIN
  CREATE TYPE attachment_parent_kind AS ENUM ('topic', 'reply');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
  CREATE TYPE subscription_target_kind AS ENUM ('forum', 'topic');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

-- ============================================================================
-- forum
-- ============================================================================
CREATE TABLE IF NOT EXISTS forum (
  id                  BIGINT      PRIMARY KEY,
  slug                TEXT        NOT NULL,
  title               TEXT        NOT NULL,
  description         TEXT,
  parent_forum_id     BIGINT,
  menu_order          INT         NOT NULL DEFAULT 0,
  group_id            BIGINT,

  forum_type          TEXT        NOT NULL DEFAULT 'forum'
                                  CHECK (forum_type IN ('forum','category')),
  status              TEXT        NOT NULL DEFAULT 'open'
                                  CHECK (status IN ('open','closed')),
  visibility          TEXT        NOT NULL DEFAULT 'public'
                                  CHECK (visibility IN ('public','private','hidden')),
  tier_gate           TEXT        NOT NULL DEFAULT 'public'
                                  CHECK (tier_gate IN ('public','lite','pro')),

  topic_count         INT         NOT NULL DEFAULT 0,
  reply_count         INT         NOT NULL DEFAULT 0,
  total_topic_count   INT         NOT NULL DEFAULT 0,
  total_reply_count   INT         NOT NULL DEFAULT 0,
  last_topic_id       BIGINT,
  last_reply_id       BIGINT,
  last_active_id      BIGINT,
  last_active_at      TIMESTAMPTZ,
  total_last_active_at TIMESTAMPTZ,                         -- rollup over descendant subforums + topics
  effective_group_id  BIGINT,                               -- group_id walked up the ancestor chain; NULL if no group in chain
  header_image_url    TEXT,                                 -- admin-set forum header banner (NOT synced from WP; set via api/v0/set-forum-image.php)

  created_at          TIMESTAMPTZ NOT NULL,
  modified_at         TIMESTAMPTZ NOT NULL,
  sync_at             TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_forum_parent           ON forum (parent_forum_id, menu_order);
CREATE INDEX IF NOT EXISTS idx_forum_total_last_active ON forum (total_last_active_at DESC NULLS LAST);
CREATE INDEX IF NOT EXISTS idx_forum_effective_group  ON forum (effective_group_id) WHERE effective_group_id IS NOT NULL;

-- ============================================================================
-- bp_group  — mirror of wp_bp_groups
-- ============================================================================
-- attached_forum_id is the forum BB binds this group's "discussions" tab to.
-- A group's posts land in that forum (and its subforums transitively).
-- ON DELETE SET NULL on the group → forum link so a dropped group leaves the
-- forum addressable (orphan-gate rule: deleted group means "no gate").
CREATE TABLE IF NOT EXISTS bp_group (
  id                  BIGINT      PRIMARY KEY,
  slug                TEXT        NOT NULL,
  name                TEXT        NOT NULL,
  description         TEXT,
  status              TEXT        NOT NULL DEFAULT 'public'
                                  CHECK (status IN ('public','private','hidden')),
  attached_forum_id   BIGINT,
  member_count        INT         NOT NULL DEFAULT 0,
  created_at          TIMESTAMPTZ NOT NULL,
  sync_at             TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_bp_group_attached_forum ON bp_group (attached_forum_id);
CREATE INDEX IF NOT EXISTS idx_bp_group_status         ON bp_group (status);
CREATE INDEX IF NOT EXISTS idx_forum_group       ON forum (group_id) WHERE group_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_forum_visibility  ON forum (visibility, tier_gate);
CREATE INDEX IF NOT EXISTS idx_forum_last_active ON forum (last_active_at DESC NULLS LAST);

-- ============================================================================
-- topic
-- ============================================================================
CREATE TABLE IF NOT EXISTS topic (
  id                BIGINT      PRIMARY KEY,
  forum_id          BIGINT      NOT NULL REFERENCES forum(id) ON DELETE CASCADE,
  slug              TEXT        NOT NULL,
  title             TEXT        NOT NULL,
  content_html      TEXT,
  content_text      TEXT,

  featured_image_url TEXT,

  author_id         BIGINT,
  author_name       TEXT,
  author_slug       TEXT,
  anonymous_name    TEXT,
  is_anon           BOOLEAN     NOT NULL DEFAULT false,   -- per-post "Post anonymously" flag (_lg_anon meta)

  status            TEXT        NOT NULL DEFAULT 'publish'
                                CHECK (status IN ('publish','closed','spam','trash','pending')),
  sticky_kind       TEXT        CHECK (sticky_kind IN ('super','forum') OR sticky_kind IS NULL),
  voice_count       INT         NOT NULL DEFAULT 0,
  reply_count       INT         NOT NULL DEFAULT 0,
  last_reply_id     BIGINT,
  last_active_id    BIGINT,
  last_active_at    TIMESTAMPTZ,

  tier_gate         TEXT        NOT NULL DEFAULT 'public'
                                CHECK (tier_gate IN ('public','lite','pro')),

  search_doc        tsvector,

  created_at        TIMESTAMPTZ NOT NULL,
  modified_at       TIMESTAMPTZ NOT NULL,
  sync_at           TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_topic_forum_active ON topic (forum_id, last_active_at DESC NULLS LAST);
CREATE INDEX IF NOT EXISTS idx_topic_forum_sticky ON topic (forum_id, sticky_kind, last_active_at DESC NULLS LAST);
CREATE INDEX IF NOT EXISTS idx_topic_author       ON topic (author_id);
CREATE INDEX IF NOT EXISTS idx_topic_status       ON topic (status);
CREATE INDEX IF NOT EXISTS idx_topic_search       ON topic USING GIN (search_doc);

-- ============================================================================
-- reply (parent_reply_id is the rename of SQLite's reply_to_id)
-- ============================================================================
CREATE TABLE IF NOT EXISTS reply (
  id              BIGINT      PRIMARY KEY,
  topic_id        BIGINT      NOT NULL REFERENCES topic(id) ON DELETE CASCADE,
  forum_id        BIGINT      NOT NULL REFERENCES forum(id) ON DELETE CASCADE,
  parent_reply_id BIGINT      REFERENCES reply(id) ON DELETE SET NULL DEFERRABLE INITIALLY IMMEDIATE,

  content_html    TEXT,
  content_text    TEXT,

  author_id       BIGINT,
  author_name     TEXT,
  author_slug     TEXT,
  anonymous_name  TEXT,
  is_anon         BOOLEAN     NOT NULL DEFAULT false,   -- per-post "Post anonymously" flag (_lg_anon meta)

  status          TEXT        NOT NULL DEFAULT 'publish'
                              CHECK (status IN ('publish','closed','spam','trash','pending')),

  search_doc      tsvector,

  created_at      TIMESTAMPTZ NOT NULL,
  modified_at     TIMESTAMPTZ NOT NULL,
  sync_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_reply_topic_created ON reply (topic_id, parent_reply_id, created_at);
CREATE INDEX IF NOT EXISTS idx_reply_forum_created ON reply (forum_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_reply_author        ON reply (author_id);
CREATE INDEX IF NOT EXISTS idx_reply_parent        ON reply (parent_reply_id) WHERE parent_reply_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_reply_search        ON reply USING GIN (search_doc);

-- ============================================================================
-- Anonymous-posting flag (per-post "Post anonymously" toggle, anon-rebuild lane)
-- Idempotent ADD for installs created before is_anon existed. Source: WP post
-- meta _lg_anon (set at write by the bb-mirror-sync mu-plugin); carried into pg
-- by the topic/reply materializers. The Hub render masks anon authors leak-safe
-- for non-moderators (see lg_bb_mirror_mask_anon in config.php).
-- ============================================================================
ALTER TABLE topic ADD COLUMN IF NOT EXISTS is_anon BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE reply ADD COLUMN IF NOT EXISTS is_anon BOOLEAN NOT NULL DEFAULT false;

-- ============================================================================
-- forum_subscription
-- ============================================================================
CREATE TABLE IF NOT EXISTS forum_subscription (
  user_id        BIGINT      NOT NULL,
  target_kind    subscription_target_kind NOT NULL,
  target_id      BIGINT      NOT NULL,
  subscribed_at  TIMESTAMPTZ,
  sync_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (user_id, target_kind, target_id)
);
CREATE INDEX IF NOT EXISTS idx_subscription_target ON forum_subscription (target_kind, target_id);

-- ============================================================================
-- person (denormalized author cache; NOT identity authority)
-- ============================================================================
CREATE TABLE IF NOT EXISTS person (
  id            BIGINT      PRIMARY KEY,
  slug          TEXT        NOT NULL,
  display_name  TEXT        NOT NULL,
  avatar_url    TEXT,
  is_moderator  BOOLEAN     NOT NULL DEFAULT false,
  -- Discussion-author mask preference, synced from profile-app (the owner) so
  -- the Hub's logged-out author mask rides the feed's author JOIN with NO
  -- per-render profile-app call (path (a), docs/briefing-discussion-visibility.md).
  -- SINGULAR 'member' (2-state author mask) — must match profile-app's column +
  -- /users payload exactly; distinct from forum.visibility's tri-state 'members'.
  -- Default 'member' = leak-SAFE (hides identity until the user opts Public).
  discussion_visibility TEXT NOT NULL DEFAULT 'member'
                        CHECK (discussion_visibility IN ('public', 'member')),
  sync_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_person_slug ON person (slug);

-- ============================================================================
-- attachment — NEW
-- Image URLs only. Files stay under WP's wp-content/uploads/.
-- Source: bbPress _bbp_attachment_*, BB Platform bp_media, or inline <img>
-- harvested from post_content at sync. Population is out-of-scope for the
-- migration round — schema lands first.
-- ============================================================================
CREATE TABLE IF NOT EXISTS attachment (
  id            BIGSERIAL   PRIMARY KEY,
  parent_kind   attachment_parent_kind NOT NULL,
  parent_id     BIGINT      NOT NULL,
  url           TEXT        NOT NULL,
  alt           TEXT,
  mime          TEXT,
  width         INT,
  height        INT,
  position      INT         NOT NULL DEFAULT 0,
  sync_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_attachment_parent ON attachment (parent_kind, parent_id, position);

-- ============================================================================
-- forum_read_state — NEW (per coordinator: build alongside v1)
-- Powers unread/NEW chrome that the v2 mockup leans on.
-- Populated by "mark seen" endpoint that fires on single-topic render.
-- ============================================================================
CREATE TABLE IF NOT EXISTS forum_read_state (
  user_id       BIGINT      NOT NULL,
  topic_id      BIGINT      NOT NULL REFERENCES topic(id) ON DELETE CASCADE,
  last_read_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (user_id, topic_id)
);
CREATE INDEX IF NOT EXISTS idx_read_state_topic ON forum_read_state (topic_id);

-- ============================================================================
-- sync_state (bookkeeping)
-- ============================================================================
CREATE TABLE IF NOT EXISTS sync_state (
  key         TEXT        PRIMARY KEY,
  value       TEXT        NOT NULL,
  updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ============================================================================
-- search_doc triggers (tsvector population)
-- Title weighted 'A', body weighted 'B', author_name weighted 'C'.
-- Uses 'english' config; revisit if non-English content shows up.
-- ============================================================================

CREATE OR REPLACE FUNCTION topic_search_doc_update() RETURNS trigger AS $$
BEGIN
  NEW.search_doc :=
      setweight(to_tsvector('english', coalesce(NEW.title, '')),       'A')
   || setweight(to_tsvector('english', coalesce(NEW.content_text, '')), 'B')
   || setweight(to_tsvector('english', coalesce(NEW.author_name, '')), 'C');
  RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS topic_search_doc_trigger ON topic;
CREATE TRIGGER topic_search_doc_trigger
  BEFORE INSERT OR UPDATE OF title, content_text, author_name
  ON topic
  FOR EACH ROW EXECUTE FUNCTION topic_search_doc_update();

CREATE OR REPLACE FUNCTION reply_search_doc_update() RETURNS trigger AS $$
BEGIN
  NEW.search_doc :=
      setweight(to_tsvector('english', coalesce(NEW.content_text, '')), 'B')
   || setweight(to_tsvector('english', coalesce(NEW.author_name, '')), 'C');
  RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS reply_search_doc_trigger ON reply;
CREATE TRIGGER reply_search_doc_trigger
  BEFORE INSERT OR UPDATE OF content_text, author_name
  ON reply
  FOR EACH ROW EXECUTE FUNCTION reply_search_doc_update();

-- ============================================================================
-- Comments (visibility for cross-schema readers — profile-app has SELECT)
-- ============================================================================
COMMENT ON TABLE forum            IS 'bbPress forum container; mirrored from wp_posts post_type=forum';
COMMENT ON TABLE topic            IS 'bbPress topic / thread; mirrored from wp_posts post_type=topic';
COMMENT ON TABLE reply            IS 'bbPress reply; threading via parent_reply_id (rename of SQLite reply_to_id)';
COMMENT ON TABLE attachment       IS 'Image URLs attached to topic or reply. No blobs.';
COMMENT ON TABLE forum_read_state IS 'Per-viewer read state for unread/NEW chrome';
COMMENT ON TABLE person           IS 'Denormalized author cache. NOT identity authority — profile-app owns that.';
