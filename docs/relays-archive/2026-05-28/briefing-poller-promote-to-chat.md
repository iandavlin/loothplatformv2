# Briefing — poller (promoting from terminal to tracked chat)

You're the poller / role-writer workstream in the Looth Group strangler rollout. Your work has been happening in terminal sessions; promoting to a tracked chat so the session ID is stable, your context persists, and coordinator can record you in the chat manifest.

## You're not starting cold — read your existing state

Your handoff exists and is current. Read in this order:

1. **Your own session handoff:** [/home/ubuntu/projects/docs/SESSION-HANDOFF.md](/home/ubuntu/projects/docs/SESSION-HANDOFF.md)
   - Original 2026-05-17 checklist-run handoff + addenda for the coordination work
   - Captures: test users + passwords, infra (mailpit, CDP), 16 code changes shipped, bugs flagged, queue items, decisions
   - Plus the 2026-05-27 addenda for: briefing v2 absorption, user-context endpoint shipped, action+purge shipped, backlog burned (affiliate Save rates closed as CDP-driver artifact, cancel-immediate code-review verified, MG selectors tightened)
   - Plus the 2026-05-27 ~23:35 + ~23:50 addenda for queue burns

2. **The coordination contract:** [/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md](/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md)
   - §1 (tier vocab), §2 (`/whoami` contract + your user-context endpoint), §3e (poller out-of-WP roadmap), §3h (Stripe dormant pattern), §3i (storage architecture, sync-writer pattern), §4 (cutover sequence)

3. **The current coordinator state:** [/home/ubuntu/projects/docs/STRANGLER-SESSION-HANDOFF.md](/home/ubuntu/projects/docs/STRANGLER-SESSION-HANDOFF.md) + the [chats menu](/home/ubuntu/projects/docs/CHATS-MENU.md)

4. **Your two outstanding cross-cutting items:**
   - [briefing-stripe-poller.md](/home/ubuntu/projects/docs/briefing-stripe-poller.md) (your original on-boarding — for reference)
   - [briefing-patreon-adapter.md](/home/ubuntu/projects/docs/briefing-patreon-adapter.md) (your post-BATCH-04 work)

## What you own

| Domain | Status |
|---|---|
| `wp_capabilities` writes for looth1..4 (Arbiter, sole writer) | shipped, stable |
| `GET /wp-json/looth-internal/v1/user-context/{id}` (tier + provenance + capabilities) | shipped on dev |
| `do_action('looth_tier_changed', ...)` + PurgeNotifier | shipped on dev |
| Stripe-side polling, gift codes, refund/cancel, etc. | shipped, stable (lg-stripe-billing checklist run) |
| **Patreon adapter** (live-side reader, returns same user-context shape) | **NEXT — blocked on BATCH-04 paste-back** |
| **`LG_PROFILE_APP_URL` config** (PurgeNotifier currently hardcodes dev host) | small, pre-cutover |
| Poller out-of-WP roadmap (post-cutover hygiene) | deferred |

## What you don't own

- profile-app's `/whoami` endpoint (they own it; you provide the tier-source endpoint they call)
- BB-mirror, archive-poc, lg-shell domains
- Live deploys (Ian + coordinator)

## How you work

- **In-lane work doesn't need coordinator round-trip.** Burn the queue (any remaining checklist items, security findings, hygiene). Ping coordinator only when something might affect another chat or you hit a cross-cutting blocker.
- **Coordinator routes via Ian.** Ian is the message bus between you and other chats.
- **Update your SESSION-HANDOFF as you go.** It's at `/home/ubuntu/projects/docs/SESSION-HANDOFF.md` — has been getting addenda; eventually rotate to `docs/handoffs/YYYY-MM-DD-...md` and write fresh if it gets too long.

## Immediate next moves (when BATCH-04 lands)

Coordinator (via Ian) will paste BATCH-04 output (live's role-writer code + collision grep). With that in hand:

1. Spec the Patreon adapter (briefing-patreon-adapter.md has the contract you're targeting — same `user-context/{id}` shape, reads from `lg-patreon-onboard` data instead of your existing source rows)
2. Decide single-plugin (extend your existing) vs separate-plugin (`lg-patreon-internal-rest`) — your call per the briefing
3. Add `LG_PROFILE_APP_URL` defined-constant pattern so PurgeNotifier works on live
4. Ship to dev, smoke test, hand off to cutover chat for the live deploy step

## Reporting back — canonical format

Use the symmetric report-back format every time you have something for coordinator. Lead with chat name, single code block with absolute paths:

```
**poller → coordinator:** <one-line subject>

/home/ubuntu/projects/docs/SESSION-HANDOFF.md
/home/ubuntu/projects/<any-specific-file-relevant-to-this-report>.md
```

Files do the substance; the message is a pointer to them. Optional 1-3 line inline summary if it saves coordinator a read.

**At spawn:** report your session ID + outliner title once so coordinator can update `CHATS-MENU.md`. If you can't see your own session ID, say "session ID unknown, Ian please capture."

Full canon: see `~/.claude/projects/-home-ubuntu-projects/memory/feedback_chat_report_back_format.md` (auto-loaded into your context via MEMORY.md).

## Reporting back at spawn

Ian will paste this briefing as your first message. When you've read everything: confirm receipt using the format above, note any drift between your existing handoff and current state. Coordinator will record your session ID in the chats menu.

— coordinator
