# Coordinator / sysadmin session handoff — 2026-06-02

Boots the next session. Supersedes `briefing-coordinator-2026-06-01.md`. Read this
+ `STRANGLER-SESSION-HANDOFF.md`, then you're oriented.

---

## 🔴 CRITICAL — read before touching anything

1. **DEV WP NOW RUNS ON LIVE DATA.** `wp-config` `DB_NAME=looth_import` — a clone of
   live (1,801 users / 19,766 posts, pulled from the R2 nightly backup). The old
   fixture DB `looth_dev` is **preserved untouched** as rollback.
   - **Roll back the flip:** repoint `/var/www/dev/wp-config.php` `DB_NAME` → `looth_dev`
     (backup at `wp-config.php.bak.*`). Bridge + uploads symlink described below.
2. **MAIL IS CAPPED — keep it that way.** Dev inherited live's FluentSMTP/SES config
   and fired **~140 real reminder emails to real members** before containment. Now:
   **FluentCRM + fluentcampaign-pro + fluent-smtp are DEACTIVATED**, outbound SMTP is
   iptables-REJECTed, and WP mail falls back to msmtp→mailpit. **RULE: whenever dev runs
   on live data, those plugins STAY OFF** (FluentSMTP bypasses the mailpit catcher → real
   sends). See `reference_dev_mail_mailpit` memory.
   - ⚠ The iptables SMTP block is **in-memory** (won't survive a reboot). The durable
     protection is the deactivated plugins (persists in DB). Re-add the block if paranoid.
3. **NOTHING IS PUSHED.** Ian's standing rule this session: **no git push.** All the
   merges + doc edits below are **local commits on `main` only**. Don't push without his ok.
4. **TWO UNEXPLAINED REBOOTS this session** (memory pressure during heavy DB ops; bumped
   MariaDB buffer pool 128M→1G but the 2nd still happened). Auto-recovered both times.
   **Root cause NOT nailed** — worth diagnosing before more heavy/bulk work.

---

## ✅ Shipped this session

**Identity / looth_id (was member-wide broken since 5/25):**
- Fixed the JWT key perms. End state: `/etc/looth/jwt-private.pem` = `root:looth-dev 640`
  **+ ACL `g:profile-app:r`** → BOTH minters read it (WP legacy `looth-dev` pool AND the
  canonical profile-app pool's `Mint.php`). Don't chgrp it back. Verified end-to-end.

**Buck's profile brief — all 6 branches MERGED to canonical `main` (local, unpushed):**
- gallery-polish, freeform-block, freeform-caddy, resume, header-links, banner. Conflicts
  resolved keep-both/union (u.php IIFEs, Block.php, _render_blocks.php). `php -l` clean,
  `/u/iandavlin` 200.
- Applied to `profile_app` PG: `2026-06-02-resume.sql` + `2026-06-02-banner.sql`.
- nginx: `me/banner|resume|freeform` rewrites added to BOTH `preview-buck-profile-app.conf`
  + `strangler-profile-app.conf`. Added `/claim` block to `buck.dev.loothgroup.com.conf`.
- sudo-queue `buck-2026-06-02-1..5` all RESOLVED.

**The dev→live-data flip (see CRITICAL #1):**
- R2: cloned live `loothgroup` bucket → `loothgroup-uploads-dev` (server-side, 59,131 obj,
  verified 0 diff). Dev token scoped to that bucket only + IP-locked (50.19.198.38) —
  cannot touch live. rclone installed; config `/home/ubuntu/.config/rclone/rclone.conf`.
- `/var/www/dev/wp-content/uploads` → **symlink to `/mnt/loothgroup-uploads-dev`** (systemd
  `r2-uploads-dev` mount, enabled). Old fixture dir → `uploads.local`.
- Bridge rebuilt: `wp_user_bridge` remapped to live IDs by email→uuid5 (1,612/1,690 mapped;
  rest provision on login). siteurl/home + content `search-replace`d loothgroup.com→dev.

**Garbage cleanup (downstream stores re-materialized from looth_import):**
- archive-poc SQLite reindexed (2,186) + 36 orphans pruned. bb-mirror PG `forums` backfilled
  + 16 orphans pruned. (bb-mirror backfill must run as `looth-dev`, not www-data — peer-auth.)
- Converted 1 video (post 70990) via `wp lg-legacy export --apply` — surfaced 2 gaps:
  VideoExtractor doesn't read the oembed URL (only the empty `youtube_link` ACF), and
  `post-type-videos` isn't v2-managed (legacy template still renders).

**Docs / artifacts:**
- `docs/TIER-TAXONOMY.md` (authoritative), `docs/OWNERSHIP-CUTOVER-AUDIT.md` (+ P10 in
  CUTOVER-PLAN), `docs/relay-stripe-pages-standalone-and-shell.md` (standalone + shell relay).
- **Visualizers** (served, cookie-gated): `/mockups/db-map.html` (current DB topology),
  `/mockups/db-map-proposed.html` (consolidation target), `/mockups/content-flow.html`
  (VERIFIED CPT+discussion read flow — spine verified from code, 6 inferred nodes flagged).

---

## 📋 Open / pending

- **content-flow.html rewrite IN PROGRESS** — Ian asked to re-orient it top-down (data at
  top → user at bottom, user not central). Was mid-edit when handoff requested.
- **lg-stripe-billing `WP_DB_NAME=looth_dev` is STALE** (should be `looth_import` post-flip).
  Flagged on the DB map, not fixed.
- **~140 real emails went out** — Ian to decide on a correction note; recipient list pullable
  from `wp_fsmpt_email_logs`.
- **Forum "garbage" still in WP** — test topics (e.g. `fsdfs`) Ian wants gone, but I can't
  auto-detect (most `test`-titled topics are real). Awaiting his list; then delete-from-WP +
  re-sync the stores.
- **Standalone lane:** nonce-via-loopback bridge shipped (`looth/v1/rest-nonce`,
  `membership-2026-06-02-3` RESOLVED). 3 light slugs ported; ~7 action slugs + heavy 4 pending
  Ian's dispatch of `relay-stripe-pages-standalone-and-shell.md`.
- **DB consolidation (agreed direction):** one PG `looth` w/ schemas `forums`/`discovery`/
  `profile`, **password DSNs (not peer-auth)**, one backfill pipeline. Sequence: (1) password
  DSNs, (2) archive-poc SQLite→`discovery` schema (discussions = view over `forums.topic`),
  (3) declarative roles/grants in the build script. Not started.
- **Reboot root-cause** (see CRITICAL #4).
- **CPT conversion follow-up:** fix VideoExtractor oembed fallback; make conversions a
  repeatable migration script (the cut-day artifact); then convert the video/loothprint CPTs.
- **Parked decisions (Ian):** Luth Pro tier threshold (looth2/3/4?), Shop Picks ownership,
  timed-bypass-for-teaser plugin port (PAUSED).

---

## 🧠 Key context

- **Comms:** Buck ↔ me via the `msg` CLI (I'm in `devmsg`). Ian relays + decides.
- **Memories updated:** `reference_r2_dev_clone`, `reference_dev_mail_mailpit`,
  `reference_tier_taxonomy`, `reference_devmsg_buck_ian_channel`.
- **DB layout** (see the map): MariaDB = `looth_import`(WP live), `looth_dev`(rollback),
  `lg_membership`(poller/billing), `loothtool_dev`. Postgres = `profile_app`, and `looth`
  (schemas `forums`=bb-mirror, `discovery`=archive-poc's PG target).
- **Cut model** = blue-green (fresh box, proven migrations, fresh dump at cut). Dev-on-live
  is a REHEARSAL; you ship code+migrations, not dev data. (`cutover/CUTOVER-PLAN.md`.)
