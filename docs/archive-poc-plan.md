# Archive POC — Scope A plan

**Date:** 2026-05-24
**Status:** Pre-execution. Author: Claude, for Ian's review.
**Companion doc:** [archive-redesign-conversation.md](archive-redesign-conversation.md) — the full design conversation that led here.

This is the **smoke-test scope** (Scope A from the design conversation). Goal: stand up the bones of an out-of-WordPress archive in one focused session and produce real performance numbers + UX feedback. Throwaway if needed; reusable foundation if it works.

---

## In scope (Scope A)

1. **One SQLite database** (`/home/ubuntu/projects/archive-poc/index.sqlite`) with a normalized `content_item` + `person` + `tag` shape, lifted from the schema in the conversation doc but simplified.
2. **One backfill script** that walks `looth_dev` once, normalizes every post into `content_item` rows, and writes thumbnail fallback URLs.
3. **One read-only API endpoint** (PHP, served by the existing dev nginx) at `/archive-api/v0/...` returning JSON.
4. **One static frontend page** at `/archive-poc/` rendering Variant C (segmented tabs + pills) against the API.
5. **Forum topics included** as a content kind (folded in from the start).
6. **Side-by-side perf comparison** with the existing Elementor/S&F-Pro archive — page weight, time-to-first-card, time-to-filter.

## Explicitly OUT of scope (Scope A)

- Discovery rows (Variant D) — added later if Scope A validates.
- Bookmarks / history / `/me/*` pages (Variant E).
- Author/profile blocks driven by `person`.
- Postgres. SQLite is sufficient for ~7K rows; we can migrate later if needed.
- `save_post` mu-plugin sync. Backfill-only for now. Index goes stale until we re-run the script; that's fine for a POC.
- Writing back to WP. Read-only.
- Replacing the current `/archive` URL. New page lives at `/archive-poc/` for direct comparison.
- Touching Search & Filter Pro, Elementor, or any WP plugin.
- Production-ready code. This is throwaway-grade — we rebuild it properly in Scope B if Scope A wins.

## Out of scope until later (Scope B / future)

- Postgres + production schema with FTS via `tsvector`
- `save_post` indexer service with retry queue
- `kind_specific jsonb` per-CPT fields
- lg-layout-v2 body extraction → searchable
- `person` table populated from Member Directory + WP/BB/Fluent
- Bookmark/history endpoints (`/me/saved`, `/me/history`)
- "Continue where you left off" view tracking
- Author blocks (`author-byline`, `contributor-card`, `profile-section`)
- Discovery row generator (`discovery_row` materialized)
- "Because you liked" via tag overlap

---

## Tech stack (Scope A)

| Layer | Choice | Why |
|---|---|---|
| Index DB | **SQLite** (single file) | Zero infra, plenty fast for 7K rows, easy to throw away |
| Search | **SQLite FTS5** | Built-in, supports stemming, fuzzy via trigram tokenizer |
| Backfill | **PHP CLI** using WP's bootstrap | Same DB connection, can use WP functions for tier resolution / attachment URLs |
| API | **PHP** (served via existing dev nginx → php-fpm) | Reuses installed stack, no new daemon |
| Frontend | **Vanilla JS + HTML + CSS** | One file, no build step |
| URL surface | `/archive-api/*` + `/archive-poc/` | nginx-gated like `/v2/` and `/mockups/` |

## What's NOT being added

- No Node, no Python service, no Postgres, no Meilisearch, no Docker
- No new systemd units
- No new plugins
- No DB schema changes to `looth_dev`

---

## Schema (Scope A)

Single SQLite file `index.sqlite` with these tables:

```sql
-- One row per surfaceable thing
CREATE TABLE content_item (
  id              INTEGER PRIMARY KEY,        -- mirrors wp_posts.ID for now
  source          TEXT NOT NULL DEFAULT 'wp',
  kind            TEXT NOT NULL,              -- article|video|loothprint|event|discussion|profile|benefit|misc
  subkind         TEXT,                       -- how-to|profile|opinion|review|... (article only for v0)
  cpt             TEXT NOT NULL,              -- raw WP post_type, for debugging
  title           TEXT NOT NULL,
  slug            TEXT NOT NULL,
  url             TEXT NOT NULL,              -- public URL
  excerpt         TEXT,
  body_text       TEXT,                       -- plain text for FTS (extracted from post_content / lg-layout-v2 / ACF)
  thumb_url       TEXT,                       -- resolved with fallback chain
  thumb_broken    INTEGER DEFAULT 0,          -- flag for R2-broken images
  author_id       INTEGER,                    -- wp_users.ID
  author_name     TEXT,                       -- denormalized for fast rendering
  tier            TEXT,                       -- public|lite|pro
  published_at    INTEGER NOT NULL,           -- unix timestamp
  last_activity   INTEGER,                    -- for discussions
  reply_count     INTEGER DEFAULT 0,          -- for discussions
  like_count      INTEGER DEFAULT 0,          -- from wp_ulike
  view_count      INTEGER DEFAULT 0,          -- from burst_statistics (aggregate)
  duration_min    INTEGER,                    -- for videos
  has_download    INTEGER DEFAULT 0           -- for loothprints/documents
);

CREATE INDEX idx_content_kind        ON content_item(kind, published_at DESC);
CREATE INDEX idx_content_tier        ON content_item(tier);
CREATE INDEX idx_content_author      ON content_item(author_id);
CREATE INDEX idx_content_last_activity ON content_item(last_activity DESC) WHERE last_activity IS NOT NULL;

-- FTS5 virtual table for search
CREATE VIRTUAL TABLE content_fts USING fts5(
  title, body_text, author_name, tag_text,
  content=content_item, content_rowid=id,
  tokenize='porter'
);

-- Tags (flat for now; tag.kind comes in Scope B)
CREATE TABLE tag (
  id    INTEGER PRIMARY KEY,
  slug  TEXT NOT NULL UNIQUE,
  label TEXT NOT NULL
);

CREATE TABLE content_tag (
  content_id INTEGER NOT NULL,
  tag_id     INTEGER NOT NULL,
  PRIMARY KEY (content_id, tag_id)
);
CREATE INDEX idx_content_tag_tag ON content_tag(tag_id);

-- Person (minimal — author byline data only for v0)
CREATE TABLE person (
  id           INTEGER PRIMARY KEY,           -- mirrors wp_users.ID
  display_name TEXT NOT NULL,
  slug         TEXT NOT NULL,
  avatar_url   TEXT
);
```

## CPT → kind mapping (Scope A)

| WP post_type | → kind | → subkind |
|---|---|---|
| post-imgcap, post-regular, post | article | (none in v0) |
| post-type-videos | video | — |
| loothprint, loothcuts, document | loothprint | — |
| event, ajde_events, international-loothi | event | — |
| topic | discussion | — |
| member-spotlight, member-directory | profile | — |
| member-benefit, sponsor-product | benefit | — |
| useful_links, coe-questions, shorty, banger, etc. | misc | (raw post_type) |
| (everything else not in this list) | (skipped) |

## Body text extraction (Scope A)

In order of preference per post:

1. If `_lg_layout_v2` postmeta exists → walk blocks, concat text from `wysiwyg.html`, `callout.body`, `transcript.text`, `post-header.tagline`, `section-heading.text`
2. Else if specific ACF body fields exist for the CPT (e.g. `post_content`, `user_post_content`, `member_benefits_full_details`) → use that
3. Else fall back to `wp_posts.post_content` (strip HTML)

Result stored as plain text in `body_text` for FTS indexing.

## Thumbnail fallback chain (Scope A)

1. Post's featured image (`_thumbnail_id` → attachment URL)
2. First image found in the body text via regex
3. Kind-default placeholder: `/archive-poc/placeholders/<kind>.png`

If the resolved URL HEAD-requests as 4xx/5xx (R2 graveyard check), mark `thumb_broken=1` and use the kind placeholder.

The HEAD-check happens **only at backfill time**, batched 20-at-a-time with a 2s timeout. Not at runtime.

---

## API surface (Scope A)

All endpoints under `/archive-api/v0/`. Cookie-gated via `$loothdev_is_authorized`. JSON responses.

### `GET /archive-api/v0/search`

Query params:
- `q` — free-text (FTS5 match)
- `kind` — one of `article|video|loothprint|event|discussion|profile|benefit|misc`
- `subkind` — string
- `tier` — `public|lite|pro` (multi-value as comma-list)
- `tag` — tag slug (multi-value as comma-list)
- `author_id` — int
- `sort` — `newest|oldest|liked|active` (default `newest`)
- `limit` — int (default 24, max 100)
- `offset` — int (default 0)

Response:
```json
{
  "total": 312,
  "items": [
    {
      "id": 69206, "kind": "article", "subkind": null,
      "title": "Battle-Scarred: A '57 Strat Goes Under the Knife",
      "url": "https://dev.loothgroup.com/post-imgcap/battle-scarred-...",
      "excerpt": "...",
      "thumb_url": "https://dev.loothgroup.com/.../thumb.jpg",
      "author": { "id": 12, "name": "Dan Erlewine", "slug": "dan-erlewine" },
      "tier": "public",
      "published_at": 1747094400,
      "tags": ["vintage", "refret"],
      "like_count": 47
    }
  ],
  "facets": {
    "kind":    [{"v":"article", "n":312}, {"v":"video", "n":298}, ...],
    "tier":    [{"v":"public", "n":203}, {"v":"lite", "n":421}, ...],
    "tags":    [{"v":"vintage", "n":38}, ...]
  }
}
```

Facets are derived from the result set (only filter values that have ≥1 match appear).

### `GET /archive-api/v0/item/{id}`

Single item detail for the inspector / debug. Returns the same shape as one items[] entry plus raw `cpt`, `body_text` preview.

---

## URL surface

| URL | Behind | Serves |
|---|---|---|
| `/archive-poc/` | cookie gate | static frontend (Variant C UI) |
| `/archive-poc/placeholders/*.png` | cookie gate | kind-default thumbnails |
| `/archive-api/v0/*` | cookie gate | PHP API |
| `/cdp/` | cookie gate (existing) | chrome devtools, unchanged |
| `/archive` | (current) | the old Elementor archive — UNTOUCHED |

After Scope A success, `/archive-poc/` either becomes `/archive` (cutover) or stays as a comparison path while Scope B builds.

---

## File layout

```
/home/ubuntu/projects/archive-poc/
├── README.md                ← how to rebuild, what's here
├── schema.sql               ← the SQLite DDL above
├── index.sqlite             ← the built index (gitignored / not committed)
├── bin/
│   ├── backfill.php         ← one-shot WP → SQLite walker
│   └── verify-thumbs.php    ← HEAD-check pass for R2 graveyard
├── api/
│   └── v0/
│       ├── search.php       ← /archive-api/v0/search
│       └── item.php         ← /archive-api/v0/item/{id}
├── web/
│   ├── index.html           ← Variant C frontend
│   ├── archive.js
│   ├── archive.css
│   └── placeholders/
│       ├── article.png
│       ├── video.png
│       ├── loothprint.png
│       ├── event.png
│       ├── discussion.png
│       ├── profile.png
│       └── misc.png
└── nginx-snippet.conf       ← location blocks to paste into the dev site conf
```

---

## Sequence of work

1. **Scaffold** — create `/home/ubuntu/projects/archive-poc/` tree, README, schema.sql.
2. **Backfill v0** — write `bin/backfill.php`. Run against `looth_dev`. Sanity-check row counts per kind.
3. **Thumb resolution** — run `verify-thumbs.php`. Tally broken vs. ok. Drop placeholders.
4. **API** — write `search.php` and `item.php`. Test with curl. Verify FTS works for "hide glue" / "strat refret".
5. **Frontend** — port Variant C from the mockup into `web/index.html`, wire to the API, render results.
6. **nginx wiring** — patch the dev site conf with the new location blocks. Reload.
7. **Verify** — open `/archive-poc/` in chrome (cookie-gated). Click around. Check that filtering returns plausible results. Take a screenshot.
8. **Perf comparison** — load the old `/archive` and new `/archive-poc/` in Chrome; capture page weight, time-to-interactive, time-to-first-card-rendered. Document.
9. **Write findings** — short results doc summarizing what works, what's wrong, what we'd change for Scope B.

Sequence is rigid up to step 5; steps 6-9 can be reordered.

## Definition of "Scope A success"

All of:

- Backfill completes without errors and produces a queryable `content_item` table with ≥6 kinds populated.
- Search for a term that's known to be in multiple posts returns those posts (e.g. "hide glue", "strat", "Erlewine").
- The frontend loads in <500ms cold, <100ms warm, with the cookie already set.
- Switching tabs (Articles → Videos → Discussions) re-renders in <50ms.
- Filtering by tier/tag/author works.
- At least 50% of cards have non-placeholder thumbnails.

If any of those fail, the POC failed — we revisit before proposing Scope B.

## Definition of "Scope A failure → what we learned"

- If body extraction is junk: tells us we need the v2 migration done before search quality matters.
- If thumb fallback yields >70% placeholders: tells us the visual side needs its own project before archive UX is viable.
- If perf isn't dramatically better: tells us the bottleneck wasn't where we thought (maybe theme CSS, not S&F Pro).
- If FTS quality is poor: tells us we need Meilisearch / Postgres FTS earlier than planned.

Each is useful information independent of "did we ship."

---

## Guardrails Claude commits to during execution

1. **Read-only on `looth_dev`.** All MySQL access is `SELECT`. No writes. No schema changes.
2. **All new files under `/home/ubuntu/projects/archive-poc/`** (and a single nginx-snippet patch).
3. **No deactivating plugins.** Search & Filter Pro stays on. The old archive stays at `/archive`.
4. **Confirm before destructive operations.** dropping the SQLite file is fine without confirmation (it's our own); anything else gets surfaced.
5. **Surface a stop signal at meaningful checkpoints** — after backfill, after the API works, before nginx wiring. Each is a natural place for Ian to redirect.

---

## Open questions to resolve as we go

1. **Where do post excerpts come from for kinds without a real excerpt field?** Probably: lg-layout-v2 → `_lg_excerpt` if set, else first 200 chars of `body_text`. Decision deferred until backfill is running.
2. **How do we model the `discussion` kind's "discussion-ness"?** `last_activity` + `reply_count` are in the schema; do we also surface the OP's first reply as the excerpt, or the topic title only? Decision after we see real forum data.
3. **What's our tier-resolution function?** lg-layout-v2 has `gated_tier` on blocks but Scope A doesn't read the layout. For v0 we use whatever `_post_tier` postmeta says, or `'public'` if absent. Improve in Scope B.
4. **Do we sync immediately on `save_post`, or stay batch-only?** Scope A is batch-only. Move to incremental in Scope B.

---

## Time estimate

- Steps 1-3 (scaffold + backfill + thumbs): ~3-4 hours
- Steps 4-5 (API + frontend): ~3-4 hours
- Steps 6-9 (wiring + verify + writeup): ~1-2 hours

**Total: 7-10 focused hours.** Spread across a session or two.
