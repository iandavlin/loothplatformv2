# Lane 197 — PLAN: live's payment app joins the monorepo

**Every "current state" below was MEASURED on live, read-only, 2026-08-22, via
`live-ro`. Nothing is inferred from dev2. All live writes are Ian's.**

Approval required before any code. Deliverable on approval:
`tools/infra/live-billing-cutover.sh` (`--check` / `--apply` / `--verify` /
`--rollback`), fetched from the repo and run on live — never typed freehand.

---

## 1. What is actually there today

### The old app — `/srv/lg-stripe-billing` (real directory)

| Fact | Measured |
|---|---|
| Owner / mode | `www-data:www-data`, setgid, ACL `looth-ro:r-x`, `loothdevs:rwx` |
| Repo | standalone `iandavlin/lg-stripe-billing`, `main` @ `d7a71f3` |
| `.env` | **`0640 www-data:www-data`**, last written Jun 2 |
| `vendor/` | 890 files present |
| **Uncommitted live edits** | `src/Http/Controllers/CheckoutController.php` (+ `PROD-CUTOVER.md`) |

**It is serving right now** — this is the rider question answered by measurement,
not assumption (loopback, `Host: loothgroup.com`):

```
/billing/health        200 application/json
/billing/v1/products   200 application/json
/billing/v1/config     200 application/json
```

The `/billing/` alias bug from `LIVE-BILLING-PREFLIGHT-2026-08-16.md` is **already
fixed** — vhost line 218 reads `alias /srv/lg-stripe-billing/public/;` (trailing
slash present).

> ⚠️ **The uncommitted edit was the one thing that could have been silently lost.**
> It retires personal one-time membership purchases (returns 400). I checked
> rather than assumed: the monorepo carries that exact block at
> `CheckoutController.php:227-232`. **Nothing is lost by the swap.**

### The new app — `/home/ubuntu/loothplatformv2-clean/lg-stripe-billing`

Live's serving checkout is on `main` @ **`2163c08a`**, **clean**, and that is the
same commit as this lane's branch — the monorepo code is already current on live.
A `git pull` is not part of this cutover.

**Three things the checkout does NOT have, all gitignored (`vendor/`, `.env`,
`logs/`, `*.log`), so no `git pull` will ever create them:**

| Missing | Consequence if swapped without it |
|---|---|
| `vendor/` | fatal on first request — no autoloader |
| `.env` | no Stripe keys, no DB, no shared secret |
| `logs/` | Monolog `StreamHandler` cannot create its dir as `www-data` inside an `ubuntu`-owned tree → 500 on first log write |

### The serving plumbing

- **FPM pool `lg-billing-dev` runs as `www-data`** — measured by spawning a worker
  and reading `ps`, not by reading the (root-only) pool conf.
- The pool conf checked into the app's `deploy/` is **stale** — it names user
  `ccdev` and path `/home/ccdev/...`. It documents dev history, not live. **No
  pool change is needed** and the script must not touch it.
- **The vhost is a symlink into the serving checkout**
  (`/etc/nginx/sites-enabled/dev2.loothgroup.com.conf` →
  `sites-available/…` → `platform/nginx/dev2.loothgroup.com.conf`). The alias
  already points at `/srv/lg-stripe-billing/public/`, and `/srv/lg-stripe-billing`
  becoming a symlink is transparent to nginx. **No nginx change, no reload.**
- Only two references to the path exist in the whole vhost: a comment (line 196)
  and the alias (line 218).
- **No `cron.d`, no systemd unit, no other path coupling** references the app.

---

## 2. The swap is smaller than it looks — and I measured why

| Check | Result |
|---|---|
| Files only in the old tree | **`.env` and `.env.example` only** — nothing else is lost |
| Files new in the monorepo | 8 (the #181 / #150 guards + `WebhookReceipts`) |
| Shared files that differ | 9 |
| `config/routes.php` | **byte-identical** → no route-surface change |
| `composer.lock` | **byte-identical** → the existing `vendor/` is valid for the new tree |
| `vendor/` old vs monorepo | **identical: 890 files, same manifest hash** |

### Rider question: `composer install` on live?

**No — and it must not be attempted.** `composer.lock` is byte-identical and the
old `vendor/` is byte-identical to the tree dev2 has been running since 8/16. The
window copies `vendor/` across. That keeps the step **offline** (no packagist
egress, no cache, no PHP-version resolution) and provably identical, instead of
re-resolving 890 files on a production box inside a change window.

This is what dev2 did: `/srv/lg-stripe-billing.bak-20260816-183554` still sits
beside the symlink there, and its `vendor/` mtimes match live's exactly.

### Rider question: what breaks at swap moment?

1. **Opcache / realpath cache.** A symlink re-target is invisible until
   `sudo systemctl reload php8.3-fpm` — the D3 lesson proven on dev2 6/28 in
   `SERVE-CONSOLIDATE-MEMBERSHIP-RUNBOOK.md`. The reload is a required step, not
   a tidy-up.
2. **The 5-minute sweep.** It is WP-cron `lgms_poll_tick` → `POST
   /billing/v1/reconcile-pending` (`Tick.php:129`). If it fires inside the window
   it logs `reconcile-pending FAILED` and retries 5 minutes later. Non-destructive,
   self-healing; no action needed.
3. **The outage window is two commands** (`mv` then `ln`), sub-second. All
   copying happens *before* the move. I am not going to pretend it is zero: for
   that instant `/srv/lg-stripe-billing` does not exist and `/billing/*` 404s.

---

## 3. ⚠️ The finding that matters most — the swap ARMS the #181 guard

The new `CheckoutController` runs `CheckoutAudienceGuard` as the first gate. The
old one has no such gate. So the swap changes live checkout behaviour, and the
current live switch state decides how.

**Enumerated on live (not read off a fixed list — I listed every `lgms%` option):
only `lgms_stripe_pages_live = 0` is set. `lgms_shared_secret` is ABSENT.**

The chain, traced in code:

```
lgms_checkout_audience absent → CheckoutAudience::DEFAULT_STATE = 'allowlist' (enforcing)
Slim probe → POST /wp-json/lg-member-sync/v1/checkout-audience  (X-LGMS-Token)
  → authSharedSecret(): get_option('lgms_shared_secret','') === '' → returns false → 401
  → HttpCheckoutAudienceProbe: `if ($code !== 200) return null`
  → CheckoutAudienceGuard: null → STATUS_UNKNOWN = 503
```

**So after the swap, live checkout answers 503 — "We could not verify access to
checkout just now. Please try again in a moment." — to everyone, until
`lgms_shared_secret` is set on the WP side to match `.env`'s `LGMS_SHARED_SECRET`.**

This is fail-safe-closed and it is the designed behaviour, not a bug. It is also
**not a member-visible regression**, because `lgms_stripe_pages_live = 0` keeps the
purchase pages administrator-only and `lgms_stripe_secret_key` is absent, so live
cannot mint a Stripe session today either.

> ⚠️ **The charter's verify battery expects the 403 sentence. On live today it
> will be the 503 sentence, and the two are different findings.** A battery that
> only asserts "checkout refuses" reads GREEN on the very defect. So the verify
> step below **asserts the exact sentence** and treats 503 as EXPECTED-TODAY with
> a named follow-up, not as a pass.

**Prerequisite for the 403 state (Ian's, WP-side, not in this window):** set
`lgms_shared_secret` to match `.env`, then populate the tester list.

### Also confirmed reachable (so these are NOT blockers)

`checkout-audience` carries its own narrow exemption from BuddyBoss's blanket REST
wall, and it is deployed. I discriminated this rather than guessing — the two
routes give *different* 401 codes:

| Route (anon, loopback) | Code | Means |
|---|---|---|
| `/lg-member-sync/v1/sync-customer` | `bb_rest_authorization_required` | BuddyBoss's wall |
| `/lg-member-sync/v1/checkout-audience` | `rest_forbidden` | **past the wall**, refused by its own token check — healthy |

`patreon-standing` and `auth`/`gift-auth` carry the same exemption.
**`sync-customer` does not** — live's `bb-enable-private-rest-apis-public-content`
still holds only `looth/v1`, `looth-internal/v1` and a wc URL, exactly as
`LIVE-ARMING-RUNBOOK-2026-08-16.md` Step 1 said. That is a **pre-existing,
unrelated blocker**: it affects the old app identically, this swap neither causes
nor fixes it, and my verify battery does not claim to prove the sync leg.

---

## 4. The window (proposed exact sequence)

Literal paths, hostname guard, fail-closed preflight. Becomes
`tools/infra/live-billing-cutover.sh` on approval.

```bash
[ "$(hostname)" = "ip-172-31-67-175" ] || { echo "WRONG BOX"; exit 1; }
OLD=/srv/lg-stripe-billing
NEW=/home/ubuntu/loothplatformv2-clean/lg-stripe-billing
TS=$(date +%Y%m%d-%H%M%S); BAK="/srv/lg-stripe-billing.bak-$TS"
```

**Preflight — every one must pass or it exits without touching anything**
1. `$OLD` is a real directory, **not** already a symlink (re-run safety)
2. `$NEW` exists; `$NEW/public/index.php` and `$NEW/config/routes.php` present
3. checkout is on `main` and clean (`git status --porcelain` empty)
4. `cmp $OLD/composer.lock $NEW/composer.lock` — identical
5. `$OLD/.env` exists and is `0640 www-data:www-data`
6. **as `www-data`**: `LGMS_SYNC_URL` ends in `/sync-customer` — prints `PASS`/`FAIL`
   only, never the value (it is in a 0640 secrets file)
7. vhost still reads `alias /srv/lg-stripe-billing/public/;` (with trailing slash)

**Apply — all copying first, so the outage is the last two commands**
```bash
sudo cp -a  "$OLD/vendor"  "$NEW/vendor"                    # 890 files, offline
sudo install -d -o www-data -g www-data -m 0755 "$NEW/logs" # mirrors dev2 exactly
sudo cp -a  "$OLD/.env"    "$NEW/.env"                      # -a carries 0640 www-data:www-data
sudo mv     "$OLD"         "$BAK"                           # MOVED, never deleted
sudo ln -sfn "$NEW"        /srv/lg-stripe-billing
sudo systemctl reload php8.3-fpm                            # D3 — without this you test the old bytes
```

> **`.env` posture: live's, not dev2's.** dev2's `.env` is `0664 ubuntu:ubuntu` —
> world-readable. Mirroring dev2 "exactly" would widen live Stripe secrets to every
> user on the box. `cp -a` carries live's `0640 www-data:www-data` unchanged, and
> preflight #5 refuses to run if that posture is not what it finds.

**Rollback — one `mv` back**
```bash
[ -L /srv/lg-stripe-billing ] && sudo rm -f /srv/lg-stripe-billing   # only ever a symlink
sudo mv "$BAK" /srv/lg-stripe-billing
sudo systemctl reload php8.3-fpm
```
The `[ -L ]` guard is the whole safety: the script can only ever remove a symlink,
never a directory of live code.

---

## 5. Verify battery (keeper runs `--verify` after)

Each asserts the goal, and the refusal assertions **assert the sentence**, per
`feedback-red-first-that-stays-green`.

| # | Check | Expected |
|---|---|---|
| V1 | `readlink -f /srv/lg-stripe-billing` | `= $NEW` (proves the swap, not the old dir) |
| V2 | `/billing/health` | `200 application/json` |
| V3 | `/billing/v1/products` | `200 application/json`, body non-empty |
| V4 | `/billing/v1/config` | `200 application/json` |
| V5 | anon `POST /billing/v1/checkout` | **today: 503** + exact `UNKNOWN_MESSAGE`. After `lgms_shared_secret` is set: **403** + the #181 tester sentence. Any other status/sentence = RED |
| V6 | `POST /billing/v1/webhook`, no signature | **400** invalid-signature (401 = BuddyBoss/auth wrong; 404 = routing wrong) |
| V7 | health panel (WP admin) reads settings | renders, no fatal |
| V8 | **liveness control:** `$NEW/logs/app.log` grows after V2–V5 | proves `logs/` perms are right — without this, V2–V4 pass on a box that cannot log at all |

V8 is deliberate: an absence/"it works" assertion is vacuous without proof the
machinery is live (`feedback-absence-assertion-needs-liveness`).

---

## 6. Files I expect to touch

Guessing wider rather than narrower, per LANE-RULES:

- `handoffs/plans/197-billing-cutover-PLAN.md` (this file)
- `tools/infra/live-billing-cutover.sh` **(new — on approval)**
- `docs/LIVE-BILLING-CUTOVER-2026-08-22.md` **(new — the runbook Ian is handed)**
- `docs/domains/MEMBERSHIP.md` and/or `docs/domains/INFRA.md` (domain-label rule:
  closing #197 updates its domain file in the same commit)
- `handoffs/2026-08-22-197-billing-cutover.md` (final handoff)

**Not touched:** the vhost, the FPM pool, `platform/nginx/*`, any `.env`, and
anything under `config/`. If that changes I will stop and say so.

## 7. Noticed, not fixed

- `lgms_db_pass` is stored **in plaintext in `wp_options`** on live (live
  `lg_membership` DB credentials). Pre-existing, out of scope here, worth its own
  issue.
- The app's `deploy/php-fpm-pool-lg-billing-dev.conf` and
  `nginx-billing-location.conf` are stale (user `ccdev`, `/home/ccdev/...`,
  `dev.loothgroup.com`). They describe no box that exists. Candidate for deletion
  or a "historical" header once the swap lands.
- `/srv/lg-stripe-billing.bak-20260816-183554` on **dev2** is still there from the
  June/August dev2 swap — cleanup is a sweep Ian authorises, not mine.
