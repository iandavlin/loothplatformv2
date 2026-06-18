# profile-app — Session Handoff (2026-05-25, slice 1.5)

> Prior handoffs in `handoffs/`:
> `2026-05-25-slice-zero.md`, `2026-05-25-slice-one.md`.

## What slice 1.5 ships

Same data plane as slice one — totally new UX skin:

- **Live-look + modal editor.** No more form. The page renders as the published
  profile would; pencils on hover open focused modals that save and re-render
  in place. Pencils + grips disappear in non-Me viewer roles.
- **Header restructured** with per-field pencils: name, location, socials,
  avatar (avatar is "coming later" placeholder).
- **Inactive sections.** About / Credentials / Practices all render as dashed-
  border "click to add" cards until they have real content. About is the only
  one functionally activatable in slice 1.5; the other two open "lands in slice
  X" modals.
- **Drag-to-reorder** via native HTML5 DnD; new order PATCHes to the server,
  rendered from `profiles.section_order` on next load.
- **Segmented viewer-role toggle** (Me / Member / Public). State doesn't
  persist — reload returns to Me.
- **Explicit claim flow.** No more silent auto-claim on `/me` GET. First-time
  visitors hit a "Start your profile" interstitial that POSTs `/me/claim` and
  reloads with `?just_claimed=1` (which auto-opens the About modal).
- **Public `/u/<slug>`** read-only SSR (same renderer in view mode, no
  pencils/grips/toggle). `/u/<slug>/edit` 302s to /profile/edit for self,
  403 for others.
- **`GET /profile-api/v0/schema`** — public, version-stamped JSON describing
  the editor's data shape. Designed for import/export round-trips: includes a
  `payload_shape` reference, per-social-kind `validation_by_kind` patterns,
  and an `endpoints` discoverability map. Foundation for the future LLM-fill
  skill-pack and a future `/me/export` + `/me/import` pair.
- **WP admin bar "My Profile"** item added to the existing `profile-auth`
  mu-plugin (priority 80, visible to all logged-in users).

Slice zero's identity backbone + slice one's auth (RS256 JWT in `looth_id`
cookie) are unchanged.

## URL surface (dev)

| Path | Method | Auth | Notes |
|---|---|---|---|
| `/profile/edit`                       | GET   | JWT (or interstitial)         | live-look editor; renders claim interstitial if no `profiles` row |
| `/u/<slug>`                           | GET   | optional JWT                   | public read-only; 302s to /profile/edit if slug == self |
| `/u/<slug>/edit`                      | GET   | JWT required                   | 302 self → /profile/edit; 403 otherwise |
| `/profile-api/v0/me`                  | GET   | JWT                            | full profile, no auto-claim |
| `/profile-api/v0/me/claim`            | POST  | JWT                            | `{via?: "menu"|"banner"|"public_view"|"direct"|"import"}` — idempotent |
| `/profile-api/v0/me/name`             | PATCH | JWT                            | `{display_name}` |
| `/profile-api/v0/me/about`            | PATCH | JWT                            | `{text?, visibility?}` |
| `/profile-api/v0/me/location`         | PUT   | JWT                            | `{place_result, precision_grants}` |
| `/profile-api/v0/me/socials`          | PUT   | JWT                            | `{items:[{kind,value,sort_order}]}` |
| `/profile-api/v0/me/section-order`    | PATCH | JWT                            | `{order: [<key>, …]}` — invalid keys dropped silently |
| `/profile-api/v0/me/preview?as=X`     | GET   | JWT                            | what viewer-role X would see |
| `/profile-api/v0/user/<uuid>`         | GET   | JWT-optional                   | viewer-role aware (me/member/public) |
| `/profile-api/v0/schema`              | GET   | none (cookie-gate still applies on dev) | `Cache-Control: public, max-age=60` |
| `/profile-api/v0/hooks/user-created`  | POST  | X-Hook-Secret                  | loopback only |
| `/wp-json/looth/auth/refresh`         | POST  | WP session                     | re-mints `looth_id` cookie |

## Schema changes (sql/0003_section_order_and_claim.sql)

```sql
ALTER TABLE profiles
    ADD COLUMN section_order TEXT[] NOT NULL DEFAULT '{}',
    ADD COLUMN claimed_via   TEXT;
```

Idempotent (uses `IF NOT EXISTS`). `claimed_via` is nullable for the four users
who claimed pre-slice-1.5; new claims record their entry point.

## Key files added or changed in slice 1.5

| Path | Role |
|---|---|
| `sql/0003_section_order_and_claim.sql`     | section_order text[], claimed_via |
| `src/Profile.php`                           | `claim()`, `hasClaimed()`, `setSectionOrder()`, `knownSectionKeys()`; `loadFull()` now returns claimed/claimed_via/section_order; About no longer auto-seeded |
| `api/v0/me.php`                             | refactored: no auto-claim |
| `api/v0/me-claim.php`                       | NEW — explicit claim |
| `api/v0/me-section-order.php`               | NEW — PATCH order |
| `api/v0/me-name.php`                        | NEW — PATCH display_name |
| `api/v0/schema.php`                         | NEW — public schema doc |
| `web/_render.php`                           | NEW — shared SSR (editor + public-view + interstitials) |
| `web/edit.php`                              | thin entry: routes to interstitial / claim card / editor |
| `web/u.php`                                 | NEW — `/u/<slug>` public read-only |
| `web/u-edit.php`                            | NEW — `/u/<slug>/edit` alias |
| `web/edit.css`                              | rewritten from new mockup |
| `web/edit.js`                               | rewritten: modal pattern, drag reorder, segmented role swap |
| `deploy/profile-auth.mu-plugin.php`         | added admin_bar_menu hook (priority 80) |
| `/var/www/dev/wp-content/mu-plugins/profile-auth.php` | re-installed with admin bar item |

## Validation matrix (all passed)

| Check | Result |
|---|---|
| Editor loads as live-look, no form widgets in initial state | ✅ |
| Pencils + grips have opacity 0 by default, opacity 1 on section hover | ✅ |
| Clicking each header pencil opens the right modal scoped to that block | ✅ |
| Click About pencil → modal opens; save persists; section transitions active | ✅ |
| Never-claimed user hits interstitial; `/me/claim` POST inserts profiles row | ✅ |
| `?just_claimed=1` auto-opens About modal after fresh claim | ✅ |
| Drag a section to new position → PATCH `/me/section-order` succeeds | ✅ (endpoint verified end-to-end; native HTML5 DnD not exercised in headless CDP) |
| Reload reflects persisted order | ✅ — Ian's `section_order=[practices,about,credentials]` survives |
| Switch role to Public → About hidden, location coarsens to city, pencils gone | ✅ |
| `/u/4` anon → mode-view, 0 pencils/grips, no role bar, name+loc visible | ✅ |
| `/u/4` as Ian self → 302 /profile/edit | ✅ |
| `/u/9999` → 404 | ✅ |
| `/u/4/edit` self → 302; `/u/5/edit` as Ian → 403 | ✅ |
| `GET /schema` returns `version:1` + `payload_shape` + 3 sections + `endpoints` + per-social-kind validation | ✅ |
| "My Profile" admin bar item visible on real wp-admin page, links to `/profile/edit` | ✅ (CDP browser test on `/wp-admin/profile.php`) |
| Never-claimed user full E2E: interstitial → click → POST /me/claim → reload `?just_claimed=1` → About modal auto-opens | ✅ (CDP browser test as wp_id=4 / gerry; `profiles.claimed_via='direct'` after) |

Screenshot of live editor in Me view: `/var/www/dev/mockups/profile-edit-15-me.png`.

## What surprised me (the 5-liner)

1. **`snippets/fastcgi-php.conf` has a `try_files $fastcgi_script_name =404`**
   that 404s clean URLs like `/u/4` *before* the request reaches PHP — the
   `/profile/edit` flow's `alias` + `rewrite … last` masked this in slice one,
   so the trap stayed hidden. Slice 1.5 fix: include `fastcgi.conf` directly
   (no try_files) for clean-URL endpoints that map to a fixed `SCRIPT_FILENAME`.
   Worth a future shared snippet so the next service doesn't relearn this.
2. **Native HTML5 drag-and-drop is desktop-only.** Mobile/touch fires no `drag*`
   events at all — the grip handle does nothing on a phone. Slice 1.5 ships
   reorder marked desktop-only; slice two should either pull in SortableJS
   (touch synth) or swap the grip for an explicit up/down button affordance.
3. **Claim flow is theoretically racy but practically safe.** Two concurrent
   `POST /me/claim` calls land on an `INSERT … ON CONFLICT DO NOTHING`; first
   wins, second returns `{claimed: false, existing: true}` — idempotent and
   single-source-of-truth. The trickier edge is "user clicks claim, hits the
   refresh button mid-POST, briefly sees the interstitial again, clicks
   twice." The button is disabled on first click (`btn.disabled = true`) so
   double-click within a single request is blocked, but cross-tab is not.
   Acceptable — the worst case is one extra no-op POST.
4. **"Active section" semantics are split between two layers** and I picked
   different rules for each: the editor treats About as inactive when its
   `data.text` is empty (UI hint), while `/schema` consumers would treat any
   `profile_sections` row as activation. The slice-one auto-seeded empty About
   rows for Ian/Gerry/etc. exist in DB but render as inactive cards — saving
   content activates the UI without a schema change. Worth nailing canonical
   answer before the directory ships and starts iterating over "active" users.
5. **Schema endpoint had a shape choice with downstream cost.** Slice 1.5
   uses `kind: 'placeholder'` + `available_in: 'slice-X'` for not-yet-built
   sections so skill-pack consumers can filter. The bigger choice was making
   the schema **import/export-shaped from day one**: stable string slugs as
   join keys, flat payloads with no FK references, an explicit `payload_shape`
   reference at the root, per-social-kind `validation_by_kind` patterns that
   work in both PHP and JS regex engines. That last bit means socials
   validation isn't duplicated — `/schema` is the spec, `me-socials.php` and
   a future client-side check both consult it.

## Quick-start for next session

```bash
# Sanity
curl ifconfig.me                                                # → 50.19.198.38
sudo -u profile-app psql -d profile_app -c '\dt'                # → 6 tables

# Schema check
TOK='qShCjBdCVXLie7wcQddsprkYj4SuaXu7UJeYAHHG'
curl -sk -H "Host: dev.loothgroup.com" -H "Cookie: loothdev_auth=$TOK" \
  https://127.0.0.1/profile-api/v0/schema | jq .

# JWT for Ian + load editor in browser via chrome-dev-login skill
JWT=$(sudo -u looth-dev wp --path=/var/www/dev eval \
  'echo looth_auth_mint_jwt(get_user_by("id", 1));' | tail -1)
# (set cookies via CDP, navigate to /profile/edit)
```

## What's ahead (slice two seeds)

- **Catalogs / taxonomy:** instruments, skills, credentials, scenes. Drives
  the credentials editor (typeahead → catalog entry; free-text fallback) and
  the directory pages.
- **Skill-pack zip endpoint** that bundles `/schema` + a per-user `/me` snapshot,
  meant for LLM-driven profile fill flows.
- **Directory page** (`/directory` or similar) reading the new taxonomy.
- **Avatar upload** — currently the avatar pencil opens a "coming later" modal.
- **Touch-reorder** — replace native HTML5 DnD with SortableJS or button
  affordance for mobile.
- **Header highlights picker** — pin selected catalog tags into the header.
- **Friend graph** in Postgres → real `friend` viewer role.
- **`looth_uuid` cached in WP usermeta** to bulletproof JWT `sub` against
  email changes (carried forward from slice one).
- **Shared `/opt/looth/identity.php`** to kill the namespace-constant
  duplication between profile-app and the mu-plugin (carried forward).
- **Live deploy** (still deferred since slice zero).

## What slice 1.5 deliberately did NOT do

- No catalogs / typeahead
- No avatar upload
- No "deactivate section" UI
- No touch-friendly reorder
- No live deploy
- No header highlights picker
- No friend graph
- No skill-pack zip (just the `/schema` foundation)
