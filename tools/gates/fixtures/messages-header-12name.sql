-- Fixture for tools/gates/messages-header-footprint-gate.py — dev2 ONLY, idempotent.
--
-- WHY THIS EXISTS: dev2's largest organic message thread has FOUR people, which is not
-- enough to show the defect the header clamp fixes. Keeper asked for the saving to be
-- MEASURED, not extrapolated from a 3-name header, so the gate needs a many-name thread
-- that actually exists. Measured with it: 388px -> 100px at 1440.
--
-- Picks users with display names >= 18 chars on purpose: long names are what made the
-- list wrap to four lines in the first place, so a fixture of short names would be a
-- softer test than reality.
--
-- Re-runnable: deletes its own rows first. Never touches an organic thread.
--   sudo -u profile-app psql -d profile_app -f tools/gates/fixtures/messages-header-12name.sql
BEGIN;
DELETE FROM message_recipients WHERE thread_id IN
  (SELECT id FROM message_threads WHERE uuid = 'aaaaaaaa-0000-4000-8000-00000000f1f0');
DELETE FROM messages WHERE thread_id IN
  (SELECT id FROM message_threads WHERE uuid = 'aaaaaaaa-0000-4000-8000-00000000f1f0');
DELETE FROM message_threads WHERE uuid = 'aaaaaaaa-0000-4000-8000-00000000f1f0';

INSERT INTO message_threads (uuid, subject, is_group, created_by, created_at, last_message_at)
VALUES ('aaaaaaaa-0000-4000-8000-00000000f1f0', NULL, true,
        '6b3cd71f-54ba-53bb-b59f-3bb01ba836a7', now(), now());

INSERT INTO message_recipients (thread_id, user_uuid, unread_count, is_deleted)
SELECT t.id, u.uuid, 0, false
FROM message_threads t,
     (SELECT uuid FROM users WHERE uuid = '6b3cd71f-54ba-53bb-b59f-3bb01ba836a7'
      UNION
      SELECT uuid FROM (SELECT uuid FROM users
                        WHERE display_name IS NOT NULL AND length(display_name) >= 18
                          AND uuid <> '6b3cd71f-54ba-53bb-b59f-3bb01ba836a7'
                        ORDER BY uuid LIMIT 11) x) u
WHERE t.uuid = 'aaaaaaaa-0000-4000-8000-00000000f1f0';

INSERT INTO messages (thread_id, sender_uuid, body, kind, created_at)
SELECT t.id, '6b3cd71f-54ba-53bb-b59f-3bb01ba836a7',
       'Gate fixture thread — 12 participants, used to measure the header clamp.',
       'message', now()
FROM message_threads t WHERE t.uuid = 'aaaaaaaa-0000-4000-8000-00000000f1f0';
COMMIT;
