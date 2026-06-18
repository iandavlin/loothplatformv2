# Lane briefing — lg-layout-v2 engine (2026-06-07)

You own the **lg-layout-v2 layout engine** (`/home/ubuntu/projects/lg-layout-v2/`, currently **0.1.65**) —
the block system that renders all managed CPTs (articles, videos, loothprints) from a `_lg_layout_v2` JSON
layout. (Note: `SESSION-HANDOFF.md` is stale at 0.1.54/May-23 — the engine has moved since.)

Sanity-check the box: `curl -s ifconfig.me` → `50.19.198.38` = act locally, do NOT SSH. Commit small by
pathspec; coordinator reviews, **git-tsar pushes — no silent pushes**.

## TASKS (priority order)

### 1. 🔴 Download block renderer — converted loothprints show NO download (BUG, ~165 posts)
**Diagnosed (coordinator 6/7):** the engine has **no `download` block renderer** (blocks are callout/
columns/divider/embed/gallery/image/paywall/post-header/post-footer/section-heading/transcript/wysiwyg —
no "download"). The loothprint conversion emitted bare `{"type":"download","file_id":N}` blocks, but the
engine only renders downloads as a **`callout` block, `variant:'files'`** (its own builder does this at
`lg-layout-v2/src/Plugin.php:378`). So the bare `download` blocks match no renderer → **render nothing**,
across ~165 converted loothprints. NOT gating (tier is public), NOT missing data (the file + resolved URL
are baked in the blob's `media` map; the materializer's walker already collects `file_id`).
- **FIX:** add a `download` block renderer (`lg-layout-v2/blocks/download/render.php` — doesn't exist) that
  renders the file as a download button/callout, resolving `file_id` → URL **via the blob's media map**
  (the standalone path has no WP, so don't call `wp_get_attachment_url` at render — the URL is pre-baked).
  Mirror the callout/`files` shape. Land in BOTH the engine AND the standalone vendor copy
  (`archive-poc/standalone/engine/blocks/download/`). Bump + deploy → all 165 light up, **no re-conversion**.
- **Verify:** `https://dev.loothgroup.com/loothprint/neck-side-crack-jig/` (post 70814, `file_id` 70813 =
  `NeckSideCrackJig-1.zip`, a real 2 MB file) renders a working download. Currently 0 download elements.

### 2. Author links → Hub author-search (not the WP author archive)
Ian (6/7): the author byline + avatar links in v2 layouts currently point at the **WP author archive**;
make them point at **the Hub, filtered to that author** instead.

**Render point (one variable, reused by both links):** `lg-layout-v2/blocks/post-header/render.php` —
`$author_archive_url` is computed at **lines 158–161** (`get_author_posts_url($author_id)` → WP
`/author/{slug}/`, through the `lg_layout_v2_author_archive_url` filter) and reused by the **byline link**
(`~231`, key `author_archive`) and the **avatar link** (`~414`). Change that one URL.

**Target = the Hub author filter.** The Hub matches authors by **NAME** (CSV), not slug or ID —
`_hub-filters.php:179` (`'authors' => $csv('author')`) / `_filter-rail.php:27` (`?author=<names>`). So
build `/hub/?author=<urlencode($author_name)>` using the byline's rendered `$author_name`. (Keep the
"All posts by {name}" aria-label.)

**⚠️ TWO copies — change both.** The archive-poc **standalone** renderer vendors its own copy of the
engine, so the same edit must land in `archive-poc/standalone/engine/blocks/post-header/render.php` (or be
re-vendored). The standalone path doesn't run WP filters, so change the **URL construction directly** in
render.php — don't rely only on the `lg_layout_v2_author_archive_url` filter (that won't fire standalone).
Coordinate the re-vendor with the archive-poc lane.

**Verify:** an article byline/avatar link now goes to `/hub/?author=<name>` and the Hub feed filters to
that author (both the WP-template `?lg_edit` render AND the standalone render). Bump version + deploy
(versioned zip — `LIVE-DEPLOY.md`).

### 3. CPT type badge in the post-header — ALL CPTs (Ian 6/7)
Add a **type badge** (Loothprint / Article / Video / Document / Loothcut / …) to the v2 **post-header** for
**every** managed CPT. Reuse the Hub card's kind→label vocabulary for consistency — the `$kind_label` map
in `bb-mirror/web/forums/_feed.php` (article→Article, video→Video, loothprint→Loothprint, sponsor-post→
Sponsor, etc.). Place it in the post-header near the byline/title. The CPT/kind is available in
`post-header/render.php`. Both copies (engine + standalone vendor) + bump/deploy.

## Engine ownership (general)
You own `lg-layout-v2/` — blocks, `src/`, `shell.css` (per-level overrides live OUTSIDE `@layer block-shell`
or block-defaults clobber them — [[feedback_lg_layout_v2_level_overrides]]), the manifest, validation.
Deploy = versioned zip → live ([[reference_lg_layout_v2_deploy]] / `LIVE-DEPLOY.md`).

## Report back (to coordinator)
`DONE · FILES · TEMPLATE DRAFT (for Ian) · VERIFIED (wp validate) · BLOCKED (on loothprint confirms?)`.
Report your session ID + outliner title for CHATS-MENU + lineage.
