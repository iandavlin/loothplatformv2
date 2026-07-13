-- profile-app — NOTIFICATIONS: hub events (replies, mentions, reactions).
--
-- Lane: notifications (2026-07-12). Extends the EXISTING `notifications` table
-- (sql/2026-05-30-social-layer.sql) rather than adding a second store — the bell
-- stays ONE store with ONE writer, exactly like the reaction_count contract.
--
-- Why new columns and not a new table: the social-layer schema said it plainly —
-- "extensible to other lanes later via the `type` namespace (a new lane adds its
-- type + its own nullable referent column)". Hub events are that lane.
--
-- The referent is a (kind, id) pair + a denormalized deep link, NOT an FK: the
-- things being pointed at (bbPress topics/replies, managed-CPT cards) live in
-- MySQL/WP and in the `looth` PG database — profile_app cannot FK across either
-- boundary. target_url is computed WP-side at push time (only WP knows the forum
-- and topic slugs) and stored, so rendering the bell needs no cross-DB lookup.
--
-- APPLY (dev2 only; the table is owned by the `profile-app` role, so run as it —
-- peer auth, no ALTER OWNER needed; verified 2026-07-12):
--     sudo -u profile-app psql -d profile_app -f 2026-07-12-notifications-hub-events.sql
--
-- Idempotent: re-running is a no-op (IF NOT EXISTS / DROP-then-ADD constraints).
-- Reversible: see the DOWN block at the foot of this file.

BEGIN;

-- ---------- referent + coalescing columns ----------
-- target_kind: 'topic' | 'reply' | 'card'  — deliberately an open vocabulary, NOT
--   an enum/CHECK, so a future lane (dmv-native chapters: 'chapter_post',
--   'chapter_chat') slots in with ZERO schema change. The renderer switches on it.
-- target_id:   the WP post id of the thing (topic id / reply id / card post id).
-- anchor_id:   the specific reply to scroll to + highlight inside the topic modal
--              (NULL = no sub-anchor; the topic itself is the target).
-- target_url:  the deep link, stamped at push time. Points at the CURRENT deep-link
--              system (/hub/?topic=<forum>/<topic>[&reply=<id>]) — never a legacy
--              BuddyBoss full-page URL, never a generic /hub/ landing.
-- actor_count: coalescing. Two reactors on one card = ONE row, latest actor, count 2
--              ("Alice and 1 other reacted to your post").
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS target_kind text;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS target_id   bigint;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS anchor_id   bigint;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS target_url  text;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS actor_count integer NOT NULL DEFAULT 1;

-- ---------- widen the type vocabulary ----------
-- Legacy three keep their bare names (they are live and written by Connections.php
-- / Messaging.php — renaming them would be a data migration for zero user-visible
-- gain). New events use the namespaced `domain.event` shape from the taxonomy in
-- docs/atlas/NOTIFICATIONS-AUDIT.md §4.1.
ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_type_check;
ALTER TABLE notifications ADD  CONSTRAINT notifications_type_check CHECK (type IN (
    'message', 'connection_request', 'connection_accept',
    'forum.reply_to_topic', 'forum.reply_to_reply', 'forum.mention', 'reaction.on_post'
));

-- A hub-event row is useless without something to click through to. Legacy rows
-- (which route by thread_id/connection_id) are exempt.
ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_target_shape;
ALTER TABLE notifications ADD  CONSTRAINT notifications_target_shape CHECK (
    type IN ('message', 'connection_request', 'connection_accept')
    OR (target_kind IS NOT NULL AND target_id IS NOT NULL AND target_url IS NOT NULL)
);

-- ---------- dedup / coalesce target ----------
-- ONE UNREAD row per (recipient, event type, target, sub-anchor):
--   * re-fire dedupes    — the same reply syncing twice bumps the row, never doubles it
--   * two reactors merge — one row, latest actor, actor_count = 2
-- Scoped to is_read = false ON PURPOSE: once you have READ "Alice replied", a LATER
-- reply must be able to ring again (a fresh row, count back to 1) instead of being
-- silently swallowed into the row you already dismissed. COALESCE(anchor_id, 0)
-- because NULLs are never equal in a unique index — without it, every
-- reply-to-topic row (anchor NULL) would be distinct and dedup would not fire.
CREATE UNIQUE INDEX IF NOT EXISTS uq_notifications_target_unread
    ON notifications (user_uuid, type, target_kind, target_id, COALESCE(anchor_id, 0))
    WHERE target_kind IS NOT NULL AND is_read = false;

COMMIT;

-- ---------------------------------------------------------------------------
-- DOWN (reversible; drops the hub-event rows, leaves the legacy bell untouched):
--
--   BEGIN;
--   DELETE FROM notifications WHERE target_kind IS NOT NULL;
--   DROP INDEX IF EXISTS uq_notifications_target_unread;
--   ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_target_shape;
--   ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_type_check;
--   ALTER TABLE notifications ADD  CONSTRAINT notifications_type_check
--       CHECK (type IN ('message','connection_request','connection_accept'));
--   ALTER TABLE notifications DROP COLUMN IF EXISTS actor_count;
--   ALTER TABLE notifications DROP COLUMN IF EXISTS target_url;
--   ALTER TABLE notifications DROP COLUMN IF EXISTS anchor_id;
--   ALTER TABLE notifications DROP COLUMN IF EXISTS target_id;
--   ALTER TABLE notifications DROP COLUMN IF EXISTS target_kind;
--   COMMIT;
-- ---------------------------------------------------------------------------
