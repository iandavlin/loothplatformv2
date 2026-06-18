-- profile-app — banner image columns (per-user profile-header banner).
--
-- ⚠️ WRITE-ONLY — apply-ready, idempotent, NOT YET APPLIED (coordinator applies
-- after review). Phase 3 of the profile brief (Sharing/2026-06-01-profile-brief-plan.md).
--
-- Mirror of the avatar versioning model:
--   banner_url      — versioned served URL (/profile-media/banners/<uuid>/<v>.<ext>?v=<v>)
--   banner_version  — incremented on every successful upload (cache-bust + diff signal)
--
-- Banner is OPTIONAL — null means no banner; the renderer omits the element.
-- Visibility is inherited from the header (the banner lives INSIDE the header
-- card, so the header-as-ceiling rule covers it — no separate vis column).

BEGIN;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS banner_url     text NULL,
    ADD COLUMN IF NOT EXISTS banner_version integer NOT NULL DEFAULT 0;

COMMIT;

-- Verify post-apply:
--   \d users
-- Expect: banner_url (text), banner_version (integer, NOT NULL, DEFAULT 0).
