# Block onboarding

Process for adding a new block (or variant, or prop) to lg-layout-v2. Every block follows the same steps; the acceptance gate at the end is non-negotiable.

The process exists to:
- Force the manifest contract to be thought through *before* CSS is written.
- Surface cascade-impact regressions before they ship.
- Keep the editor framework data-driven, not per-block branches.
- Make adding the 25th block as straightforward as adding the 3rd.

## Flavors of "adding a block"

Three distinct flows. Use the one that matches the change.

| Flavor | When | Process |
|---|---|---|
| **New block from scratch** | Adding a fundamentally new content type | Full 7 steps below |
| **New variant of existing block** | Same DOM, different visual treatment (e.g., wysiwyg → boxed/plain) | Step 1 (design doc) + manifest update + tests + dash check. Skip scaffolding + render.php. |
| **New prop on existing block** | Adding a field to an existing block | Manifest update + render update + tests. Skip design doc, dash work happens automatically. |

The 7 steps below describe the full from-scratch flow.

## Step 1 — Design doc

Before any code: write a one-page design doc at `docs/blocks/<name>.md`. Copy [blocks/_template.md](blocks/_template.md) as a starting point.

The design doc captures:
- **Purpose**: one sentence. What is this block for?
- **Content shape**: what data does the block hold? (Maps to `schema.props` in the manifest.)
- **Visual reference**: sketch, screenshot, or wireframe. Even a rough one. If you can't picture it, the manifest will be wrong.
- **Variable contract**: which CSS vars does the block expose? Why those and not others?
- **Defaults**: what does it look like out of the box?
- **Editor affordances**: insertable? what's inline-editable? custom picker?
- **Accessibility notes**: keyboard navigation, ARIA, contrast considerations.
- **Opt-outs**: does this block ignore column normalization? gallery cell normalization?

The doc gets committed before the next step. The scaffold tool refuses to run without it.

Why this matters: every bug in v1 that took an hour to debug would have been prevented by 5 minutes of design thinking. The doc forces the thinking.

## Step 2 — Scaffold

```bash
bin/block-new.php <name>
```

Generates:

```
blocks/<name>/
  manifest.json        # vars, defaults, schema, editor — populated from defaults
  shell.css            # @layer block-shell { .lg-<name> { /* TODO */ } }
  render.php           # canonical <lg-edit> marker + container shell
  preview.html         # sample markup for the dash Preview modal
  README.md            # links to design doc + manifest + fixture

tests/fixtures/<name>-minimal.json    # minimal valid fixture
tests/expected/<name>-minimal.html    # populated on first snapshot pass
```

The scaffold refuses to run if `docs/blocks/<name>.md` doesn't exist. Step 1 is mandatory.

## Step 3 — Implement (manifest-first)

Hard rule: manifest comes first, CSS second, render third.

### 3a. Fill in the manifest

Open `blocks/<name>/manifest.json`. Fill in:
- `name` and `version` (start at `1`; see *Versioning* below for when to bump)
- `vars.container` and `vars.text` lists
- `defaults` for each declared var
- `schema.props` matching the design doc's content shape
- `editor` if the block is insertable or has inline editing
- `context_overrides` if the block participates in any context normalization
- `inherits_global` if the block should opt out of Global Defaults cascade
  (default `true` for card-like blocks; set `false` for structural blocks
  whose own defaults are the truth — divider, columns, heading, etc.)

The manifest must lint clean before moving to CSS:

```bash
bin/lint-block.php <name>
```

Checks:
- All declared vars have defaults (or explicit `null` for "use shell fallback")
- All schema props have a `type`
- Required props are listed in `schema.required`
- Editor fields reference valid pickers and pill buttons

### 3b. Write shell.css

Only structural CSS. Every chrome property goes through a variable. No bare colors, no bare paddings, no bare font sizes.

```css
@layer block-shell {
  .lg-<name> {
    /* structural — display, position, grid, aspect-ratio */
    display: grid;
    gap: 16px;

    /* chrome — every property is a var with a fallback */
    padding: var(--lg-padding, 0);
    background: var(--lg-bg, transparent);
    border: var(--lg-border, none);
    border-radius: var(--lg-radius, 0);
    color: var(--lg-color, inherit);
  }
}
```

Re-run `bin/lint-block.php <name>` after writing shell.css. The linter now checks:
- Every var declared in the manifest appears in shell.css
- Every `var(--lg-*)` in shell.css is declared in the manifest
- No selectors that reach outside this block

### 3c. Write render.php

Canonical structure:

```php
<?php
/** @var array $args   parsed props (validated against manifest schema) */
/** @var array $ctx    render context (post, layout, viewer, editor_mode) */

$variant = $args['variant'] ?? 'stacked';
$class   = 'lg-<name> lg-<name>--' . $variant;

if ( $ctx['editor_mode'] ) {
    // <lg-edit> marker is emitted by the renderer wrapper, not here.
    // render.php just produces the block content.
}
?>
<div class="<?php echo esc_attr( $class ); ?>" data-lg-<name>-... >
  <!-- block content here -->
</div>
```

Things `render.php` MUST NOT do:
- Emit its own `<lg-edit>` marker (the renderer adds it)
- Hardcode chrome (`style="padding:..."`) — that's what vars are for
- Call WordPress functions other than escaping helpers (`esc_attr`, `esc_html`, `wp_kses_post`)
- Query the database (the renderer prefetches data the block needs and passes it via `$ctx`)

### 3d. Edit preview.html

The scaffold drops a generic lorem-ipsum stub. Replace it with sample markup that exercises the block's chrome — the dash Preview modal renders this so authors can judge spacing, color, border, and typography choices live as they tweak fields.

Conventions:

- One element carries `data-lg-preview-root` and the block's base class (e.g. `class="lg-callout"`). The modal JS toggles `lg-<name>--<variant>` on it when a variant tab is clicked.
- Use lorem ipsum for text and inline SVG data-URIs for images (no network deps from the admin dash).
- For blocks where variants change the *inner structure* (e.g. callout's `links` items list vs `quote` prose), populate the trailing `<script type="application/json" data-lg-preview-variants>` map keyed by variant name → innerHTML. The JS swaps innerHTML on variant change. Variants not in the map fall through to `_default` (or whatever is already in the root).
- Keep it self-contained: no external scripts, no asset requests. The iframe loads only the generated v2 CSS bundle.

Example (chrome-only block, no per-variant markup needed):

```html
<div class="lg-<name>" data-lg-preview-root>
  <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit.</p>
</div>
<script type="application/json" data-lg-preview-variants>{}</script>
```

Example (variants change structure):

```html
<aside class="lg-foo" data-lg-preview-root></aside>
<script type="application/json" data-lg-preview-variants>
{
  "_default": "<p>default body…</p>",
  "compact":  "<span>compact body…</span>"
}
</script>
```

### 3e. Write script.js (optional)

Only if the block needs client-side behavior (defer-mount, drag, intersection observer, etc.). Keep it small. No editor-mode code here — editor wiring is data-driven via the manifest.

## Step 4 — Isolation tests

Snapshot the block in isolation:

```bash
bin/render-test.php --block=<name>
```

This runs:
1. Render the block's minimal fixture through the pipeline
2. Compare HTML output to `tests/expected/<name>-minimal.html`
3. Compare CSS bundle to `tests/expected/<name>-minimal.css`

First run: no expected file exists, so the harness writes one and prompts you to review and commit it.

Subsequent runs: diff against the committed baseline. Fail if it moved without intent.

## Step 5 — Cascade impact tests

Two checks ensure adding a new block doesn't silently affect anything else.

### 5a. Inert addition test

Re-snapshot the full corpus *without* using the new block:

```bash
bin/render-test.php --all
```

Diff must be empty. If anything in another article moved, the new block has a side effect — most likely a global selector leaked from its shell.css, or its `context_overrides` declaration affected an unintended selector.

### 5b. Targeted insertion test

Add the block to one canonical fixture article (`tests/fixtures/integration-<name>.json`). Re-snapshot:

```bash
bin/render-test.php --fixture=integration-<name>
```

Diff should affect only the targeted article. Inspect visually via the mockup:

```bash
open mockup/render-pipeline.html?fixture=integration-<name>
```

## Step 6 — Editor wiring

Only if `editor.insertable` is `true` or `editor.inline_editable_props` is non-empty.

The editor framework is data-driven. There's nothing to *implement* per block — but there is something to *verify*:

```bash
bin/editor-test.js --block=<name>
```

This runs headless-chrome assertions:
- The block's pill renders with the buttons declared in `editor.pill_buttons`.
- Each `inline_editable_prop` becomes contenteditable and dispatches blur-save with the right payload.
- The declared `custom_picker` (if any) opens when triggered.
- `wireMarkers` binds the block's host correctly (idempotently, with the safety failsafe for native form submission).

If the editor framework is missing a feature the block needs (a new picker type, a new pill button), add it in the framework first — never with a per-block escape hatch in admin.js.

## Step 7 — Acceptance gate

The block doesn't ship until every box is checked:

- [ ] Design doc committed at `docs/blocks/<name>.md`
- [ ] `bin/lint-block.php <name>` passes clean
- [ ] `bin/render-test.php --block=<name>` passes (snapshot committed)
- [ ] `bin/render-test.php --all` passes (inert addition: no unrelated diff)
- [ ] `bin/render-test.php --fixture=integration-<name>` passes (targeted insertion: only this article moves)
- [ ] `bin/editor-test.js --block=<name>` passes (if applicable)
- [ ] Block visible in dash form (load admin page, verify panel exists with declared vars)
- [ ] Preview modal renders correctly (click the panel's **Preview** button, switch through every variant tab, confirm sample markup + variant modifier classes look right)
- [ ] Block appears in the editor's insertable list (if `insertable: true`)
- [ ] One-paragraph entry added to [BLOCKS.md](BLOCKS.md) index
- [ ] **Layout-JSON schema doc regenerated**: `bin/generate-schema-doc.php` →
      [docs/LAYOUT-JSON.md](LAYOUT-JSON.md) reflects the new block / variant /
      prop. The doc is machine-generated from manifests; never hand-edit.
- [ ] **Manifest version reviewed** (see *Versioning* below). If the block's
      shape changed in a breaking way, `manifest.version` is bumped and a
      migration entry is added to [MIGRATION.md](MIGRATION.md).
- [ ] `bin/lint-docs.php` passes (the new design doc has correct cross-refs)

The gate is enforced by `make test` running every check.

## Versioning

Two version numbers exist; bump rules are different.

### `manifest.version` (per-block)

In every `blocks/<name>/manifest.json`. **Bump when the block's data shape
changes in a way that existing stored layouts would no longer satisfy:**

| Change | Bump? |
|---|---|
| Adding a new optional prop with a default | No |
| Adding a new variant | No |
| Adding a new CSS var to `vars.container` | No |
| Renaming an existing prop | **Yes** |
| Removing a prop that was already in use | **Yes** |
| Changing a prop's type (`string` → `integer`) | **Yes** |
| Changing a prop's structural shape (e.g., flat array → array of buckets) | **Yes** |
| Tightening a required-prop list | **Yes** |

When you bump, you must also add a migration entry to
[MIGRATION.md](MIGRATION.md) under "Per-block schema migrations" describing:
- What changed
- How the importer/validator handles old (pre-bump) data
- Whether a one-shot rewriter runs over existing posts on plugin upgrade

A real example: columns originally stored children as
`{ cols: 2, blocks: [child, child, ...] }` with round-robin distribution at
render time. It was refactored to explicit buckets:
`{ columns: [{ blocks: [...] }, { blocks: [...] }] }`. That's a structural
shape change — a `version: 2` bump territory. (We skipped it during initial
dev because zero legacy posts existed in the corpus; for production-era
changes, don't skip.)

### Top-level `schema` (per-layout)

In every layout JSON at the root: `{ "schema": 1, "_meta": {...}, "blocks": [...] }`.
**Bump only when the layout *wrapper* changes**, not when individual blocks
change shape. Triggers:

- A new required field at the root
- A change to how `blocks` is structured at the root (e.g., adding columns/rows
  at the article level)
- Anything that means the importer needs to read existing layouts differently
  *before* dispatching to block-level parsing

The Validator should refuse layouts with `schema > current` (unknown future
version) and run migration on layouts with `schema < current` (or refuse if
the migration isn't implemented yet).

### Don't bump for

- Pure CSS / shell.css edits (no data shape change)
- Defaults changes (e.g., `--lg-padding: 12px` → `16px`)
- Dash UI improvements
- Editor wiring changes

These are runtime/render concerns, not data-contract concerns.

## Variant flow

Adding a variant of an existing block (e.g., wysiwyg → boxed/plain). Lighter than a full new block.

1. Update [docs/blocks/&lt;name&gt;.md](blocks/_template.md): add a "Variants" section describing the new variant.
2. Add a `variants.<name>` entry in `blocks/<name>/manifest.json`.
3. `bin/lint-block.php <name>` — verifies the variant references valid var names.
4. `bin/render-test.php --block=<name> --variant=<variant>` — snapshot the variant in isolation.
5. `bin/render-test.php --all` — confirm no unintended cascade.
6. Verify the dash now shows a sub-panel for the variant with its own Adopt/Reset toggle.
7. `bin/generate-schema-doc.php` — refresh [LAYOUT-JSON.md](LAYOUT-JSON.md). Adding a variant is **not** a `manifest.version` bump (variants are additive).

## Prop-addition flow

Adding a new prop to an existing block. Lighter still.

1. Update the design doc to document the new prop and its use case.
2. Add the prop to `blocks/<name>/manifest.json` under `schema.props`.
3. Update `blocks/<name>/render.php` to consume the prop.
4. Add or update a fixture exercising the new prop.
5. `bin/render-test.php --block=<name>` — snapshot the new prop's effect.
6. Run `--all` to confirm no cascade.
7. `bin/generate-schema-doc.php` — refresh [LAYOUT-JSON.md](LAYOUT-JSON.md).
8. **Version check** (see *Versioning*):
   - Adding an **optional** new prop with a default → no bump.
   - Renaming, removing, retyping, or adding it to `schema.required` → bump
     `manifest.version` and add a [MIGRATION.md](MIGRATION.md) entry.

Dash work happens automatically: the new prop appears as a field in the next dash form render.

## Anti-patterns the gate catches

Each of these is a CI failure:

- Bare CSS values in `shell.css` (must be `var(--lg-*, default)`)
- Selectors that reach outside the block (e.g., `.lg-other-block` inside `blocks/foo/shell.css`)
- New utility classes inside a block (utilities belong in the `theme` layer)
- Editor wiring done in `admin.js` instead of via the manifest's `editor` field
- Adding a block without re-running the cascade snapshot
- Cross-refs between docs that aren't reciprocal

If the gate seems strict: it's strict on purpose. Every rule is a v1 bug we don't want to repeat.

---

**See also**
- [README.md](README.md) — entry point
- [MANIFEST.md](MANIFEST.md) — the contract every new block declares
- [ARCHITECTURE.md](ARCHITECTURE.md) — the system this process slots into
- [TESTING.md](TESTING.md) — how the linter and snapshot tools work in detail
- [BLOCKS.md](BLOCKS.md) — where every new block gets indexed
- [blocks/_template.md](blocks/_template.md) — the per-block design doc template
- [GLOSSARY.md](GLOSSARY.md) — terms used here (manifest, shell CSS, variant, fixture, snapshot)
