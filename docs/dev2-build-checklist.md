# dev2.loothgroup.com — build runbook + cut checklist (prod candidate)

Build a clean prod box from **stock Ubuntu 24.04** (NOT a dev AMI) carrying dev's strangler stack
without dev's cruft; at the cut, DNS-flip `loothgroup.com` → this box. Source of truth = **dev**
(claude.loothgroup.com). Strategy companion: `docs/DEPLOY-PLAN.md`. This file is the re-runnable
record of what was actually done + every gotcha (so a rebuild or the cut doesn't rediscover them).

---

## CURRENT STATE (2026-06-14) — dev2 SERVES END-TO-END
HTTPS + real cert · cookie gate enforced (anon→403) · `/`=archive-poc front · `/hub/` · `/events/`
all render · logged-in identity works (header shows the member + ADMIN) · images live (R2).
- **Done:** Phases 0–6 + 8 (timers/cron) + 9 (SSL). Phase 7 nearly done (identity bridge, images,
  materialize/standalone render all verified); only the **archive-poc index URL-rewrite** (`dev.`→`loothgroup.com`)
  + **bb-mirror person-resync** remain — both are cut-time data steps, not pre-cut blockers.
  **Remaining: 10 verify → 11 the cut** (+ the two Phase-7 cut-time data steps).
- **Active side-lanes (don't duplicate):** *whoami/identity* lane — CLOSED the bridge. *profile-routing*
  lane — a legacy BuddyBoss profile page is still reachable (likely a pre-existing parity issue, being
  handled there).

## ADJUSTMENTS LOG — 6/14 session (gaps the hand-build missed; the cut MUST re-apply these)
The hand-build carried the DB but **not all files / perms / plugins / media**. Each below was found + fixed
*live* on dev2; re-apply at the cut — OR avoid the whole class by making the cut box a full git checkout +
a media sync. (Handoff: `docs/dev2-to-live-handoff.md`. Audits preserved: `tools/dev2/dev2-{audit,perms-audit}.sh`.)
1. **Plugin symlink targets** — `wp-content/plugins/{lg-layout-v2,lg-snippets}` symlink into `projects/` but those
   dirs weren't bundled → "Plugin file does not exist" → v2 editor + events broke. Carry EVERY `wp-content/{plugins,mu-plugins}` symlink target.
2. **lg-snippets ↔ code-snippets** — dev2's DB had the dup code-snippets active (ids 39,44,86,90,92,93,95,96) → redeclare fatal on activate. Deactivate those in `wp_snippets`.
3. **membership-pages app** — not bundled → `/manage-subscription/` etc. "File not found". Carry `projects/membership-pages` + `env[LG_MEMBERSHIP_ENV]=dev` + create `/etc/lg-membership-db` (root:membership 640) + `setfacl -m u:membership:r`.
4. **Avatar media files** — PG had the avatar URLs but `/srv/profile-app-media` (~507 files) wasn't synced → broken avatars on profiles + comments. Sync the media dir.
5. **Identity bridge** — post-snapshot signups unbridged (112 REAL members) → /whoami anon. Re-run reconcile-bridge + backfill-looth-uuid AFTER the final top-off.
6. **Env override on the looth-dev (WP) pool** — `dev2.` host fell to the strangler `'live'` branch → `auth.php` + every WP-pool bb-mirror-api endpoint 500 → "Sign in to post". Add `env[LG_BB_MIRROR_ENV|LG_ARCHIVE_POC_ENV|LG_EVENTS_ENV]=dev` to `pool.d/looth-dev.conf` (gotcha #2 — the WP pool was the missed pool).
7. **Host hardcode / CORS** — composer POSTed cross-origin. bb-mirror fixed (`eefbe97` = request-derived host + `env[LG_BB_MIRROR_PUBLIC_HOST]`). ✅ **events + archive-poc `config.php` now fixed too (`8c677aa`)** — same host-from-request pattern, fallbacks `env[LG_EVENTS_PUBLIC_HOST]` / `env[LG_ARCHIVE_POC_PUBLIC_HOST]` for CLI/cron. Deploy bundle `dev2-host-derive.tgz` (plain-file surfaces). Also folded in the footer `/billing-refund/`→`/request-refund/` 404 fix.
8. **JWT-private ACL** — `setfacl -m u:profile-app:r /etc/looth/jwt-private.pem` was missed (the internal-secret ACL was set; this one wasn't).
9. **R2 read/write** — the rclone mount was `--read-only` → all uploads failed. Remove `--read-only` (it's a dedicated bucket → zero live risk).
10. **Charset** — ✅ VERIFIED on dev2 (6/14): `wp_postmeta`/`wp_posts` = `utf8mb4_unicode_520_ci`, `meta_value` column = `utf8mb4`. Emoji-1366 does NOT apply here — no fix needed. (Re-verify on the cut box if it's rebuilt from a different dump.)
11. **⚠️ Secrets in the public path** — the build left PG/MySQL dumps + `profile-app-config.php` in the gate-exempt `.well-known` (member-data + creds, publicly fetchable). **Never stage dumps/secrets there; clean `.well-known` after any deploy.**

## BOX & ACCESS MODEL
- dev2 = **Ubuntu 24.04 / native PHP 8.3.6** (parity ✓), t3a x86_64. pub **34.193.244.53** (re-check
  after relaunch) / priv **172.31.47.205**. SSH `ubuntu@` + `loothgroup2-0.pem`. (A 26.04 box was
  scrapped — ships PHP 8.5, no php8.3.)
- **The dev session (me) runs ON dev and CANNOT SSH to dev2** (dev2 SG blocks dev's IP). Build model:
  I build clean, cruft-excluded bundles → serve at dev's gate-exempt `https://dev.loothgroup.com/.well-known/`
  → hand Ian **exact commands**; he pastes on dev2. **Delete bundles from dev after dev2 pulls** (dev disk ~85%).
- Gate token for curl tests: `qShCjBdCVXLie7wcQddsprkYj4SuaXu7UJeYAHHG`. Test nginx locally on dev2 with
  `curl --resolve dev2.loothgroup.com:443:127.0.0.1 …` (dev2 :443 isn't reachable externally — see SSL gotcha).

## KEY FACTS
- WP DB = **`looth_import`** (NOT looth_dev), user `looth_dev_user`; also `lg_membership`. WP **6.9.4**.
  WP root = **`/var/www/dev`** (path parity with dev), owner **looth-dev**.
- **loothtool DROPPED** from this cut (goes on its own box). The loothgroup "Loothtool" nav opens
  `shop-bubble.js` (a loothgroup webroot asset) → just links out to loothtool.com; no DB/iframe coupling.
  → loothtool_dev DB/user dropped; `lg_membership` KEPT (loothgroup's).
- Custom data is tiny (~6MB PG); the ~800MB WP DB is ~all 3rd-party. Uploads on R2 (not in any bundle).

---

## RUNBOOK (phase status + what was actually done)

### Phase 0 — base ✓
Ubuntu 24.04 patched · stage-1 pkgs (nginx 1.24, MariaDB 10.11, PG 16, redis, memcached, PHP 8.3 stack,
wp-cli, **`acl`** ← was missing) · 7 app users (archive-poc, bb-mirror, events, looth-dev, profile-app,
membership, tool-dev) · hostname dev2.loothgroup.com · DNS A `dev2`→34.193.244.53 (**Cloudflare, DNS-only/grey**).

### Phase 1 — app code ✓
App bundle (archive-poc, events, lg-shared, profile-app, lg-legacy-import, bb-mirror) extracted under
`/home/ubuntu` (mirrors dev) + `/srv/*` symlinks + ownership. Real dirs: profile-app-media, thumb-app, lg-push.
`useradd -M` (not `-m`) for app users — `-m` pre-creates /srv dir and breaks the symlink.
- ⚠️ **MISSED in the first pass (6/14):** `wp-content/plugins/{lg-layout-v2,lg-snippets}` are **symlinks into
  `/home/ubuntu/projects/`**, but Phase 1 only bundled archive-poc/events/lg-shared/profile-app/lg-legacy-import/bb-mirror
  → those two plugin targets were absent → WP "Plugin file does not exist" → **deactivated** → no v2 editor JSON,
  events render wrong. Fix: bundle `lg-layout-v2`+`lg-snippets` into `/home/ubuntu/projects`, recreate the
  wp-content symlinks, `wp plugin activate`. **At cut: carry EVERY `wp-content/{plugins,mu-plugins}` symlink target**
  (lg-layout, lg-layout-v2, lg-legacy-import, lg-snippets, …) — enumerate `find wp-content/{plugins,mu-plugins} -type l`.

### Phase 2 — PHP-FPM pools ✓
Per-user pool confs in `/etc/php/8.3/fpm/pool.d/` (archive-poc, bb-mirror, events, looth-dev, membership,
profile-app, tool-dev; billing later); www.conf disabled; sockets up. **NOTE: per-user pools are the root
of most "X can't read Y" gotchas below.**

### Phase 3a — Postgres ✓
`looth` (schemas discovery [701 content_item / 675 article_blobs] + forums) + `profile_app` — restored
from dev dumps; peer auth (roles = OS users); roles archive-poc/bb-mirror/looth-dev/profile-app.

### Phase 3b — MariaDB ✓
3 DBs (utf8mb4): `looth_import` (293 tbls, from the **LIVE** dump per DEPLOY-PLAN), `lg_membership` (from
dev). 4 users recreated from password **hashes** (preserves plaintext): looth_dev_user, lg_membership,
profile-app(unix_socket) (loothtool_dev dropped). Live dump had no DEFINER issues (if it does: `sed -E
's/DEFINER=\`[^\`]+\`@\`[^\`]+\`//g'`). Setup SQL: dev `.well-known/dev2-mariadb-setup.sql`.

### Phase 3b — WordPress ✓
`wp core download --version=6.9.4 --skip-content` into /var/www/dev + dev's wp-content bundle over it
(EXCLUDE uploads, uploads.local, cache, ewww, *.log, ai1wm-backups, updraft, upgrade*); `chown -R
looth-dev:looth-dev`. wp-content is SGID 2770 (no world-read).

### Phase 4 — wp-config + secrets ✓
`wp config create` with DB creds + **FRESH salts** (→ LIVE's at cut) + custom defines (redis db1,
FS_METHOD, WP_DEBUG, **DISABLE_WP_CRON**→needs Ph8 cron, AS3CF [carried dev's dead key — see
[[project_dev_wpconfig_aws_key_dead]]], LG_INTERNAL_SECRET-from-file, LG_PROFILE_APP_URL). **WP_HOME/SITEURL
pinned to dev2.loothgroup.com** (shim; looth_import carries live URLs — Ph7 search-replace makes it real).
- `/etc/lg-internal-secret` (root:www-data 0640) — value matches profile-app's carried config.
- **JWT keypair `/etc/looth/jwt-private.pem`(640 root:looth-dev) + `jwt-public.pem`(644)** — generated
  FRESH on dev2 (`openssl genrsa 2048`). This was initially MISSED → every front-end showed anon. WP's
  `profile-auth.php` signs the `looth_id` token with the private key; lg-shell/profile-app verify with public.

### Phase 5 — R2 uploads ✓  (NEW 3rd bucket `loothgroup2-0`)
The new system gets its **own** R2 bucket (Ian) — not the dev clone, not old-live. Populated by
`rclone copy r2:loothgroup-uploads-dev → r2new:loothgroup2-0` (62,344 objs / 6.5GiB; the clone is already
live-clone + dev-adds, so one copy = the union). Dedicated R2 token (read/write/list on loothgroup2-0).
Mounted at `/mnt/loothgroup-uploads`; `wp-content/uploads` → symlink there. See the
**image-serving chain** gotcha — every link bit us. Ian hand-populates any live-only gaps at launch.
- ⚠️ **READ/WRITE, not read-only (Ian 6/14).** Earlier build kept the token read-only "until cut" for tidiness —
  WRONG for a box that becomes prod: WP media upload fails ("could not be moved to wp-content/uploads/…"), so
  you can't test uploads/media, and the ro→rw flip became an untested cut step. `loothgroup2-0` is a DEDICATED
  bucket (not the live clone) → writable poses ZERO risk to live. Grant **write** on its R2 token + remount `rw`.

### Phase 6 — nginx + cookie gate ✓
Bundle `dev2-nginx.tgz`: site conf (dev's, **domain-swapped dev→dev2** incl cookie Domain), gate maps
`conf.d/loothdev-auth.conf` + `loothdev-ratelimit.conf` (without these `$loothdev_is_authorized` is
undefined), 6 strangler snippets, gzip, webroot `deploy.sh`. **DROP the bundled `gzip.conf`** — stock
nginx.conf already has `gzip on` (duplicate → emerg). `/` serves the archive-poc front (front-page = home;
defined in strangler-archive-poc.conf, git-tracked).

### Phase 9 — SSL ✓  (done early, paired with Phase 6)
**certbot DNS-01 via Cloudflare, NOT HTTP-01.** dev2 `:80/:443` isn't internet-reachable (shared SG with
proxied live = CF-IP ranges only), AND loothgroup.com is authoritative on **Cloudflare** (a Route53 TXT is
invisible to LE). `apt install python3-certbot-dns-cloudflare`; CF DNS-edit token in `/root/.cloudflare.ini`;
`certbot certonly --dns-cloudflare …`. Stripped the conf's `options-ssl-nginx.conf`/`ssl-dhparams.pem`
includes (certonly doesn't create them).

### Phase 7 — data / identity / URL-rewrite  (NEARLY DONE — only the index URL-rewrite + person-resync remain, both cut-time)
- ✅ **Identity bridge reconcile:** backfilled WP usermeta `_looth_uuid` from `profile_app.users` by
  `primary_email` for **1699 users** (`profile-auth.php` refuses to mint without it). Header lights up on
  re-login as a matched user (NOT claude_admin/qa-disposable — they have no profile-app row).
- ✅ **Images:** the image-serving chain fixed (see gotcha).
- ✅ **conversions / materialize / standalone render:** VERIFIED on dev2 (6/14) — a managed-CPT article
  (`/post-imgcap/dying-aging-plastic-parts/`) + a video (`/post-type-videos/docs-festival-of-adhesion/`) both
  render 200 through the standalone renderer with real content (28–33KB, real titles, not placeholders).
- **⚠️ REFRAME (6/14) — what is and isn't cut-critical for URLs.** At the cut the box BECOMES `loothgroup.com`, so
  **anything carrying `loothgroup.com` is already correct** and needs NO action: WP content (from the live dump),
  and `archive-poc/web/defaults.php`'s hardcoded sponsor/group/benefit/fallback URLs. The OLD "URL rewrite"
  step (`wp search-replace '//loothgroup.com' '//dev2.loothgroup.com'`) is **dev2-TEST hygiene only** (makes
  dev2 self-contained / no cross-box links) and is **THROWAWAY at the cut — do NOT run it on the live box.**
  - ☐ **THE ONE REAL CUT ITEM — archive-poc discovery index carries `dev.loothgroup.com`** (dumped from dev;
    the renderer emits stored `$it['url']` verbatim — `_render-card.php:22`, `_render-main-row.php`). At cut these
    must be `loothgroup.com`: **reindex from the live data** (preferred — materialize rebuilds them), OR targeted
    `dev.→loothgroup.com` search-replace on the url/thumb columns in PG `discovery.content_item` + `article_blobs`.
    Verify the front page has zero `dev.`/`dev2.` links after.
  - ☐ **bb-mirror person-resync** — after the final snapshot top-off, forum author names go stale (person keyed
    on a recyclable WP user ID; reconcile only refreshes persons whose POSTS changed). Full person-resync post-top-off.
  - Minor: `wp-login.php` emits a stray `dev.loothgroup.com` (likely a plugin/option) — harmless, glance at the cut.

### Phase 8 — systemd units / timers  (✅ DONE on dev2 6/14)
- ✅ WP cron (DISABLE_WP_CRON is set): `/etc/cron.d/looth-wp-cron` → `* * * * * looth-dev cd /var/www/dev && /usr/local/bin/wp cron event run --due-now`.
- ✅ bb-mirror-reconcile (.service+.timer, every 10m) + lg-person-vis-refresh (.service+.timer, every 15m). Both ExecStart normalized to **`/srv/bb-mirror`** (tracks the git clone, not the stale plain-file tree). Both oneshots run-tested clean: reconcile 20 rows, vis-refresh 507 resolved / 0 master-switch-private (**parity with dev: 510 public / 0 private** — NOT a fail-open).
- ✅ Shared env `/etc/lg-loothdev-gate.env` (640 root:looth-dev + ACL u:bb-mirror:r): `LG_LOOTHDEV_GATE_TOKEN` + `LG_BB_MIRROR_PUBLIC_HOST=dev2.loothgroup.com`. **Prereq that gated this:** eefbe97 had to be pulled first (grep confirmed =2) so the loopback host resolves to dev2, not the real dev box. Also set `env[LG_BB_MIRROR_PUBLIC_HOST]` on the bb-mirror + looth-dev FPM pools.
- **poller (Stripe/Patreon):** NOT installed on dev either (Stripe PARKED) → nothing to port; separate parked lane.
- SKIP for prod: mailpit, idle-shutdown, code-server, chrome-dev, **thumbnails*.service** (those are node *editor* UIs on :8080/:3334, authoring tools — not a render dependency).
- **⚠️ AT CUT:** delete `/etc/lg-loothdev-gate.env` (no gate on live) and flip `LG_BB_MIRROR_PUBLIC_HOST` → `loothgroup.com` on the two pools + (if kept) the env file. Same for the `LG_ARCHIVE_POC_PUBLIC_HOST` / `LG_EVENTS_PUBLIC_HOST` pool envs.

### Phase 8b — code deploy model  (NEW 6/14; CORRECTED 6/15 — read this carefully before ANY dev2 deploy)
dev2 had NO git (plain-file bundles). Gave it a **read-only** GitHub deploy key (`~/.ssh/looth_platform_deploy`,
ed25519, "Allow write access" UNCHECKED) + `github-looth` ssh alias. Cloned to `/home/ubuntu/git/looth-platform`;
flipped `/srv/bb-mirror` symlink → the clone's `bb-mirror/`. Old plain-files tree kept at
`/home/ubuntu/worktrees/bespoke-cutover/bb-mirror` as instant rollback (`ln -sfn … && reload`).

**ACTUAL SERVE TOPOLOGY (verified 6/15 — the doc previously got this wrong and it caused a live-looking regression):**
- `/srv/bb-mirror`  → `/home/ubuntu/git/looth-platform/bb-mirror`  — **THE GIT CLONE.** Branch was repointed
  **`bespoke-cutover` → `main`** on 6/15 (per the "AT CUT" note below). **A `git pull` here changes served bb-mirror code.**
- `/srv/archive-poc` → `/home/ubuntu/projects/archive-poc`  — **a SEPARATE plain dir, NOT the clone.** The git pull does
  NOT touch it. Deploy archive-poc files (e.g. `sitemap.php`) by copying straight into `/home/ubuntu/projects/archive-poc/...`.
- nginx snippets → flat copies in `/etc/nginx/snippets/` — **NOT git, and they DIVERGE from dev1/git** (box-specific
  host/path/cache edits). `sha256` of all 3 differed from both git baseline AND dev1 on 6/15.

**HARD RULES (learned the hard way 6/15 — see GOTCHA "dev2 deploy" below):**
1. **NEVER blind `git pull` / `reset --hard HEAD@{1}`.** First confirm branch: `git -C … status -sb | head -1`, and read
   `git -C … reflog -15`. The map work lives on **main's first-parent line, NOT on `bespoke-cutover`** — a clone sitting on
   `bespoke-cutover` SILENTLY LOSES the full-map (`3a5817e`) + other main-only work. A blind pull/reset bounced the checkout
   and looked like the whole site regressed. Recovery is always `git reset --hard origin/main` (canonical cut state).
2. **NEVER wholesale-copy a dev1 snippet over dev2's** (they diverge → clobbers box-specific config). Deploy nginx changes
   as **appended self-contained blocks + an idempotent `sed`**, then `nginx -t` with **auto-rollback to a timestamped backup**.
   Template: `/var/www/dev/.well-known/dev2-seo-deploy-v2.sh` (the SEO deploy — copy its pattern).
- **Deploy now = `git -C /home/ubuntu/git/looth-platform pull --ff-only` (bb-mirror only) + the surgical snippet/file steps + reload.**
- **⚠️ AT CUT:** clone tracks `main` (done 6/15). Prod must not track a lane branch. Read-only deploy key persists.

### Phase 10 — verify (PARTLY DONE)
- ✅ **Anon web sweep (6/14, self-serve from dev):** source-disclosure all locked (`config.php`/`wp-config.php`/`.git/config`
  → 403/404); front/hub/events/article render 200; images resolve from R2; front uses the resizer + srcset.
  - ⚠️ Note (NOT a cut blocker, NOT dev2-specific): events + standalone-article images are raw `/wp-content/uploads`
    (no resizer/srcset) — same on dev; pre-existing craft-debt for the events + standalone lanes (passes the gate on budget).
- ☐ **Member-viewer (needs dev2 WP login):** `tools/gates/run-all.sh` member half · real WP login + `/whoami` tier ladder
  (anon/lite/pro) · member craft surfaces · payments test-mode.
- ✅ refresh-JWT **wrong-key** case (DEPLOY-PLAN blocker) **CLOSED 6/14 on dev2:** forged `looth_id` (faithful claims
  `sub`+`wp_user_id`) signed with a bogus key → `authenticated:false` (REJECTED); same claims signed with the real
  `/etc/looth/jwt-private.pem` → `authenticated:true` (full payload). Proves signature verify works + the dev2 keypair
  pairs → swapping in LIVE's keypair at the cut is safe. (whoami requires BOTH `sub` AND `wp_user_id` claims — a bare
  `sub` token is anon even with a valid signature.)

---

## ⚠️ CUT-CRITICAL GOTCHAS — re-apply on ANY rebuild / the cut box (these live in NO config)
1. **App traversal:** `chmod o+x /home/ubuntu` (dev2 home was 0750; apps live under it via /srv symlinks →
   every FPM pool got "File not found"/403). dev runs 0751.
2. **Env detection:** add `env[LG_ARCHIVE_POC_ENV|LG_BB_MIRROR_ENV|LG_EVENTS_ENV] = dev` to those pool
   confs. The strangler `config.php`s pick `dev` only for `dev.`/`claude` hosts → `dev2.` fell to `live`
   → wanted WP at `/var/www/html` → wp-load fatal (saved-posts/bookmark/bb-auth). **⚠️ AT CUT** host=
   loothgroup.com → `live` branch wants `/var/www/html`+looth-live but the box is `/var/www/dev`+looth-dev:
   keep the env override OR add a proper **"new-prod" branch** to the strangler config.php's.
   **⚠️ The override ALSO belongs on the `looth-dev` (WP) pool (`pool.d/looth-dev.conf`) — MISSED in the first
   pass (6/14).** The WP-pool bb-mirror-api endpoints (`auth`/`topic`/`reply`/`mark-seen`/`unread`/…, all on
   `php8.3-fpm-looth-dev.sock`) load the strangler `config.php` → without the override they `require
   /var/www/html/wp-load.php` → **500**. `auth.php` 500ing is THE cause of the Hub "Sign in to post" for a
   logged-in member (the composer's login check rides that endpoint). dev doesn't need it (host auto-detects `dev`).
   - ✅ **PROPER FIX landed (bb-mirror eefbe97):** `LG_BB_MIRROR_HOST` is now **request-derived** (`$_SERVER['HTTP_HOST']`),
     so dev/dev2/loothgroup.com self-resolve; `LG_BB_MIRROR_ENV` selects PATHS only (keep `=dev` — load-bearing,
     else host=loothgroup.com auto-detects `live`→`/var/www/html`). Set **`env[LG_BB_MIRROR_PUBLIC_HOST]`** =
     `dev2.loothgroup.com` (cut: `loothgroup.com`) on the bb-mirror + looth-dev FPM pools AND the reconcile/vis
     timer units — web self-resolves via HTTP_HOST, but CLI/cron loopbacks have none → without it the visibility
     sync silently fails-open. **Cut shape: `LG_BB_MIRROR_ENV=dev` + `LG_BB_MIRROR_PUBLIC_HOST=loothgroup.com`** (no
     newprod code branch). ✅ archive-poc + events config.php now carry the SAME request-derived host (`8c677aa`):
     set `env[LG_ARCHIVE_POC_PUBLIC_HOST]` / `env[LG_EVENTS_PUBLIC_HOST]` = the public host on their FPM pools (+ any
     CLI/cron timer env) — web self-resolves via HTTP_HOST, CLI loopbacks need the env. (dev2 = `dev2.loothgroup.com`, cut = `loothgroup.com`.)
3. **Secret-reader ACLs** (`acl` pkg must be installed): `setfacl -m u:profile-app:r /etc/lg-internal-secret`
   AND `… /etc/looth/jwt-private.pem`. Rule: ONLY actual readers get a grant — profile-app via ACL,
   looth-dev via www-data-group membership; archive-poc/bb-mirror/events/tool-dev don't read the secret →
   leave alone. **billing-svc (`MemberTierWriter.php`) will need its own ACL when billing deploys.**
   **Also the `membership` user reads `/etc/lg-membership-db`** (dev: `640 root:membership`, read via the
   `membership` group; if dev2/the cut box differs → `setfacl -m u:membership:r /etc/lg-membership-db`). Without
   it the membership pages (`/manage-subscription/` et al.) fatal/degrade. (membership-pages was ALSO missed in
   the Phase-1 bundle — carry `/home/ubuntu/projects/membership-pages` + `env[LG_MEMBERSHIP_ENV]=dev` on the pool.)
4. **Image-serving chain** (symptom ladder: 403→can't traverse wp-content, 404→uploads not a symlink, 200):
   (a) `usermod -aG looth-dev www-data` + restart nginx/fpm — nginx must traverse wp-content (2770, group
   looth-dev); (b) `uploads` MUST be a **symlink** to `/mnt/loothgroup-uploads`, NOT a real dir — `ln -sfn`
   SILENTLY fails if a real `uploads/` exists (WP/`wp core download` create one) → `rm -rf` it first;
   (c) FUSE mount flags `--allow-other --dir-perms 0755 --file-perms 0644` (umask/uid/gid alone weren't
   www-data-readable); mountpoint owned by the mount's User (ubuntu) or fusermount 403s; rclone must be
   **v1.74+** (stock v1.60 501s on R2 with `--vfs-cache-mode full`).
5. **Uploads serve same-origin** (`<siteurl>/wp-content/uploads/` from the mount): AS3CF `serve-from-s3=true`
   but empty delivery-domain → site domain; so the URL search-replace (Ph7) is what makes images resolve.
6. **dev2 deploy = NEVER blind git, NEVER wholesale snippet copy** (learned 6/15 — caused a full-site-looking regression).
   - The bb-mirror clone (`/home/ubuntu/git/looth-platform`) serves live code via `/srv/bb-mirror`. A blind `git pull`
     dragged it across commits; because **map work + other main-only commits are NOT on `bespoke-cutover`**, a checkout on
     that branch silently loses them (map reverted to US-center, etc.). A blind `reset --hard HEAD@{1}` then overshot.
     **Always:** `git status -sb` + `reflog -15` FIRST; recover with `git reset --hard origin/main`; pull with `--ff-only`.
   - `/srv/archive-poc` → `/home/ubuntu/projects/archive-poc` is a SEPARATE dir (NOT the clone) — copy app files there directly.
   - The 3 nginx snippets are flat copies that **diverge from dev1/git** — wholesale-copying a dev1 snippet clobbers
     box-specific config. **Deploy nginx as appended self-contained blocks + idempotent `sed`, `nginx -t`, auto-rollback to
     a timestamped backup.** Reference pattern: `/var/www/dev/.well-known/dev2-seo-deploy-v2.sh`.
   - **The dev session cannot SSH to dev2 (SG blocks :22)** — shell ops are Ian-paste; ship via a `.well-known` bundle +
     a paste-safe SCRIPT (run as `bash file`, NOT pasted with `set -e` — `set -e` + a no-match `grep` logs the shell out).

## Phase 11 — THE CUT (dev2 → loothgroup.com)
- ☐ **FETCH FROM LIVE** (file access, NOT in the DB dump — or sessions/JWTs die at flip):
  - ☐ LIVE's 8 wp-config salt lines (AUTH/SECURE_AUTH/LOGGED_IN/NONCE _KEY+_SALT) → replace dev2's fresh salts.
  - ☐ LIVE's JWT keypair `/etc/looth/jwt-private.pem`+`jwt-public.pem` → carry SAME (else re-mint storm). Verify the wrong-key refresh case.
- ☐ Swap `loothgroup2-0` R2 token read-only → **read/write** (real uploads).
- ☐ Re-apply ALL the cut-critical gotchas above (o+x, env, ACLs, www-data group, uploads symlink, mount flags, acl pkg).
- ☐ gate OFF · real SMTP/R2/secrets (Stripe/Patreon/VAPID) · re-point Stripe/Patreon webhooks → new box.
- ☐ SSL for loothgroup.com; URL rewrite WP **and every app config** (the "new-prod" env branch) + nginx server_name.
  - ⚠️ **Flip DNS + WP URL together, never DNS alone.** WP redirects every request to `siteurl`; if DNS→new box while WP still says `dev2.` you get a redirect loop / wp-admin lockout. `WP_HOME`/`SITEURL` are pinned as wp-config CONSTANTS (the safety net — one-line edit, no DB redirect-loop risk). At cut: flip the constant + content URLs (`//dev2.loothgroup.com`→`//loothgroup.com`) in the SAME window as the DNS flip.
- ☐ DSNs peer→password where the FPM user changes; 5-way /whoami re-arm (poller, lgms creds, BB REST gate, bridge).
- ☐ **Identity-bridge reconcile AFTER the final data top-off** (NOT just the build-time backfill): every WP
  user needs a `profile_app.users`+`wp_user_bridge` row AND usermeta `_looth_uuid==users.uuid`, or /whoami
  returns anon (→ "Sign in to post", header/composer divergence). **Post-snapshot signups are unbridged**
  (found 9 REAL members on dev, not just test accts). Re-run on the cut box: `sudo -u profile-app php
  /srv/profile-app/bin/reconcile-bridge.php` then `sudo WP_PATH=<wproot> /srv/profile-app/bin/backfill-looth-uuid.sh`
  (idempotent; exits non-zero unless GATE GREEN) → affected users must log out/in (JWT sub minted at login).
  Watch for dup-WP-account collisions on `users.primary_email` UNIQUE (e.g. mikelle.davlin wp1848 dup of wp1905 — needs a dedup decision, not a bridge re-run).
- ☐ **PG grant — front-page discussion row (e2ac627):** `GRANT USAGE ON SCHEMA forums TO "archive-poc";` +
  `GRANT SELECT ON forums.topic, forums.forum TO "archive-poc";` — the archive-poc role had `forums.person`
  only; the "Active discussions" row reads `forums.topic`/`forum` directly (the `content_item kind=discussion`
  sync was retired 6/5). **Without it the front page 500s for members.** Applied on dev; re-run on dev2 AND live PG.
  (Also a dev2-deploy prereq: archive-poc is plain files on dev2 → e2ac627 ships as a .well-known bundle, and the grant must be run before/with it.)
- ☐ lower DNS TTL ahead; dress-rehearse → freeze live writes → final delta top-off (incl. sessions) → flip DNS → verify → hold old-live as rollback.
