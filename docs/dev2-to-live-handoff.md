# dev2 → live (loothgroup.com) handoff — cut readiness

**Date:** 2026-06-14 · **From:** dev-box coordinator session · **For:** whoever takes dev2 to the cut.
**Companion docs (READ THESE):** `docs/dev2-build-checklist.md` (the re-runnable runbook + every cut-critical
gotcha) and `docs/DEPLOY-PLAN.md` (strategy: new box + DNS flip).

## TL;DR
`dev2.loothgroup.com` (the prod candidate) **serves end-to-end AND is now substantially complete.** A long
session today closed ~a dozen "the hand-build carried the DB but not the files/perms/plugins" gaps. What's
left: a few **pending deploys to dev2**, then the **Phase 11 cut wire-swaps**. Nothing here is mysterious —
it's all enumerated below and in the runbook.

## Access model (important — and better than the runbook says)
- dev2 = `ubuntu@34.193.244.53` + `loothgroup2-0.pem` (re-check IP after any relaunch). Ian drives by hand.
- The dev session **cannot SSH to dev2** (SG blocks :22) **but CAN reach dev2 over the public web** —
  `curl`/headless-Chrome `https://dev2.loothgroup.com/` works with the gate cookie
  `loothdev_auth=qShCjBdCVXLie7wcQddsprkYj4SuaXu7UJeYAHHG` (403 without it). So **HTTP/web verification is
  self-serve from dev**; only shell ops (wp-cli, psql, files, perms, deploys) need Ian to paste.
- Deploy transport: build bundle on dev → serve at `https://dev.loothgroup.com/.well-known/` → Ian pulls →
  **delete after** (dev disk ~85%).
- ⚠️ **Mixed deploy model — DO NOT treat it as "one git pull" (that misread caused a regression 6/15):**
  `bb-mirror` is a git checkout (`/home/ubuntu/git/looth-platform`, tracking **`main`** as of 6/15) served via `/srv/bb-mirror`;
  **archive-poc is a SEPARATE plain dir** (`/home/ubuntu/projects/archive-poc`, NOT the clone); **nginx snippets are flat
  copies that DIVERGE from dev1/git.** Before any deploy: confirm branch (`git status -sb`) + read `reflog`; pull `--ff-only`;
  copy app files into their real dirs; deploy nginx as appended blocks + `nginx -t` + auto-rollback (never wholesale-copy).
  Full rules + the recovery recipe live in **dev2-build-checklist.md → Phase 8b + CUT-CRITICAL GOTCHA #6**. Converting dev2
  to a single full git checkout (so deploys really are one pull) is still a good idea — but it isn't the state today.

## DONE this session (on dev2, verified)
- **Plugins:** `lg-layout-v2` + `lg-snippets` bundled + active (were missing → v2 editor + events broke).
  Resolved the `lg-snippets`↔`code-snippets` redeclare conflict (deactivated dup DB snippets 39,44,86,90,92,93,95,96).
- **Identity bridge:** found **112 real members unbridged** (post-snapshot signups, not just test accts) → fixed, `missing=0`. Slugs fixed.
- **Composer "Sign in to post":** ROOT = `auth.php` 500 — the env-branch gotcha on the **looth-dev/WP pool**.
  Fixed with `env[LG_BB_MIRROR_ENV|LG_ARCHIVE_POC_ENV|LG_EVENTS_ENV]=dev` in `pool.d/looth-dev.conf`. Cleared every WP-pool bb-mirror-api 500.
- **Composer image upload (CORS):** `rest_base` pointed at dev.loothgroup.com. Band-aid (relative, `edf7fcd`)
  + proper host-from-request fix (`eefbe97`). **Keep `LG_BB_MIRROR_ENV=dev` (paths only now).**
- **Membership pages:** app bundled (was missing) + `/etc/lg-membership-db` created (root:membership 640) + `env[LG_MEMBERSHIP_ENV]=dev`.
- **R2:** flipped **read-only → read/write** (removed `--read-only` from the rclone mount unit). Uploads work.
- **Avatars:** synced `/srv/profile-app-media` (~507 files) — 404→200 verified from dev. Fallback (Optimum for photo-less) via `dev2-avatar-fallbacks.sh` + Provision default (`bcd9109`).
- **events "My Profile":** `/u/<slug>` fix (was `/profile/edit`).
- **Permissions/ACL audit: ALL GREEN** after 2 fixes (`setfacl u:profile-app:r /etc/looth/jwt-private.pem` + the membership secret).
- **Front-page work** (live discussion modal, cream cards, dark mode, font-smoothing): pushed to `main`; dev2 bundle = `dev2-archive-front.tgz` (pull issued 6/14 — confirm applied).

## PENDING to dev2 (queued deploys — confirm each applied)
1. ✅ **`eefbe97`** (bb-mirror host fix) PULLED on dev2 (grep=2) + `env[LG_BB_MIRROR_PUBLIC_HOST]=dev2.loothgroup.com` set on the bb-mirror + looth-dev pools and the Phase-8 timer env file. Loopback vis sync verified NOT failing-open (parity with dev).
2. **`dev2-avatar-fallbacks.sh`** — photo-less → Optimum pass.
3. **`dev2-archive-front.tgz`** — front-page modal/CSS/dark (pull issued).
4. **`dev2-audit.sh` (content/DB half)** — ✅ charset verified `utf8mb4` (no 1366 risk); bridge + materialization still to confirm.
5. ✅ **Phase 8 timers** INSTALLED on dev2 (6/14): WP cron `/etc/cron.d/looth-wp-cron`, bb-mirror-reconcile.timer (10m), lg-person-vis-refresh.timer (15m). Both oneshots run-tested clean. Units written via heredoc (no bundle), ExecStart on `/srv/bb-mirror`.
6. **`dev2-host-derive.tgz` (`8c677aa`)** — archive-poc + events `config.php` host-from-request fix + footer refund-link 404 fix (see OPEN-lane host item, now CLOSED). Extract to `/home/ubuntu/projects`; set `env[LG_ARCHIVE_POC_PUBLIC_HOST]` / `env[LG_EVENTS_PUBLIC_HOST]` = `dev2.loothgroup.com` on those pools; reload FPM. **Paste-commands handed to Ian.**
7. ☐ **bb-mirror Hub paragraph fix `9510cbf`** (branch `bespoke-cutover`, NOT pushed yet) — Hub bodies collapse `\n\n` (no wpautop at render); fix = shared `bb_mirror_content_html()`. **content_html is a STORED column → pulling code is not enough; must RE-BACKFILL on dev2** (dev was: 1282 topics / 4995 replies). Sequence at cut/dev2: push `bespoke-cutover` → `git pull` on dev2 clone → re-run bb-mirror backfill. Until then dev2's Hub bodies stay collapsed. Folds into the cut's bb-mirror materialize step (merge `bespoke-cutover`→`main` first). Gate: `hub-content-paragraph-gate.sh` = run-all.sh GATE 4/4 (`b16fcc7`, projects main). **Open Ian decision:** forum descriptions share the column + latent bug, left untouched — close in same lane?

## Re-runnable audits (in dev's .well-known)
- `dev2-perms-audit.sh` — pool→app-dir reads + secret-reader ACLs (currently all-green).
- `dev2-audit.sh` — routes / charset / R2 r+w / discussion grant / materialization.
- Web crawl from dev: 148/149 links clean. ~~footer "Billing & Refund" → `/billing-refund/` 404~~ ✅ **FIXED `8c677aa`**
  (`_chrome-footer.php` → `/request-refund/`; ships in `dev2-host-derive.tgz`).

## OPEN lane work (briefs written or owed — coordinator routes; NOT dev2-build)
- **events weekly email render (#2):** lead/unsent digest should preview as email HTML via `LG_WD_Email_Builder` (brief written). NOT a dev2 bug — dev does the same; unsent issues have no FluentCRM body.
- ~~**host-from-request:** events + archive-poc `config.php` owe the same~~ ✅ **DONE `8c677aa`** (bundle `dev2-host-derive.tgz`) — both now request-derive the host with `LG_*_PUBLIC_HOST` CLI fallbacks, same as bb-mirror `eefbe97`. Remaining: deploy to dev2 (paste-commands ready) + set the pool env.
- **Buck ping:** front-page cream/dark/font touch his redesign tokens; `hub_teaser` is live. (msg relay.)
- Optional: **convert dev2 to a full git checkout** (kills the tar bundles).

## THE CUT — Phase 11 (full list in dev2-build-checklist.md)
Each wire-swap MUST land: **LIVE's wp-config salts + the SAME JWT keypair** (else every session/JWT dies) ·
**final live mysqldump incl. users/sessions** · R2 token → real bucket + write · **gate OFF** · real
SMTP/Stripe/Patreon/VAPID + re-point webhooks · SSL for loothgroup.com · **URL rewrite WP + every app config +
nginx server_name** · **identity-bridge reconcile AFTER the top-off** (post-snapshot signups). **Re-apply every
cut-critical gotcha** (o+x /home/ubuntu, the env overrides incl the looth-dev pool, ACLs incl membership,
www-data→looth-dev group, uploads symlink, FUSE mount flags, `acl` pkg). Lower DNS TTL → dress-rehearse →
freeze live writes → final delta top-off → flip DNS → verify → hold old-live as rollback for a defined window.
