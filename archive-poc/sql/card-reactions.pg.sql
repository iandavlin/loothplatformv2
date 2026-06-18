-- archive-poc/sql/card-reactions.pg.sql
--
-- Reactions ON feed CARDS (forum topics + content items) — the engine half of the
-- Hub card-reactions feature (Ian, 2026-06-06). Sibling of comment_reactions
-- (reactions ON comments) but keyed to a CONTENT TARGET (post_type, item_id), the
-- same shape as discovery.comments / discovery.likes. Uses the 7-reaction BuddyBoss
-- palette — single source of truth = lg_reactions_palette() in api/v0/_comments.php.
--
-- THE LIKE FOLD (reconcile, accepted from the Hub SURFACE lane via coordinator):
-- a "like" is just one slug in the palette, so discovery.likes folds INTO this table
-- (slug='like'). There is ONE reaction store for cards, not two parallel like
-- systems. like.php / _likes.php repoint to here (slug='like'); discovery.likes is
-- kept (read-only, un-dropped) for revert safety until the coordinator retires it.
--
-- TWO DOORS, ONE ROW (the actor_key trick):
--   * Hub door  = card-react.php on the looth-dev WP pool — gate is the WP login
--     cookie (works for UNBRIDGED members, who are anon to /whoami). Identity =
--     WP user id (+ user_uuid when the member is bridged).
--   * Standalone door = like.php on the archive-poc pool — gate is /whoami. Identity
--     = user_uuid only (no WP user id).
-- A bridged member hitting either door must dedup to ONE reaction per item. So the
-- dedup key is a normalized actor_key = COALESCE(user_uuid, 'wp:'||user_wp_id):
-- bridged → keys on the uuid through BOTH doors (one row); unbridged → 'wp:'+id
-- (Hub door only). This is why the unique key is actor_key, NOT (item,user_wp_id)
-- like comment_reactions (comments only ever have the WP-cookie door).
--
-- Apply as the schema OWNER (archive-poc) so it owns the table; the looth-dev write
-- grant below attaches for the Hub door. like.php writes as the OWNER (archive-poc
-- pool) so it needs no explicit grant. search_path assumed `discovery, public`:
--   sudo -u archive-poc psql -d looth -v ON_ERROR_STOP=1 \
--     -c 'SET search_path = discovery, public' -f sql/card-reactions.pg.sql

CREATE TABLE IF NOT EXISTS card_reactions (
  post_type   TEXT        NOT NULL,                  -- content target type (CPT slug or 'topic')
  item_id     BIGINT      NOT NULL,                  -- target id (= wp_posts.ID, or bb topic id)
  user_wp_id  BIGINT,                                -- Hub door identity (WP-cookie gate); NULL on the standalone door
  user_uuid   UUID,                                  -- bridged shared identity / standalone-door identity
  slug        TEXT        NOT NULL,                  -- palette slug: like|ouch|wow|lol|shop|take-my-money|brain
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  -- normalized actor: bridged members collapse to their uuid across both doors.
  actor_key   TEXT GENERATED ALWAYS AS (COALESCE(user_uuid::text, 'wp:' || user_wp_id::text)) STORED,
  CONSTRAINT card_reactions_actor_present CHECK (user_wp_id IS NOT NULL OR user_uuid IS NOT NULL),
  CONSTRAINT card_reactions_one_per_actor UNIQUE (post_type, item_id, actor_key)  -- ONE reaction per user per card
);

-- Count/aggregate per card + slug (the feed reaction bar + like_count).
CREATE INDEX IF NOT EXISTS idx_card_reactions_item ON card_reactions (post_type, item_id, slug);
-- "my reactions across a feed page" lookup (viewer-pick highlight).
CREATE INDEX IF NOT EXISTS idx_card_reactions_actor ON card_reactions (actor_key);

-- Write-side role: `looth-dev` runs the Hub write endpoint (card-react.php) on the
-- looth-dev WP FPM pool (it boots WP to validate the login cookie), exactly like
-- comment-react. The schema owner (archive-poc) keeps ownership → its pool reads
-- counts for SSR AND runs the standalone like.php door (owner ⇒ implicit write).
GRANT USAGE  ON SCHEMA discovery TO "looth-dev";
GRANT SELECT, INSERT, UPDATE, DELETE ON card_reactions TO "looth-dev";

-- Read-only cross-schema consumers. bb-mirror reads counts for the unified feed
-- SSR (_feed.php), mirroring the dd248c5 discovery.comments grant — RE-APPLY at
-- cutover. profile-app for future "most-reacted" surfaces.
GRANT SELECT ON card_reactions TO "bb-mirror";
GRANT SELECT ON card_reactions TO "profile-app";

-- ---------------------------------------------------------------------------
-- ONE-TIME FOLD MIGRATION: copy existing discovery.likes rows in as slug='like'.
-- Idempotent (ON CONFLICT DO NOTHING on the actor_key unique). Safe to re-run.
-- Legacy likes are uuid-only (standalone door) → actor_key = uuid.
INSERT INTO card_reactions (post_type, item_id, user_uuid, slug, created_at)
SELECT post_type, item_id, user_uuid, 'like', created_at
  FROM likes
ON CONFLICT (post_type, item_id, actor_key) DO NOTHING;
