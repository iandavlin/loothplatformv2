# BB-mirror — Session Handoff (2026-05-28, activity-feed IA)

## What this project is

Read-side strangler for BB/bbPress forum threads. Reads from postgres mirror
at native speed; writes round-trip through BB REST. Mu-plugin syncs WP→pg
in real time; systemd timer reconciles every 10 min.

Scope contract: [STRANGLER-COORDINATION.md §3f](../docs/STRANGLER-COORDINATION.md).
Storage: [§3i](../docs/STRANGLER-COORDINATION.md).

## What landed this session

### Activity feed IA — replaces the forum dashboard at `/forums-poc/`

| File | Change |
|---|---|
| **new** `web/forums/_feed.php` | Activity feed template — site-wide or scoped to a forum + descendants |
| **updated** `web/index.php` | 0-seg → `_feed.php`; 1-seg → `_feed.php` with `forum_slug`; 2-seg → unchanged single-topic; old `index.php` + `_topic-list.php` unreferenced but on disk |
| **updated** `web/_chrome.php` | `bb_mirror_left_nav()` + two-pane layout structure (corner-hamburger, nav aside, content main) |
| **updated** `web/forums.css` | New: `.corner-hamburger`, `.bb-layout`, `.bb-layout__nav`, `.nav-tree*`, `.feed-card*`, `.feed-more` — all additions, no tokens changed |
| **updated** `web/forums.js` | Hamburger toggle (desktop: `body.nav-closed`; mobile: `body.nav-open`); feed reply-stack expand; existing reply-form + mark-seen + unread code intact |

### What the feed does

- **Site-wide** (`/forums-poc/`): union of topic-starts + replies across all
  public forums, sorted `created_at DESC`, collapsed into ≤50 cards.
- **Scoped** (`/forums-poc/<slug>/`): same but limited to that forum's
  `id` + all descendant subforums via `WITH RECURSIVE scope AS (...)`.
- **Collapse rule**: all replies to one topic fold into a single
  `feed-card--reply-stack` card (most-recent reply snippet always visible;
  older replies hidden behind an expand button). Topic-starts are always
  their own `feed-card--topic-start` card.
- **Load older**: "Load older activity" link passes `?offset=<n>` on the
  raw event query (300-event pages) for v0 pagination.

### Left nav

- Rendered by `bb_mirror_left_nav()` in `_chrome.php` (DB query on every
  page load — small table, fast).
- Buckets: General · Local Looths · each category container (with subfolded
  subforums).
- Active forum highlighted via `$_SERVER['REQUEST_URI']` slug match.
- Parent labels (categories) are clickable → `/forums-poc/<slug>/` which
  scopes the feed to that container + descendants via recursive CTE.
- Desktop: sticky 240px sidebar; mobile/tablet: off-canvas drawer.

### Triangle hamburger

- Fixed `clip-path: polygon(0 0, 100% 0, 0 100%)` 76px square → warm
  mahogany triangle in top-left.
- Desktop click: toggles `body.nav-closed` → nav hides/shows.
- Mobile click: toggles `body.nav-open` → drawer slides in; `.nav-overlay`
  backdrop closes on click-outside.

## Verified routes (2026-05-28)

```
50 cards   /forums-poc/              (site-wide: 24 topic-start + 26 reply-stack)
50 cards   /forums-poc/acoustic/     (Acoustic Repair scoped)
50 cards   /forums-poc/repair-and-restoration/   (container + 9 subforums via CTE)
200        /forums-poc/general/stripped-out-trussrod/   (single-topic, unchanged)
50 hits    /forums-poc/?q=guitar     (search, unchanged)
{"ok":true} /bb-mirror-api/v0/_sync  (sync, unchanged)
46 nav items visible in left nav
corner-hamburger present on every page
```

## Gotchas discovered this session

1. **Two forums share slug `acoustic`**: `acoustic / Acoustic Repair` and
   `acoustic / Acoustic Builds` both have `slug = 'acoustic'` in pg. The
   nav active-highlight correctly highlights both (slug match). The scoped
   feed hits only whichever row the `LIMIT 1` returns. This is a data
   issue in upstream BB — `bbp_get_forum_by_slug` de-dupes there.
   Don't try to fix in bb-mirror; let upstream resolve.

2. **`grep -c 'feed-card'` overcounts**: the string appears in class
   names inside each card (`.feed-card__reply`, `.feed-card__meta-top`,
   etc.). Use `grep -c '<article class="feed-card'` for an accurate card
   count.

3. **`load older` offset is on raw events, not collapsed cards**: offset=300
   skips 300 raw events (topics + replies) before collapsing. The second
   page may yield fewer than 50 cards if many recent events were to the same
   topics. Acceptable for v0; a card-cursor approach would fix it for v1.

4. **The existing nginx snippet is untouched**: no new routes needed —
   the 1-segment URLs already matched the `try_files` fallback to `index.php`.

5. **File ownership**: `/home/ubuntu/projects/bb-mirror` is `bb-mirror:loothdevs`
   mode 2775. Deploy via `sudo cp /tmp/file <target> && sudo chgrp loothdevs <target> && sudo chmod 664 <target>`.

6. **Prior gotchas still apply** (nginx alias drops `$query_string`, etc.) —
   see `handoffs/2026-05-28-burn-queue-complete.md` §Pitfalls.

## Postgres infrastructure (unchanged)

- DB `looth`, schema `forums`, role `bb-mirror`
- 9 tables; 55 forums, 1128 topics, 4405 replies, 465 persons, 20 bp_groups,
  1549 attachments, 1 forum_read_state (test row)
- Reconcile cron every 10 min via `bb-mirror-reconcile.timer`

## Still queued (item 6 + deferred)

1. **Group-member-aware private visibility** — show private group forums to
   members. Needs `/whoami` + group-membership table (profile-app post-cutover).
2. **Reply-form group gating** — "Join SoCal to post here" CTA. Same dep.
3. **§4.3 shared header swap** — placeholder still in `_chrome.php`. Waits
   on archive-poc shipping `/srv/lg-shared/site-header.php`.
4. **Forum-list unread chip aggregate** — count of unread topics per forum in
   nav. Needs viewer state from `/whoami`.
5. **Retire unreferenced dashboard/topic-list** — `web/forums/index.php` and
   `web/forums/_topic-list.php` are on disk but never routed. Safe to rename
   to `*.bak` or delete once feed UI is confirmed stable on live.

## How to test

```bash
TOK=$(sudo grep -E 'set \$loothdev_token' \
  /etc/nginx/sites-available/dev.loothgroup.com.conf | \
  head -1 | grep -oE '"[^"]+"' | tr -d '"')
curl -s "https://dev.loothgroup.com/claim?t=$TOK" -c /tmp/bbjar -o /dev/null

# Site-wide feed: expect 50 cards
curl -s -b /tmp/bbjar https://dev.loothgroup.com/forums-poc/ | grep -c '<article class="feed-card'

# Scoped feed: expect 50 cards
curl -s -b /tmp/bbjar https://dev.loothgroup.com/forums-poc/acoustic/ | grep -c '<article class="feed-card'

# Repair+Restoration container (recursive CTE)
curl -s -b /tmp/bbjar https://dev.loothgroup.com/forums-poc/repair-and-restoration/ | grep -c '<article class="feed-card'

# Single topic
curl -s -b /tmp/bbjar -o /dev/null -w "%{http_code}\n" \
  https://dev.loothgroup.com/forums-poc/general/stripped-out-trussrod/

# Search
curl -s -b /tmp/bbjar 'https://dev.loothgroup.com/forums-poc/?q=guitar' | grep -c 'search-result__title'

# Sync
curl -sk -X POST https://127.0.0.1/bb-mirror-api/v0/_sync \
  -H 'Host: dev.loothgroup.com' -H 'X-BB-Mirror-Sync: 1' \
  -H 'Content-Type: application/json' \
  -d '{"kind":"topic","id":68963,"action":"upsert"}'
```

## Pointers

- Coordination doc: [/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md](../docs/STRANGLER-COORDINATION.md)
- Prior handoffs: [handoffs/](handoffs/) — previous is `2026-05-28-burn-queue-complete.md`

## Handoff rotation

When superseding this file, rename to `handoffs/YYYY-MM-DD[-suffix].md` and
write fresh per the project schema in [/home/ubuntu/projects/CLAUDE.md](../CLAUDE.md).
