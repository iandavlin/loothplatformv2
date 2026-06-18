# profile-app — Session Handoff (2026-05-27, slice 3 + coordination)

> Prior handoff: `handoffs/2026-05-27-slice-two-seven-five-followup.md`.
> This handoff covers slice 3 (practices + business_name editor + identity
> mirror) AND a major architecture shift that landed after the audit:
> profile-app cutover is now the unifying event for the whole strangler.
> Read the coordination context first; it changes what slice 3.5 is.

## Current state (2026-05-28)

- **Coordination chat (this session):** architecture locked, all asks
  answered, SESSION-HANDOFF + build order written. Idle, ready to relay
  cutover-step-1-complete signal when build lands.
- **Build chat (separate terminal session):** last visible status was
  "building /whoami; can now wire poller live" — ~90 min ago at relay
  time. Unclear if the original status-push (poller endpoint live + URL
  + header + secret) was relayed into that session. Coordinator's
  prior FYI included preemptive unblockers (Redis-vs-pg-unlogged choice,
  `setfacl -m u:profile-app:r /etc/lg-internal-secret` for the secret
  file readability gotcha).
- **Waiting on:** visible-on-dev signal that `/whoami` returns clean
  shape; then I notify coordinator (step 1 complete) and archive-poc
  + poller chats for cross-wire.
- **Not blocking the build:** poller's `GET /wp-json/looth-internal/v1/tier/{wp_user_id}`
  endpoint. Build can ship with `tier: "public"` + `tier_unavailable: true`
  stub and wire poller in via one-line change when both land.

## Strangler-coordination context (READ FIRST)

A coordination doc landed mid-session at
`/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md`. Briefing at
`/home/ubuntu/projects/docs/briefing-profile-app.md`. Both negotiated
and settled this session. Key bindings for profile-app:

1. **profile-app cutover is the unifying event** for the whole strangler
   (poller + archive-poc + lg-layout-v2 + BB-mirror + WP). Not just our
   slice 4. Templating fragmentation across surfaces pushed it
   all-or-nothing.

2. **profile-app hosts `/whoami`** at `/profile-api/v0/whoami` (WP shim
   at `/wp-json/looth/v1/whoami`). Born here, day one. Returns identity
   (from postgres) + tier (looked up from poller via a small GET
   endpoint they'll expose). 30s Redis cache with two purge triggers:
   Arbiter on role write, profile-app on `/me/*` self-edit.

3. **profile-app does NOT store tier locally.** `users.tier` column
   (slice 0 placeholder, never populated) gets dropped. Tier authority
   stays in the poller. `/whoami` is the consumer-facing surface.

4. **looth1 = `public` for gating, `authenticated` for identity features.**
   Resting state from the Arbiter; carries no paid entitlement.
   Identity-aware features check `authenticated`, never the role.

5. **Cutover sequence (settled):**
   1. `/whoami` ships in profile-app on dev
   2. archive-poc switches from cookie-only to `/whoami`-backed for
      sensitive gates (cookie stays as first-paint hint)
   3. Shared header partial across BB-replacement / lg-layout-v2 / archive-poc
   4. profile-app slice 4 cutover — run `bin/migrate-from-xprofile.php`
      on prod, BB hijack, mu-plugin flips
   5. BB-mirror first read (downstream of us)
   6. Post-cutover BB cleanup (delete vestigial groups, reskin remaining)
   7. Poller role-shape changes (last — every consumer reads through `/whoami`)

   We're at step 0 (slice 3 shipped, /whoami not yet built). Next session
   = step 1.

6. **Slice 3.5 scope changed** — was "PATs + OAuth + outbound webhooks."
   Now: `/whoami` + batch `/users?uuids=` + Redis cache + self-purge
   hook + drop `users.tier`. PATs/OAuth/outbound webhooks move to a later
   slice (still pre-cutover, but after coordination contract is live).

## What surprised me (top of file)

1. **The slim cutover migration left `users.business_name` populated but
   the editor had no field to view or change it.** Slice 2.75 added the
   column + public render but the editor modal was untouched, so for
   ~10 days the only way to fix a wrong business name on dev would have
   been a SQL update. Adding the field is two minutes; the surprise is
   that the audit-via-Chrome step in 2.75 caught the public-side gap but
   not the editor-side gap. Future slices: audit BOTH viewing surfaces.
2. **The placeholder `b-practices` modal in `_render.php` was already
   wired — pencil button, `data-modal="practices"`, knownSectionKeys
   entry — so swapping it for a real implementation was almost entirely
   additive.** Slice 1.5 paid the "build the empty section card" cost
   up-front. Worth remembering for the next entity-introducing slice.
3. **`Practice::forUser()` is called from the editor render AND the
   public `_render_public.php` render** — that means anonymous viewers
   trigger a DB query for every `/u/<slug>` load. Acceptable today
   (small N, single indexed join), worth flagging if `/u/` ever gets
   high anon traffic.
4. **Nginx route ordering caught nothing surprising this time** — `/p/`
   slotted in next to `/u/` with the identical regex+QUERY_STRING shape.
   The `/profile-api/v0/me/practices/<uuid>` rewrite needed the
   `[0-9a-fA-F-]{36}` constraint *before* the bare `/me/practices`
   rewrite to avoid the latter swallowing it.
5. **The orphan-practice case (last member leaves) is benign for now**
   — `/p/<slug>` still renders, staff roster is empty. The walk
   asserts this. Slice 3.5 may add a cleanup job; for now an orphan
   practice is just a public page nobody can edit.

## What this slice shipped

### DB

`sql/2026-05-28-slice-3-practices.sql` (applied to dev):

- `practices` table — uuid + slug + name + about + tagline + website +
  full location quartet + `location_visibility` (default 'public' —
  storefronts want to be findable) + `avatar_url` + `archived_at` +
  `created_by`. `touch_updated_at` trigger attached.
- `practice_members` — many-to-many join, primary key (practice_id,
  user_id), `role IN ('owner','staff')`, `sort_order`, `added_at`.
- `skill_catalog`: + `retail-sales` + `tool-maker` rows under a new
  `business` category.

### Source

- `src/Practice.php` — load by slug/uuid/id, member roster, forUser
  listing, uniqueSlug, renderForViewer (location-visibility gated),
  shape().
- `src/Profile.php` — `loadFull()` now returns `user_id` (needed by
  editor render to call `Practice::forUser`).
- `config.php` — autoload the new Practice source.

### Web

- `web/p.php` — public `/p/<slug>` entry; 404 on missing or archived.
- `web/_render_practice.php` — public renderer mirroring
  `_render_public.php` — header (avatar + name + tagline + location +
  website) + about + staff roster (links to `/u/<slug>`).
- `web/_render_public.php` — Practices section card appended to user
  profiles. Links to `/p/<slug>`. Loads via `Practice::forUser`.
- `web/_render.php` (editor) — Practices section body now lists
  attached practices with `/p/` links and role chip. b-name modal
  gained the `f-biz` business_name input. b-practices modal swapped
  from placeholder to real create/manage UI; new b-practice-edit
  modal for owner-only field editing.
- `web/edit.js` — `SAVE.name` now sends `business_name` and updates the
  `#disp-biz` element + BOOT. New practices block: render section
  card, render modal list, create handler, edit-modal hydrate + save,
  leave handler.
- `web/edit.css` — `.practice-list`, `.staff-list`, `.my-practices`,
  `.pr-item` minimal styling.

### API

- `api/v0/me-practices.php` — GET (list), POST (create + auto-owner
  membership), PATCH `?uuid=<uuid>` (owner-only update), DELETE
  `?uuid=<uuid>` (leave membership).
- `api/v0/practice.php` — public GET by uuid, returns the renderForViewer
  shape + members + `/p/` URL.
- `api/v0/me-name.php` — now accepts optional `business_name`. Mirrors
  `display_name` to `wp_users.display_name` via `wp_user_bridge`
  best-effort.

### Nginx

`/etc/nginx/sites-available/dev.loothgroup.com.conf` (sudo-edited):

- New `location ~ "^/p/([\w\-]+)/?$"` block (mirrors `/u/`).
- New rewrites: `/profile-api/v0/me/practices`,
  `/profile-api/v0/me/practices/<uuid>`,
  `/profile-api/v0/practice/<uuid>`.
- Authed regex extended with `me-practices`.
- Public/auth-aware regex extended with `practice`.

### Walk

`bin/walk-onboarding.sh` extended with two new sections:

- **9b.** PATCH `/me/name` with `business_name`. Asserts the DB column
  saved, `wp_users.display_name` mirrored, and `/u/<slug>` rendered
  `.biz` with the value.
- **10.** Practice cold-walk: create → /p/<slug> renders publicly +
  has staff roster + creator linked → /u/<slug> shows Practices card
  with link → PATCH tagline → reload /p/ confirms → DELETE leave →
  /u/<slug> no longer references → /p/<slug> persists empty.

Latest run: `/var/www/dev/mockups/walks/20260527T100532Z` (passes).

## Slice-3 architectural notes

### Slug uniqueness
`Practice::uniqueSlug()` slugifies the name and appends `-2`, `-3`, …
until free. The slug is set at create time and stays stable — PATCH
of `name` does NOT re-slug (URL is canonical, breaking it on rename
would be surprising). Owner can pick a new slug only by creating a
new practice.

### Role semantics
- `owner` — can edit fields. The creator is auto-promoted to owner on
  POST.
- `staff` — can leave (`DELETE`), cannot PATCH.
- "Join existing practice" is deferred to slice 3.5 (no public way for
  a non-owner to become a member yet — owners have to add them, but
  the add-member endpoint also isn't shipped). Today: owner = creator
  only.

### `business_name` and practices coexist
business_name is still kept as a free-text "primary affiliation" on
`users`. The original 2.75 plan to drop it once first-practice-create
prefills the name was *not* taken — Ian chose the additive path:
sole proprietors who don't want a separate page just keep
business_name; multi-shop folks add practices and (optionally) clear
business_name themselves. The editor surfaces both fields independently.

### Location visibility on practices
Default is `'public'` (vs `'members'` for users). Storefront entities
should be findable by anonymous visitors past the cookie gate; the
visibility toggle is still there for the rare case where someone wants
to gate it.

## What's NOT done (slice 3 explicit non-goals, verbatim from prompt)

- No live deploy (still pending — slice 4 problem).
- No app-ready APIs / webhooks (slice 3.5).
- No catalog editor UI for adding more rows.
- No "join existing practice" flow.
- No staff role permissions UI.
- No archived-practice browsing.

## What's still owed for live cutover

See `CUTOVER-CHECKLIST.md`. Outstanding from 2.75 (still valid):

- [ ] Triage review with Ian (`/tmp/triage.tsv`).
- [ ] Test-data residue wipe on real accounts before re-running the
      migration on prod.
- [ ] Hand-jigger the 6 unresolved locations (342, 880, 889, 1076,
      1163, 1347).
- [ ] GeoLite2 + nginx rate-limit deploy.
- [ ] Re-run `backfill-avatars.php` on prod after cutover.

Coordination-driven adds (must precede cutover):

- [ ] `/whoami` ships on dev (slice 3.5)
- [ ] `/users?uuids=` batch lookup ships alongside
- [ ] archive-poc switches to `/whoami`-backed gating
- [ ] Shared header partial across surfaces
- [ ] Poller exposes `GET /wp-json/looth-internal/v1/tier/{wp_user_id}`
      (asked of poller chat; not blocking /whoami shape work — we can
      ship with `tier: "public"` + `tier_unavailable: true` flag and
      wire in the poller call when it lands)

## Next-session opening move

1. Read this file. Read STRANGLER-COORDINATION.md §2 (the `/whoami`
   contract) and §4 (cutover sequence).
2. Slice 3.5 scope is **settled** (no decision needed): ship
   `/whoami` + `/users?uuids=` + Redis cache + self-purge + drop
   `users.tier`. The original "PATs + OAuth + outbound webhooks"
   moves to a later slice.
3. Build order:
   a. Drop `users.tier` migration (`sql/2026-05-2X-drop-tier.sql`)
   b. Confirm or stand up Redis on the box. If Redis adds infra
      friction, fall back to a postgres `unlogged` table with
      `(wp_user_id, payload, expires_at)` — same 30s TTL semantics,
      no new daemon.
   c. `Auth::whoami(WP|JWT)` resolver returning the contract shape
      (identity from postgres, tier as stub `public` until poller
      endpoint lands).
   d. `api/v0/whoami.php` + nginx rewrite
   e. `api/v0/users.php` (batch by `?uuids=csv`, cap 100, returns
      `[{uuid, slug, display_name, avatar_url}]`)
   f. WP shim mu-plugin: `/wp-json/looth/v1/whoami` proxies to
      profile-app endpoint, preserves caller's WP cookie
   g. Self-purge: wire `purgeWhoami($wpUserId)` into every `/me/*`
      handler that mutates display_name / slug / avatar_url /
      business_name
   h. Internal purge endpoint `POST /profile-api/v0/internal/purge-whoami`
      (shared-secret auth) for Arbiter to call on role change
   i. Walk script gains a `/whoami` smoke step
4. When `/whoami` returns clean shape on dev, ping coordinator chat —
   they fold it into the cutover sequence as step 1 complete and
   update archive-poc's switchover briefing.
5. Slice 4 (live cutover) is gated on steps 2-4 of the cutover
   sequence — do NOT start slice 4 until archive-poc has switched and
   shared header has landed.

## Pointers

- Code: `/home/ubuntu/projects/profile-app/`
- Prior slice prompts: `/home/ubuntu/projects/profile-app-slice-*.prompt.md`
- Rotated handoffs: `handoffs/2026-05-{25,26,27}-*.md`
- CUTOVER-CHECKLIST: `CUTOVER-CHECKLIST.md`
- Walk transcript (passed with slice-3 additions): `/var/www/dev/mockups/walks/20260527T100532Z/`
- **Coordination doc: `/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md`**
- **Profile-app briefing: `/home/ubuntu/projects/docs/briefing-profile-app.md`**
- **Shared-postgres FYI: `/home/ubuntu/projects/docs/briefing-profile-app-fyi-shared-pg.md`**
  (all 3 strangler surfaces on one postgres instance, separate schemas;
  no code changes for us — discipline note: keep cross-schema joins
  in the *consumer's* schema, not ours, to preserve one-way data flow)
- Audit screenshots (slice 3 verification):
  `/var/www/dev/mockups/audit/slice3-*.png`
