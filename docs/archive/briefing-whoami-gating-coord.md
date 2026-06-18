# Briefing — whoami / wp-user / gating chat

**Paste into a fresh chat.** This lane owns making `/whoami` the correct, single source of
truth for identity + tier, and making gating consumers READ from it. It is **foundational** —
the header-convergence lanes (archive-poc, bb-mirror, lg-layout-v2) all map `$ctx` from
`/whoami` verbatim, so until this lane is green their verifies are meaningless.

Stay in-lane: profile-app `/whoami` + the poller tier bridge + gating reads. Do NOT touch the
header markup/CSS (that's lg-shell/header lanes), conversions, or Buck's lanes. Cross-cutting
contract changes → coordinator.

## What's already known (don't re-derive)
- **whoami endpoint is healthy.** `/profile-api/v0/whoami` returns `{authenticated:false,tier:"public"}`
  when hit with the cookie-gate token and no WP session. A bare 403 just means you forgot the
  `loothdev_auth` cookie (the gate), NOT that whoami is down.
- **Contract** (STRANGLER-COORDINATION.md §2): anon `{authenticated:false,tier:"public"}`;
  authed `{authenticated, user_uuid, wp_user_id, slug, display_name, avatar_url, tier,
  provenance, capabilities, cache{}}`.
- **Architecture** (`profile-app/src/Whoami.php`):
  - Identity from **postgres, keyed by `wp_user_id`**.
  - Tier from the **poller**: `GET https://127.0.0.1/wp-json/looth-internal/v1/user-context/{wp_user_id}`
    (auth: `X-LG-Internal-Auth` shared secret `/etc/lg-internal-secret`). If unavailable →
    stub `tier:"public"` + `tier_unavailable:true`.
  - Auth resolution order: trusted WP-session bridge header (`X-LG-Internal-Auth` +
    `X-LG-WP-User-Id`, set by the WP shim) → JWT `looth_id` cookie → anon.
  - **30s Redis cache** (`Cache::getWhoami/purgeWhoami`); purged by Arbiter on
    `looth_tier_changed` and by profile-app `/me/*` mutations.

## Prime suspects (triage in this order)
1. **wp_user_id ↔ profile mapping broke in the CF reload.** The 6/2 reload replaced `wp_users`;
   WP user IDs may no longer match the `wp_user_id` profile-app stored in postgres. Authed
   whoami would then resolve the wrong person / nobody. **Check:** pick a known user (Ian),
   compare `wp_users.ID` now vs the `wp_user_id` in profile-app's postgres user row; confirm
   they still align. This is the most likely root of "it's a mess out there."
2. **Poller tier endpoint down → everyone `public`.** If `/wp-json/looth-internal/v1/user-context/{id}`
   isn't responding (or the shared secret mismatched after reload), whoami stubs `public` for
   all → universal gating walls that look like a gating bug but are a tier-source bug. **Check:**
   curl the poller endpoint with the internal secret for a known PRO user.
3. **Trusted-header bridge after reload.** Confirm the WP shim still sets `X-LG-WP-User-Id` and
   the secret in `/etc/lg-internal-secret` matches both sides.
4. **Stale Redis whoami.** Post-reload cache can serve a pre-reload identity for 30s+; confirm
   purge fired (or flush once).
5. **Scattered gating reads (the consumer mess).** Gating identity is read from cookies/JWT/
   globals in MANY files, not just headers — e.g. archive-poc `index.php`, `_page-shell.php`,
   `_render-main-row.php`, `search.php`, `archive.js`. Inventory who gates on `lg_tier`/JWT/
   `LG_VIEWER_TIER` instead of `/whoami`, and flag them. (Header `_chrome.php` files are
   already owned by the header lanes — don't double-fix; coordinate via coordinator.)

## Verify (the lane is green when)
- Authed `/whoami` for a known PRO user returns the right `wp_user_id`, `display_name`,
  `avatar_url`, and `tier:"pro"` (not `public`, `tier_unavailable` absent).
- Gated content unwalls for that user; logged-out still walls.
- The header lanes' verifies (PRO pill on /archive/, /hub/, /u/) now pass for the right reason.

## Dependencies / notes
- **Re-login first.** The siteurl fix (coordinator chat) corrected dev's URLs; the user must
  log out/in so a fresh WP session exists before authed whoami can resolve. See the
  db-reload-stale-sessions behavior.
- Tier is authoritative from WP roles → poller → whoami; NEVER the `lg_tier` cookie or a global
  (those are caches). See docs/TIER-TAXONOMY.md.

## Report back to coordinator
- Root cause(s) found (esp. #1 mapping / #2 poller), files/data touched, verify result,
  and a flagged list of any scattered gating reads for the header/consumer lanes to fix.
