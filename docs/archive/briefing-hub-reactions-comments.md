# Hub reactions + comments — SURFACE lane (2026-06-06)

You own the **Hub feed's surface for reactions + comments** — the engagement bar, the reaction picker
in-feed, the comment count + modal wiring. You are the CONSUMER. The **engine** (storage, endpoints,
palette, gating) belongs to the comments+reactions lane — you call its API, you never build backend.

Sanity-check the box: `curl -s ifconfig.me` → `50.19.198.38` = act locally, don't SSH.

## 🔴 FIRST — fix the action-bar flash (urgent, it regressed)
The engagement/action bar is injected CLIENT-SIDE on load → post-paint layout shift on every card,
desktop + mobile. `buildActions()` in `/var/www/dev/hub-polish.js` does
`createElement('div').innerHTML = …action bar…; insertBefore(...)` per card.
- **FIX:** server-render the `.lg-card-actions` row in `bb-mirror/web/forums/_feed.php` so it ships
  with each card; make `buildActions` a **no-op WIRE** (attach handlers to the present bar), exactly
  like `relayCard` already does behind its `data-lg-card` guard for the meta-top.
- **VERIFY:** spam-reload at 390 AND 1280 → no bar pop-in; desktop unchanged.
This is the same class of bug Step 2a fixed — paint complete from the server, JS only wires.

## Your scope (files)
- `bb-mirror/web/forums/_feed.php` — server-render the engagement bar (reaction chips + counts, comment
  count, reply/share). **Coordinate with the Hub structure lane:** you own the engagement-bar lines;
  they own card layout. Same file → keep your edits to the bar block, commit small.
- `bb-mirror/web/forums.js` — wire the reaction picker + comment modal + counts.
- `bb-mirror/web/forums.css` — engagement-bar / picker styles (the toast/fastfilters scoped CSS too).

## The engine you CONSUME (don't rebuild)
- **Comment reactions:** table `discovery.comment_reactions` (comment_id · user · slug · ts); read
  counts via the comments endpoint; POST a reaction to `archive-poc/api/v0/comment-react.php`.
- **Comments:** read via the WP-free `comments` endpoint (the modal HTML); POST via `comment-post.php`
  (WP pool, gate = WP login cookie, nonce).
- **Palette:** the approved BuddyBoss 7-set — `tools/reaction-assets/palette.json` + the 3 custom pngs
  (ouch/shop/take-my-money); the other 4 are unicode. **Rip out Buck's hub-polish `wireReactions`
  generic-emoji stub** — replace with the real palette + endpoint.
- **Gating:** WP login cookie for writes (NOT /whoami). Counts render server-side (absence = hidden).

## Boundaries
- **Don't touch the engine** (tables, endpoints, palette definition) — that's the comments+reactions
  lane. If you need an API change, route it to coordinator (the contract).
- **Coordinate `_feed.php` + `forums.js`** with the Hub structure lane — you both touch them. You own
  the reactions/comments/engagement bits; they own card structure/redesign. Commit small so collisions
  surface fast. git-tsar merges by pathspec.

## Verify
- No action-bar flash (server-rendered) at 390/1280; desktop unchanged.
- React on a comment → row lands in `comment_reactions`, survives reload, anon write → 401.
- Comment modal opens + posts; count updates from the store.
- Reaction picker uses the BuddyBoss palette, not the generic stub.

## Report back (to coordinator)
`DONE · FILES · VERIFIED (no flash + reactions persist/gate + comments work + palette) · BLOCKED`.
Report your session ID + outliner title for CHATS-MENU.md.
