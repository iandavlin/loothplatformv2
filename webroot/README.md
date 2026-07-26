# webroot/ — loothgroup.com served static layer (canonical, git-managed)

The static/glue layer that lives at the **loothgroup web root** (`/var/www/dev` on dev,
the site docroot on the new box) — buck's overlay JS, the PWA shell, a few PHP endpoints,
and their assets. Historically this lived **only on the live filesystem** (no git), with the
live copy as the source of truth and stale snapshots scattered in repos. This directory makes
it git-managed: **edit here, deploy with `deploy.sh`** — stop editing the webroot in place.

Captured from LIVE dev `/var/www/dev` on **2026-06-14** (the only authoritative copy; the
old `live-webroot-capture/2026-06-06/` snapshot was ~thousands of lines stale).

## What's here
- **JS overlay** — `pwa.js` (the injector/loader), `app-mobile-fixes.js`, `bottom-nav.js`,
  `app-settings.js`, `hub-*.js`, `mobile-hub.js`, `directory-*.js`, `events-*.js`,
  `sponsor-*.js`, the `*-sheet.js` mobile sheets, `guitardle-teaser.js`, `gdle-side-art.js`,
  `push.js`, `sw.js`, `loothalong.js`.
- **PHP endpoints** — `loothalong.php`, `saved-posts.php`, `push-subscribe.php` (run on the
  loothgroup FPM pool).
- **CSS** — `mobile-hub.css`. **PWA** — `manifest.json`, `icons/`. **Other** — `robots.txt`,
  `push/`, `sponsors-deck/`.

## Deliberately NOT here
- **The shop/Loothtool-modal apparatus** — `shop-bubble.js`, the `/shop/` page, the feed JSON,
  `shop-img/`, and buck's feed cron/scripts. Decision (Ian 2026-06-14): loothtool runs on its
  own box → the nav item is a plain **link-out** to loothtool.com. The apparatus is stashed for
  revival at [`../fast-follow/loothtool-shop/`](../fast-follow/loothtool-shop/). `pwa.js` here
  has the `shop-bubble.js` injector removed (the link-out change, version-controlled).
- **`img.php`** (the resizer) — owned by looth-dev and already git-homed in `bb-mirror/web/img.php`;
  deploy it from there, not here.
- **Generated/regenerable** — `shop-feed.json`, `shop-vendors.json`, `*.bak`, logs (see `.gitignore`).
- **Cruft** — `catapult-*`, `cdp-launcher`, WP core/content.

## Deploy (deploy-one-pull 2026-07-26: DEPLOY = GIT PULL)
The docroot holds **symlinks** into this directory, installed once per box by
```bash
sudo ./install-symlinks.sh [WEBROOT]    # default: /var/www/dev; md5-gated, idempotent
```
so a `git pull` in the serving checkout IS the deploy — no copy step exists anymore
(the old rsync `deploy.sh` is retired). Cache-busting is automatic: `pwa-loader.php`
serves `/pwa.js` with a `window.LG_V` filemtime map and pwa.js builds every layer URL
as `?v=<filemtime>` — never hand-bump a version again. New files: commit here, run
`install-symlinks.sh --new-only` (lg-deploy does it for you).

## Lane note
This was **buck's** lane (he authored these files, edited live). As of 2026-06-14 the webroot lane
is being taken into git-managed flow (Ian); buck looped in. Going forward: PR/edit here → `deploy.sh`,
not live edits.
