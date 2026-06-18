-- profile-app — discussion-author posting visibility (public|member).
--
-- ⚠️ WRITE-ONLY — apply-ready, idempotent, NOT YET APPLIED (coordinator applies
-- after review). Piece #1 of the discussion-author visibility feature
-- (docs/briefing-discussion-visibility.md).
--
-- Per-user preference for whether LOGGED-OUT viewers see the user's real identity
-- on their DISCUSSION (forum) posts. The Hub reads it (via the forums.person sync
-- in archive-poc) to mask member-only authors from the open web. Scope is
-- discussions only — CPTs (articles/videos/loothprints) stay public, untouched.
--
--   discussion_visibility — 'public' | 'member'; DEFAULT 'member' (Ian 6/7):
--                           names hidden from the open web until a user opts Public.
--
-- ⚠️ Vocabulary note: this column uses SINGULAR 'member' (not the tri-state
-- 'members' used by section visibility) — it is a 2-state author mask, and the
-- profile-page toggle UI + the /me set-endpoint are coded against 'public'|'member'.

BEGIN;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS discussion_visibility text NOT NULL DEFAULT 'member';

ALTER TABLE users DROP CONSTRAINT IF EXISTS users_discussion_visibility_ck;
ALTER TABLE users
    ADD CONSTRAINT users_discussion_visibility_ck
        CHECK (discussion_visibility IN ('public', 'member'));

COMMIT;

-- Verify post-apply:
--   \d users
-- Expect: discussion_visibility (text NOT NULL DEFAULT 'member') with check
--         constraint users_discussion_visibility_ck IN ('public','member').
