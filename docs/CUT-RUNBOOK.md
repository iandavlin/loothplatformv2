# CUT RUNBOOK — dev2 → live (loothgroup.com)

## ⚑ LAUNCH DECISION (Ian, 2026-06-17): launch via TOP-OFF, not full-replace
Keep dev2 as the working strangler box and **additively top it off with live content**
(`tools/topoff-dev-from-live.sh`, missing-rows-only) instead of a full `mysqldump` replace.
Rationale: the full replace wipes the strangler's `wp_options` config (lgms/profile_hook_secret/
poller) + desyncs the bridge every time — a fragility we won't fight at launch. Top-off preserves
all of that.
- **Accepted trade-off:** everyone does a clean RE-LOGIN at cut (no carried live sessions); live
  rows that *changed* aren't updated (only new rows added); live users whose WP ID collides with an
  existing dev user are skipped. Acceptable for launch.
- **Implication:** the live-WP-key swap + session_tokens carry become UNNECESSARY (nobody's old
  cookie needs to survive — they re-login). The staged `/etc/looth/live-wp-keys.php` can stay parked.
- **Fast-follow (separate, known):** the git-pull deploy refactor; and (optional) moving strangler
  config out of `wp_options` to harden future full-replaces.
- **Cut shape now:** top-off dev2 → URL-rewrite dev2.→loothgroup.com → swap live wires (R2/SMTP/
  secrets/SSL/webhooks) → DNS flip → users re-login.
- BEFORE RUNNING the top-off: verify `topoff-dev-from-live.sh` is purely additive (it stages new
  IDs into STAGE_DB then INSERTs only — confirmed it backs up DEV_DB first; re-read before each run).

### ⚠️ BODY-STEP RECONCILIATION — the C–F steps below predate this top-off decision
Sections C/D/E were written for the OLD full-DB-replace plan and still say "carry WP keys /
session_tokens." Under the ACTIVE top-off plan those are **MOOT**:
- §C "full `mysqldump` replace" → use `tools/topoff-dev-from-live.sh` (additive) instead; the full
  dump is only the fallback if you ever abandon top-off.
- §C import `session_tokens` · §D "**WP keys**: REQUIRED" · §E "carried keys" → **SKIP**. Everyone
  re-logins, so no cookie needs to survive; the staged `/etc/looth/live-wp-keys.php` stays parked.
- Everything else in C–F (URL rewrite, FPM env-pins, R2/SMTP/secret/webhook swaps, DNS/CF flip,
  rollback) still applies exactly as written.

---


Ordered, command-level cut procedure. Strategy = DEPLOY-PLAN.md (new box + DNS flip).
dev2 (34.193.244.53) is the new box; old live = 54.157.13.77. Drive dev2 over SSH
(`ssh -i .../claude-keypair.pem ubuntu@34.193.244.53`). Catalogue of app host-pins =
`dev2-wiring-punchlist.md`. **Nothing here is executed yet — this is the plan.**

## STATUS GOING IN (2026-06-16)
- Box wiring: dev2 is a faithful, functioning mirror of dev (login/whoami/admin tier/avatars/
  images/onboard/delete all green). Build-gaps closed + logged in the punch-list.
- Blockers: #1 refresh-JWT VERIFIED · #2 live WP keys STAGED (`/etc/looth/live-wp-keys.php`) ·
  #3 live creds READ-ONLY CONFIRMED.

## WHAT IAN STILL MUST PROVIDE / DECIDE (gating)
1. **Live JWT private key** — `/etc/looth/jwt-private.pem` from LIVE. Carrying the SAME key lets
   existing `looth_id` JWTs verify with no re-mint. **NOW OPTIONAL (not a hard blocker):** blocker #1
   is VERIFIED — a wrong-key/absent JWT + valid WP cookie silently bounces → re-mints cleanly. So
   without live's key, the cost is a one-time silent re-mint bounce per user at flip (not a logout).
   Carry it to avoid that bounce; skip it and the re-mint absorbs it. (The LIVE WP keys, by contrast,
   are MANDATORY — without them the WP cookie itself is invalid and there's nothing to re-mint from.)
2. **Live data load timing** — when to do the full `mysqldump` of current live into dev2 (step C).
   This replaces dev2's dev data with live's; do it as the dress-rehearsal, then a final delta at flip.
3. **Patreon app** — register `https://loothgroup.com/patreon-callback` in the Patreon app Redirect
   URIs; confirm `lgpo_client_id E5AtYwry…` is the LIVE app (else swap client_id/secret).
4. **DNS TTL** — lower loothgroup.com TTL days ahead (CF), and the go/no-go flip window.
5. **devsync_ro grant** — add dev2 IP `34.193.244.53` to the live grant, OR run the final dump FROM dev1
   (grant is IP-locked to 50.19.198.38).

## PRE-STAGE (days ahead, reversible, no user impact)
- [ ] Lower loothgroup.com DNS TTL (CF) — Ian.
- [x] **DONE 2026-06-16** — `loothgroup.com` + `www.loothgroup.com` cert issued on dev1 (CF DNS-01,
      ECDSA, exp 2026-09-14) and STAGED on dev2 at `/etc/letsencrypt/live/loothgroup.com/`
      (fullchain + privkey 600). nginx NOT pointed at it yet (server_name still dev2). Renewal runs on dev1.
- [ ] Stage live JWT key at `/etc/looth/jwt-private.pem.live` (600) on dev2 (do not swap yet).
- [ ] Point Stripe/Patreon webhooks + register the loothgroup.com Patreon redirect URI.
- [ ] Dress-rehearse C–F below against a staged copy.

## C. LOAD CURRENT LIVE DATA (build/test, then final delta at flip)
- [ ] From dev1 (creds in `/etc/lg-topoff.conf`, devsync_ro, read-only):
      `mysqldump -h 54.157.13.77 -u devsync_ro -p<…> wp_loothgroup | …` → import into dev2's WP DB
      (incl. users + `wp_usermeta session_tokens` so sessions carry).
- [ ] Reconcile identity bridge (`profile-app/bin/reconcile-bridge.php`, env=dev2) for the new users.
- [ ] Re-run the 5-way post-reload `/whoami` re-arm: poller active, lgms_db_* creds, BB REST gate,
      bridge gaps (these break on every data reload — see project_cf_reload_whoami_casualties).
- [ ] Decide WP DB name mapping: dev2 serves `looth_import`; live dump is `wp_loothgroup`. Keep importing
      INTO `looth_import` (wp-config DB_NAME stays) so app configs don't need a DB rename.

## D. SWAP DEV WIRES → LIVE WIRES (the punch-list cut-checklist)
- [ ] **WP keys**: splice `/etc/looth/live-wp-keys.php` into `/var/www/dev/wp-config.php` (replace the
      8 dev AUTH_KEY/salts). REQUIRED for live cookies to survive.
- [ ] **JWT key**: swap in live's `/etc/looth/jwt-private.pem`.
- [ ] **URL rewrite dev2.→loothgroup.com**:
      - WP DB: `wp search-replace dev2.loothgroup.com loothgroup.com --all-tables-with-prefix; wp cache flush`
      - App code/config (search-replace, the punch-list catalogue): profile-auth.php (cookie domain +
        iss), and any literal `dev2.loothgroup.com` in served mu-plugins/app configs. NB cookie domain
        becomes `.loothgroup.com`, iss `https://loothgroup.com`.
      - nginx: `server_name dev2.loothgroup.com;` → `loothgroup.com;` in the site conf; point at the
        loothgroup.com cert.
      - `wp option update home/siteurl https://loothgroup.com` (if not covered by search-replace).
      - Patreon: `wp option update lgpo_redirect_uri https://loothgroup.com/patreon-callback`.
      - **Env at cut (THE landmine — but the configs are built for it):** when host=loothgroup.com the
        apps would resolve env=`live`, whose branch expects the REAL-live layout (`/var/www/html`,
        `looth_live`, `looth-live` user) — which dev2 does NOT have (dev2 = `/var/www/dev`, `looth_import`,
        `looth-dev`). FIX = pin env to the dev-class value + set the public host, in each FPM pool
        (`/etc/php/8.3/fpm/pool.d/*.conf`), then `systemctl restart php8.3-fpm`:
          - profile-app pool : `env[LG_PROFILE_APP_ENV]=dev2`  (dev2 branch already = correct paths/DB;
            its `LG_PROFILE_APP_HOST` literal gets fixed by the dev2.→loothgroup.com search-replace)
          - archive-poc pool : `env[LG_ARCHIVE_POC_ENV]=dev`  +  `env[LG_ARCHIVE_POC_PUBLIC_HOST]=loothgroup.com`
          - events pool      : `env[LG_EVENTS_ENV]=dev`       +  `env[LG_EVENTS_PUBLIC_HOST]=loothgroup.com`
          - membership pool  : `env[LG_MEMBERSHIP_ENV]=dev`   +  `env[LG_MEMBERSHIP_PUBLIC_HOST]=loothgroup.com`
        Rationale: env selects PATHS/DB (keep dev2's actual layout); PUBLIC_HOST + the request host build
        URLs (loothgroup.com). Do NOT pre-stage these — they're wrong for dev2-now (would make CLI links say
        loothgroup.com early); set them at cut only. Also verify archive-poc's `GATE_COOKIE` (dev branch sets
        `loothdev_auth`) is harmless with the gate already removed at nginx — confirm in dress-rehearsal.
- [ ] **Uploads/R2**: repoint to the REAL R2 bucket + WRITE creds (dev2 uses the read-only `loothgroup2-0`
      clone). Update rclone remote + the mount unit.
- [ ] **Email**: mailpit → real SMTP (else welcome/reset/notify die silently).
- [ ] **Secrets**: real Stripe/Patreon/VAPID/bridge/HMAC (dev2 has dev/test).
- [ ] **Cookie gate**: already open on dev2 (correct for live) — confirm the map default-allows.

## E. FLIP WINDOW
- [ ] Freeze old-live writes.
- [ ] Final delta top-off (activity since the build dump, incl. sessions).
- [ ] Flip loothgroup.com DNS/CF origin → dev2.
- [ ] Verify: real logins survive (live cookie + carried keys), gates green, whoami/tier, images,
      onboard, payments in test mode, the refresh-JWT cases.

## F. ROLLBACK
- DNS is reversible, but once users WRITE to dev2, flipping back loses that data. Pre-set low TTL +
  a defined go/no-go window. Hold old-live (54.157.13.77) as rollback for that window.

## OPS / TOOLS ON dev2
- **Webmin** installed (v2.641) on dev2, port 10000, root login set. Network exposure handled by the
  AWS SG (locked to Ian's IP — no host firewall, per Ian). KEEP that SG tight after the cut (never
  world-open :10000 on the live box).

## FAST-FOLLOW (post-cut, per Ian 2026-06-16)
- Convert dev2 to the single git-checkout deploy model (`deploy = git pull`) instead of rsync/`/projects`.
