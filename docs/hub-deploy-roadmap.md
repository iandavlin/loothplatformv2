# Hub — deploy worklist (2026-06-06)

THE single board for getting `/hub/` (the front door) deploy-ready under the new polish structure.
Supersedes `briefing-hub-fold-cpts.md` and `briefing-hub-deploy-prep.md` (ignore those).
Read `hub-up-to-speed.md` once for orientation, then work from here.

## The structure (the rule you build to)
> **Canonical paints the Hub. Server-render the content + look (forums.css + the PHP in
> `bb-mirror/web/forums/`). PWA JS (`hub-polish.js` via `pwa.js`) is ONLY for un-absorbed behaviors
> and app-shell — never for the look.**

So you edit canonical and it just works. The flash is gone because the polish is now in `forums.css`
and the card/rail structure is server-rendered. `hub-polish.js` still runs the *behaviors* on top until
Step 2b absorbs them — that's the only remaining shadow.

## Where you edit
- `bb-mirror/web/forums.css` — all Hub styling (the polish lives here now, `@media ≤640` for mobile)
- `bb-mirror/web/forums/_feed.php` — unified feed query (`forums.topic` ⋃ `discovery.content_item`)
- `bb-mirror/web/forums/_hub-filters.php` — filter engine + facet counts (content OR-arm on `forum_label`)
- `bb-mirror/web/forums/_filter-rail.php` — the rail + single-open category accordion
- `bb-mirror/web/forums.js` — behaviors (action row, comments modal); Step 2b target
- `bb-mirror/web/_chrome.php` — chrome, tagline, `#lgc-modal`

## DONE + verified (in canonical, shipped)
- ✅ Polish CSS → `forums.css`; **no first-paint flash** (`97d9d8e`)
- ✅ Card structure + sort bar + empty-rail-rows server-rendered (`42e6f57`, `e5072f4`) — no DOM-recompose flash
- ✅ Unified feed (forums + discovery content), one cross-schema query
- ✅ Category filter content OR-arm (`forum_label`/`subforum_label`) + single-open accordion rail
- ✅ Server-side gating in the feed
- ✅ Inline comments wired (`forums.js` → WP-free read endpoint)

## CUT-BLOCKING — verify before deploy (mostly confirmation, not build)
- [ ] **Filter end-to-end**: tap "Repair & Restoration" → both threads AND content show; tap a leaf
      (Acoustic Repair) → narrows both; parent/leaf counts match the feed.
- [ ] **Gating**: a below-tier viewer sees the right subset (verify as the tier, not as anon).
- [ ] **No horizontal scroll** at 390 / 360 / 320 (CDP).
- [ ] **Comments**: open + post on a content card (logged-in); anon gets 401.
- [ ] **Desktop unchanged** at 1280.
- [ ] **Mute**: muting a leaf collapses only that leaf, not the parent/siblings.

## POST-DEPLOY (Step 2b — not a cut blocker)
- Absorb `hub-polish.js` behaviors → `forums.js` one at a time: reply system, fast-filters,
  top-search, text-toggle, share, and the `applyFreshFeed` feed-order (server-render the order if you
  want it tidy). Retire `hub-polish.js` only when empty.
- **Reactions** → comments+reactions lane (BuddyBoss palette, persists). Buck's `wireReactions` stub
  is a placeholder — don't canonicalize it.
- **Shop bubble / bottom-nav** → app-shell PWA layer; shop needs an Ian scope decision before building.

## ONE-PAGE HUB — discussions read in-feed; CPTs click through (sequenced 2026-06-06)
Direction (Ian 6/6): **discussions are no-click-through** — read/comment/react in-card. **CPTs
(articles/videos/loothprints) DO click through** to their rich standalone post page. Don't expand CPTs inline.
- Mockup (spec for the build): `/mockups/hub-card-tiers.html` (Compact/Semi/Max + expand-in-place,
  per-type) and the static element vocab at `/mockups/hub-card-verbose.html`.
- Prototype slice (live, behind `?proto=cards`; `?proto=off` clears): click a **discussion (topic)**
  card → expands to max in place, single-open, lazy-loading full body + thread (`?replies=`). Click a
  **content** card → navigates to its `data-href` (click-through). CPT comments stay on the post page /
  the §4c modal; NOT inlined.
- **Still to build:** server-render the full max element set in `_feed.php` for discussions (member
  subline, breadcrumb, tags, inline composer) to reach mockup parity; **discussion inline compose**
  (reply box → BB REST `/reply` with `{topic_id, forum_id, content}` + nonce, mirroring §4b).
- **FAST FOLLOW — Save (☆):** not built today; it's a mockup placeholder (the only `Save` in code saves
  post *edits*). Needs (1) a per-user saved store, (2) a toggle endpoint (gate = WP cookie), (3) the ☆
  engagement-bar wire, (4) **"Saved" surfaced as a rail filter** (Ian 6/6) — sits alongside Type/Category,
  flip it → feed narrows to your saved set, same server-side-filter model as the other facets.
  **Ownership (resolved by the rail-filter choice):** because the unified feed query has to filter on it
  server-side, "saved" lives in **discovery** (Hub-owned, alongside comments/reactions) so `_feed.php`
  can JOIN/WHERE on it cheaply — NOT profile-app (a cross-service lookup can't gate the feed query).
- Engagement-bar stubs (don't ship as real until backed): **reactions** → comments+reactions lane;
  **save** → above; **views** → needs a counter source. Comments + inline thread are the only live parts.

## Verify / push
- Phone view: `chrome-dev-login` skill at 390/360/320. Raw server view: curl `/hub/` with the gate
  cookie (`$loothdev_token` in the dev conf) to see canonical-only output.
- Commit in clean tested increments. **66 commits are unpushed** — Ian + git-tsar gate the push.

## Safety
The whole polish layer is backed up twice (`f378da3` + `/home/buck/webroot-backup/2026-06-06/`), so
absorb aggressively — any regression reverts from a copy.
