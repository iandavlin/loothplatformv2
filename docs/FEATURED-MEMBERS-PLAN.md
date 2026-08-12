# Featured members (backlog item 18) — phase 1: mocks + what the build actually is

Lane: `featured-members`. Ian asked 2026-08-11 ("fairly soon type of thing").
**Phase 1 is mocks only. Nothing below is built.** This note records what the
lane found while drawing them, because most of the surprises are load-bearing
for the build and are not discoverable from the backlog line.

Mocks (behind the dev gate): `/footer-mockups/featured-members/`
Source (in-repo): `footer-mockups/featured-members/`, symlinked into
`~/projects/footer-mockups/featured-members` so nothing is written to `/var/www/dev`.

---

## 1. The headline finding: the front-page band already exists

`archive-poc/web/index.php:898` already renders a **`row--featured-member`**
band, styled at `archive-poc/web/archive.css:3237` (`body.view-discover .lg-fm*`).
It is fed by the `LG_FEATURED_MEMBER` constant, defined at `index.php:290` from
`web/defaults.php:42` and overridable via the `config.json` overlay.

Today it is **hand-typed**: `enabled => true`, name `Dan Erlewine`, with a
role, location, bio, avatar URL and CTA — none of it linked to any member
account. It has been on the front page since June.

**So item 18 is not "build a featured-member card".** The card exists. The work is:

1. give it a **source** — members who consented;
2. give Ian a **chooser** — the admin dash;
3. keep a **history**.

The `_config` webhook (`archive-poc/api/v0/_config.php:86,129-138`) *already*
accepts `featured_member` as a flat-map key, so the write path from a WP admin
page to the front page exists too. What does not exist is any link to a member.

## 2. Where each piece goes

### Tickbox — profile-app, exactly the `discussion_visibility` shape

There is a precedent to copy line for line, added for Ian's 6/7 ruling:

| Piece | Existing precedent |
|---|---|
| Column | `users.discussion_visibility` (`profile-app/sql/2026-06-08-discussion-visibility.sql`) |
| Endpoint | `profile-app/api/v0/me-discussion-visibility.php` (GET/PUT, self only) |
| UI | the owner privacy slab in `profile-app/web/u.php:806-816` |
| JS | the optimistic toggle at `u.php:1478-1510` |

So: a `users.featured_opt_in boolean NOT NULL DEFAULT false` plus a consent
timestamp, a `me-featured-optin` endpoint, and a row in the slab. Variant A in
the mock is that row. **Default false is the whole point — never inferred**
(this is the `bell_follows_bb_subscriptions` consent-inference lesson, which
Ian closed OFF on 8/9, applied to a new surface).

### Admin dash — WP admin, beside the front-page dash Ian already uses

`ArchivePocDash` (`archive-poc/standalone/engine/src/ArchivePocDash.php`) is a
submenu under LG Layout v2 that edits the front-page config arrays and POSTs
them to the `_config` webhook. The featured-member dash belongs next to it,
same capability (`manage_options`), same webhook. Its `SECTIONS` schema does
**not** currently expose `featured_member` — that is the gap.

The dash needs profile-app data (the pool). WP cannot read profile-app's
Postgres directly, so the pool list should come from an admin-only profile-app
endpoint rather than a cross-DB join from WP.

### Front page — reading a real member

`archive-poc/web/sitemap.php:91-95` is the precedent: archive-poc opens
`pgsql:…dbname=profile_app` directly under a **column-scoped SELECT grant**
(`tools/cut/sitemap-grants.sql`, currently `slug, profile_visibility,
updated_at`). Auto-publish needs that grant widened to the card's columns.

Note archive-poc's own `person` table (`schema.pg.sql:71`) is only
`id, display_name, slug, avatar_url` — not enough for the card (no tagline, no
location, no bio), so the mirror is not a shortcut here.

## 3. Privacy interlocks the build must honour

These are not hypotheticals; each is an existing rule the card would otherwise break.

1. **`profile_visibility = 'private'` is owner-only** — no directory card, no map
   pin, no search hit (`profile-app/src/Visibility.php`). Featuring such a member
   would undo that in one click. The tickbox must be **unavailable** while private,
   and a member who goes private must drop out of the pool. Drawn in the mock.
2. **Location is not one flag.** The band already hides `where` from logged-out
   viewers (`index.php:906-908`), but a real member also has
   `location_public_precision` / `location_members_precision` (`private|state|city|street`).
   The card must honour those, not just "is this a member".
3. **The About section has its own visibility.** Measured on dev2: of the members
   who have one, some are `public` (Beau Hannam) and some are `members`
   (Karl Borum). **The card may only quote an About marked `public`**, or a
   members-only bio leaks to the open web. Otherwise: tagline, no bio.
4. **`at_a_glance` is the tagline** that maps to the card's `role` line, with
   `business_name` as the fallback. There is no "role" field.

## 4. Flag plan (house rule: member-facing ships OFF and the OFF state is gated)

- Tickbox is member-facing → tracked-config flag, **default false**, OFF state a
  proven byte-identical no-op, asserted per-state (absent / OFF / ON) off the
  served asset so flipping the default needs no gate edit.
- Dash is admin-only; front-page band already ships.
- **Gate number: to be ALLOCATED BY KEEPER — not minted here.** Roster was at 33
  when this lane opened; number from `main`, not from this branch.

## 5. Overlap with backlog item 19 (profiles arrive alive) — NOT built here

Item 19 wants new members to arrive with photo + city + what-they-do instead of
a bare shell. It shares this lane's surface twice over:

- **Same editor surface.** Item 19's prompt writes `avatar_url`,
  `location_*` and `at_a_glance` — the exact three fields this card reads.
- **Same dependency, opposite direction.** A featured card is only worth showing
  for a filled-in profile; item 19 is what makes profiles filled-in. 9 of the 12
  newest members are bare shells, so at launch the pool of *featurable* members is
  much smaller than the pool of *consenting* ones.

Recommendation: build 18 first (it is small and self-contained), but whoever takes
19 should reuse the consent row's placement in the privacy slab rather than
inventing a second settings surface.

## 6. What Ian has to rule on

Listed on the mock overview page; repeated here so the build has one source.

1. Tickbox placement: **A** (row in the privacy slab, recommended) or **B** (own card).
2. The consent wording.
3. Untick while featured → **off immediately** (recommended) or stays until swapped.
4. **One** featured member (as drawn, matching today's band) or a rotation.
5. Card reads the profile **live** (recommended) or a snapshot frozen at selection.
6. Whether the dash may invite/nudge members (drawn: **no** — no override at all).
7. The CTA label.

## 7. Verification done on the mocks themselves

The mocks are served from the dev2 docroot, which injects platform machinery
into every page. Two real defects were found and fixed while proving them:

- **Token inversion.** `app-settings.js` does not merely repaint the body under a
  dark profile — it rewrites the `--lg-*` brand tokens as inline style on `<html>`
  (`THEMES[dark].vars`). The mock's components are token-driven (they are the real
  product CSS), so the charcoal privacy slab rendered near-**white** and the white
  featured card rendered near-**black**. Pinning `body#lgfm`'s own background was
  not enough. Fix: re-declare the whole token set on the mock's own `<body>`.
  Proven by comparing 22 computed colours per page across light and dark
  (88 total, all identical) — and proven *red first* by removing the pin and
  watching the same assertions fail with caching disabled.
- **Injected chrome on the drawings.** `NAV#looth-tabbar` (z-index 2147481200)
  covered the bottom 55px of every mock at phone width, and the `#looth-pwa-banner`
  "Install Looth" prompt landed directly **on top of the Variant A tickbox row** —
  the one control Ian needs to see. Both now hidden on mock pages.
- **Phone overflow.** The dash table laid out at 654px inside a 390px viewport, so
  Chrome widened the layout viewport to 694 and the page side-scrolled. Rows now
  stack into cards below 760px. Every page: `scrollWidth == innerWidth` at 390.

Measurement notes for whoever picks this up: the shared Chrome profile caches
aggressively — every CDP measurement here needed `Network.setCacheDisabled`, and
two "the fix did not work" readings were stale cache. `Emulation.setDeviceMetricsOverride`
is dropped by navigation and **re-sending identical params is deduped**, so clear
the override then set it, and assert `window.innerWidth` before trusting a shot.
