-- 2026-07-12 — members own their @username (username-mentions lane)
--
-- Today users.slug is auto-minted at provision (mostly `patreon_<id>` junk) and no
-- member can change it. This makes it a real, member-controlled handle: the /u/<slug>
-- URL AND the name every @mention renders.
--
-- Three things the store needs that it does not have:
--   1. Case-insensitive uniqueness. `users_slug_key` is UNIQUE on the raw text, so
--      `KevinSmith` and `kevinsmith` can both exist — two handles that read as the
--      same person. Verified 2026-07-12: zero existing case-collisions, so the index
--      below builds clean on live data.
--   2. A rate-limit clock (slug_changed_at).
--   3. slug_history — every handle a member has ever held.
--
-- WHY HISTORY IS UNIQUE ACROSS ALL MEMBERS (the load-bearing rule):
-- a retired handle is NEVER re-issued to a different member. If it were, an attacker
-- could wait for someone to rename, take their old handle, and inherit every historical
-- /u/<old> link and every legacy mention that pointed at the previous owner. That is not
-- hypothetical — it already happened in this data: WP user 1 (Ian) once held the nicename
-- `ianhatesguitars`, released it, and a different member now holds that exact slug, so a
-- historical mention renders his old handle while linking to Ian. The unique index makes
-- the invariant the DATABASE's job, not the app's.
--
-- A member CAN always reclaim their own retired handle (same user_id) — the API deletes
-- their history row on reclaim, so the handle is never in two places at once.

BEGIN;

-- 1. Case-insensitive uniqueness on the live handle.
CREATE UNIQUE INDEX IF NOT EXISTS users_slug_lower_key
    ON users (lower(slug))
    WHERE slug IS NOT NULL;

-- 2. Rate-limit clock. NULL = never changed => the first change is free (everyone is
--    currently sitting on an auto-minted slug they did not choose).
ALTER TABLE users ADD COLUMN IF NOT EXISTS slug_changed_at TIMESTAMPTZ;

-- 3. Retired handles.
CREATE TABLE IF NOT EXISTS slug_history (
    id          BIGSERIAL   PRIMARY KEY,
    user_id     BIGINT      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    slug        TEXT        NOT NULL,
    released_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- The never-re-issued invariant, enforced in the DB.
CREATE UNIQUE INDEX IF NOT EXISTS uq_slug_history_lower ON slug_history (lower(slug));
CREATE INDEX        IF NOT EXISTS idx_slug_history_user ON slug_history (user_id);

COMMIT;

-- Rollback:
--   DROP TABLE slug_history;
--   DROP INDEX users_slug_lower_key;
--   ALTER TABLE users DROP COLUMN slug_changed_at;
