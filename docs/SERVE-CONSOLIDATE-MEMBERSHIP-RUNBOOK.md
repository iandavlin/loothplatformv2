# SERVE-CONSOLIDATE-MEMBERSHIP — Live Cutover Runbook

**Lane:** `serve-consolidate-membership` · **Author:** serve-consolidate chat · **Date:** 2026-06-28
**Keeper-gated:** nothing here runs on LIVE without Ian's OK. Modeled on the profile-lane
SLUG-LIVE-APPLY runbook (ordered steps · exact bash · per-step verify · per-step rollback).

## What this does
Repoints the **last two app-facing surfaces still served from the DEAD repo**
(`/home/ubuntu/projects`, branch `lane-profile-app` on the retired `iandavlin/looth-platform`)
onto the **monorepo serve clone** `~/loothplatformv2-serve`, via root-owned `/srv` symlinks —
the same pattern as `/srv/{profile-app,archive-poc,lg-shared,bb-mirror,events}`:

1. **ALL membership pages** — `strangler-membership.conf` `alias` + `SCRIPT_FILENAME`
   (`manage-subscription, join, lgjoin, connect-your-patreon, welcome, lggift, lggift-buy,
   my-gifts, affiliate-earnings, request-refund, membership-guide, regional-pricing-not-available,
   test-checklist`) → `/srv/membership-pages/web/`.
2. **lg-layout-v2 `/v2/` asset alias** (in the site vhost) → `/srv/lg-layout-v2/`.

After cutover the dead `~/projects` repo is OUT of the live serve path. (Archiving/removing
`~/projects` itself is OUT OF SCOPE — left in place as a safety net.)

## Parity (verified clean 2026-06-28, live-ro vs canonical `main`@3cfa04f)
- **membership-pages/web:** every file byte-identical EXCEPT `manage-subscription.php`, whose
  ONLY delta is the **Manage Subscription → Manage Account** rename (title + h1). Canonical is
  *ahead*; **no live-only edits.**
- **lg-layout-v2:** 3 files differ, ALL canonical-*ahead* (live is older), no live-only edits:
  `assets/lg-front.js` (iOS single-tap fix, Ian 6/17), `blocks/embed/render.php` (featured-image
  poster, 6/25), `src/WpAssets.php` (inline-CSS bundle fallback). Only `lg-front.js` is served
  via `/v2/` (PHP is 403-denied there).
- **Net:** cutover loses nothing and moves live *forward* (rename + the iOS fix reach the page).

## ⚠️ Hard dependencies (BEFORE the nginx cutover means anything)
- **D1 — push `main` to origin.** `origin/main` is `fcfba93` and still says "Manage
  Subscription". Local/canonical `main` `3cfa04f` (carries the rename, 4 commits ahead) is
  **UNPUSHED**. Live's serve clone pulls `origin/main`, so the rename only reaches live after
  `git push origin main`. **[Keeper action]**
- **D2 — merge this lane** (`serve-consolidate-membership`) to `main`: adds
  `platform/nginx/strangler-membership.conf` (the repo source-of-truth this cutover copies),
  this runbook, and the SYSTEM-MAP update. **[Keeper-gated]**
- **D3 — opcache.** Any in-place content change at a FIXED served path (the serve-clone
  `git pull` advancing `manage-subscription.php`, or re-targeting a `/srv` symlink) is invisible
  until **`sudo systemctl reload php8.3-fpm`** — PHP opcache/realpath-cache serves the stale
  compiled file otherwise. **Proven on dev2 6/28** (symlink flip showed old title until FPM
  reload). EVERY step below that changes served bytes is followed by an FPM reload.

---

## LIVE CUTOVER (hand-Ian bash — run on the LIVE box)

> Identify the live vhost first (filename differs from dev2):
> ```bash
> LIVE_VHOST=$(grep -rl 'alias /home/ubuntu/projects/lg-layout-v2/' /etc/nginx/sites-available/ | head -1)
> echo "LIVE_VHOST=$LIVE_VHOST"   # expect e.g. /etc/nginx/sites-available/loothgroup.com.conf
> TS=$(date +%Y%m%d-%H%M%S)
> ```

### Step 1 — Create the `/srv` symlinks (additive; nginx still on `~/projects` here — safe)
```bash
sudo ln -sfn /home/ubuntu/loothplatformv2-serve/membership-pages /srv/membership-pages
sudo ln -sfn /home/ubuntu/loothplatformv2-serve/lg-layout-v2     /srv/lg-layout-v2
ls -ld /srv/membership-pages /srv/lg-layout-v2
# read tests as the actual serving users:
sudo -u membership head -1 /srv/membership-pages/web/router.php     # -> <?php
sudo -u www-data  head -c 40 /srv/lg-layout-v2/assets/lg-front.js   # -> /* lg-layout-v2 ...
```
**Verify:** both symlinks resolve; both read tests succeed.
**Rollback:** `sudo rm /srv/membership-pages /srv/lg-layout-v2` (nothing points at them yet).

### Step 2 — Advance the live serve clone to `main` (pulls rename + lg-front.js fix) + FPM reload
```bash
cd ~/loothplatformv2-serve
git status --short                       # confirm no unexpected overlay (live should be clean)
git fetch origin && git checkout main && git pull --ff-only origin main
grep -m1 'Manage Account' membership-pages/web/manage-subscription.php   # -> confirms rename present
sudo systemctl reload php8.3-fpm
```
**Verify:** `grep` finds "Manage Account"; FPM reload clean.
**Note:** depends on **D1** (push). If the live serve clone carries any local overlay (it should
NOT — unlike dev2's slug-rehearsal pin), STOP and coordinate with keeper before pulling.
**Rollback:** `git checkout <prev-sha> && sudo systemctl reload php8.3-fpm`.

### Step 3 — Deploy the membership nginx snippet (repo → box-local)
```bash
# diff first so you see exactly what changes (expect ONLY the path swaps ~/projects -> /srv):
diff <(sudo cat /etc/nginx/snippets/strangler-membership.conf) \
     ~/loothplatformv2-serve/platform/nginx/strangler-membership.conf
sudo cp -a /etc/nginx/snippets/strangler-membership.conf \
           /etc/nginx/snippets/strangler-membership.conf.bak-serveconsol-$TS
sudo cp ~/loothplatformv2-serve/platform/nginx/strangler-membership.conf \
        /etc/nginx/snippets/strangler-membership.conf
grep -nE 'alias /srv/membership-pages|SCRIPT_FILENAME /srv/membership' \
        /etc/nginx/snippets/strangler-membership.conf
```
**Verify:** diff shows only path lines; grep shows `/srv` alias + SCRIPT_FILENAME.
**Rollback:** `sudo cp /etc/nginx/snippets/strangler-membership.conf.bak-serveconsol-$TS /etc/nginx/snippets/strangler-membership.conf && sudo nginx -t && sudo systemctl reload nginx`.

### Step 4 — Repoint the `/v2/` asset alias in the live vhost
```bash
sudo cp -a "$LIVE_VHOST" "$LIVE_VHOST.bak-serveconsol-$TS"
sudo sed -i 's#alias /home/ubuntu/projects/lg-layout-v2/;#alias /srv/lg-layout-v2/;#' "$LIVE_VHOST"
grep -n 'alias /srv/lg-layout-v2/;' "$LIVE_VHOST"
```
**Verify:** grep shows the `/srv` alias.
**Rollback:** `sudo cp "$LIVE_VHOST.bak-serveconsol-$TS" "$LIVE_VHOST" && sudo nginx -t && sudo systemctl reload nginx`.

### Step 5 — Test config + reload nginx & FPM
```bash
sudo nginx -t && sudo systemctl reload nginx && sudo systemctl reload php8.3-fpm
```
**Verify:** `nginx -t` "successful" (pre-existing duplicate-MIME *warnings* are fine).

### Step 6 — Verify on LIVE (replace TOKEN/host as needed; live has its own gate)
```bash
for s in membership-guide manage-subscription connect-your-patreon lgjoin lggift-buy lggift \
         my-gifts affiliate-earnings request-refund test-checklist welcome \
         regional-pricing-not-available join; do
  printf '%-32s %s\n' "$s" "$(curl -sk -o /dev/null -w '%{http_code}' https://<LIVE_HOST>/$s/)"
done
curl -sk https://<LIVE_HOST>/manage-subscription/ | grep -o '<title>[^<]*</title>'   # -> Manage Account
curl -sk -o /dev/null -w 'lg-front.js %{http_code}\n' https://<LIVE_HOST>/v2/assets/lg-front.js
```
**Pass:** all 13 routes `200`; title = **Manage Account**; `/v2/assets/lg-front.js` `200`;
header renders; no `500`s.

---

## FULL ROLLBACK (one shot — reverts serving to `~/projects`)
```bash
sudo cp /etc/nginx/snippets/strangler-membership.conf.bak-serveconsol-$TS /etc/nginx/snippets/strangler-membership.conf
sudo cp "$LIVE_VHOST.bak-serveconsol-$TS" "$LIVE_VHOST"
sudo nginx -t && sudo systemctl reload nginx && sudo systemctl reload php8.3-fpm
# /srv symlinks may stay (now unused) or: sudo rm /srv/membership-pages /srv/lg-layout-v2
```
`~/projects` still holds the dead repo (not removed), so rollback is byte-for-byte the pre-cutover
state (minus the serve-clone advance in Step 2, which is independent and harmless).

## dev2 rehearsal state (for reference — done 6/28)
Box-local on dev2 already in the consolidated state: `/srv/{membership-pages,lg-layout-v2}`
symlinks created; `strangler-membership.conf` deployed with `/srv` paths;
`dev2.loothgroup.com.conf` `/v2` alias = `/srv/lg-layout-v2/`; backups at
`*.bak-serveconsol-20260628-165506`. All 13 routes 200; rename proven through `/srv` (with FPM
reload). dev2 serve clone is pinned @`8e7b8f1` (true-preview lane) so dev2 shows the OLD title
until that lane un-pins to `main` — LIVE serve clone tracks `main`, so LIVE shows the new title.
