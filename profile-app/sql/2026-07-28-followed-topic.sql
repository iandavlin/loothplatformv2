-- profile-app — THREAD-FOLLOW: the `forum.followed_topic` bell event.
--
-- Lane: thread-follow (2026-07-28). Spec: docs/atlas/THREAD-FOLLOW-SPEC.md §3.3.
--
-- ONE new value in the type vocabulary. Nothing else changes:
--
--   * The coalescing index (uq_notifications_target_unread, 2026-07-12 migration
--     :64-74) needs NO change. followed_topic rows carry anchor_id = NULL, which
--     COALESCE(anchor_id,0) folds to 0 — exactly the shape forum.reply_to_topic
--     already uses to get ONE row per topic per user. Second replier coalesces to
--     "Alice and 1 other replied in a discussion you follow", link re-pointed at
--     the newest reply, read-resets-the-row, 30-day prune — ALL INHERITED.
--   * notifications_target_shape needs no change: followed_topic always carries a
--     (kind,id) target and a deep link, so it satisfies the existing CHECK.
--
-- WHY THIS IS THE FOURTH AND LEAST-SPECIFIC RUNG (§0.0, and it is the whole reason
-- the opt-in reversal was cheap): the other three forum.* types are authorship- or
-- mention-based and fire for people holding ZERO subscriptions. This type is the
-- only one that requires a deliberate opt-in — "a thread I chose to watch but am
-- not otherwise part of". A topic author still hears about replies to their own
-- topic via forum.reply_to_topic without following anything.
--
-- The FOLLOW BIT ITSELF IS NOT HERE. It lives in the `looth` database
-- (forums.topic_follow, bb-mirror/sql/2026-07-28-topic-follow.sql) because its one
-- writer and its one reader are both on the WP pool. See that file's header for the
-- reasoning and for the deliberate deviation from SPEC §5's recommended placement.
--
-- APPLY (dev2 only; table owned by the `profile-app` role, so run as it — peer auth):
--     sudo -u profile-app psql -d profile_app -f 2026-07-28-followed-topic.sql
--
-- Idempotent: DROP-then-ADD the constraint. Reversible: DOWN block at the foot.

BEGIN;

-- ---------- widen the type vocabulary by exactly one value ----------
-- Must stay in lockstep with Notifications::HUB_TYPES (src/Notifications.php:38-43)
-- and with internal-notify.php's in_array() gate, which rejects unknown types 400.
ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_type_check;
ALTER TABLE notifications ADD  CONSTRAINT notifications_type_check CHECK (type IN (
    'message', 'connection_request', 'connection_accept',
    'forum.reply_to_topic', 'forum.reply_to_reply', 'forum.mention', 'reaction.on_post',
    'forum.followed_topic'
));

COMMIT;

-- ---------------------------------------------------------------------------
-- DOWN (reversible; drops only this lane's rows, leaves every other type intact):
--
--   BEGIN;
--   DELETE FROM notifications WHERE type = 'forum.followed_topic';
--   ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_type_check;
--   ALTER TABLE notifications ADD  CONSTRAINT notifications_type_check CHECK (type IN (
--       'message', 'connection_request', 'connection_accept',
--       'forum.reply_to_topic', 'forum.reply_to_reply', 'forum.mention', 'reaction.on_post'
--   ));
--   COMMIT;
--
-- NOTE the DELETE must run BEFORE the constraint is narrowed, or the ADD fails on
-- the rows it is meant to be removing.
-- ---------------------------------------------------------------------------
