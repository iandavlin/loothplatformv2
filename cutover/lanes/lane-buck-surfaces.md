# Buck-zone operating manual (coordinator-stewarded while buck is away)

How to run buck's whole zone without collision or waiting on him. NOT a rewrite — his code is left
as-is, mapped + brought under our gates. Hand back on his return (pinged via `msg`). Front-end *file*
security is in `docs/BUCK-SURFACES-AUDIT-2026-06-13.md`; this is the operational map (run `buck-zone-map`
workflow `w8sgs0ul9` for the raw detail).

## ⚠️ Operating rules for buck's stuff
- His files are owned by `buck` (front-end / scripts) or `tool-dev` (loothtool WP / theme). **Run/edit as
  the owning user** (`sudo -u buck …`, `sudo -u tool-dev …`) so output ownership stays correct, then
  chown back. Running his scripts as root/ubuntu re-owns outputs and breaks his next cron.
- `find -user buck` MISSES the loothtool side (owner is `tool-dev`) AND DB-only customizations (wp_snippets).

## 1. Automation — ONE hourly cron
- **`07 * * * * /home/buck/bin/refresh-shop-feed.sh`** (the only buck cron; no systemd timers). Builds the
  shop bubble: wp-eval against `/var/www/dev.loothtool` → mirrors+WebP product thumbs into
  `/var/www/dev/shop-img/` → validates each product's live loothtool.com URL → writes `shop-feed.json`;
  last line calls `mirror-vendor-logos.py` (Dokan vendor avatars + the site logo → `shop-vendors.json`).
- **Run manually:** `sudo -u buck /home/buck/bin/refresh-shop-feed.sh`. Verify: `jq length
  /var/www/dev/shop-feed.json` (~26), `jq length /var/www/dev/shop-vendors.json` (~10), tail
  `/home/buck/logs/shop-feed.log` for "DONE".
- **Collisions/gotchas:** the `:07` cron OVERWRITES any hand-edit of those JSON/img outputs within the hour
  — change the *generator*, never the output. The departed-vendor EXCLUDE list is DUPLICATED in the `.sh`
  (PHP heredoc) and the `.py` — edit both. The prune loop's `vendor-*`/`loothtool-logo*` guard is
  load-bearing (a prior prune ate the logo). 3+ stale copies of the script exist (`/home/buck/_work`,
  `/home/buck/temp/pwa-verify`) — **only `/home/buck/bin/` is live**; the script's own header comment is
  stale. The coordinator's 320px logo cap in `mirror-vendor-logos.py` is now correctly placed (module level).
- *Nits:* `shop-feed.log` has no rotation (grows forever); hard external deps (Dokan API, cwebp, live curls) with no alerting.

## 2. Web-push backend — `/srv/lg-push` (100% DARK today)
- **Pipeline:** browser subscribes (`push.js` → `POST /push-subscribe.php` → `wp_lg_push_subscriptions`) →
  something enqueues `wp_lg_push_queue` → `run-queue.php` (root) drains it, signs with VAPID
  (`/etc/lg-vapid`, root:root 0600), POSTs to push services → `sw.js` shows it.
- **State:** sender is OFF — **no cron, publish hook staged-only.** Client is live + accumulating subs
  (2 rows), so it LOOKS half-wired but delivers nothing until a **root** cron for `run-queue.php` + the
  mu-plugin land. Test a send: `sudo php /srv/lg-push/send-test.php --count` (all as root — VAPID is root-only).
- **Collision trap:** the sender cron MUST be in **root's** crontab (VAPID dir is 700 root) — under buck/www-data
  it silently `exit(2)` every tick. Also a name-collision: a live `mu-plugins/lg-event-reminders.php` exists
  but is UNRELATED (Ian's FluentCRM email one).
- Security on `push-subscribe.php` (unauth write + hijack) is in the buck-surfaces audit (H1).

## 3. Branches + how buck's code DEPLOYS
- **Hub/forum PHP** (`_feed.php`, `forums.css`, `api/v0/*`): edit in
  `/home/ubuntu/worktrees/bespoke-cutover/bb-mirror/` — served live instantly via the `/srv/bb-mirror`
  symlink (no build). Worktree is ubuntu-owned → `git -c safe.directory='*'` works; commits land on
  **bespoke-cutover**. Publish: `git push origin bespoke-cutover`.
- **Front-end overlay JS** (`/var/www/dev/*.js`): edited **in place**, NOT a git repo. Recovery = buck's
  `*.bak` files only. `hub-overlay-flag/*.js` is a STALE fork that LOSES to live — never `cp` it over live.
- **⚠️ TWO bb-mirror trees:** served PHP = the **bespoke-cutover worktree** (`/srv/bb-mirror`); but
  `reconcile.php` (the WP→PG sync) runs from the **main-branch** `projects/bb-mirror`. A render fix goes in
  the worktree; a sync fix goes in projects. Don't confuse them.
- **pwa.js cache-busting is two-level + manual** (nginx `?v=` busts pwa.js; pwa.js's internal `?v=N` busts
  each child JS). A missed bump pins a stale overlay for users.

## 4. Handoffs + sub-coordination
- **Read:** `sudo cat /home/buck/Sharing/*` (his HANDOFF docs + COORD-STATUS). **Msg:** `msg thread buck`,
  `msg send buck "…"` — but **buck doesn't reliably read devmsg replies**, so confirm landings through Ian.
- **Merge a buck branch:** bundle/fetch from `/home/buck/looth-platform`, `git merge --no-commit --no-ff`,
  enforce his file-set guard, UNION-resolve additive conflicts. (Buck merge policy: auto-merge
  trivial/clobber-clean; HOLD policy/privacy/member-data for Ian.)

## 5. Loothtool (dev.loothtool.com) — separate WP install
- **Target it explicitly:** `cd /var/www/dev.loothtool && sudo -u tool-dev wp <cmd>` (DB `loothtool_dev`).
  Browse: claim its OWN gate token at `https://dev.loothtool.com/claim?t=…`. Edit theme files as ubuntu+sudo
  then `sudo chown tool-dev:loothdevs`; `sudo systemctl reload php8.3-fpm` after PHP edits.
- The **site logo source** lives here (`hello-elementor-child/assets/loothtool-logo-tight.png`, now 320×94).
- **7 customizations live ONLY in the DB** (`wp_snippets`) — a filesystem grep misses them.

## 🚨 Launch-relevant issues the map surfaced (NOT in the front-end audit)
- **[HIGH/continuity] bb-mirror's 5 security commits (C2/H6/H7/SSRF) + 1 uncommitted `_feed.php` edit live
  ONLY in the bespoke-cutover worktree** — one `git reset`/worktree-prune from loss. **Push them.**
- **[HIGH/secret] `/var/www/dev.loothtool/wp-content/plugins/loothtool-ads/CREDENTIALS.md`** stores plaintext
  secrets incl. an **Anthropic API key (`sk-ant-…`)**, group-readable on disk. Rotate + remove.
- **[HIGH] WS3 catalog handlers missing behind a LIVE route:** `practice_services`/`practice_instruments`
  PG tables + nginx routes exist, but the handler PHP only lives unmerged in `/home/buck/Sharing` → broken endpoint.
- **[MED] `/profile-media` nginx alias serves with ZERO auth** (gated galleries/banners/resumes by direct
  URL) and `/profile-api/v0/directory/members` hands anon all ~668 member UUIDs (buck flag, confirm vs the
  visibility-model "/profile-media auth'd" claim — possible regression).
- **[MED] ~229 stray `.bak` + wp-config backups fetchable over HTTP** behind only the gate cookie (creds/salts/JWT source).
- **[MED] loothtool payment secrets in `wp_options` plaintext** (Stripe/Shopify/Shippo keys); a sudoers grant
  lets the loothtool FPM user halt the shared dev box.
- **[MED] events `data-start/data-end` never emitted** (a ~2-line ubuntu-lane edit; `events-live.js` no-opping a week).
