# BB-mirror — Session Handoff (2026-05-28, feed-images-header)

## What this project is

Read-side strangler for BB/bbPress forum threads. Reads from postgres mirror
at native speed; writes round-trip through BB REST. Mu-plugin syncs WP→pg
in real time; systemd timer reconciles every 10 min.

Scope contract: [STRANGLER-COORDINATION.md §3f](../docs/STRANGLER-COORDINATION.md).
Storage: [§3i](../docs/STRANGLER-COORDINATION.md).

## What landed this session (feed-images-header)

Two features added on top of the feed-ui-v2 session.

| File | Change |
|---|---|
| **updated** `web/forums/_feed.php` | LATERAL join for card images; header image query; forum-header block |
| **updated** `web/forums.css` | `.forum-header` block appended (background image, title, label) |

### Feature 1: First image in feed cards

Added `LEFT JOIN LATERAL` on `forums.attachment` to pull the first attachment
image per topic (ordered by `id ASC`). Card image uses
`COALESCE(t.featured_image_url, first_img.url)` — so native WP featured image
wins if ever populated; attachment image is the fallback. Stored as `card_image`
alias in SELECT.

Verified: 54 `feed-card__thumb` elements on site-wide feed (topics with
attachments show images; topics without don't show broken placeholder).

### Feature 2: Forum header with image

Header block rendered above sort bar and feed cards:

```html
<header class="forum-header forum-header--has-image">
  <div class="forum-header__bg" style="background-image: url('...')"></div>
  <div class="forum-header__body">
    <h1 class="forum-header__title">Acoustic Repair</h1>
    <span class="forum-header__label">Activity</span>
  </div>
</header>
```

- Title: forum name when scoped, "All Forums" for site-wide
- Label: always "Activity"
- Image: most-recent attachment in scope (scoped) or site-wide
- No image: `forum-header` without `--has-image` modifier, no bg div
- CSS: `forum-header__bg` absolute inset, opacity 0.28, mask-gradient darkening
  toward bottom; body relative z-index 1

Header image query:
- Scoped: `JOIN topic ON forum_id = ANY($scope_ids)` ORDER BY `a.id DESC LIMIT 1`
- Site-wide: `SELECT url FROM forums.attachment ORDER BY id DESC LIMIT 1`

The scope_ids are resolved separately with a recursive CTE before the main
topic query — reuses same pattern as the topic query but as a pre-fetch.

## Verified checks (2026-05-28)

```
1     forum-header element on /forums-poc/                  ✓
54    feed-card__thumb elements on /forums-poc/             ✓ (topics with attachments)
bg    forum-header__bg with background-image on /acoustic/  ✓
      "Acoustic Repair" in forum-header__title on /acoustic/ ✓
1     feed-sort-bar present on /forums-poc/                 ✓
200   /forums-poc/general/stripped-out-trussrod/            ✓
50    /forums-poc/?q=guitar search results                  ✓
ok    /bb-mirror-api/v0/_sync                               ✓
```

## Gotchas discovered this session

1. **Attachment table schema confirmed**: columns are `id, parent_kind, parent_id,
   url, alt, mime, width, height, position, sync_at`. No `filename` or `wp_id`
   column. LATERAL join uses `parent_kind = 'topic' AND parent_id = t.id`.

2. **URL format**: attachment URLs are full absolute `https://dev.loothgroup.com/wp-content/uploads/bb_medias/...`
   — safe to use directly in `style="background-image: url(...)"`.

3. **grep -c 'forum-header' returns 5** on the feed page (CSS class names
   also match). Use `grep -c '<header class="forum-header'` to count HTML
   elements specifically — that returns 1 as expected.

4. **Scope resolution done in two passes**: header image query needs forum_ids
   as a PHP array to build the PG `ANY({...}::int[])` literal. So scope CTE
   runs first as a small pre-query, then the main topic query runs its own
   recursive CTE independently. Slight redundancy but clean and fast (55 forums max).

5. **Prior gotchas still apply**: nginx alias drops query string, dual `acoustic`
   slug, file ownership pattern, `forums.person` has no `wp_user_id`.

## Postgres infrastructure (unchanged)

- DB `looth`, schema `forums`, role `bb-mirror`
- 9 tables; 55 forums, 1128 topics, 4405 replies, 465 persons, 20 bp_groups,
  1549 attachments, 1 forum_read_state (test row)
- Reconcile cron every 10 min via `bb-mirror-reconcile.timer`
- Top forums by attachment count: acoustic (152), electric-2 (102),
  finish (84), quick-questions (84), general (65)

## Still queued

1. **Group-member-aware private visibility** — show private group forums to
   members. Needs `/whoami` + group-membership table (profile-app post-cutover).
2. **Reply-form group gating** — "Join SoCal to post here" CTA. Same dep.
3. **§4.3 shared header swap** — placeholder still in `_chrome.php`. Waits
   on archive-poc shipping `/srv/lg-shared/site-header.php`.
4. **Forum-list unread chip aggregate** — count of unread topics per forum in
   nav. Needs viewer state from `/whoami`.
5. **Retire unreferenced dashboard/topic-list** — `web/forums/index.php` and
   `web/forums/_topic-list.php` are on disk but never routed. Safe to delete
   once feed UI is confirmed stable on live.
6. **`featured_image_url` sync** — upstream sync doesn't populate this column yet.
   When it does, `COALESCE(t.featured_image_url, first_img.url)` means the WP
   featured image will automatically win over the attachment fallback.

## How to test

```bash
TOK=$(sudo grep -E 'set \$loothdev_token' \
  /etc/nginx/sites-available/dev.loothgroup.com.conf | \
  head -1 | grep -oE '"[^"]+"' | tr -d '"')
curl -s "https://dev.loothgroup.com/claim?t=$TOK" -c /tmp/bbjar -o /dev/null

# Header element (use <header tag, not class name grep)
curl -s -b /tmp/bbjar https://dev.loothgroup.com/forums-poc/ | grep -c '<header class="forum-header'
# expect: 1

# Card images (at least some topics have attachments)
curl -s -b /tmp/bbjar https://dev.loothgroup.com/forums-poc/ | grep -c 'feed-card__thumb'
# expect: >0

# Scoped header: forum name + bg image
curl -s -b /tmp/bbjar https://dev.loothgroup.com/forums-poc/acoustic/ | grep -A3 'forum-header'
# expect: forum-header--has-image, bg div with background-image url, "Acoustic Repair" title

# Sort bar: expect 1
curl -s -b /tmp/bbjar https://dev.loothgroup.com/forums-poc/ | grep -c 'feed-sort-bar'

# Single topic: expect 200
curl -s -b /tmp/bbjar -o /dev/null -w "%{http_code}\n" \
  https://dev.loothgroup.com/forums-poc/general/stripped-out-trussrod/

# Search: expect 50
curl -s -b /tmp/bbjar 'https://dev.loothgroup.com/forums-poc/?q=guitar' | grep -c 'search-result__title'

# Sync: expect {"ok":true,...}
curl -sk -X POST https://127.0.0.1/bb-mirror-api/v0/_sync \
  -H 'Host: dev.loothgroup.com' -H 'X-BB-Mirror-Sync: 1' \
  -H 'Content-Type: application/json' \
  -d '{"kind":"topic","id":68963,"action":"upsert"}'
```

## Pointers

- Coordination doc: [/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md](../docs/STRANGLER-COORDINATION.md)
- Prior handoff: `handoffs/2026-05-28-feed-ui-v2.md`
