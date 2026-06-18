# transcript

Collapsible long-form transcript. Native `<details>` / `<summary>` so the
body **stays in the DOM at all times** — search crawlers index every word
regardless of whether a visitor expanded the accordion. No JavaScript.

Use for video / podcast transcripts attached to an `embed` block above.
For short prose, use `wysiwyg`. For structured link lists, use `callout`.

| Prop    | Type    | Default            | Purpose                                                          |
|---------|---------|--------------------|------------------------------------------------------------------|
| `label` | string  | `"Show transcript"`| Clickable summary text on the collapsed accordion.               |
| `body`  | string  | `""`               | Transcript HTML. `format: "html"` — sanitized with `wp_kses_post`. |
| `open`  | boolean | `false`            | Initial open/closed state. Default closed.                       |

## Authoring conventions

- One `<p>` per paragraph. Wrap timestamps in `<strong>` (e.g.
  `<strong>00:14:32</strong> &mdash; text…`) and the CSS gives them
  serif emphasis automatically.
- For auto-generated YouTube captions, group by minute rather than by
  raw 7-second caption line — readable at a glance, manageable to scroll.
- The accordion chrome is provided; never wrap your transcript in your
  own `<details>` inside a `wysiwyg` block (the old workaround).

## Inline editing

Both `label` and `body` are inline-editable via the FE pill editor.
`body` is HTML-format so paragraph structure survives blur-save through
`innerHTML` round-trip (the same path the callout `note`/`quote`
variants use).

## Why `<details>` and not a JS accordion

Hiding the body behind a JS toggle (`display:none` until clicked) risks
crawler invisibility — Googlebot generally indexes hidden-but-DOM-present
content, but `display:none` historically de-weights it and JS-revealed
content is unreliable. `<details>` is a first-class HTML primitive: the
content is plain visible text in the DOM source, only the rendering is
collapsed. Best of both worlds — short visual footprint, full SEO weight.
