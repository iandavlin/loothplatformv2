-- discovery.saved_posts — account-synced "save a post" store.
--
-- Same actor-key shape as discovery.card_reactions (TWO DOORS, ONE ROW: dedup on the
-- normalized actor_key = COALESCE(user_uuid, 'wp:'||user_wp_id), so a member is the same
-- saver whether or not they're bridged to a profile uuid). Binary — a row means "saved",
-- no slug. The WRITE door (save-post.php) runs on the WP pool gated by the WP login cookie;
-- the READ door (my-saved.php) lists a user's saves newest-first.
--
-- CUTOVER: re-apply this DDL on the cut DB + GRANT SELECT,INSERT,DELETE to the WP-pool role
-- (same role that writes card_reactions). See card-reactions.pg.sql for the sibling grant.

CREATE SCHEMA IF NOT EXISTS discovery;

CREATE TABLE IF NOT EXISTS discovery.saved_posts (
    post_type   text        NOT NULL,
    item_id     bigint      NOT NULL,
    user_wp_id  bigint,
    user_uuid   uuid,
    actor_key   text        NOT NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT saved_posts_actor_present  CHECK (user_wp_id IS NOT NULL OR user_uuid IS NOT NULL),
    CONSTRAINT saved_posts_one_per_actor  UNIQUE (post_type, item_id, actor_key)
);

-- my-saved: a single actor's saves, newest first.
CREATE INDEX IF NOT EXISTS saved_posts_actor_recent ON discovery.saved_posts (actor_key, created_at DESC);
