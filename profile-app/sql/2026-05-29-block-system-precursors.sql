-- profile-app — block-system precursors that ride slice-4 cutover.
--
-- Two small schema adds called out by the converged block-system design
-- (docs/plan-profile-block-system.md, docs/spec-block-identity-location.md):
--
--   1. users.location_address — full address text, populated at slice-4
--      cutover from BB xprofile field 96 (the address-precision tier).
--      Carries the data we already have; without this column the editor
--      would need users to re-enter what BB already knows.
--
--   2. profile_socials_kind_ck — extend the allowed kind set with
--      'linktree'. Locked decision (2026-05-29): SOCIAL_KINDS gains
--      exactly one new entry — linktree. Reddit folds into 'web'
--      (preserve URL, not its own kind). Mapping is final.
--
-- The block-system pilot's other schema adds (users.at_a_glance,
-- users.location_exact_visibility, practices.type) are POST-cutover and
-- ship with the block-system build. This migration is just the two bits
-- that need to ride cutover: data we're snapshotting anyway, and a
-- taxonomy entry that the social backfill depends on.

BEGIN;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS location_address text;

ALTER TABLE profile_socials
    DROP CONSTRAINT IF EXISTS profile_socials_kind_ck;
ALTER TABLE profile_socials
    ADD CONSTRAINT profile_socials_kind_ck
    CHECK (kind = ANY (ARRAY[
        'instagram'::text, 'youtube'::text, 'bandcamp'::text, 'web'::text,
        'email'::text, 'phone'::text, 'x'::text, 'tiktok'::text,
        'facebook'::text, 'patreon'::text, 'linktree'::text
    ]));

COMMIT;
