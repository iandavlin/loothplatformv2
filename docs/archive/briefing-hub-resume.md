# Lane briefing — Hub desktop SURFACE (resume, 2026-06-07)

You're the **Hub desktop / feed-surface** lane (bb-mirror). Fresh chat resuming a stable, shipped lane —
successor to `0ad40ab7`. The original missions (unified feed, reactions surface, action-bar flash) are
**DONE + dev-proven**. You hold the desktop Hub surface and pick up open items as they're greenlit.

Sanity-check the box first: `curl -s ifconfig.me` → `50.19.198.38` = act locally, do NOT SSH. You work in
the canonical tree (`/home/ubuntu/projects/bb-mirror/` + `archive-poc/`), NOT a worktree. Commit small by
pathspec; coordinator reviews, **git-tsar pushes — no silent pushes**.

## Read for the architecture (do this before touching anything)
- **`docs/hub-mobile-desktop-split.md`** — THE contract. One server-rendered feed, two CSS layers split
  at 640px on a FLAT shared `fc-*` markup. CSS-ARRANGE, never JS-reshape (reshape = the flash we killed).
- `docs/CHATS-MENU.md` — the roster.

## What's already shipped (don't rebuild)
- **Mobile/desktop split is LIVE.** `_feed.php` renders the flat `fc-*` card contract (coordinator-
  governed). **You own `forums.css @media (min-width:641px)` = desktop.** Buck owns `mobile-hub.css ≤640`
  + `mobile-hub.js` (behaviors only). **Never touch mobile-hub.\* or styles below 641.**
- **Reactions — done, real, WP-free.** Engine = `discovery.card_reactions` + `comment_reactions`,
  BuddyBoss palette (`archive-poc/web/reactions/`). Replies are now a reactable target (`ec9a30e`).
  **Count contract:** ONE store per target, **server-rendered count**, optimistic UI reconciles. Don't
  add a second count source.
- Action-bar flash fixed (server-rendered bar, JS only wires).

## Open items you may pick up (status gated — confirm with coordinator before starting)
- **Cooler-card redesign — HELD; fire only on Ian's confirm.** Mockup approved at
  `/var/www/dev/mockups/hub-card-cooler.html`. When greenlit: add new flat regions
  (`fc-activity`/`fc-facepile`/`fc-tags`/`fc-composer` + category accent) to the contract, build desktop;
  the **persistent reply composer** is the "reply is lost" UX fix. Contract changes are announced to Buck.
- **≤640 forums.css extraction — POST-CUT.** Once Buck confirms `mobile-hub.css` coverage, delete the
  residual ≤640 card rules so forums.css ends desktop-only. Handoff:
  `bb-mirror/handoffs/2026-06-06-forums-css-640-card-rules-FOR-BUCK.css`.
- **Hot-sort repoint** — hot-sort still ranks on a stale `content_item.like_count`; repoint onto the real
  reaction store (engine hands you the subqueries). Ranking-only, non-blocking.

## Boundaries
- The flat markup contract is **coordinator-governed** — propose changes, don't unilaterally reshape it
  (Buck's mobile layer depends on it). You own desktop CSS + the engagement-bar wiring; you never build
  reaction/comment **backend** (that's the engine lane) and never edit mobile.
- Route cross-cutting/contract changes + any API need through coordinator.

## Report back (to coordinator)
`DONE · FILES · VERIFIED (desktop unchanged + no flash + counts server-rendered) · BLOCKED`.
**Report your session ID + outliner title** so coordinator logs you in CHATS-MENU + lineage.
