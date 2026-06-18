# Briefing — cutover chat

You're a new workstream in the Looth Group strangler rollout. Your job:
inventory live, design the cutover from dev-shape to live-shape, and
ship a rollback plan for every step. Peer to profile-app, archive-poc,
BB-mirror. The coordination chat is your routing partner; Ian is the
human-in-the-loop bus.

## Read first (in order)

1. [/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md](/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md)
   — the target architecture you're cutting over to. §1 (tier vocab),
   §2 (`/whoami` contract), §3d (BB inventory), §3e (poller-shrink
   roadmap), §3f (BB-mirror scope), §3g (nginx snippet pattern), §4
   (cutover sequence).
2. [/home/ubuntu/projects/docs/STRANGLER-SESSION-HANDOFF.md](/home/ubuntu/projects/docs/STRANGLER-SESSION-HANDOFF.md)
   — current state of the cross-chat coordination: who has shipped
   what, what's outstanding.
3. The individual project handoffs:
   - [/home/ubuntu/projects/profile-app/SESSION-HANDOFF.md](/home/ubuntu/projects/profile-app/SESSION-HANDOFF.md)
   - [/home/ubuntu/projects/archive-poc/SESSION-HANDOFF.md](/home/ubuntu/projects/archive-poc/SESSION-HANDOFF.md)
   - [/home/ubuntu/projects/bb-mirror/SESSION-HANDOFF.md](/home/ubuntu/projects/bb-mirror/SESSION-HANDOFF.md)
   - [/home/ubuntu/projects/docs/SESSION-HANDOFF.md](/home/ubuntu/projects/docs/SESSION-HANDOFF.md) (the lg-stripe-billing / poller handoff)

## Critical constraint — Live is Claude-free

You **cannot** SSH to live, run commands on live, or push files to live.
All live work is:

1. You propose a **read-only** command (or batch)
2. Ian runs it on live, pastes output back
3. You fold into the inventory doc

Write commands must be flagged explicitly:

```
WRITE COMMAND — review before running
Rollback: <exact rollback command or recovery procedure>
Risk: <what breaks if this goes wrong>
```

Never assume a command ran. Always wait for Ian's pasted output.

Live host (per memory): `54.157.13.77` — production. Dev (this box) is
`50.19.198.38`. If unsure where you are: `curl -s ifconfig.me`.

## Scope — narrow first

**v0 inventory:** only the surfaces strangler-apps will take over.

- WP routes: `/profile/edit`, `/u/<slug>`, `/p/<slug>`, `/members/*`,
  `/archive`, `/forums`, `/directory/members`, `/wp-json/*`
- WP plugins/themes/mu-plugins relevant to those routes (BuddyBoss,
  bbPress, lg-patreon-stripe-poller, lg-member-sync, any active theme)
- BB data scale (groups, members, xprofile fields, forum posts,
  activity feed depth)
- Cron jobs / systemd timers that touch those surfaces
- Cloudflare cache state on those URLs
- Active sessions / cookies that need to keep working through cutover

**Not v0 (defer):** SSL certs, DNS, monitoring, backup state, the
full nginx site map. Reassuring to know, but doesn't change at cutover.

## First deliverables

1. **Mine local artifacts before asking for live commands.** Lots of
   what we *think* live looks like is already on this box:
   - `/srv/lg-stripe-billing/PROD-CUTOVER.md` — decisions parked for
     cutover
   - `/home/ubuntu/projects/archive-poc/deploy/LIVE-DEPLOY.md` —
     archive-poc's deploy procedure
   - `/home/ubuntu/projects/lg-layout-v2/` deploy scripts
   - Memory entries (see `/home/ubuntu/.claude/projects/-home-ubuntu-projects/memory/MEMORY.md`):
     - `project_dev_migration_20260515.md` — what moved from 54.157.13.77 to claude.loothgroup.com
     - `reference_lg_layout_v2_deploy.md` — current live-deploy mechanic
2. **Build `/home/ubuntu/projects/cutover/LIVE-INVENTORY.md`** — what
   we *think* live looks like, with a delta list of what we need to
   verify or fill.
3. **First batch of read-only commands for Ian** — grouped by topic
   (nginx, WP, BB, cron, services). Keep each batch small (5-15
   commands) so Ian can paste, run, paste back in one pass.

## Reporting back

Update `/home/ubuntu/projects/cutover/SESSION-HANDOFF.md` as you go —
that's your durable state. When you need a cross-chat decision (e.g.
"this finding changes the cutover sequence in §4"), ask the
coordinator chat via Ian. Don't edit `STRANGLER-COORDINATION.md`
directly — coordinator chat owns that.

When the inventory is complete enough to draft cutover steps, ping
coordinator. Cutover plan itself will live in
`/home/ubuntu/projects/cutover/CUTOVER-PLAN.md` — write it incrementally,
one step at a time, each with explicit rollback.

## What you do NOT own

- Code changes in any of the four project lanes (those chats own
  their own code)
- The target architecture (coordinator owns the contract; you cut
  over TO it, you don't redesign it)
- Live writes — those are Ian's, never yours

## Handoff rotation

When superseding `SESSION-HANDOFF.md`, rotate to
`handoffs/YYYY-MM-DD[-suffix].md` per
[/home/ubuntu/projects/CLAUDE.md](/home/ubuntu/projects/CLAUDE.md).

## Opening move for the first session

1. Read the three docs in §"Read first" above.
2. Read the mining sources in §"First deliverables" item 1.
3. Write the v0 of `LIVE-INVENTORY.md` — what we think live looks like,
   with `[VERIFY]` tags on anything inferred rather than known.
4. Queue the first batch of read-only commands for Ian (~10 commands,
   one topic — start with nginx, since that's the routing layer everything
   else depends on).
5. Update your `SESSION-HANDOFF.md` and report back to coordinator.

Good luck. Measure twice, cut once. The cost of an outage at cutover
is much higher than the cost of one more inventory pass.
