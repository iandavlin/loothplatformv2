-- profile-app slice 2 foundation:
--   (a) Empty profile_sections rows are no longer the source of "inactive."
--       Drop them. Going forward, no row = inactive; rows are created on
--       first non-empty save.
--   (b) Backfill profiles for every user with a non-empty location_text
--       (the slice-zero xprofile pull). They count as "claimed" via the
--       backfill provenance — no first-visit interstitial for them.

BEGIN;

-- (a) Drop empty profile_sections rows. Match the slice-one auto-seed shape
-- (data={text:""}) AND any data nullish that may have slipped through.
DELETE FROM profile_sections
WHERE data IS NULL
   OR data::text = '{}'
   OR (data ? 'text' AND (data->>'text' IS NULL OR data->>'text' = ''));

-- (b) Backfill profiles for users with a location string.
INSERT INTO profiles (user_id, claimed_at, claimed_via)
SELECT u.id, NOW(), 'backfill_location'
FROM users u
WHERE u.location_text IS NOT NULL AND u.location_text != ''
ON CONFLICT (user_id) DO NOTHING;

COMMIT;
