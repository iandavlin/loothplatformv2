# `embed` block

Third-party media embed via URL. YouTube, Vimeo, Twitter/X, SoundCloud, etc. The block reserves space for the embed's aspect ratio so there's zero CLS as the iframe loads.

## Purpose

The editorial "drop a video / tweet / song here" block. Author pastes a URL; render time resolves the embed HTML via WP's oEmbed cache and emits an iframe wrapped in an aspect-ratio container.

## Content shape

| Prop | Type | Required | Default | Description |
|---|---|---|---|---|
| `url` | string | yes | `""` | The source URL. Must be on WP's oEmbed whitelist for auto-resolution. |
| `ratio` | string | no | `"16x9"` | Aspect ratio to reserve. Common: `16x9`, `4x3`, `1x1`, `9x16` (vertical). |
| `caption` | string | no | `""` | Optional caption rendered below the iframe. Plain text, escaped. |

`url` is required. If oEmbed can't resolve it (unsupported provider, network failure), the block emits a fallback `<a>` link in the aspect-ratio box rather than a broken iframe.

## Visual reference

```
┌─────────────────────────────┐
│                             │
│   [iframe / poster image]   │
│                             │
└─────────────────────────────┘
Caption text below if provided.
```

The outer box matches the image block's chrome (cream bg, sage border, 8px radius) by default. Aspect-ratio is enforced via CSS `aspect-ratio` — no JS, no shift on load.

## Variable contract

### Container

- `padding`, `margin-block`, `bg`, `border`, `radius`, `shadow`, `gap`

### Text

- `color`, `link-color`, `font-family`, `font-size`, `font-weight`, `line-height`, `letter-spacing`, `text-align`

Caption text styling uses the text vars; the embed itself (iframe) gets no text styling.

## Defaults

### Container

```json
{
  "padding": "12px 16px",
  "margin-block": "16px",
  "bg": "var(--lg-cream)",
  "border": "1px solid var(--lg-sage-3)",
  "radius": "8px",
  "shadow": "none",
  "gap": "12px"
}
```

Matches image-block default chrome — embed and image look like one family.

### Text

```json
{
  "color": "var(--lg-ink)",
  "link-color": "var(--lg-sage)",
  "font-family": "var(--lg-font-sans)",
  "font-size": "15px",
  "font-weight": "400",
  "line-height": "1.5",
  "letter-spacing": "normal",
  "text-align": "left"
}
```

## Editor affordances

| Affordance | Setting | Notes |
|---|---|---|
| Insertable? | yes | |
| Inline-editable props | `caption` | |
| Custom picker | `embed-url` | TODO future picker — for now plain text input for URL. |
| Pill buttons | edit, ratio, tier, delete | `ratio` cycles through 16x9 / 4x3 / 1x1 / 9x16. |

## Accessibility notes

- `<iframe>` carries the embed provider's `title` attribute when oEmbed provides one; falls back to "Embedded content from {provider}".
- Captions are escaped plain text.
- Aspect-ratio reservation avoids layout shift, which improves CLS for AT users with magnifiers.

## Cross-block interactions

- Below-the-fold embeds get `loading="lazy"` on the iframe so the browser defers fetching. No custom JS / IntersectionObserver needed.

---

**See also**
- [BLOCKS.md](../BLOCKS.md) — block index where this block is listed
- [_template.md](_template.md) — design doc template
