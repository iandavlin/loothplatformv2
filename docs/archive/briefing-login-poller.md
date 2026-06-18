# Lane briefing — poller: login/onboarding fixes (lifecycle Phases 3-4)

You're the **poller lane**, picking up the *create/login* half of the user-lifecycle work now that the
delete half is done + integration-proven. Fresh focus. Work in your worktree (the git tsar will give you
the path; commit on your branch, tsar merges).

## Read first
1. This file.
2. `docs/USER-LIFECYCLE-AUDIT.md` — §3 gap register (G1/G2/G3/G7), §5 Phases 3-4, §2 CREATE map, file:line index.
3. `docs/STRANGLER-COORDINATION.md` §0 (commit discipline), §1 (tiers), the AUTH INVARIANT in §4.
4. `CLAUDE.md`.

## Context
The Patreon OAuth onboard creates a WP account but **never logs the member in** — they land anonymous
(real case: Mikelle Davlin, 2026-06-04). Root cause is wider: there's no single create front-door, and
several tier writers skip the cache-purge/source-write that keep `/whoami` honest. Fix these.

## Deliverables (urgent first)

**G1 — auto-login on Patreon onboard (the reported bug).** `lg-patreon-onboard.php:974-1012`: on
successful onboard (+ already_onboarded), call `wp_set_auth_cookie($uid, true)` so the connected member
lands logged-in. **Critical:** route it so the **`wp_login` action fires** (or mint explicitly) — the
JWT is minted on `wp_login` (`profile-auth.php:83`); a bare `wp_set_auth_cookie` without that = WP cookie
but no `looth_id` JWT = still anon on the fast path. Coordinate the mint trigger with the shim/identity
lane (`briefing-login-identity.md`). **This is an auth change → must be dev-built + soaked (AUTH INVARIANT).**

**G2 — fire `looth_tier_changed` on every tier write.** `MemberTools::doSetTier` (MemberTools.php:346)
and `RestController::giftAuth` (RestController.php:1552) write a role but never fire the action, so
`/whoami` serves stale tier up to 30s. Fire it (with the right provenance) after each.

**G3 — stop WP-dash role edits from being clobbered.** Hook `set_user_role`/`profile_update` so a manual
WP-admin role change writes a `manual_admin` source to `lg_role_sources` + runs Arbiter, instead of being
silently overwritten by the next sync.

**Phase 3 — canonical provision front-door.** `UserLifecycle::provision($email, $opts)` as the *only*
user creator (mirror of the `teardown()` you already built): WP account → role via Arbiter → bridge +
profile identity **blocking with retry** (fixes G7's fire-and-forget miss) → optional auth cookie. Route
the existing 7 creators (onboard, gift-auth, Stripe, sweep-match, admin, affiliate, native) through it so
they stop each keeping a different subset of promises (audit §2).

## Verify (dev-complete AND dev-proven)
- New Patreon onboard → member lands **logged-in**, `/whoami` returns authed + correct tier (not anon).
- Admin tier change in member-tools → `/whoami` reflects it immediately (cache purged).
- WP-dash role edit → survives the next Arbiter tick (source recorded).
- A user created via `provision()` has WP acct + role + bridge + profile identity, every time.

## Protocol
Burn in-lane; ping coordinator (via Ian) only for the cross-lane mint contract (G1) or a contract change.
Commit by pathspec; tsar reviews + merges + pushes (Ian-signed). Report:
`DONE · FILES (paths:lines) · VERIFIED (on dev) · CONTRACT · BLOCKED`.
