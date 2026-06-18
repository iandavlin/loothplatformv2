-- Default everyone's PUBLIC map precision to 'city' (was 'private'). Ian: members should be
-- findable on the public map at city level by default; individuals can still dial down to
-- state/private. All current users sit at the 'private' default (none customized), so this is
-- a clean flip. members_precision stays 'city'.
UPDATE users SET location_public_precision = 'city' WHERE location_public_precision = 'private';
ALTER TABLE users ALTER COLUMN location_public_precision SET DEFAULT 'city';
