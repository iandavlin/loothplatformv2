# What changes on LIVE when this batch deploys — measured beforehand

Lane: hub-seo-landing. Merged to main and serving on dev2; **live gets it on
Ian's next `lg-deploy`**. Everything below was measured on live, read-only,
against the origin (`-k --resolve …:127.0.0.1` — a plain public curl is
Cloudflare-challenged into a 403 that reads as an outage).

Written so the deploy has a known expected delta rather than a surprise, and so
anything that looks wrong afterwards can be checked against what was true before.

## ⚠️ Two steps, not one

`git pull` alone is **not** enough. Two of these changes live in
`platform/nginx/strangler-*.conf`, which is symlinked into the serving checkout —
so the files arrive with the pull but nginx keeps serving the old config until it
is **reloaded**. On live that reload is Ian's.

| change | arrives with |
|---|---|
| canonical on topic landings + category pages | `git pull` |
| the discussion landing itself | `git pull` |
| `/all-forums-all-topics/reply/<id>/` per-URL 301 | pull **+ nginx reload** |
| `/shop-organisation/`, `/featured-content/`, `/merch/` 301s | pull **+ nginx reload** |

## The delta, measured on live BEFORE the deploy

| URL | live today | after deploy | note |
|---|---|---|---|
| `/hub/<forum>/<topic>/` | no canonical | self-referencing canonical | already renders the hub+modal |
| `/hub/<category>/` | no canonical | self-referencing canonical, variants fold into the bare URL | rail rebuild is NOT in this batch |
| `/all-forums-all-topics/reply/<id>/` | `301 → /hub/` (many-to-one soft 404) | `301 →` its own topic permalink | |
| `/shop-organisation/` | **404** | `301 → /hub/shop-organisation/` | Google lists it today |
| `/featured-content/` | 200, indexable | `301 → /hub/` | Ian's ruling |
| `/merch/` | 200, indexable | `301 → https://loothtool.com/` | Ian's ruling |
| `/shop/` | 200 | **unchanged** | alive — must stay 200 |

## Every redirect destination was checked ON LIVE, not inferred from dev2

This is the check worth doing before a redirect ships, because a 301 to a URL
that does not exist is strictly worse than the 404 it replaced:

- `/hub/shop-organisation/` → **200** on live (the category exists there too)
- `/hub/` → **200**
- `https://loothtool.com/` → **200**, fetched from **live's own network**, not
  just from dev2. Note the apex only: `https://www.loothtool.com/` answers **522**
  (dead Cloudflare origin), which is why the conf uses the apex and
  `stale-page-redirect-gate` asserts the destination responds.

## After the deploy, check these

```
bash tools/gates/run-all.sh          # on dev2 — the suite still owns the contract
```
and on live, from the box, against the origin:

- `/shop/` still **200** and not redirected — it is alive and linked from the
  homepage, front page, hub and events;
- `/merch/` 301s to the **apex**, and that apex answers;
- one sitemapped discussion URL still 200 **and** now carries its canonical;
- `/all-forums-all-topics/reply/<id>/` reaches a topic permalink rather than
  bare `/hub/`.

## Not in this batch, on purpose

The `/hub/<category>/` **rail rebuild** — the legacy left category tree is still
there after this deploy. It is held pending Ian's ruling on whether a category
page shows content items alongside discussions
(`docs/HUB-CATEGORY-PAGES-PLAN.md`, and the pictures at
`/footer-mockups/hub-category-options/`). The canonical half ships now because it
is not member-visible; the rail is.

The **sitemap** is untouched, per Ian's 2026-08-11 ruling that the list is final.
