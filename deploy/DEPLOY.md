# LIVE deploy — Patreon Sync Report fix (commit efa7970)

**What it changes:** one file —
`wp-content/plugins/lg-patreon-stripe-poller/includes/class-lgpo-sync-engine.php`.
Makes the sync report accurate (real applied vs reconciled, full counts) and stops the no-op
hourly spam (sends only when a real role change or an error occurs). **No DB/schema/option changes.**

**Why a git pull is WRONG here:**
- The poller lives in `wp-content/plugins` — **not** git-served (the git-pull deploy only covers the
  app/overlay tree, not `wp-content`).
- Live's `class-lgpo-sync-engine.php` is **ahead of `main`** by an 18-line `$member += […]` tolerance
  block in `upsert_patreon_member_row()`. Shipping `main`'s file would **drop** that block and
  re-introduce "Undefined array key" on self-connect onboards.

So we patch live's CURRENT file in place. The patcher below only touches the `send_summary` /
`apply_change` / `run` / `execute_approved` regions (which are byte-identical between main and live)
and **leaves the tolerance block intact**. It is self-verifying: each of the 8 hunks must match
exactly once or it aborts without writing.

## Steps (run ON the live box, as the plugin file owner or via sudo)
```bash
cd /var/www/<LIVE_WEBROOT>/wp-content/plugins/lg-patreon-stripe-poller/includes
F=class-lgpo-sync-engine.php

# 1. Back up
cp -p "$F" "$F.bak-$(date +%Y%m%d-%H%M%S)"

# 2. Dry-run check + apply (copy deploy/patch-sync-report.py from this branch to the box first)
python3 /path/to/patch-sync-report.py "$F"        # prints "patched OK: …" or "ABORT:" (no write on abort)

# 3. Lint
php -l "$F"                                        # must say: No syntax errors detected

# 4. Reload opcache
sudo systemctl restart php8.3-fpm                  # (use the live FPM unit/pool name)
```
> dev2 is a recent clone of live, so the path under `wp-content/plugins/...` is identical on both.
> Verified on dev2: the patcher applies cleanly to the live-equivalent served file and `php -l` passes.

## Alternative (drop-in file instead of patcher)
A prebuilt `live-base + fix` file (tolerance block preserved) was generated on dev2 at
`/tmp/deploy_engine.php`. You can `scp` it into place instead of running the patcher — but verify its
SHA against your live file's non-fix regions first. The patcher is preferred (self-verifying).

## Rollback
```bash
cp -p class-lgpo-sync-engine.php.bak-<stamp> class-lgpo-sync-engine.php && sudo systemctl restart php8.3-fpm
```

## After deploy — verify (no email needed)
Trigger one sweep and read the result; do NOT rely on the email:
- Admin → poller "Run Sync Now", or fire the hook. The report now shows
  `Fetched / Matched / Applied / Unchanged / Errors` and lists only REAL role changes under "Applied".
- A no-op sweep sends nothing; a sweep with a real change emails the full report.

## Do NOT
- Do not flip `FluentSMTP simulate_emails` (keep the kill-switch as-is).
- Do not touch the poller's auto-sync schedule — it stays hourly; it just won't email on no-ops now.
