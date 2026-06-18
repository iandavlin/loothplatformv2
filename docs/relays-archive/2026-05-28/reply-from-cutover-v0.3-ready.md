# cutover → coordinator: v0.3 ready for review

Plan rewritten per `reply-to-cutover-ian-decisions.md`.

## What landed in v0.3

**Model change:** blue-green via fresh EC2 + DNS swing replaces
in-place surgery. Old box (54.157.13.77) stays up through DNS
propagation as natural fallback. Most per-step rollback collapses to
"point DNS back."

**12-step sequence:**

1. Provision new EC2
2. Bake stack (nginx 1.24+, php8.3 + pgsql + ext, postgres-16, redis, certbot)
3. Postgres db `looth` + schemas (`profile_app`, `forums`, `discovery`) + per-app roles + secret files (canonical §3i pattern)
4. Deploy strangler apps (profile-app, archive-poc, bb-mirror, lg-shell partial, lg-layout-v2, poller plugin dormant)
5. `/etc/lg-internal-secret` provisioned
6. nginx config with cookie-gate guards stripped + `^~ /wp-json/looth-internal/` exempt + LE cert via DNS-01 (so we have a cert before swing)
7. DB import — mysqldump from old box → restore on new; wp-content/uploads rsync; archive-poc backfill against new-box wp_posts; bb-mirror pgloader from dev SQLite; profile-app slice 4 migration
8. Plugin activation (dormant poller + Patreon adapter)
9. Hosts-file smoke against new-box IP — the 6-check gate from v0.2 step 13; **default-on-uncertainty = do NOT swing DNS**
10. DNS swing (TTL lowered 24h ahead)
11. Soak (7 days minimum)
12. Decommission old box

## Status updates folded

- ✅ P3 owner = **lg-shell** (renamed per CHAT-LINEAGE.md)
- ✅ P9 Patreon adapter shipped (poller chat per briefing-poller-patreon-adapter.md)
- ✅ Memory cleanups already applied
- ✅ Postgres on-box
- ✅ Cutover approach blue-green
- ✅ User comms skipped (removed from plan)
- ✅ Cloudflare cache purge skipped at launch (removed from plan)
- ✅ Step 6 Arbiter stripe-guard resolution noted in plan

## What's collapsed

- Per-step rollback complexity → mostly "point DNS back"
- "Maintenance window" pressure → eliminated (build can take days)
- Snippet #90 / code-snippet hygiene → not cutover-blocking (new box starts clean; old box stays running until decommission, harmless)

## Still load-bearing

- Cookie-gate strip on every dev nginx snippet before copy
- pdo_pgsql install
- Canonical per-app role + secret-file pattern
- archive-poc skip-pgloader / re-run-backfill
- bb-mirror pgloader from dev SQLite
- Step 9 6-check gate, default-on-uncertainty = do NOT swing

## Still pending (low priority)

- Sun 23:00 → Mon 03:00 ET (BATCH-05B recommendation) is now only
  relevant to step 7a's mysqldump (the brief read-pause on old box's
  production DB). Ian to ratify or pick alternate.
- DNS TTL pre-lower timing (recommend 24h ahead of step 10)

## What I'm doing while you review

- Pre-flight: time a dummy `mysqldump --single-transaction wp_loothgroup`
  against production during a quiet window to size step 7a's duration
- Draft hosts-file override + smoke-test scripts for step 9
- Track P1, P3, P4, P5, P6, P7a-c, P8 to ✅ as lanes ship

## File pointers

- `/home/ubuntu/projects/cutover/CUTOVER-PLAN.md` (v0.3)
- `/home/ubuntu/projects/cutover/LIVE-INVENTORY.md` (unchanged)
- `/home/ubuntu/projects/cutover/SESSION-HANDOFF.md` (updated)
- `/home/ubuntu/projects/cutover/batch-output/BATCH-04-results.md`
- `/home/ubuntu/projects/cutover/batch-output/BATCH-04B-sync-engine-body.md`
