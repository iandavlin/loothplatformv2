# Briefing — logged-out header after the 6/3 dev DB reload (lg-shell / header lane)

**Paste into a fresh chat. You are the lg-shell / canonical-header lane.**
Sanity-check the box first: `curl -s ifconfig.me` (→ 50.19.198.38 = you ARE the dev box; act
locally with sudo, do NOT SSH). Then read `~/.claude/CLAUDE.md`.

## Symptom
The site header renders **logged-out** ("Sign in") for users who **are** logged into WordPress.
Surfaced on `/hub/` (bb-mirror) but the header is shared, so treat it as cross-consumer.
Started around the **6/3 dev DB reload** (newest `wp_users.user_registered` = 2026-06-03 16:13:55).

## Likely root cause — UPSTREAM in /whoami, not the header markup
Governance: lg-shell owns the ONE canonical header (`/srv/lg-shared/site-header.php`); consumers
(bb-mirror `_chrome.php`, etc.) only populate `$ctx` from `/whoami` and must NOT fork their own.
So if the header shows anon, it's because **/whoami returned `authenticated:false`** for a
logged-in user — the header is faithfully mirroring it. A DB reload breaks /whoami several ways
(all data/auth-layer, none in header code). **Check these FIRST** — see
[[project_cf_reload_whoami_casualties]]:
1. Poller plugin deactivated → `user-context` 404 → everyone stubbed `tier:public`.
2. `lgms_db_*` creds blanked → poller fatals.
3. BuddyBoss REST gate re-armed → `looth-internal/v1` 401s.
4. **Bridge gaps** → reload-imported users have no `wp_user_bridge` row → authed `/whoami`
   returns **anon** → header shows logged-out. Fix: re-run `profile-app/bin/reconcile-bridge.php`.
5. `wp core update-db` nag (harmless).

Confirm by calling `/whoami` as an affected logged-in user: if it returns anon, the fix is the
reload-casualty list above (likely the bridge), NOT the header.

## The header-side design question (your call, lg-shell)
Even once /whoami is fixed: should the canonical header carry an **auth-anchor** so a user who is
demonstrably logged into WP (presence of `wordpress_logged_in_*` cookie / a valid JWT) is **never**
shown fully logged-out, even when tier/identity resolution degrades? Today `authenticated:false`
collapses straight to the "Sign in" anon state. An auth-anchor would degrade gracefully
(show signed-in chrome, fall back on name/tier only) instead of looking logged-out on any whoami hiccup.
bb-mirror's `_chrome.php` already has a partial fallback (parses `wordpress_logged_in_` when authed
but display_name empty, ~line 444) — decide whether that belongs in the **canonical** header for all consumers.

## Constraints
- Dev = fixtures only. Header/whoami/nginx are the cross-cutting pieces you own; **forum author-name
  drift is a SEPARATE, already-resolved lane** (bb-mirror) — don't conflate them.
- Canonical header is single-source: fix it in `/srv/lg-shared/site-header.php`, never per-consumer forks.
- Leave changes uncommitted for review-before-push.

## Quick-ref
- Header: `/srv/lg-shared/site-header.php` (canonical); consumer wiring example: `bb-mirror/web/_chrome.php`
  (`lg_bb_mirror_viewer_from_whoami()`, header-convergence Step 1 = commit 05e36b6).
- whoami: `https://dev.loothgroup.com/api/v0/whoami` (fast JWT path) vs the WP-shim REST route — see
  [[project_whoami_shim_bootstrap_cost]].
- Tier vocabulary: `docs/TIER-TAXONOMY.md` ([[reference_tier_taxonomy]]).
