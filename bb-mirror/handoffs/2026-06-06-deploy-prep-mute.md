# BB-mirror / Hub — Session Handoff (2026-06-06 — deploy-prep: flash closed, cut-blockers verified shippable)

Prior handoff rotated to `handoffs/2026-06-03-stream-replies-reply-endpoint.md`.

> **THE board for this lane is `docs/hub-deploy-roadmap.md`** (Done / Cut-blocking / Post-deploy).
> This handoff is the session state; the roadmap is the standing plan. Read the roadmap first.

> **Direction note (supersedes the old handoff):** /hub/ is NOT being retired for a separate
> /stream/ — /hub/ **is** the unified feed destination now (forum threads ⋃ discovery content,
> one cross-schema query). The old "don't invest in /hub/" framing is dead.

## One-page-Hub proto + polish (2026-06-06 cont.) — POST-DEPLOY, flag-gated
Behind `?proto=cards` (sticky; `?proto=off` clears). Default feed untouched without the flag.
- **Discussions = no click-through:** click a topic card (incl. its title) → expands to MAX in place
  (single-open): full body + full thread + engagement bar + **inline reply composer**. **CPTs click
  through** to the post (Ian 6/6 — don't inline them).
- **Moderation (in-feed, admin):** per-reply **Edit + Trash** on thread stubs, revealed only under
  `.feed--can-moderate` (auth `can_edit_others`); PUT/DELETE `/reply/{id}`, server re-checks caps.
  Next: Move/Split/Spam (need BB proxy endpoints); reactions = comments+reactions lane.
- **Engagement bar** server-rendered on topic cards (comment count real; reactions=our unicode palette
  stub 👍😮😂🧠, share/save=stub → see roadmap fast-follows incl. Save-as-rail-filter in discovery).
- **Fixes (ship-worthy, some broader than proto):** reply line-breaks (format_snippet `$preserve_breaks`
  + closure-capture bug fixed); full replies in the thread (was 200-char capped); card colour-states
  cordoned to Hub theme (`--lguser-*`→`--lg-*` aliases); shell chrome insulated from the Hub theme
  (`.lg-chrome`/`.lg-chrome-foot` pin their palette); card overflow at large text (feed grid `minmax(0,1fr)`).
- **Webroot (NOT git, backed up):** `hub-polish.js` v51→v52 (composer category chips now preserve the
  forum `<optgroup>` nesting); `pwa.js` iframe guard (no app-shell bottom-nav/shop in the §4c comments
  iframe). Backups beside each + `pwa.js.bak-*` / `hub-polish.js.bak-*`.
- Mockup: `/mockups/hub-card-tiers.html` (tiers + expand-in-place, per-type).

## Shipped this session (newest → oldest)
- **e5072f4** Server-render empty filter-row omission (`_filter-rail.php` → `.hub-rail__row--empty`
  + mobile-only CSS); `hideEmptyFilterRows` now a no-op.
- **42e6f57** Server-render card layout + sort bar (every card carries `data-lg-card="1"` + polished
  meta-top from PHP; Fresh tab / Filters proxy chip / New-post clone + tagline server-rendered).
  Kills the DOM-recompose flash by construction. `relayCard`/`restyleSortBar`/`wireFreshPill`/
  `relocateFilterToggle`/`addTagline` are now no-ops or behavior-only.
- **97d9d8e** Absorbed hub-polish.js visual CSS → `forums.css` (no first-paint flash).
- **Webroot (not git, buck/ubuntu-owned):** `hub-polish.js` v50→v51 (relayCard wires behaviors for
  pre-rendered cards; relocateFilterToggle wires the proxy chip; hideEmptyFilterRows no-op;
  injection defer→async=false). `pwa.js` hub-polish ref → v51. nginx pwa.js cache-buster v2→v3
  (`/etc/nginx/sites-available/dev.loothgroup.com.conf`, reloaded). Backups beside each file.

## Cut-blocker verification (2026-06-06) — SHIPPABLE
Verified via CDP + curl (anon with gate cookie; admin = uid 1 iandavlin minted cookies):
- ✅ **No horizontal scroll** at 390 / 360 / 320; **desktop unchanged** at 1280 (scrollWidth ≤ inner).
- ✅ **Filter narrows both** discussions + CPTs: `?cat=repair` → 50/50 cards `data-cat=repair`,
  45 topic + 5 content (content OR-arm works); `?leaf=43277` (count 1) → exactly 1 card. Counts
  consistent (634→50 is pagination).
- ✅ **Comments open + post**: read endpoint 200 (anon + admin); admin GET auth → nonce; admin POST
  `/archive-api/v0/comment-post` → **200** (comment created, author Ian Davlin); **anon POST → 401**.
  Gate = WP login cookie, server-side. (Test comment cleaned from `discovery.comments`.)
- ✅ **Gating server-side**: raw `/hub/` HTML differs by auth — anon 7 content cards + 0 posting
  buttons; admin 19 + 64. Server-side absence, not a client hide. (Anon-vs-admin verified; the full
  looth1–4 per-tier matrix is profile-app's domain, not re-run here.)

## RESOLVED — mute scope (Ian 2026-06-06)
Mute is **Types (CPTs) + Categories (topics) only** — the rail's `hub-sw` sticky switches stay. There
is **NO person/author mute** (no profile-based mute, not building member-muting). Cut-blocker check
"mute collapses only the leaf" verifies the rail switches; nothing to rip out. Spec updated in
`hub-filter-nav-spec.md`.

## PARKED — Step 2b (post-deploy, Ian 2026-06-06: do NOT start yet)
Absorb hub-polish.js behaviors → canonical `forums.js`, sequenced AFTER the cut: reply system
(Buck's fix got clobbered once — fold it so it sticks), fast-filters, top-search, text-toggle, share,
`applyFreshFeed` order (server-render the order to kill the last faint flicker on bare /hub/). The
**merged dual-action rail** is also post-deploy. Retire hub-polish.js only when empty.

## NOT this lane (don't double-build)
Reactions → comments+reactions lane (Buck's emoji stub is a placeholder). Shop bubble / bottom-nav /
push → app-shell PWA layer (shop needs Ian scope). Stream-page retirement → once Hub fully covers it
(keep archive-poc content render + likes/comments APIs).

## Key files (`bb-mirror/web/`)
- `forums.css` — all Hub styling (polish absorbed here; `@media ≤640` mobile).
- `forums/_feed.php` — unified feed query + server-rendered card structure (`data-lg-card`).
- `forums/_hub-filters.php` — filter engine + facet counts (content OR-arm on `forum_label`).
- `forums/_filter-rail.php` — rail + single-open accordion + empty-row class.
- `forums.js` — behaviors (action row, §4c comments modal); Step 2b absorption target.
- `_chrome.php` — chrome, Cabin font link, tagline shell, `#lgc-modal`.

## State
66 commits unpushed on `main` (Ian + git-tsar gate the push — no silent pushes). Polish layer backed
up twice (`f378da3` + `/home/buck/webroot-backup/2026-06-06/`) — absorb aggressively, reverts are cheap.

## Test accounts (dev)
admin uid 1 (iandavlin, bypasses reply flood throttle); regular uid 1081 (subscriber, throttles).
claude_admin (1904) GONE (DB-reload casualty) → wp-cli falls back to first admin.
