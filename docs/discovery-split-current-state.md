# Discovery store split — current state (2026-06-11)

**Status:** descriptive only. SQLite retirement is **HELD pending a real soak — Ian's
explicit call**. Nothing in this doc is a green light to delete or "finish" anything.
Written by the content-cleanup lane per briefing item 6.

## The two stores

| | Postgres `looth` db, `discovery` schema | SQLite `archive-poc/index.sqlite` |
|---|---|---|
| Role | **LIVE** — all production reads + writes | Legacy index, revert insurance |
| Feed/search index | `content_item`, `content_tag`, `tag`, `person` (+ `tsv` column, `websearch_to_tsquery` search) | same tables + `content_fts*` (FTS5, bm25 search) |
| Standalone blobs | `article_blobs` — serves every managed-CPT post page (672 blobs, renderer at `standalone/render.php`) | **absent — never existed here** |
| Social (net-new) | `comments`, `comment_reactions`, `card_reactions`, `likes`, `saved_posts` | **absent — never existed here** |

## How they stay in sync

- `api/v0/_sync.php` (loopback endpoint, hit by mu-plugins `archive-poc-sync.php` +
  `lg-article-materializer.php`) writes PG, then **best-effort dual-writes the same
  decision into SQLite** — explicitly marked "SOAK WINDOW ONLY" in the code.
- Blobs are PG-only: `bin/materializer.php` / `bin/materialize-all.php`.
- **Gotcha:** the save-hooks do NOT fire reliably from wp-cli edits (verified
  2026-06-11 during the dedupe) — after `wp post update`/`wp eval` meta changes, sync
  manually: `curl -X POST /archive-api/v0/_sync` + `materialize-all.php --post=<id>`.

## Who reads what

- The archive-poc FPM pool pins `env[LG_ARCHIVE_POC_DSN]=pgsql:...` → all web/API
  reads are PG.
- `config.php` **defaults to SQLite when no DSN is set** — CLI tools
  (`reindex-all.php`, `backfill.php`, ad-hoc scripts) silently hit the legacy file
  unless you export the pgsql DSN. Second gotcha; bites every new session.
- Search code is driver-aware (`_bootstrap.php`): PG `tsv @@ websearch_to_tsquery`,
  SQLite `content_fts MATCH + bm25`.

## What the revert actually covers (the part to be honest about)

Flipping the pool DSN back to `sqlite:` restores **feed + search reads only**.
Comments, reactions, likes, saved posts, and the standalone blob renderer have no
SQLite counterpart — a revert today would break those features, not just degrade
freshness. The revert insurance has been narrowing every time a net-new feature
lands PG-only. Worth weighing when deciding how long the soak needs to be.

## What a finish (SQLite retirement) would take

1. **Ian's call** that the soak is real and done. Not before.
2. Remove the dual-write block from `api/v0/_sync.php` (clearly fenced, ~25 lines).
3. Flip `config.php`'s no-DSN default from SQLite to PG (or make a missing DSN fatal)
   so CLI tools can't silently write a dead file.
4. Retire or port the SQLite-direct tools: `bin/reindex-all.php` opens
   `index.sqlite` directly.
5. Delete `index.sqlite{,-shm,-wal}` + the `.bak.20260602` copy; update
   `archive-poc/README.md` + `SESSION-HANDOFF.md`.
6. Replace the revert story: PG-side insurance (e.g. nightly `pg_dump` of the
   `discovery` schema, or a `discovery_shadow` schema) instead of the SQLite file.
7. Cut-day note: live presumably runs the same split — re-verify the pool DSN, the
   dual-write flag, and blob coverage (`wp posts with _lg_layout_v2` vs
   `article_blobs` count) on live before and after.
