# Featured members (backlog item 18) — phase 1: mocks + what the build actually is

Lane: `featured-members`. Ian asked 2026-08-11 ("fairly soon type of thing").
**Phase 1 is mocks only. Nothing below is built.** This note records what the
lane found while drawing them, because most of the surprises are load-bearing
for the build and are not discoverable from the backlog line.

**Where things stand (2026-08-14).** Ian has ruled twice; four decisions remain,
all boxed on one page with pictures:

| Page | What it is |
|---|---|
| `decide.html` | **the one to send him** — four questions, each with its screenshot, a recommendation, and a settled-already block |
| `desktop.html` | every surface at 1280w, light AND dark |
| `index.html` | overview + the flow |
| `tickbox.html` / `dash.html` / `frontpage.html` / `completeness.html` | each surface in detail |

RULED: one featured member at a time (no rotation); the completeness percentage
replaces the one-line-blurb idea; consent explicit/opt-in with no admin override.
OPEN: tickbox A vs B · the eight items · nudge-vs-gate · meter here vs site-wide.

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
(`tools/cut/sitemap-grants.sql`, verified: `GRANT SELECT (slug,
profile_visibility, updated_at) ON public.users TO "archive-poc"`).
Auto-publish needs that grant widened to the card's columns.

**Two operational facts from that file that the build inherits, both of which
fail SILENTLY:**

- **Grants do not survive a PG restore** and must be re-applied every time — so
  after any `profile_app` restore the featured band would quietly stop
  resolving its member.
- **PG16 revokes public-schema USAGE from PUBLIC**, so the column grant *alone*
  returns **zero rows rather than an error**. Confirmed on the cut box 6/15:
  column grant only → 0 profiles; adding `CONNECT` + `USAGE` → 1,904.

Both mean a broken grant looks exactly like "nobody is featured". Whatever
resolves the card must distinguish *no member selected* from *cannot read the
member*, and say so in the dash — otherwise the failure is invisible.

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

## 3b. ⚠️ CORRECTION + the real numbers (measured on LIVE)

**An earlier version of this note said ~1,477 members could make a good featured
card. That was wrong by a factor of 22, and the error is instructive.**

The query counted `business_name` as "what they do". It is not: for **97%** of
members (1,427 of 1,465) `business_name` is a **suffix slice of their own
`display_name`** — "Brian Kuchta" → `business_name` "Kuchta", "Basil Smoke" →
"Smoke". It is a name-parsing artifact from profile creation, not something a
member wrote. Only **38** members have a `business_name` independent of their
display name.

Verified with `display_name LIKE '%'||business_name`. **The lesson is the
standing one — verify the thing, not the thing next to it.** A non-empty column
is not a filled-in field.

### What is actually there (live, 1,743 public profiles with a real slug)

| Signal | Members | Share |
|---|---|---|
| a photo (`avatar_version > 0`) | 1,711 | 98% |
| a location (city or region) | 715 | 41% |
| at least one social/link | 152 | 9% |
| **a real "what I do" line** (`at_a_glance`, or an independent `business_name`) | **81** | **5%** |
| an About section (any visibility) | 29 | 2% |
| skills / instruments / gallery / banner / genres | 12–27 each | 1–2% |
| **an About marked PUBLIC** (the only source of a card bio) | **7** | **0.4%** |

**Members who could make a complete card today (photo + what-they-do + where): 66.**
986 have a photo and nothing else; 645 have a photo and a location but nothing
saying what they do.

(Of those 7, **6** also clear the card bar — hence "6 cards could carry a bio".)

**Every number above is reproducible**: `tools/profile-completeness-report.sql`,
read-only, runs against live via `ssh live-ro`. It carries the `business_name`
trap in its header so the next person does not repeat the mistake.

Note the section system is barely used at all — only **29** members on live have
*any* `profile_sections` row. The structured tables (`profile_socials`,
`profile_skills`, `profile_instruments`, `profile_genres`) carry what little
else exists.

## 3c. The completeness percentage (Ian 8/12) — design

> "It should be more than that. Can we have a percent completed of the profile
> and have it be a percentage?"

Mocked at `/footer-mockups/featured-members/completeness.html`.

### The eight items, 12.5% each

photo · where you are · what you do (one line) · a bit about you · a link or two ·
skills/instruments · photos of your work · banner. Every one maps to a field that
already exists. Equal weighting until Ian says otherwise.

### The distribution that scoring produces (live, today)

| % | Members | Share |
|---|---|---|
| 0% | 25 | 1.4% |
| **12%** | **978** | **56.1%** |
| 25% | 553 | 31.7% |
| 37% | 135 | 7.7% |
| 50% | 23 | 1.3% |
| 62% | 10 | 0.6% |
| 75% | 8 | 0.5% |
| 87% | 6 | 0.3% |
| 100% | 5 | 0.3% |

**88% of the membership sits at 25% or below, and 29 members are above 60%.**
That is not a scoring artifact — it is what a photo-and-a-name membership looks
like when you measure it. It is also the whole argument for the meter.

### Two measures, deliberately separate

- **Profile %** — the member's own progress across all eight items. A nudge.
- **Card ready** — only the four things the front-page card renders (photo,
  name, what-you-do, where). This is what gates the dash's **Feature** button.

A member can be 62% and make a perfect card; a member at 37% can be missing
exactly the one line that matters. Conflating them would either block good cards
or ship empty ones.

### Recommendation: nudge, do not gate consent

Anyone may tick the box. **Feature** is only *offered* when the card would look
good. Blocking consent behind a percentage refuses someone who is trying to say
yes, and makes the pool look empty for reasons nobody can see. (A hard 50% floor
would today leave 52 members able to volunteer at all.)

### The percentage must never become a public score

It is shown to the member about themselves, and to admins in the dash. It is not
a badge, not a directory sort key, and not visible member-to-member. Nothing in
Ian's ask suggests otherwise, and it is much easier to keep that line now than to
walk it back.

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

**The completeness meter (§3c) tightens this overlap into a shared dependency.**
The meter's first three items — photo, where you are, what you do — are exactly
what item 19 wants to collect at sign-up. So:

- **One definition of "complete", not two.** Whatever function computes the
  percentage should be the same one item 19's sign-up prompt drives toward, or
  the two will drift and a member will be told different things in different
  places.
- **The meter measures; item 19 fills.** The numbers in §3b are item 19's
  business case, quantified: 978 members with a photo and nothing else, 56% of
  the membership. Featuring is limited by that, not by consent.
- Still **not built here**. Ian's 8/12 ruling explicitly kept them separate
  ("Does the meter go site-wide, or stay in this flow?" is question 3 on the
  completeness mock — if he says site-wide, the two jobs should merge and be
  re-chartered as one).

## 6. What Ian has to rule on

Listed on the mock overview page; repeated here so the build has one source.

1. Tickbox placement: **A** (row in the privacy slab, recommended) or **B** (own card).
2. The consent wording.
3. Untick while featured → **off immediately** (recommended) or stays until swapped.
4. ~~One featured member or a rotation~~ — **RULED 2026-08-12: ONE at a time.**
   No rotation, no running order; the dash selects exactly one.
5. Card reads the profile **live** (recommended) or a snapshot frozen at selection.
6. Whether the dash may invite/nudge members (drawn: **no** — no override at all).
7. The CTA label.
8. ~~Should the tickbox also ask for a one-line blurb~~ — **RULED 2026-08-12:
   go further, a profile-completeness percentage.** Designed in §3c; it needs
   three rulings of its own (the eight items, nudge-vs-gate, and whether the
   meter goes site-wide or stays in this flow).

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


---

## 8. What drawing it in DARK changed (2026-08-14)

Ian asked for desktop views in light and dark. The dark pass is not cosmetic —
it found three faults, all in **this proposal's own components**, none in the
surfaces that already ship:

| Component | Fault in dark | Why |
|---|---|---|
| consent card (Variant B) | body text invisible | `#4c4f47` on a `#1e2124` card |
| consent card, "in the pool" | stayed white | background hardcoded `#fffdf7` |
| completeness meter | text, track and ticks unreadable | fixed light greys throughout |

The existing surfaces were fine because they already carry dark handling —
`u.php:732` pins the privacy slab to `#22262a` so it does **not** flip white when
`--lg-charcoal` inverts to near-white, and `u.php:733-739` flip selected sage
chips to dark ink. The mock's `.tp-dark` scope reproduces both verbatim, so the
dark drawings are the real rendering rather than a guess.

**Rule for the build:** any new component here must be token-driven or carry its
own dark rule. A hardcoded light grey is a latent dark-mode defect, and it will
not show up in any light-only check.

**No dark admin dash**, and that is a fact rather than an omission: the dash is a
WP *admin* page and the member theme picker does not reach WP admin. WordPress's
own admin colour schemes are the answer if Ian wants one.

## 9. Two verification traps this lane paid for

1. **A colour check is blind to geometry.** Meter and distribution bars shipped as
   *empty tracks* for a whole revision — `<span>` fills inside a non-flex parent
   stay inline and silently ignore `width`/`height`. Every colour assertion was
   green. Now asserted in `tools/mock-theme-proof.py` (zero-width bars +
   horizontal overflow).
2. **A breakpoint gap hides between the widths you test.** `dash.html` stacked
   below 760px and fitted above ~830px, so it side-scrolled at **768px — iPad
   portrait** — while both 390 and 1280 were clean. Breakpoint raised to 900 and
   bracketed at 899/901. Test *between* your breakpoints, not just at the extremes.
