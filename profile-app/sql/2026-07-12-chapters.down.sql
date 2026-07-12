-- profile-app — CHAPTERS, DOWN-MIGRATION (reverses 2026-07-12-chapters.sql).
--
-- Target DB: profile_app.  Apply as the schema OWNER:
--   sudo -u profile-app psql -d profile_app -v ON_ERROR_STOP=1 -f sql/2026-07-12-chapters.down.sql
--
-- ⚠️ THIS DESTROYS DATA. It drops every chapter, every membership, every announcement, and every
--    chat message ever posted in a chapter room. That is what "reverse the chapters feature"
--    means. It is safe on dev2 (test data only). Do NOT run it anywhere that has real chapter
--    content without an explicit dump first:
--       pg_dump -U profile-app -d profile_app -t chapter -t chapter_member -t chapter_post \
--               -t chapter_room_read > chapters-backup.sql
--
-- IDEMPOTENT: safe to re-run (IF EXISTS everywhere), and safe to run when the up-migration was
--   never applied.
--
-- WHAT IT DELIBERATELY DOES NOT TOUCH:
--   * discovery.comments rows (post_type='chapter_post') — a DIFFERENT DATABASE. They are
--     orphaned, not deleted, because this script cannot reach across databases and because
--     silently deleting from another app's store during a rollback is worse than leaving inert
--     rows. Clean them up separately, deliberately, with the companion looth-side script.
--   * The 445 pre-existing DM threads. They have chapter_id NULL and are untouched by every
--     statement below — the room DELETE is explicitly scoped `WHERE chapter_id IS NOT NULL`.

BEGIN;

-- 1. The chat rooms. This must happen BEFORE dropping message_threads.chapter_id, or we lose the
--    only way to tell a room from a DM and the room threads become invisible orphans forever.
--    messages.thread_id -> message_threads(id) is ON DELETE CASCADE, so this takes the room's
--    messages with it. chapter_room_read cascades from the same FK.
DELETE FROM message_threads WHERE chapter_id IS NOT NULL;

-- 2. Room read-state. (Already emptied by the cascade above; explicit for the case where the
--    table exists but message_threads.chapter_id was somehow already dropped.)
DROP TABLE IF EXISTS chapter_room_read;

-- 3. The room marker on the shared threads table.
DROP INDEX IF EXISTS uq_message_threads_chapter;
ALTER TABLE message_threads DROP COLUMN IF EXISTS chapter_id;

-- 4. Chapter content, then the chapters themselves. chapter_member/chapter_post FK to chapter(id)
--    ON DELETE CASCADE, but drop them explicitly and in dependency order so this also works if a
--    partial apply left the parent behind.
DROP TABLE IF EXISTS chapter_post;
DROP TABLE IF EXISTS chapter_member;
DROP TABLE IF EXISTS chapter;

COMMIT;

-- 5. NOT dropped: idx_messages_thread_id ON messages (thread_id, id).
--    It is a pure performance index on a PRE-EXISTING shared table, it is useful to the DM code
--    too, and dropping it is the one action here that could regress something outside this lane.
--    If you truly want the box back to byte-zero:
--       DROP INDEX IF EXISTS idx_messages_thread_id;

-- Verify post-apply:
--   \d chapter                                  -- expect: Did not find any relation named "chapter".
--   \d message_threads                          -- expect: NO chapter_id column.
--   SELECT count(*) FROM message_threads;       -- expect 445 (the DM threads, untouched).
--   SELECT count(*) FROM messages;              -- expect 2166 (the DM messages, untouched).
