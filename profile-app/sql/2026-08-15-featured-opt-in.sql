-- profile-app — FEATURED MEMBERS: the opt-in flag.
--
-- Lane: featured-members. Backlog item 18 (Ian 8/11, "fairly soon type of
-- thing"), all four design rulings recorded 2026-08-14 (docs/IAN-RULINGS-2026-08-14.md
-- item 6, after decide.html): tickbox = its own block (Option B); the front page
-- reads only opted-in + currently-selected members.
--
-- ── WHY A SEPARATE TIMESTAMP, NOT JUST THE BOOLEAN ──────────────────────────
-- The admin dash shows "opted in <date>" per Ian's ADMIN DASH ruling (tracks
-- featured HISTORY, who + when). A boolean alone loses that the moment it
-- flips; the dash mock (dash.html) already commits to showing it. Set on
-- every transition TO true (not just the first), so re-opting-in after an
-- untick shows the current commitment date, not a stale original one.
--
-- ── CONSENT SHAPE — the whole reason this migration exists as its own column
-- rather than reusing an existing flag ──────────────────────────────────────
-- DEFAULT FALSE, EXPLICIT, NEVER INFERRED (Ian's own words in the charter,
-- citing the bell_follows_bb_subscriptions consent-inference lesson). No
-- backfill, no default-derived-from-profile-completeness, nothing. A member is
-- in the selectable pool because they, personally, ticked a box — full stop.

BEGIN;

ALTER TABLE users
  ADD COLUMN featured_opt_in    BOOLEAN     NOT NULL DEFAULT false,
  ADD COLUMN featured_opt_in_at TIMESTAMPTZ;

-- Belt-and-braces: the timestamp is meaningless while opted out, and a stray
-- UPDATE that flips the boolean without touching the timestamp (or vice versa)
-- is exactly the kind of two-column drift a check constraint catches for free.
ALTER TABLE users
  ADD CONSTRAINT users_featured_opt_in_at_ck
  CHECK (featured_opt_in_at IS NULL OR featured_opt_in = true);

COMMENT ON COLUMN users.featured_opt_in IS
  'Explicit consent to be considered as a featured member on the front page. '
  'Opt-in, default false, never inferred. Set only by the member''s own PUT to '
  '/profile-api/v0/me/featured. Toggling this OFF removes the member from the '
  'admin-dash pool immediately, per Ian''s ruling.';
COMMENT ON COLUMN users.featured_opt_in_at IS
  'Set on every false->true transition (not just the first). NULL while '
  'featured_opt_in is false. Shown on the admin dash as "opted in <date>".';

COMMIT;

-- ── DOWN ─────────────────────────────────────────────────────────────────────
-- BEGIN;
-- ALTER TABLE users DROP CONSTRAINT users_featured_opt_in_at_ck;
-- ALTER TABLE users DROP COLUMN featured_opt_in_at;
-- ALTER TABLE users DROP COLUMN featured_opt_in;
-- COMMIT;
-- Destroys consent state. Say so before running it — a member who ticked the
-- box and is rolled back would need to tick it again; that is a real change to
-- what they agreed to, not a no-op.
