# Lane briefing — archive-poc / Postgres (resume, 2026-06-07)

You're the **archive-poc** lane. Fresh chat resuming a stable lane — successor to `05b7f8d2`. The
SQLite→Postgres migration is **DONE and LIVE**; you hold the PG stack and pick up open items.

Sanity-check the box first: `curl -s ifconfig.me` → `50.19.198.38` = act locally, do NOT SSH. Work in the
canonical tree (`/home/ubuntu/projects/archive-poc/`), NOT a worktree. Commit small by pathspec;
coordinator reviews, **git-tsar pushes — no silent pushes**.

## Current state (ground truth)
- **SQLite→PG cutover is LIVE.** archive-poc reads AND writes on Postgres (`discovery.*` in the `looth`
  DB); a **SQLite dual-write keeps the revert path fresh**. Listing, search (PG full-text), and content
  render all run off PG.
- **🔴 SQLite retirement is HELD — Ian's call, do NOT delete.** Writer-on-PG is NOT the same as
  safe-to-retire. Retirement needs a real **soak**, then Ian decides. Don't conflate the two; don't drop
  the dual-write or the SQLite path without an explicit go.
- **Known duplication (open, not yet greenlit to fix):** `discovery` holds a half-finished SQLite→PG
  migration — `article_blobs` (live, serves CPT standalone render) **and** `content_item` (a dup of the
  SQLite search index) run in PARALLEL. Reconciling/cleaning this up is open work; flag it to coordinator
  before acting — it's load-bearing for the standalone renderer.
- Ground-truth audit: `docs/DB-STATE-AUDIT-2026-06-05.md`. Memory: `project_archive_poc_pg_cutover`,
  `project_discovery_pg_migration`.

## Scope
- Own the archive-poc PG stack: the PG reads (listing/search/render), the `_sync` PG writer, the indexer/
  taxonomy populate. Keep the index **derived/rebuildable** from the WP source, not a new source of truth.
- The Hub feed consumes `discovery` — coordinate contract-affecting schema changes through coordinator.

## Cutover reminder (don't lose this)
- Grants must be re-applied at the real cut, e.g. `GRANT SELECT ON discovery.comments TO "bb-mirror"`
  (committed `dd248c5`, applied on dev). Same pattern for `content_item`. Track any new grant you add.

## Out of scope
- The Hub feed surface (separate lane). The WP source data. The 3-way `person`/identity split.

## Report back (to coordinator)
`DONE · FILES · VERIFIED (PG parity + perf + rebuildable) · SQLITE: still dual-write (retirement = Ian) · BLOCKED`.
**Report your session ID + outliner title** so coordinator logs you in CHATS-MENU + lineage.
