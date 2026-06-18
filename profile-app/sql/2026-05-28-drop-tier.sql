-- profile-app — drop the unused users.tier column.
--
-- Coordination decision (STRANGLER-COORDINATION.md §2): profile-app does NOT
-- store tier locally. Tier comes from the poller via GET
-- /wp-json/looth-internal/v1/user-context/{wp_user_id}, served to consumers
-- through /profile-api/v0/whoami (30s Redis cache, purge on Arbiter writes
-- and on profile-app self-edits).
--
-- The users.tier column was added in slice 0 as a placeholder and never
-- populated. Removing it eliminates the temptation to backfill it and keeps
-- the contract clean: profile-app owns identity, poller owns tier.

BEGIN;
ALTER TABLE users DROP COLUMN IF EXISTS tier;
COMMIT;
