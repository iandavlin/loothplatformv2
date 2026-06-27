# Runbook — preview-a faithful preview (true-preview lane)

Make `preview-a.dev2.loothgroup.com` render the slot's `integration` branch for EVERY dynamic
surface (front `/`, `/hub/`, `/directory/members`, `/u/`, `/p/`, `/events/`, `/profile-api/v0/whoami`,
membership), not just the hub. Box: dev2 only. Main dev2 vhost + shared `strangler-*.conf` untouched.

## ⛔ REQUIRED PRE-MERGE / PRE-DEPLOY STEP — seed profile-app `vendor/`

`profile-app/vendor/` is **gitignored**. The de-pin (`LG_PROFILE_APP_APP_ROOT = realpath(__DIR__)`)
makes profile-app load its `src/*` classes — and `vendor/autoload.php` — from the SAME tree that
served the view. So **any tree that serves profile-app must contain `profile-app/vendor/`** or
`/profile-api/v0/whoami` fatals (HTTP 500), breaking the header / avatars / DMs site-wide for
logged-in users.

Before the de-pin is served from a tree (serve clone on dev2, or the live serve tree at cutover),
seed vendor in that tree:

```
# proven relocatable (composer is __DIR__-relative, no app PSR-4):
cp -a /home/ubuntu/projects/profile-app/vendor  <SERVE_TREE>/profile-app/vendor
#   dev2 serve clone: <SERVE_TREE> = /home/ubuntu/loothplatformv2-serve
# OR, if composer is available in that env:
cd <SERVE_TREE>/profile-app && composer install --no-dev --optimize-autoloader
```

The deploy must place `vendor/` BEFORE the de-pinned `config.php` goes live (i.e. before
`systemctl reload php8.3-fpm`). Sequence: pull → seed vendor → reload.

### Proof captured 2026-06-27 (dev2, against the real serve clone + a throwaway de-pin)
- Seeded `~/loothplatformv2-serve/profile-app/vendor` (1.4M). Main `/srv` `/whoami` stayed **200**
  (config still pinned → seed dormant). APP_ROOT still `~/projects/profile-app`.
- Throwaway = `cp -a` of the (now vendor-seeded) serve clone + the merge-candidate de-pinned config:
  - `APP_ROOT = <throwaway serve tree>` (realpath(__DIR__))
  - `Looth\ProfileApp\Identity` loaded from `<throwaway>/src/Identity.php` (the serve tree, NOT ~/projects)
  - `whoami` → `{"authenticated":false,"tier":"public"}` (loads vendor + all src classes, no fatal)

## Routing (already deployed on dev2 preview-a)

Repo-tracked snippets `platform/nginx/strangler-{profile-app,archive-poc,events,membership,webroot}-preview-a.conf`,
deployed to `/etc/nginx/snippets/`. Each repoints `SCRIPT_FILENAME`/`alias` `/srv/<app>` (or
`~/projects/membership-pages`) → `~/preview-slots/slot-a/<app>` on the SAME existing FPM pool
(no new pools; bb-mirror keeps its `bb-preview-a` pool). The webroot snippet serves the `webroot/`
overlay slot-first and SUBSUMES the vhost generic static block (regex precedence is source order —
the vhost static block sat before the includes and won, so it had to be replaced, not just added).

Include swap is in the preview-a vhost ONLY. Backups: `*.bak-true-preview-*`. `nginx -t` before
every reload.

Slot also needs `profile-app/vendor` (same `cp -a` as above) — already seeded.

## Deploy / preview a branch on the slot
```
cd ~/preview-slots/slot-a
git fetch origin && git checkout -B integration origin/main
git merge --no-edit origin/<lane>            # per lane; conflict -> record + abort
sudo systemctl reload php8.3-fpm             # opcache; pools are ondemand
```

## Test (curl the ORIGIN via loopback — AWS does NOT hairpin the EIP)
```
PA="--resolve preview-a.dev2.loothgroup.com:443:127.0.0.1"
MN="--resolve dev2.loothgroup.com:443:127.0.0.1"
curl -s $PA https://preview-a.dev2.loothgroup.com/profile-api/v0/whoami   # valid JSON, not 500
# slot-only proof: add an X-Slot-Marker header after declare(strict_types=1) in a slot entrypoint,
# or a unique file in slot/webroot/, then curl PA (present) vs MN (absent).
```

## Out of scope (shared docroot — not branch-isolatable)
- WP core/theme under `/var/www/dev`.
- Shared chrome `/srv/lg-shared/site-header.php` (lg-shell owns ONE header).
- archive-poc `LG_ARCHIVE_POC_APP_ROOT` stays pinned BY DESIGN (data: index.sqlite/config.json);
  its code + front-feed rows.json load via `__DIR__` → render from the slot.
