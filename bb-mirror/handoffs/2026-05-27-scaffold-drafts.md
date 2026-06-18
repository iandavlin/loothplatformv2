# BB-mirror — Session Handoff (2026-05-27)

## What this project is

Read-side strangler for BuddyBoss/bbPress forum threads. Forum URLs render
out of bb-mirror's own SQLite + FPM pool at native-static speed; writes
still round-trip through BB REST so notifications/moderation/mentions/
presence keep working unchanged.

Authoritative scope contract: [STRANGLER-COORDINATION.md §3f](../docs/STRANGLER-COORDINATION.md). Original briefing: [briefing-bb-mirror.md](../docs/briefing-bb-mirror.md).

## Current state (2026-05-27, end of first session)

**Drafts only — nothing deployed to dev yet.**

| Artifact | Path | Status |
|---|---|---|
| Config | [config.json](config.json) | draft, no secrets |
| Schema | [schema.sql](schema.sql) | draft v0.0.1, ready for review |
| Sync mu-plugin | [deploy/bb-mirror-sync.php](deploy/bb-mirror-sync.php) | **NOT deployed** — drop into `/var/www/dev/wp-content/mu-plugins/` only after schema ratified + SQLite initialized |
| nginx snippet | [nginx-snippet.conf](nginx-snippet.conf) | draft, not yet included |
| Template chrome | [web/_chrome.php](web/_chrome.php) | placeholder header/footer (clearly marked) |
| Forum index sketch | [web/forums/index.php](web/forums/index.php) | mock data, no DB |
| Topic-list sketch | [web/forums/_topic-list.php](web/forums/_topic-list.php) | mock data, no DB |
| Single-topic sketch | [web/forums/_single-topic.php](web/forums/_single-topic.php) | mock data + reply-form stub |

Coordinator confirmed scope + answered the four open questions on
2026-05-27. Folded into §3f. See [handoffs/2026-05-27-scaffold-stub.md](handoffs/2026-05-27-scaffold-stub.md) for the prior stub.

## Next session: what to do

In order. Each step is independently shippable.

1. **Review + ratify schema.** Walk [schema.sql](schema.sql) end-to-end.
   Open question: do we want a `forum_moderator` table now (M:N user→forum)
   or rely on global `person.is_moderator` until per-forum moderation
   matters? Leaning the latter — YAGNI.
2. **Write `bin/init-db.php`** — applies schema.sql to a fresh `index.sqlite`
   in `/home/ubuntu/projects/bb-mirror/`. Idempotent (no-op if tables exist).
3. **Write `bin/backfill.php`** — one-shot walk of `wp_posts WHERE post_type
   IN (forum, topic, reply)`, plus `wp_postmeta` for `_bbp_*` projection
   columns. Pattern lifted from `/home/ubuntu/projects/archive-poc/bin/backfill.php`.
4. **Write `web/_sync.php`** — receives POST from the mu-plugin, validates
   loopback origin via `$_SERVER['REMOTE_ADDR'] === '127.0.0.1'` + the
   `X-BB-Mirror-Sync: 1` header, dispatches to `bin/indexer.php` logic.
5. **Wire FPM pool** — `/etc/php/8.3/fpm/pool.d/bb-mirror.conf`. Copy
   archive-poc's pool config, swap socket path + user. systemctl reload php8.3-fpm.
6. **Include nginx snippet** — drop into `dev.loothgroup.com.conf`, reload
   nginx. At this point `/forums/index.php` should 200 with mock data.
7. **Deploy mu-plugin to mu-plugins/.** Only after steps 1–6. Verify with
   `wp shell` create a test topic → see _sync POST hit, see row appear in
   SQLite.

## Held until upstream lands

Per STRANGLER-COORDINATION.md §4:

| Held item | Unblocked by |
|---|---|
| Gating logic (filter forums by viewer tier) | `/whoami` + `capabilities.moderate_forums` from `looth-internal/v1/user-context/{wp_user_id}` |
| Group-scoped forum views (the 9 Local Looths) | profile-app cutover — `user ↔ group` table reads from profile-app, not BB's `bp_groups_member` |
| Real header/footer partial | §4.3 — swap [web/_chrome.php](web/_chrome.php) placeholder for `lg-layout-v2/templates/partials/site-header.php` include |
| First live read on dev | §4 step 5 (after profile-app cutover) |

## Open questions

1. **`bb_native=1` fallback duration.** Coordinator said "first week." Make
   it a date-stamped env var so it actually expires, or just delete the
   nginx `if` block on a calendar reminder? Leaning the latter — simpler.
2. **`person` table refresh policy.** Pre-cutover sync from `wp_users` is
   easy. Post-cutover, when do we re-fetch from profile-app? Options: lazy
   (on miss only), TTL (e.g. 24h), or invalidation hook from profile-app.
   Deferred until profile-app's `/users` endpoint shape is published.
3. **Search UX scope for v0.** FTS5 index is in the schema. Surface as
   a search box on `/forums/` only, or also a sitewide bar? Punted to a
   later session — query layer first.
4. **Anonymous topics.** BB supports anon posting in some forums via
   `_bbp_anonymous_name` meta. Schema mirrors this, but the render layer
   and the gating story haven't been thought through. Probably fine to
   skip until we know it's actually used (memory says it isn't, but
   verify before cutover).

## Pointers

- Coordination doc: [/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md](../docs/STRANGLER-COORDINATION.md)
- Briefing: [/home/ubuntu/projects/docs/briefing-bb-mirror.md](../docs/briefing-bb-mirror.md)
- Pattern to mirror (service shape): [/home/ubuntu/projects/archive-poc/](../archive-poc/)
- BB REST inventory: `curl -b /tmp/devjar https://dev.loothgroup.com/wp-json/buddyboss/v1` (~80 routes; forum/topic/reply ones live under `/forums`, `/topics`, `/reply`)
- lg-layout-v2 SiteHeader (reference for the swap at §4.3): [/var/www/dev/wp-content/plugins/lg-layout-v2/src/SiteHeader.php](../../var/www/dev/wp-content/plugins/lg-layout-v2/src/SiteHeader.php)

## Handoff rotation

When superseding this file, rename it `handoffs/YYYY-MM-DD[-suffix].md` and
write fresh per the project schema in [/home/ubuntu/projects/CLAUDE.md](../CLAUDE.md).
