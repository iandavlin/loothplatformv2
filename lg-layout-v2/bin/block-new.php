#!/usr/bin/env php
<?php
/**
 * block-new.php — scaffold a new block.
 *
 * Refuses to run unless docs/blocks/<name>.md exists (the design doc is
 * mandatory — see docs/BLOCK-ONBOARDING.md#step-1-design-doc).
 *
 * Generates:
 *   blocks/<name>/
 *     manifest.json   — populated from a minimal template
 *     shell.css       — @layer block-shell skeleton
 *     render.php      — canonical structure with <lg-edit> placeholder
 *     preview.html    — sample markup for the dash Preview modal
 *     README.md       — links to design doc + tests
 *   tests/fixtures/<name>-minimal.json
 *
 * Usage:
 *   bin/block-new.php <name>
 *   bin/block-new.php <name> --insertable       # mark insertable=true
 *   bin/block-new.php <name> --description="..."
 *
 * The scaffold is intentionally minimal — it's a starting structure, not a
 * complete block. You fill in vars, defaults, schema, render logic. The
 * acceptance gate at the end of BLOCK-ONBOARDING.md tells you when you're done.
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);

$args = parseArgs(array_slice($argv, 1));
$positional = array_values(array_filter(array_slice($argv, 1), fn($a) => $a[0] !== '-'));

if (count($positional) !== 1) {
    fwrite(STDERR, "usage: bin/block-new.php <name> [--insertable] [--description=...]\n");
    exit(2);
}
$name = $positional[0];

if (!preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
    fwrite(STDERR, "block-new: name must be lowercase kebab-case (got '$name')\n");
    exit(2);
}

$blockDir   = $ROOT . '/blocks/' . $name;
$designDoc  = $ROOT . '/docs/blocks/' . $name . '.md';
$fixturePath = $ROOT . '/tests/fixtures/' . $name . '-minimal.json';

/* Refuse without design doc */
if (!file_exists($designDoc)) {
    fwrite(STDERR, "block-new: design doc not found at docs/blocks/$name.md\n");
    fwrite(STDERR, "  Create it first by copying docs/blocks/_template.md and filling it in.\n");
    fwrite(STDERR, "  This is step 1 of BLOCK-ONBOARDING.md.\n");
    exit(2);
}

if (is_dir($blockDir)) {
    fwrite(STDERR, "block-new: blocks/$name already exists\n");
    exit(2);
}

@mkdir($blockDir, 0755, true);
@mkdir($ROOT . '/tests/fixtures', 0755, true);

$insertable  = !empty($args['insertable']);
$description = $args['description'] ?? ucfirst($name) . ' block — fill in this description in manifest.json.';

/* ── manifest.json ─────────────────────────────────────────────── */
$manifest = [
    'name'        => $name,
    'version'     => 1,
    'selector'    => '.lg-' . $name,
    'description' => $description,
    'schema' => [
        'props' => [
            /* TODO: fill in props from the design doc */
        ],
        'required' => [],
    ],
    'vars' => [
        'container' => ['padding', 'margin-block', 'bg', 'border', 'radius', 'shadow'],
        'text'      => ['color', 'font-size', 'font-weight'],
    ],
    'defaults' => [
        'container' => [
            'padding'      => '0',
            'margin-block' => '0',
            'bg'           => 'transparent',
            'border'       => 'none',
            'radius'       => '0',
            'shadow'       => 'none',
        ],
        'text' => [
            'color'       => 'inherit',
            'font-size'   => 'inherit',
            'font-weight' => '400',
        ],
    ],
];
if ($insertable) {
    $manifest['editor'] = [
        'insertable'            => true,
        'inline_editable_props' => [],
        'custom_picker'         => null,
        'pill_buttons'          => ['edit', 'tier', 'delete'],
    ];
}
file_put_contents($blockDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

/* ── shell.css ─────────────────────────────────────────────────── */
$shellCss = "@layer block-shell {\n";
$shellCss .= "  .lg-$name {\n";
$shellCss .= "    /* TODO: structural CSS — display, position, grid, aspect-ratio */\n";
$shellCss .= "    display: block;\n\n";
$shellCss .= "    /* Chrome — every property goes through a var. */\n";
$shellCss .= "    padding: var(--lg-padding, 0);\n";
$shellCss .= "    margin-block: var(--lg-margin-block, 0);\n";
$shellCss .= "    background: var(--lg-bg, transparent);\n";
$shellCss .= "    border: var(--lg-border, none);\n";
$shellCss .= "    border-radius: var(--lg-radius, 0);\n";
$shellCss .= "    box-shadow: var(--lg-shadow, none);\n";
$shellCss .= "    color: var(--lg-color, inherit);\n";
$shellCss .= "    font-size: var(--lg-font-size, inherit);\n";
$shellCss .= "    font-weight: var(--lg-font-weight, 400);\n";
$shellCss .= "  }\n";
$shellCss .= "}\n";
file_put_contents($blockDir . '/shell.css', $shellCss);

/* ── render.php ────────────────────────────────────────────────── */
$renderPhp = "<?php\n";
$renderPhp .= "/**\n";
$renderPhp .= " * blocks/$name/render.php\n";
$renderPhp .= " *\n";
$renderPhp .= " * \\\$args  — parsed props (validated against manifest schema)\n";
$renderPhp .= " * \\\$ctx   — render context (post, layout, viewer, editor_mode)\n";
$renderPhp .= " *\n";
$renderPhp .= " * The <lg-edit> marker is emitted by the renderer wrapper, not here.\n";
$renderPhp .= " * render.php just produces the block content.\n";
$renderPhp .= " */\n\n";
$renderPhp .= "/** @var array \$args */\n";
$renderPhp .= "/** @var array \$ctx */\n\n";
$renderPhp .= "// TODO: emit the block HTML using \$args.\n";
$renderPhp .= "// Remember: no hardcoded chrome (use vars), no inline style=, no DB queries.\n";
$renderPhp .= "?>\n";
$renderPhp .= "<div class=\"lg-$name\">\n";
$renderPhp .= "  <!-- block content here -->\n";
$renderPhp .= "</div>\n";
file_put_contents($blockDir . '/render.php', $renderPhp);

/* ── preview.html ──────────────────────────────────────────────── */
/* Sample markup shown in the dash Preview modal. Lorem-ipsum content
   plus a [data-lg-preview-root] anchor so the JS can swap variant
   modifier classes. Authors edit this in place to make the preview
   meaningful — the scaffold ships a generic stub. */
$previewHtml  = "<div class=\"lg-$name\" data-lg-preview-root>\n";
$previewHtml .= "  <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p>\n";
$previewHtml .= "</div>\n";
$previewHtml .= "<script type=\"application/json\" data-lg-preview-variants>{}</script>\n";
file_put_contents($blockDir . '/preview.html', $previewHtml);

/* ── README.md ─────────────────────────────────────────────────── */
$readme = "# blocks/$name\n\n";
$readme .= "Block implementation for `$name`. See the [design doc](../../docs/blocks/$name.md) for purpose, schema, and visual reference.\n\n";
$readme .= "## Files\n\n";
$readme .= "- `manifest.json` — the contract: vars, defaults, schema, editor affordances. See [docs/MANIFEST.md](../../docs/MANIFEST.md).\n";
$readme .= "- `shell.css` — structural CSS, in the `block-shell` cascade layer. Every chrome property is a `var(--lg-*)`.\n";
$readme .= "- `render.php` — receives parsed props in `\$args`, emits HTML.\n";
$readme .= "- `preview.html` — sample markup for the dash Preview modal. Element with `data-lg-preview-root` carries the block class; the JS toggles `--<variant>` modifiers on it. For blocks where variants change inner structure, populate the `data-lg-preview-variants` JSON map with per-variant innerHTML.\n";
$readme .= "- `script.js` (optional) — client-side behavior (defer-mount, etc.). Do not add editor wiring here; the editor framework is data-driven.\n\n";
$readme .= "## Tests\n\n";
$readme .= "- Fixture: [tests/fixtures/$name-minimal.json](../../tests/fixtures/$name-minimal.json)\n";
$readme .= "- Run isolation: `bin/render-test.php --fixture=$name-minimal`\n";
$readme .= "- Run cascade: `bin/render-test.php --all`\n";
$readme .= "- Run lint: `bin/lint-block.php $name`\n";
file_put_contents($blockDir . '/README.md', $readme);

/* ── fixture ───────────────────────────────────────────────────── */
$fixture = [
    'schema' => 1,
    '_meta' => [
        'title'           => "$name — minimal fixture",
        'post_id'         => 1,
        'post_type'       => 'post-imgcap',
        'fixture_purpose' => "Minimal valid $name block. Renders the block in isolation for snapshot testing.",
    ],
    'blocks' => [
        [
            'type' => $name,
            'id'   => 'b_minimal',
            /* TODO: fill in minimum required props from the manifest schema */
        ],
    ],
];
file_put_contents($fixturePath, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

/* ── done ──────────────────────────────────────────────────────── */
fwrite(STDOUT, "block-new: scaffolded '$name'\n");
fwrite(STDOUT, "\n");
fwrite(STDOUT, "Next steps (per docs/BLOCK-ONBOARDING.md):\n");
fwrite(STDOUT, "  1. Fill in blocks/$name/manifest.json — vars, defaults, schema\n");
fwrite(STDOUT, "  2. Write blocks/$name/shell.css — structural CSS, consume only declared vars\n");
fwrite(STDOUT, "  3. Implement blocks/$name/render.php — emit HTML\n");
fwrite(STDOUT, "  4. Edit blocks/$name/preview.html — replace lorem-ipsum stub with sample markup that exercises the block's chrome (and per-variant inner HTML if variants change structure)\n");
fwrite(STDOUT, "  5. Fill in tests/fixtures/$name-minimal.json — minimum valid props\n");
fwrite(STDOUT, "  6. Lint:    bin/lint-block.php $name\n");
fwrite(STDOUT, "  7. Snapshot: bin/render-test.php --fixture=$name-minimal\n");
fwrite(STDOUT, "  8. Cascade: bin/render-test.php --all  (must be clean = no unrelated changes)\n");
fwrite(STDOUT, "  9. Update docs/BLOCKS.md if not already listed\n");
fwrite(STDOUT, "\n");
exit(0);

function parseArgs(array $argv): array {
    $out = [];
    foreach ($argv as $a) {
        if ($a === '--insertable') $out['insertable'] = true;
        elseif (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m)) $out[$m[1]] = $m[2];
    }
    return $out;
}
