# Lane briefing — archive-poc: switch content store SQLite → Postgres

You're the **archive-poc** lane. Switch archive-poc's content **listing + search** from its SQLite index
to Postgres. This finishes a migration that's already half-done (the full rendered content is already in
PG), deletes a whole store, and unblocks the unified Hub feed (a downstream lane). Work in the canonical
tree (`/home/ubuntu/projects/archive-poc/`) — NOT a worktree. Commit by pathspec; coordinator reviews,
git-tsar pushes.

## Ground truth — `docs/DB-STATE-AUDIT-2026-06-05.md` (physical, 2026-06-05)
- `index.sqlite` (12 MB): `content_item` 1,971 rows — **but 1,263 are forum "discussion" entries;
  only ~708 are real content** (video 341, loothprint 166, article 63, useful_links 39, shorty 29,
  benefit 25, sponsor-post 16, event 9, loothcuts 7, misc 7, document 6). Plus `content_fts*` (the FTS5
  search), `person` 1,801, `tag`/`content_tag`.
- `discovery.article_blobs` (627) is **already in PG** (full rendered content). PG `looth` = 55 MB.

## The work
1. **Schema** — `discovery.content_item` in PG, mirroring the SQLite columns; keep `(post_type,item_id)`
   keying consistent with `discovery.likes`/`comments`. Add a `legacy`/source key for idempotent backfill.
2. **Migrate the ~708 real content rows** into it. **Do NOT migrate the 1,263 forum discussions** — the
   Hub reads those from `forums.*`; re-indexing them here is the redundancy we're removing.
3. **Rebuild search on Postgres full-text** (`tsvector`/`tsquery` + GIN index) to replace SQLite FTS5.
   **This is the meaty part** — the row move is trivial; search is the real job. Match current behavior
   (kind filters, ranking) so the archive/search UX doesn't regress.
4. **Repoint archive-poc's reads** — every listing/search read that hits SQLite today
   (`web/index.php`, `api/v0/search.php`, `search-suggest.php`, `rows-more.php`, `_stream-feed.php`,
   etc.) → Postgres. Audit them; don't miss one.
5. **Make it regenerable, then retire SQLite** — confirm the indexer/materializer can rebuild
   `discovery.content_item` + the PG search from the WP source (so the index stays derived/rebuildable,
   not a new source of truth). **Back up `index.sqlite` before deleting it.** Keep the SQLite path
   working until PG is proven, then retire.

## Out of scope (don't touch)
- The Hub feed (separate `briefing-hub-fold-cpts.md` lane — starts after you land this).
- The WP source data. The `person`/identity stores (3-way split flagged in the audit) — separate pass.

## Verify (dev, reversible — keep SQLite until proven)
- Archive listing + search render off Postgres; **results match the SQLite baseline** (spot-check a few
  queries + kind filters); content pages still render; perf holds (loop perf-czar — must not regress).
- Backfill is idempotent (re-run = no dups). Indexer can rebuild PG content_item from WP.

## Report back
`DONE · FILES · VERIFIED (PG vs SQLite parity + perf + rebuild) · SQLITE RETIRED? (y/n + backup path) · BLOCKED`.
