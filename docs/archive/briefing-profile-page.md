# Lane briefing — Profile PAGE front-end, DESKTOP (2026-06-07)

You own the **public profile page front-end, DESKTOP (≥641) only** — the block render, inline styles, and
editor JS. A **carved slice of profile-app, deliberately disjoint from Buck** (directory/map + all mobile).

## ⚠️ DESKTOP-ONLY — the page is NOT split yet
`u.php`'s inline CSS currently applies at ALL widths (the page hasn't been split into the 640 layers — only
the directory/map was). So **wrap every new style rule in `@media (min-width:641px)`** so your desktop work
does NOT bleed onto mobile. Mobile profile keeps its current behavior; **Buck owns the mobile profile** (and
the eventual `mobile-profile.css` split) — NOT you. Don't build the profile-page split here.

Sanity-check the box: `curl -s ifconfig.me` → `50.19.198.38` = act locally, do NOT SSH. Commit small by
pathspec; coordinator reviews, **git-tsar pushes — no silent pushes**.

## YOU OWN (this file set — and ONLY this)
- **`profile-app/web/u.php`** — the profile page: the inline `<style>` (all `lg-block`/`lg-gallery`/
  `lg-gphoto`/`lg-carousel` CSS lives here) + the inline editor/carousel `<script>`.
- **`profile-app/web/_render_blocks.php`** — the block render markup (gallery, about, header, connect, etc).

## 🔴 OFF-LIMITS — do NOT touch (Buck / map-desktop / shared)
- `profile-app/web/directory-members.php`, `directory.css`, `mobile-directory.css` — the **directory/map**
  (Buck owns mobile, the map-desktop lane owns desktop). Different surface entirely.
- `profile-app/src/*` (e.g. `Block.php`) + `profile-app/api/v0/*` — **backend**, shared with practice. If a
  task needs a backend/endpoint change, route it via coordinator. (Your FIRST task below needs none.)
- buck-coord is holding Buck off your two files while you work — keep your edits inside them.

## 🥇 FIRST TASK — gallery "Add photos" → blank tile at the end of the run (Ian)
Today the add control is a **separate button after the gallery** (`_render_blocks.php:244`,
`<button class="lg-gphoto__add" id="lg-gallery-add">＋ Add photos</button>`). Make it an **empty tile at
the end of the photo run** instead:
- **Grid mode → mostly CSS** (in `u.php`'s inline style): the add button already renders inside the grid
  after the last `.lg-gphoto` (loop at `_render_blocks.php:221-229`). Style `.lg-gphoto__add` as an **empty
  "+" tile matching `.lg-gphoto`** dimensions (dashed placeholder), not a full-width button.
- **Carousel mode → markup + JS** (the screenshot case): the add currently sits **outside** `.lg-carousel`
  (it closes at `_render_blocks.php:241`). Move it to be the **last slide inside `.lg-carousel__track`**,
  and make the carousel JS in `u.php` (slide count + dots loop) **aware of the extra slot** (don't give the
  add-slide a dot, or handle it intentionally). Verify nav/dots still line up.
- Verify both modes (CDP): owner sees the "+" tile at the end; upload still fires (`#lg-gallery-add` /
  `me-gallery` POST unchanged); non-owner never sees it.

## 🥈 SECOND TASK — discussion "Public / Member-only" toggle (Ian 6/7)
Add a profile-settings toggle: **"discussion posting: Public / Member-only"** (default **member**) → PUTs
`discussion_visibility` to the profile-app endpoint **Buck** is building (`docs/briefing-discussion-visibility.md`
§2). Desktop-only (≥641), in your front-end (`u.php` / the profile render). The actual masking of discussion
authors happens in the Hub (hub-coord) — you only own the toggle UI here. NOT the composer per-post anon
button (parked).

## Data provenance — already clean ✅
Profile data (avatar, about, socials, gallery) reads from profile-app's own Postgres (`users`,
`profile_sections`) — it IS the source system. No WP/BuddyBoss. ([[project_profile_app_identity_source]].)

## Report back (to coordinator)
`DONE · FILES · VERIFIED (grid + carousel, owner/non-owner) · NEEDS-BACKEND · BLOCKED`.
Report your session ID + outliner title for CHATS-MENU + lineage.
