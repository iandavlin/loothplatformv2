# Lane briefing — identity stability (shim-replacement lead · profile-app partner)

Two lanes, one coupled problem: a member's identity must survive an email change and a programmatic
login. **shim-replacement leads** (owns the JWT mint); **profile-app partners** (owns the bridge + the
stored UUID). Both open this file; build to the split below. Work in your worktrees; tsar merges.

## Read first
1. This file.
2. `docs/USER-LIFECYCLE-AUDIT.md` — gaps **G4** + **G7**, §2 LOGIN section, file:line index.
3. `docs/STRANGLER-COORDINATION.md` §0c (post-shim identity contract), §2 (whoami), AUTH INVARIANT.

## The bug (G4)
The `looth_id` JWT's `sub` is **recomputed from the user's email on every mint** (`profile-auth.php:51`),
but profile-app resolves identity from the **stored** `users.uuid` (`Whoami.php:113`). Change your email
in WP → the new mint's `sub` diverges from the stored uuid → `/whoami` returns **anon**. Silent logout-
as-stranger.

## Split

**shim-replacement (lead):**
- Stop deriving `sub` from the live email at mint time. Carry the **stored** `users.uuid` (look it up via
  the bridge / a profile-app call) so the token's identity is stable across email changes.
- Coordinate **G1** with the poller lane: ensure the onboard's `wp_set_auth_cookie` path triggers the
  mint (the `wp_login` action, or an explicit mint call) — otherwise onboarded members get a cookie but
  no JWT and stay anon. Confirm the mint contract back to poller.

**profile-app (partner):**
- On WP email change, **keep the UUID stable** — reconcile the email alias without changing
  `users.uuid` (add the new email as an alias; never re-key identity off email). Expose what the shim
  needs to fetch the stored uuid at mint.
- **G7:** make the `user-created` path (bridge + identity) reliable — the current `user_register` →
  `profile-sync.php:41` webhook is fire-and-forget (1s, no retry), so the bridge can silently miss.
  Back the poller's blocking `provision()` (briefing-login-poller.md) with an idempotent, retryable
  create so a new user always ends up bridged.

## Verify
- Change a test user's WP email → they stay logged in, `/whoami` still authed, same uuid.
- Onboard a fresh Patreon user → JWT minted, `/whoami` authed (pairs with poller G1).
- Kill profile-app briefly during a signup → the user still ends up bridged once it's back (retry).

## Protocol
Cross-lane: the mint↔uuid contract is the one thing to agree explicitly (shim ↔ profile-app ↔ poller's
mint trigger) — settle it in this file, ping coordinator only if it changes. Commit by pathspec; tsar
merges + pushes (Ian-signed). Report: `DONE · FILES · VERIFIED · CONTRACT · BLOCKED`.
