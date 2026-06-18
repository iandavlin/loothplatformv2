# dev2 post-build wiring punch-list (2026-06-16)

Gaps found while wiring dev2 to "function like dev." The from-scratch build copied
WP + projects code + DBs, but a `wp search-replace` only rewrites the **database** —
it never touches files on disk or out-of-tree data stores. These are the misses.

## FIXED
1. **R2 bucket name + token** — dev2's R2 token is scoped to bucket **`loothgroup2-0`**
   (NOT `loothgroup-uploads-dev`). It was ALSO IP-locked; Ian allowed dev2's IP
   (34.193.244.53). Wrong-bucket was the whole 403 story — token/keys/rclone.conf were fine.
   - Cloned dev→loothgroup2-0 via `rclone sync` (63,077 objs / 6.5 GiB, exact mirror).
   - Mount unit `r2-uploads-dev.service` → `r2:loothgroup2-0` at `/mnt/loothgroup2-0`,
     `--uid 999 --gid 988` (dev2 has no gid-1005 `loothdevs` group). fuse `user_allow_other`
     added; mountpoint chown ubuntu. `wp-content/uploads` → symlink to the mount.
2. **Missing secret ACLs** — `setfacl -m u:profile-app:r /etc/looth/jwt-private.pem` and
   `u:membership:r /etc/lg-membership-db` (build dropped these).
3. **Login broken (cookie host-pin)** — `profile-auth.php` const
   `LOOTH_AUTH_COOKIE_DOMAIN = '.dev.loothgroup.com'` → browser drops the `looth_id`
   cookie on dev2 → `/whoami` anon → header "Sign in". DB search-replace can't touch PHP.
   FIXED via plain search-replace `dev.loothgroup.com → dev2.loothgroup.com` in
   profile-auth.php (cookie domain + iss + hrefs flip together; lint-green; FPM reloaded).
   whoami → `authenticated:true, "Ian Davlin"`. NO code change.
4. **profile-media store missing** — `/srv/profile-app-media/` (avatars, banners, gallery,
   resumes; 19M / 818 files) did NOT exist on dev2 → every `/profile-media/avatars/...`
   404'd. Real local dir (root:root), not in R2, never copied. FIXED via rsync dev1→dev2.
   Resizer (`?w=`) self-heals: media.php regenerates `.cache/<w>/…webp` via GD on first hit
   (GD present, cache writable by profile-app, `/profile-media-internal` nginx loc OK).
   Ian confirmed 2026-06-16: **avatars are back.**

## FIXED (cont.)
5. **Admin/tier wrong** — `/whoami` returned `tier:public`, `tier_unavailable:true`, caps
   all false. Root cause = whoami's poller tier call (`/wp-json/looth-internal/v1/user-context/`)
   401'd then 500'd. TWO infra gaps the build missed:
   - **`looth-dev` not in `www-data` group** → WP pool can't read `/etc/lg-internal-secret`
     (640 root:www-data) → `LG_INTERNAL_SECRET` const empty → poller `hash_equals('',…)` → 401.
     Fix: `usermod -aG www-data looth-dev` + `systemctl restart php8.3-fpm` (matches dev1).
   - **MySQL users `lg_membership` + `profile-app` did not exist** (only `looth_dev_user` made).
     poller Db.php → access denied → 500. Fix: recreated both from dev1's defs
     (lg_membership = mysql_native_password w/ dev1 hash + ALL on lg_membership; profile-app =
     unix_socket + SELECT on lg_membership/looth_import). WP `lgms_db_*` options were already correct.
   RESULT: whoami → `manage_options:true`, `tier:lite`, `provenance:paid`, no tier_unavailable.
6. **Other file host-pins (inventory done)** — most are already host-derived
   (`$_SERVER['HTTP_HOST'] ?? 'dev.loothgroup.com'` CLI fallbacks) or comments. Genuine
   literals to consider via search-replace: `LOOTH_AUTH_ISS`, a hardcoded
   `https://dev.loothgroup.com/profile/edit` href, `BB_MIRROR_SYNC_HOST`, archive-poc/events
   sync Host headers. config.php env-detection ALREADY handles dev2 (has a `dev2` branch).

## FIXED (round 2 — surfaced testing Mikelle + sweep)
7. **Provision bridge dead — `/etc/lg-profile-app-secret` MISSING on dev2** → WP→profile-app
   provision (POST `/profile-api/v0/hooks/user-created`, secret-authed) failed silently, so a
   newly onboarded user got a WP account + `_looth_uuid` but NO profile_app `users`/bridge row →
   mint produces a JWT whose uuid isn't in profile_app → whoami anon → "logged-out header after
   password page" (Mikelle, re-onboarded as WP 1929). FIXED: carried `/etc/lg-profile-app-secret`
   dev1→dev2 (640 root:profile-app; md5 matches the WP `profile_hook_secret` option) +
   `reconcile-bridge.php` to provision the orphaned rows. Mikelle whoami → authenticated, tier:lite.
   CONFIRMED 2026-06-16: after the secret fix, a fresh nuke→re-onboard of Mikelle provisions and
   logs in correctly on its own (no manual reconcile needed). Ian: "works!" — onboard path green.
8. **config.php dev2 detection used the WRONG private IP** — checked `ip-172-31-47-205`, but dev2's
   actual host is `ip-172-31-18-136`. Web requests OK (Host header → `dev2.` match), but **CLI/cron
   scripts have no HTTP_HOST → fall back to gethostname() → resolve env=`live` → hit nonexistent
   `looth_live` DB → fatal** (e.g. reconcile-bridge, and any cron). FIXED: search-replace
   `ip-172-31-47-205 → ip-172-31-18-136` in profile-app/config.php (dev2 copy). CLI now resolves dev2.

## FIXED (round 4 — safe build-gap batch)
9.  **Missing `/srv` apps carried** dev1→dev2: `thumb-app` (15M), `lg-push` (5.1M),
    `lg-sudo-queue` (32K). Created group **`loothdevs` (gid 1005)** on dev2 (+www-data,looth-dev)
    so their `*:loothdevs` ownership maps. (Thumbnails were never actually broken — `/thumb/`→401
    is auth_basic on BOTH boxes.) `/srv/lg-stripe-billing` deliberately SKIPPED (Stripe parked).
10. **Missing secrets carried:** `/etc/lg-vapid` (a DIRECTORY, 700 root:root — web-push keys),
    `/etc/lg-events-db` (640 root:events), `/etc/lg-topoff.conf` (640 root:www-data).
11. **Scheduled jobs installed + enabled** (ran clean, Result=success):
    - `lg-person-vis-refresh.timer` (15min) — as-is, deps present.
    - `bb-mirror-reconcile.timer` (10min) — unit's ExecStart repointed
      `/home/ubuntu/projects/bb-mirror` → `/srv/bb-mirror` (the projects path doesn't exist on dev2).
    DEFERRED: `thumbnails.service`/`-2` (node app `/home/ubuntu/thumbnail-gen-editor` not on dev2 —
    internal thumbnail-editor tool, not public), `lg-sudo-queue` (no `.service` even on dev1).
12. **archive-poc & events env-detection** — like profile-app, their CLI (no HTTP_HOST) resolves
    `live` on dev2 (no dev2 host match). LOWER severity: their DB DSN comes from the FPM pool's
    explicit `LG_*_DSN` env, so web is fine — only CLI/cron host-string fallbacks (link generation)
    would be wrong. NOT yet fixed (would be a `ip-172-31-81-87 → ip-172-31-18-136` search-replace on
    each dev2 config, same as the profile-app fix #8).

## OPEN (round 3 sweep — /srv apps + endpoints)
- **Missing `/srv` backend apps on dev2** (real dirs on dev1, absent on dev2):
  - `/srv/thumb-app` (15M) — thumbnail service (serves `/thumb/`). LIKELY NEEDED.
  - `/srv/lg-push` (5.1M) — web-push service (pairs with missing `/etc/lg-vapid`).
  - `/srv/lg-stripe-billing` (12M) — billing (`/billing/*`); Stripe is PARKED so maybe deferrable.
  - `/srv/lg-sudo-queue` (32K) — sudo-queue helper.
  - (`/srv/profile-app-media` already fixed; `/srv/lg-shared` is a symlink, fine.)
- **Endpoint health (dev2):** `/`, `/hub/`, `/sponsors/`, `/archive-poc/`, `/u/<slug>`, `/whoami`
  all 200. VERIFIED FINE: events (`/event/<slug>` → 301, identical to dev1), archive-api
  (real routes are `search`/`item`/`_sync` — my `/feed`,`/health` test paths were bogus).
- **THUMBNAILS BROKEN** — `/thumb/` → 401 because `/srv/thumb-app` is missing, so nginx can't
  read its `.htpasswd` (alias lines ~423/434). Carrying `/srv/thumb-app` dev1→dev2 should fix it.
- Redis + memcached active (whoami cache OK). No app cron in /etc/cron.d (scheduling is via the
  missing systemd timers above).

## OPEN (from dev1↔dev2 sweep — not yet fixed)
- **Missing /etc secrets on dev2:** `lg-events-db` (events DB creds), `lg-vapid` (web-push keys),
  `lg-topoff.conf` (social backfill). `lg-profile-app-secret` now fixed (#7).
- **Missing enabled systemd units on dev2:** `bb-mirror-reconcile.timer`, `lg-person-vis-refresh.timer`,
  `thumbnails.service` + `thumbnails-2.service`, `lg-sudo-queue.path`. (dev1-only + expected:
  `chrome-dev.service`, `idle-shutdown.service`.) These run as CLI → ALSO would hit the env bug (#8)
  in their app's config until each app's dev2 detection is verified.
- **Group memberships:** dev2 `www-data`/`looth-dev` lack `loothdevs`(gid 1005) + `tool-dev` (the
  `loothdevs` group doesn't exist on dev2 at all). Minor; the www-data membership that mattered (#5/secret) is fixed.
- **MySQL `loothtool_dev_user` absent** — expected (that's for the separate dev.loothtool.com site, not on dev2).

## FIXED (round 5 — env-detection sweep)
13. **All app CLI env-detection** — found `ip-172-31-81-87` (dev1 host) hardcoded in 5 configs;
    profile-app already fixed (#8). Applied `ip-172-31-81-87 → ip-172-31-18-136` search-replace
    (backup + lint each) to: archive-poc, events, membership-pages, bb-mirror config.php. Now
    their CLI/cron resolves the dev-class env (correct DB) on dev2, not `live`.

## STATUS @ end of 2026-06-16 wiring session
- drift-check: **28 aligned / 15 drift / 1 FAIL** (was 25/.../4). Endpoints `/ /hub/ /sponsors/
  /archive-poc/ /whoami /u/<slug>` all 200. Timers firing. Onboard→login→delete all verified.
- **The one remaining drift-check FAIL** = `/home/ubuntu/git/looth-platform` clone missing. dev2
  serves from `/home/ubuntu/projects` + `/srv` symlinks (functional) instead of the intended
  single git-checkout (`deploy = git pull`) model.
  **DECISION (Ian 2026-06-16): cut with the rsync/`/projects` model; the `git pull` deploy model
  is a FAST-FOLLOW (post-cut).** Not a cut blocker.
- **The 15 DRIFT items are the deliberate dev2→live flip** (host pinned to dev2.loothgroup.com:
  cookie domain, JWT iss, wp-config WP_HOME/SITEURL, SSL cert, hostname). Those flip to
  loothgroup.com AT THE CUT (another search-replace pass + loothgroup.com cert + DNS/CF origin
  flip) — per docs/DEPLOY-PLAN.md. Not bugs; expected-at-cut.

## CUT-DAY CHECKLIST (dev→live wire-swaps + blockers) — per docs/DEPLOY-PLAN.md
Blockers:
- [x] **#2 Live WP keys + salts** — provided by Ian 2026-06-16, STAGED at `/etc/looth/live-wp-keys.php`
      on dev2 (600 root:root, 8 defines, NOT in git). At cut: swap these into `/var/www/dev/wp-config.php`
      (replace dev's AUTH_KEY/salts) so existing live login cookies stay valid.
- [x] **#1 Refresh-JWT (wrong-key)** — VERIFIED on dev2: whoami fails safe to clean anon (200, not 500)
      on a wrong-key `looth_id`; a WP-logged-in user with a bad/absent token gets a silent 302 bounce to
      `/looth-auth/issue` → clean re-mint (fresh valid cookie, no loop via the `looth_issue_tried` guard).
- [x] **#3 Live MySQL creds read-only** — CONFIRMED 2026-06-16: `devsync_ro`@`50.19.198.38` on live
      (54.157.13.77 / `wp_loothgroup`) has only `USAGE` + `SELECT` — zero write privs. Build can't touch prod.
      NOTE: the grant is IP-locked to dev1 (50.19.198.38), so the final live dump/top-off must run FROM dev1
      (or add dev2's IP 34.193.244.53 to the `devsync_ro` grant).

Cut-day wire-swaps still to do (none done — dev2 still in dev mode, correct):
- **JWT key**: carry live's `/etc/looth/jwt-private.pem` (same key → existing JWTs verify, no re-mint storm).
- **Live data**: full `mysqldump` of current live (incl. users + `session_tokens`) → dev2.
- **Patreon OAuth**: register `https://loothgroup.com/patreon-callback` in the Patreon app's Redirect URIs
  (exact match, NO trailing slash); at cut `wp option update lgpo_redirect_uri https://loothgroup.com/patreon-callback`.
  Confirm dev2's `lgpo_client_id E5AtYwry…` is the live Patreon app (else swap client_id/secret to live).
- **SSL**: `loothgroup.com` cert on dev2 before flip. **Uploads**: real R2 bucket + write creds (dev2 uses
  the `loothgroup2-0` dev clone). **Email**: mailpit → real SMTP. **Secrets**: real Stripe/Patreon/VAPID/bridge.
  **Webhooks**: repoint Stripe/Patreon → new box. **URL rewrite**: `dev2.`→`loothgroup.com` in WP DB +
  every app config (this punch-list = the app-config catalogue) + nginx server_name + cookie domain + JWT iss.

## NOTES / CONSTRAINTS
- Ian: **search-replaces only, NO code changes** for the host-pins.
- dev2 is NOT a throwaway box — it becomes live at the cut. Be clean + reversible.
- The live dev site does NOT use `/home/buck/*` (that's only the `buck.dev.loothgroup.com`
  preview vhost). Canonical profile-app = `/srv/profile-app` → `/home/ubuntu/projects/profile-app`,
  byte-identical dev1↔dev2.
