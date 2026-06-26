# Poller deploy origin — monorepo is the single source (file-sync), standalone retired

> Lane: **poller-monorepo-reconcile** P4. Status: **runbook + provisioning design — NOT yet
> executed.** Deploy/cutover is gated by keeper + Ian. Prod is LIVE.

## The problem this closes

Until now the poller **running** on dev2 and prod was a checkout of the **standalone** repo
`git@github.com-patreon-oauth:iandavlin/lg-patreon-stripe-poller.git` @ `1486bc8` (2026-05-14) +
~16–18 uncommitted hand-patches, deployed by **in-place patchers** (`deploy/patch-sync-report.py`,
`deploy/patch-tier-truth.py`) because `main` was historically *behind* live. The monorepo poller
(`loothplatformv2/lg-patreon-stripe-poller`) was an orphan deployed nowhere.

**P1 proved `main` is now byte-identical to running prod** (the hand-patches were already captured;
the historical 18-line tolerance block was folded into main base). So the in-place-patch model is
obsolete and we can make the **monorepo the single source + deploy origin**.

## Deploy model — file-sync (rsync), NOT a standalone `git pull`

The poller lives in `wp-content/plugins/` — outside the git-served overlay tree — and ships a
`vendor/` dir, so it is **file-synced** from the monorepo, not symlinked. (Contrast: the mu-plugins
are repo-symlinked into the serve clone.)

**Source of truth:** `loothplatformv2/lg-patreon-stripe-poller/`
**Target:** `<WEBROOT>/wp-content/plugins/lg-patreon-stripe-poller/`

```bash
# --- poller deploy: monorepo -> box (run from a clean monorepo checkout on the target box) ---
REPO=<monorepo checkout>/lg-patreon-stripe-poller
DEST=<WEBROOT>/wp-content/plugins/lg-patreon-stripe-poller

# 0. PRE: confirm main == intended release; take a backup of the live tree
cp -a "$DEST" "$DEST.bak-$(date +%Y%m%d-%H%M%S)"

# 1. file-sync (NEVER delete vendor/.git wholesale; exclude box-local + regenerable)
rsync -a --delete \
  --exclude='.git' --exclude='vendor' --exclude='node_modules' \
  --exclude='*.bak*' --exclude='*.pre-*' --exclude='tick.log' \
  "$REPO/" "$DEST/"

# 2. composer deps (only if composer.json changed; vendor is NOT synced)
#    cd "$DEST" && composer install --no-dev --optimize-autoloader

# 3. lint the touched PHP, then reload opcache
find "$DEST" -name '*.php' -not -path '*/vendor/*' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax' || true
sudo systemctl reload php8.3-fpm   # live FPM pool = looth-dev (uid 999); dev2 pool = looth-dev

# 4. POST-deploy smoke (NO email needed) — see "Verify" below
```

> `--delete` is safe here because the excludes protect `vendor/`, the box's `.git`, and `*.bak*`.
> After cutover the box's standalone `.git` and `*.bak-*` twins should be **removed** (they are the
> old dual-source vestige) — do that in a separate, reviewed step, not as part of the first sync.

### Why not symlink-serve like the mu-plugins?
The poller carries `vendor/` (Composer) and is a full WP plugin; the overlay-serve symlink model
(`docs/atlas/OVERLAY-SERVE-DEPLOY.md`) is for the lightweight app/overlay tree. Live gets **real
files** (the cut never symlinks `wp-content` on live). File-sync matches how `deploy.sh` already
pushes the standalone apps to `/srv` on live.

## Provisioning / secrets (unchanged, provision-on-target — NEVER committed)
- `/etc/lg-internal-secret` (poller ↔ profile-app shared secret); `setfacl -m u:profile-app:r …`
- WP DB + Patreon/Stripe creds live in `wp-config.php` / WP options on the box, not the repo.
- Dev mail containment is enforced separately (see the mu-plugins below + `lg-dev-mail-containment`).

## Retire the standalone repo (like looth-platform)
1. **Archive bundle** — already taken: `~/backups/poller-standalone-repo-20260626.bundle` (full
   history @ `1486bc8`). Keep it with the other lane snapshots.
2. **Flag the GitHub repo for archive to Ian:** `iandavlin/lg-patreon-stripe-poller`
   (remote alias `github.com-patreon-oauth`). Mark **read-only / archived** on GitHub so no new
   work lands there. (Ian action — same as the looth-platform retirement.)
3. **Obsolete in-repo deploy glue** — once this file-sync model is adopted, retire/delete:
   `lg-patreon-stripe-poller` standalone checkout's `.git` on each box, and the monorepo's
   `deploy/patch-sync-report.py` + `deploy/patch-tier-truth.py` in-place patchers (they exist
   only because main was behind live; with main==prod they are a foot-gun). Flag, don't delete
   blind — keeper review.

## ✅ Deploy-safety: dev-only mu-plugins excluded from the live sync (RESOLVED 2026-06-26, Q7)
`deploy.sh` now builds a **marker-driven** exclude list from every file containing the
`@lg-dev-only` header tag, so dev2-ONLY / prod-dangerous mu-plugins never reach live (future
dev-only files auto-exclude — just add the tag). Verified by dry-run rsync: these 5 are excluded,
20 legit mu-plugins still ship:

- `lg-poller-mail-killswitch.php` (suppresses poller mail — would mute live notifications)
- `lg-dev-disable-looth1-bounce.php` (opens looth1 login — gated by design on live)
- `lg-dev-mail-containment.php` (redirects ALL mail to mailpit — would blackhole live email)
- `lg-dev2-power.php` (dev2 EC2 wake/sleep — meaningless/confusing on live)
- `lg-secrets-dash.php` (secret-bearing dev tool — never ship to live)

Live member/billing mail is held OFF separately via the runtime `lgms_poller_mail_enabled` flag
(poller `Plugin::gateOutboundMail`), NOT the excluded killswitch — see the remediation README
“Mail posture on live.”

## Verify (post file-sync, no email)
- Admin login works; `/whoami` unaffected (the poller backs the tier/role bridge).
- Trigger one sweep (Admin → poller "Run Sync Now"): report shows
  `Fetched / Matched / Applied / Unchanged / Errors`; a no-op sweep emails nothing.
- `php -l` clean on all touched files (step 3 self-verifies).
- Rollback = restore `$DEST.bak-<stamp>` + `systemctl reload php8.3-fpm`.
