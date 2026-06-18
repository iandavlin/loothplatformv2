# Handoff — Hub-desktop + Profile-desktop → Buck (via buck-COORD)

**Decision (Ian, 2026-06-08):** fold both desktop surfaces back under **buck-COORD**, so Buck owns
**desktop + mobile** for Hub and the profile page. The dedicated desktop lanes (**hub-COORD** `a5a33224`
and **profile-page**) wind down. **Transfer executes AFTER the current bb-mirror/web push batch lands** —
hand Buck a clean, pushed tree, no in-flight ambiguity.

## Why
One owner per surface kills the desktop↔mobile **`fc-*` contract-announce dance** — the root of the
gray-box / mobile-flash regressions. Contract changes become *internal to one lane* instead of a
cross-chat relay. Same logic already proved out on the profile/map split (Buck owns both layers there).

## What transfers (buck-COORD becomes sole owner)

### Surface A — Hub desktop (was hub-COORD, all of `bb-mirror/web/`)
- `forums.css` (≥641 desktop card), `forums.js` (all feed/reaction/comment behavior)
- `forums/_feed.php` (the flat `fc-*` card contract — server-rendered markup)
- `_chrome.php`, `hub-filters.js`, `forums/_filter-rail.php`, `_hub-filters.php`, `_search.php`, `_suggest.php`
- `forums/_reply-render.php`, `_topic-replies.php`, `_topic-body.php`, `_topic-list.php`, `_single-topic.php`, `index.php`

### Surface B — Profile page desktop (was profile-page lane)
- `profile-app/web/u.php` — profile page: inline `<style>` (all `lg-block`/`lg-gallery`/`lg-gphoto`/`lg-carousel` CSS) + inline editor/carousel `<script>`
- `profile-app/web/_render_blocks.php` — block render markup (gallery, about, header, connect)
- ⚠️ `u.php` is **NOT split yet** — its inline CSS applies at all widths. New desktop rules still wrap in
  `@media (min-width:641px)` until Buck builds the `mobile-profile.css` split (now also his, no cross-lane).

### Surface C — Directory / Map desktop (was map-desktop lane) — FOLDED IN (Ian 6/8)
- `profile-app/web/directory-members.php`, `directory.css` (desktop ≥641), `mobile-directory.css` (≤640)
- Buck now owns **both** map layers + the surrounding layout (he already had mobile). The map is an
  interactive **Leaflet** widget: the "never JS-reshape" rule applies to the layout *around* it (filter
  rail, member list), NOT the map canvas. **Leaflet options per breakpoint at init** (zoom, controls,
  `scrollWheelZoom`) is config, allowed. Split line stays **640** (re-cut the old `≤760` layer).
- map-desktop lane winds down with hub-COORD + profile-page.

## What does NOT transfer (boundaries hold — buck-COORD stays a CONSUMER here)
- **bb-mirror backend** — `bb-mirror/lib/*`, `api/v0/*`, `deploy/*`, `bin/*`, `schema.pg.sql`, `config.php`,
  the person-sync + materializers (anon `is_anon`, `discussion_visibility`). Desktop consumes; doesn't own.
- **comments+reactions ENGINE** — `archive-poc/api/v0/*`, palette, `sql/*`. Contract asks route to the engine lane.
- **archive-poc PG content stack** — `discovery.*`, indexer, blobs.
- **profile-app backend** — `profile-app/src/*`, `profile-app/api/v0/*` (shared with practice).
  *(directory/map desktop is now IN scope — see Surface C; map-desktop lane folds in.)*

## Conventions buck-COORD inherits (the durable rules — don't relitigate)
- **640px is the site-wide mobile breakpoint.** Desktop `@media (min-width:641px)`, mobile `@media (max-width:640px)`, disjoint files.
- **CSS-ARRANGE the flat markup, never JS-reshape** the DOM (reshape = the mobile flash, banned).
- **Mobile CSS = media-gated `<link>` in `<head>`** (`media="(max-width:640px)"`), NOT injected via pwa.js (deferred JS = flash returns).
- **Count contract:** ONE server-rendered store per target; optimistic UI reconciles to server. Never a second count source.
- **Leak-safe rendering is a hard bar** (gated teasers, anon mask, discussion-visibility mask): masked identity must be ABSENT from DOM/JSON server-side, never CSS-hidden.

## In-flight state at handoff time (must settle BEFORE transfer)
Unpushed on `main`, all in the transferring trees — these land + push first:
| Commit | What |
|---|---|
| `3afbf8c` | replies: @mention seed + raw-body inline edit |
| `2ea1aa9` | cards: remove fc-activity beacon |
| `4aa4ca8` | cards: spacer collapse, compact title scale, divider |
| `4cd12f2` | hot-sort repoint → live card_reactions |
| `94f52e8` | profile u.php: discussion Public/Member toggle |
| `6ae1c90` | anon Phase 1: per-post "Post anonymously" toggle |
| *(not yet committed)* | hub-coord's **discussion-visibility mask wire** (`_feed.php`/`_reply-render.php`/`_topic-replies.php`) — the one open piece |

## Transfer checklist (run at handoff, after the push)
1. [ ] hub-coord lands the mask-wire commit; coordinator reviews diff for identity-absence.
2. [ ] Joint review of the full batch → git-tsar pushes → `origin/main` clean.
3. [ ] Confirm `git status` clean in `bb-mirror/web` + `profile-app/web` (no orphan edits).
4. [ ] Update `briefing-buck-coord.md` — add Surfaces A+B; delete the "announce fc-* contract to the Hub lane" rule (now internal).
5. [ ] Mark **hub-COORD** `a5a33224` + **profile-page** + **map-desktop** retired in CHATS-MENU + append to CHAT-LINEAGE (succeeded by buck-COORD).
6. [ ] Hand buck-COORD this doc as the absorb-briefing.
