# Live deploy — lg-layout-v2 + legacy importer

Two plugins ship. Zero theme files change. Every step has a one-line rollback.

## What goes where

| Path on live | Where it comes from |
|---|---|
| `wp-content/plugins/lg-layout-v2/` | rsync `lg-layout-v2/` from this repo |
| `wp-content/plugins/lg-legacy-import/` | rsync `lg-legacy-import/` from this repo |

That's it. The BuddyBoss child theme stays untouched on live.

## Deploy order

Each step is independently reversible. Walk them in order, pause between each to spot-check.

### Step 1 — Activate `lg-layout-v2` (additive, zero visual change)

```bash
# from live's wp-content/plugins/, after rsyncing
wp plugin activate lg-layout-v2
```

What happens: nothing visible yet. The plugin only intercepts posts that have
`_lg_layout_v2` postmeta. No live posts have that yet, so every page renders
exactly as it did before.

What to watch:
- PHP error log (`tail -f` on live's php-fpm log) for an hour
- The dashboard for activation errors
- A handful of Elementor posts to confirm they look identical

**Rollback:** `wp plugin deactivate lg-layout-v2`

### Step 2 — Site-wide header takes over

This happens automatically as a side-effect of step 1. The plugin hooks
BuddyBoss's `buddyboss_header` action and removes BB's masthead callbacks,
replacing them with our lg-site-header markup. **Every page on live** —
homepage, /members/, /forums/, Elementor landing pages, regular posts — now
shows the new header.

The outer `<header id="masthead">` element BB ships still wraps the chrome
(so BB's broader page CSS isn't disturbed); only the *contents* of that
element are ours, plus a class swap from `site-header--bb` to `site-header--lg`
to neutralize BB's masthead-specific styles.

Spot-check checklist:
- [ ] `/` — homepage. Logo + nav + sign-in CTA render. No BB masthead doubling.
- [ ] `/members/` — logged-in BP profile pages still lay out. Avatars + tabs visible.
- [ ] `/forums/` — bbPress thread list renders, sidebar (if any) intact.
- [ ] `/activity/` — BuddyPress activity stream loads.
- [ ] Any Elementor landing page — sticky/hero positioning still works (the
      new header is `position: fixed; height: 64px;` with `body { padding-top: 64px }`
      via `body.lg-page--v2` only on v2 posts; other pages may or may not need
      that padding depending on the Elementor template).
- [ ] Hamburger icon shows ≤960px viewport; primary nav shows >960px.
- [ ] Wheel-scroll triggers the compact-on-scroll backdrop after 60px.

**Rollback:** `wp plugin deactivate lg-layout-v2` returns BB's masthead.

### Step 3 — Activate `lg-legacy-import` (additive, no auto-conversion)

```bash
wp plugin activate lg-legacy-import
```

What happens: a new metabox appears on the classic-editor view of every
**legacy** `post-imgcap` post (i.e., one without `_lg_layout_v2`). It reads:

> **LG Legacy → v2 Conversion**
>
> *Walks this post's ACF repeater + body content and generates a v2 layout.
> N blocks will be produced.*
>
> [Download JSON]  [Preview ▾]  [Apply →]

- **Download JSON** — streams `post-<id>.json` to your browser. Open it in a
  text editor, eyeball the block sequence, hand-edit if needed.
- **Preview** — toggles an inline textarea with the same JSON for quick
  inspection without leaving the page.
- **Apply →** — writes the layout to `_lg_layout_v2` postmeta and bumps the
  v2 cache epoch. After this, the post renders via v2.

This step adds the UI but doesn't convert anything on its own.

**Rollback:** `wp plugin deactivate lg-legacy-import`

### Step 4 — Convert one post, manually, as a smoke test

Pick a representative legacy post (a Strat-like article with mixed ACF rows).
On live:

```bash
# Inspect the intermediate first — no writes, just shows what Extractor sees.
wp lg-legacy inspect <post_id>

# Or by live URL — slug + CPT match a local post:
wp lg-legacy inspect https://loothgroup.com/post-imgcap/<slug>/

# Generate the layout, write to disk so you can eyeball it:
wp lg-legacy export <post_id> --out=/tmp/legacy-preview

# Happy with it? Apply:
wp lg-legacy export <post_id> --apply

# OR use the "Apply →" button in wp-admin.
```

Spot-check that one converted post:
- [ ] Renders via v2 (look for `<header class="lg-post-header">` markup)
- [ ] Image blocks present, captions read correctly
- [ ] Gallery blocks (if any) render with the right tile count
- [ ] Embed blocks (YouTube / Instagram) render
- [ ] post-header chips show tier + categories
- [ ] post-footer "Keep reading" related grid renders

**Rollback (per post):** `wp post meta delete <id> _lg_layout_v2`
The original ACF data is untouched; deleting the v2 meta returns the post
to its legacy renderer.

### Step 5 — Bulk convert (when you're confident)

```bash
# Dry-run first: writes layouts to disk, doesn't apply.
wp lg-legacy export_all --out=/tmp/legacy-export

# Skim a sample of the *.json files in there. Then apply:
wp lg-legacy export_all --apply
```

`export_all` skips any post that already has `_lg_layout_v2`, so partial
applies are safe to resume.

**Rollback (bulk):**
```bash
wp post meta delete --post_type=post-imgcap _lg_layout_v2  # WP-CLI supports this with a loop
# Or in SQL: DELETE FROM wp_postmeta WHERE meta_key='_lg_layout_v2'
```

## Where the conversion JSON comes from

Three ways to get the v2 layout JSON for a legacy post:

1. **wp-admin "Download JSON" button** — per-post, no CLI needed.
2. **`wp lg-legacy export <id> --out=DIR`** — writes `layout.json` + `extracted.json` (raw intermediate, useful for debugging) + `post.md` (human-readable mirror, useful if the layout needs hand-tweaking via the `write-article-v2` skill).
3. **`wp lg-legacy inspect <id>`** — prints just the Extractor intermediate to stdout. Skips the Mapper entirely. Use when a converted post looks wrong and you want to see what the input was.

## Caveats

- **Uploads sync** — the lg-layout-v2 plugin reads attachment URLs from WP.
  If a file is missing from disk, the new image-block frame shows a sage
  dashed placeholder (built in to handle dev's missing-uploads case).
  Once the file lands, the placeholder vanishes automatically. On live this
  shouldn't matter since uploads aren't lost.
- **Elementor coexistence** — Elementor pages remain untouched. The plugin
  only intercepts posts with `_lg_layout_v2` postmeta. CSS isolation (the
  dequeue-everything pass in [Isolate.php](src/Isolate.php)) also only runs
  on those posts.
- **`patreon-level` → tier mapping** — see [Extractor.php:TIER_MAP](../lg-legacy-import/src/Extractor.php). Confirm the table matches what your
  Members plugin actually stores on live. If it differs, edit the map and
  re-run `export_all --apply`.
- **No live DB connection from dev** — the URL-resolution feature in the
  importer (`wp lg-legacy export https://loothgroup.com/post-imgcap/X/`)
  only works because dev is a slug-for-slug snapshot of live. On live, just
  pass the post ID.
