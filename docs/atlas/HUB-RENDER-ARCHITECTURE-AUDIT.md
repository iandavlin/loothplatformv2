# Hub Rendering Architecture Audit — findings + options

**Lane:** Hub rendering architecture audit (READ-ONLY, keeper-routed)
**Date:** 2026-06-23 · **Box:** dev2 (34.193.244.53) · **Repo:** loothplatformv2 @ main 4f9452f
**Scope:** map the Hub render pipeline (server + client), catalog mobile↔desktop coupling, propose
a cleaner break. **No code changed. Nothing pushed.**

---

## 0. TL;DR

The Hub (`/hub/`) is **server-rendered HTML** from the bb-mirror PHP front controller, then
**re-shaped at runtime by a ~12-file client overlay fleet** injected by `pwa.js`. Mobile and
desktop are NOT separate render paths — they are **one DOM + one base stylesheet, then layered
with breakpoint overrides spread across at least 5 CSS sources and ~6 JS files**, several of which
rewrite the same DOM and inject their own `<style>` blocks at runtime.

The tail-chasing is real and structural: **the same component (e.g. `.feed-card` / `.fc-card`) is
styled in 4–5 places with no single owner per viewport.** A fix at one layer flips the cascade at
one breakpoint and silently regresses the other.

Good news that narrows the fix: **the worst drift is already gone on dev2** — the webroot overlays
are byte-identical to the repo copies (the "~18 versions behind live" note was the *old* box). So
this is now an *architecture* problem, not a *sync* problem. That makes a clean break tractable.

---

## 1. Render pipeline — `/hub/` end to end

```
Browser → nginx (dev2.loothgroup.com.conf)
  include snippets/strangler-bb-mirror.conf
    location ^~ /hub/  →  alias /srv/bb-mirror/web/
                          try_files $uri /hub/index.php
                          FPM pool: php8.3-fpm-bb-mirror.sock   (NO WordPress; PG-backed)
  static .css/.js under /hub/ → served straight from the web dir, 1y immutable, ?v=filemtime busted
```

**Server side (all repo-canonical, served from a git symlink):**

| Stage | File | Role |
|---|---|---|
| entry | `bb-mirror/web/index.php` | front controller; parses REQUEST_URI, routes by segment count |
| shell | `bb-mirror/web/_chrome.php` | the page `<head>`/`<body>` shell; enqueues CSS+JS; pulls shared header/footer from `/srv/lg-shared/` |
| 0 seg | `forums/_feed.php` | site-wide unified feed (topics + content) |
| 1 seg | `forums/_feed.php` | forum-scoped feed |
| 2 seg | `forums/_single-topic.php` | single thread |
| frags | `_topic-body.php`, `_topic-replies.php`, `_suggest.php` | lazy fragments / type-ahead |

`/srv/bb-mirror` → `/home/ubuntu/loothplatformv2-serve/bb-mirror` (serve clone, **on `main`, clean**).
Canonical source `/home/ubuntu/loothplatformv2/bb-mirror`. **This pipeline is healthy.**

**Where mobile vs desktop diverge:** there is **no UA branch and no separate template.** The server
emits ONE HTML tree for every viewport. Divergence happens entirely **after** render, via:
1. CSS media queries in `forums.css` (`min-width:641px` desktop / `max-width:640px` mobile),
2. a media-gated `<link>` to `mobile-hub.css` (`media="(max-width:640px)"`),
3. JS width-branching (`matchMedia`) in `forums.js` and the overlay fleet,
4. runtime DOM rewrites + `<style>` injection by `hub-polish.js` (both viewports).

---

## 2. Client layer inventory (what shapes the Hub)

Two delivery pipelines feed ONE page:

### Pipeline A — bb-mirror engine (repo-canonical, git-symlink served)
Enqueued explicitly by `_chrome.php`:

| File | Size | Viewport | Tracked | Role |
|---|---|---|---|---|
| `forums.css` | **200 KB** | all (49 `@media`) | repo `bb-mirror/web` | base + responsive styling, single sheet |
| `forums.js` | **165 KB** | all (`matchMedia` branches) | repo `bb-mirror/web` | base behaviors: composer, modals, video, reactions |
| `hub-filters.js` | 9.6 KB | all | repo `bb-mirror/web` | toolbar live-search / author type-ahead |
| `_fonts-inline.css` | 15 KB | all | repo `bb-mirror/web` | inlined webfonts (perf) |

### Pipeline B — webroot overlay fleet (loose `/var/www/dev` files, mirrored into repo `webroot/`)
**None are enqueued by the server.** `pwa.js` (injected on EVERY page by the nginx `</head>`
sub_filter) is a runtime **layer loader** that conditionally `inject()`s them client-side:

| File | Size | Gate (in pwa.js) | Tracked | Role |
|---|---|---|---|---|
| `pwa.js` | — | site-wide (nginx sub_filter) | `webroot/` | SW reg + **the layer loader itself** |
| `app-settings.js` v32 | — | all | `webroot/` | theme / webfont / text-size (sets CSS vars) |
| `hub-polish.js` v211 | **296 KB** | `onHub` (all viewports) | `webroot/` | **re-shapes feed DOM → `.fc-*` app-cards; desktop mosaic; action row; injects ~15 `<style>` blocks** |
| `hub-infinite.js` v4 | — | `onHub` | `webroot/` | infinite scroll |
| `sponsor-cards.js` v5 | — | `onHub` | `webroot/` | sponsor spotlight cards |
| `hub-nojump.js` v2 | — | `onHub` | `webroot/` | cover-image placeholder heights |
| `mobile-hub.js` v3 | — | `onHub` **&& ≤640** | `webroot/` | killCompactOnMobile + long-press reactions |
| `mobile-hub.css` | 8.5 KB | `<link media="(max-width:640px)">` | `webroot/` | **mobile-only restyle of `.feed-page` / `.fc-*` (Buck)** |
| `bottom-nav.js` v23 | 53 KB | **all** (self-gates) | `webroot/` | tab bar (≤640) **AND** desktop settings gear (≥641) |
| `app-mobile-fixes.js` v36 | 27 KB | **≤640 / coarse** | `webroot/` | **"guard": injects `<style>` (incl. `!important`) duplicating an un-landed lg-shared fix** |

(Plus `directory-*.js`, `events-*.js`, `*-sheet.js`, `push.js` — not Hub-specific.)

**Source-of-truth / staleness:** on dev2, every `webroot/` overlay is **md5-identical** to its
`/var/www/dev/` live copy (verified hub-polish/bottom-nav/app-mobile-fixes/mobile-hub). They are
**hand-synced copies, not symlinks** — deployed via `webroot/deploy.sh`. So they CAN drift again,
but currently don't. The "~18 versions behind" warning applied to the decommissioned dev1 box.

---

## 3. THE COUPLING — why a mobile fix breaks desktop (and vice versa)

The root cause is **multiple uncoordinated layers styling/behaving on the same selectors and the
same DOM, with viewport split scattered instead of owned.** Concrete coupling points, worst first:

**C1 — One stylesheet, both viewports, same selectors.** `forums.css` styles `.feed-card`,
`.feed-page`, `.feed-sort-bar`, `.reply-stub` in a *base* rule, again in a `min-width:641px` block,
and again in a `max-width:640px` block. Editing the base hits both viewports; editing one breakpoint
override is silently undone or amplified by the other. 49 media queries, 25× `641px` + 19× `640px`,
interleaved through a 200 KB file.

**C2 — Behavior split across 3 files.** `forums.js` implements base behaviors but **explicitly
delegates the mobile variants to the overlays** (its own comments: "mobile's composer driven by
hub-polish fbStyleComposer", "hub-polish relocates the tray", "hub-polish status-watcher",
"Buck's mobile-hub.css"). So one feature (e.g. the composer) lives in `forums.js` + `hub-polish.js`
+ `mobile-hub.js`. Fixing the desktop branch in `forums.js` can't see the mobile branch in
`hub-polish.js`.

**C3 — JS rewrites the DOM the CSS targets.** `hub-polish.js` (296 KB, loaded **sync on all
viewports**) transforms the server-rendered `.feed-card` markup into `.fc-*` "app-cards" AND builds
the desktop mosaic — then injects **~15 runtime `<style>` blocks** that win over `forums.css` by
source order. So the *real* card styling is split between a static stylesheet and JS-injected CSS,
and the DOM the stylesheet was written against no longer exists by the time the user sees it.

**C4 — Five sources of mobile styling on the same component.** For `.fc-*` / `.feed-card` at ≤640:
(a) `forums.css` `max-width:640` blocks, (b) `mobile-hub.css` (whole file is ≤640), (c) hub-polish
runtime `<style>` mobile branches, (d) `mobile-hub.js` DOM changes, (e) `app-mobile-fixes.js`
`!important` rules. Selector-overlap check confirms `mobile-hub.css` and `forums.css` **share the
entire `.fc-*` family** (`.fc-actions .fc-author .fc-avatar .fc-cover .fc-excerpt .fc-title
.fc-replies .feed-card .feed-page …`). No single file owns "the card on mobile."

**C5 — The "guard" that silently overrides.** `app-mobile-fixes.js` injects a `<style>` block to
patch a footer-overflow bug whose "proper fix lives in `/srv/lg-shared/site-header.css`" (per its
own header). It claims source-order specificity, but it **does use `!important`** (lines 15/53/89/
128–130). It is a temporary duplicate that was never retired because the canonical fix never landed
— so it permanently shadows the real CSS, and anyone fixing the footer in CSS sees no effect.

**C6 — One file owns a mobile AND a desktop feature.** `bottom-nav.js` loads on all viewports
because it owns BOTH the mobile tab bar (≤640) and the **desktop header settings gear** (≥641),
self-gating internally. Touching it risks both surfaces at once (this exact trap already bit once —
gating it mobile-only removed the desktop gear, per the pwa.js comment).

**C7 — Two delivery pipelines for one page.** Engine = git-symlinked canonical
(`forums.css/js`); overlays = loose webroot files mirrored + `deploy.sh`. A fix can be made in the
wrong pipeline, or in the repo copy without deploying, or in `/var/www/dev` without committing.

---

## 4. Source-of-truth map

| Concern | Canonical | Served from | State |
|---|---|---|---|
| Hub engine (HTML/CSS/JS) | `loothplatformv2/bb-mirror/web` | `/srv/bb-mirror` → `loothplatformv2-serve` (main, clean) | ✅ healthy, git-symlinked |
| Overlay fleet | `loothplatformv2/webroot/*` | `/var/www/dev/*` (plain files) | ⚠️ in sync now (md5 match), but **copies not symlinks** → can re-drift |
| Shared chrome | `/srv/lg-shared/site-header.{php,css}` + footer | `/srv/lg-shared` | header fix for C5 **never landed** (guard still masking) |
| Layer loader | `webroot/pwa.js` → `/var/www/dev/pwa.js` | nginx `</head>` sub_filter | ✅ single loader, but it's the seam between the two pipelines |

Nothing is missing from the repo. The confusion is **two-pipeline assembly**, not lost files.

---

## 5. Cleaner-break options

### Option A — One responsive engine, single source; delete the overlays
Fold `hub-polish.js`, `mobile-hub.css`, `mobile-hub.js`, `app-mobile-fixes.js` (and the hub bits of
`bottom-nav.js`) **into the bb-mirror engine**: emit the final `.fc-*` app-card markup
**server-side** in `_feed.php`, move all styling into `forums.css` as proper responsive rules, move
behavior into `forums.js`. `pwa.js` keeps only SW + non-Hub layers.

- **Kills:** C1–C7 almost entirely. One DOM, one stylesheet, one behavior file, one pipeline, one
  owner per component. Mobile and desktop become breakpoints of the same rule, not separate files.
- **Migration cost:** **High.** ~300 KB of hub-polish JS DOM-rewrites must be reproduced as
  server markup; ~15 injected `<style>` blocks reconciled into `forums.css`; behavior merged. This
  is the big one — weeks, not days, and it touches the highest-traffic surface.
- **Risk:** High during migration (the feed is the front door), but **terminal** — once done the
  whack-a-mole is structurally impossible, not just discouraged.
- **Ends tail-chasing?** **Yes, definitively.**

### Option B — Explicit mobile vs desktop split with hard ownership boundaries
Keep two layers but make the split **explicit and non-overlapping**: `forums.css` carries ONLY
shared + desktop (`min-width:641`); ALL mobile rules move into `mobile-hub.css` (the existing ≤640
link); forbid `max-width:640` blocks in `forums.css`. Same for JS: `forums.js` = shared+desktop,
`mobile-hub.js` = mobile, delete `app-mobile-fixes.js` after landing its fix in lg-shared. One
file per (concern × viewport), enforced by a lint/grep gate.

- **Kills:** C1 (no more dual-breakpoint same-file), C4/C5 (collapses 5 mobile sources to 1),
  partially C2. Leaves C3 (hub-polish still rewrites DOM) and C7 (two pipelines) unless also moved.
- **Migration cost:** **Medium.** Mechanical relocation of mobile rules out of `forums.css` into
  `mobile-hub.css`; retire the guard. No DOM-generation rewrite.
- **Risk:** Medium. The boundary only holds if enforced (a gate), else it silently re-merges.
- **Ends tail-chasing?** **Mostly** — "fix mobile, break desktop" largely goes away because the
  files no longer overlap; but hub-polish's runtime DOM rewrite + `<style>` injection (C3) remains
  a second, weaker source of the same disease.

### Option C — Component-level responsive, single source of truth per component
Re-cut the Hub into self-contained components (feed-card, composer, filter-rail, bottom-nav…), each
owning its markup + its CSS (one block with internal breakpoints) + its behavior, regardless of
which file ships it. The card is responsive within its own definition; no file styles another's
component.

- **Kills:** C1–C6 *per component*, incrementally. You can convert `.feed-card` first and leave the
  rest, so it's shippable in slices.
- **Migration cost:** **Medium-high but incremental** — pay per component, not big-bang. Needs a
  convention (and ideally the server emitting final markup so hub-polish stops rewriting it).
- **Risk:** Medium, spread thin over time; lowest blast radius per step.
- **Ends tail-chasing?** **Yes for converted components, immediately;** the un-converted ones keep
  chasing until done. Converges on the same end state as A but path-dependent.

---

## 6. Recommendation

**Target Option A's end-state, reached via Option C's incremental path — starting with the feed
card.**

Rationale:
- The disease is **multiple layers on one component with no owner** (C3/C4 most of all). Only A's
  end-state (server emits final markup; one stylesheet; one behavior file) actually removes it. B
  leaves `hub-polish.js`'s DOM rewrite in place — the strongest coupling — so the whack-a-mole
  survives in weaker form.
- But A as a big-bang on the highest-traffic surface is the riskiest possible move. C de-risks it:
  convert **`.feed-card` / `.fc-*` first** (it's the single most-coupled component — shared across
  `forums.css` + `mobile-hub.css` + hub-polish injected styles), prove the pattern, then peel off
  composer, filter-rail, bottom-nav one at a time. Each slice deletes its overlay contribution.
- **Two immediate, low-cost wins regardless of which path** (do these first, this week):
  1. **Retire `app-mobile-fixes.js` (C5):** land its footer fix in `/srv/lg-shared/site-header.css`
     and delete the guard. It's a self-described temporary duplicate using `!important` that
     permanently shadows the real CSS. Pure debt removal, no feature risk.
  2. **Add a coupling gate** (`tools/gates/`): grep-fail any new `max-width:640` block added to
     `forums.css`, and any new `createElement('style')` in `hub-polish.js`. Stops the bleeding while
     the migration proceeds. (Aligns with the existing "defect-twice → a gate" practice.)
- **Sequencing the pipelines:** as each component moves server-side into the bb-mirror engine, it
  leaves the loose-webroot pipeline (B-side) and joins the git-symlinked canonical pipeline (C7
  shrinks naturally). End state: `pwa.js` keeps only the service worker + genuinely cross-surface
  layers; the Hub is wholly engine-owned.

**Do NOT** pursue Option B as the destination — it formalizes the two-layer split rather than
removing it, and explicitly leaves C3 (the 296 KB runtime DOM rewriter) in place. Its one good idea
— "no `max-width:640` in the desktop sheet" — is already captured as the coupling gate above.

---

## Appendix — evidence
- nginx: `snippets/strangler-bb-mirror.conf` `location ^~ /hub/` → `alias /srv/bb-mirror/web/`, FPM `bb-mirror.sock`.
- `_chrome.php:529` `forums.css`; `:533` `mobile-hub.css media=(max-width:640px)`; footer `forums.js` + `hub-filters.js`.
- `pwa.js:42-110` layer loader; `:69` hub-polish (onHub, sync); `:103` bottom-nav (all); `:106` app-mobile-fixes (≤640).
- `forums.css`: 49 `@media`; 25×641px, 19×640px.
- `forums.js`: `matchMedia`/`innerWidth` branches; comments delegate mobile to hub-polish + mobile-hub.
- `hub-polish.js`: ~15× `createElement('style')`, many `matchMedia('(max-width:640px)')` + `(max-width:960px)`.
- `app-mobile-fixes.js`: header declares it duplicates an un-landed lg-shared fix; uses `!important`.
- drift: `/var/www/dev/*` vs repo `webroot/*` md5-identical (hub-polish/bottom-nav/app-mobile-fixes/mobile-hub).
- overlap: `mobile-hub.css` ∩ `forums.css` = full `.fc-*` family + `.feed-card` + `.feed-page`.
