# Lane briefing — fold content CPTs into The Hub feed (unified feed)

You're the **Hub** lane (bb-mirror). Instead of rebuilding a separate stream, **fold content into the
Hub's already-polished feed** → one unified activity feed (forum threads + articles/videos/loothprints),
reusing the Hub UI. This is the unified-feed end-state ([[project_activity_stream_launch]]). Work in the
canonical tree (`/home/ubuntu/projects/bb-mirror/` + `archive-poc/`); NOT a worktree. Commit by
pathspec; coordinator reviews, git-tsar pushes.

## Scope — keep archive-poc, kill the stream PAGE only (Ian, 2026-06-05)
- **KEEP archive-poc** as the content engine: it still renders individual article/video/sponsor pages,
  owns the content data + search index, and serves the likes/comments APIs. None of that changes.
- **RETIRE the `/stream/` feed page only** — `archive-poc/web/stream.php`, `_stream-feed.php`,
  `_render-stream-card.php`, `api/v0/stream-more.php`, and the `/stream/` route. The Hub becomes the
  single unified feed. Do this LAST, once the Hub feed covers it. The comments/likes APIs + content
  rendering STAY (they're archive-poc, not "stream").
- **NO data duplication** either way — see below.

## PREREQUISITE — content must be in Postgres first (separate lane, must land before you start)
The **archive-poc lane** (`docs/briefing-archive-poc-pg.md`) switches archive-poc's content store from
SQLite to Postgres — moving the ~708 real content rows into `discovery.content_item` and rebuilding
search on PG. **Do not start the feed work until that lands** — you can't cross-query forums (PG) with
content that's still in SQLite. Ground truth for both lanes: `docs/DB-STATE-AUDIT-2026-06-05.md`.

Once content is in `discovery.content_item` (PG), `forums` + `discovery` are two schemas in the same
`looth` DB → the Hub feed reads both in **one query**, no duplication, no data moved twice.

## Step 2 — add content as a Hub feed source
- Extend the Hub's feed query (`bb-mirror/web/forums/_feed.php`) to **UNION** forum activity +
  `discovery.content_item`, sorted together by recency / hot.
- Respect the existing sort tabs (New/Old/Hot) across the merged set.

## Step 3 — content cards in the Hub renderer
- Render a **content card** variant alongside forum-thread cards (reuse the card styling): thumbnail,
  title, type/tier badge, author, → links to the standalone article/video page.
- Keep the Hub's toggles (compact / text-size / color-theme) working across both card types.

## Gating
Content is **tier-gated** (server-side absence — gated items don't appear for users below the tier);
forum posts are **membership-gated**. The feed mixes both — confirm a below-tier viewer sees the right
subset (no gated content leaking, no forum posts hidden wrongly). Both gate server-side already; just
verify the merged read.

## Decide with Ian (via coordinator) — don't guess
- **Naming/routing:** does the Hub stay "The Hub" with content folded in, or become the unified feed
  (e.g. served at `/stream/` too)? Product call — flag it.

## Verify (dev)
Unified feed renders forum threads + content cards interleaved by time; sort tabs work across both;
toggles work; gating correct for anon / lite / pro; content cards deep-link to the standalone pages;
stays as fast as the Hub today (97 Lighthouse — loop perf-czar).

## Report back
`DONE · FILES · VERIFIED (unified feed + gating + perf) · DECISION-NEEDED (naming/route) · BLOCKED`.
