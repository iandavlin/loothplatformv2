# Block manifest spec

Every block in v2 declares itself via `blocks/<name>/manifest.json`. The manifest is the **single source of truth** for that block: CSS variables it exposes, default values, schema for the post-JSON props, editor affordances, dash form fields.

The generator reads the manifest to produce:
- The `block-defaults` CSS layer (one rule per block, one decl per default)
- The dash form panel (one field per declared var)
- The schema validator's rules for that block
- The editor's wiring (insertable list, inline-editable props, custom picker dispatch)

If a block has a feature you can't express in the manifest, the manifest spec is incomplete — extend it here before adding the feature to one block.

## Full structure

```json
{
  "name": "image-caption",
  "version": 1,
  "selector": ".lg-image-caption",
  "description": "Image with optional caption underneath. Optional sequence number badge.",

  "schema": {
    "props": {
      "id":      { "type": "integer", "description": "Attachment ID" },
      "url":     { "type": "string",  "description": "Image URL (resolved from id if absent)" },
      "alt":     { "type": "string",  "default": "" },
      "caption": { "type": "string",  "default": "" },
      "number":  { "type": "string",  "default": "", "description": "Optional sequence badge text" },
      "variant": { "type": "string",  "default": "stacked", "enum": ["stacked", "overlay"] }
    },
    "required": ["id"]
  },

  "vars": {
    "container": [
      "padding", "margin-block", "bg", "border", "radius", "shadow"
    ],
    "text": [
      "color", "font-family", "font-size", "font-weight", "line-height", "letter-spacing", "text-align"
    ]
  },

  "defaults": {
    "container": {
      "padding": "12px 16px",
      "margin-block": "16px",
      "bg": "var(--lg-cream)",
      "border": "1px solid var(--lg-sage-3)",
      "radius": "8px",
      "shadow": "none"
    },
    "text": {
      "color": "var(--lg-ink)",
      "font-size": "20px",
      "font-weight": "400"
    }
  },

  "variants": {
    "overlay": {
      "extends": "defaults",
      "container": { "bg": "transparent", "border": "none", "padding": "0" }
    }
  },

  "editor": {
    "insertable": true,
    "inline_editable_props": ["caption", "number"],
    "custom_picker": "image",
    "pill_buttons": ["edit-link", "ratio", "tier", "delete"]
  },

  "context_overrides": ["columns"]
}
```

## Field reference

### Top-level

| Field | Type | Required | Purpose |
|---|---|---|---|
| `name` | string | yes | Block identifier. Matches directory name and `type` field in post JSON. Kebab-case. |
| `version` | int | yes | Manifest schema version. Bumped when this spec changes. |
| `selector` | string | yes | CSS selector this block targets. Convention: `.lg-<name>`. |
| `description` | string | yes | One-line description. Shown in dash, in BLOCKS.md, in fixture comments. |
| `schema` | object | yes | Schema for the post-JSON props this block accepts. See [#schema](#schema). |
| `vars` | object | yes | CSS variables this block exposes. See [#vars](#vars). |
| `defaults` | object | yes | Default values for the declared vars. See [#defaults](#defaults). |
| `variants` | object | no | Named variant configurations. See [#variants](#variants). |
| `editor` | object | no | Editor affordances. See [#editor](#editor). |
| `context_overrides` | array | no | Named contexts this block expects to be normalized in. See [#context-overrides](#context-overrides). |

### Schema

Defines the shape of this block's entry in the post layout JSON. The validator rejects post JSON whose blocks don't match.

```json
"schema": {
  "props": {
    "<prop-name>": {
      "type": "string|integer|boolean|array|object|array_of_objects",
      "format": "html",                // optional; see "HTML-format strings" below
      "default": "<value>",            // optional
      "enum": ["a", "b"],              // optional
      "description": "...",            // optional, surfaces in dash
      "items": { "type": "string" }    // optional, for arrays
                                       // for array_of_objects: { "props": { ... } }
    }
  },
  "required": ["<prop-name>", ...]     // optional, defaults to []
}
```

The validator runs at:
- Save time (REST endpoints, CLI import) — rejects invalid post JSON before persisting.
- Render time — logs invalid blocks but doesn't crash (degrades gracefully).
- Fixture load — in the test harness, fails the test.

#### Prop types

| `type` | Validates as |
|---|---|
| `string` | PHP `is_string` |
| `integer` | PHP `is_int` |
| `number` | int or float |
| `boolean` | PHP `is_bool` |
| `array` | list-array (numeric keys 0..N) |
| `object` | associative array (non-list) |
| `array_of_objects` | list-array of associative arrays |

#### `array_of_objects` — structured repeater rows

For props that hold a list of homogeneous rows (e.g. the callout block's
`items: [{ icon, label, url, description, ... }]`), declare an item sub-schema:

```json
"items": {
  "type": "array_of_objects",
  "default": [],
  "description": "...",
  "items": {
    "props": {
      "icon":  { "type": "string", "default": "link", "description": "..." },
      "label": { "type": "string", "default": "" },
      "url":   { "type": "string", "default": "" }
    }
  }
}
```

What the engine does with it:

- **Validator** type-checks each row's props against the sub-schema and honors per-prop `enum`s.
- **MetaBox** renders a repeater UI (`render_repeater`) — Add row / ↑ / ↓ / × controls,
  one form field per item prop. `icon` gets a dropdown sourced from `LG\LayoutV2\Icons::keys()`
  with a live SVG preview.
- **MetaBox parse** reads rows back as `$_POST[<prop>][<rkey>][<itemprop>]`, sorts by
  `__pos`, drops rows where every value matches the manifest default (so a fresh
  "+ Add row" + Save with nothing typed doesn't persist a junk row).
- **FE editor** renders the same repeater in a modal (`openItemsModal` in
  `lg-fe-editor.js`), reading current state from a JSON node the renderer
  emits into the host element when in editor mode.

The block's `render.php` is responsible for emitting `<script type="application/json"
data-lg-<block>-state>` in editor mode if you want the FE modal to seed itself
without a REST round-trip. See `blocks/callout/render.php` for the pattern.

#### HTML-format strings

A `string` prop that carries HTML (rich text, paragraphs, links) should declare
`"format": "html"`. This changes two boundaries:

- **MetaBox save** sanitizes with `wp_kses_post` instead of `sanitize_textarea_field`,
  so author tags survive.
- **FE editor inline edit** (when listed in `editor.inline_editable_props`)
  round-trips `el.innerHTML` instead of `el.innerText`, so paragraph structure
  survives blur-save.

Without `"format": "html"`, strings are treated as plain text and stripped of markup.

### Vars

Declares which CSS variables this block exposes. Format:

```json
"vars": {
  "container": ["padding", "margin-block", "bg", "border", "radius", "shadow"],
  "text":      ["color", "font-family", "font-size", "font-weight", ...]
}
```

The `container` and `text` groups are conventions for grouping in the dash UI (separate sections). They map to actual CSS vars as `--lg-<varname>`:

| Declared | CSS var | Used as |
|---|---|---|
| `padding` | `--lg-padding` | `padding: var(--lg-padding, ...)` |
| `bg` | `--lg-bg` | `background: var(--lg-bg, ...)` |
| `font-size` | `--lg-font-size` | `font-size: var(--lg-font-size, ...)` |

The block's `shell.css` is required to use exactly these vars and no others. The linter (`bin/lint-block.php`) enforces this contract.

### Defaults

Declares the default value for each var. These become the `block-defaults` CSS layer for this block:

```css
@layer block-defaults {
  .lg-image-caption {
    --lg-padding: 12px 16px;
    --lg-bg: var(--lg-cream);
    --lg-border: 1px solid var(--lg-sage-3);
    /* ... */
  }
}
```

Values may reference theme tokens (`var(--lg-cream)`, `var(--lg-sage-3)`, etc.) — those resolve from the `theme` layer.

Empty / missing default = the property falls through to the var's own fallback in `shell.css` (typically `transparent`, `none`, `0`, `inherit`).

### Variants

Named alternative configurations of the same block. Used when the same DOM structure needs to look meaningfully different in different contexts — e.g., the wysiwyg block's "boxed" and "plain" variants.

```json
"variants": {
  "overlay": {
    "extends": "defaults",
    "container": { "bg": "transparent", "border": "none", "padding": "0" },
    "text":      { "color": "#fff" }
  }
}
```

`extends` may be `"defaults"` (inherit from the block's `defaults`) or the name of another variant.

A variant generates an additional class on the rendered block (`<figure class="lg-image-caption lg-image-caption--overlay">`) and an additional rule in `block-defaults`:

```css
@layer block-defaults {
  .lg-image-caption--overlay {
    --lg-bg: transparent;
    --lg-border: none;
    --lg-padding: 0;
    --lg-color: #fff;
  }
}
```

Each variant also appears as a separate panel in the dash, with its own Adopt/Reset toggle. The dash user can override variant-level values without touching the block's base defaults.

To select a variant in post JSON: `{ "type": "image-caption", "variant": "overlay", ... }`.

### Editor

Declares how the editor surfaces this block. The editor framework reads this and wires up — no per-block JS in `admin.js`.

```json
"editor": {
  "insertable": true,
  "inline_editable_props": ["caption", "number"],
  "custom_picker": "image",
  "pill_buttons": ["edit-link", "ratio", "tier", "delete"]
}
```

| Field | Type | Purpose |
|---|---|---|
| `insertable` | bool | Whether the block appears in the "+" insert dropdown. Default `true`. |
| `inline_editable_props` | string[] | Props that become `contenteditable` in the rendered HTML. The editor wires up blur-save and TinyMCE binding automatically. |
| `custom_picker` | string|null | Which picker UI to invoke for this block: `null`, `"image"`, `"carousel-slots"`, `"embed-url"`, etc. Registered picker handlers in the editor framework dispatch by name. |
| `pill_buttons` | string[] | Which buttons the edit pill shows. Order matters. Standard: `edit`, `edit-link`, `ratio`, `tier`, `delete`, `move-up`, `move-down`. |

If `editor` is omitted entirely: the block is insertable, has no inline editing, no custom picker, default pill buttons (`edit`, `tier`, `delete`).

### Context overrides

Declares which named contexts this block participates in normalization for. The `context` CSS layer reads this to decide which blocks get column-normalized, gallery-cell-normalized, etc.

```json
"context_overrides": ["columns"]
```

Each named context corresponds to a rule in the `context` layer. For `"columns"`:

```css
@layer context {
  .lg-columns__col > .lg-image-caption,
  .lg-columns__col > .lg-text,
  /* ... all blocks that declare "columns" in context_overrides ... */
  {
    --lg-padding: 0;
    --lg-margin-block: 0;
    --lg-bg: transparent;
    --lg-border: none;
    --lg-shadow: none;
  }
}
```

The selector list is generated by walking all blocks' `context_overrides`. Adding a new block to `columns` normalization = adding one string to its manifest. No CSS edits.

Available contexts (extensible):

| Context | Selector pattern | What it normalizes |
|---|---|---|
| `columns` | `.lg-columns__col > .lg-<block>` | Strips chrome so paired column cells look uniform |
| `gallery-cell` | `.lg-gallery__cell > .lg-<block>` | Same idea, gallery context |
| `hero-overlay` | `.lg-hero__overlay > .lg-<block>` | Forces white-on-translucent variant |

Each named context's normalization rule lives in `src/CssBuilder.php` (Phase 1) as a named template. Adding a new context type is a code change, but using one is just a manifest entry.

## Dash generation

The dash form for a block is generated entirely from its manifest. One panel per block. Within a panel:

- One section per `vars` group (`container`, `text`) — title from group name.
- One row per var — label from var name (formatted), input type inferred (text for most, color for `*-color`, select for enum-prop schema fields).
- Defaults shown as placeholder text in empty fields.
- An "Adopt Global / Reset to Global" toggle button (see [v1 BlockStyles.php's stateful toggle](../../lg-layout/src/BlockStyles.php)).
- Variants appear as additional collapsible panels within the block's panel.

The generator emits dash rules at the end of the `dash` layer, in this order:

1. Brand token overrides (`:root { --lg-amber: ...; }`)
2. Global defaults that affect all blocks (`:where(.lg-author-card, .lg-byline, ...) { ... }`)
3. Per-block container vars (`.lg-image-caption { --lg-bg: ...; }`)
4. Per-block text vars (`.lg-image-caption :where(p, li, ...) { color: ...; }`)
5. Per-variant overrides (`.lg-image-caption--overlay { --lg-bg: ...; }`)
6. Per-context overrides if exposed (`.lg-columns__col > .lg-image-caption { ... }`)
7. Title pseudo-block (`h1.lg-heading--h1 { ... }`)
8. Advanced CSS textareas (raw, appended last)

Order within `dash` matters because all rules are equal-layer; specificity is the tiebreaker, and later wins on ties.

## Manifest versioning

`version` field on each manifest. When this spec changes in a breaking way (new required field, semantic change to an existing field):

1. Bump the spec version in this doc.
2. Add migration notes in the per-block design doc for any block whose manifest needs updating.
3. The validator emits a warning when loading a manifest with an older version, identifying what needs updating.

No silent breakage. No "the manifest format changed, your block stopped rendering."

## Worked example: image-caption manifest end to end

The manifest above generates:

**`block-defaults` layer rule** (auto-emitted):
```css
@layer block-defaults {
  .lg-image-caption {
    --lg-padding: 12px 16px;
    --lg-margin-block: 16px;
    --lg-bg: var(--lg-cream);
    --lg-border: 1px solid var(--lg-sage-3);
    --lg-radius: 8px;
    --lg-shadow: none;
    --lg-color: var(--lg-ink);
    --lg-font-size: 20px;
    --lg-font-weight: 400;
  }
  .lg-image-caption--overlay {
    --lg-bg: transparent;
    --lg-border: none;
    --lg-padding: 0;
    --lg-color: #fff;
  }
}
```

**`context` layer rule** (auto-emitted, joined with other blocks declaring `columns`):
```css
@layer context {
  .lg-columns__col > .lg-image-caption,
  .lg-columns__col > .lg-text,
  /* ... */ {
    --lg-padding: 0;
    --lg-margin-block: 0;
    --lg-bg: transparent;
    --lg-border: none;
    --lg-shadow: none;
  }
}
```

**Dash form**: a panel titled "image-caption" with sections "Container" and "Text", populated with 13 input fields, each with the default value as placeholder, plus an "overlay" sub-panel for the variant.

**Schema validator**: rejects post JSON where an image-caption block is missing `id`, has a non-string `caption`, or has a `variant` other than `"stacked"` or `"overlay"`.

**Editor wiring**: image-caption is insertable; the pill shows edit/edit-link/ratio/tier/delete; clicking on the caption makes it contenteditable; the image picker opens via the `image` custom picker.

All of that from one JSON file. No PHP changes, no CSS files to hand-write, no editor JS branches.

---

**See also**
- [ARCHITECTURE.md](ARCHITECTURE.md) — the cascade layer this manifest slots into
- [BLOCK-ONBOARDING.md](BLOCK-ONBOARDING.md) — the process for writing a manifest from scratch
- [BLOCKS.md](BLOCKS.md) — current blocks and their manifest summaries
- [MIGRATION.md](MIGRATION.md) — translator output is validated against these manifests
- [TESTING.md](TESTING.md) — how the variable-contract linter enforces the manifest
- [blocks/_template.md](blocks/_template.md) — the per-block design doc that maps onto this contract
- [GLOSSARY.md](GLOSSARY.md) — terms used here (cascade layer, shell CSS, variant, context override)
