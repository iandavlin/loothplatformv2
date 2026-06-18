# Migration

How legacy WordPress posts (ACF-authored, Elementor-authored, raw post_content) become v2 layout JSON. The migration runs once per post and writes `_lg_layout_v2` post meta alongside the original data — nothing legacy gets deleted.

## The shape of the problem

The Looth Group WP install has multiple authoring systems for similar content:

- **ACF "Article" field group** — `post-imgcap` and `freebie-article`. Preamble WYSIWYG + repeater of {image, gallery, text, oembed} rows. ~52 posts.
- **ACF "Loothprint" / "Loothcut" / "Document" / "Member Benefit" / etc.** — each CPT has its own ACF group with bespoke fields. ~150+ posts each in some cases.
- **Raw post_content** — older posts with Elementor data or plain HTML in `post_content`. ~1108 posts have `_elementor_data` still.
- **v1 `_lg_layout` JSON** — ~9 dev test articles in the v2-style block JSON.

v2 needs to read all of these and emit a single canonical format: a v2 layout JSON in `_lg_layout_v2` post meta.

## The two-step approach

Building per-CPT smart importers is brittle and slow. Instead:

1. **Dumb exporter** walks any post and writes a fat JSON bundle containing everything the post has. Universal, ~150 lines of PHP.
2. **Translator** reads the bundle and emits a v2 layout JSON. Per-CPT (because the structural mapping differs). Can be a script, a Claude skill, or a person — they all read the same bundle format.

Decoupling extraction from translation means we can iterate on the translator without re-querying the DB.

```
WP post
   │
   ▼
[Exporter]  bin/export-bundle.php --post-id=69206
   │
   ▼  storage/exports/<cpt>-<id>.json
   │  { post, author, taxonomies, attachments, acf, postmeta, media_resolved, rendered_html }
   │
   ▼
[Translator]  bin/translate-bundle.php --bundle=... --cpt-template=post-imgcap
   │
   ▼  storage/layouts/<cpt>-<id>.json
   │  { "schema": 1, "blocks": [...] }
   │
   ▼
[Importer]  bin/import-layout.php --post-id=69206 --layout=...
   │
   ▼  wp_postmeta._lg_layout_v2 = <json>
   │
   ▼
v2 renderer takes over
```

## Bundle format

The exporter writes one JSON file per post. Inherits from v1's `BundleExporter` with two additions: `media_resolved` (pre-resolved attachment metadata for any ID referenced anywhere) and `rendered_html` (the post as it currently renders, for cross-checking).

```json
{
  "exported_at": "2026-05-20T18:49:15+00:00",
  "post": {
    "ID": 69206,
    "post_type": "post-imgcap",
    "post_title": "Battle-Scarred — A '57 Strat Goes Under the Knife",
    "post_name": "battle-scarred-...",
    "post_status": "publish",
    "post_date": "2026-04-12 13:24:00",
    "post_modified": "2026-05-20 18:00:00",
    "post_author": 5,
    "post_excerpt": "...",
    "post_content": "<raw HTML preamble or Elementor JSON>",
    "permalink": "..."
  },
  "author": {
    "ID": 5,
    "display_name": "...",
    "user_login": "...",
    "user_email": "...",
    "roles": ["author"],
    "meta": {
      "_author_about": "...",
      "_author_instagram": "...",
      "_author_linktree": "...",
      "_author_image": "12345"
    }
  },
  "taxonomies": {
    "tier": [{ "id": 42, "slug": "looth-pro", "name": "Looth Pro" }],
    "post_tag": [...],
    "category": [...],
    "series": [...]
  },
  "thumbnail": { "ID": 67890, "url": "...", "alt": "..." },
  "attachments": [
    { "ID": 12345, "url": "...", "mime": "image/jpeg", "title": "...", "alt": "..." },
    ...
  ],
  "media_resolved": {
    "12345": { "id": 12345, "url": "...", "alt": "...", "sizes": {...}, "mime": "image/jpeg", "filesize": 234567 },
    "67890": { ... }
  },
  "acf": {
    "post_content": "...",
    "img_cap_images_and_captions_repeater": [
      {
        "add_a_single_image__": true,
        "img_cap_repeater_image": { "id": 12345, "url": "..." },
        "add_an_image_gallery__": false,
        "gallery43": [],
        "add_a_text_area__": true,
        "img_cap_repeater_text": "<p>caption text</p>",
        "add_a_youtube_or_instagram_link__": false,
        "repeater_oembed": ""
      },
      ...
    ],
    "category": [...]
  },
  "postmeta_raw": {
    "_thumbnail_id": "67890",
    "_lg_layout": null,
    "_elementor_data": null,
    ...
  },
  "rendered_html": "<article>...what the post looks like today...</article>"
}
```

The bundle is fat by design — translators should never need to make a second query.

## Translator contract

A translator takes one bundle and produces one v2 layout JSON. The output shape:

```json
{
  "schema": 1,
  "_meta": {
    "source_bundle": "post-imgcap-69206.json",
    "translated_at": "2026-05-20T19:00:00+00:00",
    "translator": "post-imgcap-v1",
    "notes": ["row 3 had both image and oembed toggled — defaulted to image"]
  },
  "blocks": [
    { "type": "image", "id": "b_a1", "image_id": 12345, "alt": "...", "caption": "..." },
    { "type": "prose", "id": "b_a2", "html": "<p>...</p>" },
    { "type": "embed", "id": "b_a3", "url": "https://www.youtube.com/watch?v=...", "ratio": "16x9" },
    ...
  ]
}
```

Translators MUST:
- Validate the produced layout against the manifest schema (call `bin/validate-layout.php`).
- Surface uncertainty as `_meta.notes` rather than silently guessing.
- Be deterministic: same bundle → same output, every time.
- Be idempotent: re-translating a previously-translated post should produce identical output.

Translators MAY:
- Be PHP scripts, JS scripts, Claude skills, or human-written JSON. The bundle format doesn't care.
- Drop or merge legacy fields the v2 manifest doesn't support, with a note in `_meta.notes`.
- Make CPT-specific decisions (Loothprint translator knows about download blocks, post-imgcap translator knows about repeater→image-caption).

## Per-CPT translators

One translator script per source CPT. Lives at `bin/translators/<cpt>.php`. Each one understands the ACF group's quirks and emits the appropriate block sequence.

| Source CPT | Translator | Notes |
|---|---|---|
| `post-imgcap` | `bin/translators/post-imgcap.php` | Preamble → prose; repeater rows → {image, gallery, prose, embed} per row toggles |
| `freebie-article` | `bin/translators/post-imgcap.php` | Same ACF group, same translator |
| `loothprint` | `bin/translators/loothprint.php` | Title prose → download (with 3D file) → gallery → embed → resource (parts list) |
| `loothcut` | `bin/translators/loothcut.php` | Similar to loothprint but for CNC files |
| `document` | `bin/translators/document.php` | Title + download |
| `member-benefit` | `bin/translators/member-benefit.php` | Hero image + intro prose + details prose + download/link |
| `post-type-videos` | `bin/translators/video.php` | Featured embed + description prose + related-posts |
| `shorty` | `bin/translators/shorty.php` | Single prose block from post_content |
| `*` (Elementor) | `bin/translators/elementor.php` | Best-effort flatten of `_elementor_data` to prose/image blocks |

Translators that share an ACF group can share a script.

## Author meta cleanup

The DB has `_author_link_tree` (with underscore in `link_tree`) and `_author_linktree` (no underscore) — two spellings of the same field. v2 canonicalizes on **`_author_linktree`**.

The migration includes a one-time WP-CLI step:

```bash
wp lg-layout-v2 cleanup-author-meta --dry-run
wp lg-layout-v2 cleanup-author-meta
```

For each user:
1. Read both `_author_link_tree` and `_author_linktree`.
2. If only one is set, copy to `_author_linktree`, delete the other.
3. If both are set, prefer `_author_linktree` (no underscore), delete `_author_link_tree`. Log to a report.
4. If both are set and they differ, log the conflict, leave both alone, surface in the report.

The report lands at `storage/migration-reports/author-meta-cleanup.json`. Review before deleting any data.

## Migration runner

The Phase 5.5 WP-CLI command runs the full pipeline per CPT:

```bash
wp lg-layout-v2 migrate --cpt=post-imgcap --dry-run
wp lg-layout-v2 migrate --cpt=post-imgcap
```

For each post in the CPT:
1. Run exporter → bundle JSON at `storage/exports/`.
2. Run appropriate translator → layout JSON at `storage/layouts/`.
3. Validate layout against manifest schemas.
4. Write `_lg_layout_v2` post meta.
5. Write per-post migration report at `storage/migration-reports/<cpt>-<id>.json` recording any notes / warnings.

The migration is **non-destructive**: original ACF fields, original `post_content`, original `_lg_layout` (if any) all stay. The new system reads `_lg_layout_v2` and renders from it; legacy data is ignored but preserved.

To roll back: delete `_lg_layout_v2` post meta. The post goes back to being rendered by the legacy system.

## Switching CPTs onto v2

A CPT switches onto v2 rendering when:
1. The CPT is added to `lg-layout-v2`'s managed CPT list (Plugin constant).
2. The migration runner has been run for all that CPT's posts.
3. A shell template exists at `storage/shells/<cpt>.json`.
4. The v2 plugin is active.

Toggle is intentionally manual per CPT so you can do `post-imgcap` first, validate, then do the next.

## Per-block schema migrations

In-v2 manifest version bumps (per [BLOCK-ONBOARDING.md](BLOCK-ONBOARDING.md) § Versioning) get an entry here. Each entry describes the shape change, how the validator/importer handles old data, and any one-shot rewriter that runs over the existing corpus.

### `columns` v1 → v2 *(early dev, before any production data existed)*

**What changed.** Children moved from a flat array with implicit round-robin distribution to explicit per-column buckets.

**Old shape:**

```json
{ "type": "columns", "cols": 2, "blocks": [child, child, child] }
```

Renderer distributed `child[i]` to column `i % cols`.

**New shape:**

```json
{
  "type": "columns",
  "columns": [
    { "blocks": [child] },
    { "blocks": [child, child] }
  ]
}
```

Column count is `columns.length` (2 or 3). The `cols` prop is gone.

**Why.** Round-robin was unauthorable — the metabox couldn't show which column a child would land in, and inserting/removing rippled positions across the flat list. Buckets give one-to-one author intent ↔ rendered output and make the (forthcoming) front-end DnD between columns a simple array splice.

**Migration handling.** No live data existed when this landed (site survey returned zero columns posts), so no rewriter was written. If a legacy `{ cols, blocks: [...] }` shape is ever seen by the importer or runtime, the appropriate behavior is:

1. Read `cols` (default 2 if missing).
2. Round-robin-distribute the flat `blocks` array into `cols` buckets.
3. Emit the new shape, drop `cols`, drop the flat `blocks`.
4. Save back; log a migration note.

If this surfaces in production data later, add the rewriter at that point — the validator should refuse legacy shape outright until then so silent breakage is impossible.

## Cascade testing the migration

The most important migration test: render the same post under v1 and v2, screenshot both, diff. If the v2 output is visually equivalent to v1, the migration succeeded for that post.

See [TESTING.md#migration-cascade](TESTING.md#migration-cascade) for the workflow.

---

**See also**
- [ARCHITECTURE.md](ARCHITECTURE.md) — the target system the migration produces JSON for
- [MANIFEST.md](MANIFEST.md) — the block contract the translator emits against
- [BLOCKS.md](BLOCKS.md) — the block toolkit the translator can use
- [TESTING.md](TESTING.md) — how migration correctness is verified
- [GLOSSARY.md](GLOSSARY.md) — terms used here (bundle, translator, shell template)
