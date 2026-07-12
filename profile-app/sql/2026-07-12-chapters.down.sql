-- profile-app — CHAPTERS, DOWN-MIGRATION (reverses 2026-07-12-chapters.sql).
--
-- Target DB: profile_app.  Apply as the schema OWNER:
--   sudo -u profile-app psql -d profile_app -v ON_ERROR_STOP=1 -f sql/2026-07-12-chapters.down.sql
--
-- ⚠️ THIS DESTROYS DATA. It drops every chapter, every membership, and every chapter discussion
--    ever posted. That is what "reverse the chapters feature" means. It is safe on dev2 (test data
--    only). Do NOT run it anywhere that has real chapter content without an explicit dump first:
--       pg_dump -U profile-app -d profile_app -t chapter -t chapter_member -t chapter_post \
--               > chapters-backup.sql
--
-- IDEMPOTENT: safe to re-run (IF EXISTS everywhere), and safe to run when the up-migration was
--   never applied.
--
-- SCOPE: the chat room is DEFERRED and this migration never created it, so there is nothing room-
--   shaped to reverse here (no message_threads.chapter_id, no chapter_room_read). If a room is
--   revived later it ships as its own migration pair with its own down-script.
--
-- WHAT IT DELIBERATELY DOES NOT TOUCH:
--   * discovery.comments rows (post_type='chapter_post') — a DIFFERENT DATABASE. They are
--     orphaned, not deleted, because this script cannot reach across databases and because
--     silently deleting from another app's store during a rollback is worse than leaving inert
--     rows. Clean them up separately, deliberately, with the companion looth-side script.
--   * The 445 pre-existing DM threads and their messages — this migration never touched
--     message_threads at all, so they cannot be affected.

BEGIN;

-- Chapter content, then the chapters themselves. chapter_member/chapter_post FK to chapter(id)
-- ON DELETE CASCADE, but drop them explicitly and in dependency order so this also works if a
-- partial apply left the parent behind.
DROP TABLE IF EXISTS chapter_post;
DROP TABLE IF EXISTS chapter_member;
DROP TABLE IF EXISTS chapter;

COMMIT;

-- Verify post-apply:
--   \d chapter                                  -- expect: Did not find any relation named "chapter".
--   \d message_threads                          -- expect: unchanged, NO chapter_id column ever added.
--   SELECT count(*) FROM message_threads;       -- expect 445 (the DM threads, untouched).
--   SELECT count(*) FROM messages;              -- expect 2166 (the DM messages, untouched).
