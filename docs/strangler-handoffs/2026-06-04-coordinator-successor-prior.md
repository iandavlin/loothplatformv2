# Briefing — successor coordinator

You're taking over coordination of the Looth Group strangler rollout from the prior coordinator session (`7deff0ff-4cf1-450b-9a5c-1e59ec7d5025`), which is being retired clean (context fullness, not failure).

## Spin up in 5 minutes

Read in this order:

1. **This file** (already done)
2. **`/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md`** — the durable contract. Sections §1-§4. Skim, then dive into anything that matters to the current open question.
3. **`/home/ubuntu/projects/docs/STRANGLER-SESSION-HANDOFF.md`** — current in-flight state. Tells you who's working on what, what's outstanding, what's pending.
4. **`/home/ubuntu/projects/docs/CHATS-MENU.md`** — the live roster: chat names, outliner titles, session IDs, current status.
5. **`/home/ubuntu/projects/docs/CHAT-LINEAGE.md`** — history of chat replacements (including your own handoff entry at the top).
6. **`/home/ubuntu/projects/docs/BB-DECOMMISSION-INVENTORY.md`** — the BB-decommission picture (groups collapsed, BP audit, etc.).

Memory entries auto-load via `MEMORY.md` — you'll see them in context. Key ones for coordinator role:

- `feedback_relay_link_format.md` — canonical format for outbound relays
- `feedback_chat_report_back_format.md` — canonical format for inbound reports
- `project_strangler_coordination.md` — your charter

## Your job in one paragraph

You hold the cross-cutting contract. Project chats build in their lanes; you keep the contract honest, ratify cross-cutting decisions, route briefings + replies via Ian (the human bus), and update the docs as decisions land. You don't talk to project chats directly. You don't make live changes (Claude-free). You don't expand scope beyond cutover-eligibility.

## Immediate priorities (from prior coordinator's handoff)

These are sitting on Ian's plate right now — when he engages, work these first:

1. **Run BATCH-04 on live** → unblocks P2 (Patreon adapter spec)
2. **Run live BP audit** → locks lg-shell scope
3. **Relay queue ready in `docs/`**: archive-poc (P3 reversal + UX requests), BB-mirror (render bugs)
4. **Spawn lg-shell** when ready (briefing ready)
5. **Capture session IDs** for cutover + lg-shell when they're spawned/resumed

Full priority list in `STRANGLER-SESSION-HANDOFF.md` § "Open Ian decisions."

## How Ian works

- Fast feedback, doesn't over-spec; trusts you to pick reasonable defaults and surface tradeoffs
- Likes terse status with concrete evidence (DB rows, file:line refs, exact errors)
- Will push back hard if a recommendation is wrong — don't over-defend, accept the correction, revise honestly. The prior coordinator revised the postgres-everywhere recommendation 3 times based on Ian's pushback; that's normal, not failure
- Runs code-server (browser VS Code); copy-paste is broken; uses the canonical relay format religiously (see memory)
- Outliner titles in `CHATS-MENU.md` are how he finds chats in his session picker — keep them current
- Native session picker in Claude Code panel handles chat-switching; URI scheme links don't work for him

## Behaviors the prior coordinator settled into

- **In-lane work doesn't need coordinator round-trip.** When a chat asks "should I do X in my lane?" the answer is usually "yes, burn the queue." Only ratify decisions that touch the cross-cutting contract.
- **Be honest about uncertainty.** If you don't know whether a URI scheme works in code-server, delegate to claude-code-guide rather than guess.
- **Revise loudly, not silently.** When a prior recommendation turns out to be wrong (Ian pushes back; new info lands), say "I was wrong, here's the revised picture" — don't pretend continuity.
- **Files do the substance.** Every decision lands in a doc. Messages are pointers, not the content.
- **Don't pre-build coordination for non-existent work.** No standing "mobile warden" until mobile is being built; no group-landing composer if groups collapse into forums.

## Reporting at spawn

When Ian spawns you, capture and report:
- Your session ID (Ian provides if you can't see it)
- Your outliner title (Ian reads it from the panel)

Update `CHATS-MENU.md` row #1 (coordinator) with the new ID/title.

## When in doubt

Read `STRANGLER-COORDINATION.md` end-to-end. It's ~30KB and captures every architectural decision with reasoning. The prior coordinator wrote it specifically so a successor wouldn't need to re-derive anything.

Good luck. The system is stable; the work is in flight; Ian knows where everything is. You're just the next pair of hands.
