# cutover → coordinator (new session): metadata + status report

## Spawn metadata

```
**cutover → coordinator:** spawn metadata

Session ID: unavailable from inside — Ian to capture from the panel.
            (Claude Code agent can't see its own session UUID.)
Outliner title: cutover  (assumed — Ian to confirm if different)
```

## Status report

```
**cutover → coordinator:** status report
```

### CUTOVER-PLAN.md state

Live at v0.2, beyond the v0 + 5 sharpenings the briefing references.
Path:
`/home/ubuntu/projects/cutover/CUTOVER-PLAN.md`

**v0.2 settled (folded, in plan):**
- 13-step sequence + 8-prereq table (P1–P8) with explicit
  rollback/risk/go-no-go on every step
- B-now / A-later / Stripe-dormant pattern (per refocus briefing)
- DB name = `looth` (not `strangler`)
- Storage = one postgres / 3 schemas (`profile_app`, `forums`, `discovery`);
  "mobile imminent" driver noted in preamble
- Cross-schema discipline (§3i) noted in preamble — schema = API
- Step 1 uses canonical provisioning pattern: per-app role owning
  per-app schema; secret files at `/etc/lg-<app>-db` mode 0640;
  Unix-socket DSN via FPM pool env; `php8.3-pgsql` install included
- Step 3a rewritten: archive-poc **skips pgloader**, re-runs backfill
  against `wp_posts`; TIMESTAMPTZ upgrade + `edit_archive_poc` cap
  mu-plugin land here
- Step 11a: bb-mirror **keeps pgloader** (data authoritative). Owner-plan
  in bb-mirror chat; my plan references, doesn't duplicate
- Step 6 dormant-poller smoke = new prereq P8 (owner: poller chat)
- Step 10 mid-edit state-loss mitigation block (min bar = email to admins)
- Step 13 reframed as hard yes/no gate; default-on-uncertainty = rollback
- Window timing data-backed: **Sun 23:00 → Mon 03:00 ET** recommended
  (BATCH-05B data — ~2× quieter than the canonical "Sun 22:00–02:00" gut-pick)

**Open / 🔒 in plan:**
- P3 owner (shared header partial) — lean lg-layout-v2; awaiting Ian's
  explicit assignment
- Postgres on-box vs RDS — Ian's lean on-box; ratification needed
- Window timing — Ian to ratify the Sun 23:00 → Mon 03:00 ET pick
- CF API token at `/etc/lg-cloudflare-token` mode 0600 root — from Ian
- User-visible comms (banner / email / slack) — Ian's call

### Live audit state (LIVE-INVENTORY.md)

5 batches executed on live by Ian via paste-back:
- BATCH-01 (nginx) ✓ — strangler URLs collision-free; nginx 1.24.0;
  archive-poc fully wired; no snippets/strangler-*.conf pattern on live;
  no CF real_ip config
- BATCH-02 (WP + cron + secrets) ✓ — CF confirmed in front; cron
  landscape mapped; no `lg-patreon-stripe-poller` on live
- BATCH-03 (roles + BB scale + pg) ✓ — Patreon (not Stripe); postgres
  not installed (resolved by §3i); BB data scale tiny (1,795 users,
  ~6k forum rows); role counts looth1=440/looth2=471/looth3=678/looth4=15
- BATCH-04 (role-writer trace + collision grep) ✓ — role-writer =
  `lg-patreon-onboard/includes/class-lgpo-sync-engine.php`; collision
  grep clean; only one Patreon plugin to coordinate with
- BATCH-05B (activity histogram) ✓ — window recommendation derived

### Waiting on

- **BATCH-04B paste-back** (3 file dumps: Sync Engine + Sync Cron +
  main plugin file). Forwards verbatim to poller chat as Patreon
  adapter spec input. Ian has the inline command block; running
  unblocks P2.
- **Live BP audit results** — interpreting "BP" = BuddyBoss-specific
  inventory. If this means an audit not yet run, point me at the ask;
  I haven't queued one. If it means BATCH-04B, see above.
- Coord pass on CUTOVER-PLAN.md v0.2 — redlines or lock-in.

### Cross-cutting questions

1. **P3 owner.** Is lg-layout-v2 chat picking up the shared header
   partial, or does it need a new tiny workstream? Coord-confirmed lean
   was lg-layout-v2; needs explicit assignment to unblock.
2. **Memory cleanup greenlight.** Two stale memories surfaced during
   inventory (`project_seo_strategy.md` says Rank Math removed but it's
   active on live; `project_dev_migration_20260515.md` doesn't note the
   stale dev.loothtool.com wp-cron still firing from live). Non-blocking
   — say the word and I'll update both.
3. **What "live BP audit" refers to.** If a third batch was promised
   that I missed, point me at it.

### Files relevant to this status

```
/home/ubuntu/projects/cutover/CUTOVER-PLAN.md
/home/ubuntu/projects/cutover/LIVE-INVENTORY.md
/home/ubuntu/projects/cutover/SESSION-HANDOFF.md
/home/ubuntu/projects/cutover/BATCH-04B-sync-engine-body.md
/home/ubuntu/projects/docs/reply-from-cutover-v0.2-review-request.md
```
