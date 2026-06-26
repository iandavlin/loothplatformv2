# Hub Tag Search — BUILD handoff + schema runbook

**Branch:** `tag-search-build` (off main @ 06fd486)
**Date:** 2026-06-26
**Scope:** Option A from `docs/atlas/TAG-SEARCH-SCOPE.md` — turn tag chips into a
real **exact-tag facet**, working **cross-world** (forum topics + content CPTs).
**Status:** code complete + verified origin-direct on dev2 against the live DB.
**Do NOT merge** — preview-request to the keeper.

---

## What changed (code, all in the monorepo)

- **`bb-mirror/web/forums/_hub-filters.php`**
  - `hub_filters_parse()`: new `tag` dimension — `hub_slugify($_GET['tag'])`, so the
    value is always a normalized `[a-z0-9-]` slug (single-select, mirrors `show`).
  - `hub_filter_where()`: new `tag` branch.
    - content: `u.topic_id IN (SELECT ct.content_id FROM discovery.content_tag ct
      JOIN discovery.tag t ON t.id=ct.tag_id WHERE t.slug=:tag_c)` — exact copy of
      the proven **Shows** facet clause.
    - topic: `EXISTS (SELECT 1 FROM unnest(u.tags) x WHERE <slugify(x)> = :tag_t)`,
      where `<slugify>` = `trim(both '-' from regexp_replace(lower(x),'[^a-z0-9]+','-','g'))`
      — the SAME rule as PHP `hub_slugify()`, so the two stores reconcile on one
      canonical slug at query time. (`u.tags` is already in the union projection —
      topic branch selects `t.tags`, content branch `NULL::text[]` — so no projection
      change was needed; that resolves the SCOPE doc's open decision.)
    - Distinct binds `:tag_c` / `:tag_t` (PDO emulation is off — a named placeholder
      can't be reused; matches the existing `:hq_t`/`:hq_c` pattern).
  - `hub_tag_terms($db,$slug,$content_tiers)`: active-tag facet — cross-world count
    (content via slug, tier set = the feed's display set; topics via normalized
    label) + a display label (canonical `discovery.tag.label`, else de-slugified).
    Ships the "active-tag-only" count per SCOPE §4; full per-tag listing deferred.

- **`bb-mirror/web/forums/_feed.php`**
  - `feed_render_tags()`: repointed chips from `/?q=<tag>` (the blind FTS path) to
    `hub_url(['tag'=>slug])`. Accepts either a string (topic label → `hub_slugify`)
    or `['label'=>,'slug'=>]` (content → the **real** `discovery.tag.slug`).
  - Content tag fetch now also selects `t.slug` and carries `['label','slug']` per
    chip — **67/1846** content tags have `slug <> slugify(label)` (e.g. `Gerry's
    Picks` → real slug `gerrys-picks`, not `gerry-s-picks`), so the stored slug is
    required for an exact content match.
  - Stashes the active-tag term into `$GLOBALS['__bb_hub_rail']['tag']`.

- **`bb-mirror/web/forums/_filter-rail.php`**
  - `hub_url()` + `hub_query_params()`: carry `tag` (so sort links + pagination keep
    the active tag).
  - `hub_render_chipbar()`: renders the active tag as a removable **`Tag`** chip
    (label from the stashed term; remove clears `tag`). `hub_reset_url()` already
    clears it (it omits the `tag` key).

- **`bb-mirror/schema.pg.sql`** — declare `topic.tags` (it was added ad-hoc on
  dev2/prod with no migration in repo; folded back per the monorepo mandate) and a
  GIN index. See the runbook below.

No CSS change (reuses the existing `.fc-tag.tag-chip` styles). Pure read-path; no
WP / poller / auth coupling.

---

## SCHEMA RUNBOOK — apply on dev2 + prod PG (keeper)

`topic.tags` already exists on the live dev2 DB (as `_text`), so the feature is
**correct without this migration** — it only (a) reconciles the repo schema with
reality and (b) adds the GIN index. **Idempotent**; safe to run anywhere.

```sql
-- DB: looth   (schema: forums)
SET search_path = forums, public;

-- 1. Declare the column the repo schema was missing (no-op where it exists).
ALTER TABLE topic ADD COLUMN IF NOT EXISTS tags TEXT[];

-- 2. GIN index for the tag facet. Backs exact element / @> membership
--    (e.g. tags @> ARRAY['councilyes']). NOTE: the live feed's NORMALIZED match
--    (slugify each element) is a seq scan and will NOT use this index — that is
--    fine at ~1.3k topics; the index is forward-looking for the exact-element path
--    and for scale (per SCOPE §4). Use CONCURRENTLY in prod to avoid a write lock:
CREATE INDEX IF NOT EXISTS idx_topic_tags ON topic USING GIN (tags);
-- prod alternative (run OUTSIDE a txn):
--   CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_topic_tags ON topic USING GIN (tags);
```

Verify:
```sql
\d+ forums.topic            -- tags TEXT[] present
\di forums.idx_topic_tags   -- index present
```

The `CREATE TABLE … (… tags TEXT[] …)` + `CREATE INDEX IF NOT EXISTS` are also in
`bb-mirror/schema.pg.sql` so a fresh init gets them automatically; this runbook is
the in-place path for already-provisioned DBs.

---

## Verification (origin-direct, dev2, live DB)

Rendered `bb-mirror/web/index.php` for several `?tag=` requests as the `looth-dev`
pool user against the live `looth` DB (anon viewer = full display set, gated
teasers for higher tiers; page caps at 18 cards/page):

| request | result |
|---|---|
| `?tag=councilyes` | **14** cards, all topics, 0 content — the headline fix (old `/?q=councilyes` = **0** FTS hits) |
| `?tag=neck-reset` | cross-world: 14 topic + all-tier content, interleaved, page-1 capped at 18 |
| `?tag=tools` | cross-world: topics + content (15+ content) |
| `?tag=zzznotarealtag` | **0** cards — exact, fails closed, no false positives |
| no tag | full feed unchanged |

- Card chips emit `href="/hub/?tag=<slug>"` (not `/?q=`); topic chips use
  `hub_slugify(label)`, content chips use the real slug — confirmed
  `Gerry's Picks → ?tag=gerrys-picks` (label htmlspecialchars-safe).
- Active **`Tag`** chip shows in the chipbar (label `neck reset`) and is removable;
  the tag is preserved across sort links + pagination.
- `php -l` clean on all three PHP files; page renders ~190 KB, exit 0, no warnings.

---

## Collision note (for merge sequencing)

This lane touches:
- `bb-mirror/web/forums/_feed.php` — **shared** with the discussion save/share lane.
- `bb-mirror/web/forums/_hub-filters.php`
- `bb-mirror/web/forums/_filter-rail.php`
- `bb-mirror/schema.pg.sql`

No `forums.css` change (so no collision with hub-card-sep). All built off main in
parallel; `_feed.php` will need rebase sequencing against the save/share lane —
my `_feed.php` edits are localized to `feed_render_tags()`, the content-tag fetch
(~L826), and the rail-stash line (~L628).
