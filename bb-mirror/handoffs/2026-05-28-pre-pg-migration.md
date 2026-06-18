# BB-mirror — Session Handoff (2026-05-28, mockup v2 + postgres plan staged)

## What this project is

Read-side strangler for BuddyBoss/bbPress forum threads. Forum URLs render
out of bb-mirror's own datastore + FPM pool at native-static speed; writes
still round-trip through BB REST so notifications/moderation/mentions/
presence keep working unchanged.

Authoritative scope contract:
[STRANGLER-COORDINATION.md §3f](../docs/STRANGLER-COORDINATION.md).
Storage architecture: [§3i](../docs/STRANGLER-COORDINATION.md) (postgres,
one server, schema per strangler).

## Current state — mockup v2 shipped, postgres migration plan proposed, no code touched yet

### Deployed on dev (unchanged from 2026-05-27)
- Mu-plugin firing: `/var/www/dev/wp-content/mu-plugins/bb-mirror-sync.php`
- SQLite mirror populated: **55 forums, 1128 topics, 4405 replies, 468 persons, 5533 FTS rows**
- Three URL routes serve at `/forums-poc/` behind the cookie gate
- Forum-list + topic-list templates are **wired to real SELECTs**; single-topic is still on mock data
- Sync end-to-end proven (mu-plugin → loopback POST → `_sync.php` materialize)

### New since 2026-05-27
- **Mockup v2** live at https://dev.loothgroup.com/mockups/forums.html — dashboard-style forum index with hero + regional groups + activity rail, threaded reply rendering, image layers (avatars / topic thumbnails / inline images / attachment rows), schema-implications section, mobile breakpoint at 960 + 640.
- **Postgres migration ruling from Ian** ([briefing-bb-mirror-postgres.md](../docs/briefing-bb-mirror-postgres.md), §3i): all three stranglers on one postgres server, schema per strangler. Driver is "mobile is imminent" — not theoretical future flex.
- **Migration plan proposed, awaiting green-light.** Three decisions pending (see below).

### What this means for the wired SELECTs
Forum-list + topic-list templates exist on dev pointing at SQLite. They keep working until the postgres swap — at which point the DSN flip + `INSERT OR REPLACE` → `ON CONFLICT` translation lands and the templates re-render unchanged (query strings are PDO-portable).

## Decisions pending Ian's sentence

1. **Schema name** — `forums` (recommended, domain-named) vs `bb_mirror` (project-named).
2. **Postgres role** — dedicated `bb_mirror` role owning `forums` schema (recommended) vs reuse `profile-app` role (permissions get weird).
3. **`forum_read_state` table** — build alongside v1 (recommended; powers unread/NEW chrome that the v2 mockup leans on) vs punt.

Once these are green-lit I execute the 10-step plan in the proposal (see next section).

## Postgres migration plan (10 steps, all on dev, reversible)

1. Create role `bb_mirror`, schema `forums` in the existing postgres instance. Grant `profile-app` + `archive-poc` roles `USAGE` for future cross-schema joins.
2. Rewrite `schema.sql` for postgres — BIGINT PKs, TIMESTAMPTZ time cols, ENUMs (`attachment.parent_kind`), `tsvector + GIN` replacing FTS5, `parent_reply_id` rename, new `attachment` table, optional `forum_read_state`.
3. Rewrite `bin/init-db.php` to use pg DSN.
4. Rewrite `bin/backfill.php` — `INSERT OR REPLACE` → `INSERT ... ON CONFLICT (id) DO UPDATE`. Drop PRAGMAs. Run against dev WP.
5. Rewrite `api/v0/_sync.php` the same way. Update `bb_mirror_db()` in `config.php`.
6. Re-test forum-list + topic-list templates against new backend (query strings should survive).
7. Add `bin/migrate-sqlite-to-pg.sh` (pgloader recipe — keep for the eventual live cutover replay).
8. Env flag `LG_BB_MIRROR_DB=sqlite|pg` for ~a day so we can flip back if anything's off.
9. Smoke test: re-fire `bbp_edit_topic` on topic 68963, verify it lands in `forums.topic`.
10. Update handoff + post §3i status to coordinator.

**Out of scope** for the migration round: populating the `attachment` table from `_bbp_attachment_*` + inline `<img>` harvesting. Schema lands first; fill it later.

## Held items (unchanged)

| Item | Unblocked by |
|---|---|
| Tier gating (`tier_gate` filter on queries) | `/whoami` + `/wp-json/looth-internal/v1/user-context/{wp_user_id}` |
| Group-scoped forum views (the 9 Local Looths) | profile-app cutover (user ↔ group from profile-app, not BB) |
| Real header/footer partial | §4.3 — swap [web/_chrome.php](web/_chrome.php) placeholder for `lg-layout-v2/templates/partials/site-header.php` include |
| `/forums/` mount flip (today: `/forums-poc/`) | §4 step 5 cutover |
| First live read (real data on the live site) | §4 step 5 cutover + post-cutover smoke |
| Image-URL harvesting into `attachment` table | Postgres migration lands first |
| Single-topic template real SELECT | Threading + image rendering ships post-migration so the work isn't done twice |

## Mockup v2 — what shipped

Six sections at https://dev.loothgroup.com/mockups/forums.html (cookie-gated, 61 KB):

1. **Forum dashboard** — hero card (Spring Build Challenge example), 9-region Local Looths strip, category-grouped forum cards with monogram + warm-gradient icons, right rail (Recent Activity / Trending / Your Subscriptions). Replaces v1's flat list.
2. **Topic list** — adds optional thumbnail column + `📷 N` image-count chip + "threaded discussion" sub-meta.
3. **Single topic** — threaded replies depth-capped at 4 desktop / 2 mobile (deeper collapse with "Show 3 deeper replies"), per-depth border-left tint fading mahogany → soft, inline mock-images, attachment row beneath OP, "↩ replying to X" affordance on every threaded reply.
4. **Schema implications** — postgres-aware: `attachment` shape, `parent_reply_id` rename, `topic.featured_image_url`, `tsvector + GIN`, optional `forum_read_state`.
5. Mobile note (covers threading collapse rules).
6. State inventory (kept / skipped / open).

Design tokens still inline in the file (A/B/C decision deferred until token set proves stable).

## Threading is non-optional in v1

Dev data: **1,592 of 4,404 replies (36%) use `_bbp_reply_to`**. Threading is load-bearing user behavior, not vestigial. Schema must ship with `parent_reply_id` populated; render must handle it.

## What didn't change from prior handoff

- `?bb_native=1` kill-switch still in nginx
- `/bb-mirror-api/v0/_sync` loopback route, runs on WP FPM pool for `$wpdb` access
- SQLite ownership: `bb-mirror:loothdevs` 664, `umask(0002)` in config.php
- nginx routes inline in `dev.loothgroup.com.conf` (extraction to `snippets/strangler-bb-mirror.conf` is a future tidy-up — mentioned in the postgres briefing as "still extracted in...", but on dev it's not extracted yet)

## How to test (current SQLite state)

```bash
# Counts
sudo -u bb-mirror sqlite3 /home/ubuntu/projects/bb-mirror/index.sqlite \
  "SELECT 'forums',COUNT(*) FROM forum UNION ALL
   SELECT 'topics',COUNT(*) FROM topic UNION ALL
   SELECT 'replies',COUNT(*) FROM reply;"

# Live wired routes
TOK=$(sudo grep -E 'set \$loothdev_token' /etc/nginx/sites-available/dev.loothgroup.com.conf | head -1 | grep -oE '"[^"]+"' | tr -d '"')
curl -s "https://dev.loothgroup.com/claim?t=$TOK" -c /tmp/bbjar -o /dev/null
curl -s -b /tmp/bbjar -o /dev/null -w "%{http_code}\n" https://dev.loothgroup.com/forums-poc/
curl -s -b /tmp/bbjar -o /dev/null -w "%{http_code}\n" https://dev.loothgroup.com/forums-poc/acoustic/

# Mockup
curl -s -b /tmp/bbjar -o /dev/null -w "%{http_code}\n" https://dev.loothgroup.com/mockups/forums.html
```

## Pointers

- Coordination doc: [/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md](../docs/STRANGLER-COORDINATION.md)
- Original scope briefing: [/home/ubuntu/projects/docs/briefing-bb-mirror.md](../docs/briefing-bb-mirror.md)
- Postgres briefing: [/home/ubuntu/projects/docs/briefing-bb-mirror-postgres.md](../docs/briefing-bb-mirror-postgres.md)
- Mockup (live): https://dev.loothgroup.com/mockups/forums.html — source at [/var/www/dev/mockups/forums.html](../../var/www/dev/mockups/forums.html)
- Prior handoffs: [handoffs/](handoffs/) — `2026-05-27-scaffold-stub.md`, `2026-05-27-scaffold-drafts.md`, `2026-05-27-post-deploy.md`, `2026-05-27-step7-done.md`

## Handoff rotation

When superseding this file, rename it `handoffs/YYYY-MM-DD[-suffix].md` and
write fresh per the project schema in [/home/ubuntu/projects/CLAUDE.md](../CLAUDE.md).
