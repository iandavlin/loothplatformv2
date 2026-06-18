#!/usr/bin/env php
<?php
/**
 * generate-schema-doc.php — write docs/LAYOUT-JSON.md from manifests.
 *
 * Reads every blocks/<name>/manifest.json, emits a single canonical layout
 * JSON reference for authors, uploaders, and migration tooling. The
 * manifests are the source of truth; this script is the projection.
 *
 * Usage:
 *   bin/generate-schema-doc.php                # write docs/LAYOUT-JSON.md
 *   bin/generate-schema-doc.php --check        # exit 1 if file would change (CI gate)
 *   bin/generate-schema-doc.php --stdout       # print to stdout instead of writing
 *
 * Determinism: blocks are emitted alpha-sorted; props within a block follow
 * manifest order (PHP preserves insertion order). No timestamps in output.
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$blocksDir   = "$projectRoot/blocks";
$outPath     = "$projectRoot/docs/LAYOUT-JSON.md";

$check  = in_array('--check',  $argv, true);
$stdout = in_array('--stdout', $argv, true);

/* ── Collect manifests ──────────────────────────────────────────────── */

$manifests = [];
foreach (glob("$blocksDir/*/manifest.json") as $path) {
    $name = basename(dirname($path));
    $raw  = (string) file_get_contents($path);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fwrite(STDERR, "skipping $name: invalid JSON\n");
        continue;
    }
    $manifests[$name] = $data;
}
ksort($manifests);

/* ── Emit the markdown ──────────────────────────────────────────────── */

$out = [];
$out[] = '# Layout JSON reference';
$out[] = '';
$out[] = '> **Auto-generated** from `blocks/*/manifest.json`. Do not edit by hand —';
$out[] = '> run `bin/generate-schema-doc.php` to regenerate. The manifests are the';
$out[] = '> source of truth; this doc is the human-readable projection.';
$out[] = '';
$out[] = '## Top-level shape';
$out[] = '';
$out[] = 'Every layout JSON is an object with this wrapper:';
$out[] = '';
$out[] = '```json';
$out[] = '{';
$out[] = '  "schema": 1,';
$out[] = '  "_meta": { /* optional */ },';
$out[] = '  "blocks": [ /* array of blocks, see Block types below */ ]';
$out[] = '}';
$out[] = '```';
$out[] = '';
$out[] = '| Field | Required | Type | Notes |';
$out[] = '|---|---|---|---|';
$out[] = '| `schema` | yes | integer | Wire-format version. Currently `1`. Validator rejects unknown versions. See `BLOCK-ONBOARDING.md` § Versioning. |';
$out[] = '| `_meta` | no | object | Free-form provenance (translator notes, source bundle, etc.). Ignored by the renderer. |';
$out[] = '| `blocks` | yes | array | Ordered list of blocks. Each block is one of the types below. |';
$out[] = '';
$out[] = '## Common block fields';
$out[] = '';
$out[] = 'Every block, regardless of type, may carry these fields in addition to its type-specific props:';
$out[] = '';
$out[] = '| Field | Required | Type | Notes |';
$out[] = '|---|---|---|---|';
$out[] = '| `type` | yes | string | Block type. Must match one of the keys below. |';
$out[] = '| `id` | no | string | Stable per-instance id (e.g., `b_abc123`). Auto-assigned `b_` + 6 chars if omitted. The editor needs ids to address blocks. |';
$out[] = '| `gated_tier` | no | string | Tier-gating. Viewers not satisfying the tier skip this block silently. Allowed values are project-defined; see `TierResolver`. |';
$out[] = '| `variant` | no | string | Variant key. Must be declared in the block\'s manifest under `variants`. Available variants per block listed below. |';
$out[] = '';
$out[] = '## Block types';
$out[] = '';
$out[] = 'Alphabetical. Each block lists its current `manifest.version`, props, variants, and any structural notes.';
$out[] = '';

foreach ($manifests as $name => $m) {
    $version     = (int) ($m['version'] ?? 1);
    $description = (string) ($m['description'] ?? '');
    $selector    = (string) ($m['selector'] ?? '');
    $insertable  = !empty($m['editor']['insertable']);

    $out[] = "### `$name`";
    $out[] = '';
    $out[] = "*Version $version. Selector `$selector`. " . ($insertable ? 'Insertable.' : 'Not insertable — emitted by shell or container only.') . "*";
    $out[] = '';
    if ($description !== '') {
        $out[] = $description;
        $out[] = '';
    }

    /* Columns: structural exception — document the per-column-bucket shape. */
    if ($name === 'columns') {
        $out[] = '**Structural shape** (overrides the standard `blocks` field):';
        $out[] = '';
        $out[] = '```json';
        $out[] = '{';
        $out[] = '  "type": "columns",';
        $out[] = '  "columns": [';
        $out[] = '    { "blocks": [ /* children of column 1 */ ] },';
        $out[] = '    { "blocks": [ /* children of column 2 */ ] }';
        $out[] = '  ]';
        $out[] = '}';
        $out[] = '```';
        $out[] = '';
        $out[] = 'Column count is `columns.length` and must be 2 or 3. Nesting columns inside columns is rejected by the validator.';
        $out[] = '';
    }

    /* Props table. */
    $props = is_array($m['schema']['props'] ?? null) ? $m['schema']['props'] : [];
    $required = is_array($m['schema']['required'] ?? null) ? $m['schema']['required'] : [];
    if ($props) {
        $out[] = '**Props**';
        $out[] = '';
        $out[] = '| Name | Required | Type | Default | Notes |';
        $out[] = '|---|---|---|---|---|';
        foreach ($props as $propName => $propDef) {
            $type     = (string) ($propDef['type'] ?? '?');
            $req      = in_array($propName, $required, true) ? 'yes' : 'no';
            $default  = array_key_exists('default', $propDef) ? json_encode($propDef['default'], JSON_UNESCAPED_SLASHES) : '—';
            $enum     = isset($propDef['enum']) ? ' Enum: `' . implode('` / `', array_map(fn($v) => is_string($v) ? $v : (string) json_encode($v), $propDef['enum'])) . '`.' : '';
            $desc     = trim((string) ($propDef['description'] ?? ''));
            /* Escape pipes in cell contents — Markdown table cells. */
            $desc     = str_replace('|', '\|', $desc);
            $out[]    = "| `$propName` | $req | `$type` | `$default` | $desc$enum |";
        }
        $out[] = '';
    }

    /* Variants list — key + extends + the actual CSS-var overrides the
       variant declares. Shows authors what each variant changes vs. the
       block defaults, not just its name. */
    $variants = is_array($m['variants'] ?? null) ? $m['variants'] : [];
    if ($variants) {
        $out[] = '**Variants**';
        $out[] = '';
        foreach ($variants as $vname => $vdef) {
            $extends = isset($vdef['extends']) ? " (extends `{$vdef['extends']}`)" : '';
            $out[] = "- `$vname`$extends";
            foreach (['container', 'text'] as $group) {
                $overrides = is_array($vdef[$group] ?? null) ? $vdef[$group] : [];
                foreach ($overrides as $varName => $varValue) {
                    $out[] = "    - `$group.$varName`: `" . str_replace('`', '\\`', (string) $varValue) . '`';
                }
            }
        }
        $out[] = '';
    }

    /* Context overrides. */
    $contexts = is_array($m['context_overrides'] ?? null) ? $m['context_overrides'] : [];
    if ($contexts) {
        $out[] = '**Context normalization**: participates in ' . implode(', ', array_map(fn($c) => "`$c`", $contexts)) . '.';
        $out[] = '';
    }

    $out[] = '---';
    $out[] = '';
}

$out[] = '## Examples';
$out[] = '';
$out[] = '### Minimal layout (a heading + a paragraph)';
$out[] = '';
$out[] = '```json';
$out[] = '{';
$out[] = '  "schema": 1,';
$out[] = '  "blocks": [';
$out[] = '    { "type": "heading", "level": "h2", "text": "Hello world" },';
$out[] = '    { "type": "wysiwyg", "html": "<p>First post.</p>" }';
$out[] = '  ]';
$out[] = '}';
$out[] = '```';
$out[] = '';
$out[] = '### Two-column layout';
$out[] = '';
$out[] = '```json';
$out[] = '{';
$out[] = '  "schema": 1,';
$out[] = '  "blocks": [';
$out[] = '    {';
$out[] = '      "type": "columns",';
$out[] = '      "columns": [';
$out[] = '        { "blocks": [ { "type": "image", "image_id": 123 } ] },';
$out[] = '        { "blocks": [ { "type": "wysiwyg", "html": "<p>Caption-style prose.</p>" } ] }';
$out[] = '      ]';
$out[] = '    }';
$out[] = '  ]';
$out[] = '}';
$out[] = '```';
$out[] = '';
$out[] = '### Embed with caption';
$out[] = '';
$out[] = '```json';
$out[] = '{';
$out[] = '  "schema": 1,';
$out[] = '  "blocks": [';
$out[] = '    {';
$out[] = '      "type": "embed",';
$out[] = '      "url": "https://www.youtube.com/watch?v=dQw4w9WgXcQ",';
$out[] = '      "caption": "Optional caption text."';
$out[] = '    }';
$out[] = '  ]';
$out[] = '}';
$out[] = '```';
$out[] = '';
$out[] = '*YouTube `shorts/` URLs auto-resolve to 9×16; standard YT URLs resolve to 16×9. Instagram URLs route through `embeds.js` and ignore `ratio`.*';
$out[] = '';

$markdown = implode("\n", $out) . "\n";

/* ── Output ─────────────────────────────────────────────────────────── */

if ($stdout) {
    echo $markdown;
    exit(0);
}

if ($check) {
    $existing = is_file($outPath) ? (string) file_get_contents($outPath) : '';
    if ($existing === $markdown) {
        fwrite(STDOUT, "docs/LAYOUT-JSON.md is up to date\n");
        exit(0);
    }
    fwrite(STDERR, "docs/LAYOUT-JSON.md is STALE. Run bin/generate-schema-doc.php.\n");
    exit(1);
}

file_put_contents($outPath, $markdown);
fwrite(STDOUT, sprintf("wrote %s (%d bytes, %d blocks)\n", $outPath, strlen($markdown), count($manifests)));
