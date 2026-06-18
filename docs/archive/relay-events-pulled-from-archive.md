# Relay → Coordinator: events kept in the index, hidden from the archive *page*

**From:** archive-poc lane
**Date:** 2026-06-03
**Re:** Events had crept into archive search results. Final disposition (per Ian):
**keep events in the index, but don't render them on the archive search page.**

## The decision

Events serve two different surfaces with opposite needs:

- **Front-page "Upcoming events" rail** — *wants* events. It reads the SQLite index
  directly (`archive_poc_run_events_upcoming()` in `api/v0/_rowlib.php`,
  `WHERE ci.kind='event'`, dispatched from `web/index.php`, row config in
  `rows.json` / dash `config.json`).
- **Archive search page (`/archive/`) + search modal** — should *not* surface events.

So events stay indexed (rail keeps working) and are filtered out at the search layer.

## What I changed

1. **Kept** `'event' => 'event'` in the CPT→kind map of all three indexers
   (`bin/backfill.php`, `bin/indexer.php`, `bin/backfill-pg.php`) — events index normally.
2. **Archive search API (`api/v0/search.php`):** added a hard base-WHERE exclusion
   `ci.kind != 'event'`, so events never appear in `/archive/` results **or** the kind/
   author/tag facets. `'event'` is also kept out of `$ALLOWED_KINDS` (the type filter).
3. **Search-suggest (`api/v0/search-suggest.php`):** the "posts" queries now exclude
   events (`ci.kind NOT IN ('discussion','event')`); discussions query is unaffected.
4. Re-ran the backfill.

## Verified

- Index: **9 events** present → front-page "Upcoming events" rail repopulated. ✓
- `/archive-api/v0/search` (mixed + faceted): **0 events** in results, **no** event facet. ✓
- search-suggest posts: events excluded by query. ✓ (code-level)
- The `event` CPT remains a fully indexed, incrementally-synced kind — no special-casing
  in the sync path; only the *read* surfaces filter it.

## Note for whoever owns front-page rows

The events rail is the **only** intentional event surface now. If that rail is ever
retired, events become "indexed but unsurfaced" — harmless, but worth a cleanup pass
then. Also: the activity strip (WP-fed `/wp-json/looth/v1/activity`) is independent of
the index; if an `event`-type activity is posted in WP it can still appear there — left
as-is (ask if you want it filtered too).

## Status

- [x] events kept in index (count 9) — front-page rail intact
- [x] events excluded from `/archive/` search results + facets + type filter
- [x] events excluded from search-suggest posts
- [x] re-backfill run + verified
