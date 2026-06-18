# archive-poc — Findings (Scope A + Scope B)

**Live:** https://dev.loothgroup.com/archive-poc/ (cookie-gated).
**Old archive (baseline):** https://dev.loothgroup.com/archive/.

---

## Scope A — Smoke-test index + grid view (2026-05-24, AM)

### Perf — cold load, cache cleared

| Metric | Old `/archive` | POC grid view | Ratio |
|---|---:|---:|---:|
| FCP | 6,888 ms | 140 ms | **49×** |
| DOMContentLoaded | 8,811 ms | 94 ms | **94×** |
| `load` event | 12,500 ms | 97 ms | **129×** |
| Total transfer | 7,129 KB | 41 KB | **173×** |
| HTTP requests | 199 | 8 | **25×** |
| Time to filter (tab click) | full page reload | 80 ms | — |
| API server time | n/a | 4–9 ms | — |

### What's in the index

2,044 items, 8 kinds: discussion (1,161), video (318), loothprint (167), misc (146), event (121), article (102), benefit (22), profile (7). 2,066 tags, 5,300 content↔tag rels, 1,808 persons. 7.0 MB SQLite. FTS5 with porter stemming — "hide glue" / "strat refret" / "Erlewine" / "truss rod" all return relevant posts.

### Scope A scorecard

5 of 6 plan criteria hit. The miss — "≥50% non-placeholder thumbs" — is environmental: the dev box never received the 2023 R2 uploads, so 99.95% of cards fall to placeholders. POC works; dev box just doesn't have the images. Scope B swapped per-kind placeholders for `picsum.photos/seed/lg-{id}` to make the demo not look broken.

---

## Scope B — Sync, SSR, discovery rows (2026-05-24, PM)

Three steps from Ian's brief:

1. **Picsum thumb fallback** (5 min) — `archive.js` now uses `https://picsum.photos/seed/lg-{id}/480/320` when the real thumb is missing. Dev-only; swap to per-kind placeholders for prod.
2. **save_post reindex** (2 hr) — index reflects live edits in <100 ms (verified — 2s "total" wall time in measurements is wp-cli boot, not sync latency).
3. **Discovery rows (Variant D)** with **SSR + JSON-LD + SEO** baked in.

### B2 — sync architecture

```
wp_save_post (etc) → mu-plugin → wp_remote_post (non-blocking, sslverify=off)
                                       ↓
                            https://127.0.0.1/archive-api/v0/_sync
                                       ↓
                       nginx: allow 127.0.0.1; deny all  (defense in depth)
                                       ↓
                          php-fpm:looth-dev pool (booted WP)
                                       ↓
                       archive_poc_index_post($db, $post_id)
                                       ↓
                              SQLite (single-row upsert)
```

Hooks wired: `save_post`, `edit_post`, `trashed_post`, `untrashed_post`, `deleted_post`, `bbp_new_topic`/`bbp_edit_topic`, `bbp_new_reply`/`bbp_edit_reply`. The indexer resolves a `reply` → its parent `topic` and reindexes the topic; replies aren't surfaceable on their own.

End-to-end verified: edit a post in wp-cli → SQLite row reflects within the first 100ms poll. Force-delete → row removed. New post → indexed.

### B3 — discovery + SSR

**Page architecture:**
- `web/index.php` is the SSR entry point. Renders 7 rows server-side into the initial HTML. No JS-blocking content on first paint.
- `archive.js` only handles **hydration** — mode flip between discovery rows (`body.view-discover`) and grid (`body.view-grid`), URL state, infinite-load. The initial `/search` call is suppressed when SSR'd rows are present.
- `archive.css` adds the rail/rcard styles + body-class-driven mode toggle.

**Row config externalized to `rows.json`:**

```json
{
  "rows": [
    { "id": "featured-this-week", "layout": "hero", "query": { "max_age_days": 7, ... }, "fallback_when_empty": {...} },
    { "id": "new-this-week",      "layout": "rail", "query": { "max_age_days": 14, ... } },
    { "id": "most-liked",         "layout": "rail", "query": { "sort": "liked", "min_likes": 1 } },
    { "id": "latest-videos",      "layout": "rail", "query": { "kind": "video" } },
    { "id": "latest-loothprints", "layout": "rail", "query": { "kind": "loothprint" } },
    { "id": "tag-row-1",          "type": "tag-random", "seed": "weekly", "slot": 0, "candidate_pool": "top-20-by-count", "exclude": [...] },
    { "id": "tag-row-2",          "type": "tag-random", "seed": "weekly", "slot": 1, "candidate_pool": "top-20-by-count", "exclude": [...] }
  ]
}
```

`audience: members|public|both` exists on each row as **structural insurance** for the public-mode work in scope C — v0 just renders all rows.

**Weekly seed:** `floor(time() / 604800)` deterministically picks 2 tags from the top-20 pool. Same week = same tags for everyone (cacheable). Fisher-Yates with `mt_srand($seed)` keeps it reproducible. Currently picks `#CAD/CAM` and `#Repair Tool`; next week picks `Electronics Repair` and `Shop Organization`; week after `Tool Mod` and `Electric Builds`. Pool excludes kind-overlap (`video`, `loothprints`) and structural (`legacy-post`, `all-videos`, `loothing`) tags.

**SEO:**
- `<title>`, meta description, `<link rel="canonical">`, `<meta robots>`
- Open Graph (type/title/description/url/image/site_name) — hero card's thumb as default image
- Twitter Card (`summary_large_image` + title/desc/image)
- One `<h1>` (page), `<h2>` per row, `<h3>` per card

**JSON-LD per row:** Schema.org `ItemList` wrapping per-item entries. Kind → @type map: `article→BlogPosting`, `video→VideoObject`, `loothprint→HowTo` (if has_download) else `CreativeWork`, `event→Event`, `discussion→DiscussionForumPosting`, `profile→ProfilePage`, `benefit→Offer`, `misc→CreativeWork`. Per-item fields: `name`, `url`, `description`, `image`, `author` (Person), `datePublished`, `interactionStatistic` (likes). **`isAccessibleForFree: false`** on every gated card (tier ≠ public), plus a `hasPart` selector pointing at the gated DOM — Google's blessed flexible-sampling signal.

**Caveat on tier:** every row in the live index resolves to `tier='public'` because the source has no `_post_tier` postmeta. The JSON-LD mechanism works (verified by flipping one row to `pro` and re-rendering — `isAccessibleForFree: false` + `hasPart` appeared exactly where expected). Real Pro/Lite gating won't fire until tier resolution is wired to lg-layout-v2's `gated_tier` or a `_post_tier` postmeta convention lands. Logged as a Scope C item.

### B3 perf — SSR vs old archive

Cold load, cache cleared, viewport 1280×900:

| Metric | Old `/archive` | POC discovery (SSR + 7 rows × ~10 cards) |
|---|---:|---:|
| FCP | 6,408 ms | 628 ms |
| DOMContentLoaded | 8,040 ms | 554 ms |
| `load` event | 9,607 ms | 595 ms |
| Total transfer | 6,933 KB | 35.4 KB |
| HTTP requests | 196 | 36 |

FCP went 140 ms → 628 ms vs Scope A's grid because the discovery page fetches 28 picsum thumbs across 7 rows (vs 8 thumbs in the grid view). Server-side render time is ~10ms per row, ~70ms total for the whole page. Still an order of magnitude faster than the old archive on every metric.

Mode flip cost:
- Discover → grid (typing "erlewine"): **321 ms** (dominated by /search API round-trip + 24 card renders)
- Grid → discover (Esc): **51 ms** (pure CSS class swap; SSR'd rows still in the DOM)

### What worked

- **Page handler in the same PHP stack as the API** meant SSR was a 200-line `index.php`. No SSR framework, no hydration mismatch — first render is just HTML.
- **rows.json + `_rowlib.php`** lets the page composition change without touching code. Drop in a row, change a query, swap a seed mode — no PHP edit, no JS edit.
- **Weekly seed** gives the page a fresh feel without manual editing. Right knob for v0.
- **`Page handler` runs in the `archive-poc` FPM pool** — read-only, isolated. WP is only invoked for the `_sync` endpoint (looth-dev pool).
- **mu-plugin is 50 lines** — `wp_remote_post(non-blocking, sslverify=off)` to the loopback endpoint. WP stays a publisher of events; archive-poc owns the index.

### What broke / decisions worth flagging

1. **`$action` clash with WP globals.** `require '/var/www/dev/wp-load.php'` clobbered a script-local `$action` variable, silently turning every `delete` into `upsert`. Renamed to `$sync_action`. Future endpoints booting WP should use a `_` prefix or local-only scope. Manifested as `Undefined variable $action` warnings + stale rows that survived deletes. Bonus debt: one orphan test row (id 69435) had to be purged via `/_sync` after the fix.

2. **`return 404` in nginx outer prefix locations preempts nested location selection.** This bit us twice — once in Scope A on the API location, again briefly when the `_sync` location was added. The pattern that works: `alias` + nested `location ~ pattern` with no outer `return`. Worth memo-ing in the dev-server CLAUDE.md.

3. **Permission daisy chain** to make a dedicated FPM pool work:
   - `chmod o+x /home/ubuntu /home/ubuntu/projects` (so `archive-poc` user can traverse)
   - `usermod -aG www-data looth-dev` (so the looth-dev pool can write `index.sqlite` via group perms)
   - `chgrp www-data /home/ubuntu/projects/archive-poc/index.sqlite` (mode 664)

4. **Discovery page weight isn't byte-for-byte smaller than the grid view** — it's actually 5KB heavier in transfer because of more thumbnail HTTP requests. The dominant cost is image transfers, not HTML/CSS/JS. When real thumbs come back (R2 reattached), preloading + responsive `srcset` becomes worth doing.

5. **Excluded tags from the pool** (`repair-and-restoration`, `loothprints`, `video`, `loothing`, `all-videos`, `legacy-post`) overlap with kind facets or are structural. The kind-overlap exclusion is a manual list now; could be auto-derived from the CPT→kind map but isn't worth it until/unless the tag schema gets a `kind` column (Scope B+ idea from the redesign doc).

---

## Files

```
/home/ubuntu/projects/archive-poc/
├── README.md
├── FINDINGS.md (this file)
├── schema.sql
├── rows.json                      ← row composition config
├── nginx-snippet.conf
├── index.sqlite                   ← 7.0 MB, gitignored
│
├── bin/
│   ├── backfill.php               ← bulk WP → SQLite walker
│   ├── indexer.php                ← shared per-post normalization
│   ├── verify-thumbs.php
│   ├── make-placeholders.php
│   └── local-router.php           ← php -S router for offline testing
│
├── api/v0/
│   ├── _bootstrap.php             ← shared API setup
│   ├── _rowlib.php                ← row executor for SSR
│   ├── _sync.php                  ← loopback-only single-post reindex
│   ├── search.php
│   └── item.php
│
└── web/
    ├── index.php                  ← SSR landing (discover + grid in one doc)
    ├── archive.css
    ├── archive.js                 ← hydration + mode flip
    └── placeholders/              ← kind PNGs (unused in dev; picsum fallback active)
```

### System changes outside the project tree

- `/etc/php/8.3/fpm/pool.d/archive-poc.conf` — dedicated FPM pool, system user `archive-poc` (UID 116)
- `/etc/nginx/sites-available/dev.loothgroup.com.conf` — snippet for `/archive-poc/` (SSR) + `/archive-api/v0/{search,item,_sync}` (read API + loopback sync). Backups at `.bak.20260524-165923` and `.bak.20260524-172011` and after.
- `/var/www/dev/wp-content/mu-plugins/archive-poc-sync.php` — 50-line WP hook bridge
- `usermod -aG www-data looth-dev` — sqlite write access via group
- `chmod o+x /home/ubuntu /home/ubuntu/projects` — pool user traversal
- `apt install php8.3-sqlite3` — PDO sqlite driver

---

## Next-up queue (out of scope for this work; logged for handoff)

1. **Forum-style discussion card** — 1–2 hr additive. Use Variant E mockup pattern: no hero, avatar OP + question + first 3 lines + reply/participant counts + Resolved/Active badge. Drop into a new `discussion` row when re-enabled.
2. **Public-mode + `/me` endpoint + sign-up CTAs** — scope C. Public renderer that swaps gated card bodies for lock + sign-up CTA, plus the `/me/bookmarks` and `/me/history` endpoints that recommend rows depend on.
3. **Editorial dash for rows.json** — scope C. Web UI for pinning rows, hand-curating tag rows, ordering, toggling audience. Removes the "ssh in and edit JSON" loop.
4. **lg-layout-v2 renderer portability refactor** — scope C/D. The strategic enabler for moving post bodies out of WP. Once the v2 renderer is a portable TS module, the archive-poc page can render `body_md` from index data directly without round-tripping to WP.

Also flagged for whenever:

- **Tier resolution table** — `_post_tier` doesn't exist on most content; tier is structurally `public` for 2,044/2,044 rows in the current index. JSON-LD gating mechanism works, but won't actually mark anything as gated until source data lands.
- **Move off `/home/ubuntu/`** to `/srv/archive-poc-dev/` to match `/srv/lg-stripe-billing` and `/srv/thumb-app` conventions.
