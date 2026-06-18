#!/usr/bin/env php
<?php
/**
 * lint-block.php — enforce the manifest-vs-shell.css contract for a block.
 *
 * Checks (per docs/MANIFEST.md and docs/BLOCK-ONBOARDING.md):
 *
 *   M1.  manifest.json parses, has the required top-level keys.
 *   M2.  Every var declared in manifest.vars.{container,text} appears as
 *        --lg-<name> in shell.css.
 *   M3.  Every var(--lg-*) referenced in shell.css is declared in the manifest.
 *   M4.  Every default in manifest.defaults parses as a non-empty value.
 *   M5.  shell.css contains no selectors that reach outside the block
 *        (selector must match the block's manifest.selector at the start).
 *   M6.  shell.css uses no bare chrome properties — every chrome property goes
 *        through var(). Structural properties (display, position, grid, etc.)
 *        are allowed bare.
 *   M7.  Manifest editor.custom_picker is null or a known picker name.
 *   M8.  Manifest editor.pill_buttons references known button names.
 *
 * Exit code: 0 if clean, 1 if any rule violated.
 *
 * Usage:
 *   bin/lint-block.php <name>          # one block
 *   bin/lint-block.php --all           # every block
 *
 * NOTE: This is the Phase 0 linter. Until blocks/ has real manifests +
 * shell.css files (built in Phase 1), --all walks an empty list. The single
 * --block mode works against a directory you point it at.
 */

declare(strict_types=1);

const KNOWN_PICKERS = [null, 'image', 'rich-text', 'carousel-slots', 'embed-url', 'gallery-items', 'media'];
const KNOWN_PILL_BUTTONS = ['edit', 'edit-link', 'ratio', 'tier', 'delete', 'move-up', 'move-down', 'rotate'];

/* Properties that are "chrome" — must be wrapped in var() on the main block
   selector. On sub-element selectors (e.g. .lg-image-caption__num), only the
   COLOR_CHROME subset is enforced — sub-elements often have intentional
   layout/sizing decisions that don't need dash exposure. */
const CHROME_PROPS = [
    'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
    'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'margin-block', 'margin-inline',
    'background', 'background-color',
    'border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-color', 'border-radius',
    'box-shadow', 'color', 'font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing',
    'text-align', 'text-transform', 'text-decoration',
];

/* Properties that bear COLOR or theme-critical visual identity. These must use
   var() even on sub-element selectors, so brand-palette changes cascade. */
const COLOR_CHROME = [
    'color',
    'background', 'background-color',
    'border-color',           /* note: `border` shorthand inspected separately */
    'box-shadow',             /* shadows are part of brand identity */
    'fill', 'stroke',         /* SVG */
];

/* Properties that are "structural" — allowed bare in shell.css */
const STRUCTURAL_PROPS = [
    'display', 'position', 'top', 'right', 'bottom', 'left', 'inset',
    'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height',
    'flex', 'flex-direction', 'flex-wrap', 'flex-grow', 'flex-shrink', 'flex-basis', 'align-items', 'justify-content', 'gap', 'order',
    'grid', 'grid-template-columns', 'grid-template-rows', 'grid-template-areas', 'grid-area', 'grid-column', 'grid-row', 'grid-gap', 'row-gap', 'column-gap',
    'aspect-ratio', 'overflow', 'overflow-x', 'overflow-y', 'object-fit', 'object-position',
    'contain', 'isolation', 'z-index',
    'transition', 'transform', 'opacity', 'visibility',
    'cursor', 'pointer-events', 'user-select',
];

$ROOT = dirname(__DIR__);
$BLOCKS_DIR = $ROOT . '/blocks';

if (!is_dir($BLOCKS_DIR)) {
    fwrite(STDERR, "lint-block: no blocks/ at $BLOCKS_DIR (Phase 1 creates these)\n");
    exit(0);
}

$args = array_slice($argv, 1);
$targets = [];

if (in_array('--all', $args, true)) {
    foreach (glob($BLOCKS_DIR . '/*', GLOB_ONLYDIR) as $d) $targets[] = basename($d);
} elseif (count($args) === 1 && $args[0][0] !== '-') {
    $targets[] = $args[0];
} else {
    fwrite(STDERR, "usage: bin/lint-block.php <name>   |   bin/lint-block.php --all\n");
    exit(2);
}

if (!$targets) {
    fwrite(STDOUT, "lint-block: no blocks to lint\n");
    exit(0);
}

$totalViolations = 0;
foreach ($targets as $name) {
    $violations = lintBlock($name, $BLOCKS_DIR);
    if ($violations) {
        $totalViolations += count($violations);
        fwrite(STDERR, "lint-block [$name]: " . count($violations) . " violation(s)\n");
        foreach ($violations as $v) fwrite(STDERR, "  ✗ $v\n");
    } else {
        fwrite(STDOUT, "lint-block [$name]: clean\n");
    }
}

exit($totalViolations === 0 ? 0 : 1);

/* ───────────────────────────────────────────────────────────────── */

function lintBlock(string $name, string $blocksDir): array {
    $dir = "$blocksDir/$name";
    $violations = [];

    if (!is_dir($dir)) return ["block directory $dir does not exist"];

    /* M1 — manifest */
    $manifestPath = "$dir/manifest.json";
    if (!file_exists($manifestPath)) return ["M1: missing manifest.json"];
    $manifest = json_decode(file_get_contents($manifestPath), true);
    if (!is_array($manifest)) return ["M1: manifest.json is not valid JSON"];

    foreach (['name', 'version', 'selector', 'description', 'schema', 'vars', 'defaults'] as $key) {
        if (!isset($manifest[$key])) $violations[] = "M1: manifest.json missing top-level '$key'";
    }
    if ($manifest['name'] ?? null !== $name) {
        if (($manifest['name'] ?? null) !== $name) {
            $violations[] = "M1: manifest.name '" . ($manifest['name'] ?? '?') . "' does not match directory '$name'";
        }
    }
    if ($violations) return $violations;  /* if manifest itself is broken, stop */

    /* M2, M3, M6 — shell.css contract */
    $shellPath = "$dir/shell.css";
    $shell = file_exists($shellPath) ? file_get_contents($shellPath) : '';
    if ($shell === '') {
        $violations[] = "missing or empty shell.css";
        return $violations;
    }

    $declaredVars = [];
    foreach (($manifest['vars']['container'] ?? []) as $v) $declaredVars[$v] = 'container';
    foreach (($manifest['vars']['text']      ?? []) as $v) $declaredVars[$v] = 'text';

    /* M2 — every declared var appears in shell.css as --lg-<name> */
    foreach (array_keys($declaredVars) as $varName) {
        if (!preg_match('/--lg-' . preg_quote($varName, '/') . '\b/', $shell)) {
            $violations[] = "M2: manifest declares var '$varName' but --lg-$varName doesn't appear in shell.css";
        }
    }

    /* M3 — every var(--lg-*) in shell.css is either declared in the manifest
       OR a known brand token (from src/theme/tokens.json). Brand tokens give
       sub-elements a way to reference theme without per-block dash exposure. */
    $brandTokens = brandTokenSet();
    preg_match_all('/var\(\s*--lg-([a-z0-9-]+)/i', $shell, $m);
    foreach (array_unique($m[1]) as $usedVar) {
        if (isset($declaredVars[$usedVar])) continue;
        if (isset($brandTokens['--lg-' . $usedVar])) continue;
        $violations[] = "M3: shell.css uses --lg-$usedVar which is neither declared in manifest.vars nor a brand token (see src/theme/tokens.json)";
    }

    /* M4 — defaults parse as non-empty for declared vars */
    foreach (['container', 'text'] as $group) {
        $defaults = $manifest['defaults'][$group] ?? [];
        foreach ($defaults as $k => $v) {
            if (!isset($declaredVars[$k]) || $declaredVars[$k] !== $group) {
                $violations[] = "M4: default '$group.$k' is not in manifest.vars.$group";
            }
            if ($v === '' || $v === null) {
                $violations[] = "M4: default '$group.$k' is empty";
            }
        }
    }

    /* M5 — every selector in shell.css starts with the block's selector */
    $blockSelector = $manifest['selector'];
    /* Strip @layer wrappers + comments to find raw selectors */
    $stripped = preg_replace('/@layer\s+[a-z0-9-]+\s*\{/i', '', $shell);
    $stripped = preg_replace('!/\*.*?\*/!s', '', $stripped);
    /* Naive: lines that look like selectors are before { and contain no : (declarations have :) */
    preg_match_all('/([^{}]+?)\{/', $stripped, $sm);
    foreach ($sm[1] as $selectorChunk) {
        foreach (explode(',', $selectorChunk) as $sel) {
            $sel = trim($sel);
            if ($sel === '' || $sel === '}' || str_starts_with($sel, '/*')) continue;
            /* Allow root + the block selector + descendants/pseudo */
            if (!str_starts_with($sel, $blockSelector) && $sel !== ':root' && $sel !== ':where(' . $blockSelector . ')') {
                $violations[] = "M5: shell.css selector '$sel' reaches outside the block (must start with '$blockSelector')";
            }
        }
    }

    /* M6 — bare chrome properties.
       Behavior depends on whether the selector is the BLOCK'S MAIN selector
       (exactly $blockSelector or its variant siblings .lg-name--variant) or a
       SUB-ELEMENT selector (anything deeper, like .lg-image-caption__num):
         - Main selector:    enforce ALL CHROME_PROPS
         - Sub-element:      enforce only COLOR_CHROME (color-bearing chrome)
       Borders are special: `border: <width> <style> <color>` needs the color
       part to be a var(); a hex/rgb literal inside the shorthand fails. */
    foreach (parseRuleBlocks($stripped) as $rule) {
        $isMain = isMainSelector($rule['selector'], $blockSelector);
        $enforce = $isMain ? CHROME_PROPS : COLOR_CHROME;

        foreach ($rule['declarations'] as [$prop, $val]) {
            $prop = strtolower(trim($prop));
            $val  = trim($val);
            if (!in_array($prop, $enforce, true)) continue;

            /* Pass if the value contains var() */
            if (str_contains($val, 'var(--lg-')) continue;

            /* Border shorthand: explicitly check for hex/rgb color in the value */
            if ($prop === 'border' || str_starts_with($prop, 'border-')) {
                if (!preg_match('/#[0-9a-f]{3,8}|rgb|hsl|currentcolor/i', $val)) continue;
                $violations[] = "M6: shell.css has bare color in '$prop: $val' on selector '{$rule['selector']}' — replace the color with var(--lg-*) (brand token or block var)";
                continue;
            }

            $violations[] = "M6: shell.css has bare chrome property '$prop: $val' on selector '{$rule['selector']}' — wrap in var(--lg-*)";
        }
    }

    /* M7 — custom_picker known */
    $picker = $manifest['editor']['custom_picker'] ?? null;
    if ($picker !== null && !in_array($picker, KNOWN_PICKERS, true)) {
        $violations[] = "M7: editor.custom_picker '$picker' is not a known picker";
    }

    /* M8 — pill_buttons known */
    foreach (($manifest['editor']['pill_buttons'] ?? []) as $btn) {
        if (!in_array($btn, KNOWN_PILL_BUTTONS, true)) {
            $violations[] = "M8: editor.pill_buttons has unknown button '$btn'";
        }
    }

    return $violations;
}

/* ── Helpers ────────────────────────────────────────────────────── */

/** Load brand-token names from src/theme/tokens.json as a set. */
function brandTokenSet(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $path = dirname(__DIR__) . '/src/theme/tokens.json';
    if (!is_file($path)) return ($cache = []);
    $raw = json_decode((string) file_get_contents($path), true);
    if (!is_array($raw) || !isset($raw['tokens'])) return ($cache = []);
    return ($cache = array_fill_keys(array_keys($raw['tokens']), true));
}

/**
 * A "main selector" for the block is either the block's own selector,
 * or its variant siblings (.lg-name--variant). Anything else is a
 * sub-element and gets the relaxed M6 enforcement.
 */
function isMainSelector(string $sel, string $blockSelector): bool {
    $sel = trim($sel);
    if ($sel === $blockSelector) return true;
    /* `.lg-foo--variant` — variant on the main element */
    if (preg_match('/^' . preg_quote($blockSelector, '/') . '--[a-z0-9-]+$/', $sel)) return true;
    /* `.lg-foo[...]`, `.lg-foo:hover` etc. — still main element */
    if (preg_match('/^' . preg_quote($blockSelector, '/') . '(?::|\[)/', $sel)) return true;
    return false;
}

/**
 * Parse a CSS-ish string into rule blocks: returns array of
 *   ['selector' => '...', 'declarations' => [[prop, value], ...]]
 *
 * Comma-separated selectors are split — each gets its own entry sharing
 * the same declarations.
 */
function parseRuleBlocks(string $css): array {
    $out = [];
    $len = strlen($css);
    $i = 0;
    while ($i < $len) {
        /* skip whitespace */
        while ($i < $len && ctype_space($css[$i])) $i++;
        if ($i >= $len) break;

        /* find next '{' that opens a rule */
        $brace = strpos($css, '{', $i);
        if ($brace === false) break;
        $selChunk = trim(substr($css, $i, $brace - $i));

        /* find matching '}' */
        $end = strpos($css, '}', $brace);
        if ($end === false) break;
        $body = substr($css, $brace + 1, $end - $brace - 1);

        $declarations = [];
        foreach (explode(';', $body) as $decl) {
            $decl = trim($decl);
            if ($decl === '') continue;
            $colon = strpos($decl, ':');
            if ($colon === false) continue;
            $declarations[] = [substr($decl, 0, $colon), substr($decl, $colon + 1)];
        }

        foreach (explode(',', $selChunk) as $sel) {
            $sel = trim($sel);
            if ($sel === '') continue;
            $out[] = ['selector' => $sel, 'declarations' => $declarations];
        }

        $i = $end + 1;
    }
    return $out;
}
