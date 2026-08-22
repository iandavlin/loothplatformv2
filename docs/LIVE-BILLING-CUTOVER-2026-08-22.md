# Live billing cutover — retire /srv/lg-stripe-billing, serve the monorepo copy

**Issue #197.** Every "current state" here was MEASURED on live, read-only,
2026-08-22, via `live-ro`. Nothing is inferred from dev2. **All live writes are
Ian's.** Modeled on `SERVE-CONSOLIDATE-MEMBERSHIP-RUNBOOK.md`: ordered steps,
exact commands, per-step verify, per-step rollback.

Everything below is run by one reviewed script fetched from the repo —
`tools/infra/live-billing-cutover.sh` — not by pasted commands.

---

## What this does

`/srv/lg-stripe-billing` is today a **real directory holding a second git repo**
(`iandavlin/lg-stripe-billing` @ `d7a71f3`, last touched May 10). It becomes a
**symlink into the serving checkout**, the arrangement dev2 has run since
2026-08-16. After this, the billing app deploys by the same `lg-deploy` pull as
everything else, and the standalone repo stops being a second source of truth.

**nginx is not touched.** The vhost already aliases `/srv/lg-stripe-billing/public/`
and a symlink at that path is transparent to it. **The FPM pool is not touched.**

## Current live state (measured 2026-08-22)

| | |
|---|---|
| Old app | `/srv/lg-stripe-billing`, real dir, `www-data:www-data`, `.env` **0640 www-data** |
| Is it serving? | **Yes** — `/billing/health`, `/billing/v1/products`, `/billing/v1/config` all **200 JSON** |
| Serving checkout | `main` @ `2163c08a`, **clean**, already carries the current app code |
| Pool | `lg-billing-dev`, runs as **www-data** (measured by spawning a worker) |
| vhost alias | `alias /srv/lg-stripe-billing/public/;` — the #40 trailing-slash fix is in |
| Uncommitted live edit | `CheckoutController.php` (one-time memberships retired) — **the monorepo carries the same block; nothing is lost** |

## Why there is no `composer install` step

`composer.lock` is **byte-identical** old vs monorepo, and the two `vendor/` trees
are **byte-identical** (890 files, same manifest hash). So the window **copies**
`vendor/`. That is provably the same bytes and needs no packagist egress inside a
change window — the opposite of re-resolving 890 packages on production.

## The three things a pull can never deliver

`vendor/`, `.env` and `logs/` are all gitignored, so the serving checkout has none
of them. The script places all three **before** the move.

- **`.env` keeps LIVE's posture, not dev2's.** dev2's `.env` is `0664 ubuntu`
  (world-readable); live's is `0640 www-data`. `cp -a` carries live's unchanged,
  and preflight **refuses to run** if it finds anything else.
- **`logs/` must exist and be www-data-owned.** Monolog creates its own directory,
  but not as `www-data` inside an `ubuntu`-owned tree — the app would answer 200
  on every route right up until something logged, then 500.

---

## ⚠️ Read this before you run it: the swap changes checkout behaviour

The new tree runs #181's `CheckoutAudienceGuard` as the **first** gate on
`POST /v1/checkout`. The old tree has no such gate.

**`lgms_shared_secret` is ABSENT on live** (enumerated 2026-08-22 — of every
`lgms%` option, only `lgms_stripe_pages_live = 0` is set). So:

```
probe → WordPress authSharedSecret(): secret unset → 401
      → HttpCheckoutAudienceProbe: non-200 → null
      → CheckoutAudienceGuard: null → 503 STATUS_UNKNOWN
```

**After the swap, checkout answers 503 to everyone.** That is fail-safe-closed and
by design. **It is not member-visible**: `lgms_stripe_pages_live = 0` keeps the
purchase pages administrator-only, and `lgms_stripe_secret_key` is absent, so live
cannot mint a Stripe session today either.

**To reach the 403 state** (invited testers buy, strangers get the tester
sentence): set `lgms_shared_secret` WP-side to match `.env`'s `LGMS_SHARED_SECRET`,
then populate the tester list. That is Ian's, WP-side, and outside this window.

`--verify` asserts the **exact sentence**, so 503 can never be mistaken for the
403 — and never read as a generic "checkout refuses ✓".

---

## The window

### Step 1 — Preflight (changes nothing)
```bash
[ "$(hostname)" = "ip-172-31-67-175" ] && \
  sudo /home/ubuntu/loothplatformv2-clean/tools/infra/live-billing-cutover.sh --check \
  || echo "WRONG BOX"
```
**Verify:** `PREFLIGHT GREEN — safe to --apply.` Seven checks: the old path is a
real dir (not already swapped), the monorepo app is complete, the checkout is
clean and on `main`, the lock files match, `.env` has live's posture,
`LGMS_SYNC_URL` ends in `/sync-customer`, and the vhost alias is the expected line.

**If any line is FAIL, stop.** Preflight failing is the script working.

### Step 2 — Apply
```bash
sudo /home/ubuntu/loothplatformv2-clean/tools/infra/live-billing-cutover.sh --apply
```
It copies `vendor/`, creates `logs/`, copies `.env`, **moves** the old tree to
`/srv/lg-stripe-billing.bak-<timestamp>`, creates the symlink, and reloads
`php8.3-fpm`.

**The outage is two commands** (`mv` then `ln`) — sub-second. All copying happens
first. It is not zero: for that instant `/billing/*` 404s.

**The FPM reload is a step, not a tidy-up.** opcache and the realpath cache serve
the old compiled file through a re-pointed symlink (proven on dev2 6/28). Skip it
and every check still passes while live runs the old code.

### Step 3 — Verify
```bash
sudo /home/ubuntu/loothplatformv2-clean/tools/infra/live-billing-cutover.sh --verify
```

| | Check | Expected |
|---|---|---|
| V1 | `/srv/lg-stripe-billing` resolves | the monorepo app |
| V2-4 | `/billing/health`, `/v1/products`, `/v1/config` | 200, products body non-empty |
| V5 | anon `POST /v1/checkout` | **503 + the UNKNOWN sentence** (today) or **403 + the #181 sentence** (once armed) |
| V6 | unsigned `POST /v1/webhook` | **400 invalid-signature** — the door Stripe's signature test knocks on |
| V8 | `logs/` writable by www-data | liveness — without it V2–V6 pass on a box that cannot log |

> **V5 is also the opcache proof.** Before the swap that same request answers
> `400 price_id is required` (the old tree validates the body first and has no
> guard). So a **400 at V5 means the swap or the reload did not take**, whatever
> `readlink` says. Measured both sides on live 2026-08-22.

### Rollback — one `mv` back
```bash
sudo /home/ubuntu/loothplatformv2-clean/tools/infra/live-billing-cutover.sh --rollback
```
Removes the symlink, moves the `.bak-` tree back, reloads FPM.

**The script can only ever remove a symlink.** If `/srv/lg-stripe-billing` is a
real directory it refuses and exits non-zero — tested. The old tree is **moved,
never deleted**, so rollback is always available until someone deliberately
removes the `.bak-` dir (a sweep Ian authorises, not part of this).

After rollback, `--verify` V5 returns to `400 price_id is required`.

Rollback leaves `vendor/`, `.env` and `logs/` in the checkout. Harmless — all
three are gitignored and `.env` keeps its 0640 www-data posture — and it makes a
re-apply fast.

---

## Not in this window (stated, not papered over)

- **`sync-customer` is still behind BuddyBoss's REST wall on live.** Live's
  `bb-enable-private-rest-apis-public-content` holds only `looth/v1`,
  `looth-internal/v1` and a wc URL — exactly as
  `LIVE-ARMING-RUNBOOK-2026-08-16.md` Step 1 said. It affects the old app
  identically; this swap neither causes nor fixes it, and `--verify` makes no
  claim about the sync leg. `checkout-audience` and `patreon-standing` carry
  their own narrow exemptions and **are** reachable (discriminated by their
  different 401 codes: `rest_forbidden` vs `bb_rest_authorization_required`).
- **The 5-minute sweep** is WP-cron `lgms_poll_tick` → `POST /v1/reconcile-pending`.
  If it fires inside the window it logs `reconcile-pending FAILED` and retries in
  5 minutes. Non-destructive, self-healing, no action needed.
- No `cron.d` entry, no systemd unit and nothing else on the box references the
  app path. The only two references anywhere are a comment and the alias, both in
  the vhost.

## Testing this script

It carries a redirectable root so it can be tested rather than trusted:

```bash
LG_BC_ROOT=/tmp/fixture sudo -E tools/infra/live-billing-cutover.sh --check
```
With `LG_BC_ROOT` unset every path is byte-for-byte the production path. The full
state machine (check → apply → refuse re-run → rollback → check) and every
preflight refusal were exercised against a fixture on 2026-08-22.
