-- profile-app — resume PDF columns + visibility.
--
-- ⚠️ WRITE-ONLY — apply-ready, idempotent, NOT YET APPLIED (coordinator applies
-- after review). Phase 5 of the profile brief (Sharing/2026-06-01-profile-brief-plan.md).
--
-- Mirror of the avatar/banner versioning model with a third dimension: per-user
-- visibility for the resume itself (avatars + banners are always as-visible-as
-- the header; resumes are credential-like material members often want to gate
-- separately).
--
--   resume_url        — versioned served URL (/profile-media/resumes/<uuid>/<v>.pdf?v=<v>)
--   resume_version    — bumped on every successful upload
--   resume_visibility — 'public' | 'members' | 'private'; default 'members' per
--                       Ian's brief recommendation. Header-as-ceiling still
--                       applies (effective vis = MORE RESTRICTIVE of the two).

BEGIN;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS resume_url        text NULL,
    ADD COLUMN IF NOT EXISTS resume_version    integer NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS resume_visibility text NOT NULL DEFAULT 'members';

-- CHECK constraint mirrors the standard tri-state (public|members|private).
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_resume_visibility_ck;
ALTER TABLE users
    ADD CONSTRAINT users_resume_visibility_ck
        CHECK (resume_visibility IN ('public', 'members', 'private'));

COMMIT;

-- Verify post-apply:
--   \d users
-- Expect: resume_url (text), resume_version (integer NOT NULL DEFAULT 0),
--         resume_visibility (text NOT NULL DEFAULT 'members') with check
--         constraint users_resume_visibility_ck.
