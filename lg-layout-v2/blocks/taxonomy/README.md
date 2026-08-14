# blocks/taxonomy

Block implementation for `taxonomy`. See the [design doc](../../docs/blocks/taxonomy.md) for purpose, schema, and visual reference.

## Files

- `manifest.json` — the contract: vars, defaults, schema, editor affordances. See [docs/MANIFEST.md](../../docs/MANIFEST.md).
- `shell.css` — structural CSS, in the `block-shell` cascade layer. Every chrome property is a `var(--lg-*)`.
- `render.php` — receives parsed props in `$args`, emits HTML.
- `preview.html` — sample markup for the dash Preview modal. Element with `data-lg-preview-root` carries the block class; the JS toggles `--<variant>` modifiers on it. For blocks where variants change inner structure, populate the `data-lg-preview-variants` JSON map with per-variant innerHTML.
- `script.js` (optional) — client-side behavior (defer-mount, etc.). Do not add editor wiring here; the editor framework is data-driven.

## Tests

- Fixture: [tests/fixtures/taxonomy-minimal.json](../../tests/fixtures/taxonomy-minimal.json)
- Run isolation: `bin/render-test.php --fixture=taxonomy-minimal`
- Run cascade: `bin/render-test.php --all`
- Run lint: `bin/lint-block.php taxonomy`
