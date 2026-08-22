# Profile system — audit of what it ACTUALLY does today

**Status:** **COMPLETE except current phone-width screenshots** (the only item needing a
browser engine, queued with keeper). The privacy model is **PROVEN GREEN** against the
running system — `visibility-matrix.php` 67/67 (§3.6b), after repairing three
environment drifts that had left that gate unable to run at all.
**Written for:** the Membership Guide's PROFILE entry.
**Box:** dev2 (dev2 and live hold different data — every number here is dev2).
**Audited at:** branch `profile-audit`, off repo `6ef25e3`.

> Read this before writing a word of the guide. Every claim is either sourced to a
> file:line, measured against the store, or proven by the matrix — and where I have
> only read the code and not the screen, it says **NOT PROVEN**. A reading of the code
> is not a reading of the screen.

---

## 0. The one-paragraph shape of the thing

There is **no separate profile editor**. `/u/<slug>` is simultaneously the public
profile page, the member-facing profile page, and — when the owner is signed in and
looking at their own — the **inline editor**. `/profile/edit` exists only as a
redirect that bounces you to `/u/<your-slug>` (`web/edit.php:44-47`). The member never
navigates to an "edit mode"; the page they read is the page they edit. This single
fact reshapes the guide: there is no "click Edit" step to screenshot.

---

## 1. Routes and entry points

| URL | File | Who gets it | What it is |
|---|---|---|---|
| `/u/<slug>` | `profile-app/web/u.php` (2406 ln) | anyone (subject to §3) | profile page **and** owner's inline editor |
| `/u/<slug>?view=public\|member\|me` | `u.php:108`, `u.php:135` | owner only | **View-as preview** — owner re-renders their own page as another audience |
| `/u/<slug>?admin_edit=1` | `u.php:119` | admin only | admin edits someone else's profile; every save logged |
| `/profile/edit` | `web/edit.php` | signed-in member | **302 → `/u/<slug>`**. Never a destination. |
| `/u/<slug>/edit` | `web/u-edit.php` | owner → 302 `/profile/edit`; others → **403** | legacy edit URL, redirect-only |
| `/profile-media/<class>/<uuid>/<file>` | `web/media.php` | gated by `Visibility::fileVisible` | avatars/banners always servable; gallery/resume gated |

**Unclaimed / unauthenticated edge states** (all in `web/edit.php`):
- WP session but no `looth_id` → invisible hop through `/looth-auth/issue` and back.
- No session at all → `looth_render_login_interstitial()`.
- Signed in but profile never claimed → `looth_render_claim_interstitial()`.

These three are real screens a new member can hit and the guide should probably show at
least the claim interstitial. **NOT PROVEN:** I have not yet rendered them.

---

## 2. The editing gate — the single most guide-relevant behaviour

```php
u.php:127   $editing = ($isOwner && $role === 'me') || $adminEditing;
```

`$editing` controls whether the **editing apparatus** renders: the Sections picker
button (pre-A `u.php:748`; post-A the `[data-caddy-open]` openers), the caddy panel
itself, the in-place field affordances, the location precision dials, and the
**per-section** privacy chips. It
does *not* gate the master Profile-visibility chip — see the measurement below.

**Consequence the guide MUST state:** when an owner uses "View as → Public" or
"View as → Member", the editor *disappears*. Not greys out — is not emitted. To get
editing back they must click **"View as → Edit"**. A member who previews their profile
and then wonders where their edit controls went is hitting designed behaviour, and it is
exactly the kind of thing an instruction set exists to pre-empt.

> **The third position was called "Me" until 2026-08-22 (#195).** Ian: *"in the
> profile, I'd like the view as controls in the privacy area to read edit instead
> of me."* The other two positions name an AUDIENCE; "Me" looked like a third
> audience and was not one — it is the working view, the one where things can be
> changed, which is what the panel's own hint already said ("This IS your
> editor"). ⚠️ **The LABEL moved and the VALUE did not:** the URL is still
> `?view=me`, the predicate is still `$role === 'me'`, and every consumer keys on
> that string. Gate 97 asserts the pair per position for exactly this reason —
> anyone "tidying" the value to match the new word takes edit mode down with it.
> The same rename landed on `/p/` (the practice page) so one position does not
> wear two names, and on `/profile/edit`'s SSR editor, which is unreachable.

The **View-as strip itself** stays visible in all three modes, because it is gated on
`$isOwner || $adminEditing` (`u.php:728`), a weaker condition than `$editing`.

### MEASURED against the served HTML (2026-07-28, no engine needed)

> **These numbers are PRE-option-A** — measured against the serve before `04113b2`.
> The `#lg-caddy-toggle` row below no longer exists in the branch: that button was
> removed and replaced by `[data-caddy-open]` openers (§6). **Re-measure after A
> merges.** Everything else in the table — the caddy, the chips, the dials, the panel
> — is unaffected by A and still holds.

Fetched as the real owner (minted session, gate cookie, pinned to the internal IP) and
counted **elements, not CSS rules** — `lg-caddy` appears 55 vs 35 times between the two
renders purely because the stylesheet stays, which would have been a misleading count:

| probe | owner (`?view=me`) | owner `?view=member` | anon |
|---|---|---|---|
| `id="lg-caddy-toggle"` (the Sections button) | **1** | **0** | 0 |
| `id="lg-caddy"` (the drawer itself) | **1** | **0** | 0 |
| `data-pmp-block=` (privacy chips) | **4** | **1** | 0 |
| `data-prec-aud=` (location dials) | **2** | **0** | 0 |
| `class="lg-viewas"` (the panel) | 1 | **1** | 0 |

`/profile/edit` → **302 → `https://dev.loothgroup.com/u/visibility-matrix-qa`**, measured.

**Correction to an earlier draft of this section.** I had written that `$editing` gates
"the per-section privacy chips" without qualification. The measurement says otherwise —
one chip survives preview mode:

- gone in preview: `about`, `gallery` (the **per-section** chips) and both location
  precision dials.
- **survives: the `header` chip** — the master *Profile visibility* control, because it
  lives in the `.lg-viewas` panel under the weaker `$isOwner` condition (`u.php:762`),
  not under `$editing`.

So the accurate sentence for the guide is: **in View-as preview you can still change
your whole-profile visibility, but not any individual section's.** That asymmetry is
real, is not obvious, and is exactly the sort of thing a member would trip on.

---

## 2.5 Every control a member can reach

Authoritative index: the `me-*` endpoints (`profile-app/api/v0/`), cross-checked
against what the renderer actually emits. Everything below is edited **in place on
`/u/<slug>`** — there is no separate form.

### Identity / chrome
| Control | Endpoint | Notes |
|---|---|---|
| **Avatar** | `me-avatar.php` | click the photo; POST to upload, DELETE to remove (`u.php:1684,1699`) |
| **Cover / banner** | `me-banner.php` | "+ Add banner" strip above the identity row (`u.php:2377,2397`) |
| Display name | `me-name.php` | **renaming re-derives the @handle AND the profile URL** |
| Tagline / bio | `me-header.php` | the "Add a one-line bio…" line |
| Status lights | `me-lights.php` | "+ Status" picker; work / collab / tour (§4) |
| @handle | `me-slug.php` | **READ-ONLY — not member-editable.** Ian 2026-07-25: handles are derived from the name. Renames re-derive; past mentions stay uuid-anchored so they never break. |

### Layout
| Control | Endpoint | Notes |
|---|---|---|
| Add / remove a section | `me-layout.php` | the caddy picker (§6) |
| Reorder sections | `me-section-order.php` | drag the grip, or the ⌃ ⌄ buttons on each block |

### Section content
`me-about.php`, `me-gallery.php`, `me-resume.php`, `me-socials.php`,
`me-connect.php`, `me-location.php` + `me-location-search.php`, and
`me-catalog.php` / `me-skills.php` / `me-instruments.php` (the catalog-chip blocks).

### Privacy
`me-header.php` (master switch + ceiling), per-section chips, `me-location.php`
(both precision dials), `me-discussion-visibility.php`. See §3.

### Endpoints that are NOT member-reachable profile blocks
`me-craft.php`, `me-credentials.php`, `me-scenes.php`, `me-highlights.php`,
`me-dropoffs.php`, `me-freeform.php` — none of these keys appear in the render
dispatch (`_render_blocks.php:124-135`, 12 keys only) or in `LAYOUT_BLOCKS`. They are
retired or practice-only. **Do not document them as profile sections.**

### The state a member never sees but must understand: the gate screen

`Block::gateDecision()` (`Block.php:417`) returns one of three outcomes, and the
middle one is its own **screen**:

| decision | when | what renders |
|---|---|---|
| `private` | master switch private, viewer below admin | **nothing at all** |
| `gate` | members-only, viewer is anonymous | `looth_render_members_gate()` — a join / sign-in screen |
| `render` | otherwise | the profile, blocks then refining down per their own vis |

The guide should show that gate screen, because it is **what a logged-out visitor sees
of a default profile** — and the member themselves can only reach it via
"View as → Public".

**STILL NOT PROVEN — and I tried.** I fetched the fixture profile as a true anonymous
viewer: it returned **200 with the full profile rendered** (display name present 9×, no
`class="lg-gate"` element at all). That is *correct behaviour*, not a leak — the matrix
parks fixture 1849 with `header=public`, so an anon visitor is entitled to see it. It
simply means **this fixture cannot demonstrate the gate**. Proving the gate screen needs
a subject whose header ceiling is `members`, which the matrix's S2 state does exercise
but does not leave behind. Flagged in §9; do not screenshot the gate until it has
actually been produced.

*(Method note: my first count looked like the gate WAS present — `lg-gate` matched 7
times. Those were all CSS rules. Counting `class="lg-gate` instead gave 0. Same family
as the `grep -c` trap: match the element, not the word.)*

## 3. PRIVACY — the full model

Privacy is **four independent controls**, not one. Members conflate them; the guide
must not. The single decision point is `profile-app/src/Visibility.php` (208 ln) —
every read surface asks it (page SSR, directory, map pins, search mask, file store).

### 3.1 Audience vocabulary

`Visibility::audience()` (`Visibility.php:53`) resolves every viewer to exactly one of:

| audience | who | renderer calls it |
|---|---|---|
| `owner` | the subject themselves | `me` |
| `admin` | any site admin | `admin` |
| `member` | any other signed-in member | `member` |
| `public` | anonymous / logged-out | `public` |

### 3.2 Control 1 — Profile visibility (the MASTER SWITCH)

- **Column:** `users.profile_visibility`, plus the header section's ceiling.
- **UI:** `/u/<slug>` → "Profile visibility" row (`u.php:760-764`), a chip control.
- **States:** Public / Members-only / **Private**.
- **Default:** `members` (`Block::HEADER_DEFAULT`, `Block.php:28`).
- **What Private actually does** — this is the one people get wrong. Private is
  **OWNER-ONLY**, *not* members-only. It removes you from the profile page, the
  directory, the map, and search, **for other members too**. Admins excepted.
  (`Visibility.php:16-21`, `Visibility::profileVisible()` at `:74`.)
- It is a **ceiling**, not just a default: no section can be more open than it.
  `Block::effectiveVisibility()` (`Block.php:381`) takes the more restrictive of
  header-vs-section. A section chip set to Public under a Members-only header shows a
  **capped warning** (`⚠▾`) and viewers see the restricted value
  (`_render_blocks.php:653-657`).

### 3.3 Control 2 — Per-section visibility chips

- **UI:** a chip on each section, rendered by `looth_pmp_control()`
  (`_render_blocks.php:647`).
- **States:** Public / Members-only / Private.
- **Default:** `members` for every block (`Block::blockVisibility()` default arg,
  `Block.php:696`).
- **Effective value** = more restrictive of (header ceiling, section chip).
- **Resume is special:** it does NOT live in `profile_sections`. It reads the
  singleton column `users.resume_visibility`, default `members`
  (`Visibility.php:116-119`).

### 3.4 Control 3 — Location precision (TWO dials, not one)

Location is the most intricate control and deserves its own guide sub-section. The
model (`Visibility::precisionForAudience()`, `Visibility.php:131`): **one address is
stored; the displayed precision follows the audience.**

- **Two dials:** "Members see" and "Public sees", each
  `private | state | city | street` (`Block::LOCATION_PRECISION`, `Block.php:550`).
  UI = `looth_prec_control()` (`_render_blocks.php:669`).
- **Defaults for a never-touched profile:** members → **city**; public → **private**
  (`Visibility.php:134,137`). i.e. **the public map is explicit opt-in.** Imported
  members are not on the public finder until they choose to be.
- **Owner** always sees `street`.
- **Admin** sees `street` — *unless* members-precision is `private`, then `private`.
- **Public never out-resolves members:** if members-precision is `private`, public is
  forced `private` too, teaser dots included (`Visibility.php:140`).
- **Removing the Location section from your layout takes you off the map for
  everyone**, admins included (`Visibility.php:151-153`). A layout action with a
  privacy consequence — the guide should call this out explicitly.

### 3.5 Control 4 — Discussion post identity

- **UI:** "Discussion posts" row (`u.php:773-780`), a 2-state radio group.
- **States:** Public / Member-only. **Default:** `member` (`u.php:155`).
- **Governs only:** whether logged-out visitors see your real name+avatar on your
  **discussion posts**, or the string "private member". Signed-in members always see
  you.
- **It is NOT the profile switch.** Distinct control, distinct column, adjacent row in
  the UI — a prime confusion the guide can defuse with one caption.

### 3.6 What each audience sees — summary truth table

Source: `Visibility::audienceCanSee()` (`Visibility.php:93`).

| section vis → | Public | Members-only | Private |
|---|---|---|---|
| **owner** | ✅ | ✅ | ✅ |
| **admin** | ✅ | ✅ | ✅ |
| **member** | ✅ | ✅ | ❌ |
| **anon** | ✅ | ❌ | ❌ |

With the master switch on **Private**, every row below admin becomes ❌ regardless of
section chips — the profile does not exist for them.

### 3.6b PROVEN — the whole model, green against the running system

**`bin/visibility-matrix.php`: pass=67 fail=0, exit 0. Run on dev2, 2026-07-28,
authorised by keeper.** Everything in §3 above is now verified end to end against the
running site, not merely read from source. The gate drives **real HTTP** as
anon / member / owner / admin across three subject states — S1 public-finder opt-in,
S2 members-only default, S3 master-switch private — over `/u/` SSR, the user/users
APIs, directory list + map pins, pins-public, `me/location`, the `/profile-media` file
store (avatar, gallery, resume) and the hub search mask.

**The gate had rotted and could not run at all.** It died before its first check. Three
independent environment drifts, all repaired in this lane (§8a):

1. **Gate token moved.** It read `set $loothdev_token` from
   `sites-available/dev.loothgroup.com.conf`; that file is gone and the value is now a
   cookie map in box-local `conf.d/loothdev-auth.conf`. Exit 2, zero checks run.
2. **Host unreachable.** `dev.loothgroup.com` resolves to 50.19.198.38, which this box
   cannot reach (curl exit 28). Every check returned `code=0` — **a wall of FAILs that
   looked exactly like a catastrophic privacy regression and was pure connectivity.**
3. **wp-cli ran as the wrong user.** As `www-data` it cannot read
   `/etc/looth/live-wp-keys.php`; needs root + `--allow-root`.

**The trap worth remembering** (it nearly became a false report from this lane): pinning
requests to `127.0.0.1` to reach the box makes `api/v0/users.php:18` treat the caller as
an **internal service** — which skips the anon 401 (`:19`) and skips slug-stripping on a
private profile (`:44`). Two checks then fail in a way that reads precisely like *"a
private member's slug and display name leak to other members."* **It is not a leak.** It
is the test's own `(external)` path being short-circuited by the pin. Pinning to the
box's internal IP (172.31.78.94) instead exercises the real external path — and both
checks pass. Measured both ways.

So: **no privacy defect was found.** The one thing that looked like one was an artifact
of the instrumentation, and I proved that before reporting it.

### 3.7 What the STORE actually says — measured, not inferred

Queried directly against Postgres `profile_app` on **dev2**, 2026-07-28. (dev2 and
live hold different data — these are dev2 numbers.) **n = 1,925 users.**

| measure | result |
|---|---|
| `users.profile_visibility` | **public 1,924 · private 1** |
| `profile_sections` rows with `key='header'` | **16 total** (12 public, 4 members) |
| `users.resume_visibility` | **members — all 1,925** |
| `location_members_precision` | city 1,918 · street 7 |
| `location_public_precision` | **private 1,903** · city 14 · state 2 · street 6 |
| `profile_layout` | **never-arranged 1,858 · explicit 67** |

**This corrects a reading of the code alone.** `users.profile_visibility` is *not* the
tri-state the UI chip shows — the store proves it is effectively binary
(`public`/`private`), and the tri-state ceiling lives on a **separate**
`profile_sections` row with `key='header'`. The chip writes **both columns**
(`Visibility.php:18-20`, "ONE DIAL"). Because only **16 users have a header row at
all**, the other ~1,909 never touch that table and fall through to the code constant
`HEADER_DEFAULT = 'members'` (`Block::headerCeiling`, `Block.php:400-407`).

So the honest sentence for the guide is: **a member who has never touched anything is
Members-only** — even though their `users.profile_visibility` literally reads
`public`. Writing "your profile defaults to public" would be wrong; writing "the
default is members-only" is right, but for a reason no single column shows.

Two more facts worth the guide's attention:
- **Only 22 of 1,925 members are on the public finder** at any precision. The
  opt-in default (§3.4) is doing exactly what it was designed to do.
- **Only 67 of 1,925 members have ever arranged their layout** — 96.5% have never
  successfully used the section picker. See §6.

`profile_sections` also still holds **retired keys** — `freeform:<id>` (2 rows) and
`dropoffs` — removed by Ian on 2026-06-11. `Block::normalizeLayout` drops them on read
(`Block.php:310`), so they are inert orphans, not live sections. Do not document them.
(Aside, out of scope: `src/Auth.php:74` still lists a `me-freeform.php` endpoint for
that retired block. Flagged, not chased.)

### 3.8 File store

`Visibility::fileVisible()` (`:167`): `avatars` + `banners` are **identity chrome and
always servable** (they appear in forum bylines and messages). `gallery` follows the
gallery section's visibility; `resumes` follows `users.resume_visibility`. Unknown
classes **fail closed**.

---

## 4. Sections — the catalog a member can deploy

Registry: `Block::LAYOUT_BLOCKS` (`Block.php:58`).

| key | label | notes |
|---|---|---|
| `about` | About | rich text, 8k plain / 16k sanitized HTML |
| `location` | Location | the two-dial control (§3.4) |
| `skills` | Skills | catalog-backed chips |
| `services` | Services | catalog-backed chips |
| `instruments` | Instruments | catalog-backed chips |
| `music` | Music | catalog-backed chips (genre catalog) |
| `gallery` | Gallery | |
| `gallery-2`, `gallery-3` | Gallery 2/3 | **not in the picker** (`caddy => false`); deploy from the in-rail "Add gallery (N left)" counter, max 3 |
| `resume` | Resume | visibility from `users.resume_visibility` |
| `connect` | Connections | |
| `socials` | Links | |

The four catalog blocks (`Block::CATALOG_BLOCKS`, `:81`) are the **filterable** ones —
they tag the member with site taxonomy so others can find them in search. The picker
says so (`u.php:821`).

**Default layout for a never-arranged profile** (`Block::defaultLayout()`, `:122`):
**only Location, and only if a location is set.** Everything else is opt-in from the
picker *even when imported data would populate it* — a deliberate Ian 6/12 ruling after
the BuddyPress friendship import auto-surfaced a Connections grid on ~1,200 profiles
nobody had arranged. The data stays intact, waiting for opt-in.

### Header status lights
`Block::HEADER_LIGHTS` (`:196`) — addable header widgets, a glowing dot + label:
`work` (accepting / not accepting), `collab` (open / not open), `tour` (available /
on tour / not available). Added via a "+ Status" picker.

---

## 4b. Where a member's privacy choices become VISIBLE — directory + map

The profile page is where a member *sets* privacy; the directory and map are where they
*see it take effect*. The guide's privacy section should end here, because "did it
work?" is answered on this surface, not on the profile.

**Mind the two files with the same name** — I initially read the wrong one and found no
visibility enforcement at all:

| file | role |
|---|---|
| `profile-app/web/directory-members.php` (930 ln) | the **HTML shell** — filters, layout. **No `Visibility::` calls, correctly.** |
| `profile-app/api/v0/directory-members.php` | the **FEED** — this is where enforcement lives |
| `profile-app/api/v0/directory-pins-public.php` | the public (anon) map pin aggregate |
| `webroot/directory-desktop.js` (815 ln) | desktop behaviour |
| `webroot/directory-mobile.js` (773 ln) | mobile behaviour — **a genuine split**, per `MOBILE-DESKTOP-SPLIT.md` |

Enforcement in the feed, all through the one decision point:
- `Visibility::profileVisible()` drops the row entirely (`:55`) — master switch.
- `Visibility::audienceCanSee()` gates the row and each pin (`:57`, `:433`).
- `Visibility::locationPrecision()` decides pin resolution (`:32`); only the
  coarsening math stays local, so **a pin can never out-resolve a card**.
- The SQL carries the master switch too (`:136`,
  `pins-public.php:39` — `u.profile_visibility = 'public'`), so a private profile is
  excluded in the query, not just the render.

**Both desktop and mobile directory JS exist as separate files**, so the guide needs two
screenshot sets here as well as on the profile.

## 5. DESKTOP vs MOBILE — two surfaces

Per `docs/atlas/MOBILE-DESKTOP-SPLIT.md`, mobile and desktop are deliberately separate
across the Hub. **The profile is different: it is ONE responsive template** (`u.php`)
whose behaviour swings on CSS media queries. The guide still needs two screenshot sets,
because what the member sees genuinely differs.

### The breakpoint that matters is 1380px — and it is NOT a phone breakpoint

```
u.php:487   @media(min-width:1380px)   → owner shell becomes a 3-column grid
                                         "caddy | profile | spacer"
u.php:496   .lg-shell--owner .lg-caddy → sticky, in-flow, permanent column
u.php:500   .lg-viewas__caddy          → display:none  (no toggle button needed)
```

Below 1380px — **which includes every phone, every tablet, and most laptops** — the
caddy is an off-canvas drawer and the only way to open it is a button (§6).

Other profile breakpoints found so far: `560px` (identity row stacks, banner aspect
ratio changes, lightbox nav shrinks — `u.php:673,684,599`) and, in the separate
`edit.css` surface, `780px` / `560px`.

### The mobile surface carries a fixed tab bar that desktop does not

`webroot/bottom-nav.js` injects a **fixed bottom tab bar** — 54px plus
`env(safe-area-inset-bottom)`, `z-index 2147481200`, 5 tabs with a raised centre Post
button — and sets `body.has-looth-tabbar{padding-bottom:…}`. On `/u/` and `/profile/`
the **"You" tab renders active** (`bottom-nav.js:412`). It also *hides* the shared
header's account bubble and the hamburger on mobile (`:123-132`, Ian 2026-06-24).

Consequences the guide must respect:
- **Mobile screenshots will always contain the tab bar; desktop ones never will.** Two
  genuinely different frames, not one with a note.
- The **bottom of the mobile screen is spoken for.** Any future profile affordance
  docked to the bottom collides with it (this constrains the §6 proposal).
- The **route into the profile differs by surface**: desktop uses the header account
  menu → "My Profile"; mobile uses the bottom "You" tab.

### Visual evidence already on the box (partially stale — read the caveat)

`/var/www/dev/mockups/` holds owner-view captures from **2026-06-15**:
`u-owner-768.png`, `u-owner-1024.png`, `u-burger-768-closed.png`,
`u-burger-768-open.png`. I have read them. They **confirm the shape** of everything in
§3 and §6 — the dark Profile-controls panel with the amber Sections pill top-right, the
"Members see / Public sees" dial pair under the map, the right-sliding drawer with
CORE / EXTRAS groups and FILTERABLE badges.

**They are stale in detail and must NOT be used as guide screenshots.** The June
drawer shows `Gallery` as a palette bubble and has no `Services`; today's code has
`Services` in Core and moved Gallery behind an "Add gallery (N left)" counter
(`u.php:792-794`, Ian 2026-07-24). Anything shot before that date shows a picker that
no longer exists.

**NOT PROVEN:** the full list of what visually differs at phone width (these captures
are 768px and 1024px — neither is a phone). That needs the browser engine at two
viewports. Outstanding work, §9.

---

## 6. IAN'S COMPLAINT — the section picker on mobile

> **STATUS 2026-07-28: RULED AND BUILT.** Ian chose option A; it is implemented in
> `04113b2` (branch only — **not merged, not on the serve**). Everything in this
> section describes the state that was audited and is retained as the record of *why*
> it changed. See `PROFILE-SECTION-PICKER-PROPOSAL.md` for what shipped.
>
> **What the guide must describe once this merges:** the picker opens from a
> **"Your layout" row under the identity card** and from a **dashed "＋ Add a section"
> card at the end of the block list** — *not* from the privacy panel. Every mobile and
> desktop-narrow screenshot of the owner view changes. **Do not shoot the picker
> frames until this is on the serve** (`PROFILE-GUIDE-SHOTLIST.md` §3A).

> "I don't currently like the way the section picker is deployed on mobile.
> It's a weird button in the privacy controls."

**Confirmed, and it is worse than 'on mobile'.** Here is exactly what it is.

### What it is

The section picker is the **caddy** (`<aside class="lg-caddy">`, `u.php:816`). Below
1380px it is a hidden off-canvas drawer. Its **only** opener is:

```php
u.php:748
<button class="lg-viewas__caddy" id="lg-caddy-toggle" aria-label="Open sections menu">
  <span class="lg-burger-ic"></span><span>Sections</span>
</button>
```

That button is emitted **inside** `<div class="lg-viewas" role="group"
aria-label="Profile controls">` (`u.php:729`) — the panel whose other three rows are
View-as, **Profile visibility**, and **Discussion posts**. It is pinned to the far
right of the View-as row by `margin-left:auto` (`u.php:403`) and styled as an amber
pill, visually unlike everything around it.

So: **the only control that adds content to your profile is parked in the corner of the
privacy panel**, wearing a different colour from its neighbours, next to two controls
that do something completely unrelated. Ian's read is correct.

**Confirmed visually**, not just in source: `u-owner-768.png` (2026-06-15) shows the
amber pill sitting in the top-right of the dark panel, the only warm-coloured control
in a black box of privacy settings. That capture is stale in its drawer contents
(§5) but the placement it shows is still what the code emits today.

### Why it ended up there

**Commit archaeology yields nothing.** `git log -S` for `lg-viewas__caddy`, `lg-caddy`
and the `1380` breakpoint all bottom out at `e5d466d` — the "fresh seed from dev2
reality" mega-commit that seeded this repo. The picker arrived whole, with no
incremental history recording a decision. So the following is **reconstruction from
the code and its own comments, and is NOT PROVEN as intent**:

1. The caddy was designed **desktop-first as a permanent left column** (`u.php:483-501`
   describes the ≥1380px 3-column grid as the intended form).
2. Below 1380px that column has nowhere to live, so it became a drawer.
3. A drawer needs a toggle. The `.lg-viewas` strip was the only owner-only chrome
   already on the page at that point — `$editing` is a subset of the condition that
   emits it — so the toggle was dropped into the nearest available owner-only row.
4. The comment at `u.php:401-402` confirms the reasoning was about *rendering*, not
   *information architecture*: "below 1380px the caddy is an off-canvas drawer, so this
   reads as a menu button; at >=1380 it's display:none".

It is a **placement of convenience**, not a designed IA decision. Nothing about
"Sections" belongs to "Profile controls" conceptually.

### The number that backs Ian up

**67 of 1,925 dev2 members have ever arranged their layout. 1,858 have not** (§3.7).
96.5% of members have never successfully used the section picker.

That is not proof of causation — imported members may simply not have engaged, and
dev2 is not live. But it is the strongest available signal that the control is not
being found, and it is consistent with everything above: the picker's only opener is
top-of-page, in the wrong conceptual group, in the hardest thumb zone on a phone, and
it vanishes in preview mode. **Worth re-running against live before treating it as
decisive** — dev2 and live hold different data.

### Second-order problem worth flagging

Because `$editing` goes false in View-as mode (§2), the Sections button vanishes when
previewing — so the one entry point to the picker is not merely oddly placed, it is
also **intermittent**.

### Proposal — drawn, published, not built

**URL for Ian (dev-gated, loopback-or-cookie):**
`https://dev.loothgroup.com/footer-mockups/profile-sections/`

Source of truth in-repo: `footer-mockups/profile-sections/index.html` (published copy
lives at `~/projects/footer-mockups/profile-sections/`, the same convention the keeper
dashboard used on 2026-07-27). Three phone frames side by side — **Today**,
**Proposal A (recommended)**, **Alternative B** — reflowing to stacked on a phone,
because Ian reads these on his.

- **A (recommended):** Sections leaves the privacy panel and gets its own "Your layout"
  row under the identity card, **plus** a dashed "＋ Add a section" card at the end of
  the block list. Fixes the grouping error, is reachable *after* scrolling (today's
  opener is pinned to the top — a member at the bottom of their profile must scroll all
  the way back up), matches the drawer's own "drag a section into your profile" model,
  and adds no floating layer.
- **B (alternative):** a sticky bar docked above the mobile tab bar. Best thumb reach,
  but it stacks a second bar on the existing 54px tab bar and competes with its raised
  Post button, and costs vertical height permanently for a control used rarely.
- **Desktop is untouched by either.** At ≥1380px the permanent sidebar still wins.

**Not changing it.** Ian decides.

---

## 7. The archived Membership Guide — what it covers

Ian's ruling: *"archive it for future additions, it's moot for profile."* Not built on,
not deleted. Recorded here so a future entry can pick it up.

### What it covers — and the key fact

```php
MembershipGuide.php:93
private const SECTION_SLUGS = [ 'events', 'archive', 'feed', 'forums', 'looths', 'loothalong' ];
```

**PROFILE IS NOT ONE OF ITS SECTIONS.** The archived guide covers six surfaces — events,
archive, feed, forums, looths, loothalong — and has never had a profile entry. This
independently corroborates Ian's "it's moot for profile": there is nothing to reuse
because nothing was ever written. The PROFILE entry is genuinely net-new.

Its other machinery, worth knowing before a future entry picks it up: an admin screen
(`renderAdmin`, `:669`) editing wp_options-backed **preview cards, starter cards,
elders (with BuddyPress-backed bios/avatars/links), screenshots, recurring shows**, and
demo-video URLs; plus per-elder page syncing (`syncElderPages`, `:1085`). It is a
**CMS-driven** guide, not a hand-written one. A profile entry built the same way would
mean an admin editing screenshots in wp-admin — worth an explicit decision, because
the standing rule ("change the profile → change the guide") is easier to enforce
against files in git than against rows in `wp_options`.

### There are TWO implementations, not one

The brief cited ~2,700 lines in the plugin. There is also a **standalone front
controller** — a verbatim port with no WP boot:
- `membership-pages/web/membership-guide.php` (139 ln) + `membership-guide.css` (125)
- `membership-pages/lib/guide-data.php` (111) — reads the same `wp_options` by direct PDO

### Which one actually serves — RESOLVED, measured on the running box

**`membership-pages` owns the route. The plugin does not.** Verified by fetching
`/membership-guide/` on dev2 (gate cookie, pinned to the internal IP):

- 200, and the body is `membership-pages/web/_admin-gate.php` — its `<title>` is
  literally `Not available — The Looth Group`.
- Route registry: `membership-pages/web/router.php:61`
  `'membership-guide' => ['membership-guide.php', 'admin', 'public']`.
- The gate is **`manage_options`-only** and is described in the router as *"the
  AUTHORITATIVE gate"* (`router.php:13-16, 44`). A profile-app `looth_id` token does
  **not** satisfy it — I minted an admin one and still got the Not-available page,
  because the gate wants a real WP admin capability, not a profile-app session.
- Prelaunch `admin` → live `public`, flipped by **a flag in the poller's WP-admin
  settings with no code edit** (`router.php:38-39`).

So the division of labour is: **the plugin is the CMS + admin editor** (writes
`wp_options`), and **membership-pages is the front end** (reads those options by direct
PDO, no WP boot). A PROFILE entry extends `membership-pages/web/membership-guide.php`
for rendering, and the plugin's admin screen only if it needs new editable fields.

### Header user menu — verified, with a trap

The brief's claim holds. The **live** header
(`lg-shared/site-header.php:557-592`) menu is: My Profile → `/u/<slug>`, Manage Account,
Join, Gift Memberships, Redeem a Gift, My Gifts, Earnings, Request a Refund, Test
Checklist, Sign out. **No Membership Guide link.**

**The trap:** `lg-shell/lg-shared/site-header.php:325` *does* carry
`<a role="menuitem" href="/membership-guide/">Membership Guide</a>`. That file is the
**dead twin tree** flagged in `MOBILE-DESKTOP-SPLIT.md` §4 (`#twin-cleanup`). A grep for
"membership-guide" hits it first and reads as "already linked". It is not. **Whoever
adds the guide link must edit `lg-shared/site-header.php`, not the twin** — and note
the mobile surface hides that account bubble entirely (§5), so mobile needs its own
entry point in the bottom-nav tray.

---

## 8. File → guide-section map (for the standing rule)

The standing rule ("any change to the profile system requires a matching change to the
guide") needs a hook. This is it.

| Change a file here… | …and this guide section must change |
|---|---|
| `profile-app/src/Visibility.php` | **all of Privacy** — it is the single decision point |
| `profile-app/src/Block.php` §`LAYOUT_BLOCKS`/`CATALOG_BLOCKS` | Sections catalog; the picker screenshots |
| `profile-app/src/Block.php` §`HEADER_LIGHTS` | Status lights |
| `profile-app/src/Block.php` §`LOCATION_PRECISION`, `precisionFromInput` | Location precision dials |
| `profile-app/web/u.php` §`.lg-viewas` rows (`:729-784`) | Privacy controls walkthrough + View-as |
| `profile-app/web/u.php` §`.lg-caddy` (`:816+`) & the 1380px media query | Adding a section — **desktop AND mobile screenshots** |
| `profile-app/web/_render_blocks.php` §`.lg-layoutrow` / `.lg-addsec` (the option-A openers) | **How you open the picker** — the "Your layout" row + end-of-list add card. Changing either changes the guide's core "add a section" walkthrough on both surfaces. |
| `profile-app/web/_render_blocks.php` `looth_pmp_control`/`looth_prec_control` | the privacy-chip and precision-picker captions |
| `profile-app/web/edit.php` | entry points / claim + login interstitials |
| `profile-app/web/media.php`, `Visibility::fileVisible` | what's visible in galleries/resumes |

---

## 8a. The privacy regression gate — repaired, and how to run it

`profile-app/bin/visibility-matrix.php` is the enforcement arm of the standing rule:
if the guide's privacy section and the code ever disagree, **this is the thing that
says who is wrong.** It was unrunnable; it now runs green.

```bash
php /home/ubuntu/worktrees/<lane>/profile-app/bin/visibility-matrix.php
# expect: ==== MATRIX GREEN ====  pass=67 fail=0   (exit 0)
```

- **dev2 ONLY.** It mutates fixture user 1849. **Never on live.**
- Needs passwordless sudo (token minting, fixture SQL, wp-cli as root).
- Override the gate cookie if the conf moves again: `LG_DEV_GATE_TOKEN=<value>`.
- Override the target: `LG_MATRIX_HOST=https://…`.

**Read failures carefully before believing them.** This gate has now produced two
distinct false catastrophes — an all-`code=0` sweep that was connectivity, and a
private-profile "slug leak" that was a loopback pin defeating the endpoint's own
external path. **Confirm `curl_pin()` is sane before reading any FAIL as a privacy
verdict.**

---

## 9. Outstanding — what is NOT yet proven

**Proven so far:** the privacy model and defaults (§3, verified in the store, which
corrected the code reading), the control inventory (§2.5, from the endpoint list +
render dispatch), the picker's placement (§6, confirmed against a real capture of the
running page), the section catalog (§4), and the archived guide's contents (§7).

**Not proven — honestly outstanding:**

1. **No current screenshot of the running system exists at phone width.** The engine
   is queued with keeper behind events-fix; I flagged that the box measured **295MB
   available** on 2026-07-28 and an engine is 500-660MB, so it should not be granted at
   that number. The June captures (§5) confirm placement but are stale on contents and
   are 768/1024px — neither is a phone.
2. **Full phone-width visual inventory** (§5) — blocked on 1.
2b. **The members GATE screen has never been produced** (§2.5). The matrix fixture is
   parked `header=public`, so it renders to anon rather than gating. Needs a subject at
   `header=members`. Until then the gate screen is described from source only — **do not
   shoot it or caption it as fact.**
3. ~~Which implementation serves `/membership-guide/`.~~ **RESOLVED** (§7):
   `membership-pages` owns the route and the `manage_options` gate; the plugin is the
   admin/CMS side. Measured on the running box.
4. ~~`bin/visibility-matrix.php` not run.~~ **DONE — GREEN, 67/67** (§3.6b). Keeper
   authorised it for dev2 on 2026-07-28. Three environment drifts repaired to make it
   runnable again; fixes committed (§8a). Fixture user **1849** (`visibility-matrix-qa`,
   bridged wp 1910) is the gate's own permanent fixture, not litter — the script parks
   it members-only and its four restore checks passed. **Left in place deliberately:
   deleting it would break the gate.**
5. **The 96.5%-never-arranged figure is dev2 only** (§6). Re-run on live before
   treating it as decisive.
6. Practice profiles (`web/p.php`, 814 ln) — a second entity with its own header,
   hours, services, staff, dropoffs, and its own `me-practice-*` endpoints.
   **Deliberately not audited: I judged it out of scope** for an entry Ian described as
   "PROFILE". If practices belong in the guide, that is a second audit of comparable
   size. **Flagged for Ian's call.**
7. ~~Directory / map surfaces unaudited.~~ **DONE** (§4b) — enforcement traced to the
   FEED api/v0/directory-members.php + pins-public, all via the one decision point.
   Desktop and mobile directory JS are separate files; both need screenshots.
8. Commit archaeology on the picker placement is a **dead end, not pending** — it
   arrived whole in seed commit `e5d466d` (§6).

---

_Lane: profile-audit. Keeper-owned once merged._
