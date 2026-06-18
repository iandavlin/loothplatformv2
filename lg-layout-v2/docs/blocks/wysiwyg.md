# `wysiwyg` block

Body copy. The block you'll use for ~80% of every article. Replaces v1's separate `text`, `richtext`, and `wysiwyg` blocks — having three near-identical blocks was a UX trap, so v2 collapses them.

## Purpose

A single TinyMCE-edited rich text region. Holds paragraphs, in-body headings (H1–H3), inline emphasis, lists, links, and inline images via the WP media library. Authors who need raw HTML can use TinyMCE's Text tab; `wp_kses_post` on save preserves `<iframe>`, `<table>`, `<video>`, etc.

## Content shape

| Prop | Type | Required | Default | Description |
|---|---|---|---|---|
| `html` | string | yes | `""` | Rich-text HTML. Sanitized with `wp_kses_post` on save. |

The whole block is one HTML blob from TinyMCE. No per-paragraph block boundaries — if an author wants two visually distinct passages of body copy with different styling, they use two `wysiwyg` blocks.

## Visual reference

```
┌────────────────────────────────────────────────────────┐
│  ## In-body heading                                    │
│                                                        │
│  Body paragraph copy in the brand sans, leading 1.6,   │
│  with **bold** and *italic* and [links] in-line.       │
│                                                        │
│  - List item                                           │
│  - List item                                           │
│                                                        │
│  Another paragraph follows after the list.             │
└────────────────────────────────────────────────────────┘
```

No outer frame by default — prose flows naturally inside the article column. Authors can switch to a "boxed" variant for callout-style passages.

## Variable contract

### Container

- `padding`, `margin-block`, `bg`, `border`, `radius`, `shadow`, `gap`

### Text

- `color`, `font-family`, `font-size`, `font-weight`, `line-height`, `letter-spacing`, `text-align`

Same canonical set as `image`. Headings inside the block inherit from the text vars but render at their HTML-tag-default scale — `<h2>` is still larger than `<p>` because the browser stylesheet sets `<h2>` font-size relative to current.

## Defaults

### Container

```json
{
  "padding": "0",
  "margin-block": "16px",
  "bg": "transparent",
  "border": "none",
  "radius": "0",
  "shadow": "none",
  "gap": "0"
}
```

No outer chrome by default — prose is "naked" body copy. Authors who want a callout box switch to the `boxed` variant or override per-instance.

### Text

```json
{
  "color": "var(--lg-ink)",
  "font-family": "var(--lg-font-sans)",
  "font-size": "17px",
  "font-weight": "400",
  "line-height": "1.6",
  "letter-spacing": "normal",
  "text-align": "left"
}
```

Sans by default (brand voice). Authors can switch to serif via the dash per-site or per-block.

## Variants

Not in the first cut. Two natural future variants:

- **`boxed`** — adds the cream bg + sage border + padding (matches `image` default chrome). For callouts and asides.
- **`drop-cap`** — first-letter styling on the first paragraph. Editorial flourish.

Defer until a real article needs them.

## Editor affordances

| Affordance | Setting | Notes |
|---|---|---|
| Insertable? | yes | Appears in the metabox block-type picker |
| Inline-editable props | `html` | The whole block is contenteditable in Phase 4 |
| Custom picker | `rich-text` | TinyMCE in the metabox; in-place TinyMCE in Phase 4 |
| Pill buttons | edit, tier, delete | Standard set |

## Accessibility notes

- Author-controlled HTML — `wp_kses_post` on save strips scripts, event handlers, dangerous attributes. Authors are trusted (admin role) but not unboundedly.
- Headings inside the block use their HTML tags (`<h2>`, `<h3>`) — screen readers get the right hierarchy.
- Color contrast: default `--lg-ink` on `transparent` parent must meet WCAG AA against whatever the parent's background is. Default page bg is light, so this works.

## Opt-outs

- ✅ Participates in `columns` normalization. Prose inside a column slot has its bg/border/padding stripped because the column gap handles spacing.

## Cross-block interactions

- Adjacent prose blocks: each emits its own `margin-block`, so they accumulate. The article wrapper's vertical rhythm is the sum.
- Prose inside `columns`: column normalization strips chrome; the prose just contributes text.

## Open questions

- Should we strip `<style>` and `<script>` even more aggressively than `wp_kses_post` does? Probably no — the post-edit gate is admin-cap, and the dash already controls chrome.
- TinyMCE toolbar config: which buttons? First cut: bold, italic, link, h2, h3, ul, ol. Defer block-quotes and code until needed.

---

**See also**
- [BLOCKS.md](../BLOCKS.md) — block index where this block is listed
- [_template.md](_template.md) — design doc template
