-- profile-app — drop vestigial columns + add business_name
--
-- Decision in slice 2.75 (post-cold-walk audit): the legacy_xprofile jsonb
-- dump bucket is unused. The original plan was to park un-mapped BB xprofile
-- fields (work history, references, shop pics, resume) there until matching
-- section types existed. Ian's cutover-scope decision: drop them entirely,
-- only port name + business + location, let users rebuild the rest.
--
-- Likewise, location_grant_* columns date from the precision-shaving privacy
-- model that the slice 2.75 visibility gate replaced. Nothing reads them.
--
-- business_name gets its own column because legacy_xprofile is going away
-- and "stash it for slice 3 practices" is the only data that needs a home.

BEGIN;

ALTER TABLE users ADD COLUMN business_name text;

ALTER TABLE users DROP COLUMN IF EXISTS legacy_xprofile;
ALTER TABLE users DROP COLUMN IF EXISTS location_grant_public;
ALTER TABLE users DROP COLUMN IF EXISTS location_grant_members;
ALTER TABLE users DROP COLUMN IF EXISTS location_grant_friends;

COMMIT;
