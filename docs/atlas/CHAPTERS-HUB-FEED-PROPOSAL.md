# Proposal — surface chapter discussions in the Hub feed

**Lane:** dmv-native · **Date:** 2026-07-12 · **Status:** proposal, awaiting Ian
**Companion:** [CHAPTERS-RUNBOOK.md](./CHAPTERS-RUNBOOK.md) (add-a-chapter = one INSERT)

## The ask

Chapter discussions currently live only on `/g/<slug>`. This proposes surfacing them in
the **Hub feed** (`/hub/`) so a chapter meetup or "anyone going Saturday?" reaches people
who aren't already sitting on the chapter page — honoring the model Ian approved on the
board (2026-07-12): **read = anyone, post = members, join = one tap.** Because chapter reads
are already public, a chapter card in the Hub is safe to show anon, at parity with a public
forum topic. No new visibility gating is required.

## What the Hub feed actually is

`bb-mirror/web/forums/_feed.php` renders `/hub/` **server-side**. The feed is one
`UNION ALL` (lines ~647–774) over two branches, discriminated by a `card_type` column:

| Branch | `card_type` | Source table | DB |
|---|---|---|---|
| Forum topics | `'topic'` | `topic` | bb-mirror / discovery |
| Content (video/article/…) | `'content'` | `discovery.content_item` | bb-mirror / discovery |

Cards render in PHP branches (lines ~1389–1525), keyed on `card_type`. The Type facet
(`bb-mirror/web/forums/_hub-filters.php:22`) groups `'topic'` under **"Discussions."**
Sort (new/old/hot/random) and cursor paging are **all SQL-side** on that one query.

## The load-bearing constraint (why the "obvious" fix is impossible)

The obvious move is a **third UNION branch** `FROM profile_app.chapter_post`. **It cannot
work as written.** The feed's query runs on the **discovery/bb-mirror database**; chapter
posts live in **`profile_app`**, a *separate database*. There is no fdw/dblink on this
cluster — the same wall `src/DiscoveryComments.php` documents, and the same reason the feed
already reaches `profile_app.user_mutes` and author profiles through **app-level second
passes** (`hub_viewer_muted_uuids()`, `hub_resolve_profiles()` at `_feed.php:803–811`),
never a JOIN. A cross-DB UNION branch is not slow here; it is impossible.

So the real question is **how chapter posts get into the feed's database**, not which line
of SQL to edit.

## Options

**A — Project chapter posts into the discovery DB (recommended).**
On chapter-post create/edit/soft-delete, write a derived "card" row into the discovery
database (into `discovery.content_item` with `kind='chapter_discussion'`, or a dedicated
`discovery.chapter_card` table). The feed then surfaces it through machinery that already
exists. Source of truth stays `profile_app.chapter_post`; the projection is derived and
**rebuildable from scratch** (a backfill query), so it can never be the system of record.
Reply counts already live in `discovery.comments` keyed `post_type='chapter_post'` — the
*same DB* — so the existing count batch (`_feed.php:946–991` idiom) works natively.

- *Into `content_item`* → maximum reuse: the content render branch, tags, reactions,
  sort and paging all light up for free. Cost: `content_item` carries WP-ish semantics
  (`author_id` is a WP id; chapter authors have only a `uuid`), so chapter cards render
  their byline from the denormalized `author_name` with `author_id = NULL`, and avatar
  needs either a stored `avatar_url` on the row or an initial-only fallback (Phase 1).
- *Into a dedicated `discovery.chapter_card`* → a cleaner boundary (no semantic overload),
  at the cost of one new UNION branch + one new render branch. Engineering's call at build.

**B — App-level merge (no schema change).** Leave the feed query alone; run a *second*
query against `profile_app` for recent chapter posts and interleave in PHP before render.
Avoids a projection, but the feed's sort/hot-ranking/cursor paging are SQL-side — merging
a second source breaks stable paging and global ordering. Workable only for a small,
separate **"Recent from your chapters" rail**, not the unified infinite feed.

**C — Do nothing in the Hub.** Discussions stay on `/g/<slug>` only. Cheapest; doesn't
meet the ask.

## Recommendation

**Option A, projecting into `discovery.content_item`.** It respects the cross-DB wall,
reuses the entire content-card path (render, sort, paging, reactions, tags), keeps
`profile_app.chapter_post` as the source of truth, and matches how the platform already
keeps discovery denormalized. Fall back to a dedicated `discovery.chapter_card` table only
if overloading `content_item` proves semantically noisy in review.

## The concrete seam

1. **Writer (outbox).** `src/Chapters.php::createPost()` / `deletePost()` (and the edit
   path) each already do one `profile_app` write. Add a projection write to the discovery
   DB right after — new row on create, `deleted_at` mirror on delete. Callers are
   `api/v0/chapter-posts.php`. This is the only new *logic*; everything downstream is reuse.
   Projected fields: `kind='chapter_discussion'`, `tier='public'`, `title`, `excerpt`
   (`LEFT(body,240)`), `author_name`, `author_uuid`, permalink `/g/<slug>?post=<id>`,
   `published_at=created_at`.
2. **Feed read.** If projecting into `content_item`, the existing content branch and render
   need only: a "**Chapter · <name>**" badge instead of a content-category chip, and the
   chapter permalink. If a dedicated table: add the 4th UNION branch (`_feed.php:~716`) +
   render branch (`_feed.php:~1495`) per the shapes already in the file.
3. **Facet.** `_hub-filters.php:22` — either fold chapter cards under the existing
   **"Discussions"** label (simplest) or add a **"Chapter discussions"** type.
4. **Deep link (small prerequisite).** A Hub card must open the specific discussion. The
   modal I just wired exposes `openPost(pid)` behind the `data-src-base` seam, so `g.php`
   auto-opening from `?post=<id>` is ~3 lines: on load, read the param and call `openPost`.
   Without it, a card can still link to `/g/<slug>` and the post is in the list.

## Open decisions for Ian (product, not engineering)

1. **Scope:** surface **all active chapters** globally (discovery-friendly, but chatter
   from chapters I'm not in could be noise), or only **chapters I've joined**? A "my
   chapters" filter facet is a natural Phase-2 refinement either way.
2. **Weighting:** should chapter chatter rank equal to forum topics in *hot*/*new*, or sit
   slightly demoted so a busy chapter doesn't flood the global feed?
3. **Facet:** fold into **"Discussions"**, or its own **"Chapter discussions"** type?
4. **Permalink:** confirm `/g/<slug>?post=<id>` (auto-opens the modal) as the card target.

## Phasing

- **Phase 1** — projection writer + surface globally under a facet; card → `/g/<slug>?post=<id>`
  (deep-link auto-open); byline + reply count. Read-anyone parity, no new gating.
- **Phase 2** — reactions parity (`discovery.card_reactions` is already polymorphic on
  `post_type`), "my chapters" filter, reply teasers on the card.

---
*This proposal is grounded in a read of `_feed.php`, `_hub-filters.php`, `Chapters.php`,
and `DiscoveryComments.php`. The cross-DB constraint is the crux — verified against the
feed's own `user_mutes`/profile second-pass pattern, not assumed.*
