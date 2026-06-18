# profile-app — Session Handoff (2026-05-28, slice 3.5 as-built)

> Prior handoff: `handoffs/2026-05-27-slice-three-plus-coordination.md`.
> This handoff covers slice 3.5: `/whoami`, batch `/users`, internal purge
> endpoint, WP shim, self-purge wiring, and the drop of `users.tier`.
> Step 1 of the cutover sequence is COMPLETE.

## What surprised me

1. **FPM curl loopback wants HTTP/1.1 + 5s timeout.** Same code from CLI as
   `profile-app` user completes in ~820ms via HTTP/2/ALPN. From inside FPM
   serving a request, the same call timed out at 2s and 5s repeatedly until
   I forced `CURLOPT_HTTP_VERSION=CURL_HTTP_VERSION_1_1` and `CURLOPT_TIMEOUT=5`.
   ALPN handshake on a fresh FPM-worker SSL session is the apparent culprit.
   Worth remembering for every future profile-app-to-loopback call.
2. **Self-purge on `/me/name` was the only purge required day one.** Of all
   `/me/*` handlers, only `me-name.php` mutates fields surfaced in `/whoami`
   (display_name). Avatar URL, slug, business_name aren't mutated through
   live endpoints today (slug from migration, avatar via backfill script,
   business_name is in `/me/name` but not in `/whoami` payload). One purge
   call covers the contract.
3. **`/etc/lg-internal-secret` is `root:www-data 0640`.** profile-app FPM
   runs as `profile-app` user, not `www-data`. `setfacl -m u:profile-app:r`
   was the clean unblocker — predicted in the coordinator's preemptive
   relay and confirmed needed.
4. **`users.tier` had three live readers** (Profile.php `loadFull` line 232
   + `renderForViewer` line 299). Both were emitting null implicitly via
   `$user['tier']` which became a PHP warning the moment the column dropped.
   Caught immediately because `/u/iandavlin` writes the warning into the
   error log; fixed by removing the keys from the return arrays. Lesson:
   `grep -rn "user\['tier'\]"` before dropping any column.
5. **Capabilities merge: the poller returns a richer map than §2's named
   trio.** It emits `manage_options`, `edit_archive_poc`, `edit_posts`,
   `moderate_forums`. I pass-through `edit_posts` and `moderate_forums`
   on top of the §2-named caps + profile-app's own `edit_own_profile`.
   Flagged in coordinator report for review; trivially clip if §2 wants
   strict-narrow.

## What this slice shipped

### Endpoints

- **`GET /profile-api/v0/whoami`** — anon + authed shape per
  STRANGLER-COORDINATION.md §2. ETag/304 honored. 30s Redis cache.
  Cache key: `pa:whoami:user:{wp_user_id}`. Cache miss → fetch tier
  from poller, build shape, set.
- **`GET /profile-api/v0/users?uuids=<csv>`** — batch lookup. Cap 100.
  Returns `[{uuid, slug, display_name, avatar_url}]`. Filters
  `archived_at IS NULL`.
- **`POST /profile-api/v0/internal/purge-whoami`** — localhost-only
  (nginx `allow 127.0.0.1; deny all;` + cookie-gate skipped). Auth is
  `X-LG-Internal-Auth` header verified via `hash_equals()` against
  `/etc/lg-internal-secret`. Returns 204 on success, 403 on bad auth.
- **`GET /wp-json/looth/v1/whoami`** — WP shim mu-plugin. Forwards
  caller cookies verbatim; passes through ETag + Cache-Control from
  upstream; returns identical body.

### Sources

- `src/Cache.php` — thin Redis wrapper. Unix socket
  `/run/redis/redis-server.sock` preferred, 127.0.0.1:6379 fallback.
  Methods: `getWhoami`, `setWhoami`, `purgeWhoami`. Redis-down → silent
  no-op (a broken cache never breaks the API).
- `src/Whoami.php` — payload assembler. `resolve()` for current JWT
  bearer; `buildForWpUserId()` for the WP shim. Calls poller's
  `https://127.0.0.1/wp-json/looth-internal/v1/user-context/{wp_user_id}`
  with `X-LG-Internal-Auth` header. Stub `tier: "public" +
  tier_unavailable: true` if poller unreachable.
- `src/Profile.php` — dropped tier reads from `loadFull()` +
  `renderForViewer()`.
- `config.php` — autoloads Cache + Whoami.

### Schema

`sql/2026-05-28-drop-tier.sql` (applied to dev):
```sql
ALTER TABLE users DROP COLUMN IF EXISTS tier;
```
Zero code references at drop time confirmed via grep.

### Wiring

- `api/v0/me-name.php` — calls `Cache::purgeWhoami($wpId)` after the
  wp_users mirror runs. Tested: PATCH display_name → immediate `/whoami`
  reflects new value.
- `/etc/nginx/snippets/strangler-profile-app.conf` — three new rewrites
  + a localhost-only fastcgi block for `internal-purge-whoami.php` +
  added `whoami` + `users` to the public/auth-aware regex.
- `/var/www/dev/wp-content/mu-plugins/profile-whoami-shim.php` —
  deployed copy of `deploy/profile-whoami-shim.mu-plugin.php`.
- `/etc/lg-internal-secret` ACL: `setfacl -m u:profile-app:r`.

### Walk-onboarding

`bin/walk-onboarding.sh` gained 5 new smoke steps after 9b
(business_name patch):
- **9c.** /whoami shape sanity (authed; required keys present)
- **9c.** self-purge: PATCH name → next /whoami reflects new value
- **9c.** WP shim returns identical shape
- **9c.** /users?uuids=<self> count == 1
- **9c.** internal purge: 403 without header, 204 with valid secret

Latest green run: `/var/www/dev/mockups/walks/20260528T165628Z/`.

### Verified flows

```
$ curl -sk -H "Cookie: loothdev_auth=…; looth_id=…" .../profile-api/v0/whoami
{
  "authenticated": true,
  "user_uuid": "f20ad778-1e5e-5508-853b-ad928c499f2f",
  "wp_user_id": 1,
  "slug": "iandavlin",
  "display_name": "Ian B Davlin",
  "avatar_url": "...",
  "tier": "public",
  "provenance": "lapsed",
  "capabilities": {
    "edit_own_profile": true,
    "manage_options": true,
    "edit_archive_poc": false,
    "edit_posts": true,
    "moderate_forums": true
  },
  "cache": { "etag": "W/\"...\"", "max_age": 30 }
}
```

`tier_unavailable: true` appears only when poller call fails.

## Open items for coordinator review

1. **Capability map pass-through scope.** Currently merge poller-supplied
   `edit_posts` + `moderate_forums` on top of §2's named caps. If §2
   should be strict-narrow, clip those — one-line change in
   `Whoami::capabilitiesFor()`.
2. **Stale-cache fallback semantics.** If the poller goes down mid-day,
   `/whoami` will start emitting `tier_unavailable: true` on cache miss
   for users whose 30s TTL has expired. Want last-known-good (stale)
   instead? Tradeoff: cleaner UX (no flicker), but a permanently-broken
   poller would mask itself. Current: fail visibly.
3. **Anon shape minimalism.** Returns only `{authenticated: false,
   tier: "public"}` — no `user_uuid`, no slug. If archive-poc wants any
   anon field (e.g., a session ID for tracking), happy to add.

## Cross-lane queue (locked 2026-05-29)

Multiple coordination docs landed this session; ordering settled:

**Cutover-critical (priority queue):**
1. **Shim-replacement design** (`docs/design-shim-replacement.md`) — written,
   awaiting ratification by coordinator + lg-shell + archive-poc + bb-mirror.
   Do NOT start the build until §10 open-questions get review-pass.
2. **Slice-4 migration** — runs the slim `migrate-from-xprofile.php` on prod
   plus the new carryover from the block-system spec: add
   `users.location_address` column + backfill from xprofile field 96 alongside
   the location_city/region snapshot. BATCH-06 #62-63 will confirm the live
   field ID.
3. **Social backfill** — waiting on BATCH-06 paste-back from live (xprofile #56-59
   + ACF author socials #60-61 + address field #62-63). Will land as a sibling
   migration to `migrate-from-xprofile.php`. **Locked: write kind+url only,
   skip per-row visibility** (block-level pmp wins; per-row vis would be ignored
   at render). Precedence: editor edit > xprofile > ACF author > nothing.
4. **Linktree precursor** — add `linktree` to `Profile::SOCIAL_KINDS` + schema
   check constraint. One-line precursor migration so the social backfill has
   a valid target. Trivial; can ship anytime.

**Post-cutover (queued, NOT building):**
5. **Profile 2.0 block system** (`docs/plan-profile-block-system.md` +
   `docs/spec-block-identity-location.md`). Two pilot blocks (`identity`,
   `location`) establish the JSON↔relational↔pmp↔render↔LLM-draft pattern.
   Schema adds: `users.at_a_glance`, `users.location_exact_visibility`,
   `practices.type`. (`users.location_address` rides slice-4 per #2 above.)
   pmp defaults LOCKED: identity=public; location-approx=member; location-exact=private;
   contact=storefront/practice-only, not personal header.
   - **Note durably:** location precision reverses slice 2.75 INTENTIONALLY
     (visibility × specificity beats either alone for safety-sensitive addresses;
     coarse coords drive the geo facet, exact resolves only for permitted
     viewers). Don't re-litigate.

**Recently fixed:**
- Shim regression (10:55 UTC clobber) — root-caused to a sibling archive-poc
  session that manually reverted my shim during perf-debug and didn't restore.
  Both deployed files restored from source. Cross-lane discipline note flagged
  to archive-poc. Both restores re-verified end-to-end.

## What's still owed for live cutover

Coordination sequence (post-step-1):

- [x] Step 1: `/whoami` ships on dev ← DONE
- [ ] Step 2: archive-poc switches from cookie-only to `/whoami`-backed
- [ ] Step 3: shared header partial across surfaces
- [ ] Step 4: profile-app cutover (run migration on prod, BB hijack, etc)
- [ ] Step 5: BB-mirror first read
- [ ] Step 6+: post-cutover BB cleanup, poller role-shape changes

Outstanding from 2.75 (still valid):

- [ ] Triage review with Ian (`/tmp/triage.tsv`)
- [ ] Test-data residue wipe on real prod accounts before re-running
      the migration on prod
- [ ] Hand-jigger the 6 unresolved locations (342, 880, 889, 1076,
      1163, 1347)
- [ ] GeoLite2 + nginx rate-limit deploy
- [ ] Re-run `backfill-avatars.php` on prod after cutover

## Next-session opening move

1. Read this file.
2. If coordinator has acknowledged step 1 complete and pinged archive-poc
   to start step 2 — wait for archive-poc shape-clean signal, then move
   to step 3 (shared header partial).
3. If unclear: check `/home/ubuntu/projects/docs/` for any new briefing
   files or marking-order docs.
4. Slice 4 (live cutover) is gated on steps 2 + 3 — do NOT start until
   archive-poc has switched AND the shared header is in place.

## Pointers

- Code: `/home/ubuntu/projects/profile-app/`
- Coordination doc: `/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md`
- Profile-app briefing: `/home/ubuntu/projects/docs/briefing-profile-app.md`
- Shared-postgres FYI: `/home/ubuntu/projects/docs/briefing-profile-app-fyi-shared-pg.md`
- Rotated handoffs: `handoffs/2026-05-{25,26,27}-*.md`
- CUTOVER-CHECKLIST: `CUTOVER-CHECKLIST.md`
- Walk transcript (slice 3.5 green): `/var/www/dev/mockups/walks/20260528T165628Z/`
- nginx snippet: `/etc/nginx/snippets/strangler-profile-app.conf`
  (backup: `.bak-pre-whoami` from this session)

## WP-session auth bridge — shipped 2026-05-28

**profile-app → coordinator:** WP-session auth bridge live

**profile-app → archive-poc:** looth_id bridge live — WP-logged-in users now
get `authenticated: true` from `/whoami`. Re-test your gating flow.

### What was built

Two-part change to close the gap between WP session and profile-app identity:

**`profile-whoami-shim.php` (mu-plugin):**
- `get_current_user_id()` first (works when WP has a valid nonce session)
- Falls back to `wp_validate_auth_cookie('', 'logged_in')` — reads session
  cookie directly, safe for GET with no side-effects, no nonce required
- When either returns a non-zero user ID: adds `X-LG-WP-User-Id` +
  `X-LG-Internal-Auth` (shared secret from `/etc/lg-internal-secret`)
- Cookie forwarding retained for `looth_id` JWT path

**`api/v0/whoami.php`:**
- Checks for `X-LG-WP-User-Id` + valid `X-LG-Internal-Auth` before JWT path
- If both present and secret validates: calls `Whoami::buildForWpUserId()`
- Falls through to `Whoami::resolve()` (JWT) otherwise

**Verified:** `/wp-json/looth/v1/whoami` with WP admin cookie returns
`authenticated: true` with full profile-app payload including tier + capabilities.

**Files changed:** `mu-plugins/profile-whoami-shim.php`, `api/v0/whoami.php`
