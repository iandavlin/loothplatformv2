-- Master profile switch (Ian 6/12 ruling 1, refactor increment A).
--
--   users.profile_visibility — 'public' (default) | 'private'.
--   private = OWNER-ONLY: invisible to members too — no directory card, no map
--   pin, no search hit, /u/ page gated — admins excepted. Enforced exclusively
--   through src/Visibility.php; no surface checks this column directly.
--
-- ONE DIAL (Ian 6/12 pm): there is no new UI control. The existing
-- profile-visibility chip (profile_sections key='header'.visibility) drives
-- this column — 'private' on the chip sets profile_visibility='private',
-- 'public'/'members' set it back to 'public' (me-header.php writes both).
--
-- Backfill: members whose header chip already says 'private' meant owner-only
-- (the old behavior blanked the page but leaked them named into the directory
-- — that was the bug). 0 rows on dev at migration time; the UPDATE keeps the
-- migration correct on any copy of the data.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS profile_visibility text NOT NULL DEFAULT 'public';

ALTER TABLE users DROP CONSTRAINT IF EXISTS users_profile_visibility_ck;
ALTER TABLE users
    ADD CONSTRAINT users_profile_visibility_ck
        CHECK (profile_visibility IN ('public', 'private'));

UPDATE users u
   SET profile_visibility = 'private'
 WHERE profile_visibility <> 'private'
   AND EXISTS (SELECT 1 FROM profile_sections s
                WHERE s.user_id = u.id AND s.key = 'header' AND s.visibility = 'private');
