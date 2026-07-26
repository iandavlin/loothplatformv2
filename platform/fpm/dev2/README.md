# platform/fpm/dev2 — dev2's FPM posture (tracked, per-box)

Captured from the dev2 box 2026-07-26 (deploy-one-pull). The sibling
`platform/fpm/*.conf` files are the LIVE posture (smaller pm sizing, no dev env
vars) — same split as the vhosts: per-env values live in per-box tracked files,
secrets never do (there are none here).

Installed as symlinks by `platform/bin/install-config-symlinks.sh`:
  /etc/php/8.3/fpm/pool.d/<pool>.conf      -> platform/fpm/dev2/<pool>.conf
  /etc/php/8.3/fpm/conf.d/99-lg-tuning.ini -> platform/fpm/dev2/99-lg-tuning.ini

After a pull that changes these: `sudo systemctl reload php8.3-fpm` (lg-deploy
does this automatically). Box-local by design: www.conf (stock),
lg-billing-dev.conf (billing app deploys from its own tree).
