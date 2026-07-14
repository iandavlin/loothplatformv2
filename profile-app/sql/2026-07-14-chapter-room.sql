-- CHAPTER-V2 ask 3 (Ian 2026-07-14): the chapter CHAT ROOM.
--
-- ONE nullable column binds a group message_thread to a chapter, exactly as the chapters DDL
-- (2026-07-12-chapters.sql §4) reserved. A non-null chapter_id makes a thread that chapter's room.
--
-- ⚠️ DESIGN DIVERGENCE FROM THAT §4 COMMENT — READ THIS.
-- §4 sketched a DERIVE room: membership computed from chapter_member, ZERO message_recipients
-- rows, a 1-INSERT send, a watermark read-table, zero fan-out. Ian's 2026-07-14 CHAPTER-V2 ruling
-- chose the OPPOSITE for v1: ENUMERATE — reuse the shipped group messaging wholesale, syncing each
-- chapter join/leave into a real message_recipients row. This ships now and needs no new send/read/
-- list infra, at the cost of per-member fan-out (unread_count UPDATE per recipient per message; note
-- messages do NOT touch the notification bell, so there is no bell-spam). The DERIVE design remains
-- the SCALE path ("broadcast v2") for large chapters; git 2b3891d / 54b1828 hold its full sketch.
-- So under v1 a chapter room DOES carry recipient rows — the §4 "zero recipient rows" property is
-- intentionally not in force yet.

ALTER TABLE message_threads
    ADD COLUMN IF NOT EXISTS chapter_id bigint REFERENCES chapter(id) ON DELETE CASCADE;

-- One room per chapter.
CREATE UNIQUE INDEX IF NOT EXISTS uq_message_threads_chapter
    ON message_threads (chapter_id) WHERE chapter_id IS NOT NULL;

-- Down: profile-app/sql/2026-07-14-chapter-room.down.sql
