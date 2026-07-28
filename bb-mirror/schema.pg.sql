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

  tags              TEXT[],                                  -- denormalized bbPress topic-tag labels (WP-sourced via the mirror sync); free-text, no slug. Powers the cross-world exact-tag facet (?tag=, see TAG-SEARCH-SCOPE.md). NOT in search_doc.

  created_at        TIMESTAMPTZ NOT NULL,
  modified_at       TIMESTAMPTZ NOT NULL,
  sync_at           TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_topic_forum_active ON topic (forum_id, last_active_at DESC NULLS LAST);
CREATE INDEX IF NOT EXISTS idx_topic_forum_sticky ON topic (forum_id, sticky_kind, last_active_at DESC NULLS LAST);
CREATE INDEX IF NOT EXISTS idx_topic_author       ON topic (author_id);
CREATE INDEX IF NOT EXISTS idx_topic_status       ON topic (status);
CREATE INDEX IF NOT EXISTS idx_topic_search       ON topic USING GIN (search_doc);
-- Topic-tag facet (?tag=). topic.tags was added ad-hoc (no prior declaration);
-- folded back in above. GIN supports exact element / @> membership (e.g.
-- tags @> ARRAY['councilyes']); the live feed's NORMALIZED match (slugify each
-- element) is a seq scan — fine at ~1.3k topics, this index is forward-looking
-- for the exact-element path and for scale. See TAG-SEARCH-SCOPE.md §4.
CREATE INDEX IF NOT EXISTS idx_topic_tags         ON topic USING GIN (tags);

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
-- Topic tags (cross-world exact-tag facet, tag-search-build lane)
-- Idempotent ADD for installs created before topic.tags was declared (it was
-- added ad-hoc on dev2/prod with no migration in repo — this folds it back in
-- per the monorepo mandate). Populated by the bb-mirror sync from the bbPress
-- topic-tag taxonomy. The GIN index backs the ?tag= facet. See TAG-SEARCH-SCOPE.md.
-- ============================================================================
ALTER TABLE topic ADD COLUMN IF NOT EXISTS tags TEXT[];
CREATE INDEX IF NOT EXISTS idx_topic_tags ON topic USING GIN (tags);

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
-- attachment cleanup — the referential integrity a polymorphic column can't have
-- ============================================================================
-- `attachment` is polymorphic (parent_kind + parent_id), so it CANNOT carry a
-- foreign key, so it gets no ON DELETE CASCADE. Every other child of topic/forum
-- does: reply.topic_id, reply.forum_id and topic.forum_id are all CASCADE. The
-- result was a silent leak — deleting a reply left its image rows behind, and
-- deleting a topic or forum cascaded away whole subtrees of replies whose
-- attachment rows nothing ever removed.
--
-- These triggers are that missing CASCADE, expressed the only way a polymorphic
-- key allows.
--
-- WHY THIS LIVES IN THE DATABASE AND NOT IN api/v0/_sync.php: on a topic or
-- forum delete, Postgres cascades to the reply rows internally. The application
-- issues one `DELETE FROM topic WHERE id = ?` and never learns which replies
-- died, so it cannot clean up after them. An AFTER DELETE row trigger fires for
-- every cascaded row, including ones no code path can name — and it also covers
-- wp-admin, bulk deletes, reconcile, and manual SQL for free. Measured in a
-- scratch DB (2026-07-28): deleting one forum orphaned 12 of 12 attachment rows
-- before this, and 0 after.
--
-- Deliberately NOT a sweeper job: a cleanup script is a second thing to remember
-- to run, a correct delete path is not.
--
-- KNOWN LIMIT, stated rather than discovered later: a FOR EACH ROW trigger does
-- not fire on TRUNCATE, so `TRUNCATE topic` / `TRUNCATE reply` would still strand
-- attachment rows. Nothing in the repo truncates either table (checked
-- 2026-07-28), and bin/init-db.php uses DROP SCHEMA CASCADE, which takes
-- `attachment` with it. If a TRUNCATE path is ever added, add `attachment` to the
-- same statement — TRUNCATE accepts a table list.
--
-- EVERY NAME IN THE BODY IS SCHEMA-QUALIFIED, and that is load-bearing. plpgsql
-- resolves unqualified names when the function RUNS, against the CALLER's
-- search_path — not against the search_path in force here at CREATE time. The
-- app is fine either way (config.php sets `search_path = forums, public` on
-- every connection), but `sudo -u postgres psql -d looth` runs with
-- `"$user", public`. Measured on the real schema (2026-07-28), with the body
-- unqualified:
--     DELETE FROM forums.reply WHERE id = 100;
--     ERROR:  relation "attachment" does not exist
-- The trigger raised, so the DELETE aborted — turning a silent leak into a hard
-- failure for exactly the hand-run cleanup this is supposed to cover. Qualified,
-- the same statement succeeds from any search_path. The type cast needs it too:
-- `attachment_parent_kind` is just as unqualified a name as the table.
-- Everything between the BEGIN/END markers below is extracted verbatim by
-- bin/fix-attachment-orphans.sh, so that applying this fix to LIVE runs these
-- statements and nothing else — rather than replaying this whole file against a
-- production database. Keep the block self-contained and fully schema-qualified.
-- >>> BEGIN attachment-purge <<<
CREATE OR REPLACE FUNCTION forums.attachment_purge_for_parent() RETURNS trigger AS $$
BEGIN
  -- TG_ARGV[0] is the parent_kind this trigger is bound to. Matching on BOTH
  -- kind and id matters: topic 100 and reply 100 are different parents, and a
  -- kind-blind delete would take the wrong one's images.
  DELETE FROM forums.attachment
   WHERE parent_kind = TG_ARGV[0]::forums.attachment_parent_kind
     AND parent_id   = OLD.id;
  RETURN OLD;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS topic_attachment_purge ON forums.topic;
CREATE TRIGGER topic_attachment_purge
  AFTER DELETE ON forums.topic
  FOR EACH ROW EXECUTE FUNCTION forums.attachment_purge_for_parent('topic');

DROP TRIGGER IF EXISTS reply_attachment_purge ON forums.reply;
CREATE TRIGGER reply_attachment_purge
  AFTER DELETE ON forums.reply
  FOR EACH ROW EXECUTE FUNCTION forums.attachment_purge_for_parent('reply');
-- >>> END attachment-purge <<<

-- ============================================================================
-- forum_subscription cleanup — the SAME polymorphic hole, closed before it opens
-- ============================================================================
-- `forum_subscription` is keyed (user_id, target_kind, target_id) with target_kind
-- an ENUM over 'forum'|'topic'. Polymorphic, therefore no foreign key, therefore
-- no ON DELETE CASCADE — byte for byte the shape that leaked `attachment` rows for
-- months. Deleting a topic would strand every subscription to it.
--
-- IT HAS NOT LEAKED YET, and the reason is worth writing down: on live 2026-07-28
-- the table is EMPTY. WordPress holds 1,563 real forum/topic subscriptions in
-- wp_bb_notifications_subscriptions (1,517 topic + 46 forum, ~400 members), but
-- nothing has ever backfilled them and `forum_subscription` is referenced ONLY by
-- the write path in api/v0/_sync.php — no read path, no subscribe UI on the mirror
-- surface. It is a dormant table, not a broken feature.
--
-- Which is exactly why the trigger goes in NOW: on an empty table it is free and
-- unobservable, and it means whoever eventually backfills those 1,563 rows and
-- builds the UI inherits a delete path that already cleans up after itself,
-- instead of rediscovering this leak the expensive way a second time.
--
-- Targets are 'forum' and 'topic' only — never 'reply'. A forum delete cascades to
-- its topics, and row triggers fire for cascaded rows, so the topic trigger also
-- clears subscriptions to every topic under a deleted forum.
CREATE OR REPLACE FUNCTION forums.subscription_purge_for_target() RETURNS trigger AS $$
BEGIN
  -- Schema-qualified for the same measured reason as attachment_purge_for_parent:
  -- plpgsql resolves unqualified names against the CALLER's search_path at RUN
  -- time, so an unqualified body raises "relation does not exist" — and ABORTS the
  -- delete — for any caller without `forums` on its path.
  DELETE FROM forums.forum_subscription
   WHERE target_kind = TG_ARGV[0]::forums.subscription_target_kind
     AND target_id   = OLD.id;
  RETURN OLD;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS forum_subscription_purge ON forums.forum;
CREATE TRIGGER forum_subscription_purge
  AFTER DELETE ON forums.forum
  FOR EACH ROW EXECUTE FUNCTION forums.subscription_purge_for_target('forum');

DROP TRIGGER IF EXISTS topic_subscription_purge ON forums.topic;
CREATE TRIGGER topic_subscription_purge
  AFTER DELETE ON forums.topic
  FOR EACH ROW EXECUTE FUNCTION forums.subscription_purge_for_target('topic');

-- ============================================================================
-- Comments (visibility for cross-schema readers — profile-app has SELECT)
-- ============================================================================
COMMENT ON TABLE forum            IS 'bbPress forum container; mirrored from wp_posts post_type=forum';
COMMENT ON TABLE topic            IS 'bbPress topic / thread; mirrored from wp_posts post_type=topic';
COMMENT ON TABLE reply            IS 'bbPress reply; threading via parent_reply_id (rename of SQLite reply_to_id)';
COMMENT ON TABLE attachment       IS 'Image URLs attached to topic or reply. No blobs.';
COMMENT ON TABLE forum_read_state IS 'Per-viewer read state for unread/NEW chrome';
COMMENT ON TABLE person           IS 'Denormalized author cache. NOT identity authority — profile-app owns that.';
