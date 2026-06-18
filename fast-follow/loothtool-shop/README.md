# Fast-follow: Loothtool shop bridge (STASHED — not deployed)

**Status:** parked 2026-06-14 (Ian). The loothgroup→loothtool inline shop was **removed from
the cut**: loothtool runs on its own box now, so the "Loothtool" nav item is a plain link-out
to loothtool.com. This dir preserves the apparatus so it can be **revived as a fast-follow**
after the cut if we want inline shop browsing back.

## What this was
A mini-storefront that surfaced loothtool.com products *inside* loothgroup:
- **`shop-bubble.js`** — desktop pop-up modal (header "Loothtool" tab opened it); mobile routed
  to the `/shop/` page. Brand-skinned (teal/mint, Barlow+Inter). Read same-origin `/shop-feed.json`
  + `/shop-vendors.json`; every product/CTA opened loothtool.com in a new tab.
- **`shop-page-index.html`** — the mobile `/shop/` page (was served at webroot `shop/index.html`).
- **`refresh-shop-feed.sh`** — buck's hourly cron (`07 * * * *`, see `crontab.txt`). Built
  `shop-feed.json` by running **`wp eval-file` against the LOCAL `/var/www/dev.loothtool`** WP
  install (dev's loothtool was gated, so a local read sidestepped the 403), then rewrote product
  URLs `dev.loothtool.com` → `loothtool.com`.
- **`mirror-vendor-logos.py`** — built `shop-vendors.json` from the **public** Dokan stores API
  (`https://loothtool.com/wp-json/dokan/v1/stores`) + mirrored logos into `shop-img/`.

## Why it can't just be copied to the new box
The modal *runtime* is box-agnostic (it reads same-origin JSON + links to public loothtool.com).
But the **feed generator depends on loothtool's WP being local** (`wp eval-file /var/www/dev.loothtool`).
On a box without the loothtool install, that cron breaks → the feed goes empty.

## Revival plan (the actual work)
1. **Repoint the feed generator at loothtool.com's public REST API** instead of local `wp eval-file`
   — e.g. the Dokan/WooCommerce products endpoint (the vendor-logos script already proves the
   public API works). This is *easier* now that loothtool.com is a real public box (no gate to dodge —
   `refresh-shop-feed.sh`'s own TODO anticipated exactly this).
2. Run the cron on whichever box serves loothgroup; output `shop-feed.json` / `shop-vendors.json`
   to the loothgroup webroot (same-origin).
3. Re-add the injector to `webroot/pwa.js`: `inject('looth-shop-js', '/shop-bubble.js?v=NN');`
   and deploy `shop-bubble.js` + the `/shop/` page + `shop-img/` (re-mirrored, not committed).
4. Decide desktop-modal vs mobile-page vs both.

## Not stashed (regenerable)
`shop-feed.json`, `shop-vendors.json`, and `shop-img/` (1.5M of mirrored product images) — all
rebuilt by the two scripts above. No need to version them.
