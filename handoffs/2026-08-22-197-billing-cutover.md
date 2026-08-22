# Lane 197 — billing cutover — HANDOFF (2026-08-22)

**Branch:** `197-billing-cutover` · **State:** delivered, awaiting Ian's live run.
**Nothing was written on live.** Every measurement was read-only via `live-ro`.

## What shipped

| File | What |
|---|---|
| `handoffs/plans/197-billing-cutover-PLAN.md` | the measured plan (approved) |
| `tools/infra/live-billing-cutover.sh` | `--check` / `--apply` / `--verify` / `--rollback` |
| `docs/LIVE-BILLING-CUTOVER-2026-08-22.md` | the runbook Ian is handed |
| `docs/domains/MEMBERSHIP.md`, `docs/domains/INFRA.md` | dossier updates |

Commits: `1ff3cd0` (plan), `+1` (script + runbook + dossiers). Both pushed.

## The four charter deliverables

1. **The window** — `--apply`. Copies `vendor/`, creates `logs/` (www-data),
   copies `.env` with `cp -a`, **moves** the old tree to `.bak-<ts>`, symlinks,
   reloads FPM. All copying before the move; the outage is two commands.
2. **The verify battery** — `--verify`. V1 resolve · V2-4 health/products/config
   · V5 checkout refusal **by sentence** · V6 unsigned webhook → 400
   invalid-signature · V8 logs liveness.
3. **The rider question, measured** — below.
4. **Rollback** — `--rollback`. One `mv` back. Provably can only remove a
   symlink; refuses a real directory, exits non-zero. Tested.

## Rider question, answered by measurement

- **What the old app serves today:** `/billing/health`, `/v1/products`,
  `/v1/config` all **200 JSON**. It is live-serving; "stale" was always about the
  code, never availability. The 8/16 alias bug is fixed.
- **composer deps:** `vendor/` is **byte-identical** old vs monorepo (890 files,
  same manifest hash) and `composer.lock` matches. **No `composer install` on
  live** — the window copies. Offline, provably identical.
- **FPM pool paths:** no change. Pool `lg-billing-dev` runs as **www-data**
  (measured by spawning a worker; the conf is root-only). The pool conf in the
  app's `deploy/` is stale fiction (`ccdev`, `/home/ccdev/...`).
- **nginx:** no change. The vhost already aliases `/srv/lg-stripe-billing/public/`.
- **The 5-min sweep:** WP-cron `lgms_poll_tick` → `POST /v1/reconcile-pending`.
  A miss inside the window logs `reconcile-pending FAILED` and retries in 5
  minutes. Non-destructive, self-healing.
- **Other crons:** none. No `cron.d`, no systemd unit, nothing else references
  the path — only a comment and the alias, both in the vhost.

## The two findings that shaped it

1. **Live's standalone repo had an uncommitted edit** (`CheckoutController.php`,
   one-time memberships retired → 400). A naive `mv` destroys that silently. I
   diffed the working tree rather than the last commit: **the monorepo carries
   the same block, so nothing is lost.**
2. **The swap arms #181, and today that means 503, not the 403 the charter
   expected.** `lgms_shared_secret` is absent on live (enumerated every `lgms%`
   option — only `lgms_stripe_pages_live = 0` is set), so the probe is refused,
   non-200 → `null`, `null` → **503 UNKNOWN**. Fail-safe-closed and **not
   member-visible** (`pages_live = 0`). The battery asserts the exact sentence so
   503 cannot read as a generic "checkout refuses ✓".

## For whoever runs it

Ian's decision (2026-08-22): **swap now, the 503 is fine** — it lands while the
purchase pages are still administrator-only, decoupling the risky part (retiring
the stale app) from the arming.

**To reach 403:** set `lgms_shared_secret` WP-side to match `.env`'s
`LGMS_SHARED_SECRET`, then populate the tester list. Then re-run `--verify`; V5
should flip to 403 + the #181 sentence with no script change.

## Noticed, not fixed

- **`sync-customer` is still behind BuddyBoss's REST wall on live** — the 8/16
  arming runbook's Step 1, still open. Affects the old app identically; this swap
  neither causes nor fixes it, and the battery makes no claim about the sync leg.
  `checkout-audience` / `patreon-standing` / `auth` carry their own exemptions and
  ARE reachable.
- **`lgms_db_pass` is stored in plaintext in `wp_options`** on live (the
  `lg_membership` DB credentials). Pre-existing, out of scope, **worth its own
  issue.**
- The app's `deploy/nginx-billing-location.conf` and
  `deploy/php-fpm-pool-lg-billing-dev.conf` describe a box that does not exist.
  Candidates for deletion or a "historical" header once the swap lands.
- `/srv/lg-stripe-billing.bak-20260816-183554` still sits on **dev2** from its own
  swap. Cleanup is a sweep Ian authorises.
- **`gh` has no token in this worktree**, so I could not read #197's body and
  worked from the lane charter. Worth fixing for future lanes.
