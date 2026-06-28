# 2026-06-28 SHIP BATCH — Live Cutover Runbook (umbrella)

> **Deploy = everything it takes to make live CLEAN and functioning** — ship + verify + decommission
> what it replaced + remove orphaned/stale artifacts. The job is not done until live carries no dead
> weight from the change (see §8). This is a standing definition, not a one-off.

Ships the full 8-commit batch merged to `main` (`origin fcfba93 → 275b1cd`) to **LIVE** in one
ordered motion. **Keeper-gated** — nothing here runs on live without Ian's OK; live = hand-Ian bash.

## ⚠️ Deploy model — use the PULL path, NOT `deploy/deploy.sh`
The running live box serves from **`/var/www/dev`** via the **serve clone** (`~/loothplatformv2-serve`,
`git pull`) + root-owned `/srv` symlinks + real webroot/mu-plugin files. `deploy/deploy.sh` targets
**`/var/www/html`** (the from-scratch / new-box model) with a push-rsync that would clobber the live
serve-clone rewire. **Do NOT run `deploy/deploy.sh` on the current live box.** This runbook uses the
live pull model. Every step that changes served PHP bytes ends with `sudo systemctl reload php8.3-fpm`
(opcache — proven required on dev2 6/28).

## The batch (main @275b1cd)
| Surface | Files | Deploy path |
|---|---|---|
| Membership repoint + Manage Account rename + join/connect copy | strangler-membership.conf, site-header.php, manage-subscription.php, join.php, connect-your-patreon.php | SERVE-CONSOLIDATE runbook |
| Mobile You-menu (Reset-pw row + icon removal) | webroot/bottom-nav.js | webroot/deploy.sh |
| Password-page shell | platform/mu-plugins/lgpo-set-password.php | mu-plugin file sync |
| Onboarding copy (poller) | lg-patreon-onboard.php, README | poller sync (mu-loaded) |

---

## 0. Prereq (keeper)
- **D1 — `git push origin main`** (`fcfba93 → 275b1cd`). Review the 8 commits + diffstat with Ian
  FIRST (no silent push). Live serve clone pulls `origin/main`.

## 1. Advance the live serve clone (common to ALL surfaces — do ONCE)
```bash
cd ~/loothplatformv2-serve
git status --short                       # MUST be clean (live has NO overlay, unlike dev2's slug pin)
git fetch origin && git checkout main && git pull --ff-only origin main
git rev-parse --short HEAD                # -> 275b1cd
grep -m1 'Manage Account' membership-pages/web/manage-subscription.php
sudo systemctl reload php8.3-fpm
```
**Rollback:** `git checkout fcfba93 && sudo systemctl reload php8.3-fpm`.

## 2. Membership + rename + lg-front  →  run `docs/SERVE-CONSOLIDATE-MEMBERSHIP-RUNBOOK.md`
Run its **Steps 1, 3, 4, 5, 6** (`/srv` symlinks, nginx snippet swap, `/v2` vhost sed, reload,
verify). **SKIP its Step 2** (serve-clone advance) — already done in §1 here. Its rollback section
applies.

## 3. Mobile webroot (`bottom-nav.js`)
Live webroot = **real files** (dev2 = symlinks). Push from the serve clone:
```bash
sudo ~/loothplatformv2-serve/webroot/deploy.sh /var/www/dev looth-dev
grep -c 'Reset password' /var/www/dev/bottom-nav.js     # -> 2 (comment + row); person icon gone
```
**Cache-bust:** `bottom-nav.js` carries no `?v=` → the PWA service worker can serve a stale copy;
bump the SW/`pwa.js` version, or users hard-refresh / Clear site data. Verify on a real phone.
**Rollback:** restore the prior `bottom-nav.js` (deploy.sh from the previous serve-clone sha).

## 4. Password-page mu-plugin (`lgpo-set-password.php`)
```bash
sudo cp ~/loothplatformv2-serve/platform/mu-plugins/lgpo-set-password.php \
        /var/www/dev/wp-content/mu-plugins/lgpo-set-password.php.bak-ship-$(date +%Y%m%d-%H%M%S) 2>/dev/null || true
sudo cp ~/loothplatformv2-serve/platform/mu-plugins/lgpo-set-password.php \
        /var/www/dev/wp-content/mu-plugins/lgpo-set-password.php
sudo chown looth-dev:looth-dev /var/www/dev/wp-content/mu-plugins/lgpo-set-password.php
sudo systemctl reload php8.3-fpm
```
**Verify:** log in as a test member → `/patreon-password/?change=1` = 200 WITH site header/footer +
inline set-password copy.
**Rollback:** restore the `.bak-ship-*` file + reload fpm.

## 5. Onboarding copy in the poller (`lg-patreon-onboard.php`) — ⚠️ CONFIRM PATH AT CUT
The poller is **mu-loaded** post-reconcile (NOT in `wp-content/plugins/`). KEEPER: confirm the live
loaded path at cut time — if the loader requires from the serve clone, §1 already deployed it; else
sync the file to the live poller location **as looth-dev**. The poller also has incremental patchers
(`lg-patreon-stripe-poller/deploy/`) — file-sync only, NEVER deactivate (poller deploy must not break
admin login).
**Verify:** live onboard file contains `Set a password so you can sign in directly`; **smoke: admin
login OK + `/whoami` 200**.
**Rollback:** restore prior file + reload fpm.

## 6. Combined LIVE verify
- 13 membership routes `200`; `/manage-subscription/` title = **Manage Account**; `/v2/assets/lg-front.js` `200`.
- Mobile You-menu (logged OUT): **Reset password** row present, **no** person icon.
- `/patreon-password/` (logged in): shelled + inline copy.
- Onboarding copy: join/connect read "Set a password…", **no** "check your email".
- **Smoke:** admin login + `/whoami` `200`; a fresh Patreon onboard lands **logged in** (dev2-verified
  happy path — see [[project_patreon_onboard_findings]] for the contaminated-teardown trap, NOT a
  new-user bug).

## 7. Full rollback
Per-step rollbacks above + the SERVE-CONSOLIDATE one-shot. The dead `~/projects` repo stays in place
as the membership safety net.

## 8. Decommission — leave live CLEAN (part of the deploy, not optional)
Deploy is "done" only when the replaced artifacts are gone and live carries no dead weight. After §6
is green and a short **soak** (rollback window closed — Ian's call, e.g. 24–72h):

- **Remove the orphaned `~/projects` serve checkout** (LIVE **and** dev2). It is the retired
  `looth-platform` / `lane-profile-app` repo; post-cut **nothing** serves from it (verified: no
  nginx/fpm/router bindings — membership-pages + `/v2` were the last two surfaces). It was the
  rollback net DURING the cut; once soaked, it goes.
  ```bash
  ls -la /home/ubuntu/projects                 # CONFIRM it is purely the dead looth-platform checkout
  cat /home/ubuntu/projects/.git/HEAD          # -> refs/heads/lane-profile-app
  git -C /home/ubuntu/projects bundle create ~/looth-platform-FINAL-$(date +%Y%m%d).bundle --all  # final safety bundle
  sudo rm -rf /home/ubuntu/projects            # only after the bundle + Ian OK
  ```
  **Guard:** if `~/projects` holds anything beyond the dead repo, remove only the orphaned subtrees
  (`membership-pages`, `lg-layout-v2`) — never blind-`rm` a dir with live-needed loose files.
- **Archive the retired GitHub repo** `iandavlin/looth-platform` (a backup bundle already exists on
  the keeper box; this is the long-pending GitHub-side archive).
- **Delete the stray duplicate** `lg-shell/lg-shared/site-header.php` in the repo (dead copy of the
  canonical `lg-shared/site-header.php` — confirmed not served).
- **Clean cut-time backups** once soak passes: `*.bak-serveconsol-*`, `*.bak-ship-*` on live.
- **Docs:** update `docs/atlas/SYSTEM-MAP.md` to record `~/projects` **removed** from the serve path
  (currently marked "repointed" — mark fully decommissioned).

**Verify CLEAN:** `grep -rn '/home/ubuntu/projects' /etc/nginx /etc/php/8.3/fpm` returns nothing;
`ls /home/ubuntu/projects` absent (or only non-served remnants); all §6 routes still `200`.
