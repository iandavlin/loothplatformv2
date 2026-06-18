# Architecture

The technical big picture for lg-layout-v2. Read this end-to-end before changing anything substantial. It's the foundation every other doc builds on.

## Why a rewrite

v1 grew organically and ended up with three independent CSS authorities — static block CSS, plugin-wide "safety net" rules in `lg-layout.css`, and the dash-generated overrides — competing on specificity. The dash claimed to be authoritative but lost to hidden context rules (e.g., column normalization). New blocks required edits in five places. Adding an iframe-sizing rule could silently break a different block half a file away.

v2 picks one source of truth (the dash), one specificity story (cascade layers), and one place each block declares itself (the manifest). The rest of the architecture falls out of those three choices.

## Rewrite phases

Six phases, each independently shippable. Phase 0 is read-only docs and harnesses; the plugin itself starts at Phase 1.

| Phase | Scope | Status |
|---|---|---|
| 0 | Docs, mockups, test harness, ACF exporter prototype | In progress |
| 1 | Core pipeline (manifest, validator, renderer, CSS bundler) — runs in CLI and WP | Pending |
| 2 | WP wrapper (entry, hooks, meta box, asset enqueue, template selector) | Pending |
| 3 | Authoring surface (BlockStyles dash, MetaBox, CLI commands) | Pending |
| 4 | Inline editor (REST + admin.js) | Pending |
| 5 | Adjacent systems (Profile, RelatedPosts, BundleExporter enhanced, BBStripper, hero, paywall logic) | Pending |
| 5.5 | Corpus migration (ACF→v2 importer run against all legacy posts) | Pending |
| 6 | Deactivate legacy, activate v2 | Pending |

Each phase has its own acceptance gate (see [TESTING.md](TESTING.md)). The legacy plugin stays installed throughout — toggle is in the Plugins screen.

## The authority ladder

When a block renders, the visual outcome is determined by this ordered list. Each layer can only be overridden by something later in the list:

1. **Reset** — base resets, normalize-ish, theme intrusions.
2. **Theme** — brand tokens at `:root` (`--lg-amber`, `--lg-sage-3`, etc.), font registrations, base typography.
3. **Block shell** — structural CSS for blocks: `display`, `grid`, `flex`, `position`, `aspect-ratio`. *No visual chrome.*
4. **Block defaults** — visual defaults per block: background, border, padding, color, etc. Generated from each block's manifest.
5. **Context** — context-aware variants: blocks-in-columns, blocks-in-gallery-cells, etc. Sets CSS variables on parent contexts.
6. **Dash** — generated from the `lg_layout_v2_block_styles` option. Always wins.

The dash is at the bottom for a reason. **If a value isn't expressible in the dash today, the dash isn't authoritative.** Either expose it in the dash (preferred) or revisit the architecture — don't add an escape hatch.

## Cascade layers

The ordering above is implemented with native CSS `@layer`. Declared once at the top of the first enqueued stylesheet:

```css
@layer reset, theme, block-shell, block-defaults, context, dash;
```

Order matters: **later layers always beat earlier layers regardless of selector specificity.** This is the entire conflict-resolution story. Within a layer, normal specificity rules apply.

Practical consequence: a rule like `:where(.lg-article) :not(.lg-hero) > iframe { height: auto }` in the `block-shell` layer cannot beat `.lg-embed__defer > iframe { height: 100% }` in the `block-defaults` layer, even though the former has higher specificity. That entire class of v1 bug is gone.

Browser support: Chrome 99+, Safari 15.4+, Firefox 97+ (all GA early 2022). For a logged-in publication site in 2026, this is fine.

### Within `dash`, generated rules can fight each other

The `dash` layer contains many generated rules — global defaults, per-block overrides, context variants the dash exposes. Inside that layer, specificity still matters. The generator deliberately orders rules so per-block beats global beats brand-token, and per-block-in-context beats per-block standalone. See [MANIFEST.md#dash-generation](MANIFEST.md#dash-generation) for the emission order.

## CSS variable convention

Each block exposes a known set of CSS variables as its style API. The actual `padding: ...` / `background: ...` declarations live in *one* place: the block's `shell.css`. Every other layer sets variables, never properties.

Naming: `--lg-<property>` for generic, `--lg-<block>-<property>` if a block has a property that other blocks shouldn't inherit.

```css
/* In block-shell layer, blocks/image-caption/shell.css */
@layer block-shell {
  .lg-image-caption {
    display: grid;
    gap: 16px;
    padding: var(--lg-padding, 0);
    margin-block: var(--lg-margin-block, 0);
    background: var(--lg-bg, transparent);
    border: var(--lg-border, none);
    border-radius: var(--lg-radius, 0);
    box-shadow: var(--lg-shadow, none);
    color: var(--lg-color, inherit);
    font-size: var(--lg-font-size, inherit);
  }
}
```

Block defaults set the variables:

```css
@layer block-defaults {
  .lg-image-caption {
    --lg-padding: 12px 16px;
    --lg-bg: var(--lg-cream);
    --lg-border: 1px solid var(--lg-sage-3);
    --lg-radius: 8px;
    --lg-margin-block: 16px;
  }
}
```

Context layer can override:

```css
@layer context {
  .lg-columns__col > [class*="lg-"] {
    --lg-padding: 0;
    --lg-margin-block: 0;
    --lg-bg: transparent;
    --lg-border: none;
    --lg-shadow: none;
  }
}
```

Dash always wins:

```css
@layer dash {
  .lg-image-caption { --lg-bg: #f7dcb4; }
}
```

### Composite shorthand variables

Some CSS properties — `border`, `box-shadow`, `font` — can't be partially overridden via variables. Standardize on a single composite var per shorthand:

- ✅ `--lg-border: 1px solid var(--lg-sage-3)` — one var, can be overridden cleanly.
- ❌ `--lg-border-width: 1px; --lg-border-style: solid; --lg-border-color: var(--lg-sage-3)` — three vars, partial overrides cause property-level fallthrough bugs.

The dash UI exposes `border` as a single string field, not three.

## The render pipeline

Six stages, each independently testable. The mockup at [`../mockup/render-pipeline.html`](../mockup/render-pipeline.html) is an interactive walkthrough.

```
1. Input        →  Article JSON + dash overrides JSON + brand tokens JSON
2. Validation   →  Schema check against block manifests; reject early with clear errors
3. Block tree   →  Parsed in-memory structure; ids assigned to any missing them
4. Variable     →  For each block instance, resolve effective values from
   resolution      block-defaults → context → dash. Surface a table for debugging.
5. CSS bundle   →  Emit `@layer` stylesheet from theme + shell + defaults + context + dash.
6. HTML output  →  Walk the tree, call each block's render fn, emit the article HTML.
                   Includes <lg-edit> markers if editor mode is on.
```

Same pipeline runs in two contexts:

- **CLI** (`bin/render-test.php`): inputs are files, outputs are files. No WordPress.
- **WP** (`Renderer::filter_content`): inputs are post meta + option, outputs are `$content` for the `the_content` filter.

The pipeline itself is identical. Only the I/O layer differs. This is what makes the test harness possible — and why the test harness should be trusted: the same code runs in both places.

### Why this matters for cascade testing

When you change a manifest default or a block's shell.css, the harness can:
1. Re-run stage 5 to regenerate the CSS bundle and snapshot it.
2. Re-run stage 6 for every post in the corpus and snapshot each.
3. Diff against the baseline.

If a snapshot moved that shouldn't have, the diff surfaces it. See [TESTING.md#cascade-tests](TESTING.md#cascade-tests).

## Block contract

Every block is a directory under `blocks/`. The directory contains:

```
blocks/<name>/
  manifest.json     # the contract: vars, defaults, schema, editor affordances
  shell.css         # structural CSS only, consumes only declared vars
  render.php        # produces the HTML; receives parsed props + render context
  script.js         # optional client-side behavior (defer-mount, etc.)
  README.md         # links to the per-block design doc in docs/blocks/<name>.md
```

Everything else is generated:

- `block-defaults` layer emitted from `manifest.json` defaults
- Dash form fields generated from `manifest.json` vars
- Editor wiring driven by `manifest.json` editor affordances (no per-block JS in admin.js)
- Insertable list, schema validator, fixture defaults — all from manifest

See [MANIFEST.md](MANIFEST.md) for the manifest spec in full.

## Tier resolver

Blocks declare access requirements with a named tier:

```json
{ "type": "prose", "gated_tier": "looth-pro", "html": "…members-only content…" }
```

The renderer hands `(viewer, "looth-pro")` to a `TierResolver` service. The resolver returns a boolean. Blocks don't care how the resolver gets there.

Named tiers:

| Tier | Meaning |
|---|---|
| `public` | Baseline. Anyone can see. |
| `looth-lite` | Viewer has the `looth-lite` taxonomy term (or any tier above). |
| `looth-pro` | Viewer has the `looth-pro` taxonomy term (or any tier above). |
| `admin` | Viewer has admin role or is flagged by the polling plugin's admin bypass. |

Internal states the resolver handles, *not* exposed as gating tiers:

- **delinquent** — viewer's billing lapsed; their last-paid tier is downgraded for gating purposes. v1 called this `looth1`; that name is dead.
- **admin bypass** — admins satisfy every tier regardless of taxonomy.
- **preview-as** — admin querying with `?lg_preview_role=public` sees the post as a `public` viewer. The resolver honors this when present.

The resolver is one PHP class with one method: `satisfies( $viewer, $required_tier ): bool`. It's the only place tier logic lives.

## What's NOT in the architecture

These are explicitly out of scope for v2 to keep the system understandable:

- **Per-instance scoped CSS** (v1's `wysiwyg` variant). Replaced by named manifest variants. If you need a one-off style, it's a new variant, not raw CSS smuggled into the post JSON.
- **Inline `style=` attributes from authors.** Stripped on render. Authors style through the dash.
- **Block-level escape hatches in `admin.js`.** Editor behavior is fully manifest-driven. If a block needs a custom picker, it's declared in the manifest's `editor.custom_picker` field and the editor framework dispatches to a registered picker handler.
- **Selectors that reach outside a block** (e.g., `blocks/foo/shell.css` writing `.lg-other-block { ... }`). The linter flags these.
- **Unnamed defaults** living in static CSS files. If a value is a default, it lives in the manifest. The `block-defaults` layer is generated; you don't hand-write rules there.

## Anti-patterns the linter catches

- Bare CSS values in `shell.css` (everything must be `var(--lg-*, default)`).
- Variables declared in the manifest but unused in `shell.css`, or vice versa.
- Selectors that reach outside the block.
- A new utility class inside a block's shell.css (utilities belong in the `theme` layer).
- Editor wiring done in `admin.js` instead of via the manifest's `editor` field.
- Adding a block without re-running the cascade snapshot.
- Two-way doc cross-refs that aren't reciprocal.

---

**See also**
- [README.md](README.md) — entry point with the AI-agent quickstart
- [MANIFEST.md](MANIFEST.md) — the block contract this architecture relies on
- [BLOCKS.md](BLOCKS.md) — the current block catalog this architecture serves
- [BLOCK-ONBOARDING.md](BLOCK-ONBOARDING.md) — how to add a block within this architecture
- [TESTING.md](TESTING.md) — how the architecture's invariants are verified
- [MIGRATION.md](MIGRATION.md) — how legacy data is brought into this architecture
- [GLOSSARY.md](GLOSSARY.md) — terms used here (cascade layer, manifest, shell CSS, tier resolver)
