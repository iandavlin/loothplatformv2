# Physical DB-state audit — 2026-06-05 (live queries, pre-content-migration)

All numbers from direct queries against the running stores. No docs consulted.

## The stores

| Store | Engine | Size | What's in it |
|---|---|---|---|
| **looth_import** | MySQL | **837 MB** | WordPress — the SOURCE of all content + forum + members (wp_posts, BuddyBoss tables, Woo) |
| **looth** | Postgres | 55 MB | strangler read-stores: `forums` + `discovery` schemas |
| **profile_app** | Postgres | 13 MB | identity / profiles / social |
| **index.sqlite** | SQLite | 12 MB | archive-poc's content listing + full-text search index |

## Postgres `looth` — by schema

**`forums` (the Hub's store — already fully in PG):**
- reply 4,910 (25 MB) · topic 1,268 (13 MB) · attachment 1,721 · person 500 · forum 55 · bp_group 20

**`discovery` (content read-store):**
- article_blobs **627** (6.4 MB) — materialized full rendered content (serves standalone pages)
- comments 79 · likes (small)
- **No `content_item`, no FTS here** — the content *index* is NOT in PG yet.

## SQLite `index.sqlite` (12 MB) — the thing option A moves

- **content_item 1,971** — the listing/search index. By kind:
  discussion **1,263** · video 341 · loothprint 166 · article 63 · useful_links 39 · shorty 29 ·
  benefit 25 · sponsor-post 16 · event 9 · loothcuts 7 · misc 7 · document 6
- **content_fts\* (1,971 docs)** — the full-text SEARCH index (SQLite FTS5)
- person 1,801 · tag 1,813 · content_tag 5,053

## Key findings (the decision-relevant stuff)

1. **The SQLite index already spans forum + content.** Of its 1,971 rows, **1,263 are forum
   "discussion" entries** and **~708 are real content** (video/loothprint/article/etc.). So it's
   already a unified search index — but the Hub doesn't read it; the Hub reads `forums.*` in PG.

2. **Forum content exists in TWO places.** SQLite `content_item` discussions (1,263) ≈ PG
   `forums.topic` (1,268) — the same forum topics, represented in both the SQLite search index and the
   PG forums mirror. Existing redundancy.

3. **Identity/person exists in THREE places.** `profile_app.users` (1,815, canonical) ·
   `forums.person` (500, bb-mirror's mirror) · SQLite `person` (1,801, archive-poc's author index).
   Different scopes, but identity is scattered.

4. **What A actually has to move:** the **~708 content items** + the **FTS search** from SQLite → PG
   `discovery`. NOT the 1,263 discussions (the Hub gets those from `forums.*`). The data move is
   trivial (~708 rows); **the real work is rebuilding search on Postgres** (SQLite FTS5 → PG full-text).

5. **No bloat from A.** ~708 content rows + a PG search index added to a 55 MB database = noise. And A
   lets the **entire 12 MB SQLite index retire** (content_item + FTS + person + tags all become
   redundant), so the end state is *smaller*, one store, no SQLite/PG split.

## Recommended scope for A (the content→PG migration)
- Move the **non-discussion content** (~708) into PG `discovery.content_item` (don't re-index
  discussions — drop that overlap; Hub reads `forums.*`).
- Rebuild **search** on Postgres full-text (the meaty part).
- Repoint archive-poc's listing + search reads to PG; **retire `index.sqlite`.**
- Hub feed then unions `forums.topic/reply` + `discovery.content_item` in one query.
- Leave `forums.person` / SQLite `person` cleanup as a *separate* identity-consolidation pass (not
  part of A) — flagged here so it's not forgotten.
