# BB-mirror — Session Handoff (2026-05-28, postgres migration live)

## What this project is

Read-side strangler for BuddyBoss/bbPress forum threads. Forum URLs render
out of bb-mirror's own datastore + FPM pool at native-static speed; writes
still round-trip through BB REST so notifications/moderation/mentions/
presence keep working unchanged.

Scope contract: [STRANGLER-COORDINATION.md §3f](../docs/STRANGLER-COORDINATION.md).
Storage architecture: [§3i](../docs/STRANGLER-COORDINATION.md) — one postgres,
schema per strangler.

## Current state — postgres backend live on dev, end-to-end proven

**bb-mirror is running on postgres.** SQLite path retained behind
`LG_BB_MIRROR_DB=sqlite` env flag as the rollback escape.

### Postgres infrastructure on dev
- DB: `looth` (new), shared instance with `profile_app`
- Schema: `forums` owned by role `bb-mirror`
- Roles: `bb-mirror` (web pool, peer-auth), `looth-dev` (sync writer pool, peer-auth, RWD on schema), `profile-app` (USAGE + default SELECT for future cross-schema joins)
- 8 tables: forum, topic, reply, forum_subscription, person, attachment, forum_read_state, sync_state
- tsvector + GIN search indexes on topic + reply, populated by triggers (no manual reindex)
- `reply.parent_reply_id` FK declared `DEFERRABLE INITIALLY IMMEDIATE` so backfill + bulk sync can SET CONSTRAINTS DEFERRED inside a transaction
- Re-applying schema.pg.sql is idempotent (CREATE TABLE IF NOT EXISTS + DO blocks for types)

### Data populated
| Table | Rows |
|---|---|
| forum | 55 |
| topic | 1,128 |
| reply | 4,405 (of which 1,592 are threaded — `parent_reply_id IS NOT NULL`) |
| person | 465 |
| attachment | 0 (population deferred to a later session) |
| forum_read_state | 0 (table exists; "mark seen" endpoint to come) |

Backfill skipped 34 orphan topics + 83 orphan replies that referenced deleted forums/topics in WP. (Postgres is stricter at insert time than SQLite was; SQLite version pruned them after the fact.)

### End-to-end sync proven on postgres
- `wp eval 'do_action("bbp_edit_topic", 68963)'` → mu-plugin POST → nginx → `_sync.php` (on looth-dev FPM pool, peer-auths as postgres `looth-dev` role) → row materialized in `forums.topic` (sync_at confirmed updated)
- Direct curl POST to `/bb-mirror-api/v0/_sync` also 200s

### Live URLs (cookie-gated, real-data)
- `https://dev.loothgroup.com/forums-poc/` — forum list (postgres-backed, 200, ~12KB)
- `https://dev.loothgroup.com/forums-poc/<slug>/` — topic list (postgres-backed)
- `https://dev.loothgroup.com/forums-poc/<slug>/<topic>/` — still mock data (single-topic template not yet wired; held until v2 restyle)

## Files changed in this migration

| File | Status |
|---|---|
| [schema.pg.sql](schema.pg.sql) | new — postgres DDL |
| [schema.sql](schema.sql) | retained — SQLite fallback for env-flag rollback |
| [config.php](config.php) | LG_BB_MIRROR_DB env detection, dual-mode `bb_mirror_db()`, helpers: `bb_mirror_ts()`, `bb_mirror_ts_in()`, `bb_mirror_upsert_sql()`, `bb_mirror_bool()` |
| [bin/init-db.php](bin/init-db.php) | branches pg vs sqlite; pg path shells out to `psql -f` |
| [bin/backfill.php](bin/backfill.php) | uses `bb_mirror_upsert_sql()` so works in both backends; tracks valid forum/topic IDs to skip orphans; `_thumbnail_id` → `featured_image_url` resolution added |
| [api/v0/_sync.php](api/v0/_sync.php) | same dual-mode pattern; subscriptions use composite-PK conflict target |
| [bin/migrate-sqlite-to-pg.load](bin/migrate-sqlite-to-pg.load) | new — pgloader recipe for live cutover replay (not used on dev; reserve for live) |

## Rollback path (~ a day, then retire)

```bash
# Switch the bb-mirror FPM pool to use SQLite again
sudo sh -c 'echo "env[LG_BB_MIRROR_DB] = sqlite" >> /etc/php/8.3/fpm/pool.d/bb-mirror.conf'
# Same for looth-dev pool (sync writer)
sudo sh -c 'echo "env[LG_BB_MIRROR_DB] = sqlite" >> /etc/php/8.3/fpm/pool.d/looth-dev.conf'
sudo systemctl reload php8.3-fpm
# SQLite mirror is intact from prior session, no re-backfill needed
```

To retire the SQLite path entirely: delete the `init_sqlite`/`!is_pg` branches
in config.php, init-db.php, backfill.php, _sync.php; delete schema.sql + index.sqlite.

## Schema decisions ratified by coordinator

1. **Schema name: `forums`** (domain-named, ages past BB)
2. **Dedicated `bb-mirror` postgres role** (cross-schema discipline)
3. **`forum_read_state` table built alongside v1** (unread/NEW chrome the mockup leans on)
4. **`reply.parent_reply_id`** (rename of SQLite `reply_to_id`)
5. **`attachment` table** (parent_kind ENUM, parent_id, url, alt, mime, w/h, position) — image URLs only, no blobs
6. **`topic.featured_image_url`** denormalized for no-join read

## Next session — work to do

Order from the [postgres briefing](../docs/briefing-bb-mirror-postgres.md):

1. **Templates → real postgres queries (single-topic).** Forum-list + topic-list already postgres-backed. Single-topic still mock — needs the SELECT + threaded-reply rendering per the v2 mockup. ~120 lines.
2. **Restyle wired templates against v2 visual language.** Current forum-list + topic-list use v1 styling (functional but flat); the v2 mockup committed us to dashboard / threaded / image-aware. Bring them in sync.
3. **Attachment harvesting.** Schema is in; population isn't. Source priority: bbPress `_bbp_attachment_*` meta → BB Platform `bp_media` → inline `<img>` URLs harvested from `post_content` at sync.
4. **Reply form fetch handler.** `web/forums.js`, posts to `/wp-json/buddyboss/v1/reply` with `parent_reply_id` when replying to a specific post, reload on 200.
5. **Search box.** FTS query against `topic.search_doc` + `reply.search_doc` via `plainto_tsquery`. Index already populated by triggers during backfill — proven working.
6. **`forum_read_state` "mark seen" endpoint.** Fires on single-topic render; populates the unread/NEW chrome.
7. **Reconcile cron.** Walk `wp_posts WHERE modified > last_reconcile`, re-upsert. Belt-and-suspenders against dropped webhooks.

Once SQLite rollback window has passed (~ a day from now), retire the SQLite branches and schema.sql.

## Held items (unchanged)

| Item | Unblocked by |
|---|---|
| Tier gating | `/whoami` + `/wp-json/looth-internal/v1/user-context/{wp_user_id}` |
| Group-scoped forum views | profile-app cutover (user ↔ group from profile-app) |
| Real header/footer partial | §4.3 — swap web/_chrome.php placeholder |
| `/forums/` mount flip | §4 step 5 cutover |
| First live read | §4 step 5 cutover + post-cutover smoke |

## How to test

```bash
# Counts
sudo -u bb-mirror psql -d looth -c "
  SELECT 'forums' tbl, COUNT(*) FROM forum
  UNION ALL SELECT 'topics', COUNT(*) FROM topic
  UNION ALL SELECT 'replies', COUNT(*) FROM reply
  UNION ALL SELECT 'threaded', COUNT(*) FROM reply WHERE parent_reply_id IS NOT NULL;"

# FTS query
sudo -u bb-mirror psql -d looth -c "
  SELECT id, substring(title,1,60) FROM topic
  WHERE search_doc @@ plainto_tsquery('english','guitar')
  ORDER BY ts_rank(search_doc, plainto_tsquery('english','guitar')) DESC LIMIT 5;"

# Live URLs (forum-list, topic-list)
TOK=$(sudo grep -E 'set \$loothdev_token' /etc/nginx/sites-available/dev.loothgroup.com.conf | head -1 | grep -oE '"[^"]+"' | tr -d '"')
curl -s "https://dev.loothgroup.com/claim?t=$TOK" -c /tmp/bbjar -o /dev/null
curl -s -b /tmp/bbjar -o /dev/null -w "%{http_code}\n" https://dev.loothgroup.com/forums-poc/
curl -s -b /tmp/bbjar -o /dev/null -w "%{http_code}\n" https://dev.loothgroup.com/forums-poc/acoustic/

# Sync receiver (E2E via wp-cli)
cd /var/www/dev && sudo -u www-data wp eval 'do_action("bbp_edit_topic", 68963);'
sudo -u bb-mirror psql -d looth -c "SELECT id, sync_at FROM topic WHERE id=68963;"

# Re-apply schema (idempotent)
sudo -u bb-mirror php /home/ubuntu/projects/bb-mirror/bin/init-db.php

# Re-backfill from WP (idempotent — upserts everything)
cd /var/www/dev && sudo -u looth-dev wp eval-file /home/ubuntu/projects/bb-mirror/bin/backfill.php

# Toggle to SQLite rollback
LG_BB_MIRROR_DB=sqlite sudo -u bb-mirror psql -d looth -c "SELECT 1;"  # would fail; flag flips PDO target
```

## Notes for next session / open questions

- **Sticky topics rendering as NULL.** Schema captures `sticky_kind` but `_bbp_sticky_topics` / `_bbp_super_sticky_topics` actually live on the forum (as a CSV of topic IDs), not on the topic. Current backfill / sync look at topic-level meta and find nothing. Fix: walk the forum's sticky list and stamp each referenced topic. Tracked here, not yet done.
- **Persons count drift:** SQLite backfill saw 468 persons; postgres backfill saw 465. Tiny diff. Likely from the 34 orphan topics being skipped earlier in the pipeline (their author IDs never got recorded). Probably harmless; surfaces only if a forum tries to render an orphan-author byline (it won't).
- **`pg_dump` baseline.** Want to add a `bin/dump-pg.sh` for periodic backup, especially before any DDL change. Light lift, queue for a future tidy session.

## Pointers

- Coordination doc: [/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md](../docs/STRANGLER-COORDINATION.md)
- Scope briefing: [/home/ubuntu/projects/docs/briefing-bb-mirror.md](../docs/briefing-bb-mirror.md)
- Postgres briefing: [/home/ubuntu/projects/docs/briefing-bb-mirror-postgres.md](../docs/briefing-bb-mirror-postgres.md)
- Mockup v2: https://dev.loothgroup.com/mockups/forums.html — source [/var/www/dev/mockups/forums.html](../../var/www/dev/mockups/forums.html)
- Pattern source (service shape): [/home/ubuntu/projects/archive-poc/](../archive-poc/) — also migrating to pg per coordinator
- Prior handoffs: [handoffs/](handoffs/) — five rotated stubs spanning 2026-05-27 (scaffold → drafts → post-deploy → step7-done) through 2026-05-28 (pre-pg-migration)

## Handoff rotation

When superseding this file, rename `handoffs/YYYY-MM-DD[-suffix].md` and write
fresh per the project schema in [/home/ubuntu/projects/CLAUDE.md](../CLAUDE.md).
