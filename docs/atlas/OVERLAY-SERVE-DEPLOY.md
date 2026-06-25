# OVERLAY-SERVE-DEPLOY.md — the docroot static overlay layer (webroot/), repo-first

The docroot **static overlay layer** — `webroot/` in the repo (the 14 overlay JS:
`bottom-nav.js`, `pwa.js`, `hub-polish.js`, `app-mobile-fixes.js`, `mobile-hub.js`,
`app-settings.js`, `sponsor-cards.js`, `hub-infinite.js`, `push.js`, `privacy-sheet.js`,
`profile-sheet.js`, `directory-mobile.js`, `directory-desktop.js`, `guitardle-teaser.js`,
plus `manifest.json`, `sw.js`, `/icons/`, PHP) — is **repo-authoritative**. It used to be
hand-placed file copies in each box's docroot (the drift trap: a stale docroot copy shadowed
the repo). As of **2026-06-25** the serve model is explicit and differs per box:

## Two serve models

| Box | Model | How `bottom-nav.js` etc. are served | Deploy |
|-----|-------|-------------------------------------|--------|
| **dev2** | **PULL / symlink** | `/var/www/dev/<f>` is a **symlink** → `~/loothplatformv2-serve/webroot/<f>` | `git pull` in the serve clone — **zero docroot edits** |
| **live** | **PUSH / rsync** | real files in the live docroot (live has no serve clone for the docroot) | `deploy/deploy.sh` (webroot stanza, **guarded**) |

- **dev2 is pull-only.** All 14 overlays symlink into the pristine serve clone, exactly like the
  `/srv/*` apps (§13). A `git pull` in `~/loothplatformv2-serve` deploys any overlay change with
  **no docroot touch**. Proven 2026-06-25: a `?v=26→27` bump in `pwa.js` reached origin via the
  pull alone (commit `2e5abad`).
- **Cache-bust** is the `?v=N` strings inside `pwa.js` (filenames never change). Bump on change.

## CSS overlays are in the same farm (don't let one drift out)

`webroot/` also holds **CSS** overlays (e.g. `mobile-hub.css`) -- repo-authoritative and symlinked
into the docroot exactly like the JS. They are NOT in the JS list at the top, which is how
`mobile-hub.css` got missed: on **2026-06-25** it was found as a **stale standalone real file** at
`/var/www/dev/mobile-hub.css` (a Jun-7 copy) shadowing the repo -- so weeks of edits to the repo
copy never served. Fixed by symlinking it into the serve clone like its siblings (backup left at
`/var/www/dev/mobile-hub.css.bak-realfile-shadow-20260625`). **Provisioning rule:** the dev2 symlink
farm must include EVERY repo `webroot/` overlay (JS *and* CSS); a real file in the docroot that has
a repo twin is always the drift bug, never intentional.

## The guard (deploy.sh)

`deploy/deploy.sh --apply` now also rsyncs `webroot/` → the live docroot, BUT it **refuses any
target whose overlays are symlinks** (i.e. a pull-driven box / dev2) — `rsync -a` would replace
the symlinks with file copies and silently undo the rewire. So:
- on **live** (real files) → it pushes;
- on **dev2** (symlinks) → it prints `SKIP webroot …` and does nothing.

⚠️ **Never** run `webroot/deploy.sh` (the standalone pusher) against dev2's `/var/www/dev` — it has
no guard and *will* clobber the symlinks. Use `git pull` on dev2. The guard lives only in
`deploy/deploy.sh`.

## LIVE deploy runbook (human-run — live is Claude-free)

Reach live from the keeper box: `ssh live` (→ `54.157.13.77`).

1. **Confirm** the live docroot path where the overlay JS physically sit, and the file owner.
2. **Backup:** `sudo cp -a <live-docroot> <live-docroot>.bak-overlays-$(date +%Y%m%d-%H%M%S)`
   (or tar just the overlay files).
3. **Pull** the repo clone on live to the target commit (`git -C <repo> pull --ff-only origin main`).
4. **DRY-RUN** and READ the delta:
   `cd <repo> && WEBROOT_PATH=<live-docroot> ./deploy/deploy.sh`  (no `--apply`).
   ⚠️ `deploy.sh` ships the **whole static layer** (all 23 webroot entries), not just the file you
   changed — eyeball everything the webroot rsync would touch before applying.
5. **APPLY:** `WEBROOT_PATH=<live-docroot> sudo -E ./deploy/deploy.sh --apply`
   (set `WP_PATH` too if the WP docroot differs; `WEBROOT_OWNER` defaults `looth-dev`).
6. **Verify at ORIGIN, not the CF edge** (Cloudflare caches the query-less URLs immutable for a
   year — `cf-cache-status: HIT` is stale and misleading):
   `curl --resolve loothgroup.com:443:<ORIGIN_IP> https://loothgroup.com/bottom-nav.js?v=<N>` and
   compare md5 to the repo blob; confirm `pwa.js` references the intended `?v=N`.
7. **Cache:** the new `?v=N` is itself the bust for the loaded URL; optionally purge CF for the
   changed `?v` URLs.

**Rollback:** restore the docroot backup + `sudo systemctl reload php8.3-fpm`.

## Preview slots (parallel preview surfaces)

The ONE pristine serve clone doubles as the preview surface (flip a branch onto it to look at WIP),
which serializes previews to one-at-a-time. **Preview slots** break that: a dedicated surface = its
own clone + FPM pool + nginx vhost, reusing the shared dev2 backend, so a second branch previews
without touching the serve clone. (DELIVERY-ARCH-PROPOSAL step (a), realized as a fixed slot.)

**slot-A -- `preview-a.dev2.loothgroup.com`** (first slot, 2026-06-25):

| Piece | Value |
|-------|-------|
| Clone | `~/preview-slots/slot-a` -- `git -C ... fetch && checkout <branch>` to preview |
| FPM pool | `bb-preview-a` -> `php8.3-fpm-bb-preview-a.sock` (user `bb-mirror`, env `LG_BB_MIRROR_PUBLIC_HOST=preview-a.dev2...`) |
| nginx | `sites-available/preview-a.dev2.loothgroup.com.conf` = copy of the dev2 vhost; bb-mirror include swapped -> `snippets/strangler-bb-mirror-preview-a.conf` (repoints `/hub/` + `/bb-mirror-api/` -> slot-a + the bb-mirror frontend socket -> bb-preview-a) |
| Backend | REUSES dev2's WP/looth-dev pool, archive-poc front, PG, R2 -- **only the Hub code differs** |
| DNS | `preview-a.dev2.loothgroup.com` -> `34.193.244.53` (grey-cloud / DNS-only; managed in Cloudflare) |
| TLS | LE cert via `certbot --nginx` (auto-renew). Requires SG `dev2-direct-web` (`sg-02b6d0506402aa626`) inbound **80** open to `0.0.0.0/0` for ACME HTTP-01 -- LEAVE OPEN (renewal needs it). |

**Preview a branch in the slot:** `git -C ~/preview-slots/slot-a fetch && git checkout <branch>` +
`sudo systemctl reload php8.3-fpm` (pool is ondemand). The serve clone is never involved.

**Why slot-a's code renders** (not the serve clone's): bb-mirror includes are all `__DIR__`-relative
(`index.php` -> `_single-topic.php` -> reactions), so `SCRIPT_FILENAME` pointed at slot-a renders
slot-a's tree; `LG_BB_MIRROR_APP_ROOT=/srv/bb-mirror` is only the schema-file path, not request
rendering. Add slot-B (`preview-b.dev2`, own pool) the same way when a third parallel preview is needed.

## Footguns
- `deploy.sh --apply` syncs **all** app subtrees + the full webroot — always backup + dry-run/diff
  on live first.
- Verify at the **origin** (`--resolve … :127.0.0.1` on-box, or the origin IP), never the CF edge.
- dev2 = pull-only; the guard protects it, but don't fight it — `git pull`, don't rsync.

See SYSTEM-MAP §13 (serve-from-repo) and §14 (deploy model).
