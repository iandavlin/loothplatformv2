# Poller deploy origin — monorepo is the single source, ships as a MUST-USE plugin

> Lane: **poller-monorepo-reconcile**. Status: **runbook + provisioning design — NOT yet
> executed.** Deploy/cutover is gated by keeper + Ian. Prod is LIVE.
>
> **2026-06-27 — promoted to mu-plugin.** Per standing policy (all owned WP plugins ship as
> must-use), the poller now deploys into `wp-content/mu-plugins/` as a **loader + folder**, NOT
> `wp-content/plugins/`. The cutover does this in ONE motion — there is no interim regular-plugin
> state. See "Deploy model" below.

## The problem this closes

Until now the poller **running** on dev2 and prod was a checkout of the **standalone** repo
`git@github.com-patreon-oauth:iandavlin/lg-patreon-stripe-poller.git` @ `1486bc8` (2026-05-14) +
~16–18 uncommitted hand-patches, deployed by **in-place patchers** (`deploy/patch-sync-report.py`,
`deploy/patch-tier-truth.py`) because `main` was historically *behind* live. The monorepo poller
(`loothplatformv2/lg-patreon-stripe-poller`) was an orphan deployed nowhere.

**P1 proved `main` is now byte-identical to running prod** (the hand-patches were already captured;
the historical 18-line tolerance block was folded into main base). So the in-place-patch model is
obsolete and we can make the **monorepo the single source + deploy origin**.

## MUST-USE conversion — what changed (2026-06-27)

The poller is folder-structured (`src/`, `includes/`, `assets/`, `vendor/`). WordPress only
auto-loads PHP files in the **`mu-plugins/` ROOT**, not subdirectories, so the poller ships as two
pieces that land as **siblings** in `mu-plugins/`:

1. **Loader** — `platform/mu-plugins/lg-patreon-stripe-poller.php` → `mu-plugins/lg-patreon-stripe-poller.php`
   A thin must-use loader: pins `LGPO_PLUGIN_FILE/DIR/URL` to the subfolder and
   `require_once`s the real main file `lg-patreon-stripe-poller/lg-patreon-onboard.php`.
   (Same loader+folder pattern as `lg-membership-chrome.php` + `lg-membership-chrome/`.)
2. **Folder** — repo `lg-patreon-stripe-poller/` → `mu-plugins/lg-patreon-stripe-poller/`
   (the full plugin tree incl. `vendor/`).

**No activation/deactivation.** `register_activation_hook` / `register_deactivation_hook` NEVER
fire for mu-plugins. All activation work (DB schema, gift capability, membership pages) moved to an
**idempotent, version-gated self-installer**:

- `LGMS\Plugin::maybeInstall()` — hooked on `init` priority 1 by `boot()`. Gated by the
  `lgpo_schema_version` option against the `Plugin::INSTALL_VERSION` constant (`2.0.0-mu1`). On a
  fresh mu-load it runs `Schema::apply()` (CREATE TABLE IF NOT EXISTS — idempotent),
  `registerGiftCapability()` (add_cap no-ops), `Wp\Pages::ensureAll()` (existence-checked), sets the
  deferred rewrite-flush transient, then stamps `lgpo_schema_version`. Every step is idempotent and
  a concurrent first-request double-run is safe. Bump `INSTALL_VERSION` when install work must re-run.
- **Cron is activation-independent already.** The 5-min tick (`lgms_poll_tick`/`lgms_5min`) is
  (re)created by `Plugin::maybeRescheduleCron()` on `init` 99; the Patreon sweep
  (`lgpo_patreon_auto_sync`) by `LGPO_Sync_Cron::init()` on `init` (schedules iff
  `lgpo_auto_sync_enabled`). Both self-heal on every load — no activation event needed.
- **Deactivation teardown intentionally dropped.** An mu-plugin is permanent and never deactivates,
  so the tick + sweep crons MUST keep running. The off-switches are the runtime option gates
  (`lgms_poller_mail_enabled`, `lgms_stripe_frozen`, `lgpo_auto_sync_enabled`), not (de)activation.
- The path/URL `define()`s in `lg-patreon-onboard.php` are now `!defined()`-guarded so the loader's
  pinned constants win; a direct (regular-plugin) load still computes them correctly.

> **Proven on dev2 (isolated fold run, deployed plugin `--skip-plugins`'d, webroot untouched):**
> mu-loader loads the folder + classes; hook wiring correct (maybeInstall@1, maybeRescheduleCron@99,
> LGPO_Sync_Cron::init@10, lgpo_register_rewrite@10, admin_menu@10, pre_wp_mail gate@10,
> lgms_poll_tick→Tick::run); `lgpo_schema_version` '' → `2.0.0-mu1` with table present + second call
> no-op; BOTH crons recreate from the bootstrap path; one dry-run sweep fetched 3342 / matched 1508
> with 0 mail; `lgms_poller_mail_enabled` OFF + `lgms_stripe_frozen` frozen; `/wp-json/looth/v1/whoami`
> 200. `plugins_url()` resolves to `…/wp-content/mu-plugins/lg-patreon-stripe-poller/` for asset URLs.

## Deploy model — file-sync (rsync) into mu-plugins/, NOT a standalone `git pull`

The poller ships a `vendor/` dir and is folder-structured, so it is **file-synced** from the
monorepo, not symlinked. (Contrast: the lightweight overlay tree is symlink-served.) Live gets
**real files** (the cut never symlinks `wp-content`).

**Source of truth:** `loothplatformv2/lg-patreon-stripe-poller/` (folder) +
`loothplatformv2/platform/mu-plugins/lg-patreon-stripe-poller.php` (loader)
**Target:** `<WEBROOT>/wp-content/mu-plugins/lg-patreon-stripe-poller/` (folder) +
`<WEBROOT>/wp-content/mu-plugins/lg-patreon-stripe-poller.php` (loader)

```bash
# --- poller MU deploy: monorepo -> box (run from a clean monorepo checkout on the target box) ---
REPO=<monorepo checkout>
MU=<WEBROOT>/wp-content/mu-plugins
DEST=$MU/lg-patreon-stripe-poller          # the folder
LOADER=$MU/lg-patreon-stripe-poller.php    # the root loader
STAMP=$(date +%Y%m%d-%H%M%S)

# 0. PRE: drift-check + confirm main == intended release. Per-file backups.
#    (run dev2-drift-check.sh first; abort on unexpected drift)
[ -d "$DEST" ]    && cp -a "$DEST"    "$DEST.bak-$STAMP"
[ -f "$LOADER" ]  && cp -a "$LOADER"  "$LOADER.bak-$STAMP"

# 1. folder file-sync (NEVER delete vendor/.git wholesale; exclude box-local + regenerable)
rsync -a --delete \
  --exclude='.git' --exclude='vendor' --exclude='node_modules' \
  --exclude='*.bak*' --exclude='*.pre-*' --exclude='tick.log' \
  "$REPO/lg-patreon-stripe-poller/" "$DEST/"

# 2. loader (single file, into mu-plugins ROOT)
cp -a "$REPO/platform/mu-plugins/lg-patreon-stripe-poller.php" "$LOADER"

# 3. composer deps — REQUIRED (vendor/ is NOT synced; LGMS\* autoload + stripe-php live there).
#    Ship vendor/ via one of: (a) build on the box, or (b) sync a prebuilt vendor/ (drop the
#    --exclude='vendor' in step 1). Building on the box:
cd "$DEST" && composer install --no-dev --optimize-autoloader
#    Sanity: vendor/autoload.php MUST exist or LGMS\Plugin::boot never hooks (no mail gate, no
#    install, no cron). Verify: test -f "$DEST/vendor/autoload.php"

# 4. REMOVE the OLD regular-plugin copy so the poller is not loaded TWICE (fatal: redeclare).
OLD=<WEBROOT>/wp-content/plugins/lg-patreon-stripe-poller
if [ -d "$OLD" ]; then
  # back it up OUTSIDE wp-content/plugins so WP can't load the backup either
  mv "$OLD" "$HOME/poller-old-plugin.bak-$STAMP"
  # NOTE: this is a file move, NOT a WP "deactivate" — no deactivation hook fires, the crons
  # and options are untouched. The mu copy is already authoritative on the same request.
fi

# 5. lint touched PHP, then reload FPM (opcache)
find "$DEST" "$LOADER" -name '*.php' -not -path '*/vendor/*' -print0 \
  | xargs -0 -n1 php -l | grep -v '^No syntax' || true
sudo systemctl reload php8.3-fpm   # site FPM pool = looth-dev (uid 999)

# 6. POST-deploy smoke (NO email needed) — see "Verify" below
```

> `--delete` is safe in step 1 because the excludes protect `vendor/`, `.git`, and `*.bak*`.
> **Double-load is the #1 risk:** if BOTH `mu-plugins/lg-patreon-stripe-poller/` and
> `plugins/lg-patreon-stripe-poller/` exist, WP fatals on class/function redeclare. Step 4's removal
> MUST happen in the same deploy. After cutover, the box's standalone `.git` and `*.bak-*` twins
> should be removed in a separate, reviewed step.

### Why mu-plugin (not a regular plugin, not a symlink)
Standing policy: all owned WP plugins ship as must-use, so they can't be toggled off in wp-admin and
never (de)activate. The poller backs the tier/role bridge + login — it must always be on. It carries
`vendor/` (Composer) so it's file-synced, not overlay-symlinked.

## ✅ Deploy-safety: this is a PRODUCTION mu-plugin — it MUST ship

`deploy.sh` builds a **marker-driven** exclude list from every file containing the `@lg-dev-only`
header tag, excluding dev2-ONLY / prod-dangerous mu-plugins from live. **The poller loader is NOT
tagged `@lg-dev-only` — it must reach live.** Do not add that marker. (The 5 excluded dev-only
mu-plugins — `lg-poller-mail-killswitch`, `lg-dev-disable-looth1-bounce`, `lg-dev-mail-containment`,
`lg-dev2-power`, `lg-secrets-dash` — stay excluded; the poller loader + folder are separate and ship.)

Confirm at deploy time that the marker-driven logic SHIPS the loader + folder (dry-run rsync should
list `lg-patreon-stripe-poller.php` and `lg-patreon-stripe-poller/` among the included mu-plugins).

Live member/billing mail is held OFF separately via the runtime `lgms_poller_mail_enabled` flag
(poller `Plugin::gateOutboundMail`); Stripe is frozen via `lgms_stripe_frozen` (default true). The
operator failure-alert (`lgpo_alert_failure`) carries `X-LG-Poller-Intent: notify` and always
delivers; bulk poller mail stays suppressed.

## Provisioning / secrets (unchanged, provision-on-target — NEVER committed)
- `/etc/lg-internal-secret` (poller ↔ profile-app shared secret); `setfacl -m u:profile-app:r …`
- WP DB + Patreon/Stripe creds live in `wp-config.php` / WP options on the box, not the repo
  (`lgms_db_*`, `lgpo_creator_access_token`, `lgpo_campaign_id`, etc.).
- Dev mail containment is enforced separately (`lg-dev-mail-containment`, dev-only, excluded from live).

## Retire the standalone repo (like looth-platform)
1. **Archive bundle** — `~/backups/poller-standalone-repo-20260626.bundle` (full history @ `1486bc8`).
2. **Flag the GitHub repo for archive to Ian:** `iandavlin/lg-patreon-stripe-poller`
   (remote alias `github.com-patreon-oauth`) → read-only / archived (Ian action).
3. **Obsolete in-repo deploy glue** — retire the standalone checkout's `.git` on each box and the
   monorepo's `deploy/patch-sync-report.py` + `deploy/patch-tier-truth.py` in-place patchers (with
   main==prod they are a foot-gun). Flag, don't delete blind — keeper review.

## Verify (post MU deploy, no email)
- **Loads as mu:** `wp plugin list --status=must-use` — `lg-patreon-stripe-poller.php` appears under
  Must-Use; the poller is **ABSENT** from the activatable Plugins toggle list (`wp plugin list
  --status=active` does not list it). The subfolder is not a plugin entry.
- **Self-install (no activation):** first request sets `lgpo_schema_version=2.0.0-mu1`; the
  `lg_membership` tables exist (`Schema::apply` idempotent).
- **Cron scheduled from bootstrap:** `wp cron event list` shows `lgms_poll_tick` (lgms_5min) and
  `lgpo_patreon_auto_sync` (per `lgpo_sync_frequency`, when `lgpo_auto_sync_enabled`).
- **Admin pages render:** Settings → "Patreon OAuth" and the LG Member Sync pages load; "Run Sync
  Now" reports `Fetched / Matched / Applied / Unchanged / Errors`; a no-op sweep emails nothing.
- **Bridge healthy:** `/wp-json/looth/v1/whoami` → HTTP 200 (admin login + tier/role unaffected).
- **Mail/Stripe posture:** `lgms_poller_mail_enabled` OFF (bulk suppressed), `lgms_stripe_frozen`
  true (Stripe poll skipped); intentional notices (`X-LG-Poller-Intent`) still deliver.
- `php -l` clean on all touched files (step 5 self-verifies).
- **Rollback** = restore `$DEST.bak-<stamp>` + `$LOADER.bak-<stamp>` (or move the old
  `plugins/` copy back from `~/poller-old-plugin.bak-<stamp>` and remove the mu copies) +
  `systemctl reload php8.3-fpm`.
