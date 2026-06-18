# BB-mirror — Session Handoff (2026-05-27, post-deploy)

## What this project is

Read-side strangler for BuddyBoss/bbPress forum threads. Forum URLs render
out of bb-mirror's own SQLite + FPM pool at native-static speed; writes
still round-trip through BB REST so notifications/moderation/mentions/
presence keep working unchanged.

Authoritative scope contract: [STRANGLER-COORDINATION.md §3f](../docs/STRANGLER-COORDINATION.md). Original briefing: [briefing-bb-mirror.md](../docs/briefing-bb-mirror.md).

## Current state — deployed on dev, mock-data render only

Steps 1–6 of the prior handoff are **done**. Step 7 (mu-plugin deploy) and
first-live-read are **held**.

**Live on dev, behind cookie gate:**
- https://dev.loothgroup.com/forums-poc/ → 200 (forum index, mock data)
- https://dev.loothgroup.com/forums-poc/<slug>/ → 200 (topic list, mock)
- https://dev.loothgroup.com/forums-poc/<slug>/<topic>/ → 200 (single topic, mock)
- `?bb_native=1` kill-switch → 302 to WP. Pre-cutover this falls back to
  whatever WP would have served; post-cutover (when mount flips from
  `/forums-poc/` → `/forums/`) this lets us peek at BB's native render.

**Pre-cutover mount path is `/forums-poc/`**, not `/forums/`. We can't
take over `/forums/` until §4 step 5 because BB still serves real forum
traffic there. Both paths route through the same `LG_BB_MIRROR_PUBLIC_PATH`
constant in [config.php](config.php) — flipping at cutover is one-line
edits in nginx + config.

## What landed

| Artifact | Path | Status |
|---|---|---|
| Schema | [schema.sql](schema.sql) | ratified, applied to [index.sqlite](index.sqlite) (`bb-mirror:www-data` 664) |
| init-db CLI | [bin/init-db.php](bin/init-db.php) | working, idempotent (`--recreate` to wipe) |
| Backfill CLI | [bin/backfill.php](bin/backfill.php) | **drafted, NOT RUN** — `sudo -u www-data wp eval-file bin/backfill.php` when ready |
| Loopback sync receiver | [api/v0/_sync.php](api/v0/_sync.php) | live on `looth-dev` FPM pool at `/bb-mirror-api/v0/_sync` — but nothing posts to it yet (mu-plugin not deployed) |
| Sync mu-plugin | [deploy/bb-mirror-sync.php](deploy/bb-mirror-sync.php) | **NOT deployed** — held for Ian |
| Front controller | [web/index.php](web/index.php) | dispatches by URL segments to forums/* sketches |
| Forum templates | [web/forums/](web/forums/) | three sketches, mock data, placeholder header (red dashed) |
| FPM pool | `/etc/php/8.3/fpm/pool.d/bb-mirror.conf` | live, socket at `/run/php/php8.3-fpm-bb-mirror.sock` |
| System user | `bb-mirror` (uid 993, gid 983) | owns the SQLite + FPM workers |
| nginx routes | inserted in `/etc/nginx/sites-available/dev.loothgroup.com.conf` (after archive-poc block) | live, `nginx -t` clean |

Nginx-config backup before edit: `dev.loothgroup.com.conf.bak.bb-mirror-*`.

## Why `try_files` looks weird in the nginx block

With `alias /home/ubuntu/projects/bb-mirror/web/` plus `try_files $uri $uri/`,
trailing-slash subpaths intermittently 404 because nginx tests the resolved
filesystem path as a directory and there's no `index` to land on. The
working shape:

```nginx
alias /home/ubuntu/projects/bb-mirror/web/;
try_files $uri /forums-poc/index.php;

location = /forums-poc/index.php {
    fastcgi_param SCRIPT_FILENAME /home/ubuntu/projects/bb-mirror/web/index.php;
    ...
}
```

The absolute `SCRIPT_FILENAME` is load-bearing — `$request_filename` inside
a try_files-fallback nested location with alias resolves wrong. Don't
"clean this up" without re-testing all three URL shapes.

## Next session: work to do

1. **Hand the sync mu-plugin to Ian for deploy.** It's at
   [deploy/bb-mirror-sync.php](deploy/bb-mirror-sync.php); needs to be
   copied to `/var/www/dev/wp-content/mu-plugins/bb-mirror-sync.php`
   (sudo, chown looth-dev:looth-dev). Smoke test by creating a topic in
   wp-admin and tailing `/var/log/php-fpm/bb-mirror-error.log`.
2. **Run backfill** once the mu-plugin is live (so any concurrent edits
   during backfill get caught by the sync hook). Order matters.
3. **Wire templates to real DB queries.** Each sketch has a comment block
   describing the SELECT shape. Drop the mock arrays, open `bb_mirror_db()`
   in read-only mode.
4. **Reply form fetch handler.** Single static JS file (`web/forums.js`)
   that intercepts the reply form, posts to BB REST, reloads on 200. No
   bundler, no framework — same pattern as `lg-fe-editor.js`.
5. **Search box.** FTS5 query against `forum_fts`, surface in the topic-list
   page header. Read-only, no auth dependencies — can ship before /whoami.

## Still held

| Item | Unblocked by |
|---|---|
| Tier gating (`tier_gate` filter on topic/forum queries) | `/whoami` + `/wp-json/looth-internal/v1/user-context/{wp_user_id}` |
| Group-scoped forum views (the 9 Local Looths) | profile-app cutover (user ↔ group from profile-app, not BB) |
| Real header/footer partial | §4.3 — swap [web/_chrome.php](web/_chrome.php) placeholder for `lg-layout-v2/templates/partials/site-header.php` include |
| `/forums/` mount flip (today: `/forums-poc/`) | §4 step 5 cutover |
| First live read (real data on the live site) | §4 step 5 cutover + post-cutover smoke |

## Open questions (carried forward + new)

1. **`forum_moderator` table?** Still leaning defer — `person.is_moderator`
   covers global mods, and per-forum moderation isn't a current need.
2. **`person` refresh policy** post-profile-app cutover. Lazy / TTL /
   invalidation hook? Decide when profile-app's `/users` endpoint shape
   is published.
3. **Search UX scope for v0.** Now flagged for next session — FTS5 schema
   is ready, render path isn't.
4. **Anonymous topics.** Schema mirrors `_bbp_anonymous_name` but the
   render+gating path isn't designed. Defer until we verify any forum on
   dev actually has `_bbp_allow_anonymous = 1` set.
5. **NEW: backfill ordering vs mu-plugin deploy.** Plan above (mu-plugin
   first, then backfill) assumes BB writes during backfill are rare. If
   the dev site is busy at the time, may need a brief read-lock or a
   re-scan pass after backfill. Punted; size of risk depends on dev
   activity at deploy time.
6. **NEW: `looth-internal/v1/user-context` shape.** Poller chat hasn't
   published the response schema yet. The sync receiver doesn't depend
   on it (it uses `get_userdata()` in-process), but the render path will
   when gating lights up.

## How to test

```bash
# Mint cookie
TOK=$(sudo grep -E 'set \$loothdev_token' /etc/nginx/sites-available/dev.loothgroup.com.conf | head -1 | grep -oE '"[^"]+"' | tr -d '"')
curl -s "https://dev.loothgroup.com/claim?t=$TOK" -c /tmp/bbjar -o /dev/null

# Three routes
for url in '' 'anonymous-questions/' 'anonymous-questions/some-topic/'; do
  curl -s -b /tmp/bbjar -o /dev/null -w "%{http_code}  %{size_download}b  /forums-poc/$url\n" \
    "https://dev.loothgroup.com/forums-poc/$url"
done

# DB tables
sqlite3 /home/ubuntu/projects/bb-mirror/index.sqlite '.tables'

# Re-init (destructive)
php /home/ubuntu/projects/bb-mirror/bin/init-db.php --recreate
```

## Pointers

- Coordination doc: [/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md](../docs/STRANGLER-COORDINATION.md)
- Briefing: [/home/ubuntu/projects/docs/briefing-bb-mirror.md](../docs/briefing-bb-mirror.md)
- Pattern source (service shape, FPM/nginx structure): [/home/ubuntu/projects/archive-poc/](../archive-poc/)
- lg-layout-v2 SiteHeader (target of §4.3 swap): [/var/www/dev/wp-content/plugins/lg-layout-v2/src/SiteHeader.php](../../var/www/dev/wp-content/plugins/lg-layout-v2/src/SiteHeader.php)
- Prior handoffs: [handoffs/2026-05-27-scaffold-stub.md](handoffs/2026-05-27-scaffold-stub.md), [handoffs/2026-05-27-scaffold-drafts.md](handoffs/2026-05-27-scaffold-drafts.md)

## Handoff rotation

When superseding this file, rename it `handoffs/YYYY-MM-DD[-suffix].md` and
write fresh per the project schema in [/home/ubuntu/projects/CLAUDE.md](../CLAUDE.md).
