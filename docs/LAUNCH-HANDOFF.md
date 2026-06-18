# LAUNCH HANDOFF — dev2 → loothgroup.com (2026-06-17)

Seed doc for a fresh chat. Read this + `CUT-RUNBOOK.md` + `dev2-wiring-punchlist.md`.
Goal: launch loothgroup.com on the dev2 box via **TOP-OFF** (Ian's decision 6/17), then DNS flip.

═══════════════════════════════════════════════════════════════════════
## 0. ACCESS / ENVIRONMENT (orient first)
═══════════════════════════════════════════════════════════════════════
- You run ON **dev1** as `ubuntu` (passwordless sudo). Verify: `curl -s ifconfig.me` → `50.19.198.38`.
- **dev1** = claude.loothgroup.com / dev.loothgroup.com (the working dev box). 50.19.198.38 / internal ip-172-31-81-87.
- **dev2** = the prod-candidate box. Public **34.193.244.53**, hostname **ip-172-31-18-136**, serves **dev2.loothgroup.com**.
  Drive it over SSH: `ssh -i /home/ubuntu/projects/lg-stripe-billing/claude-keypair.pem ubuntu@34.193.244.53`.
- **OLD LIVE** = 54.157.13.77 (loothgroup.com today; stays as rollback after the flip).
- Ian does all AWS (EC2/SG/EIP). dev1's AWS creds are read-only. NEVER terminate dev2 (EBS holds the build).
- Webmin is on dev2:10000 (SG-locked to Ian's IP).

═══════════════════════════════════════════════════════════════════════
## 1. CURRENT STATE (as of this handoff)
═══════════════════════════════════════════════════════════════════════
- **dev2 is rolled back to a clean DEV-mirror** (we loaded live data overnight, then reverted from
  `/home/ubuntu/backups/dev2-looth_import-pre-livedata-*.sql.gz`). Ian's dev login works. profile_app/PG
  was never touched. dev2 functions like dev1 (login, whoami, admin tier, avatars, images, onboard — all green).
- **Staged cut assets (real, ready):**
  - `loothgroup.com` + `www` SSL cert — issued, staged on dev2 at `/etc/letsencrypt/live/loothgroup.com/` (nginx NOT pointed at it yet).
  - Live WP keys at `/etc/looth/live-wp-keys.php` (600 root:root) — **now OPTIONAL** under the top-off plan (see §2).
  - Runbook + punch-list docs.
- **All 3 original deploy blockers cleared:** refresh-JWT verified · live WP keys captured · live creds confirmed read-only.

═══════════════════════════════════════════════════════════════════════
## 2. THE PLAN: LAUNCH VIA TOP-OFF (Ian 6/17)
═══════════════════════════════════════════════════════════════════════
Keep dev2 as the working strangler box; **additively top it off with live content** instead of a full
DB replace. Avoids the `wp_options` wipe + bridge desync (Trap #1). Accepted trade-offs: everyone
RE-LOGINS at cut (no carried sessions), changed live rows aren't updated, WP-ID-colliding live users are
skipped. Because of the re-login, the **live-WP-key swap + session carry are NOT needed** (the staged keys
can stay parked).

Cut shape: **top-off dev2 → URL-rewrite dev2.→loothgroup.com → swap live wires → DNS flip → users re-login.**

Tool: `tools/topoff-dev-from-live.sh` (additive, missing-rows-only; backs up DEV_DB first; stages new
IDs into STAGE_DB then INSERTs). **RE-READ it before each run** to confirm it's still purely additive.
Live creds in `/etc/lg-topoff.conf` (devsync_ro, read-only). NOTE: that grant is **IP-locked to dev1**
(Trap #12) → the top-off/dump must run FROM dev1, or Ian adds dev2's IP to the `devsync_ro` grant.

Known fast-follows (separate): the **git-pull deploy refactor** (dev2 serves from /home/ubuntu/projects +
/srv symlinks, not a git checkout); optional hardening of Trap #1.

═══════════════════════════════════════════════════════════════════════
## 3. ⚠️ DEPLOY TRAPS — where the cut silently breaks (THE important part)
═══════════════════════════════════════════════════════════════════════
Each of these cost us hours. They mostly fail SILENTLY — site loads, login works, but identity/tier/media
quietly break. The top-off plan dodges some; others still apply at the URL-rewrite + wire-swap steps.

### TRAP #1 — Strangler runtime config lives IN the WP database (`wp_options`)
A full DB replace wipes it; the site looks fine but the identity stack is dead. Wiped items:
- `lgms_db_host/port/name/user/pass` (membership DB creds) → poller tier lookup 401/500
- `profile_hook_secret` (WP↔profile-app bridge auth) → provisioning silently fails → new users land anon
- `active_plugins` → the **poller plugin deactivates** (live's list doesn't include it)
- `bb-enable-private-rest-apis` (BB REST gate)
Plus the PG `wp_user_bridge` desyncs. We called this the "5-way re-arm."
→ **Top-off AVOIDS this** (no wipe). If you ever full-replace: re-set all the above + reconcile the bridge,
  and verify whoami returns real tier + `manage_options` (not `tier_unavailable`). `LG_INTERNAL_SECRET`
  is the model done right — it's a `wp-config.php` constant, survived everything.

### TRAP #2 — `wp search-replace` only touches the DATABASE, never files (host pins in CODE)
Host pins baked into PHP files are invisible to `wp search-replace`. The killer one:
`mu-plugins/profile-auth.php` → `LOOTH_AUTH_COOKIE_DOMAIN = '.dev.loothgroup.com'` and
`LOOTH_AUTH_ISS = 'https://dev.loothgroup.com'`. **Wrong cookie domain = the browser silently drops the
`looth_id` cookie → /whoami goes anon → header says "Sign in" while WP login looks fine.** At cut these
must become `.loothgroup.com` / `https://loothgroup.com`. The full file-level catalogue is in
`dev2-wiring-punchlist.md`. Plan a `dev2.loothgroup.com → loothgroup.com` search-replace across BOTH the
DB **and** these served files at cut.

### TRAP #3 — CLI/cron resolve the WRONG env (no HTTP_HOST)
App `config.php`s detect env from `$_SERVER['HTTP_HOST']`; CLI/cron have none → fall back to
`gethostname()`. dev2's hostname is `ip-172-31-18-136`, but the configs checked dev1's `ip-172-31-81-87`
(and profile-app's dev2 branch had a typo'd IP `ip-172-31-47-205`). Result: CLI resolves `live` → tries
the nonexistent `looth_live` DB → **fatal** (reconcile-bridge.php, cron jobs). Web is fine (Host header).
We fixed the dev2 copies via `ip-172-31-81-87 → ip-172-31-18-136` search-replace; verify after any code re-sync.

### TRAP #4 — At cut, `env=live` expects the REAL-live layout that dev2 doesn't have
When host becomes `loothgroup.com`, configs resolve `env=live`, whose branch expects `/var/www/html`,
DB `looth_live`, user `looth-live`. dev2 is `/var/www/dev`, `looth_import`, `looth-dev`. → **everything
breaks.** FIX (the configs were built for this — "ENV=dev but public host loothgroup.com"): pin in the
FPM pools (`/etc/php/8.3/fpm/pool.d/*.conf`) then restart php8.3-fpm:
- profile-app: `env[LG_PROFILE_APP_ENV]=dev2`
- archive-poc: `env[LG_ARCHIVE_POC_ENV]=dev` + `env[LG_ARCHIVE_POC_PUBLIC_HOST]=loothgroup.com`
- events:      `env[LG_EVENTS_ENV]=dev` + `env[LG_EVENTS_PUBLIC_HOST]=loothgroup.com`
- membership:  `env[LG_MEMBERSHIP_ENV]=dev` + `env[LG_MEMBERSHIP_PUBLIC_HOST]=loothgroup.com`
Do this AT cut only (wrong for dev2-now). Also confirm archive-poc `GATE_COOKIE` (dev branch sets
`loothdev_auth`) is harmless with the gate removed.

### TRAP #5 — Identity bridge keyed on RECYCLABLE WP user IDs
`profile_app.wp_user_bridge` maps `wp_user_id` → uuid. A DB reload reuses IDs for *different people* →
stale/wrong mappings (whoami shows the wrong person; avatars fall back because live users aren't
provisioned). With the top-off, dev identity stays and new live users get added → run
`profile-app/bin/reconcile-bridge.php` (with the right env, see Trap #3) to provision them. The uuid is
email-derived (deterministic), so keying on email/uuid instead of WP ID would make this clean (fast-follow).

### TRAP #6 — Program code EXECUTES from the uploads bucket (lg-apps)
`plugins/lg-apps/includes/class-lgapps-widget.php` does `file_put_contents(wp_upload_dir().'/lgapps-tmp/x.php')`
then `require_once` it — **PHP running from the R2-mounted uploads bucket.** Sitewide-fatal if the mount is
down. It regenerates (not data to migrate), but it's fragile. SAFE by contrast: profile-app media is in
`/srv/profile-app-media` (local, NOT the bucket); the standalone CSS/JS bundle is local
(`archive-poc/standalone/web/assets`); mu-plugins + all app code are on local disk.
`looth-cache/members-geo.json` looks STALE (live map fetches the REST route `/wp-json/looth/v1/members-geo`).
Principle (Ian): everything that makes the system RUN should live outside the swappable media bucket.

### TRAP #7 — Uploads is an R2 clone, not the real bucket
dev2 mounts bucket **`loothgroup2-0`** as `wp-content/uploads` (read-write, via rclone FUSE). It's a CLONE
of dev's uploads (which is itself a clone of live). The dev R2 token is scoped to `loothgroup2-0` AND was
IP-locked. At cut, uploads must point at the **REAL live R2 bucket with WRITE creds** — not this clone.
Gotcha history: the dev2 token 403'd for ages purely because we aimed at the wrong bucket name; and the
initial `rclone sync` DELETED 677 non-media objects from `loothgroup2-0`.

### TRAP #8 — Session invalidation + WP keys (mostly moot under top-off)
Any DB reload wipes `session_tokens` → existing cookies die → logout. WP keys (AUTH_KEY/salts) live in the
`wp-config` FILE, not the DB; a new box with different keys kills all live cookies. Under the top-off plan
everyone re-logins anyway, so this is expected, not a bug. (If you ever switch to full-replace: carry live
keys from `/etc/looth/live-wp-keys.php` + live `session_tokens`.)

### TRAP #9 — Broad search-replace mangles EMAIL addresses
`wp search-replace loothgroup.com → X` rewrites `user@loothgroup.com` emails too. At the real cut you go
dev2.→loothgroup.com (so `@dev2.loothgroup.com` test emails get fixed), but be deliberate about the
direction and watch the `wp_users.user_email` column.

### TRAP #10 — WP version vs `$wp$` hashes (verified OK, keep in mind)
WP 6.8+ stores bcrypt `$wp$` password hashes; older WP can't verify them → "incorrect password" for
everyone. dev2 = dev1 = **6.9.4** (verifies both `$wp$` and `$P$`), so fine — but the launch box must
stay ≥ live's WP version.

### TRAP #11 — Verify in a BROWSER, not curl
Curl misses redirects, JS, and the logged-in header state — the actual user experience. Use the
`chrome-dev-login` skill (CDP on dev1:9222) to drive real Chrome and SCREENSHOT. (We burned trust proving
login with curl when the real question was browser behavior.) Note: post-login redirect currently lands on
the FRONT PAGE, not /wp-admin (a redirect setting) — that's why you type the /wp-admin URL.

═══════════════════════════════════════════════════════════════════════
## 4. CUT-DAY WIRE SWAPS (dev → live) — from CUT-RUNBOOK.md §D
═══════════════════════════════════════════════════════════════════════
- **URL rewrite**: dev2.→loothgroup.com in WP DB + the served-code host pins (Trap #2) + nginx `server_name`
  + point nginx at the loothgroup.com cert.
- **Uploads/R2**: real bucket + write creds (Trap #7).  **Email**: mailpit → real SMTP.
- **Secrets**: real Stripe/Patreon/VAPID/bridge.  **Webhooks**: repoint Stripe/Patreon → new box.
- **Patreon OAuth**: register `https://loothgroup.com/patreon-callback` in the Patreon app (exact match,
  no trailing slash); `wp option update lgpo_redirect_uri https://loothgroup.com/patreon-callback`.
- **Env pins**: Trap #4.  **Cookie gate**: already open on dev2 (correct for live).
- **DNS/CF**: lower TTL ahead of time; flip origin → dev2; hold old-live as rollback for a defined window.

═══════════════════════════════════════════════════════════════════════
## 5. IMMEDIATE NEXT STEP
═══════════════════════════════════════════════════════════════════════
1. Re-read `tools/topoff-dev-from-live.sh`, confirm purely additive.
2. Run it against dev2 (from dev1; live creds are IP-locked to dev1) to pull live content onto the working box.
3. Verify in a BROWSER (Trap #11): dev2 front page, a real login, whoami tier, avatars.
4. Then work the URL-rewrite + wire-swaps + DNS flip per CUT-RUNBOOK.md.

Verbatim launch ruling: launch via TOP-OFF; git-pull deploy is a fast-follow refactor.
