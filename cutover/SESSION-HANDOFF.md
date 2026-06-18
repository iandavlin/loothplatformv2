# cutover — Session Handoff (2026-05-27, opening pass)

> ⚠️ **SUPERSEDED (2026-06-15) — HISTORICAL ONLY.** This is the FIRST cutover-planning session
> (5/27–5/28); the cut model has changed twice since (in-place → **new box + DNS flip**).
> **Current cut truth = `docs/DEPLOY-PLAN.md` + `cutover/lanes/HANDOFF.md` (LATEST block) +
> `docs/PHASE-11-CUT-RUNBOOK.md`.** The 12-step blue-green sequence and "Next session" tasks below
> are obsolete — do NOT execute them. Kept only for the BATCH-0x live-inventory findings.

> Prior handoff (stub created by coordinator): `handoffs/2026-05-27-scaffold-stub.md`.
> This is the first real cutover session. Workstream charter is the
> briefing at [/home/ubuntu/projects/docs/briefing-cutover.md](../docs/briefing-cutover.md).

## What this project is

Cutover-inventory and cutover-planning workstream for the strangler-app
rollout to live (54.157.13.77). Peer to profile-app / archive-poc /
bb-mirror; coordinated by the strangler-coordination chat. Target
architecture is [/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md](../docs/STRANGLER-COORDINATION.md).

**Critical constraint:** live is Claude-free. We propose read-only
commands, Ian runs them on live, pastes output back. Write commands
must carry an explicit `Rollback:` + `Risk:` block (per briefing).

## State after this session

- **v0 inventory drafted** → [LIVE-INVENTORY.md](LIVE-INVENTORY.md).
  Mined from local artifacts (archive-poc LIVE-DEPLOY, lg-stripe-billing
  PROD-CUTOVER, stripe-poller SESSION-HANDOFF, profile-app SESSION-HANDOFF,
  bb-mirror post-deploy handoff, dev nginx snippets, memory entries).
  Everything inferred is `[VERIFY]`-tagged.
- **First read-only command batch queued** for Ian →
  [BATCH-01-nginx.md](BATCH-01-nginx.md). Topic: nginx routing layout
  on live. 10 commands, small enough for a single paste round-trip.
- **No write commands issued.** No live state touched.

## What v0 inventory captured (highlights)

- Live is NOT a clean slate — archive-poc is already deployed there
  (`/srv/archive-poc/` + FPM pool + mu-plugin + nginx route +
  `/etc/lg-archive-poc-secret`). The cutover pattern is established;
  profile-app and bb-mirror will mirror it.
- Live has the lg-viewer-tier mu-plugin (mints `lg_tier` cookie).
- Live runs PHP 8.3 + Redis (db0 loothgroup, db2 loothtool) + Cloudflare.
- Deltas to ship at cutover are enumerated in `LIVE-INVENTORY.md`
  §"What dev has that live doesn't" — most important:
  `/etc/lg-internal-secret`, `LG_INTERNAL_SECRET` constant, the new
  internal REST endpoint + PurgeNotifier in the poller, the profile-app
  app + Postgres + nginx routes, the bb-mirror app + SQLite + nginx
  routes, `LG_PROFILE_APP_URL` config (dev currently hardcodes the dev
  host in PurgeNotifier).
- Open questions (7) need answers before we can draft cutover steps
  for slices that involve them — chief among them: where does
  profile-app's Postgres live on prod?

## What BATCH-01 found (2026-05-27 evening)

Folded into `LIVE-INVENTORY.md` §"BATCH-01 findings". Highlights:

- **Strangler URLs are clear** — no collision on `/profile/edit`, `/u/`,
  `/p/`, `/profile-api`, `/whoami`, `/forums-poc`, `/bb-mirror`,
  `/looth-internal`. Cutover can take those routes cleanly.
- **archive-poc fully wired on live** (pool + sock + nginx routes confirmed).
- **nginx 1.24.0** — matches dev; main conf is a tidy 137 lines.
- **No `/etc/nginx/snippets/strangler-*.conf` pattern on live yet.** Recommend
  introducing it (matches dev, makes per-project deploy clean). Coord doc §3g.
- **No Cloudflare `real_ip` config in nginx.** If CF fronts the site (PROD-CUTOVER
  implies it does), all logged IPs are CF edges and any rate-limit zone we add
  keys off the wrong address. BATCH-02 verifies whether CF is in front.
- **`sites-enabled/loothtool.com.conf` and `merch.loothgroup.com.conf` are
  real files, not symlinks** — operational landmine, flag for post-cutover.

## What BATCH-02 found (2026-05-27)

Folded into `LIVE-INVENTORY.md §BATCH-02 findings`. Top items:

- **Cloudflare confirmed in front** of loothgroup.com — and nginx is
  not configured for real-IP. Any rate-limit work must add `real_ip_header
  CF-Connecting-IP` first or it'll throttle the CF edges, not visitors.
  Also means BATCH-02's "do strangler URLs collide?" check was answered
  by CF, not by origin — must re-test via loopback in BATCH-03.
- **`lg-patreon-stripe-poller` is NOT installed on live.** Major finding.
  Everything in our cutover plan assumes the poller is the role-writer,
  but live doesn't have it. BATCH-03 finds whatever IS writing looth*
  roles today.
- **code-snippets #90 ("Log Out Looth 1 Users Immediately") is ACTIVE.**
  PROD-CUTOVER lists this as a must-disable before cutover. Plus #53
  ("Force Log Out") is active — need to read its body.
- **Postgres is installed on the live box but inactive.** Answers
  open-question #1: profile-app's DB can land here (start service +
  create DB + run migrations). No new infra needed.
- **archive-poc fully deployed** on live (already known, now corroborated).
- **theme = buddyboss-theme + child**, lg-layout-v2 0.1.62 active.
- Stale cron firing from live at dev.loothtool.com (migrated off May 15
  — cleanup, not blocking).
- SEO memory ("Rank Math removed") doesn't match live — Rank Math is
  active. Memory was 4-day-stale-flagged; needs updating.

## What BATCH-03 found (2026-05-27) — picture changes substantially

Two findings change the cutover plan at the architectural level:

### 🚨 Live runs Patreon, not Stripe

`lg-patreon-stripe-poller`, `lg-stripe-billing` Slim app, and the
`/var/www/billing/` directory **do not exist on live**. Live's
role-writing pipeline is:

- `lg-patreon-onboard` 1.1.0 plugin (Patreon OAuth + Sync Cron + Sync Engine)
- `lg-looth4-expiry` 1.0.0 plugin (admin/comp expiry)
- `mu-plugins/looth-roles.php` (one file flagged by grep)
- code-snippet #44 "Patreon Tier Toggler" (CPT content gating)
- code-snippet #35, #86 (Patreon-specific login UX)

The entire `/whoami` design (`looth_tier_changed` action, PurgeNotifier,
`user-context` endpoint) assumes the Stripe poller is the writer.
**Coordinator + Ian decision needed** — path A, B, or C documented in
`LIVE-INVENTORY.md §"Headline: live runs Patreon, not Stripe"`.

### 🚨 No Postgres on live → resolved 2026-05-28: one shared instance, three schemas

Briefing `docs/briefing-cutover-storage-update.md` settled it. Coord
§3i now says: one postgres server, three schemas (`profile_app`,
`forums`, `discovery`). BB-mirror and archive-poc both migrate from
SQLite to postgres at cutover via pgloader. Postgres install becomes
step 1 of the cutover plan; Ian's lean is on-box install. Steps 3a/3b
added for the pgloader migrations. Rollback for each migration =
revert DSN, reload FPM (SQLite files stay on disk through soak).

### ✓ Other confirmed

- Strangler URLs are not content-collisions (30x redirects, `^~` will
  intercept). Clear to take them.
- BB data scale is small: 1,795 users, ~3k real group memberships,
  6,100 forum rows total. Migration / backfill effort is hours, not days.
- Role counts: looth1 440, looth2 471, looth3 678, looth4 15 (~1,164 paid).
- DB name on live = `wp_loothgroup`. Backed up to R2 twice daily.
- wp_users schema is bare — slice 2.75 migration hasn't run on live.

## Decisions ratified (2026-05-28)

- **A/B/C closed:** B-now / A-later / Stripe dormant. Poller ships with
  no Stripe creds; Arbiter sees Patreon-source data via the adapter.
  Stripe-enable later = config drop-in. (briefing-cutover-refocus.md)
- **Storage:** one postgres, three schemas (profile_app, forums,
  discovery). pgloader at cutover for archive-poc + bb-mirror SQLite
  data. On-box install vs RDS still TBD (Ian leans on-box).
  (briefing-cutover-storage-update.md → coord §3i)

## CUTOVER-PLAN.md at v0.3 (2026-05-28 evening)

[CUTOVER-PLAN.md](CUTOVER-PLAN.md) — **blue-green rewrite** per Ian's
ratification (`docs/reply-to-cutover-ian-decisions.md`).

**Model change:** fresh EC2 + DNS swing replaces in-place surgery.
Build can take days; old box (54.157.13.77) stays up as natural
fallback through DNS propagation. Most per-step rollback collapses to
"point DNS back." User-visible comms: skip. Cloudflare cache purge:
skip at launch (natural miss on first request post-swing).

**12-step blue-green sequence:**
1. Provision new EC2
2. Bake stack (nginx, php8.3+pgsql, postgres-16, redis, certbot)
3. Postgres db + 3 schemas + 3 per-app roles + secret files (canonical §3i)
4. Deploy strangler apps (profile-app, archive-poc, bb-mirror, lg-shell, etc.)
5. `/etc/lg-internal-secret` provisioned
6. nginx config + cookie-gate strip + `^~ /wp-json/looth-internal/` exempt + LE cert via DNS-01
7. DB import (`wp_loothgroup` mysqldump→restore, uploads rsync, plugin sync, backfills, profile-app migration)
8. Plugin activation on new box (dormant poller + Patreon adapter)
9. Hosts-file smoke against new-box IP (6-check gate from v0.2 step 13)
10. DNS swing
11. Soak (7 days minimum)
12. Decommission old box

**v0.3 status updates folded:**
- P3 owner: **lg-shell** (renamed from lg-layout-v2 per CHAT-LINEAGE.md)
- P9 Patreon adapter: ✅ shipped + smoked (poller chat per
  briefing-poller-patreon-adapter.md)
- Memory cleanups already applied (Rank Math + stale dev cron memos
  reflect current state)
- BATCH-04B coexistence analysis ratified — B-now/A-later is mechanically
  free; LGPO's `payment_source='stripe'` skip-guard makes the dormant
  ship a no-op

## Next session

1. Surface CUTOVER-PLAN.md v0.3 to coordinator for review pass against
   the blue-green spec in `docs/reply-to-cutover-ian-decisions.md`.
2. Track P1, P3, P4, P5, P6, P7a, P7b, P7c, P8 to ✅ as project lanes
   ship. When all green, new-box build can start.
3. Pre-flight tasks while waiting on prereqs:
   - Time a dummy `mysqldump --single-transaction wp_loothgroup` against
     production during a quiet window to size step 7a's duration
   - Draft the hosts-file override + smoke-test scripts for step 9
   - Capture old-box `loothgroup.com.conf` baseline so step 6a starts
     from a known reference (we already have it from BATCH-01)
4. Ratify Sun 23:00 → Mon 03:00 ET as the step-7a quiet window or pick
   alternate (only step impacted by traffic in the new model).
5. After ratification, expand each step with exact paste-back-friendly
   commands.

## Coordinator ping (deliverable for Ian to route)

Status: cutover chat is alive. v0 inventory drafted from local mining.
First read-only command batch ready for live (nginx). No cross-chat
decisions needed yet. Will surface decisions to coordinator as they
emerge from inventory findings.

## Handoff rotation

When superseding this file, rename it `handoffs/YYYY-MM-DD[-suffix].md`
and write fresh per [/home/ubuntu/projects/CLAUDE.md](../CLAUDE.md).

## Pointers

- Briefing: [/home/ubuntu/projects/docs/briefing-cutover.md](../docs/briefing-cutover.md)
- Coordination contract: [/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md](../docs/STRANGLER-COORDINATION.md)
- Coordinator handoff: [/home/ubuntu/projects/docs/STRANGLER-SESSION-HANDOFF.md](../docs/STRANGLER-SESSION-HANDOFF.md)
- Peer handoffs: see §"Read first" in the briefing
- Local mining sources used:
  - `/srv/lg-stripe-billing/PROD-CUTOVER.md`
  - `/home/ubuntu/projects/archive-poc/deploy/LIVE-DEPLOY.md`
  - Memory: `project_dev_migration_20260515.md`, `reference_lg_layout_v2_deploy.md`, `reference_ccdev_server.md`
