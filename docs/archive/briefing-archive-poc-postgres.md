# Briefing — archive-poc, postgres migration + coordination on-boarding

archive-poc has been heads-down on dev/live work and hasn't been pulled
into cross-chat coordination yet. Now you are. Read first:

- [STRANGLER-COORDINATION.md](/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md) — the target architecture all four strangler surfaces (profile-app, BB-mirror, archive-poc, lg-layout-v2) are aligning around. Key sections: §1 (tier vocab), §2 (`/whoami` contract), §3g (nginx snippet pattern), §3i (storage architecture — relevant to you), §4 (cutover sequence).

## What changed for you

**Ian ruled: one postgres server, three schemas** (full reasoning in
§3i). archive-poc migrates from SQLite to postgres as part of the
big cutover. Schema name `discovery` (or `archive_poc` — your call;
pick the one that ages well).

**Why this lands now:** mobile is imminent (Ian confirmed — soon at
current pace, not someday). Under mobile concurrency:
- Composite views ("events near me with friends attending," "articles
  by people in my groups") cut across discovery + profile_app + forums
  schemas. Postgres makes these trivial; SQLite-across-files makes them
  painful enough that the team avoids building them.
- Concurrent readers + the mu-plugin's ongoing writes start hitting
  SQLite's writer-lock model.
- Mobile-native tooling (PostgREST, realtime via NOTIFY/LISTEN, RLS)
  is postgres-only.

**The trade-off you're making:** archive-poc's individual search
latency may move from ~2ms (SQLite FTS5 in-process) to ~8ms (postgres
FTS over a socket). Still well under user-perception threshold (~100ms).
At zero-load dev usage you'll notice in microbenchmarks; under mobile
production load, postgres pulls ahead because SQLite serializes writes
and you don't.

## Scope of your migration

**Small.** Your 10MB `index.sqlite` is the entire surface.

1. **Schema port** — your existing SQLite DDL becomes postgres DDL.
   - `content_item` table maps cleanly
   - `content_fts` (SQLite FTS5 virtual table) becomes a `tsvector` column on `content_item` + a GIN index. Query syntax changes from `MATCH` to `@@ to_tsquery(...)`.
   - `tag`, `content_tag`, `person` map directly
2. **Data move** — `pgloader sqlite:///home/ubuntu/projects/archive-poc/index.sqlite postgresql:///looth?search_path=discovery`. Runs in seconds.
3. **App code** — swap PDO sqlite DSN for pgsql DSN in your bootstrap. Most query strings work unchanged (PDO abstracts dialect). FTS queries need rewriting (MATCH → @@). row_count(*) syntax might need a tweak.
4. **nginx unchanged.** Snippet at `/etc/nginx/snippets/strangler-archive-poc.conf` (per §3g) still routes to your FPM pool; the FPM pool now talks to postgres instead of SQLite.

## What you also get from joining coordination

**`/whoami` is being built in profile-app.** Once it ships:
- Replace your current cookie-only gating (the `lg_tier` cookie) with a `/whoami` call for any decision more sensitive than first-paint. Cookie stays as the first-paint hint.
- Per coord §4 step 5, this is a cutover-day step.

**Shared header partial** — coord doc §4.3. At cutover, all strangler surfaces (you, BB-mirror, profile-app) include the same site header partial from lg-layout-v2. Your `web/index.php` currently has its own chrome; that gets replaced with the shared include.

**Future cross-schema queries.** Once you're in postgres next to profile_app and forums, queries like "show me events near user X" or "show me articles by users in the SoCal group" become trivial. Not v0 work, but the door opens.

## What you don't own

- profile-app's schema or `/whoami` build — profile-app chat
- BB-mirror's forum data — BB-mirror chat
- The cutover sequence orchestration — cutover chat

You own: archive-poc's schema, its postgres migration, its app code,
its render templates, its mu-plugin sync logic.

## What I need from you

1. **Confirm receipt** — read coord doc, read this briefing, push back if anything doesn't fit your reality.
2. **Schema name preference** — `discovery` or `archive_poc`?
3. **Timeline** — can the migration land as a single cutover-day step, or do you want a soak window?
4. **Open questions** you have about coordination that aren't yet in the doc.

Report back via Ian (he's the bus). Coordinator updates the doc as
your answers land.

## Pointers

- archive-poc code: `/home/ubuntu/projects/archive-poc/`
- Your current handoff: `/home/ubuntu/projects/archive-poc/SESSION-HANDOFF.md`
- Coordination doc: `/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md`
- Coordinator state: `/home/ubuntu/projects/docs/STRANGLER-SESSION-HANDOFF.md`
- Peer briefings + handoffs in `/home/ubuntu/projects/docs/` and each project dir
