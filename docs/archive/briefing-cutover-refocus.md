# Briefing — cutover refocus (B-now, A-later, dormant Stripe)

Ian ruled on the Path A/B/C question. New picture:

**B-now, A-later, with poller shipped dormant.**

1. **Strangler ships to live now** with the Patreon adapter feeding
   `/whoami`. New site goes live; users see unified surfaces; billing
   unchanged.
2. **Stripe poller ships in same cutover but dormant** — code+schema
   present, no Stripe credentials → no Stripe source rows → Arbiter
   sees only Patreon-source data via the adapter. Effectively disabled
   by absence, no feature flag.
3. **Stripe-enable later** is a config change (drop in creds), not a
   deploy. Low-cash real transactions verify the pipeline before
   opening to real customers.

Full pattern in
[STRANGLER-COORDINATION.md §2 + §3h + §4](/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md).

## What this changes for you

**Your A/B/C analysis collapses.** The decision is made. CUTOVER-PLAN.md
can now be drafted around B-now/A-later as the target.

**BATCH-04 is still useful** — its output (live role-writer code) is
what the poller chat needs to build the Patreon adapter. Hand the
paste-back to coordinator who'll forward to poller. Don't draft the
A/B/C analysis section anymore; reframe BATCH-04's purpose as
"specification input for the Patreon adapter."

**Postgres question (still open):** Ian's not yet ruled on
on-box-install vs RDS for profile-app's prod DB. Both work; on-box is
simpler. Park as a pending decision for CUTOVER-PLAN.md.

## What goes into CUTOVER-PLAN.md

Step-by-step with explicit rollback per step. Rough sequence:

1. Postgres provisioned on live (on-box per Ian's eventual ruling, or
   RDS endpoint configured)
2. profile-app deployed to live + nginx routes + FPM pool
3. Shared header partial deployed + included by archive-poc, lg-layout-v2,
   bb-mirror
4. `/etc/lg-internal-secret` provisioned on live
5. `LG_INTERNAL_SECRET` + `LG_PROFILE_APP_URL` defines added to wp-config
6. Poller plugin deployed (dormant — no Stripe creds)
7. Patreon adapter deployed (shipped with poller or as separate plugin
   per poller chat's call)
8. nginx `location ^~ /wp-json/looth-internal/` route added
9. profile-app slice 4 migration runs (`bin/migrate-from-xprofile.php`)
10. nginx flips `/profile/edit`, `/u/`, `/p/`, `/directory/members` etc.
    to profile-app
11. bb-mirror mu-plugin deployed; backfill runs
12. nginx flips `/forums` to bb-mirror (or stays at `/forums-poc/` for
    soak window)
13. lg-viewer-tier behavior verified end-to-end (cookies, /whoami,
    consumer surfaces)

Every step needs a rollback. Most are "remove the include directive
and reload nginx" or "deactivate the plugin via wp-cli." Profile-app
slice 4 migration is the tricky one — it'll need its own
rollback-snapshot pattern (DB dump before run, restore on abort).

## Open questions you can now close or queue

- ✅ Path A/B/C — closed, B-now/A-later
- ⏳ Postgres on-box vs RDS — Ian to rule
- ⏳ Patreon adapter packaging — poller chat to decide (single-plugin
  vs separate)
- ⏳ Cutover window timing — Ian to schedule (this is the
  scariest moment; needs a low-traffic window)
- ⏳ Cloudflare cache-bust strategy at flip — needs CF API token + the
  list of URLs to invalidate at each step
- ⏳ Memory updates (Rank Math active, dev.loothtool stale cron) —
  small cleanup, surface to coordinator

## What you don't own

- Patreon adapter code — poller chat
- profile-app slice 4 migration — profile-app chat
- bb-mirror backfill on live — bb-mirror chat
- The Stripe-enable later transition — that's a separate workstream
  Ian opens when ready

You orchestrate the cutover *sequence* and the *rollback playbook* and
the *go/no-go checklist at each step*. The actual code lives in lanes.

## Reporting

Update SESSION-HANDOFF.md as inventory + plan evolves. Ping coordinator
when CUTOVER-PLAN.md is ready for review or when a finding changes
the cross-cutting architecture.
