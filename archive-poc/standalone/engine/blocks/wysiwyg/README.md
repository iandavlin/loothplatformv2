# blocks/prose

Block implementation for `prose`. See the [design doc](../../docs/blocks/prose.md) for purpose, schema, and visual reference.

## Files

- `manifest.json` — the contract: vars, defaults, schema, editor affordances. See [docs/MANIFEST.md](../../docs/MANIFEST.md).
- `shell.css` — structural CSS, in the `block-shell` cascade layer. Every chrome property is a `var(--lg-*)`.
- `render.php` — receives parsed props in `$args`, emits HTML.
- `script.js` (optional) — client-side behavior (defer-mount, etc.). Do not add editor wiring here; the editor framework is data-driven.

## Tests

- Fixture: [tests/fixtures/prose-minimal.json](../../tests/fixtures/prose-minimal.json)
- Run isolation: `bin/render-test.php --fixture=prose-minimal`
- Run cascade: `bin/render-test.php --all`
- Run lint: `bin/lint-block.php prose`
