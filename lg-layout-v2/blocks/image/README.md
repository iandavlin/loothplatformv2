# blocks/image-caption

Image with optional caption + sequence badge. The first block implemented end-to-end as the Phase 1 vertical slice (see [docs/blocks/image-caption.md](../../docs/blocks/image-caption.md) for design, [docs/MANIFEST.md](../../docs/MANIFEST.md) for the contract format).

## Files

- `manifest.json` — vars, defaults, schema, editor affordances, context participation
- `shell.css` — structural CSS in `@layer block-shell`; all chrome flows through `var(--lg-*)`
- `render.php` — receives `$args` (validated props) + `$ctx` (render context); emits the figure

## Variants

| Name | Effect |
|---|---|
| `stacked` (default) | Caption sits below image inside the boxed chrome |
| `overlay` | Caption overlays the image with a darken gradient; container chrome stripped |

## Editor

- Insertable from the `+` menu
- Inline-editable props: `caption`, `number`
- Custom picker: `image` (WP media library returns attachment ID)
- Pill buttons: `edit`, `tier`, `delete`

## Context overrides

Participates in `columns` — when nested inside a `columns` block, the column-context layer strips this block's chrome so paired column cells look uniform.

## Tests

```bash
bin/lint-block.php image-caption                       # contract lint
bin/render-test.php --fixture=image-caption-minimal    # isolation snapshot
bin/render-test.php --all                              # cascade check
```
