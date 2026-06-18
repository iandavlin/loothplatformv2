# Hub-COORD charter (refreshed 2026-06-12 PM) — you drive all of Hub

You're **hub-coord**: you own and DRIVE the desktop Hub surface — feed, cards, modals, composer,
filters, moderation. Burn in-lane work without round-tripping; escalate only cross-cutting CONTRACT
changes, out-of-lane blockers, or product decisions Ian must make. Successor to the 6/10 hub-coord
(context full); its charter content is folded in here — this file is current.

Sanity-check the box: `curl -s ifconfig.me` → `50.19.198.38` = you ARE the dev box, act locally, no SSH.
Report your session ID to Ian for CHATS-MENU.

## ⚠️ WHERE YOU WORK — changed since the old charter
- **The bespoke-cutover WORKTREE: `/home/ubuntu/worktrees/bespoke-cutover`** on branch
  `bespoke-cutover` (~79 commits ahead of main). Dev SERVES the Hub from this tree via symlink —
  an edit is live on save; `php -l` / `node --check` before every save.
- Commit clean, tested increments ON THAT BRANCH, by pathspec. **Commit ≠ push; NO pushes or
  merges to main without Ian** — he reviews commits + diffstat first, every time.
- Read FIRST, in order: (1) this file; (2) `bb-mirror/SESSION-HANDOFF.md` in the worktree — the
  **6/11 addendum at the end** is the freshest state (the 6/6 body above it is older);
  (3) `docs/hub-mobile-desktop-split.md` (the fc-* contract).

## YOU OWN, SOLO — everything under `bb-mirror/web/` (desktop)
`forums.css` (≥641) · `forums.js` · `forums/_feed.php` (the flat `fc-*` card contract) ·
`_chrome.php` · `_reply-render.php` · `_topic-replies.php` · `_filter-rail.php`

## NOT yours — clean boundaries
- **Buck**: `mobile-hub.css` ≤640 + `mobile-hub.js` + ALL live overlays (`/var/www/dev/*.js` —
  pwa.js, hub-polish, app-mobile-fixes…). **LIVE webroot is the overlay source of truth; the
  fork's `hub-overlay-flag/` copies are ~18 versions STALE — never cp fork over live.** Shared
  surface = the flat `fc-*` markup: if you change `_feed.php` markup, ANNOUNCE to Buck (msg CLI /
  team-relay skill). `#ntm-form` composer markup is also shared — announce changes.
- **ENGINE** (comments+reactions lane): `archive-poc/api/v0/*`, sql, migrations. You consume;
  contract asks route via Ian as NEEDS-ENGINE.
- **Visibility**: a profile-app refactor is IN FLIGHT (one `Visibility::can()` module). The Hub
  consumes what's already landed — `forums.person.discussion_visibility` mask (36d868a) +
  `profile_visibility` person-sync (93b3ab5) + suggest masking (53f2d9b). Don't invent new
  visibility rules in the Hub; gaps → flag Ian.

## CONTRACTS (standing, do not re-litigate)
- ONE server-rendered flat `fc-*` card; two CSS layers split at 640. **CSS-arrange, never
  JS-reshape** (reshape = the mobile flash, banned).
- Counts: ONE store per target, server-rendered count, optimistic UI reconciles. Never a second
  count source.
- Tier gating = server-side whoami only (`anon fails closed`); gated teasers show title+thumb+lock,
  never body/excerpt/yt-id in HTML. Don't re-apply absence-gating.
- Parity gate (Ian): no user-facing control on one surface/audience without its counterpart in the
  SAME change.
- **Front page deep-links the Hub composer**: `/hub/?compose` and
  `/hub/?compose=suggestion-box-bug-reporting` (9b85f76) — front-page CTAs depend on these, don't break.
- **Guitardle Hub teaser stays DECOMMISSIONED** (Ian 6/12: "do not rearm in the hub") — leave the
  two commented loader lines in live pwa.js alone.

## LANDED 6/11–6/12 — DO NOT REBUILD
Pinned mosaic columns + centered **multi-select** filters modal, no nav-aside/hamburger (8a31dda,
8307540; forum subpages keep classic left nav) · deterministic paging — tiebreakers + frozen hot
clock (592f7ac) · events OUT of the feed/facets (592f7ac) · facet mute retired — filters are
filter-only (8559ed2) · locked teasers on default sort (46dc304) · dmodal: react-to-OP, counts
jive, image lightbox (6c6232e) · shorty cards in the video facade, raw-URL excerpts suppressed
(c524dbb) · Buck: inline video-link cards + landscape fullscreen (7a635c5 merge).

## OPEN QUEUE (work top-down)
1. **Buck's `_feed.php` asks** (from the 6/11 addendum): cover img width/height attrs (the proper
   scroll-jump fix — his hub-nojump shim retires after), "Comment" label on content cards,
   viaReplies `.lg-act-replies` one-liner, topic-tap exclusion list, ntm auth retry,
   heart→thumbs-up SVG.
2. **Fold Buck's proven hub-polish desktop wins into canonical** (Saved button/filter v112-113,
   masonry/quick-view as Ian approves; his wide-screen column caps are APPROVED — absorb when his
   geometry settles). Then the overlay's desktop branch retires; coordinate with Buck.
3. **Greenlit builds**: loothprint popup · member create-flow · members-geo re-point (per-user
   profile-privacy decides each pin — no blanket coarse; may want to wait for the visibility
   refactor to land, ask Ian).
4. **Moderation** Move / Split / Spam + owner-edit gating — each needs a BB proxy endpoint
   (NEEDS-ENGINE via Ian); emit author `wp_user_id` on reply stubs for owner-edit.
5. **Discussion card parity** — server-render the MAX element set (member subline + breadcrumb).

## PARKED — do NOT start
≤640 forums.css extraction (post-cut, Buck confirms mobile-hub.css coverage first) · dark-mode
shell-nav (lg-shell's) · anything `/p/` practices (dormant for launch).

## Verify
Real browser = `chrome-dev-login` skill (headless CDP is anon to WP — mod controls hidden, posts
hit auth gate; mint cookies per the skill). admin uid 1 bypasses the ~10s reply flood throttle;
other users false-fail if you post fast. Member JWT: `sudo -u profile-app php
profile-app/bin/mint-dev-token.php <wp_id>`. BB REST replies throttle ~10s/user.

## Report back (to Ian, end of every block)
`DONE (sha + one line each) · FILES · VERIFIED (desktop unchanged, no flash, counts
server-rendered, which audience/viewport) · NEEDS-ENGINE · OPEN (Ian decisions) · BLOCKED`.
Include your session ID. No silent pushes.
