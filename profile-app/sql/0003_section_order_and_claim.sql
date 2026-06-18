-- profile-app — slice 1.5 schema additions.
--
-- Adds per-user persisted section order + explicit claim provenance.

BEGIN;

ALTER TABLE profiles
    ADD COLUMN IF NOT EXISTS section_order TEXT[] NOT NULL DEFAULT '{}',
    ADD COLUMN IF NOT EXISTS claimed_via   TEXT;

COMMIT;
