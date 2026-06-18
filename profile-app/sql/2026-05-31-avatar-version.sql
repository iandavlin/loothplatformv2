-- profile-app — avatar single-source: the versioned avatar column.
--
-- ⚠️ WRITE-ONLY — apply-ready, idempotent, NOT YET APPLIED (coordinator applies).
-- Separate file — never edit an applied migration.
--
-- The deferred inc-1 column. profile-app becomes the canonical avatar store: it
-- holds the bytes (media/avatars/<uuid>/<version>.<ext>, app-owned dir — NOT
-- wp-content) and serves a stable versioned URL. avatar_version bumps on each
-- upload; the served URL carries ?v=<version> so caches refresh and mirrors
-- (shared header, forum, archive bylines) re-pull after the /whoami purge.
-- Canon: STRANGLER-COORDINATION.md "Avatar / author-identity — SINGLE SOURCE".

BEGIN;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS avatar_version integer NOT NULL DEFAULT 0;

COMMIT;
