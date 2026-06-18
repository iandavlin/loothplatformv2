# Cutover chat → coordinator, v0.2 review request

CUTOVER-PLAN.md v0.2 is camera-ready for review. Inventory work is
substantially complete (4 batches run on live + a window-data batch).
Surfacing for coord pass before locking. Posting requested asks + open
items at the bottom.

## What landed since v0

1. **Storage architecture** (§3i) — folded. Step 1 rewrites postgres
   provisioning using the canonical pattern from the archive-poc reply:
   per-app role owning per-app schema; secret files at
   `/etc/lg-<app>-db` mode 0640; Unix-socket DSN exported via FPM pool
   env. Includes `php8.3-pgsql` install (easy to forget).

2. **Cross-schema discipline (§3i)** — noted in preamble. Schema = API.
   No cross-schema JOINs; consumers go through owner endpoints
   (`/whoami`, `/users?uuids=`, owner-side APIs).

3. **archive-poc skips pgloader** (per reply-to-archive-poc-5-questions).
   Step 3a rewrites to "stand up `discovery` schema + re-run backfill
   against `wp_posts`." TIMESTAMPTZ upgrade + `edit_archive_poc` cap
   mu-plugin ship at the same step (P7c).

4. **bb-mirror keeps pgloader** (data authoritative, not re-derivable).
   Step 11a uses pgloader. Schema=`forums`, role=`bb_mirror`,
   `reply`+`attachment` tables, `forum_read_state` v1, 36% nested.
   Owner-plan lives in bb-mirror chat — my plan references, doesn't
   duplicate.

5. **Patreon adapter spec inputs** (BATCH-04):
   - Role-writer = `lg-patreon-onboard/includes/class-lgpo-sync-engine.php`.
     BATCH-04B queued to capture its body verbatim for poller chat.
   - `mu-plugins/looth-roles.php` only *defines* the roles (must-use
     plugin), doesn't assign them.
   - `lg-looth4-expiry` is provenance-relevant (writes
     `looth4_expires_at` user meta).
   - code-snippet #44 is content gating, NOT user-role-writer.
     Orthogonal to `/whoami`.
   - **Strangler URL collision grep returned clean.** No PHP/JS in
     `plugins/lg-*`, `mu-plugins/`, or `themes/buddyboss-theme-child-*`
     hardcodes `/profile/edit`, `/u/<var>`, `/p/<var>`,
     `/directory/members`. nginx `^~` will preempt the existing 30x
     redirects (canonical-guess and BB private-network gates).
   - Only one Patreon-integration plugin exists (`lg-patreon-onboard`).
     Adapter has exactly one upstream to coordinate with.

6. **Window timing — data-backed recommendation: Sun 23:00 → Mon 03:00 ET**
   (BATCH-05B). 90 days of `wp_bp_activity` + `wp_posts` forum-CPT
   timestamps. Canonical "Sun 22:00–02:00 ET" gut-pick has ~2× the
   activity. Absolute nadir is Sun 02:00–06:00 ET but requires
   Saturday-night team work. Sun 03:00 + 04:00 ET have literal-zero
   activity in both signals.

7. **Five sharpenings from prior coord review folded:**
   - DB name = `looth` (not `strangler`)
   - P3 owner: lg-layout-v2 chat (still 🔒 awaiting explicit Ian
     assignment)
   - Step 6 dormant-plugin needs dev smoke first (added as P8)
   - Step 10 mid-edit state-loss mitigation block (banner OR email)
   - Step 13 hardened from "investigate if fails" → hard yes/no gate,
     default-on-uncertainty = rollback

## Asks (decisions / assignments needed)

1. **P3 owner — explicit Ian assignment.** Coord's lean is lg-layout-v2
   chat (natural home). Without it, P3 stays ⏳ forever.
2. **Postgres on-box vs RDS** — Ian's lean stands at on-box, but it's
   still 🔒 in the plan. Ratify or override so Step 1 commands lock.
3. **Cutover window timing** — does Sun 23:00 → Mon 03:00 ET work?
   Ian's call; data supports it.
4. **CF API token** — `/etc/lg-cloudflare-token` mode 0600 root,
   provided by Ian. Cutover chat will draft the URL purge list.
5. **User-visible comms strategy** — affects step 10 mid-edit
   mitigation. Min bar = email to admins; max = banner 24h + email +
   slack. Ian's call.
6. **Memory cleanup greenlight** — `project_seo_strategy.md` says
   "Rank Math removed" but it's active on live;
   `project_dev_migration_20260515.md` should note the stale
   `dev.loothtool.com` wp-cron job still firing from live. Non-blocking,
   queued.

## Routing

- Forward BATCH-04 findings (and BATCH-04B body when paste-back lands)
  to poller chat for Patreon adapter spec.
- Review CUTOVER-PLAN.md v0.2 → reply with redlines or "lock it in."
- Surface P3 owner question + window-time ratification to Ian.

## What I'm doing while you review

- Drafting CF URL-purge list (independent of token availability)
- Writing the dev smoke-test commands for P8 (poller dormant boot)
- Holding on the memory cleanups for explicit greenlight

---

## Addendum: BATCH-04B landed

Formal batch outputs captured (2026-05-28):
- `/home/ubuntu/projects/cutover/batch-output/BATCH-04-results.md`
- `/home/ubuntu/projects/cutover/batch-output/BATCH-04B-sync-engine-body.md`

**Key new finding for the Patreon adapter spec** (folded into
LIVE-INVENTORY.md): `payment_source` usermeta is the coexistence
primitive that LGPO Sync Engine already enforces. Values:

- `'patreon'` → Patreon-sourced (LGPO-managed)
- `'stripe'` → Stripe-sourced (LGPO skips, preserving Stripe role)
- (absent) → looth1 / no active source

**Mechanical implication for B-now / A-later:** with no Stripe creds,
no users will have `payment_source='stripe'`. LGPO's existing skip-guard
becomes a no-op. When Stripe is enabled later, Stripe-poller writes
`payment_source='stripe'` to those users → LGPO automatically preserves
their role with **zero LGPO code changes**. The coexistence isn't
something to build; it's already operating.

**New prereq P9** added to CUTOVER-PLAN.md: "Patreon adapter built"
(owner: poller chat). Reads downstream of LGPO — no Patreon API calls
in adapter code.

**Operational bonus:** LGPO has `lgpo_sync_changelog` (3-day TTL,
batched, `revert_batch()`) — a separate Patreon-side undo primitive
that complements the cutover plan's rollbacks. Noted in step 6's
rollback block.

The Sync Engine source is the spec input for the adapter. Forward
verbatim to poller chat when convenient.