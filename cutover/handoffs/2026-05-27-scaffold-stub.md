# cutover — Session Handoff

> **Scaffold created by coordinator 2026-05-27.** First real session
> hasn't run yet. Replace this stub with real state on the first work
> session.

## What this project is

Cutover-inventory and cutover-planning workstream for the
strangler-app rollout to live. Peer to profile-app, archive-poc,
BB-mirror — coordinated by the strangler-coordination chat.

Target architecture lives in
[/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md](/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md).
This workstream owns the path from "dev works" to "live works without
crashing." Inventory live, design step-by-step cutover, ship rollback
plan for each step.

## Current state

- No inventory yet. Briefing at
  [/home/ubuntu/projects/docs/briefing-cutover.md](/home/ubuntu/projects/docs/briefing-cutover.md).

## Critical constraint

**Live is Claude-free.** Ian copy-pastes commands to live; you cannot
SSH there directly. All inventory work is: you propose read-only
commands → Ian runs → pastes output → you fold into the doc.

## Handoff rotation

When superseding this file, rename it `handoffs/YYYY-MM-DD[-suffix].md`
and write fresh per the project schema in
[/home/ubuntu/projects/CLAUDE.md](/home/ubuntu/projects/CLAUDE.md).
