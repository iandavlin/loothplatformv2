# Profile system — audit of what it ACTUALLY does today

**Status:** IN PROGRESS (source pass complete; running-system verification pending a
browser engine from keeper). **Written for:** the Membership Guide's PROFILE entry.
**Box:** dev2. **Audited at:** repo HEAD `6ef25e3`, branch `profile-audit`.

> Read this before writing a word of the guide. Every claim below is either sourced to
> a file:line or explicitly marked **NOT PROVEN**. Where I have only read the code and
> not the screen, it says so — a reading of the code is not a reading of the screen.

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

`$editing` controls whether the **entire editing apparatus** renders: the Sections
picker button (`u.php:748`), the caddy panel itself (`u.php:786`), the in-place field
affordances, and the per-section privacy chips.

**Consequence the guide MUST state:** when an owner uses "View as → Public" or
"View as → Member", the editor *disappears*. Not greys out — is not emitted. To get
editing back they must click "View as → Me". A member who previews their profile and
then wonders where their edit controls went is hitting designed behaviour, and it is
exactly the kind of thing an instruction set exists to pre-empt.

The **View-as strip itself** stays visible in all three modes, because it is gated on
`$isOwner || $adminEditing` (`u.php:728`), a weaker condition than `$editing`.

---

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

### 3.7 File store

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

Its route is gated in `membership-pages/web/router.php:61`:
`'membership-guide' => ['membership-guide.php', 'admin', 'public']` — i.e. **admin-only
today, flipping to public at live launch.** A future guide entry needs to know which of
the two implementations it is extending. **NOT PROVEN:** which one actually serves
`/membership-guide/` on dev2 right now — I have not traced the nginx route.

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
| `profile-app/web/u.php` §`.lg-caddy` (`:816+`) & `:487` media query | Adding a section — **desktop AND mobile screenshots** |
| `profile-app/web/_render_blocks.php` `looth_pmp_control`/`looth_prec_control` | the privacy-chip and precision-picker captions |
| `profile-app/web/edit.php` | entry points / claim + login interstitials |
| `profile-app/web/media.php`, `Visibility::fileVisible` | what's visible in galleries/resumes |

---

## 9. Outstanding — what is NOT yet proven

1. **Nothing here has been verified against the running screen.** Requested a browser
   engine from keeper; events-fix has priority. Until then §5's mobile detail and every
   screenshot-shaped claim is source-derived only.
2. Full phone-width visual inventory (§5).
3. Practice profiles (`web/p.php`, 814 ln) — a second entity with its own header,
   hours, services, staff, dropoffs. **Not yet audited**; may or may not be in the
   guide's scope. Flagged for Ian.
4. The directory / map surfaces (`web/directory-members.php`, 930 ln) — privacy-
   adjacent, since that is where visibility choices become visible to the member.
5. Archived guide survey (§7).
6. Commit archaeology on the picker placement (§6 "why").

---

_Lane: profile-audit. Keeper-owned once merged._
