# Looth Group — Cut to a New Box From Scratch

*Authoritative, ordered, executable runbook. Synthesized 2026-06-16 from a read-only inventory of the WORKING dev1 box (claude.loothgroup.com, 50.19.198.38). Source of truth = dev1's served filesystem; the dev2 build was hand-assembled and DRIFTED — its mistakes are encoded as gotchas inline.*

> **The one-line lesson:** git covers the Slim/PHP apps (`/srv/*` symlinks → one monorepo checkout, deploy = `git pull`). git does **NOT** cover WordPress `wp-content` (plugins, mu-plugins, looth-vendor, themes), the served poller working tree, nginx confs, `/etc` secrets, the rclone mount, OS groups/ACLs, or host-pinned values inside plugin PHP. Every one of those must be carried/recreated by hand. **Never reassemble `wp-content` from memory — bundle dev1's served tree wholesale.**

---

## Phase 0 — Decisions & Prerequisites

### 0.1 The model: NEW box + Cloudflare origin flip (NOT in-place)

DECIDED (Ian 2026-06-13, DEPLOY-PLAN.md, supersedes all earlier "in-place / no second box" language — treat in-place as DEAD, along with the dead `LIVE-DEPLOY-PLAN.md`):

- Stand up a **fresh box**, build it to parity with dev1, verify it BEFORE any user sees it, then flip.
- **The flip is a Cloudflare ORIGIN-IP change**, not a public DNS/A-record/TTL event. `loothgroup.com` is Cloudflare-PROXIED → changing the CF origin IP (old-live → new box) is **instant at the edge, no TTL/propagation wait**. Rollback = point CF origin back (seconds).
- **DEAD step:** "lower DNS TTL to 60–120s" and "raise TTL back" — that is an unproxied-DNS model and does NOT apply here. Ignore it wherever the older PHASE-11 runbook says it.

> **CUT LAW (survives any mechanism):** flip the CF origin **and** `WP_HOME`/`WP_SITEURL` in the SAME window. Origin alone → WP redirects every request to the old `siteurl` → redirect loop / wp-admin lockout. `WP_HOME`/`WP_SITEURL` are pinned as wp-config CONSTANTS specifically as the one-line safety net.

### 0.2 Env/host decoupling — the prod box deliberately runs ENV=dev

This is the single most counterintuitive fact. The apps select config by env string (`LG_*_ENV`), which selects **PATHS only** (`/var/www/dev`, user `looth-dev`). The public HOST is request-derived from `HTTP_HOST`. **At cut, the prod box keeps `ENV=dev`** (serves from `/var/www/dev` + `looth-dev` while its public host is `loothgroup.com`). There is **no "live"/"newprod" code branch**.

- Dev-detection strings baked into each `config.php`: hostname prefix `dev.`, `claude.loothgroup`, or internal `ip-172-31-81-87`.
- **Cut shape on every app pool: `env[LG_*_ENV]=dev` + `env[LG_*_PUBLIC_HOST]=loothgroup.com`.** `LG_*_PUBLIC_HOST` feeds CLI/cron loopbacks that have no `HTTP_HOST` (curl Host header, visibility sync) — without it they silently hit the dev host or fail-open.
- If neither a dev-name nor `LG_*_ENV=dev` is set, apps wrongly resolve `'live'` and hit nonexistent DBs.

### 0.3 Born-right design rules (so dev2's failure classes can't recur)

1. **All apps + the `/var/www/dev` overlay from ONE git checkout of `main` from day one.** Every `/srv/*` `readlink -f` must land inside the checkout; deploy = `git pull`. Verify `git rev-parse HEAD == origin/main`.
2. **nginx from a known git baseline** (symlinks into `platform/nginx/` + two flat app copies) — never per-box `sed`-patched flat copies (dev2's snippet-divergence class).
3. **Decide the data top-off mechanism up front** (full live dump→import vs live-read via `/etc/lg-topoff.conf`).
4. **Carry every `wp-content/{plugins,mu-plugins}` symlink target AND the poller plugin explicitly** — neither is on `main`.

### 0.4 Prerequisites to have in hand before starting

- The **live** WP DB dump (final top-off taken at freeze), live PG top-off for `profile_app` + `looth`.
- LIVE's **8 wp-config salts** and the **JWT keypair** `/etc/looth/jwt-{private,public}.pem` (stage at `/home/ubuntu/cut-staging/`, mode 600) — carry verbatim to avoid a re-mint / re-login storm.
- All `/etc/lg-*` secret VALUES (pull from dev1 `sudo cat` or live), the CF API token, a **live-scoped rotated R2 token**, Patreon client id/secret + creator tokens, (if billing un-parked) live Stripe keys.
- Rollback anchors: tags `pre-a2-real-20260615`, `pre-a2-main` (3a5817e).

### 0.5 Platform version matrix (match exactly)

| Component | Version |
|---|---|
| OS | Ubuntu 24.04 LTS (24.04.4) |
| nginx | 1.24.0 (Ubuntu) |
| PHP | 8.3.6 NTS (`php8.3-fpm`) |
| WordPress core | 6.9.4 |
| WP-CLI | 2.12.0 |
| MariaDB | 10.11.14 |
| PostgreSQL | 16.14 (cluster 16/main) |
| Redis | 7.0.15 |
| rclone | **v1.74.3** (hand-dropped; NOT apt's 1.60) |
| Composer | 2.7.1 |
| Node | v20.20.2 |
| certbot | 2.9.0 + dns-cloudflare 2.0.0 |

---

## Phase 1 — Provision OS (users, groups, packages, the ACL gotchas)

### 1.1 Packages

```bash
apt-get update
apt-get install -y \
  nginx postgresql postgresql-client postgresql-16 postgresql-client-16 \
  mariadb-server redis-server acl unzip zip \
  certbot python3-certbot-nginx python3-certbot-dns-cloudflare \
  php8.3-fpm php8.3-cli php8.3-common \
  php8.3-bcmath php8.3-bz2 php8.3-curl php8.3-gd php8.3-gmp \
  php8.3-intl php8.3-ldap php8.3-mbstring php8.3-mysql php8.3-opcache \
  php8.3-pgsql php8.3-readline php8.3-sqlite3 php8.3-xml php8.3-zip \
  php8.3-igbinary php8.3-imagick php8.3-memcached php8.3-msgpack php8.3-redis
```

> **CUT-CRITICAL — `acl` is required** for the traversal + tree ACLs below. `php8.3-pgsql` + `php8.3-sqlite3` are non-negotiable (archive-poc reads both PG and SQLite).

Out-of-band binaries (NOT apt — version matters):
- **rclone v1.74.3** → `/usr/bin/rclone` (root:root 0755). **v1.60 crash-loops / 501s on R2 `SetModTime` with `--vfs-cache-mode full`.** Pull the official static build.
- **WP-CLI 2.12.0** → `/usr/local/bin/wp` (0755).
- **Composer 2.7.1** → `/usr/bin/composer`; **Node v20.20.2** + npm.

### 1.2 OS users (app pool / service accounts — names must match exactly)

```bash
for u in looth-dev archive-poc profile-app events bb-mirror membership tool-dev; do
  useradd --system --shell /usr/sbin/nologin "$u"
done
# ccdev is an interactive (bash) admin account; the lg-billing-dev FPM pool runs as ccdev.
```

| User | Home | Pool |
|---|---|---|
| `looth-dev` | `/home/looth-dev` | WP (`looth-dev`) |
| `archive-poc` | `/nonexistent` | `archive-poc` |
| `profile-app` | `/srv/profile-app` | `profile-app` |
| `events` | `/home/events` | `events` |
| `bb-mirror` | `/home/bb-mirror` | `bb-mirror` |
| `membership` | `/home/membership` | `membership` |
| `tool-dev` | `/home/tool-dev` | `tool-dev` |
| `ccdev` | `/home/ccdev` (bash) | `lg-billing-dev` |

> There is **no** `billing-svc`, `sheets-bot`, or `looth-live` OS user. (`looth-live` is a live-box convention only; the prod box keeps `looth-dev`.)

### 1.3 Groups + the www-data traversal membership — CUT-CRITICAL (lives in no config)

```bash
groupadd --system looth-dev
groupadd --system loothdevs        # the mount --gid 1005 references THIS group by number
groupadd --system tool-dev

usermod -aG looth-dev www-data
usermod -aG tool-dev  www-data
usermod -aG loothdevs www-data
```

> dev1: `id www-data` → `33(www-data),988(looth-dev),985(tool-dev),1005(loothdevs)`. **If www-data is not in these groups, nginx returns 403 serving the 2770 `wp-content` and the app static trees.** Re-apply on every box; restart nginx + fpm after.

### 1.4 Home-dir traversal ACL — CUT-CRITICAL

`/home/ubuntu` is `0751` (`drwxr-x--x+`), NOT world-traversable. App code lives under it and `/srv/profile-app` symlinks into it. The traversal is a POSIX ACL, not `chmod o+x`:

```bash
setfacl -m u:www-data:--x /home/ubuntu
setfacl -m u:looth-dev:--x /home/ubuntu
```

> Without this, FPM pools running as `profile-app`/`www-data` cannot resolve `/srv/profile-app` → 404/500 / "File not found". (`chmod o+x /home/ubuntu` also works since `other::--x` is set, but the per-user ACL is dev1's authoritative mechanism.)

### 1.5 Document-root tree ownership + default ACLs

```bash
chgrp loothdevs /var/www/dev && chmod 2775 /var/www/dev
setfacl -m    g:looth-dev:rwx -m    g:loothdevs:rwx /var/www/dev
setfacl -d -m g:looth-dev:rwx -d -m g:loothdevs:rwx /var/www/dev   # inherited default
```

`/srv` stays `root:root 0755`. The `/srv/profile-app` + `/srv/bb-mirror` symlinks are created in Phase 3 after the code tree is in place.

---

## Phase 2 — Serving stack (nginx + php-fpm pools, the R2 uploads mount)

### 2.1 PHP-FPM: disable the stock pool, create the log dir

```bash
install -d -o root -g root /var/log/php-fpm
# rename the stock www.conf out of the way (dev1 ships www.conf.disabled)
mv /etc/php/8.3/fpm/pool.d/www.conf /etc/php/8.3/fpm/pool.d/www.conf.disabled 2>/dev/null || true
```

### 2.2 The FPM pools — one per app

Drop 8 pool confs into `/etc/php/8.3/fpm/pool.d/` (root:root 0644). All sockets are `/run/php/php8.3-fpm-<name>.sock` with **`listen.owner=www-data listen.group=www-data listen.mode=0660`** (nginx is www-data and must reach every socket). `catch_workers_output=yes`, `request_terminate_timeout=60s`, `pm.max_requests=500`. `pm=ondemand` except `looth-dev` (`pm=dynamic`).

| Pool | user:group | pm / max_children | memory | special env / notes |
|---|---|---|---|---|
| `archive-poc` | `archive-poc` | ondemand / 8 (idle 5s) | 256M, up/post 64M | **`env[LG_ARCHIVE_POC_DSN]="pgsql:host=/var/run/postgresql;dbname=looth"`** |
| `bb-mirror` | `bb-mirror` | ondemand / 10 | 256M | — |
| `events` | `events` | ondemand / 6 | 128M | — |
| `membership` | `membership` | ondemand / 6 | 128M | — |
| `profile-app` | `profile-app` | ondemand / 12 | 256M | — |
| `tool-dev` | `tool-dev` | ondemand / 4 | 256M, up/post 64M | — |
| `lg-billing-dev` | `ccdev` | ondemand / 4 (idle 10s) | 128M | **`clear_env = no`** (loads `.env` via dotenv) |
| `looth-dev` | `looth-dev` | **dynamic** / 8, start 2, minspare 2, maxspare 4 | 512M, up/post 64M | WP; `error_log → /var/www/dev/wp-content/debug.log` |

Per-pool error logs → `/var/log/php-fpm/<pool>-error.log` (except `looth-dev` as noted).

> **CUT env block on every pool** (per Phase 0.2): add `env[LG_<APP>_ENV]=dev` AND `env[LG_<APP>_PUBLIC_HOST]=loothgroup.com`. Include the **`looth-dev` (WP) pool** — its bb-mirror-api endpoints (`auth`/`topic`/`reply`) 500 without `LG_BB_MIRROR_ENV=dev`, which was THE "Sign in to post" cause on dev2. `LG_*_PUBLIC_HOST` must also go on bb-mirror / archive-poc / events pools and the reconcile/vis-refresh timer units.

```bash
systemctl enable --now php8.3-fpm
```

### 2.3 The R2 uploads FUSE mount — CUT-CRITICAL (no S3-offload plugin exists)

`wp-content/uploads` is a **symlink** to a live rclone FUSE mount of R2. WP/BuddyBoss writes, avatars, hub media, and the `img.php` resizer cache all pass straight through it. **If the mount is down, lg-apps' `require`-from-uploads fatals WP site-wide** — bring it up before fpm serves.

rclone config `/home/ubuntu/.config/rclone/rclone.conf` (ubuntu:ubuntu **0600**):
```ini
[r2]
type = s3
provider = Cloudflare
access_key_id = <SECRET>
secret_access_key = <SECRET>
endpoint = https://2b34fc01f7fc32230a76c1490ac64b13.r2.cloudflarestorage.com
region = auto
acl = private
no_check_bucket = true      ; REQUIRED — without it, R2 PUT → 403
disable_checksum = true
```
> At cut, the new box mounts the **LIVE uploads bucket** with a **live-scoped, rotated R2 token** (`r2live` remote is the template). The dev `r2` token is IP-locked + scoped to `loothgroup-uploads-dev` ONLY and cannot touch live.

Mount service `/etc/systemd/system/r2-uploads-dev.service` (root:root 0644), runs `User=ubuntu`, `Type=notify`:
```
ExecStart=/usr/bin/rclone mount r2:loothgroup-uploads-dev /mnt/loothgroup-uploads-dev \
  --config /home/ubuntu/.config/rclone/rclone.conf \
  --allow-other --uid 999 --gid 1005 --umask 022 \
  --dir-cache-time 12h \
  --vfs-cache-mode full --vfs-cache-max-size 4G \
  --log-level INFO --log-file /home/ubuntu/r2-mount.log
ExecStop=/bin/fusermount -uz /mnt/loothgroup-uploads-dev
Restart=on-failure
RestartSec=3
TimeoutStartSec=180
WantedBy=multi-user.target
```
> `--uid 999 --gid 1005` surface files as `looth-dev:loothdevs` (WP writes as itself). `--vfs-cache-mode full` (NOT `writes` — `writes` made hub render slow) is safe only on rclone ≥1.74. At cut, repoint the ExecStart remote/path to the live bucket.

```bash
grep -q '^user_allow_other' /etc/fuse.conf || echo user_allow_other >> /etc/fuse.conf
install -d -o ubuntu -g ubuntu /mnt/loothgroup-uploads-dev
systemctl enable --now r2-uploads-dev.service
findmnt /mnt/loothgroup-uploads-dev    # MUST be mounted before fpm serves
```

The `uploads` symlink (recreated in Phase 4 after `wp-content` is laid down):
```bash
ln -sfn /mnt/loothgroup-uploads-dev /var/www/dev/wp-content/uploads   # rm -rf any real dir first; ln -sfn silently no-ops if a real dir exists
chown -h looth-dev:loothdevs /var/www/dev/wp-content/uploads
```

---

## Phase 3 — Deploy code (git clone main, repoint /srv symlinks)

### 3.1 Clone the monorepo + worktree + the two standalone repos

```bash
# monorepo — ALL first-party apps except thumb-app / lg-stripe-billing
git clone github-looth:iandavlin/looth-platform.git /home/ubuntu/projects   # owner ubuntu:ubuntu
git -C /home/ubuntu/projects checkout main
git -C /home/ubuntu/projects rev-parse HEAD   # must == origin/main

# bb-mirror is served from the bespoke-cutover worktree
git -C /home/ubuntu/projects worktree add /home/ubuntu/worktrees/bespoke-cutover bespoke-cutover
# verify the hub landed on main (no bespoke/main split): empty diff = good
git -C /home/ubuntu/projects diff --quiet origin/main origin/bespoke-cutover -- bb-mirror/

# thumb-app — own repo, own branch
git clone github-thumbnails:iandavlin/thumbnail-gen-editor.git /srv/thumb-app
git -C /srv/thumb-app checkout feature/per-user-namespacing
# lg-stripe-billing — own repo
git clone https://github.com/iandavlin/lg-stripe-billing.git /srv/lg-stripe-billing

# setgid + loothdevs group on the two standalone trees
chgrp -R loothdevs /srv/thumb-app /srv/lg-stripe-billing
find /srv/thumb-app /srv/lg-stripe-billing -type d -exec chmod 2775 {} \;
```

### 3.2 The /srv symlinks (recreate exactly)

```bash
ln -s /home/ubuntu/projects/archive-poc          /srv/archive-poc
ln -s /home/ubuntu/projects/profile-app          /srv/profile-app
ln -s /home/ubuntu/projects/events               /srv/events
ln -s /home/ubuntu/projects/lg-shared            /srv/lg-shared
ln -s /home/ubuntu/worktrees/bespoke-cutover/bb-mirror /srv/bb-mirror
```
Plus real (non-symlink) dirs to create: `/srv/lg-push` (buck:loothdevs 2775), `/srv/lg-sudo-queue` (root:loothdevs 2775).

> **Profile media lives in R2 now — NOT a local `/srv/profile-app-media` dir** (changed 2026-06-17: local EBS won't scale + would be fragile to migrate post-launch). profile-app reads/writes a **DEDICATED** R2 bucket (`loothgroup2-0-profile-bucket`) via the S3 SDK (`profile-app/src/R2.php`, SigV4 — **not** the FUSE mount), key shape `profile-media/<class>/<uuid>/<file>`. Dedicated (not the uploads bucket) for least-privilege: profile-app takes user uploads + can delete, so its scoped token must not reach WP/forum media. The resizer `.cache/` stays **local** (regenerable). Creds = a scoped R2 token in an `/etc/looth` secret → `LG_PROFILE_R2_*` env (Phase 6). One-time data load: `rclone copy` dev1's `/srv/profile-app-media` → the bucket (Phase 5).

> **membership-pages has NO /srv symlink** — nginx aliases it directly from `/home/ubuntu/projects/membership-pages/web/`. Do not create `/srv/membership`.
> Do NOT recreate `/srv/lg-shared.pre-symlink-*` or any `*.bak-*` artifacts.

### 3.3 composer install where a lock exists

```bash
sudo -u profile-app composer install -d /home/ubuntu/projects/profile-app --no-dev
# lg-stripe-billing (Slim) likewise if revived
```

> Deploy model going forward = `git pull` in `/home/ubuntu/projects` (+ the worktree). thumb-app and lg-stripe-billing pull their own repos separately.

---

## Phase 4 — Replicate the WordPress layer (THE lesson)

> `/var/www/dev` is **NOT a git repo.** None of `wp-content` (plugins, mu-plugins, looth-vendor, themes) is in version control. It cannot be `git pull`-ed — bundle it from dev1 wholesale. dev2 hand-assembled it and drifted (missing `looth-auth-issue.php` → members render anon; stale poller → onboard anon; host-pinned logo).

### 4.1 WP layer facts

- WP root `/var/www/dev`, `wp-config.php` at `/var/www/dev/wp-config.php`.
- `wp-content/{plugins,themes,mu-plugins}` = **`looth-dev:loothdevs`, mode 2770 (setgid)**. All reads need sudo; all WP-CLI runs as `sudo -u looth-dev wp --path=/var/www/dev …`.
- Individual mu-plugin files are a MIX of `looth-dev:loothdevs` and `www-data:www-data` (e.g. `profile-auth.php`, `lg-comments-frame.php`, `looth-auth-issue.php` are www-data). Preserve owners on copy.
- Active theme = **`twentytwentyfive`** (v1.4). BuddyBoss themes are inactive but copied for rollback. (Inventory note: the strangler chrome carries the front-end, not the BB theme; one section listed BB-theme as active — `twentytwentyfive` is what dev1 actually has active. Copy the whole `themes/` dir either way.)

### 4.2 Bundle on dev1 (preserve owners/modes, EXCLUDE uploads)

```bash
sudo tar \
  --exclude='*.bak-*' --exclude='*.pre-*' --exclude='*.removed-*' --exclude='*.deprecated-*' \
  --exclude='*/.git' --exclude='*/cache/*' --exclude='debug.log' --exclude='*.log' \
  --exclude='ai1wm-backups' --numeric-owner \
  -czpf /home/ubuntu/projects/live-bundle/wp-content-bundle.tgz \
  -C /var/www/dev/wp-content plugins mu-plugins themes
# plugins ~834MB, themes ~111MB, mu-plugins ~1.9MB.
# The poller's DIRTY working tree is included verbatim — that is the point (see 4.5).

sudo cp -a /var/www/dev/wp-content/object-cache.php /home/ubuntu/projects/live-bundle/
sudo cp -a /var/www/dev/wp-config.php               /home/ubuntu/projects/live-bundle/wp-config.php.dev1  # REDACT secrets before reuse
```

> **Symlink trap:** `wp-content/plugins/{lg-layout,lg-layout-v2,lg-legacy-import,lg-snippets}` are symlinks into `/home/ubuntu/projects`. `tar` stores them as symlinks → they dangle unless the monorepo checkout exists at the same path. Either (a) recreate the same checkout layout (Phase 3 already does), or (b) re-bundle those four with `tar -h` (dereference) into real dirs. Enumerate before cut: `find wp-content/{plugins,mu-plugins} -type l`.

### 4.3 Lay down on the new box + fix ownership

```bash
sudo tar -xzpf /tmp/wp-content-bundle.tgz -C /var/www/dev/wp-content --same-owner
sudo chown -R looth-dev:loothdevs /var/www/dev/wp-content/{plugins,mu-plugins,themes}
sudo find /var/www/dev/wp-content/{plugins,mu-plugins,themes} -type d -exec chmod 2770 {} \;
sudo find /var/www/dev/wp-content/{plugins,mu-plugins,themes} -type f -exec chmod 0660 {} \;
```
Recreate the `uploads` symlink (Phase 2.3). Drop-ins `object-cache.php` (+ `advanced-cache.php`) only after Redis is up, else WP fatals on a missing Redis socket.

### 4.4 The cut-critical mu-plugins (a missing one fails SILENT)

Must be present (20 total + the Redis dropin). The two that bit dev2:
- **`looth-auth-issue.php`** — non-REST `GET /looth-auth/issue?return=…` JWT minter. Survives DB reload with zero manual steps; the REST `/wp-json/looth/auth/issue` it replaces re-breaks every DB reload (BuddyBoss private-REST 401 + missing X-WP-Nonce on top-level nav). **Without it every member whose `looth_id` is missing/rotated renders as guest.** A from-scratch box importing live's DB WILL hit this.
- **`profile-auth.php`** — the canonical JWT minter (see Phase 7).

Others: `archive-poc-sync.php`, `bb-mirror-sync.php`, `profile-sync.php`, `profile-whoami-shim.php`, `lg-article-materializer.php`, `lg-comments-frame.php`, `lg-viewer-tier.php`, `lg-weekly-email-bridge.php`, `lg-event-reminders.php`, `lg-error-pages.php`, `lgpo-set-password.php`, **`lg-membership-chrome.php` + the `lg-membership-chrome/` subdir** (loads `template.php` + `stripe-panel-template.php` via `__DIR__` — copy the subdir alongside the loader), `lg-admin-tools.php`, `lgms-admin-view-as-toggle.php`, `loothdev-sheets-bridge.php`, `buddyboss-performance-api.php`, `burst_rest_api_optimizer.php`, `bb-forum-author-delete.php`.
Do NOT ship `*.bak-*`, `*.removed-*`, `*.pre-*`, `*.gs.txt` siblings.

> A sync helper exists on dev1: `/var/www/dev/.well-known/dev2-mu-plugins-sync.sh` (6/16) copies `platform/mu-plugins/*.php` from the clone into `wp-content/mu-plugins` (idempotent). The bundle is more complete (it carries www-data-owned files + the chrome subdir); use the bundle as primary and this script as a drift catcher.

### 4.5 The poller trap — served working tree ≠ git HEAD

`wp-content/plugins/lg-patreon-stripe-poller` is its own git repo (branch `main`), but the **served tree diverges heavily from HEAD** and a clone/`git pull` will NOT reproduce it:
- Onboard auto-login + `/patreon-password/` redirect lives ONLY in the working tree (uncommitted `M` in `lg-patreon-onboard.php`: `wp_safe_redirect(home_url('/patreon-password/'))` + `lgpo_login_user()` → `wp_set_auth_cookie()`).
- Untracked-but-live: `src/Patreon/`, `src/PurgeNotifier.php`, `src/UserLifecycle.php`, `src/Wp/InternalRestController.php`, `src/Wp/AdminRoleCapture.php`, `src/Wp/UserLifecycleAdmin.php`, `includes/campaign-filter.php`, `tests/`.
- ~16 modified tracked files.

> **Carry the poller from dev1's FILESYSTEM (it's in the 4.2 bundle), NOT from a git clone.** This CONTRADICTS the older checklist's "poller parked, nothing to port" — the **Patreon-onboard half is load-bearing for login** even with Stripe parked. dev1 also ships `/var/www/dev/.well-known/dev2-poller-deploy.sh` (6/16) that deploys the login-proven poller bundle (config is in WP options, so a file swap doesn't touch creds).

### 4.6 looth-vendor — the off-git Composer dir (THE JWT dependency)

`/var/www/dev/wp-content/mu-plugins/looth-vendor/` (looth-dev:looth-dev, mode 2750). NOT in git. `profile-auth.php` and `looth-auth-issue.php` both `require_once __DIR__.'/looth-vendor/autoload.php'`. **No vendor dir = no JWT minting = the entire `looth_id` cross-app auth chain is dead.** Pin these versions (from `looth-vendor/composer/installed.json`):
- `firebase/php-jwt` **v7.0.5**, `ramsey/uuid` **4.9.2** (+ `ramsey/collection` 2.1.1, `brick/math` 0.14.8).

> **Copy the dir verbatim** (it's in the bundle). A fresh `composer install` may pull different point releases. If you must `composer install`, verify versions match before trusting auth.

### 4.7 wp-config constants

- DB: `DB_NAME=looth_import`, `DB_USER=looth_dev_user`, `DB_HOST=localhost`, `$table_prefix='wp_'`.
- URLs: `WP_HOME`/`WP_SITEURL` — pin them as **CONSTANTS** at cut (the safety net, Phase 9). On dev they live in the DB (`siteurl`=`home`=`https://dev.loothgroup.com`).
- Salts: all 8 + `WP_CACHE_KEY_SALT` inline — **carry LIVE's 8 salts** (Phase 6) to keep WP cookies valid across the cut.
- Cache: `WP_CACHE=true`, `WP_REDIS_HOST=127.0.0.1`, `WP_REDIS_PORT=6379`, `WP_REDIS_DATABASE=1`.
- Debug: `WP_DEBUG=true`, `WP_DEBUG_LOG=true`, `WP_DEBUG_DISPLAY=false`.
- LG: `LG_INTERNAL_SECRET` via `@file_get_contents('/etc/lg-internal-secret')`; `LG_PROFILE_APP_URL='https://127.0.0.1'`.
- `AS3CF_SETTINGS` AKIA key is **DEAD/unused** — do not rotate or rely on it (uploads go through the rclone mount).

### 4.8 Host-pinned values inside plugin FILES (a DB search-replace MISSES these)

1. **bb-mirror logo ternary** — `/srv/bb-mirror/web/_chrome.php` (~lines 499, 617): `LG_BB_MIRROR_ENV === 'dev' ? <dev logo URL> : <live logo URL>`. Because the prod box runs `ENV=dev`, a faithful replica serves the DEV logo. `LG_BB_MIRROR_PUBLIC_HOST` only feeds the curl Host header — it does NOT flip this ternary. Fix at cut: edit the ternary or make the logo path host-derived.
2. **Poller dev fallbacks** — `src/PurgeNotifier.php:50`, `src/UserLifecycle.php:343` & `:695` fall back to `'dev.loothgroup.com'` when `LG_PROFILE_APP_PUBLIC_HOST` is unset. Set `LG_PROFILE_APP_PUBLIC_HOST=loothgroup.com` in the FPM pool **and** cron env, or these CLI/cron callbacks (no `HTTP_HOST`) hit dev.
3. **Onboard copy** — `lg-patreon-onboard.php` lines 524, 751, 1182 hardcode "loothgroup.com" (cosmetic; correct at prod, wrong on a dev-named replica). The redirect uses `home_url()` (DB-derived, safe once siteurl is set).

---

## Phase 5 — Restore data (MySQL + Postgres, the GRANTs, SQLite reindex)

### 5.1 MySQL / MariaDB

Auth: apps connect over **TCP 127.0.0.1 + password** (except read-only `profile-app` which uses `unix_socket`). Socket `/run/mysqld/mysqld.sock`.

Databases to carry: **`looth_import`** (the live WP DB, `DB_NAME`, 293 tables — replaced by a fresh live dump at cut), **`lg_membership`** (Stripe/Patreon billing + entitlements), `looth_dev` (alternate WP DB referenced by billing as `WP_DB_NAME` + granted to profile-app), `loothtool_dev` (only if standing up loothtool). Do NOT migrate `looth_restore_tmp`.

```bash
# DUMP (on the source)
mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4 looth_import  | gzip > looth_import.sql.gz
mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4 lg_membership | gzip > lg_membership.sql.gz

# RESTORE
mysqladmin create looth_import;  zcat looth_import.sql.gz  | mysql looth_import
mysqladmin create lg_membership; zcat lg_membership.sql.gz | mysql lg_membership
```

> Keep `--default-character-set=utf8mb4`; do NOT normalize collations across DBs — `looth_dev`/`looth_import` differ from `lg_membership` and profile-app's cross-DB JOINs break otherwise.

**GRANTs are NOT in the data dump — re-apply by hand** (keep existing password hashes or reset + update `.env`):
```sql
CREATE USER 'looth_dev_user'@'localhost'     IDENTIFIED BY '<from .env>';
GRANT ALL PRIVILEGES ON `looth_dev`.*    TO 'looth_dev_user'@'localhost';
GRANT ALL PRIVILEGES ON `looth_import`.* TO 'looth_dev_user'@'localhost';

CREATE USER 'lg_membership'@'localhost'      IDENTIFIED BY '<from .env>';
GRANT ALL PRIVILEGES ON `lg_membership`.* TO 'lg_membership'@'localhost';

-- READ-ONLY cross-app account, unix_socket auth:
CREATE USER 'profile-app'@'localhost' IDENTIFIED VIA unix_socket;
GRANT SELECT ON `lg_membership`.* TO 'profile-app'@'localhost';
GRANT SELECT ON `looth_dev`.*     TO 'profile-app'@'localhost';
GRANT SELECT ON `looth_import`.*  TO 'profile-app'@'localhost';
```
> There is no MySQL `membership` user — lg-stripe-billing authenticates as `lg_membership` over TCP.

### 5.2 PostgreSQL

Auth (`/etc/postgresql/16/main/pg_hba.conf`) — **all app traffic is unix-socket `peer`**; DSNs carry no password:
```
local   all   postgres   peer
local   all   all        peer
host    all   all   127.0.0.1/32   scram-sha-256
host    all   all   ::1/128        scram-sha-256
```

Roles (peer-mapped to the OS users; create FIRST):
```sql
CREATE ROLE "profile-app" LOGIN;
CREATE ROLE "archive-poc" LOGIN;
CREATE ROLE "bb-mirror"   LOGIN;
CREATE ROLE "looth-dev"   LOGIN;
```

DBs: `profile_app` (owner `profile-app`) + `looth` (owner `bb-mirror`, schemas `discovery` owned by `archive-poc`, `forums` owned by `bb-mirror`, `public`). Do NOT migrate `profile_app_fresh` / `looth_fresh`.

```bash
# DUMP — -Fc carries ownership/ACLs; run as postgres
sudo -u postgres pg_dump -Fc -d profile_app -f profile_app.dump
sudo -u postgres pg_dump -Fc -d looth       -f looth.dump

# RESTORE — create DBs with the CORRECT OWNER first
sudo -u postgres createdb -O "profile-app" profile_app
sudo -u postgres createdb -O "bb-mirror"   looth
sudo -u postgres pg_restore -d profile_app profile_app.dump
sudo -u postgres pg_restore -d looth       looth.dump
```
> Owner drift to verify: in `profile_app`, tables `practice_instruments`, `practice_services`, `user_mutes` are owned by `postgres` (not `profile-app`) — preserve or profile-app writes fail. In `looth`, confirm `discovery` schema survived as `archive-poc`-owned.

**Cross-schema/role GRANTs — re-apply after ANY PG restore (build, top-off, AND cut). CONFIRMED on dev2 6/14: missing grant → front page 500 for every logged-in member.**
```sql
-- looth.forums (owner bb-mirror)
GRANT USAGE ON SCHEMA forums TO "profile-app", "looth-dev", "archive-poc";
GRANT SELECT ON forums.forum, forums.person, forums.topic TO "archive-poc";
GRANT SELECT ON ALL TABLES IN SCHEMA forums TO "profile-app";
GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA forums TO "looth-dev";
GRANT USAGE,SELECT,UPDATE ON ALL SEQUENCES IN SCHEMA forums TO "looth-dev";

-- looth.discovery (owner archive-poc)
GRANT USAGE ON SCHEMA discovery TO "looth-dev", "profile-app", "bb-mirror";
GRANT SELECT ON ALL TABLES IN SCHEMA discovery TO "profile-app", "bb-mirror";
GRANT SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES ON ALL TABLES IN SCHEMA discovery TO "looth-dev";

-- so future tables stay readable:
ALTER DEFAULT PRIVILEGES IN SCHEMA forums    GRANT SELECT ON TABLES TO "profile-app","archive-poc";
ALTER DEFAULT PRIVILEGES IN SCHEMA discovery GRANT SELECT ON TABLES TO "profile-app","bb-mirror";
```
> Canonical scripts on dev1: `tools/cut/forums-grant.sql` and `tools/cut/sitemap-grants.sql` (must grant **column** privileges too — column-only scope returns empty profiles on a fresh PG16 restore; fixed `d3b3b8c`). Verify: `sudo -u postgres psql -d looth -tAc "select has_schema_privilege('archive-poc','forums','USAGE');"` (`f` = missing).
> If `profile_app`'s schema is fresh (not a restore), apply `/home/ubuntu/projects/profile-app/sql/*.sql` in order.

### 5.3 SQLite (archive-poc) — REBUILD, don't migrate

`/home/ubuntu/projects/archive-poc/index.sqlite` (= `/srv/archive-poc/index.sqlite`, archive-poc:www-data 664, WAL) is the fallback backend. PG is source of truth. **Do NOT copy the file — regenerate it** (and the PG `discovery` index) from WP. See Phase 9.2 for ordering (reindex MUST run AFTER the WP-URL flip).

### 5.3b Profile media → R2 (one-time backfill, dedicated bucket)

profile-app media (`/srv/profile-app-media/{avatars,banners,gallery,resumes}`) lives in the dedicated
R2 bucket now (Phase 6.7), not local disk. Load the originals ONCE into the bucket (NOT `.cache` —
regenerable). New uploads already write straight to R2; this is only the pre-existing files. From the
box holding the live media (originals), with the profile-media token in env:
```bash
for c in avatars banners gallery resumes; do
  rclone copy /srv/profile-app-media/$c ":s3:<PROFILE_MEDIA_BUCKET>/$c" --transfers 16 --no-check-dest
done
# verify per-class: local file count == bucket object count
```
> The serve path checks **local first**, so until the local originals are removed the box still serves
> from disk (R2 is the redundant copy). To actually reclaim disk + serve from R2: snapshot
> `/srv/profile-app-media`, then remove the local originals (keep `.cache/`). Verify reads come from R2
> afterward (a strong GET on an exact key — `ListObjects` is eventually-consistent and lags writes).

### 5.4 Background convergence jobs (systemd timers) — re-arm after any restore

```bash
systemctl enable --now bb-mirror-reconcile.timer lg-person-vis-refresh.timer
```
- `bb-mirror-reconcile.timer` → `.service` (`User=looth-dev`, `WorkingDirectory=/var/www/dev`, `ExecStart=/usr/local/bin/wp eval-file /home/ubuntu/projects/bb-mirror/bin/reconcile.php`), every 10 min. **The drop-in `bb-mirror-reconcile.service.d/profile-token.conf` injecting `LG_LOOTHDEV_GATE_TOKEN` is DEV-ONLY (the gate fronts /profile-api on dev) — do NOT carry it to a gate-less prod box.**
- `lg-person-vis-refresh.timer` → `.service` (`User=bb-mirror`, `ExecStart=/usr/bin/php /srv/bb-mirror/bin/backfill-profile-visibility.php`), every 15 min; reads `EnvironmentFile=-/etc/lg-loothdev-gate.env` (the `-` makes it optional → portable). Set `LG_*_PUBLIC_HOST=loothgroup.com` in these unit envs (no HTTP_HOST in cron → visibility sync fails-open without it).
- **`idle-shutdown.service`** — OMIT on a true prod cut (you don't want prod auto-stopping).
- After any DB reload do a **full person-resync** (`forums.person` keys on recyclable WP user IDs → stale author names otherwise).

---

## Phase 6 — Secrets & integrations

> All secret files live OUTSIDE the source trees, in `/etc`, owned `root` with a per-service group and (where a cross-user reader exists) a POSIX ACL. Apps read them at runtime via `file_get_contents()` — NOT via pool `env[]`. Recreate each with identical owner/group/mode/ACL. VALUES come from the vault / live — never commit them.

### 6.1 Secret-file inventory

| Path | Owner:Group | Mode | ACL | Read by |
|---|---|---|---|---|
| `/etc/looth/jwt-private.pem` | `root:looth-dev` | 640 | `u:profile-app:r` | minter (`profile-auth.php`, runs as looth-dev); profile-app Mint/Auth via ACL |
| `/etc/looth/jwt-public.pem` | `root:root` | 644 | (world) | every JWT verifier |
| `/etc/lg-internal-secret` | `root:www-data` | 640 | `u:profile-app:r` | wp-config `LG_INTERNAL_SECRET`, poller, profile-app internal |
| `/etc/lg-profile-app-secret` | `root:profile-app` | 640 | — | profile-app webhook auth (= WP option `profile_hook_secret`) |
| `/etc/looth/profile-r2` | `root:profile-app` | 640 | — | profile-app R2 creds for **profile media** — `endpoint/bucket/prefix/key/secret` (read by `src/R2.php`). At cut: live profile-media bucket + bucket-scoped token. |
| `/etc/lg-archive-poc-secret` | `root:www-data` | 640 | `u:archive-poc:r` | lg-layout-v2 ArchivePocDash |
| `/etc/lg-membership-db` | `root:membership` | 640 | — | membership-pages + billing |
| `/etc/lg-events-db` | `root:events` | 640 | — | events |
| `/etc/lg-loothdev-gate.env` | `root:bb-mirror` | 640 | — | nginx gate token + vis-refresh (DEV-ONLY) |
| `/etc/lg-vapid/vapid_private.pem` + `.b64url` | `root:root` | 600 | — | lg-push |
| `/etc/lg-vapid/vapid_public.b64url` | `root:root` | 644 | — | browser subscribe (dir 700) |

```bash
# after placing files:
setfacl -m u:profile-app:r /etc/looth/jwt-private.pem
setfacl -m u:profile-app:r /etc/lg-internal-secret
setfacl -m u:archive-poc:r /etc/lg-archive-poc-secret
getfacl -p /etc/looth/jwt-private.pem   # confirm mask shows r--
```
> Recreate `/etc/looth/` as `root:root 755` first. **Do NOT carry to prod:** `/etc/lg-topoff.conf` (dev top-off config), `/etc/msmtprc` mailpit trap, the dev R2 token, `/etc/lg-loothdev-gate.env` is dev-only. The stale `/etc/lg-billing/jwt.pub` path doesn't exist — don't block on it; if billing revives, point it at `/etc/looth/jwt-public.pem`.

### 6.2 JWT keypair + salts — carry byte-identical (or logout/re-mint storm)

- **JWT keypair MUST be byte-identical on every box.** Regenerating it fails every existing `looth_id` cookie → mass strangler-surface logout. Carry `/etc/looth/jwt-{private,public}.pem` verbatim (dev1 fingerprint `MD5 a3338dd4e85cc9e5ac099f7c17401983`). Set 640 root:looth-dev + ACL `g/u:profile-app:r`, and 644 root:root for the public key.
- **The 8 wp-config salts MUST match the box that issued the imported DB's sessions** (live), or every WP cookie is invalid → forced re-login for everyone. Carry from live.
- **`/etc/lg-internal-secret`** must equal what wp-config reads (it reads the file → automatic) and what the poller/whoami-shim `hash_equals` against.

### 6.3 profile_hook_secret pairing (verify post-DB-restore)

```bash
sudo cat /etc/lg-profile-app-secret
sudo -u looth-dev wp option get profile_hook_secret --path=/var/www/dev
# must match, or WP↔profile-app webhook 401s
```
Also re-set the member-sync DB creds in wp_options after any DB restore (wiped by a reload): `lgms_db_host=127.0.0.1`, `lgms_db_name=lg_membership`, `lgms_db_user=lg_membership`, `lgms_db_pass=<...>`, `lgms_db_port=3306`.

### 6.4 Patreon OAuth — NEEDS live values + Patreon-portal redirect URI update

Config is in WP `wp_options` (set via `wp option update`). Carry from live DB: `lgpo_client_id`, `lgpo_client_secret`, `lgpo_creator_access_token`/`refresh_token`/`token_expires_at`, `lgpo_tier_map`/`lgpo_tier_labels` (authoritative looth1-4 map), `lgpo_campaign_id=4833198`, `lgpo_contact_email`, `lgpo_patreon_link`, `lgpo_sync_frequency=hourly`, `lgpo_auto_sync_enabled=1`.

**Cut actions:**
1. **In Patreon's developer portal (OFF-box) add the new host's `/patreon-callback` to Allowed Redirect URIs.** ← single most-likely-to-be-missed step.
2. `sudo -u looth-dev wp option update lgpo_redirect_uri https://loothgroup.com/patreon-callback --path=/var/www/dev`
3. Keep `/patreon-callback` + `/.well-known/` reaching WP (gate exempt).

### 6.5 Stripe — PARKED; ship disabled, wire post-launch if revived

`/srv/lg-stripe-billing/.env` (ccdev:loothdevs 0664, gitignored; `.env.example` is the template; pool sets `clear_env=no`). If revived at cut: create a **live Stripe webhook endpoint** → new host `/billing/...`, paste `whsec_` into `STRIPE_WEBHOOK_SECRET`, flip `STRIPE_MODE=live`, swap live `sk_`/`pk_`, re-point all `dev.loothgroup.com` URLs (`LGMS_SYNC_URL`, `APP_*_URL`, etc.). `DB_*` → `lg_membership`. nginx already exempts `/billing/*` + `/wp-json/lg-member-sync/*`.

### 6.6 SMTP — dev = mailpit catcher; NEEDS real mailer at cut

- System MTA `/etc/msmtprc` redirects all sendmail to local mailpit (`127.0.0.1:1025`) — a **dev trap**; replace with a real account block on prod. Also the dev mail-cap iptables (block 25/465/587) **must NOT be carried to prod**.
- WP mailer: `wp_mail_smtp` option, mailer = `gmail` (OAuth), from `ian@loothgroup.com` — carries from live DB and sends via Gmail API directly. Confirm desired behavior at cut.

### 6.7 R2 + VAPID continuity

- R2 (uploads): at cut, mount the **live uploads bucket** with a **live-scoped rotated token** (Phase 2.3). This is WP/BuddyBoss media via the FUSE mount.
- **R2 (profile media) — SEPARATE, dedicated bucket, NOT the mount:** profile-app reads/writes the dedicated bucket `loothgroup2-0-profile-bucket` (avatars/banners/gallery/resumes) via the **S3 SDK** (`src/R2.php`, SigV4), creds in `/etc/looth/profile-r2`. Dedicated for least-privilege (profile-app takes user uploads + can delete; its scoped token must not reach WP/forum media). At cut, point `bucket=` at the live profile-media bucket + a **bucket-scoped** token; the resizer `.cache/` stays local. Originals load once via the Phase 5 backfill. R2 wiring gotchas (scoped tokens can't `ListBuckets`) → the `r2-wiring` skill.
- VAPID: regenerating invalidates all browser push subscriptions — **carry the live keypair** to preserve them.

---

## Phase 7 — Identity & bridge (run AFTER the data top-off)

> All login pieces must be present or members silently log in as anonymous to strangler surfaces (the dev2 symptom) even though WP login itself works.

### 7.1 The chain (what makes /whoami resolve)

- **`looth_id` JWT** (RS256, scoped `.loothgroup.com`, 30-day, HttpOnly/Secure/SameSite=Lax) is the ONLY auth profile-app/archive-poc trust. Minted by `profile-auth.php` on `wp_login`; `init` priority-1 reverse-bridge re-establishes a WP session from a valid JWT (handles Patreon-onboarded members + post-reload session wipes).
- The JWT `sub` claim = the **stored** `users.uuid`, read from WP usermeta `_looth_uuid`. **NEVER recomputed from email.** If `_looth_uuid` is absent the minter REFUSES to mint → so the usermeta mirror MUST be backfilled after data restore (step 7.3).
- `LOOTH_IDENTITY_NAMESPACE = eaef23f7-9bc9-4a95-ac49-ffff632e6646` — hardcoded in both `profile-app/config.php` and `profile-auth.php`. **DO NOT CHANGE** (rotating it orphans every computed identity).
- **tier comes from the POLLER**, not profile-app: `Whoami::fetchPollerTier()` → loopback `https://127.0.0.1/wp-json/looth-internal/v1/user-context/<wp_user_id>` with `X-LG-Internal-Auth`. nginx locks `/wp-json/looth-internal/` to `allow 127.0.0.1; deny all`.
- Bridge tables (PG `profile_app`): `users(uuid UUID UNIQUE, primary_email UNIQUE, slug, tier, …)`, `wp_user_bridge(wp_user_id UNIQUE → users.id)`, `email_aliases`. WP side: usermeta `_looth_uuid` (minter `sub` source) + `_looth_slug`, stamped at `user_register` by `profile-sync.php`.

### 7.2 Prereqs already satisfied by earlier phases

OS+PG role `profile-app` (Phase 1/5.2); both mu-plugins `profile-auth.php` + `looth-auth-issue.php` + `looth-vendor` (Phase 4); `/etc/looth/*.pem` + `/etc/lg-internal-secret` + matching wp-config `LG_INTERNAL_SECRET` + 8 salts (Phase 6); the `looth-dev` group membership for `jwt-private.pem` (Phase 1).

### 7.3 Reconcile + backfill — AFTER the final top-off, order matters

```bash
# 1) guarantee a users + wp_user_bridge row for every wp_users row (idempotent)
sudo -u profile-app php /srv/profile-app/bin/reconcile-bridge.php

# 2) write _looth_uuid usermeta from PG; ENDS ON A GATE (non-zero unless every bridged
#    WP user has _looth_uuid == users.uuid). Until GREEN, the minter refuses → members render as guests.
sudo WP_PATH=/var/www/dev /srv/profile-app/bin/backfill-looth-uuid.sh
```
> Post-snapshot signups land unbridged → /whoami anon → "Sign in to post" — that's why this runs after the top-off, never at build time. Affected users must log out/in (JWT `sub` minted at login).
> **Known scoped non-blocker:** `mikelle.davlin` (wp 1848 orphaned / 1905 bridged, Patreon-onboard double on one `lgpo_patreon_user_id`) trips the GATE on `users.primary_email` UNIQUE. Whitelist that one `wp_user_id` so the backfill doesn't red. Re-scan at cut: `SELECT user_email,COUNT(*) c FROM wp_users WHERE user_email<>'' GROUP BY user_email HAVING c>1;`. **Never `wp user delete` to resolve — it fires the lifecycle cross-store NUKE; use direct SQL.**

---

## Phase 8 — nginx / SSL / host (server_name, gate OFF, SEO continuity, SSL)

### 8.1 Main config + global maps/zones (conf.d loads before sites)

- `/etc/nginx/nginx.conf` — stock Ubuntu (`user www-data; worker_connections 768;`), gzip on (level 5, min 512, extended types incl json/javascript/svg/manifest), `ssl_protocols TLSv1 TLSv1.1 TLSv1.2 TLSv1.3;`, tail includes `conf.d/*.conf` then `sites-enabled/*`.
- `/etc/nginx/conf.d/loothdev-auth.conf` — the cookie-gate maps (`$loothdev_is_authorized`). **DROP entirely on prod** (or make the map default to `1`).
- `/etc/nginx/conf.d/loothdev-ratelimit.conf` — **KEEP**: brute-force/DoS zones + the loopback-exempt key (so SSR /whoami self-calls don't self-throttle):
  ```nginx
  limit_req_zone $binary_remote_addr zone=loothdev_thumb:10m rate=5r/m;
  limit_req_zone $binary_remote_addr zone=loothdev_login:10m rate=5r/m;
  geo $loothdev_limit_loopback { default 0; 127.0.0.1/32 1; ::1/128 1; }
  map $loothdev_limit_loopback $loothdev_limit_key { 0 $binary_remote_addr; 1 ""; }
  limit_req_zone $loothdev_limit_key zone=loothdev_me:10m     rate=20r/s;
  limit_req_zone $loothdev_limit_key zone=loothdev_whoami:10m rate=30r/s;
  ```

### 8.2 The site vhost (rename dev.loothgroup.com.conf → loothgroup.com.conf)

Carry the structure; edit only the host-coupled bits (8.6). Keep: security headers (drop the `X-Robots-Tag noindex`), `client_max_body_size 8000M`, the PWA `sub_filter` on `</head>`, the PWA/no-cache exceptions (`/sw.js`, `/manifest.json`, `/icons/`, `/pwa.js`, `/shop-feed.json`), the static-asset cache headers, the hardening denies (`/\.(?!well-known/)`, `wp-config*.php`, `xmlrpc.php`, backup-cruft `\.(bak|save|old|orig|swp|swo|tmp)…$`, `^~ /v2/` mockups).

**Strangler includes (order matters — precede the WP catch-all):**
```nginx
include /etc/nginx/snippets/strangler-profile-app.conf;
include /etc/nginx/snippets/strangler-archive-poc.conf;
include /etc/nginx/snippets/strangler-bb-mirror.conf;
include /etc/nginx/snippets/strangler-events.conf;
include /etc/nginx/snippets/strangler-membership.conf;
include /etc/nginx/snippets/lg-shared.conf;
```
WP catch-all: `location /` (`try_files $uri $uri/ /index.php?$args`) + `location ~ \.php$` → `php8.3-fpm-looth-dev.sock`. **`location = /` is NOT here — it's owned by `strangler-archive-poc.conf`** (the front feed, Ian 2026-06-14).

> Install ONLY the `/srv/profile-app` profile-app block. **DROP the stale duplicate block aliasing `/home/buck/looth-platform/profile-app/{web,api}/`** (Buck's personal clone — a craft-gate-worthy artifact).

### 8.3 Strangler snippets (from a known git baseline — NOT per-box flat copies)

Git-managed (symlinks → `platform/nginx/`):
```bash
ln -s /home/ubuntu/projects/platform/nginx/strangler-profile-app.conf /etc/nginx/snippets/strangler-profile-app.conf
ln -s /home/ubuntu/projects/platform/nginx/strangler-bb-mirror.conf   /etc/nginx/snippets/strangler-bb-mirror.conf
ln -s /home/ubuntu/projects/platform/nginx/strangler-events.conf      /etc/nginx/snippets/strangler-events.conf
ln -s /home/ubuntu/projects/platform/nginx/lg-shared.conf             /etc/nginx/snippets/lg-shared.conf
```
App-local flat copies (`cp`, NOT symlinks):
- `strangler-archive-poc.conf` ← `/srv/archive-poc/nginx-snippet.conf` (verified identical to git; app repo is source of truth).
- `strangler-membership.conf` ← live snippet content from `/home/ubuntu/projects/membership-pages/nginx-snippet.conf` (live is NEWER than repo; copy the live content, not the stale repo header).

> Do NOT carry the `.bak-*`/`.pre-*` snippet clutter. Snippets bake **no hostname** (all use `$host`/relative) — they carry over unchanged.

### 8.4 SSL — DNS-01 via Cloudflare (wildcard, so HTTP-01 won't work)

`/etc/letsencrypt/cloudflare.ini` (root:root **0600**, `dns_cloudflare_api_token=<token>`). Then:
```bash
certbot certonly --dns-cloudflare \
  --dns-cloudflare-credentials /etc/letsencrypt/cloudflare.ini \
  --dns-cloudflare-propagation-seconds 30 \
  -d loothgroup.com -d www.loothgroup.com
```
Point the vhost `ssl_certificate`/`_key` at the new live dir. `options-ssl-nginx.conf` + `ssl-dhparams.pem` auto-create. Renewal via packaged `certbot.timer`.

### 8.5 SEO continuity — host-agnostic, MUST be applied (rebuilds ~69% bbPress footprint)

All redirects are **301** and **UNGATED** (Googlebot is anon; dropping the gate just makes the targets public). They bake no host. Carried by the snippets:
- bbPress 301 resolver (`strangler-bb-mirror.conf`): `/forums*`, `/forum*`, `/all-forums-all-topics/`, `/groups/`, `/topic-tag/` → `seo-redirect.php` → `301 /hub/...`, **never 404**.
- `/members` split (`strangler-profile-app.conf`, first-regex-wins): `/members/me*` → 302 `/profile/edit`; `/members/<slug>/profile/edit*` → 302 `/profile/edit`; `/members/<slug>*` → 301 `/u/$1`; `/members/` → 301 `/directory/members`.
- Retired-CPT (`strangler-archive-poc.conf`): `/mobile-archive-page/` → 301 `/archive/`; `/sponsor-page/<slug>/` → 301 `/sponsors/$1/`; `/stream/` → 301 `/hub/`.
- Sitemap (ungated like robots) → `/srv/archive-poc/web/sitemap.php`: `= /sitemap.xml` + `sitemap-(static|content|profiles).xml`. Emits on `$_SERVER['HTTP_HOST']` (no baked host). Sections: static, content (`discovery.content_item` tier IN public,lite), profiles (`profile_app.users` where `profile_visibility='public'`). This is the custom replacement for the removed Rank Math.

**robots.txt swap (biggest cut-time content change):** dev1's `/var/www/dev/robots.txt` is the dev `Disallow: /`. **At cut, replace with a live allow-crawl robots pointing at `/sitemap.xml`.** Do NOT carry the dev disallow-all.

### 8.6 What changes AT CUT (host coupling lives only in the vhost + a few headers)

1. `server_name dev.loothgroup.com` → `loothgroup.com` (+ `www`); rename the file.
2. SSL cert paths → the new live certbot dir.
3. **Cookie gate OFF** — drop the `conf.d/loothdev-auth.conf` maps, every `if ($loothdev_is_authorized != 1){return 403;}`, the `/claim` + `/claim-tester` + `/mailpit/` blocks, the `set $loothdev_token` lines, the static-asset gate guard. Exempt-path list that must stay anon-reachable regardless: `/billing/*`, `/wp-json/lg-member-sync/*`, `/wp-json/loothdev/`, `/wp-json/looth-internal/` (loopback-only), `/.well-known/`, `/thumb/`, `/robots.txt`.
4. robots.txt → live (8.5).
5. Remove the `X-Robots-Tag noindex` header.
6. Cookie `Domain=.dev.loothgroup.com` → moot once gate is dropped.
7. Drop dev-only tooling: `/mailpit/`, `/v2/` mockups, the heartbeat/idle-shutdown `sub_filter` beacon.

```bash
nginx -t && systemctl reload nginx    # FPM sockets must exist or routes 502
```

---

## Phase 9 — URL + DNS flip (SAME window) + reindex AFTER

### 9.1 Flip URLs + CF origin in ONE window

```bash
# pin WP_HOME/WP_SITEURL as wp-config CONSTANTS (the redirect-loop safety net)
# edit wp-config.php:  define('WP_HOME','https://loothgroup.com'); define('WP_SITEURL','https://loothgroup.com');
sudo -u looth-dev wp option update home    https://loothgroup.com --path=/var/www/dev
sudo -u looth-dev wp option update siteurl https://loothgroup.com --path=/var/www/dev
sudo -u looth-dev wp cache flush --path=/var/www/dev
# (sessions invalidate on a DB reload — members re-login; covered by carrying salts + the reverse-bridge)
```
**Then flip the Cloudflare ORIGIN IP** (old-live → new box) — instant at the edge. **Never flip the origin without the URL flip in the same window** (WP would redirect every request to the old siteurl → loop / wp-admin lockout).

### 9.2 Reindex archive-poc AFTER the URL flip (do NOT search-replace)

`bin/indexer.php`/`backfill.php` build `url` from `get_permalink()` → WP `home_url`. Running before the flip bakes in the old host. Strict order:
```bash
# 1) PG discovery index (peer auth as archive-poc)
LG_ARCHIVE_POC_DSN='pgsql:host=/var/run/postgresql;dbname=looth' \
  sudo -u archive-poc php /srv/archive-poc/bin/backfill-pg.php
# 2) materialize (slow — background it)
sudo -u archive-poc php /srv/archive-poc/bin/materialize-all.php &
# 3) SQLite mirror + comments side index
sudo -u archive-poc php /srv/archive-poc/bin/backfill.php
sudo -u archive-poc php /srv/archive-poc/bin/backfill-comments.php
```
> **Critical fix `f6400aa` (6/15):** `backfill-pg.php` had been silently TRUNCATEing `tag`+`person` mid-txn since ~6/11, FK-failing every tagged post. Run `backfill-pg.php` THEN `materialize-all.php` after EVERY restore/top-off or new content won't show. (`--force` only for an intentional >50% shrink; otherwise it aborts + rolls back.)
> content_html / index URLs are STORED columns — a code deploy alone does NOT heal existing rows. Always re-backfill/reindex.

### 9.3 bb-mirror person-resync

Full person-resync after the top-off (`forums.person` keyed on recyclable WP user IDs → stale author names otherwise).

---

## Phase 10 — Verify

```bash
tools/gates/run-all.sh            # all 4 gates green = pushable; red = stop
```
1. **Login + /whoami tier ladder:** real login on each tier; `curl` `/profile-api/v0/whoami` returns `authenticated:true` + correct `tier` (from the poller loopback), not `{authenticated:false,tier:'public'}`.
2. **Bounce loop:** logged-in-but-no-`looth_id` → `/looth-auth/issue?return=…` mints + 302s back (both mu-plugins present).
3. **Wrong-key JWT sanity:** a JWT signed with a different key must FAIL verification (confirms the public key is the real pair).
4. **Payments smoke** (if billing un-parked): checkout + webhook round-trip.
5. **Member composer-post + discussion visibility** render correctly.
6. **Image chain 403→404→200:** uploads symlink resolves, mount up, resizer serves.
7. **SEO:** a sample `/forums/...`, `/members/<slug>`, `/sponsor-page/<slug>/` each 301 to the right canonical; `/sitemap.xml` renders with `loothgroup.com` URLs.
8. **Logo** resolves to the intended host (the `_chrome.php` ternary was patched, Phase 4.8).

Re-apply gotchas with the dev1 idempotent scripts after any restore: `/var/www/dev/.well-known/dev2-cut-reapply-gotchas.sh` (grants/ACLs/perms + verify), then `dev2-cut-verify.sh <host>` (renders + SEO + sitemap + whoami).

Post-restore checklist (lives in no config): `id www-data` includes `looth-dev tool-dev loothdevs`; `getfacl /home/ubuntu` shows the `--x` ACLs; mount up + symlink resolves + rclone ≥1.74; `/srv/*` symlinks intact; both timers active; FPM sockets present `www-data:www-data 0660`; `bb-enable-private-rest-apis=0` (re-arms every DB reload → 401s BuddyBoss REST the header uses).

---

## Phase 11 — Rollback (the master switch)

- **Master switch = the Cloudflare origin IP.** Revert origin → old-live (instant, seconds). Old-live's `WP_HOME` constant is unchanged → it serves immediately. Investigate the new box out of the hot path.
- **Window discipline:** define a **go/no-go window** + a decision point — NOT "hold our breath." Once users WRITE to the new box, flipping back loses that write data → clean rollback exists only inside a short soak window. Hold old-live for that defined window before decommissioning.
- **Repo anchors:** tags `pre-a2-real-20260615`, `pre-a2-main` (3a5817e); `git reset --hard origin/main` is the canonical recovery for any drifted checkout.
- **F (post-cut):** hold old-live for the soak; then decommission. A from-scratch box is born as a single `main` checkout, so "confirm pending deploys" is automatic (`git rev-parse HEAD == origin/main`).

---

## Launch-readiness gaps (critic pass)

*Adversarial review 2026-06-16 against the live dev1 box. Each gap below is something a fresh box following ONLY the runbook above would still get wrong or be missing. Verified, not theoretical.*

### BLOCKER

1. **No WP-cron driver — and WP-cron is DISABLED.** `wp-config.php` sets `define('DISABLE_WP_CRON', true)`, yet there is **NO** system cron entry, `/etc/cron.d` file, user crontab, or systemd timer anywhere on dev1 that runs `wp cron event run`. The poller's recurring tier-sync (`src/Plugin.php` `wp_schedule_event`), `lg-weekly-email-bridge.php`, `lg-event-reminders.php`, and the orphan-reconcile sweep are ALL scheduled on WP-cron. On dev this silently never fires (acceptable — manual/admin-driven). On a real prod box this means **Patreon tier resync, weekly emails, and event reminders never run** — members' tiers go stale, no mail goes out. The runbook lists `lgpo_sync_frequency=hourly` + `lgpo_auto_sync_enabled=1` (Phase 6.4) as if scheduling works, but nothing drives it. Fix at cut: add a system cron or systemd timer that runs `sudo -u looth-dev wp cron event run --due-now --path=/var/www/dev` every ~5 min. The doc must specify this; the only timers it carries (Phase 5.4) are bb-mirror-reconcile + vis-refresh, neither of which is WP-cron.

2. **`lg-push` queue + event-reminder sender have no driver either.** `/srv/lg-push/run-queue.php` and `run-event-reminders.php` (buck-owned, composer-based, with its own `GO-LIVE.md`) are the push-notification + reminder send subsystem. No timer or cron drives them on dev1 (grep confirms NONE). Phase 3.2 creates the `/srv/lg-push` dir and Phase 6.7 carries the VAPID keys, but nothing in the runbook schedules `run-queue.php`. Push notifications + event reminders will silently never send on the new box. Decide the driver and its cadence at cut, run `composer install` in `/srv/lg-push` (composer.lock present), and document it.

### HIGH

3. **events + membership DB secret files actually point at `looth_import` via `looth_dev_user` — the runbook says otherwise.** `/etc/lg-events-db` and `/etc/lg-membership-db` both literally contain `DB_NAME=looth_import`, `DB_USER=looth_dev_user`, `DB_PASSWORD=<...>`, `DB_HOST=localhost`. Only `lg-stripe-billing/.env` uses `DB_NAME=lg_membership` / `DB_USER=lg_membership`. The Phase 6.1 table says `/etc/lg-membership-db` is "read by membership-pages + billing" and Phase 5.1 implies membership authenticates as `lg_membership` — conflating two different DSNs. A faithful recreate must write these two secret files with the `looth_dev_user`/`looth_import` values (and that password must equal `looth_dev_user`'s MySQL grant). Because `looth_import` is dropped+reimported at cut (Phase 5.1), the `looth_dev_user` password and its `looth_import` GRANT must survive the reimport, or events/membership-pages 500.

4. **certbot/DNS plugin provenance is muddy; the dns-cloudflare cert may not be the one nginx points at.** dev1 has BOTH an apt certbot 2.9.0 (with `dns-cloudflare`, `/etc/letsencrypt/cloudflare.ini` present, driven by `/etc/cron.d/certbot` every 12h) AND a **snap certbot 5.6.0 with `dns-route53`** (driving `snap.certbot.renew.timer`). The runbook's Phase 8.4 install line uses apt `python3-certbot-dns-cloudflare` + the cron path, which works — but it never mentions the snap/route53 stack also present, and the live cert for `loothgroup.com` may be issued by a different toolchain/DNS provider than the wildcard dns-cloudflare command shown. At cut, confirm which renewal path owns the `loothgroup.com` cert (apt-cron vs snap-timer) so renewals don't silently lapse, and don't leave two competing renewers.

5. **`advanced-cache.php` does not exist — only `object-cache.php`.** Phase 4.3 says "Drop-ins `object-cache.php` (+ `advanced-cache.php`)". There is no page-cache dropin on dev1. Following the doc, someone will hunt for / try to bundle a nonexistent `advanced-cache.php`. Drop the `+ advanced-cache.php` mention; only the Redis object-cache dropin exists.

6. **profile-app `config.php` carries a hardcoded `dev2.`/`ip-172-31-47-205` host branch with per-branch `LG_PROFILE_APP_HOST` constant.** The "no live/newprod code branch" framing (Phase 0.2) is not fully accurate: `config.php` has an explicit `str_starts_with($host,'dev2.')` branch and the host constant is **literally baked per-branch** (`'loothgroup.com'` in one, `'dev2.loothgroup.com'` in another). A real prod box on host `loothgroup.com` matches NEITHER the `dev.`/`claude.` branch NOR `dev2.` → falls through to the `'live'` fallthrough the doc warns hits nonexistent DBs, UNLESS `LG_PROFILE_APP_ENV=dev` overrides. The env override is the load-bearing mechanism; the doc should state plainly that without `LG_PROFILE_APP_ENV=dev` the `loothgroup.com` host falls to `'live'`, and that the same host-branch pattern likely exists in the other apps' `config.php` (audit each for a stale `dev2`/host-pinned constant before cut).

### MEDIUM

7. **Backup/snapshot strategy is absent.** The runbook covers restore-from-dump but never says to take an AMI/EBS snapshot of the built-and-verified new box before the flip, nor a final old-live snapshot before decommission. Rollback (Phase 11) relies entirely on the CF origin flip + holding old-live; there is no point-in-time image to recover the NEW box if it's corrupted post-write during the soak.

8. **No firewall / security-group guidance for the new box.** dev1's exposure is fronted by the cookie gate (dropped at cut per 8.6). Once the gate is OFF, `/wp-json/looth-internal/` is protected only by an nginx `allow 127.0.0.1; deny all` and the rate-limit zones — fine — but there is no mention of the AWS security group / `ufw` posture (SSH lockdown, closing the dev-only ports, the mail-cap iptables which 6.6 says to drop). A fresh box needs its inbound rules defined explicitly, not inherited.

9. **`looth_dev_user` / `lg_membership` MySQL passwords are only referenced as "from .env" — but events/membership read them from `/etc/lg-*-db`, not a .env.** Phase 5.1's `CREATE USER ... IDENTIFIED BY '<from .env>'` is ambiguous about source. The authoritative password for `looth_dev_user` lives in `/etc/lg-events-db` + `/etc/lg-membership-db` (and must match the WP wp-config DB password if the same user fronts WP). State the single source of truth for each MySQL password and verify all consumers agree before the reimport.

10. **Snap-based services (`snapd`) and the `lg-sudo-queue` path/notify units are undocumented.** `lg-sudo-queue.path` + `lg-sudo-queue-notify.service` + `dm-event` are present; the queue-notify is a dev coordination artifact (emails the coordinator) and like `idle-shutdown` should be explicitly OMITTED from prod. The doc only calls out omitting `idle-shutdown`; it should also exclude `lg-sudo-queue*` and the dev `msg`/devmsg tooling.

### LOW

11. **`/etc/looth/` jwt-private is mode 640 with the ACL already applied on dev1 (getfacl shows the `+`).** Confirmed fingerprint matches the doc (`a3338dd4...`). Minor: the doc says "Recreate `/etc/looth/` as root:root 755" — dev1 has it 755, good — but jwt-public is `root:root 644` (matches). No gap in values; flagging only that the ACL `+` must be re-verified with `getfacl` post-copy since a plain `cp` does not carry ACLs.

12. **Redis `WP_REDIS_DATABASE=1` (not 0).** Confirmed in wp-config (doc says db 1 — correct). If anything else on the box uses Redis db 1 it would collide; worth a one-line note that db 1 is WP's and must be exclusive.
