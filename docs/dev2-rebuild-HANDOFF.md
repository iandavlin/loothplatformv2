# dev2 from-scratch rebuild — HANDOFF (2026-06-16, late)

## WHO YOU ARE / GOAL
You run ON dev1 (the WORKING box) as `ubuntu` (`curl ifconfig.me` → 50.19.198.38). dev1 is the
source of truth; its repo is `/home/ubuntu/projects`. **You have direct SSH to dev2** — drive it
yourself, don't paste commands to Ian.

**Goal:** dev2 is being rebuilt FROM SCRATCH as a faithful mirror of dev1, because dev2 *becomes
live* (the cut = flip `loothgroup.com`'s Cloudflare origin to dev2). It was a drifted patchwork;
tonight we wiped that approach and built clean. **It is ~95% done and functional — only images
(R2 mount) + a logged-in spot-check remain.**

## ACCESS
- **dev2 SSH:** `ssh -i /home/ubuntu/projects/lg-stripe-billing/claude-keypair.pem ubuntu@34.193.244.53`
  (non-interactive Bash SSH is fine). EIP `34.193.244.53` → `dev2.loothgroup.com` (DNS already set).
- **Instance:** `i-0d727225b8b74192f` (name `loothgroup2-1`), SG `sg-049c5d5efdcfefd50`. Was a
  t3.micro (1GB) — TOO SMALL, OOM'd; Ian resized it to ~4GB. Private IP 172.31.18.136.
- **AWS creds on dev1 (`devgbox-cli`) are READ-ONLY** — you can `describe`, you CANNOT start/stop/
  resize/modify-SG/move-EIP. **Ian does all EC2 actions.** Do NOT terminate the instance (EBS holds
  the whole build). `i-0c3d40baecd141757` (loothgroup2-0, t3a.medium, stopped) is a DIFFERENT unused box.

## DONE — dev2 is a functional mirror of dev1 (all verified)
- Full stack installed = dev1: PHP 8.3.6 + all modules, nginx 1.24, PostgreSQL 16, MariaDB 10.11,
  redis, memcached, rclone 1.74.3, certbot. All services active + auto-start on boot.
- App users/groups created (looth-dev, archive-poc, profile-app, bb-mirror, events, membership);
  `www-data` in `looth-dev` group; `/home/ubuntu` chmod 751.
- Secrets carried with exact ACLs: `/etc/looth/jwt-{private,public}.pem`, `/etc/lg-internal-secret`
  (+acl profile-app:r), `/etc/lg-membership-db`, `/etc/lg-loothdev-gate.env`, `/etc/lg-archive-poc-secret`
  (+acl archive-poc:r). Also carried `/etc/letsencrypt/cloudflare.ini` + `options-ssl-nginx.conf` + `ssl-dhparams.pem`.
- **All DBs mirrored + verified == dev1:** MySQL `looth_import` (293 tables) + `lg_membership` (26);
  PG `profile_app` (users=1910, wp_user_bridge=1822) + `looth` (discovery.content_item=711). MySQL
  user `looth_dev_user` created w/ grants on looth_import+lg_membership. PG roles via `pg_dumpall
  --roles-only`; dev1's `pg_hba.conf` copied (peer auth); DBs created with owners (profile_app→profile-app, looth→bb-mirror).
- WP core+config+content rsync'd from dev1 (`sudo rsync --rsync-path="sudo rsync"`). wp-config
  `WP_HOME`/`WP_SITEURL` pinned to `https://dev2.loothgroup.com`. **chown -R looth-dev:looth-dev
  /var/www/dev** (files came as dev1's UID 1004/ian, mode 660, unreadable by pool → that was the
  first 403; chown fixed it). `wp-content/uploads` = a **real empty writable dir** (NOT the dangling
  R2 symlink) — that cleared the lg-apps sitewide fatal (lg-apps writes uploads/lgapps-tmp then requires it).
- App code rsync'd to `/home/ubuntu/projects/{archive-poc,profile-app,events,lg-shared,membership-pages,platform}`
  + bb-mirror to `/home/ubuntu/worktrees/bespoke-cutover/bb-mirror`; `/srv/*` symlinks wired to match dev1.
- 6 FPM pools copied (`/etc/php/8.3/fpm/pool.d/`), default www.conf disabled. nginx: site conf +
  6 snippets + `conf.d/{loothdev-auth,loothdev-ratelimit}.conf` copied, all `dev.`→`dev2.` rewritten.
- **Real `dev2.loothgroup.com` SSL cert** (issued ON dev1 via CF DNS-01 — the CF token is IP-locked
  to dev1 — then copied to dev2). nginx -t green.
- **COOKIE GATE OPENED — public, no token** (matches the cut). Edited `conf.d/loothdev-auth.conf`:
  removed the `"00" 0;` line so `$loothdev_is_authorized` is always 1.
- wp-cli installed (copied dev1's `/usr/local/bin/wp`). Content **search-replace `dev.`→`dev2.` done**
  (11,773 replacements, 0 `dev.` left), cache flushed.
- **VERIFIED serving (no cookie):** `/`, `/front-page/`, `/hub/`, `/sponsors/`, `/profile-api/v0/whoami`
  all 200; `/wp-login.php` 200; `/looth-auth/issue` 302. dev2 "runs like dev1" — except images.

## LEFT (in priority order)
1. **R2 uploads mount = the only real blocker (images).** STUCK on a 403. Details:
   - Ian made an **Object Read & Write token, scoped to the bucket, NOT admin** (he refuses admin —
     do not suggest it). Token (verified char-for-char correct):
     AK `29738bf4f0e4085e03c174282dc5d5cb`, SK `8621c16bce41d703a8634436d6e90142f913462e05371d5991ca37bda8f76da2`,
     account/endpoint `https://2b34fc01f7fc32230a76c1490ac64b13.r2.cloudflarestorage.com`, bucket `loothgroup-uploads-dev`.
   - dev2 rclone.conf `[r2]` now MATCHES dev1's working one exactly: `provider=Cloudflare, region=auto,
     no_check_bucket=true, acl=private, disable_checksum=true`. Clock synced (1s skew).
   - **Symptom:** EVERY op (HeadObject, PutObject, ListObjectsV2) → **403 Forbidden / AccessDenied**
     on `loothgroup-uploads-dev`. dev1's token reads the SAME bucket fine.
   - **Ian's steer (LOAD-BEARING):** "it's not the token, it's not admin, you had a tiny syntax error
     in the rclone config last time, websearch rclone." So the fix is a small rclone-config detail, NOT
     a token/scope change. Web search so far only surfaced `no_check_bucket=true` (already applied).
   - **NEXT STEP:** diff dev1's EXACT working `~/.config/rclone/rclone.conf` `[r2]` block (INCLUDING the
     access_key_id/secret — is dev1's token actually account-scoped, and is its key format different?)
     vs dev2's, char-by-char. Check rclone version behavior (dev1 1.74.3 same). Consider the recent
     AWS-SDK checksum/`X-Amz-Content-Sha256` header issue + any per-op option. The HEAD request signs with
     empty-payload sha256 `e3b0c44...` (looks normal). Find the tiny config delta that makes dev1 work.
   - **Then mount:** copy dev1's systemd unit `/etc/systemd/system/r2-uploads-dev.service` (ExecStart:
     `rclone mount r2:loothgroup-uploads-dev /mnt/loothgroup-uploads-dev --allow-other --uid 999 --gid 1005
     --umask 022 --dir-cache-time 12h --vfs-cache-mode full --vfs-cache-max-size 4G`); `mkdir /mnt/loothgroup-uploads-dev`;
     set `user_allow_other` in `/etc/fuse.conf`; enable+start; then `ln -sfn /mnt/loothgroup-uploads-dev
     /var/www/dev/wp-content/uploads` (it's currently a real dir — rm+symlink). Mount MUST be writable
     (lg-apps writes lgapps-tmp). **NEVER copy media to EBS (Ian's rule) — serve from the mount.**
2. **Logged-in spot-check:** Ian browses `https://dev2.loothgroup.com`, Sign in as `ian.davlin@gmail.com`
   → expect name in header + member content (proves whoami/JWT path). The mint route `/looth-auth/issue` is live.
3. **Instance stability:** make sure it doesn't auto-stop (the micro version "ran a minute" then died — that
   was OOM on 1GB, now resized). No idle-shutdown daemon was found on the box.

## LANDMINES
- AWS = read-only for you → Ian does EC2 (start/stop/resize/SG/EIP). Don't terminate the instance.
- Don't copy R2 media to EBS — mount only.
- CF cert token + dev R2 token are IP-locked to dev1 (issue certs on dev1, copy over).
- Ian is exhausted and has been burned by whack-a-mole all night — be decisive, drive over SSH, report at milestones.
- Reference: `docs/CUT-FROM-SCRATCH.md` (the from-scratch runbook) + `tools/dev2/dev2-drift-check.sh`.
