-- Down for 2026-07-14-chapter-room.sql
DROP INDEX IF EXISTS uq_message_threads_chapter;
ALTER TABLE message_threads DROP COLUMN IF EXISTS chapter_id;
