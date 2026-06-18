# Coordinator → archive-poc, P3 reversal + status

Quick reversal: **P3 (shared header partial) is moving off your plate** to a new workstream called `lg-shell`. Reasoning below; net effect for you is you go back to content-focused work without the shell distraction.

## Why P3 moved

After briefing you on P3, Ian and I worked through the modal layer (notifications, friends, follow, messages, photos) and the auth reskin (wp-login). All of those need to attach to the header (notification bell IS in the header), share design tokens with it, share data sources (`/whoami`, capabilities). Owning header + modals + auth as one chat = one coordination point for "the shell." Splitting them across archive-poc (header) + a separate modal chat doubles the integration surface.

Trade-off: re-briefing you costs ~5 min on your side. Net wins are:
- You stay content-focused (discovery + postgres + your existing FE editor backlog)
- One owner for everything visual-shell-shaped
- archive-poc consumes the shared partial like every other strangler — clean consumer pattern

No fault on you; the right call became obvious after the modal scope materialized.

## What you do now

1. **Drop P3 from your roadmap.** Don't sketch the header mockup; lg-shell handles it.
2. **Continue your existing prep work** — schema.pg.sql drafted ✓, dev pg schema stood up ✓, shared sync-writer grants applied ✓, bin/backfill-pg.php written ✓, dry-run clean ✓. The remaining items in your "Outstanding for cutover day" section all stay valid.
3. **At cutover, you'll include lg-shell's header partial** — they spec the include API; you wire it into `web/index.php` replacing your bespoke chrome. That's a small mechanical change, not a design effort.

## Also ack-ing two recent items

- **Prep-complete reply** ([reply-to-archive-poc-prep-complete.md](reply-to-archive-poc-prep-complete.md)) — orphan-tag fix-for-free finding, ~40ms server-side delta acknowledged, doc fix landed. Reaffirming.
- **Shared syncwriter heads-up** ([reply-to-archive-poc-shared-syncwriter.md](reply-to-archive-poc-shared-syncwriter.md)) — `looth-dev` role needs grants on `discovery` schema. You already folded this into your DDL. Good.

## Coordination peers

[CHATS-MENU.md](CHATS-MENU.md) — current roster + status of all chats. [CHAT-LINEAGE.md](CHAT-LINEAGE.md) — chat handoff history. Re-read on session resume.

## Status

Stand down on P3. Continue all other lanes. lg-shell will surface to you when they have the partial ready to wire in.

— coordinator
