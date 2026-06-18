-- archive-poc/sql/comment-reactions.pg.sql
--
-- Reactions ON comments (comments+reactions lane). The fast-follow flagged in
-- comments.pg.sql, greenlit by Ian 2026-06-05 with the 7-reaction BuddyBoss
-- palette (see lg_reactions_palette() in api/v0/_comments.php). Lives in the
-- `discovery` schema alongside comments + likes + article_blobs.
--
-- Target = a row in discovery.comments by its surrogate id (NOT a content item),
-- so this is a sibling of discovery.likes (which targets content) rather than a
-- reuse of it. Stored BY SLUG so a future re-skin of the palette never rewrites
-- history.
--
-- Identity is the WP user id, NOT user_uuid — deliberately matching the comment
-- WRITE gate (the WP login cookie, because an unbridged member is anon to /whoami
-- but can still participate). user_uuid is captured too when the member bridges,
-- for later cross-surface joins, but the dedup key is (comment_id, user_wp_id):
-- ONE reaction per user per comment (choosing a new reaction replaces the old).
--
-- Apply as the schema OWNER (archive-poc) so it owns the table (read side) and the
-- looth-dev write grants below attach. search_path assumed `discovery, public`:
--   sudo -u archive-poc psql -d looth -v ON_ERROR_STOP=1 \
--     -c 'SET search_path = discovery, public' -f sql/comment-reactions.pg.sql

CREATE TABLE IF NOT EXISTS comment_reactions (
  comment_id   BIGINT      NOT NULL REFERENCES comments(id) ON DELETE CASCADE,
  user_wp_id   BIGINT      NOT NULL,                 -- reactor's WP user id (the gate identity)
  user_uuid    UUID,                                 -- bridged shared identity, when available
  slug         TEXT        NOT NULL,                 -- palette slug: like|ouch|wow|lol|shop|take-my-money|brain
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (comment_id, user_wp_id)               -- one reaction per user per comment
);

-- Count/aggregate per comment + slug (the modal reaction bar).
CREATE INDEX IF NOT EXISTS idx_comment_reactions_comment ON comment_reactions (comment_id, slug);
-- "my reactions across a thread" lookup on the write pool.
CREATE INDEX IF NOT EXISTS idx_comment_reactions_user ON comment_reactions (user_wp_id);

-- Write-side role: `looth-dev` runs the comment-react write endpoint (looth-dev FPM
-- pool — it boots WP to validate the login cookie), exactly like comment-post.
-- The schema owner (archive-poc) keeps ownership → it reads counts for the modal
-- via its own pool/role.
GRANT USAGE  ON SCHEMA discovery TO "looth-dev";
GRANT SELECT, INSERT, UPDATE, DELETE ON comment_reactions TO "looth-dev";

-- profile-app / bb-mirror stay read-only cross-schema consumers (future "most-
-- reacted" / feed badges), matching the existing discovery grants.
GRANT SELECT ON comment_reactions TO "profile-app";
GRANT SELECT ON comment_reactions TO "bb-mirror";
