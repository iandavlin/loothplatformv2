# Glossary

Terms used across the v2 docs. If something in another doc isn't obvious, look here first.

## A–B

**ACF (Advanced Custom Fields)**
WordPress plugin used by Looth Group to author posts in v1. Provides field groups bound to CPTs (e.g., the "Article" field group with a repeater of image/caption/text/oembed rows). v2's [MIGRATION.md](MIGRATION.md) reads ACF-authored posts via the exporter and translator pipeline.

**Authority ladder**
The ordered list of layers that determine a block's final visual appearance. From least to most authoritative: reset, theme, block-shell, block-defaults, context, dash. See [ARCHITECTURE.md#the-authority-ladder](ARCHITECTURE.md#the-authority-ladder).

**Block**
A self-contained unit of content in the layout JSON. Each block is a directory under `blocks/<name>/` containing a manifest, shell CSS, render template, and optional client JS. See [BLOCKS.md](BLOCKS.md) for the inventory.

**Block-defaults layer**
The CSS cascade layer where each block's out-of-the-box variable values are emitted. Generated from each block's `manifest.json` defaults. See [ARCHITECTURE.md#cascade-layers](ARCHITECTURE.md#cascade-layers).

**Block-shell layer**
The CSS cascade layer where each block's structural CSS lives (`display`, `grid`, `position`, `aspect-ratio`). No visual chrome here — chrome goes through variables resolved later in the cascade. See [ARCHITECTURE.md#cascade-layers](ARCHITECTURE.md#cascade-layers).

**Bundle**
The fat JSON file produced by the exporter for a single post. Contains everything the translator needs: post fields, author, taxonomies, attachments, all ACF fields, all postmeta, pre-resolved media, current rendered HTML. See [MIGRATION.md#bundle-format](MIGRATION.md#bundle-format).

## C–D

**Cascade**
1. *CSS cascade*: the standard rules browsers use to resolve which style declaration wins. Native CSS feature.
2. *Cascade test*: a test that verifies a change in one place doesn't unintentionally propagate to other places. See [TESTING.md#cascade-tests](TESTING.md#cascade-tests).

**Cascade layer (`@layer`)**
Native CSS feature that groups rules into named layers. Later layers always win over earlier layers regardless of selector specificity. v2 uses six layers: `reset, theme, block-shell, block-defaults, context, dash`. See [ARCHITECTURE.md#cascade-layers](ARCHITECTURE.md#cascade-layers).

**Context (CSS layer)**
The layer above `block-defaults` where context-aware variants live. E.g., `.lg-columns__col > .lg-image-caption` normalizing chrome for blocks inside columns. Always loses to `dash` (so user overrides win). See [ARCHITECTURE.md#cascade-layers](ARCHITECTURE.md#cascade-layers).

**Context override**
A block's declaration in its manifest's `context_overrides` array that it participates in a named context's normalization. The `context` layer's generator walks all manifests to build the selector list. See [MANIFEST.md#context-overrides](MANIFEST.md#context-overrides).

**CPT (Custom Post Type)**
A WordPress post type beyond the built-in `post` / `page`. Looth Group uses many: `post-imgcap`, `loothprint`, `loothcut`, `document`, `event`, etc. Each can have its own ACF group and its own v2 shell template.

**Dash**
1. The admin form at `WP Admin → LG Layout v2 → Block Styles` where authors customize block chrome.
2. The CSS cascade layer where dash-generated rules live. Always wins.
See [ARCHITECTURE.md#cascade-layers](ARCHITECTURE.md#cascade-layers).

**Delinquent**
Internal state in the [tier resolver](#tier-resolver): the viewer's billing has lapsed and their effective tier is downgraded for gating purposes. v1 represented this as `looth1`; in v2 it's a state inside the resolver, not a tier name. See [ARCHITECTURE.md#tier-resolver](ARCHITECTURE.md#tier-resolver).

## E–H

**Editor mode**
The render pipeline run with extra annotations: `<lg-edit>` markers for each block, contenteditable on inline-editable props, and the editor JS framework loaded. Triggered by `?preview=true` for users who can `edit_post`.

**Editor framework**
The data-driven inline editor in v2. Reads each block's manifest `editor` field and wires pills, drop zones, custom pickers, and inline-edit handlers automatically. No per-block branches in `admin.js`.

**Exporter**
PHP script that walks a WP post and writes a [bundle](#bundle) JSON. Universal — same code for every CPT. The first step of legacy migration. See [MIGRATION.md](MIGRATION.md).

**Fixture**
A JSON file in `tests/fixtures/` describing one article scenario for the test harness. Each fixture has expected outputs (HTML + CSS bundle) committed at `tests/expected/`. See [TESTING.md#fixtures](TESTING.md#fixtures).

**Harness**
The CLI tools that run the v2 render pipeline outside WordPress. `bin/render-test.php` for PHP-side rendering, `bin/editor-test.js` for headless-Chrome editor assertions. See [TESTING.md](TESTING.md).

## I–M

**Inert addition test**
A cascade test: after adding a new block, re-render every existing fixture (none of which use the new block). The output diff must be empty. Catches blocks that leak global selectors. See [TESTING.md#inert-addition](TESTING.md#inert-addition).

**Manifest**
The `blocks/<name>/manifest.json` file declaring a block's CSS variables, defaults, schema for post props, editor affordances, and context participation. The single source of truth for the block. See [MANIFEST.md](MANIFEST.md).

**Marker (`<lg-edit>`)**
A custom HTML element emitted by the renderer in editor mode that points to the block immediately following it. The editor's `wireMarkers` function pairs each marker with its host block to attach edit pills and event handlers. The marker is removed after wiring.

## N–R

**Pill**
The floating control bar attached to each editable block in editor mode. Buttons like Edit, Delete, Tier, Ratio. Built automatically from the manifest's `editor.pill_buttons` field.

**Render pipeline**
The six-stage process that turns layout JSON + dash overrides into article HTML + CSS bundle. Runs identically in CLI (harness) and WP (plugin). See [ARCHITECTURE.md#the-render-pipeline](ARCHITECTURE.md#the-render-pipeline).

**Reset layer**
The first cascade layer. Minimal CSS reset (normalize.css-ish). Loses to every other layer. See [ARCHITECTURE.md#cascade-layers](ARCHITECTURE.md#cascade-layers).

## S

**Schema**
1. *Block schema*: the `schema` field in a block's manifest, declaring the post-JSON props it accepts. The validator rejects malformed blocks. See [MANIFEST.md#schema](MANIFEST.md#schema).
2. *Layout schema*: the top-level `schema: 1` field in an article's layout JSON. Bumped when the layout format changes incompatibly.

**Shell CSS**
The `blocks/<name>/shell.css` file containing the block's structural rules (in the `block-shell` cascade layer). Every chrome property goes through a CSS variable; no bare values. See [MANIFEST.md#vars](MANIFEST.md#vars) and [ARCHITECTURE.md#css-variable-convention](ARCHITECTURE.md#css-variable-convention).

**Shell template (CPT shell)**
A wrapper layout at `storage/shells/<cpt>.json` that defines the structure surrounding a post's own blocks. Typically: hero + byline + post-body + share + footer-partial. The `post-body` block in a shell resolves to the post's `_lg_layout_v2` blocks. One shell per CPT. See [MIGRATION.md#switching-cpts-onto-v2](MIGRATION.md#switching-cpts-onto-v2).

**Snapshot**
The expected rendered output of a fixture, committed at `tests/expected/<fixture>/`. The harness diffs each fixture's actual output against its snapshot. See [TESTING.md#snapshots](TESTING.md#snapshots).

## T–Z

**Targeted insertion test**
A cascade test: after adding a new block, add it to one canonical fixture and re-render. The diff should affect only that fixture. See [TESTING.md#targeted-insertion](TESTING.md#targeted-insertion).

**Theme layer**
The cascade layer where brand tokens (`--lg-amber`, `--lg-sage-3`, `--lg-cream`) and font registrations live. Defined at `:root`, consumed by `block-defaults` and `dash`. See [ARCHITECTURE.md#cascade-layers](ARCHITECTURE.md#cascade-layers).

**Tier**
Named access level for content gating. v2 uses four names: `public`, `looth-lite`, `looth-pro`, `admin`. Maps to the `tier` taxonomy in WP. See [ARCHITECTURE.md#tier-resolver](ARCHITECTURE.md#tier-resolver).

**Tier resolver**
The PHP class `TierResolver` with one method: `satisfies( $viewer, $required_tier ): bool`. Handles delinquent state, admin bypass, preview-as overrides, and taxonomy membership in one place. Blocks just declare `gated_tier`; they don't care how the resolver gets there. See [ARCHITECTURE.md#tier-resolver](ARCHITECTURE.md#tier-resolver).

**Translator**
The script (or Claude skill, or person) that reads a [bundle](#bundle) and emits a v2 layout JSON. Per-CPT, because the structural mapping differs. See [MIGRATION.md#translator-contract](MIGRATION.md#translator-contract).

**Variable contract**
The relationship between a block's manifest (declares which CSS variables exist) and its shell.css (consumes those variables). The contract linter (`bin/lint-block.php`) enforces that every declared var is used and every used var is declared. See [MANIFEST.md#vars](MANIFEST.md#vars).

**Variant**
A named alternative configuration of a block. Same DOM, different visual treatment. Each variant has its own dash sub-panel and emits as `.lg-<block>--<variant>` in CSS. E.g., `wysiwyg` has `boxed` and `plain` variants. See [MANIFEST.md#variants](MANIFEST.md#variants).

**wireMarkers**
The editor JS function that walks every `<lg-edit>` marker, pairs it with its host block, attaches a pill, and removes the marker. Must be idempotent (running twice = no double-binding). Failure to bind is what caused the "can't save a new embed" bug in v1.

---

**See also**
- [README.md](README.md) — entry point for the docs
- [ARCHITECTURE.md](ARCHITECTURE.md) — most terms here are defined more fully there
- [MANIFEST.md](MANIFEST.md) — the manifest's vocabulary
- [BLOCK-ONBOARDING.md](BLOCK-ONBOARDING.md) — process the glossary's onboarding terms refer to
- [BLOCKS.md](BLOCKS.md) — the catalog the glossary's block terms describe
- [MIGRATION.md](MIGRATION.md) — bundle/translator/exporter detail
- [TESTING.md](TESTING.md) — fixture/snapshot/cascade detail
