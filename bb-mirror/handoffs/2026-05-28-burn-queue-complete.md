# BB-mirror — Session Handoff (2026-05-28, burn-queue complete)

## What this project is

Read-side strangler for BB/bbPress forum threads. Reads from postgres mirror
at native speed; writes round-trip through BB REST. Mu-plugin syncs WP→pg
in real time; systemd timer reconciles every 10 min.

Scope contract: [STRANGLER-COORDINATION.md §3f](../docs/STRANGLER-COORDINATION.md).
Storage: [§3i](../docs/STRANGLER-COORDINATION.md).
Burn-queue authorization: [reply-to-bb-mirror-burn-queue.md](../docs/reply-to-bb-mirror-burn-queue.md).

## Current state — all 5 burn-queue items shipped

| # | Item | Result |
|---|---|---|
| 1 | **Search** | `?q=` on any URL → `web/forums/_search.php`. tsvector + GIN, weights A/B/C. Chrome bar in `_chrome.php` (site-wide). `<b>...</b>` highlighting via `ts_headline`. 50-result cap. |
| 2 | **Sticky topics** | `_bbp_sticky_topics` (forum-side CSV) walked in backfill + materializer. 4 topics correctly flagged. |
| 3 | **Retire SQLite** | `config.php`, `bin/init-db.php`, `bin/backfill.php` slimmed to pg-only. `lib/materializers.php` cleaned. SQLite + WAL files removed. `schema.sql` removed. |
| 4 | **`forum_read_state` mark-seen** | Two new endpoints — `mark-seen` (POST, single topic) + `unread` (POST, batch). JS fires mark-seen on single-topic load, batch-fetches unread on topic-list load and adds `.topic--unread` class. |
| 5 | **Attachment harvest** | `bb_mirror_sync_attachments()` walks `bp_media_ids` postmeta + inline `<img>`. **1,549 attachment rows** across 858 parent posts. Image gallery rendered in single-topic template (post-body block). |

Queue item 6 (group-member-aware visibility + reply-form group gating) remains held — needs `/whoami` from profile-app.

### Verified renders (2026-05-28)

```
200    /forums-poc/                              (forum dashboard)
200    /forums-poc/acoustic/                     (topic list w/ unread + sticky)
200    /forums-poc/general/stripped-out-trussrod/   (threaded single-topic)
200    /forums-poc/acoustic/crusty-old-gibson-l7/   (single-topic, 7 image attachments)
200    /forums-poc/?q=guitar                     (search, 50 hits)
200    /forums-poc/forums.css                    (~21KB w/ new rules)
200    /forums-poc/forums.js                     (~5KB w/ unread + mark-seen + reply form)
200    /bb-mirror-api/v0/auth.php
200    /bb-mirror-api/v0/mark-seen.php
200    /bb-mirror-api/v0/unread.php
```

End-to-end mark-seen+unread tested: marked topic 68963 read for user 1 → unread query against `[68963, 68899]` correctly returned only `68899`.

## Files changed this session

| File | Change |
|---|---|
| **new** `web/forums/_search.php` | Full-text search results page |
| **new** `api/v0/mark-seen.php` | Cookie-authed POST, upserts `forum_read_state` |
| **new** `api/v0/unread.php` | Cookie-authed POST, returns unread topic IDs from a candidate list |
| `web/index.php` | Front controller now dispatches `?q=` to search; parses `$_SERVER['REQUEST_URI']` for args because nginx alias + try_files drops `$query_string` |
| `web/_chrome.php` | Site-wide search bar + `<script src="forums.js" defer>` for the unread-marking pass |
| `web/forums/_single-topic.php` | Attachment query + render; mark-seen fired from JS after auth |
| `web/forums.js` | Three flows: topic-list unread marking, single-topic mark-seen, reply-form submit (existing) |
| `web/forums.css` | New rules: `.search-form`, `.search-result*`, `.post__attachments`, `.attachment--image`, `.attachment--file` |
| `lib/materializers.php` | Sticky read from forum-side meta + `bb_mirror_sync_attachments()`; called from topic + reply upserts |
| `bin/backfill.php` | Sticky walk + attachment post-pass; SQLite branches removed |
| `bin/init-db.php` | SQLite branch removed (postgres only) |
| `config.php` | SQLite path removed; `LG_BB_MIRROR_DB` env removed |
| `schema.sql` | **deleted** |
| `index.sqlite{,-wal,-shm}` | **deleted** |
| nginx snippet | `mark-seen.php` + `unread.php` routes added (looth-dev pool, dev-cookie-gated); `auth.php` route already existed |

## Pitfalls that bit during the session (worth remembering)

1. **nginx alias + try_files + fastcgi-php.conf drops `$query_string`.** REQUEST_URI is preserved, but QUERY_STRING reaches PHP as empty. The fix in `web/index.php` parses `$_SERVER['REQUEST_URI']` directly and rebuilds `$_GET`. Don't trust `$_GET` cold in the front controller.
2. **`bbp_insert_topic()` / `bbp_insert_reply()` don't fire the high-level hooks** (`bbp_new_topic`/`bbp_new_reply`) — verified in earlier session, holds here too. Tests use `do_action()` explicitly or hit the BB REST endpoint.
3. **`_bbp_sticky_topics` lives on the FORUM, not the topic.** Postmeta key looks topic-shaped but `get_post_meta($topic_id, '_bbp_sticky_topics', ...)` always returns empty. Backfill walks each forum's list + UPDATEs the referenced topics.
4. **`groupmeta.forum_id` is serialized PHP** (`a:1:{i:0;i:3818;}`), same as `_bbp_group_ids` on the forum side. Both deserialize through the same helper.
5. **`bp_media.attachment_id`** points at `wp_posts` (attachment post-type). The URL chain is `bp_media.id` (in postmeta CSV) → `bp_media.attachment_id` → `wp_get_attachment_image_url()` → CDN-ish URL under `wp-content/uploads/bb_medias/`.

## Postgres infrastructure on dev (unchanged)

- DB `looth`, schema `forums`, role `bb-mirror`
- 9 tables; 55 forums, 1128 topics, 4405 replies (1592 threaded), 465 persons, 20 bp_groups, **1549 attachments**, 1 forum_read_state (test row)
- Reconcile cron every 10 min via `bb-mirror-reconcile.timer`

## Next session queue (item 6 only)

The 5 burn-queue items are landed. Remaining:

1. **Group-member-aware private visibility** — show private group forums to members of that group. Needs `/whoami` + a user-group membership table (profile-app post-cutover).
2. **Reply-form group gating** — "Join SoCal to post here" CTA on group-attached forums when viewer isn't a member. One-line addition to `forums.js` (`auth` response will include `groups[]` once `/whoami` ships). BB REST enforces server-side as backstop today.
3. **The §4.3 shared header swap** — placeholder still in `_chrome.php`. Waits on archive-poc shipping `/srv/lg-shared/site-header.php` per the coordinator brief.
4. **Forum-list unread chip aggregate** — count of unread topics per forum, shown on forum-card. Skipped this session; needs the same `/whoami`-shaped viewer state.
5. **Mod data fill** — `person.is_moderator` is all false; needs the `looth-internal/v1/user-context` capability surface.

## How to test

```bash
TOK=$(sudo grep -E 'set \$loothdev_token' \
  /etc/nginx/sites-available/dev.loothgroup.com.conf | \
  head -1 | grep -oE '"[^"]+"' | tr -d '"')
curl -s "https://dev.loothgroup.com/claim?t=$TOK" -c /tmp/bbjar -o /dev/null

# search
curl -s -b /tmp/bbjar 'https://dev.loothgroup.com/forums-poc/?q=guitar' \
  | grep -c 'search-result__title'   # expect 50

# topic with attachments
curl -s -b /tmp/bbjar 'https://dev.loothgroup.com/forums-poc/acoustic/crusty-old-gibson-l7/' \
  | grep -c 'attachment--image'      # expect 7

# stickies
sudo -u bb-mirror psql -d looth -c "
  SELECT t.slug, f.slug AS forum, t.sticky_kind
    FROM forums.topic t JOIN forums.forum f ON f.id=t.forum_id
   WHERE t.sticky_kind IS NOT NULL;"

# mark-seen / unread round-trip (substitute real WP cookies)
curl -sk -b /tmp/bbjar -b "<wp_logged_in_cookie>" \
  -X POST -H 'Content-Type: application/json' -d '{"topic_id":68963}' \
  https://dev.loothgroup.com/bb-mirror-api/v0/mark-seen.php
curl -sk -b /tmp/bbjar -b "<wp_logged_in_cookie>" \
  -X POST -H 'Content-Type: application/json' \
  -d '{"topic_ids":[68963,68899]}' \
  https://dev.loothgroup.com/bb-mirror-api/v0/unread.php
```

## Pointers

- Coordination doc: [/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md](../docs/STRANGLER-COORDINATION.md)
- Burn-queue briefing: [/home/ubuntu/projects/docs/reply-to-bb-mirror-burn-queue.md](../docs/reply-to-bb-mirror-burn-queue.md)
- Reply form briefing (prior): [/home/ubuntu/projects/docs/reply-to-bb-mirror-reply-form.md](../docs/reply-to-bb-mirror-reply-form.md)
- Audit briefing: [/home/ubuntu/projects/docs/reply-to-bb-mirror-audit-findings.md](../docs/reply-to-bb-mirror-audit-findings.md)
- Mockup v2: https://dev.loothgroup.com/mockups/forums.html
- Prior handoffs: [handoffs/](handoffs/) — latest before this is `2026-05-28-reply-form.md`

## Handoff rotation

When superseding this file, rename `handoffs/YYYY-MM-DD[-suffix].md` and write
fresh per the project schema in [/home/ubuntu/projects/CLAUDE.md](../CLAUDE.md).
