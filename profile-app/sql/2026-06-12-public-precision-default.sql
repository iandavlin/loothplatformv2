-- Members-only is the STARTING STATE for everyone (Ian 6/12 pm):
-- "all locations and profiles should be set to member as the default."
--
-- Audit of where defaults stood when this ran:
--   profile page ceiling  -> no row = HEADER_DEFAULT 'members'   (already ✓)
--   profile_sections.visibility default 'members'                (already ✓)
--   location_members_precision default 'city' (members see city) (already ✓)
--   resume 'members', discussion 'member'                        (already ✓)
--   location_public_precision default 'city'                     (THE GAP)
--
-- That last one meant a NEWLY provisioned member started opted into the
-- public luthier finder. The 6/12-am data pass already flipped existing
-- never-consented rows to 'private'; this aligns the column default so
-- future rows start members-only too. Public stays pure opt-in ("Public
-- sees" dial / "Put me on the map").

ALTER TABLE users ALTER COLUMN location_public_precision SET DEFAULT 'private';
