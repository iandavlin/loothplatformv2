# `<name>` block

*Replace this entire template with the actual block name. This file lives at `docs/blocks/<name>.md` and is the design doc for the block. It is mandatory — the scaffold tool refuses to create the block's directory without it.*

## Purpose

One sentence. What is this block for? Who uses it?

> Example: "Image with optional caption underneath. Used as the primary visual element in image-driven articles."

## Content shape

What data does this block hold? This becomes the `schema.props` in the manifest.

| Prop | Type | Required | Default | Description |
|---|---|---|---|---|
| `id` | integer | yes | — | Attachment ID |
| `url` | string | no | — | Image URL (resolved from id if absent) |
| `alt` | string | no | "" | Alt text for accessibility |
| `caption` | string | no | "" | Caption text below the image; empty = no caption |
| `number` | string | no | "" | Optional sequence badge text (e.g., "001") |
| `variant` | enum | no | "stacked" | One of: stacked, overlay |

## Visual reference

Drop a sketch, screenshot, or wireframe here. Even a rough one. If you can't picture the block, the manifest will be wrong.

```
┌─────────────────────────────────────────┐
│                                         │
│       [001]                             │
│                  [ image ]              │
│                                         │
├─────────────────────────────────────────┤
│  Caption text in serif italic, centered │
└─────────────────────────────────────────┘
```

(Or: `screenshots/<name>-default.png`, `screenshots/<name>-overlay.png`, etc.)

## Variable contract

Which CSS variables does the block expose? Why those and not others?

### Container

- `padding` — block-level padding
- `margin-block` — top/bottom margin
- `bg` — background color
- `border` — composite border shorthand
- `radius` — border radius
- `shadow` — box shadow

### Text

- `color` — caption text color
- `font-family` — caption font
- `font-size` — caption font size
- `font-weight` — caption font weight
- `line-height` — caption line height
- `letter-spacing` — caption letter spacing
- `text-align` — caption alignment

Note any vars NOT exposed and why (e.g., "image dimensions are intrinsic — we never override them via CSS, the image's aspect ratio is the source of truth").

## Defaults

What does it look like out of the box?

### Container defaults

```json
{
  "padding": "12px 16px",
  "margin-block": "16px",
  "bg": "var(--lg-cream)",
  "border": "1px solid var(--lg-sage-3)",
  "radius": "8px",
  "shadow": "none"
}
```

### Text defaults

```json
{
  "color": "var(--lg-ink)",
  "font-size": "20px",
  "font-weight": "400",
  "text-align": "left"
}
```

## Variants

If this block has named variants, document each one here.

### `overlay`

Caption text overlays the image (no separate caption row).

```json
{
  "extends": "defaults",
  "container": { "bg": "transparent", "border": "none", "padding": "0" },
  "text":      { "color": "#fff" }
}
```

Use when: featured-image hero where the caption is part of the artwork.

## Editor affordances

How does this block behave in the editor?

| Affordance | Setting | Notes |
|---|---|---|
| Insertable? | yes | Appears in the "+" menu |
| Inline-editable props | `caption`, `number` | Both become contenteditable, blur-save sends a patch |
| Custom picker | `image` | Opens the WP media library, returns an attachment ID |
| Pill buttons | edit, tier, delete | No edit-link (this isn't an embed); no ratio (image's intrinsic ratio is preserved) |

## Accessibility notes

- `alt` is required (validator enforces non-empty unless the image is purely decorative — set `alt=""` explicitly).
- Sequence number badge has `aria-label="Figure {number}"`.
- Caption is in a `<figcaption>` element associated with its `<figure>`.
- Color contrast: caption text against background must meet WCAG AA 4.5:1. Default values do.

## Opt-outs

Does this block opt out of any context normalization?

- ✅ Participates in `columns` normalization (caption padding/border/bg stripped inside a column slot, since the column gap handles spacing).
- ❌ Does NOT participate in `hero-overlay` normalization — the `overlay` variant is the intentional in-hero treatment.

## Cross-block interactions

Does this block care about being next to specific other blocks?

> Example: "When followed by another image block, the margin collapses. Handled by the `gap` on the parent `<article>`, not by per-block margin rules."

## Open questions

If anything about the design isn't settled, list it here. Each question gets resolved before the block ships.

- Should the sequence badge support custom Unicode (e.g., "①") or just strings? Currently allows any string.
- Should overlay variant support a darken-gradient behind the caption? Defer until a real article needs it.

---

**See also**
- [BLOCKS.md](../BLOCKS.md) — block index
- [MANIFEST.md](../MANIFEST.md) — the contract format this doc maps to
- [BLOCK-ONBOARDING.md](../BLOCK-ONBOARDING.md) — process for going from this doc to a shipped block
