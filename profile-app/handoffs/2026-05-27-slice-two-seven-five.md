# profile-app — Session Handoff (2026-05-27, slice 2.75)

> Cutover-prep + debt-paydown slice. One user-facing change (location editor
> rework). Everything else is plumbing for slice 3's atomic xprofile →
> profile-app migration.
>
> Prior handoff: `handoffs/2026-05-26-slice-two-five.md`

## What surprised me (the 5-liner)

1. **There is no locally-hosted Nominatim.** The slice 2.75 prompt assumed
   one ("we already host one for slice-2.5 geocoding"), but the box just
   uses the public `nominatim.openstreetmap.org` at 1 rps. The
   `me-location-search` proxy still works against the public host — a
   typist's 250ms debounce keystrokes mostly stay under the rate-limit —
   but at scale we'd want to stand up our own or switch back to Google
   Places. Documented in CUTOVER-CHECKLIST.
2. **`location_grant_public/members/friends` columns are still in the
   schema** (NOT NULL, DEFAULT 'city'). The new model doesn't read them
   anymore, but the prompt only said drop `location_precision`, so the
   grants are sitting there as harmless residue. A follow-up slice can
   drop them once we're confident nothing else reads them.
3. **`read … < <(wp eval …)` returns 1 if the wp output lacks a trailing
   newline.** Cost a debugging round on `walk-onboarding.sh`. PHP's `echo`
   doesn't add `\n`, so the workaround is appending `PHP_EOL` to the last
   echo. Added a `|| true` guard for safety.
4. **`wp_generate_auth_cookie()` returns empty string for a wp_user_id
   that doesn't exist.** Caught it the hard way during cold-walk debugging:
   the first run had already deleted the user via cleanup, then a manual
   test for the same id returned empty cookies silently. Always check the
   user exists before debugging cookie minting.
5. **The slice-2.5 editor's "freeform text + geocode-on-save" flow was
   architecturally the bug, not the implementation.** Replacing it with a
   Nominatim picker that emits `{text, lat, lng, components}` atomically
   collapses the "text says Portland, coords say NJ" drift class into "the
   picker is the source of truth, period." Same lesson as slice 2.5's
   `_render_public.php` split.

## What this slice shipped

### DB schema (`sql/2026-05-27-slice-275.sql`)

- `users.location_visibility varchar(16) NOT NULL DEFAULT 'members'` —
  the new visibility gate (public | members | private).
- `users.archived_at timestamptz NULL` — soft-archive marker. Directory /
  map / typeahead all filter on it now.
- `users.legacy_xprofile jsonb NOT NULL DEFAULT '{}'::jsonb` — lossless
  dump bucket for un-mapped BB xprofile fields at cutover.
- Dropped `users.location_precision` — privacy model is now visibility-
  gated, not precision-shaved.

### Visibility-gated location API

- `Profile::canSeeLocation($viewerUserId, $subjectUserId, $visibility)` —
  the gate.
- `Profile::renderLocation($loc, $canSee)` no longer rounds coords. Emits
  stored values verbatim if the viewer is permitted, else `{visibility,
  hidden: true}`.
- `Profile::renderForViewer` now takes optional `(int $viewerUserId, int
  $subjectUserId)` so it can drive the gate. All callsites updated:
  `web/u.php`, `api/v0/user.php`, `api/v0/me-preview.php`.
- `api/v0/directory-members.php` honors `?page_size=N` (cap 200), filters
  `archived_at IS NULL`, and nulls location fields per-row based on
  viewer/subject visibility.

### Editor rework — Nominatim picker + visibility radio

- `api/v0/me-location.php` — rewritten. Accepts `{nominatim:{...}}`,
  `{text_only:"..."}`, or `{location_visibility:"..."}` (single-field
  autosave). All three may appear together. Picker is source of truth;
  no post-hoc geocoding.
- `api/v0/me-location-search.php` — NEW. Proxies Nominatim with a
  User-Agent and an IP-biased `viewbox` via GeoLite2. Graceful fallback
  if the GeoLite2 DB is missing (logged as `geo_status: missing`).
- `src/GeoIP.php` — NEW. Thin wrapper around libmaxminddb. Computes a
  ~500km viewbox around the caller's IP.
- `web/_render.php` — modal rewritten: location text input + dropdown
  `#loc-picker` + zero-result `#loc-empty-state` with "Save anyway as
  text only" escape hatch + visibility radio with three options. No Save
  button — picker + radio autosave on change.
- `web/edit.js` — old Google Places autocomplete IIFE replaced with a
  Nominatim picker IIFE (debounced 250ms, keyboard nav, mousedown to
  pick, autosaves the picked row). Visibility radio listeners autosave
  on `change`.
- `web/edit.css` — picker dropdown + visibility row styles appended.
- Google Maps SDK script tag removed from the editor.

Routing: nginx `/etc/nginx/sites-available/dev.loothgroup.com.conf` got a
new rewrite for `/profile-api/v0/me/location/search` → `me-location-search.php`,
and the file added to the authed regex. Reloaded; `nginx -t` clean.

### Backfill / reconcile scripts

- `bin/snapshot-location-from-bb.php` — full snapshot rewrite. Reads BB
  field-96 text + `geocode_96` lat/lng, reverse-geocodes via Nominatim to
  fill city/region/country/postcode. Idempotent. Replaces both the partial
  prior version and `regeocode-from-bb.php`.
- `bin/backfill-avatars.php` — populates `users.avatar_url` from BB
  uploaded avatars (`/wp-content/uploads/avatars/<id>/*-bpfull.*`) or
  Gravatar with `?d=` fallback to the Looth default.
- `bin/reconcile-bridge.php` — walks `wp_users`, ensures a
  profile-app `users` + `wp_user_bridge` row exists for every WP user
  including empty-email ghosts (synthetic `looth-<id>@invalid` email
  satisfies NOT NULL + UNIQUE).

### Tooling

- `bin/triage-accounts.php` — read-only TSV report: dup-email, dup-name,
  ghost (no email / no xprofile), never_login_2y. Suggests
  `would_archive` per row.
- `bin/walk-onboarding.sh` — scripted CDP cold-walk. Creates a fresh WP
  user, waits for the webhook, mints JWT, drives /profile/edit, takes
  screenshots, hits /u/<slug> anon and /directory/members. Designed as
  the slice-end ritual. **Currently partially working** — see the walk
  notes below.
- `bin/migrate-from-xprofile.php` — the big cutover script. Walks every
  WP user, ports xprofile fields per the locked field map, dumps
  un-mapped fields to `legacy_xprofile`. Dry-run by default, `--commit`
  required. Dedup logic for slug collisions, socials, credentials.
  **Not run** — that's slice 3.

### Misc

- `web/edit.js` — credential typeahead's `<2 char silent skip` reduced
  to `q === ''` skip. The prompt called out `api/v0/typeahead.php` but
  no such endpoint exists; the only typeahead in the app is this
  client-side credential picker against `/catalogs/credentials`.
- `api/v0/schema.php` — drops `location_precision_options`, adds
  `location_visibility_options`. Location header_field rewritten to
  describe the new picker shape.
- `bin/geocode.php` (slice 2.5) — patched to not write the dropped
  `location_precision` column.

## Validation

```
$ curl -sk "…/directory/members?page_size=2" | jq '.items[0].location'
{ "visibility": "members", "hidden": true }       # anon — location gated ✅

$ curl -sk "…/directory/members?page_size=500" | jq '.page_size'
200                                                # cap ✅

$ sudo -u profile-app psql -d profile_app -c "SELECT location_visibility, COUNT(*) FROM users GROUP BY 1"
 location_visibility | count
---------------------+-------
 members             |  1698                       # backfill default applied ✅

$ sudo -u profile-app psql -d profile_app -c '\d users' | grep -E 'location_visibility|archived_at|legacy_xprofile'
 location_visibility    | character varying(16)    | not null | 'members'::character varying
 archived_at            | timestamp with time zone |          |
 legacy_xprofile        | jsonb                    | not null | '{}'::jsonb     ✅
```

## walk-onboarding.sh notes

Ran twice; the script reaches step 9 successfully on the
`page_size=500 → cap 200` check but bails at step 6
("toggle location visibility → private") because the DB still reads
`members` after the radio dispatch. Hypothesis: the modal needs to be
visible in the DOM tree (the IIFE wires listeners at page load — fine —
but Chrome's `Network.setCookie` + `Page.navigate` flow likely settles
into an interstitial state if the `looth_id` JWT for the fresh subscriber
isn't accepted by `/profile/edit`'s auth check). The 4 captured screenshots
are all 22KB (identical), suggesting the editor never actually loaded.

Action for next session:
- Set the subscriber's WP role to one that BB allows on `/profile/edit`,
  or stub the issue endpoint to mint a JWT directly for the wp_user_id
  without going through the WP login layer.
- Move the screenshot-comparison assertion to a `cmp` of the first vs
  later frames so identical-bytes ≠ pass.

## Deliverables checklist

| Item | Path | Status |
|---|---|---|
| Schema migration | `sql/2026-05-27-slice-275.sql` | applied to dev ✅ |
| Visibility-gated API | `src/Profile.php`, `api/v0/*` | applied ✅ |
| Editor picker | `web/_render.php`, `web/edit.js`, `web/edit.css` | applied ✅ |
| Nominatim proxy | `api/v0/me-location-search.php`, `src/GeoIP.php` | applied + routed ✅ |
| Snapshot rebackfill | `bin/snapshot-location-from-bb.php` | written, not run |
| Avatar backfill | `bin/backfill-avatars.php` | written, not run |
| Bridge reconciler | `bin/reconcile-bridge.php` | written, not run |
| Page-size honoring | `api/v0/directory-members.php` | applied ✅ |
| Triage report | `bin/triage-accounts.php` | written |
| Cold-walk | `bin/walk-onboarding.sh` | written, partial (see notes) |
| Migration script | `bin/migrate-from-xprofile.php` | written (slice-3 to run) |
| Cutover doc | `CUTOVER-CHECKLIST.md` | written ✅ |
| Deploy bits | `deploy/geoipupdate.{conf,cron}.example`, `deploy/nginx-rate-limit.example.conf` | written |

## Slice 3 setup

- Practices CPT + `/p/<slug>` route
- Run `bin/snapshot-location-from-bb.php` for component backfill
- Run `bin/backfill-avatars.php`
- Run `bin/reconcile-bridge.php`
- Review `bin/triage-accounts.php` output with Ian, archive picks by hand
- Hand-jigger the 6 unresolved locations
- Run `bin/migrate-from-xprofile.php --commit`
- Smoke `bin/walk-onboarding.sh` post-cutover
- Live deploy (still deferred until production keypair + cookie-domain
  switch)
- Drop the residual `location_grant_*` columns once confirmed unused
