-- profile-app — legacy location columns: confirm/flip new-member defaults.
--
-- ⚠️ WRITE-ONLY — apply-ready, idempotent, NOT YET APPLIED (coordinator
-- applies after review). Relay: docs/relay-to-profile-app-location-default.md.
--
-- New members should start with members-visible, city-level location. The new
-- audience-precision model (location_members_precision, location_public_precision
-- — added 2026-05-31, public-precision flipped to 'city' today in
-- 2026-06-01-public-precision-city-default.sql) already gives that.
--
-- This file mirrors the same intent on the LEGACY columns (still written by
-- api/v0/me-location.php and read by Profile/Practice fallbacks):
--   location_visibility    → 'members'  (re-assertion; already DEFAULT 'members'
--                                         since 2026-05-27-slice-275.sql L16 —
--                                         no-op SET, idempotent)
--   location_pin_precision → 'city'     (real change; was DEFAULT 'exact' from
--                                         2026-05-30-location-pin-precision.sql L20)
--
-- No bulk UPDATE on existing rows — the cutover plan handles that separately
-- (relay §"What NOT to do now"; cutover step 7h).

BEGIN;

ALTER TABLE users ALTER COLUMN location_visibility    SET DEFAULT 'members';
ALTER TABLE users ALTER COLUMN location_pin_precision SET DEFAULT 'city';

COMMIT;

-- Verify post-apply:
--   \d users
-- Expect: Default 'members'::character varying on location_visibility,
--         Default 'city'::text                  on location_pin_precision.
