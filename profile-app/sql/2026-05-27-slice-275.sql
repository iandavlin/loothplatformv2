-- Slice 2.75 — cutover-prep schema additions.
--
-- 1. location_visibility — replaces the precision-shave + per-class grants
--    model with a simple "who can SEE the location at all" toggle.
-- 2. archived_at — soft-archive marker for ghost / dup accounts the triage
--    tool will surface. Filtered out of directory/map/typeahead.
-- 3. legacy_xprofile — lossless dump bucket for BB xprofile fields that
--    don't have a typed home yet (galleries, work history, references…).
--
-- Also: drop location_precision. The new model is visibility-gated, not
-- precision-shaved; renderLocation() emits stored values verbatim.

BEGIN;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS location_visibility varchar(16) NOT NULL DEFAULT 'members',
    ADD COLUMN IF NOT EXISTS archived_at         timestamptz  NULL,
    ADD COLUMN IF NOT EXISTS legacy_xprofile     jsonb        NOT NULL DEFAULT '{}'::jsonb;

ALTER TABLE users
    DROP CONSTRAINT IF EXISTS users_location_visibility_ck;
ALTER TABLE users
    ADD  CONSTRAINT users_location_visibility_ck
         CHECK (location_visibility IN ('public','members','private'));

-- Defensive — DEFAULT handles new rows, but pre-existing rows during prior
-- partial migrations might have raced in as NULL before the DEFAULT landed.
UPDATE users SET location_visibility = 'members' WHERE location_visibility IS NULL;

-- Drop the precision column if a prior slice left it around. Done last so
-- the change is the very visible final line in \d users post-migration.
ALTER TABLE users DROP COLUMN IF EXISTS location_precision;

COMMIT;
