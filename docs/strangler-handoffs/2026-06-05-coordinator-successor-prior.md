# Briefing — successor coordinator (2026-06-04)

You're taking over coordination of the Looth Group strangler rollout. The prior coordinator
session (`34c73878-3c14-41f6-b56f-8d5195ea47e4`) is being retired clean (context fullness,
not failure). The system is stable; work is in flight; every material decision lives in a doc.

## Spin up in 5 minutes

Read in this order:

1. **This file** (already done)
2. **`docs/LANE-LEDGER.md`** — the live board. Status of every dispatched lane, cross-lane items,
   what's pushed this session. This is the single most current view; start here.
3. **`docs/handoff-coordinator-2026-06-03-pm.md`** — the last full handoff. Critical-state section
   first (the items below are pulled from it). Lane detail + infra changes follow.
4. **`docs/STRANGLER-COORDINATION.md`** — the durable contract (~30KB, §1-§4). Skim, then dive into
   whatever the current open question touches. Written so you don't re-derive anything.
5. **`docs/CHATS-MENU.md`** — live roster: chat names, outliner titles, session IDs, current status.
   Row #1 (coordinator) is YOU — update its ID/title once Ian gives them to you.
6. **`docs/CHAT-LINEAGE.md`** — chat-replacement history (your handoff entry is logged at the bottom).
7. **`docs/STRANGLER-SESSION-HANDOFF.md`** — older narrative snapshot (LATEST = 2026-06-01), largely
   superseded by the 06-03-pm handoff + LANE-LEDGER. Read for "how we got here" context only.

Memory entries auto-load via `MEMORY.md`. Key ones for the coordinator role:
`feedback_relay_link_format` (outbound), `feedback_chat_report_back_format` (inbound),
`project_strangler_coordination` (your charter), `feedback_buck_merge_policy`,
`project_lg_shell_header_keeper`, `project_activity_stream_launch`.

## Your job in one paragraph

You hold the cross-cutting contract. Project chats build in their lanes; you keep the contract honest,
ratify cross-cutting decisions, route briefings + replies via Ian (the human bus), and update the docs
as decisions land. You don't talk to project chats directly. You don't make live changes via the
product — BUT you are also box sysadmin `ubuntu`, so you DO wire dev nginx / FPM / sudo-queue items
that the contract assigns to coordinator. You don't expand scope beyond cutover-eligibility.

## ⚠️ Critical state — confirm these first (from the 06-03-pm handoff)

1. **VERIFY: did Buck's `dropoff-clusters` merge (7afb514) break `/directory`?** Post-merge smoke of
   `/profile-api/v0/directory-members` returned 404 — *probably wrong test path, UNCONFIRMED.* The
   merge added a `banner_url` SELECT; confirm the `/directory` page + API render on dev and the column
   exists / query doesn't 500. If broken → follow-up commit, not an unpush.
2. **Two 🔴 secrets STILL unrotated:** CF creds (pasted in an earlier chat) + a plaintext AWS key
   (`AKIA…`) in `/var/www/dev/wp-config.php`. Coordinator-owned. Rotate.
3. **Uncommitted lane work on main** (review-before-push applies): whoami lane's `archive.js` repoint +
   `stream-more`/`rows-more` gating fix + pilot_pro PG bridge; perf-czar's ingest image fix; lightbox
   `engine/assets/lg-front.js`; comments-lean's dequeue; the CPT standalone-header identity fix.
4. **devmsg panel patch needs persisting:** live-refresh fix is in the *installed* extension copy only.
   Rebuild the vsix (`/opt/devmsg-extension`) + reinstall to survive reinstall + reach other team users.
5. **pro-gate held for Ian** (`buck/profile-public-pro-gate` 53b2a0a): policy approved + fail-closed,
   but TESTING-not-canonical (changes the "Ian FINAL" header-ceiling model). Awaiting Buck's
   pilot_pro clamp+403 test → then merge. Buck merge policy is standing (see memory).

## Coordinator-owned pending (sysadmin hat)

- Apply the **stripe-pages single-router nginx location** when that lane delivers (via sudo-queue).
- **nginx args-under-alias fix** — rows-more clean-URL + stream-more `?cursor` both drop args under
  the alias+rewrite; same fix; before cutover (deferred, dev-gated, not urgent).
- **Rotate the two secrets** (item 2 above).
- **SHORTINIT comments-endpoint nginx location** when comments-lean delivers (don't ship unreviewed).

## How Ian works

- Fast feedback, doesn't over-spec; trusts you to pick reasonable defaults and surface tradeoffs.
- Wants terse status in plain English, lead with the answer, concrete evidence (DB rows, file:line,
  exact errors). Skip tables/jargon unless asked.
- Will push back hard if a recommendation is wrong — accept, revise loudly, don't over-defend.
- Runs code-server (browser VS Code); native session picker handles chat-switching; URI links don't
  work for him. Copy-paste is broken → use the canonical relay format religiously (see memory).
- Outliner titles in `CHATS-MENU.md` are how he finds chats — keep them current.
- Token-conscious: prefers fewer supervised chats over autonomous spawns; keep spawned chats small.

## Behaviors the prior coordinators settled into

- **In-lane work doesn't need a round-trip.** "Should I do X in my lane?" is usually "yes, burn the
  queue." Only ratify decisions that touch the cross-cutting contract.
- **Files do the substance.** Every decision lands in a doc; messages are pointers.
- **Revise loudly, not silently.** New info / Ian pushback → "I was wrong, here's the revised picture."
- **Be honest about uncertainty.** Delegate (e.g. claude-code-guide) rather than guess.
- **Don't pre-build coordination for non-existent work.**
- **Header/footer = lg-shell's** (one canonical `/srv/lg-shared/site-header.php`); consumers populate
  `$ctx` from `/whoami` only. Cross-cutting (header/whoami/nginx/secrets) routes to coordinator.

## Reporting at spawn

Capture + report: your session ID (Ian provides if you can't see it) and your outliner title.
Update `CHATS-MENU.md` row #1 with the new ID/title, and append a `CHAT-LINEAGE.md` entry.

## When in doubt

Read `STRANGLER-COORDINATION.md` end-to-end. It captures every architectural decision with reasoning.
The system is stable; the work is in flight; Ian knows where everything is. You're the next pair of hands.
