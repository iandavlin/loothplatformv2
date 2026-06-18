# Block inventory

The full block toolkit for lg-layout-v2. One paragraph per block. Per-block design docs (with visual references, variant details, and editor affordances) live at `docs/blocks/<name>.md`.

## Consolidations from v1

Three v1 blocks were merged. New names + reasoning:

| v1 | v2 | Why |
|---|---|---|
| `text`, `richtext`, `wysiwyg` | `wysiwyg` | One TinyMCE-edited block. Toolbar (h2, h3, bold, italic, lists, link, blockquote) + media insert + raw-HTML mode via TinyMCE's Text tab. Three near-identical v1 blocks was a UX trap. Visual variants (boxed/naked) live in the manifest, not as separate blocks. |
| `image`, `image-caption` | `image` | One block, optional `caption` prop. Empty caption = renders without one. Removes "wait which one" mistakes. |

Open question: merge `byline` + `post-meta` into a single `meta` block with variants? Currently kept separate. Defer until we know what the deploy author workflow needs.

New blocks:

| Name | Why |
|---|---|
| `download` | Fills the gap for `loothprint`, `loothcut`, `document`, `member-benefit`. File + optional secondary link + tip jar + license + title/desc. |

## Toolkit

24 blocks total. Grouped by role.

### Structural

- **`heading`** — H1–H6 with optional class. Title-pseudo-block handles the article H1 specifically; this block is for in-body headings.
- **`divider`** — Plain `<hr />` with optional spacing variants.
- **`columns`** — 2–4 child container. Collapses to stack on mobile. Children get column-context normalization via the `context` CSS layer.
- **`paywall`** — Tier-gated cut. Renderer stops walking the layout below this block if the viewer doesn't satisfy the declared tier. Admins see a "preview as: public/looth-lite/looth-pro" badge.

### Text

- **`wysiwyg`** — TinyMCE-edited rich text. Toolbar: bold/italic/lists/link/h2/h3/blockquote + WP media insert. Replaces v1's `text` + `richtext` + `wysiwyg`. Most posts use this for body copy. Authors can drop to TinyMCE's Text tab for raw HTML when needed; `wp_kses_post` preserves `<iframe>`, `<table>`, `<video>`. Visual variants like `boxed` (cream bg, sage border) are manifest-declared.

### Media

- **`image`** — Single image with optional `caption`. Optional sequence badge (e.g., "001"). Replaces v1's `image` + `image-caption`. Caption empty = renders without one.
- **`gallery`** — Grid of images with click-to-lightbox. Columns prop, ratio prop.
- **`embed`** — YouTube, Vimeo, Twitter, Instagram via WP oEmbed (with manual blockquote fallback for Instagram). Aspect-ratio reservation guarantees zero CLS. Defer-mount via IntersectionObserver for below-the-fold embeds.
- **`carousel`** — Scroll-snap horizontal carousel of images. CSS-only, no JS library. Items array.

### Editorial

- **`pull-quote`** — Italic large-type aside. Variants for visual styling.
- **`download`** — *New in v2.* File attachment (any mime type) + optional secondary link (e.g., Onshape, mirror) + optional tip jar URL + license indicator + title/description. Used by Loothprint, Loothcut, Document, Member Benefit posts.
- **`resource`** — Multi-variant container for related items: downloads, products, guests, generic links. Has `variant` prop selecting which sub-template to use. Items array of `{label, url, description, external, ...}`.
- **`transcript`** — *New in v2.* Collapsible long-form transcript body wrapped in a native `<details>` / `<summary>` accordion. Body stays in the DOM at all times — crawlers index every word regardless of collapsed state. Used on video/podcast posts beneath the `embed`.

### Event

- **`event-header`** — *New in v2.* When/where/attend strip for the `event` CPT: a date pill + full date/time line, region chip, and event-type chips, plus a tier-gated virtual-attend CTA. Reads live from event postmeta + taxonomies (the showrunner Sheet→CPT pipeline owns the data) with explicit-prop overrides for static bake / snapshot tests. Header details are public; only the Zoom CTA gates (against the event's `tier` taxonomy term, i.e. `$ctx['post_tier']`), so under-tier viewers see an "upgrade to join" card and the Zoom URL is never emitted into their DOM.

### Author / meta

- **`hero`** — Full-bleed featured image with glass author panel overlay. Pulls from post thumbnail + author info. One per post by convention.
- **`byline`** — Compact meta strip below the hero. Author chip + date + read time + taxonomies.
- **`post-meta`** — Date + taxonomy chips, lighter than byline. Used in footer contexts.
- **`tags`** — "Filed under" chip row of post tags.
- **`share`** — Share row: copy link, X, Facebook, email. Post-level.
- **`social-links`** — Author's personal social icons (Instagram, YouTube, website, Linktree). Pulled from user meta.
- **`author-card`** — Avatar + name + role tagline + bio excerpt. Used in post footers.

### Navigation / discovery

- **`series-nav`** — Multi-part nav: "Part X of N" + next/prev button. Only renders if the post is in a series taxonomy.
- **`related-posts`** — Mixed carousel of related items: shared tags → same author → recent CPT. Scored and bucketed. Has its own cache (`_lg_related_pool` post meta).

### Composition utilities

- **`post-body`** — Stub block resolved by the renderer to inject the layout tree from `_lg_layout_v2` post meta. Used in CPT shells (`storage/shells/<cpt>.json`) to mark where the post's own blocks slot into the wrapper layout.
- **`include`** — Stub block that resolves a partial from `storage/partials/<ref>.json`. Used for reusable footer fragments, common headers, etc.

## Per-block design docs

Each block has a design doc at `docs/blocks/<name>.md`. Use [blocks/_template.md](blocks/_template.md) when creating a new one. See [BLOCK-ONBOARDING.md](BLOCK-ONBOARDING.md) for the full process.

The design docs (initially) are stubs; they fill out as each block is implemented in Phase 1.

| Block | Design doc | Status |
|---|---|---|
| event-header | [blocks/event-header.md](blocks/event-header.md) | implemented |
| heading | [blocks/heading.md](blocks/heading.md) | implemented |
| divider | [blocks/divider.md](blocks/divider.md) | implemented |
| columns | [blocks/columns.md](blocks/columns.md) | stub |
| paywall | [blocks/paywall.md](blocks/paywall.md) | stub |
| wysiwyg | [blocks/wysiwyg.md](blocks/wysiwyg.md) | implemented |
| image | [blocks/image.md](blocks/image.md) | stub |
| gallery | [blocks/gallery.md](blocks/gallery.md) | stub |
| embed | [blocks/embed.md](blocks/embed.md) | implemented |
| carousel | [blocks/carousel.md](blocks/carousel.md) | stub |
| pull-quote | [blocks/pull-quote.md](blocks/pull-quote.md) | stub |
| download | [blocks/download.md](blocks/download.md) | stub |
| resource | [blocks/resource.md](blocks/resource.md) | stub |
| hero | [blocks/hero.md](blocks/hero.md) | stub |
| byline | [blocks/byline.md](blocks/byline.md) | stub |
| post-meta | [blocks/post-meta.md](blocks/post-meta.md) | stub |
| tags | [blocks/tags.md](blocks/tags.md) | stub |
| share | [blocks/share.md](blocks/share.md) | stub |
| social-links | [blocks/social-links.md](blocks/social-links.md) | stub |
| author-card | [blocks/author-card.md](blocks/author-card.md) | stub |
| series-nav | [blocks/series-nav.md](blocks/series-nav.md) | stub |
| related-posts | [blocks/related-posts.md](blocks/related-posts.md) | stub |
| post-body | [blocks/post-body.md](blocks/post-body.md) | stub |
| include | [blocks/include.md](blocks/include.md) | stub |

---

**See also**
- [MANIFEST.md](MANIFEST.md) — the contract each block declares
- [BLOCK-ONBOARDING.md](BLOCK-ONBOARDING.md) — process for adding a block to this list
- [ARCHITECTURE.md](ARCHITECTURE.md) — where these blocks slot into the cascade
- [MIGRATION.md](MIGRATION.md) — how legacy ACF posts map to these blocks
- [blocks/_template.md](blocks/_template.md) — design doc template
- [GLOSSARY.md](GLOSSARY.md) — terms used here (variant, context override, manifest)
