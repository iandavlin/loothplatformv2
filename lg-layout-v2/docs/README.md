# lg-layout-v2 docs

A from-scratch rewrite of `lg-layout`. JSON-driven article layouts for Looth Group WordPress CPTs, with a cascade-layer CSS architecture, a manifest-driven block toolkit, and a test harness that runs outside WordPress.

This directory is the source of truth for how the plugin is *meant* to work. If the code disagrees with the docs, treat that as a bug in one or the other and fix it before writing more code.

## For AI agents or new developers picking this up cold

Read in this order:

1. **[ARCHITECTURE.md](ARCHITECTURE.md)** — the big picture: cascade layers, authority ladder, CSS variable convention, render pipeline stages, tier resolver. ~10 min, end to end.
2. **[MANIFEST.md](MANIFEST.md)** — what a block declares and why. The manifest is the single source of truth for each block's contract.
3. **[BLOCKS.md](BLOCKS.md)** — the current block inventory, one paragraph each. Skim to know what's available.
4. Then jump to the doc relevant to your task (see [Quick links](#quick-links-by-task) below).

Do not skip ARCHITECTURE.md. Most of the bugs in v1 came from changes that didn't grasp the cascade story. Re-reading it is faster than rediscovering it the hard way.

## Index

| Doc | Purpose |
|---|---|
| [ARCHITECTURE.md](ARCHITECTURE.md) | Cascade layers, CSS variable convention, render pipeline, tier resolver |
| [MANIFEST.md](MANIFEST.md) | Block manifest spec — the contract every block declares |
| [BLOCK-ONBOARDING.md](BLOCK-ONBOARDING.md) | 7-step process for adding a new block (or variant, or prop) |
| [BLOCKS.md](BLOCKS.md) | Current block inventory with one-paragraph descriptions |
| [blocks/](blocks/) | Per-block design docs — one file per block |
| [blocks/_template.md](blocks/_template.md) | Design doc template — copy this when scaffolding a new block |
| [MIGRATION.md](MIGRATION.md) | Legacy ACF/Elementor posts → v2 layout JSON. Exporter + translator flow. |
| [TESTING.md](TESTING.md) | Harness, fixtures, snapshots, cascade regression tests |
| [GLOSSARY.md](GLOSSARY.md) | Shared terminology — read this if a term in another doc isn't obvious |

## Quick links by task

- **Adding a block** → [BLOCK-ONBOARDING.md](BLOCK-ONBOARDING.md)
- **Adding a variant of an existing block** (e.g., wysiwyg → boxed/plain) → [BLOCK-ONBOARDING.md#variant-flow](BLOCK-ONBOARDING.md#variant-flow)
- **Debugging a CSS specificity issue** → [ARCHITECTURE.md#cascade-layers](ARCHITECTURE.md#cascade-layers)
- **Understanding why the dash setting didn't apply** → [ARCHITECTURE.md#authority-ladder](ARCHITECTURE.md#authority-ladder)
- **Importing a legacy ACF-authored post** → [MIGRATION.md](MIGRATION.md)
- **Running the test harness** → [TESTING.md#running](TESTING.md#running)
- **Adding a fixture** → [TESTING.md#fixtures](TESTING.md#fixtures)
- **Changing a block's default styling** → [MANIFEST.md#defaults](MANIFEST.md#defaults), then verify cascade impact per [TESTING.md#cascade-tests](TESTING.md#cascade-tests)
- **A term in a doc isn't obvious** → [GLOSSARY.md](GLOSSARY.md)

## Conventions

These rules apply to every doc in this directory. The linter (`bin/lint-docs.php`) enforces them.

1. **Every doc is listed in this README's [Index](#index).** If you add a new doc, add it here too.
2. **Every doc ends with a "See also" footer** linking to related docs with a one-line context note. Example:
   ```markdown
   ---

   **See also**
   - [ARCHITECTURE.md](ARCHITECTURE.md) — the cascade layer this contract slots into
   - [BLOCK-ONBOARDING.md](BLOCK-ONBOARDING.md) — process for using this contract
   ```
3. **Cross-references are reciprocal.** If A's "See also" links to B, B's "See also" links to A. The linter flags asymmetric pairs.
4. **Headings are short enough to be anchor links.** Avoid full-sentence headings. `## Cascade layers` good; `## How the cascade layers actually work in practice` bad.
5. **Date and decision provenance**: when a doc records a decision, link to the doc, conversation, or commit that decided it. Future-readers need to retrace the reasoning.
6. **No silent edits to the design**: if you change ARCHITECTURE.md or MANIFEST.md in a way that breaks an existing block, update the affected per-block doc in the same commit.

## Phase status

Phase 0 (docs + mockups + test harness) — **in progress**. Outputs live in this directory. No plugin code has been written. Next gate: human review of these docs before Phase 1 (core pipeline implementation) starts.

See [ARCHITECTURE.md#rewrite-phases](ARCHITECTURE.md#rewrite-phases) for the full phase plan.

---

**See also**
- [ARCHITECTURE.md](ARCHITECTURE.md) — the technical big picture this README links into
- [GLOSSARY.md](GLOSSARY.md) — terms used across all docs
