-- profile-app — location: per-AUDIENCE precision (Ian's model, 2026-05-31).
--
-- One stored address; two audience knobs decide how precise the DISPLAY is:
--   location_members_precision — what a signed-in member sees
--   location_public_precision  — what a logged-out visitor sees
-- Each ∈ private | state | city | street. Owner always sees street.
-- Supersedes the framing of location_visibility / location_exact_visibility /
-- location_pin_precision (kept as legacy columns; the block render now uses these two).

BEGIN;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS location_members_precision text NOT NULL DEFAULT 'city',
    ADD COLUMN IF NOT EXISTS location_public_precision  text NOT NULL DEFAULT 'private';

ALTER TABLE users DROP CONSTRAINT IF EXISTS users_location_members_precision_ck;
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_location_public_precision_ck;
ALTER TABLE users
    ADD CONSTRAINT users_location_members_precision_ck
        CHECK (location_members_precision IN ('private','state','city','street')),
    ADD CONSTRAINT users_location_public_precision_ck
        CHECK (location_public_precision  IN ('private','state','city','street'));

COMMIT;
