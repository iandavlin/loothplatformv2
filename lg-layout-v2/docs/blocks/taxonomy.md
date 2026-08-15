# `taxonomy` block

The categories a post is filed under — Loothprint Type and Content Topic —
rendered as chips that link to their archives.

## Purpose

Show what a post *is*, in the reader's terms, and give them a way to find more
like it. This is the only one of the four blocks in the 8/14 charter that is
genuinely new ground: **nothing in layout-v2 renders these taxonomies at all.**

Verified rather than assumed: `post-header` reads only `tier`, `post-footer`
only `category`. `loothprint_type` (18 terms) and `shared_category` (36 terms,
"Content Topics") are collected by the form and appear on no page.

## Content shape

| Prop | Type | Required | Default | Description |
|---|---|---|---|---|
| `taxonomies` | array | no | `["loothprint_type","shared_category"]` | Which taxonomies to show, in order. Empty/unknown ones are skipped, not rendered as gaps. |
| `title` | string | no | `""` | Optional eyebrow. Empty = no title row. |
| `link` | bool | no | `true` | Link each chip to its term archive. |
| `variant` | enum | no | `chips` | `chips` (pills) or `inline` (a comma run, for dense pages). |

### It follows the post, and has no picker on purpose

The block reads the post's terms **live at render**, exactly as the `license`
and `download` blocks now do. Nothing is baked, so a page cannot drift from the
form.

That is also why it declares **no term picker**. Ian's 8/14 ruling: *the form
owns the details, layout-v2 owns the page.* Taxonomies are details the form
already collects; giving v2 a second place to change them would create two
editors for one value, which is how they end up disagreeing. The block's job is
to make them visible, not to become a second form.

`title` is the only inline-editable prop — it is page furniture, not post data.

## Visual reference

```
┌──────────────────────────────────────────────────────┐
│  ┌────────────┐ ┌──────────┐ ┌──────────────────┐    │
│  │ Jigs       │ │ Fixtures │ │ Guitar Building  │    │
│  └────────────┘ └──────────┘ └──────────────────┘    │
└──────────────────────────────────────────────────────┘
```

`inline` variant:

```
Jigs · Fixtures · Guitar Building
```

Terms from different taxonomies sit in one run deliberately: a reader does not
care which taxonomy a label came from, only what the thing is. The taxonomy is
carried in each chip's `title`/`aria-label` for anyone who needs it.

## Variable contract

### Container
- `padding`, `margin-block` — block rhythm.
- `bg`, `radius` — the block sits flat by default (transparent) so it can trail
  an article without drawing a second box under the post-footer.

### Text
- `title-color` — the eyebrow.
- `chip-bg`, `chip-color`, `chip-border` — the chips. Separate from the
  container so a themed background cannot silently drop chip contrast; the same
  reasoning as the `license` block's chip pair.

## Editor affordances

- `insertable: true`
- `inline_editable_props: ["title"]`
- `custom_picker: null` — deliberate, see above.

## Accessibility notes

- Renders as a `<nav>` with an `aria-label`, so it is reachable as a labelled
  landmark and announced as navigation rather than as loose text.
- Each chip names its taxonomy in `title` (e.g. "Loothprint Type: Jigs"), so the
  grouping is available without relying on visual adjacency.
- Chips are real links when `link` is on; when off they are plain text, never a
  link-styled element that does nothing.

## Opt-outs

None. `inherits_global: false` — its own defaults are the truth, matching the
other structural blocks.

---

**See also**
- [BLOCKS.md](../BLOCKS.md) — block index where this block is listed
- [_template.md](_template.md) — design doc template
