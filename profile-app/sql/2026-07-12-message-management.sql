-- 2026-07-12-message-management.sql — group messaging management (lane: messages-manage)
--
-- Four features on the existing thin async DM spine (sql/2026-05-30-social-layer.sql):
--   1. start a GROUP message (N recipients)   2. membership: add / remove / leave
--   3. edit own message ("(edited)")           4. delete own message (soft tombstone)
--
-- Additive + idempotent (twice-run proven). NO joined_at column: Ian ruled (2026-07-12)
-- that newly ADDED members see FULL prior history, so there is no per-member visibility
-- horizon in the read path.
--
-- message_threads:
--   is_group    — "once a group, ALWAYS a group". Set true when a thread is created with
--                 >2 members or a member is ever added. findPairThread() excludes it, so a
--                 group that later shrinks to 2 members can NEVER be reached by a 1:1 send
--                 (the count(*)=2 pair gate would otherwise start matching it — the exact
--                 privacy regression the 2026-07-10 findPairThread fix closed).
--   created_by  — the member who started the thread. Ian's remove ruling (2026-07-12):
--                 the CREATOR may remove anyone, a site admin may remove anyone, anyone may
--                 remove themselves. NULL for every BuddyBoss-migrated thread (no creator was
--                 ever recorded) → remove there falls back to admin + self.
--
-- messages:
--   kind        — 'message' (default, a real user message) | 'system' (a membership event
--                 line: "Ian added Doug", "Sharon left"). System lines are centered, never
--                 owned, never editable/deletable. Transparency instead of roles (Ian: flat).
--   edited_at   — non-null once the body was edited → the "(edited)" marker.
--   deleted_at  — non-null = soft-deleted tombstone. Body is withheld from the payload and
--                 any media object is GC'd from the message store; the row stays so thread
--                 flow survives ("Message deleted").

ALTER TABLE message_threads
    ADD COLUMN IF NOT EXISTS is_group   boolean NOT NULL DEFAULT false,
    ADD COLUMN IF NOT EXISTS created_by uuid REFERENCES users(uuid);

ALTER TABLE messages
    ADD COLUMN IF NOT EXISTS kind       text NOT NULL DEFAULT 'message',
    ADD COLUMN IF NOT EXISTS edited_at  timestamptz,
    ADD COLUMN IF NOT EXISTS deleted_at timestamptz;

ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_kind_ck;
ALTER TABLE messages
    ADD CONSTRAINT messages_kind_ck CHECK (kind IN ('message', 'system'));

-- Backfill (idempotent): flag existing multi-party threads as groups so the pair gate can
-- never resolve into them. created_by intentionally stays NULL for all migrated threads.
UPDATE message_threads t
   SET is_group = true
 WHERE is_group = false
   AND (SELECT count(*) FROM message_recipients r WHERE r.thread_id = t.id) > 2;
