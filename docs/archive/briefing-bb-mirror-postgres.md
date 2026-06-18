# Briefing — BB-mirror, postgres migration + revised mockup

Ian ruled on storage architecture. New picture in
[STRANGLER-COORDINATION.md §3i](/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md).

## Postgres migration: go

**Primary driver: mobile is imminent.** Ian confirmed it's not "someday"
— soon at current pace. Storage decisions are made against that
constraint, not against today's single-user dev workload.

Mobile = many concurrent readers + writers + composite views across
strangler schemas. SQLite's single-writer model becomes a bottleneck
under mobile concurrency; cross-database joins SQLite handles poorly
become routine on mobile (forum activity by group, by location, by
friends, etc.); mobile-native tooling (PostgREST, realtime, RLS) is
postgres-only.

**Shape:**

- **Shared postgres instance** with profile-app — NOT a separate server.
  Add a schema (or database within the same server) named `forums`
  (your call on `forums` vs `bb_mirror` — pick the one that ages well).
- **Migration:** `pgloader sqlite:///path/to/index.sqlite postgresql:///looth?search_path=forums` style. Your existing 11MB SQLite is small enough that pgloader runs in seconds.
- **Schema extends** to add `reply` (parent_topic_id, parent_reply_id for threading) and `attachment` (parent type, parent id, url, alt, position) tables. Image storage stays as URLs pointing at WP `wp-content/uploads/` — no blobs in postgres.
- **Application code:** swap PDO sqlite DSN for pgsql DSN. Sync receiver, backfill, render templates all adapt. The PDO abstraction means most query strings work unchanged; watch for SQLite-specific FTS5 vs postgres FTS (`tsvector` + GIN index) — different query syntax.

**Honest-statement now in coord doc §3i:** all three strangler
surfaces (profile-app, BB-mirror, archive-poc) on one postgres
instance with separate schemas. archive-poc also migrating from SQLite.

## Revised mockup: postgres-aware + images + nesting

The mockup ask still stands. Layer images and threaded nesting into
`/var/www/dev/mockups/forums.html` per the earlier briefing
(briefing-bb-mirror, "Coordinator → BB-mirror, mockup request" block).
The postgres decision doesn't change what the mockup needs to show —
that's render-layer, not storage-layer. But your schema work to support
the mockup (reply + attachment tables) is now postgres-shaped.

## Suggested order

1. Mockup (`/var/www/dev/mockups/forums.html`) revised first — shows what data shape we're committing to
2. Postgres migration on dev — schema design, pgloader the existing SQLite, app code swap
3. Templates wire to real postgres queries (replaces the "wire to SQLite" plan from your prior handoff — that step now reads from postgres directly)
4. Reply form, search box, reconcile cron continue as planned

## What this doesn't change

- §3f scope: still forum-threads-only, reskin everything else
- Write-side: still round-trips through BB REST (mu-plugin posts to `/bb-mirror-api/v0/_sync`, sync writes to postgres now instead of SQLite)
- `?bb_native=1` kill-switch unchanged
- nginx routing unchanged (still extracted in `/etc/nginx/snippets/strangler-bb-mirror.conf`)
- The /whoami contract you'll eventually call unchanged

## Report back

When mockup lands → coordinator + Ian review pass.
When postgres migration is live on dev (DB created, pgloader run, app
swapped, tests pass) → coordinator updates §3i / §4.
