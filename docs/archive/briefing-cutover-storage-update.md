# Briefing — cutover, storage architecture update

Ian ruled on storage. The "two storage stacks for the strangler tier"
honest-statement you'd planned to surface in your inventory is no
longer accurate. New architecture in
[STRANGLER-COORDINATION.md §3i](/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md):

**One postgres server, three schemas.** All three strangler surfaces
share the existing postgres instance:

- `profile_app` — already there
- `forums` — BB-mirror migrates from SQLite at cutover
- `discovery` — archive-poc migrates from SQLite at cutover

## What changes in CUTOVER-PLAN.md

Add to the sequence (rough position 3-4, after profile-app's schema
provisioning):

- Step 3a: pgloader archive-poc SQLite → `discovery` schema. Swap
  archive-poc app config from sqlite DSN to pgsql DSN. Restart its
  FPM pool. Smoke-test `/archive-poc/` rendering.
- Step 3b: pgloader BB-mirror SQLite → `forums` schema. Same pattern.
  Smoke-test `/forums-poc/` rendering.

Both migrations run in seconds (SQLite datasets are tiny). Each has
straightforward rollback: re-swap DSN to point back at the local
SQLite file (still on disk until manually removed), reload FPM pool.

## Other implications for your plan

- **Postgres install/provisioning** is now step 1 of cutover, not
  optional. Ian's lean is on-box install (matches dev simplicity).
  RDS is a defensible alternative if HA matters; doesn't need to
  block planning.
- **Schema creation** is part of postgres provisioning. Three schemas
  with appropriate role grants. Each strangler app connects with its
  own postgres role that only sees its own schema.
- **Backup story** simplifies — one pg_dump cron handles all three
  schemas at once, vs three independent backup mechanisms.
- **Migration rollback** for each migration step is: revert the DSN
  swap, reload FPM. The SQLite files stay on disk through the soak
  window (don't delete them until everything's been on postgres for
  a week or two).

## What this doesn't change

- BATCH-04 still runs (its purpose remains: gather live role-writer code
  so poller chat can write the Patreon adapter)
- B-now/A-later cutover pattern from previous briefing still stands
- Per-strangler scope, render layer, write paths all unchanged
- nginx snippet pattern (§3g) unchanged

## What I need from you

1. Update CUTOVER-PLAN.md (when you draft it) with the postgres-shared-instance reality
2. Confirm BATCH-04 output paste-back when Ian runs it on live (still pending — kicks off Patreon adapter spec)
3. When you're ready to draft step-by-step plan, surface to coordinator for review before locking it in

## What you might want to ask Ian when convenient

- Postgres install on box (recommended) vs RDS
- Cutover window timing (this is the scariest moment; needs a low-traffic window — overnight Sunday or similar)
- Cloudflare cache-bust strategy at flip
- Communication plan for users (anything user-visible at cutover?)
