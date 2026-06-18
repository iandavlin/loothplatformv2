# `heading` block

In-body heading. H2–H4 with optional class. The article title H1 is handled by the post-title pseudo-block; this is for section headings inside the body of the article.

## Purpose

Section breaks inside an article. Provides semantic `<h2>` / `<h3>` / `<h4>` tags so screen readers and SEO get a real outline, instead of forcing authors to embed headings inside a `wysiwyg` block.

## Content shape

| Prop | Type | Required | Default | Description |
|---|---|---|---|---|
| `text` | string | yes | `""` | The heading's text content. |
| `level` | enum | no | `"h2"` | One of: `h2`, `h3`, `h4`. |

H1 is excluded because the post title pseudo-block owns it; allowing it would let authors emit two H1s and break the outline.

## Visual reference

```
─────────────────────────
A Section Heading
─────────────────────────
```

By default, no chrome. Just the heading text in the brand serif at the level's natural scale.

## Variable contract

### Container

- `padding`, `margin-block`, `bg`, `border`, `radius`, `shadow`

### Text

- `color`, `link-color`, `font-family`, `font-size`, `font-weight`, `line-height`, `letter-spacing`, `text-align`

## Defaults

### Container

```json
{
  "padding": "0",
  "margin-block": "24px 12px",
  "bg": "transparent",
  "border": "none",
  "radius": "0",
  "shadow": "none"
}
```

Asymmetric margin-block (more top, less bottom) creates a natural break before the heading and tight coupling to the prose that follows.

### Text

```json
{
  "color": "var(--lg-charcoal)",
  "link-color": "var(--lg-sage)",
  "font-family": "var(--lg-font-serif)",
  "font-size": "28px",
  "font-weight": "600",
  "line-height": "1.25",
  "letter-spacing": "-0.01em",
  "text-align": "left"
}
```

Brand serif (Cormorant) by default — sets section headings apart from the sans-serif body copy.

## Editor affordances

| Affordance | Setting | Notes |
|---|---|---|
| Insertable? | yes | |
| Inline-editable props | `text` | Becomes contenteditable in Phase 4. |
| Custom picker | `null` | Plain text input + enum select. |
| Pill buttons | edit, tier, delete | Standard set. |

## Accessibility notes

- Always renders a real `<h{level}>` element — no `<div role="heading">` shortcuts.
- Author-controlled text is escaped, not HTML.

## Opt-outs

- ✅ Participates in `columns` normalization.

---

**See also**
- [BLOCKS.md](../BLOCKS.md) — block index where this block is listed
- [_template.md](_template.md) — design doc template
