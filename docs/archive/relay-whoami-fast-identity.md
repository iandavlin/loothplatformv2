# Spec — fast `/whoami` identity (kill the ~1s WP-bootstrap cost)

**To:** whoami/gating lane · **Priority:** #1 (Ian) · **From:** coordinator (2026-06-03)

## Problem
Authenticated `/whoami` costs ~1–1.5s because resolving the WP-session identity boots the **full
WP stack — all plugins + theme + BuddyBoss + Elementor** (via the REST path) just to read a cookie.
The 30s Redis cache only covers profile-app's DB work, not the bootstrap. Same root cause behind:
(a) the per-page perf tax, (b) standalone surfaces (`/archive/`, `/stream/`) rendering logged-out
for real users, (c) the stream prototype's blocked authed like flow. **One fix clears all three.**

## Constraints
- **Keep WP as the login authority.** Do NOT pull auth out of WP.
- **Do NOT change the `/whoami` contract shape** — consumers + the shared header stay untouched.
- Use WP's OWN cookie validation (correctness: logout revocation, salt rotation). Do NOT hand-roll
  raw HMAC cookie parsing.

## The fix (two layers)
**1. Dedicated SHORTINIT identity endpoint** — replaces the full-WP bootstrap for the WP-session→wp_user_id step:
- Standalone PHP, its own nginx location (archive-poc pool or a tiny dedicated one). Body:
  `define('SHORTINIT', true); require '/var/www/dev/wp-load.php';` then require the auth pieces
  (`pluggable.php` + its deps) and call `wp_validate_auth_cookie($_COOKIE[LOGGED_IN_COOKIE], 'logged_in')`.
- SHORTINIT loads WP **core only** (DB, options, secret keys) — **no plugins, no theme, no Elementor/BB**.
- Returns the validated `wp_user_id` (or anon). This is the ONLY part being replaced — profile-app
  still maps wp_user_id → full identity (postgres) + tier (poller) exactly as today.

**2. JWT front (the bridge that isn't shipped yet)** — eliminate the per-page call on the common path:
- On a successful SHORTINIT validation, **mint/set the `looth_id` JWT** (it already carries identity).
- whoami then resolves from the JWT FIRST (zero WP call); falls back to the SHORTINIT endpoint only
  when there's no/expired JWT. This is also the missing WP-session→JWT bridge the stream lane flagged —
  shipping it makes standalone surfaces (`/archive/`, `/stream/`) resolve logged-in users.

## Measure before/after (don't declare victory on theory)
Coordinate with the **perf-czar lane**: re-run the authenticated `/whoami` timing after the change and
log before/after in `docs/PERF-BASELINE.md`. Expected: ~1s → ~100ms via SHORTINIT (floor depends on
MySQL connect + autoloaded-options weight — measure it), and ~0 on JWT-cached loads.

## Verify (done when)
- Authenticated `/whoami` returns in **<~150ms** (target ~100ms), contract shape unchanged.
- Test user **pilot_pro (id 1883, looth4→pro)** resolves logged-in + **PRO pill shows** on `/archive/` in-browser.
- The stream prototype's **authed like flow works** in-browser (like persists, count increments, survives reload).
- **Logout still revokes** (kill the session, confirm whoami flips to anon — proves we used WP's real validation).
- perf-czar before/after logged.

## Coordination
The **stream lane** + **perf-czar** both depend on this — report back the moment it's live so the
coordinator can fan out the "go" (stream does its authed end-to-end pass; perf-czar logs the number).
Anything cross-cutting (header, /whoami shape) routes to coordinator — do not change those yourself.
