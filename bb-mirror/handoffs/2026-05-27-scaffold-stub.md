# BB-mirror — Session Handoff

> **Scaffold created by coordinator 2026-05-27.** First real session
> hasn't run yet — the chat is still in planning phase. Replace this
> stub with real state on the first work session.

## What this project is

Read-side strangler for BuddyBoss forum threads. Reskins the rest of BB
(groups, messages, notifications) via shared header partial instead of
reimplementing. Lives outside WordPress as its own service (own SQLite,
own FPM pool, own nginx location `/forums/*`).

Full scope + service shape in
[/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md](/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md) §3f.

## Current state

- No code yet. Schema design, sync mu-plugin draft, and template
  sketches are next.
- Scope confirmed with coordinator 2026-05-27 (see briefing + reply
  archived in coordinator's strangler-handoffs).
- Holding on first live read until profile-app cutover (coordination
  doc §4 step 5).

## Pointers

- Coordination doc: `/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md`
- Original briefing: `/home/ubuntu/projects/docs/briefing-bb-mirror.md`
- Pattern to mirror (service shape): `/home/ubuntu/projects/archive-poc/`
- Identity contract you read: `/whoami` at profile-app, building now

## Handoff rotation

When superseding this file, rename it `handoffs/YYYY-MM-DD[-suffix].md`
and write fresh per the project schema in
[/home/ubuntu/projects/CLAUDE.md](/home/ubuntu/projects/CLAUDE.md).
