# Testing

The test harness runs the v2 render pipeline outside WordPress so the architecture's invariants can be verified in CI and in dev without a browser. It also runs editor-pipeline tests in headless Chrome to verify the inline editor's wiring.

Two tracks:

- **`bin/render-test.php`** — pure PHP, no browser. Runs the same `Renderer` + `CssBuilder` classes used in the WP plugin.
- **`bin/editor-test.js`** — Node + headless Chrome via CDP. Loads `mockup/editor-pipeline.html` and asserts DOM wiring.

Both run from a single entry: `make test`.

## Why the harness exists

Same code runs in CLI and WP (see [ARCHITECTURE.md#the-render-pipeline](ARCHITECTURE.md#the-render-pipeline)). If the harness passes, the WP integration is just plumbing — the pipeline itself is verified.

Three things the harness catches that v1 had no defense against:

1. **Cascade regressions** — a CSS or manifest change unintentionally affects other blocks. Caught by snapshot diffs across the whole corpus.
2. **Editor wiring failures** — `wireMarkers` doesn't bind, native form submission falls through silently. Caught by headless-chrome assertions on the editor mockup.
3. **Manifest contract drift** — a block's `shell.css` uses a var not declared in the manifest, or declares a var it doesn't use. Caught by `bin/lint-block.php`.

## Running

```bash
make test                      # everything
make test-render               # PHP harness only
make test-editor               # editor (Node + headless Chrome) only
make test-lint                 # all linters (block + docs)

bin/render-test.php --all      # run every fixture
bin/render-test.php --block=image-caption        # one block in isolation
bin/render-test.php --fixture=simple-article     # one specific fixture
bin/render-test.php --update-snapshots           # accept current output as new baseline
```

Headless Chrome must be running:

```bash
sudo docker run -d --name lg-headless --rm --network host \
  zenika/alpine-chrome --no-sandbox \
  --remote-debugging-address=0.0.0.0 --remote-debugging-port=9333 \
  --disable-gpu --headless --hide-scrollbars about:blank
```

The editor harness connects to `127.0.0.1:9333` by default.

## Fixtures

Inputs to the harness. Live in `tests/fixtures/`. Each fixture is a triple:

```
tests/fixtures/
  simple-article.json         # the article JSON (post-shaped, not WP post meta)
  simple-article.dash.json    # the dash overrides for this fixture (optional)
  simple-article.viewer.json  # the viewer context (tier, role) (optional)
```

The fixture format mirrors what the v2 renderer would receive from WP:

```json
{
  "_meta": {
    "title": "Simple Article",
    "post_id": 1,
    "post_type": "post-imgcap",
    "author_id": 1
  },
  "blocks": [
    { "type": "prose", "id": "b_1", "html": "<p>Hello.</p>" },
    { "type": "image", "id": "b_2", "image_id": 100, "caption": "An image." }
  ]
}
```

Media IDs in fixtures resolve via a stub media map (`tests/fixtures/_media.json`) so the harness doesn't need a DB connection.

Standard fixtures shipped with Phase 0:

| Fixture | Purpose |
|---|---|
| `simple-article.json` | Minimal happy path. One prose + one image + one embed. |
| `edge-cases.json` | IG embed (no aspect-ratio reservation); image-in-columns; gated paywall; empty embed in editor mode; nested-columns-attempt (should fail validation). |
| `loothprint-sample.json` | Exercises the new `download` block. |
| `cascade-bg.json` | image-caption in columns with dash overriding `--lg-bg`. Validates dash wins over context normalization. |
| `integration-<name>.json` | One per block, used in cascade impact tests (see [BLOCK-ONBOARDING.md#step-5-cascade-impact-tests](BLOCK-ONBOARDING.md#step-5-cascade-impact-tests)). |

Adding a fixture: drop the JSON in `tests/fixtures/`, run `bin/render-test.php --fixture=<name>` once, commit the generated `tests/expected/<name>.html` + `.css`. Future runs diff against the baseline.

## Snapshots

After rendering a fixture, the harness writes:

```
tests/output/<fixture>/
  rendered.html            # the full article HTML
  bundle.css               # the generated CSS bundle
  variables-resolved.json  # for each block instance, the effective var values
  validation.log           # validator output (errors / warnings)
```

The expected versions live at `tests/expected/<fixture>/`. Diff is byte-for-byte. The CSS bundle is normalized (sorted by layer, then alphabetized within layer) before diff so reorderings don't show as changes.

Snapshot update flow:

```bash
# 1. Make a change you believe is intentional
# 2. Run tests
bin/render-test.php --all

# 3. Inspect any diffs
diff -u tests/expected/simple-article/rendered.html tests/output/simple-article/rendered.html

# 4. If correct, accept
bin/render-test.php --update-snapshots

# 5. Commit the updated snapshots
git add tests/expected/
```

The `--update-snapshots` flag is the single way to move a baseline. The harness never moves baselines silently.

## Cascade tests

The biggest defense against the v1 problem of "changed one thing, broke five others."

### Inert addition

When you add a new block, re-render every existing fixture *without* using the new block. The output diff must be empty.

```bash
bin/render-test.php --all --inert-check
```

If anything moved, the new block has a side effect — its shell.css likely contains a global selector, or its `context_overrides` declaration affected an unintended selector.

### Targeted insertion

When you add a new block, add it to one canonical fixture and re-render. The diff should affect only that fixture.

```bash
bin/render-test.php --fixture=integration-<name>
```

### Manifest-change cascade

When you change a manifest default (e.g., `image-caption` now has `--lg-radius: 12px` instead of `8px`), re-render every fixture and review the diff. Every fixture that uses that block should change in the expected way; nothing else should.

```bash
bin/render-test.php --all
# review diffs, accept with --update-snapshots if intentional
```

### Migration cascade

When migrating legacy posts (see [MIGRATION.md](MIGRATION.md)), render the same post under v1 and v2, snapshot both, compare.

```bash
bin/migration-diff.php --post-id=69206
```

Outputs an HTML side-by-side diff at `storage/migration-diffs/<post-id>.html`. Visual regressions surface immediately.

## Variable-contract linter

`bin/lint-block.php <name>` enforces the manifest's contract:

- Every var declared in the manifest appears in `shell.css`.
- Every `var(--lg-*)` referenced in `shell.css` is declared in the manifest.
- Manifest `defaults` values parse as valid CSS for their property.
- No selectors in `shell.css` reach outside the block's own class.
- No bare property values in `shell.css` (everything goes through a var).
- Schema props have valid types and enums.
- Editor `custom_picker` and `pill_buttons` reference registered values.

Runs per-block or for all blocks:

```bash
bin/lint-block.php image-caption       # one block
bin/lint-block.php --all               # all blocks
```

Fails CI on any violation.

## Doc-drift linter

`bin/lint-docs.php` enforces the doc conventions from [README.md#conventions](README.md#conventions):

- Every `.md` file in `docs/` is listed in `README.md`'s Index table.
- Every doc has a "See also" section at the bottom.
- "See also" links are reciprocal — if A's "See also" links to B, B's "See also" links back to A.
- No broken intra-doc links (every `[text](path.md)` resolves to a real file).
- Every per-block doc in `docs/blocks/` is referenced from `BLOCKS.md`.

Runs from `make test-lint`. Fails CI on any violation.

## Editor harness

`bin/editor-test.js` is a Node script that connects to headless Chrome via CDP, loads `mockup/editor-pipeline.html` (which embeds the v2 editor framework), and asserts DOM behavior.

Standard assertions:

- **wireMarkers idempotence** — run wireMarkers twice; no double pills, no double event listeners.
- **wireMarkers failure recovery** — simulate the editor JS partially failing to load; assert the empty embed URL form has a `preventDefault` failsafe so it doesn't silently fall through to native form submission (the v1 bug).
- **Empty embed URL form submit** — paste URL, click Embed, assert `POST /lg-layout/v2/block` fires with the right payload.
- **Patch → re-hydrate** — after a successful patch, assert the new embed renders, the marker is removed, the new pill is attached, no orphan listeners on stale DOM.
- **TinyMCE blur-save** — type in a prose block, blur, assert patch payload includes sanitized HTML and is sent.
- **Drop-zone insert** — click `+` drop zone, pick a block type, assert insert REST call fires with correct anchor.
- **Concurrent edit collision** — two patches in quick succession against the same block; assert serial ordering and no lost updates.
- **Block-type coverage** — for each block declared `editable_props` or `custom_picker`, assert the framework dispatches correctly.

Each assertion is independent. The harness reports PASS/FAIL per case with a screenshot on failure (saved to `tests/output/editor-screenshots/`).

## CI gate

The full `make test` runs:

1. `bin/lint-block.php --all`
2. `bin/lint-docs.php`
3. `bin/render-test.php --all`
4. `bin/render-test.php --all --inert-check` (only meaningful when a new block was added)
5. `bin/editor-test.js` (if headless Chrome is reachable)

Exit code 0 = green. Any non-zero = fail, with the failing test's diff or stack trace.

This is the gate for every commit that touches `blocks/`, `src/`, or `docs/`.

---

**See also**
- [ARCHITECTURE.md](ARCHITECTURE.md) — the pipeline these tests verify
- [MANIFEST.md](MANIFEST.md) — the contract the variable-contract linter enforces
- [BLOCK-ONBOARDING.md](BLOCK-ONBOARDING.md) — how new blocks integrate with the test harness
- [MIGRATION.md](MIGRATION.md) — how the harness validates legacy-post migration
- [GLOSSARY.md](GLOSSARY.md) — terms used here (fixture, snapshot, cascade, contract linter)
