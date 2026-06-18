# Hub — up to speed (2026-06-06)

Working in the Hub (`/hub/`, the unified front-door feed). Read this first — there's a trap that'll
cost you an hour if you don't know about it.

## ⚠️ The trap: TWO layers render this page
1. **Canonical** server-side PHP/CSS/JS in `bb-mirror/web/` (below). This is what you edit.
2. **`/var/www/dev/hub-polish.js`** — Buck's ~1536-line client-side patch, injected on every page via
   `/pwa.js` (currently `?v=47`). It **overrides the Hub's look AND behavior at ≤640px, live.**
   Theme (Sage Tint / Trebuchet), fast mute-filters, a reaction-bar stub, reply-button fix, search
   typeahead — all of it is layered on top after the canonical page loads.

**Consequence:** edit `forums.css`, load the Hub on a phone → your change may not show, because
`hub-polish.js` is repainting over it. To see **true canonical output**:
- Test at **desktop width** (the polish layer is `matchMedia('(max-width:640px)')`-gated), OR
- Temporarily bypass it: load with `pwa.js` disabled, or check the raw server HTML via
  `curl -s https://dev.loothgroup.com/hub/ --resolve dev.loothgroup.com:443:127.0.0.1 -H "Cookie: loothdev_auth=<token>"`
  (token is `$loothdev_token` in `/etc/nginx/sites-available/dev.loothgroup.com.conf`).

This shadow layer is **slated for absorption** into canonical (mobile-czar coordinates; owning lanes
land each piece) — but until then, it's authoritative on mobile. Don't fight it blind.

## Canonical file map (`bb-mirror/web/`)
- **`forums/_feed.php`** — the unified feed query. `forums.topic` `UNION ALL` `discovery.content_item`
  (forum threads + content cards in one cross-schema query over the `looth` PG DB). FTS spans both
  (`topic.search_doc` + `content_item.tsv`). New/Old/Hot sort across both arms.
- **`forums/_hub-filters.php`** — the filter engine + facet counts. Type + Category filters, sticky
  mute set, chip bar. (Being changed now: the Category filter is gaining an OR-arm so it narrows
  CONTENT cards too, via `content_item.forum_label`/`subforum_label`.)
- **`forums/_filter-rail.php`** — the left rail: `hub_render_rail()`, `hub_rail_row()`, chip bar,
  view toggles. The single-open category **accordion** (leaves nested under parents) lands here.
- **`forums.css`** (web/ root, ~100KB) — Hub feed + rail + card styles.
- **`forums.js`** (web/ root, ~70KB) — action row (like/reply/expand), the §4c inline **comments**
  modal handler, reactions wiring.
- **`_chrome.php`** — site chrome; populates `$ctx` from `/whoami`; holds the `#lgc-modal` comment
  modal shell.

## Data model
- One PG DB `looth`, two schemas: **`forums.*`** (threads) + **`discovery.*`** (content + likes +
  comments). The feed is ONE query across both — no duplication.
- **Category taxonomy:** content cards now carry `forum_label`/`subforum_label` (592/708 populated,
  from the WP `shared_category` taxonomy that mirrors the forums). Forum↔content reconcile by **slug
  + a small alias map** (drift: "Tools, Spaces, Robots and Widgets" vs forum's Oxford comma;
  "Shop Organization"/"Organisation"; "Tools, Jigs and Fixtures" vs "Tools and Jigs"). ~116 content
  rows have no category (sponsor-post/benefit/event/misc) — correct, they never show under a filter.

## What's in flight (don't collide)
- **Taxonomy category filter + accordion** — hub lane wiring `_hub-filters.php` OR-arm +
  `_filter-rail.php` accordion. (`archive-poc` lane already populated the labels: a55871e/d97e63d.)
- **Inline comments** — wired into content cards (`forums.js` §4c → `/archive-api/v0/comments`,
  WP-free read). Write path = `comment-post.php` on the WP pool (gate = WP cookie, NOT /whoami).
- **Reactions** — only a client-side **visual stub** in hub-polish.js so far (generic emoji). The real
  one (persisted, BuddyBoss palette: like/ouch/shop/take-my-money/wow/lol/brain) is being built by the
  comments+reactions lane. Don't build a third.
- **~61 commits committed-not-pushed** on `main` — Ian reviews → git-tsar pushes. No silent pushes.

## Render routing + gotchas
- `/hub/` is served by **bb-mirror** (its own FPM pool, reads PG, no WP boot).
- **Managed-CPT** articles/videos/loothprints render via the **archive-poc standalone** renderer, not
  the WP template; `?lg_edit=1` forces the WP path. (Their front-end JS `lg-front.js` must stay
  vendored at `archive-poc/standalone/engine/assets/` — it has a habit of vanishing and killing the
  lightbox + video play.)
- **Gating is server-side absence** — a below-tier viewer never receives gated rows. Verify gated
  behavior as the right tier, not as anon (anon hides member content and looks like a bug that isn't).
- **Posting/auth gate** = the WP login cookie, not `/whoami` (unbridged members are anon to /whoami
  but have a valid cookie). Server 401 is the real lock.

## Verify trick
Real phone view: drive headless Chrome via the `chrome-dev-login` skill at 390px. Raw server view:
curl with the gate cookie (above). Remember which layer you're looking at.
