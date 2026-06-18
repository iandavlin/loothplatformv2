<?php
/**
 * CssBuilder — assemble the v2 CSS bundle from manifests + theme + dash.
 *
 * Layer order (declared once at top, then populated):
 *
 *     @layer reset, theme, block-shell, block-defaults, context, dash;
 *
 * - reset:           hand-written, lives in src/css/reset.css
 * - theme:           brand tokens at :root from BrandTokens + theme.css
 * - block-shell:     concatenated blocks/<name>/shell.css
 * - block-defaults:  emitted from each manifest's defaults + variants
 * - context:         emitted from manifests that declare context_overrides
 * - dash:            emitted from the dash overrides option
 *
 * Output is deterministic: blocks alphabetized, vars in manifest order. The
 * harness depends on this to make snapshot diffs meaningful.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class CssBuilder
{
    /** Named context normalization templates (see docs/MANIFEST.md#context-overrides). */
    private const CONTEXTS = [
        'columns' => [
            'selector_template' => '.lg-columns__col > {selector}',
            'vars' => [
                'padding'      => '0',
                'margin-block' => '0',
                'bg'           => 'transparent',
                'border'       => 'none',
                'shadow'       => 'none',
            ],
        ],
    ];

    /**
     * Build the full CSS bundle.
     *
     * @param array<string, array> $manifests      output of Manifest::all()
     * @param array<string, string> $brandTokens   :root var name → value
     * @param array<string, array>  $dashOverrides per-block dash entries: name → ['container' => [...], 'text' => [...]]
     */
    public static function build(array $manifests, array $brandTokens, array $dashOverrides): string
    {
        $lines = [];
        $lines[] = '/* lg-layout-v2 — generated bundle. Do not edit by hand. */';
        /* `legacy` is the bucket Isolate dumps every other plugin's CSS into
           via @import. Declaring it FIRST means anything wrapped in
           layer(legacy) loses to every v2 layer that follows. Out of an
           abundance of caution it's declared whether or not isolation is
           enabled — costs nothing when no rule is in it. */
        $lines[] = '@layer legacy, reset, theme, block-shell, block-defaults, context, dash;';
        $lines[] = '';

        $lines = array_merge($lines, self::resetLayer());
        $lines[] = '';
        $lines = array_merge($lines, self::themeLayer($brandTokens));
        $lines[] = '';
        $lines = array_merge($lines, self::blockShellLayer($manifests));
        $lines[] = '';
        $lines = array_merge($lines, self::blockDefaultsLayer($manifests));
        $lines[] = '';
        $lines = array_merge($lines, self::contextLayer($manifests));
        $lines[] = '';
        $lines = array_merge($lines, self::dashLayer($manifests, $dashOverrides));

        return implode("\n", $lines) . "\n";
    }

    /* ── Per-layer emitters ──────────────────────────────────────── */

    private static function resetLayer(): array
    {
        return [
            '@layer reset {',
            '  *, *::before, *::after { box-sizing: border-box; }',
            '  .lg-article img, .lg-article video { max-width: 100%; }',
            /* Neutralize the UA <figure> default (margin: 1em 40px). Figure-based
               blocks (image, embed) drive their own vertical spacing via the
               manifest --lg-margin-block var; the UA inline 40px would otherwise
               make them visibly narrower than div-based blocks. */
            '  figure { margin: 0; }',
            '}',
        ];
    }

    private static function themeLayer(array $brandTokens): array
    {
        $lines = ['@layer theme {', '  :root {'];
        ksort($brandTokens);
        foreach ($brandTokens as $name => $value) {
            $lines[] = "    $name: $value;";
        }
        $lines[] = '  }';
        /* Article shell — the readable-column wrapper the engine emits around
           every layout. Values come from theme tokens so the dash can override
           them per-site without touching CSS. */
        $lines[] = '  .lg-article {';
        $lines[] = '    max-width: var(--lg-article-max);';
        $lines[] = '    margin-inline: auto;';
        $lines[] = '    padding-inline: var(--lg-article-padding-inline);';
        $lines[] = '  }';
        /* Full-bleed escape — blocks that need to break the readable-column
           constraint (post-header hero, opt-in via the .lg-fullbleed class)
           stretch to the viewport edges via the standard `margin-inline:
           calc(50% - 50vw)` trick. They also clear the article's inline
           padding so their content goes edge to edge. */
        $lines[] = '  .lg-article > .lg-post-header,';
        $lines[] = '  .lg-article > .lg-post-footer,';
        $lines[] = '  .lg-article > .lg-fullbleed {';
        $lines[] = '    width: 100vw;';
        $lines[] = '    max-width: 100vw;';
        $lines[] = '    margin-inline: calc(50% - 50vw);';
        $lines[] = '  }';
        /* Wider track for opt-in blocks — gallery image rows, framed columns,
           etc. Stays inside the article container but uses the wider ceiling
           (1320px vs the readable 1040px). */
        $lines[] = '  .lg-article > .lg-wider {';
        $lines[] = '    max-width: var(--lg-article-max-wide);';
        $lines[] = '    margin-inline: auto;';
        $lines[] = '  }';
        /* Mobile gutter: the article-max clamp floors at 88vw, which left
           ~24px of dead space outside the inline padding on phone viewports.
           At <768px, let the article use the full width — padding-inline
           alone owns the gutter. */
        $lines[] = '  @media (max-width: 767px) {';
        $lines[] = '    .lg-article { max-width: 100%; }';
        $lines[] = '  }';
        $lines[] = '}';
        return $lines;
    }

    /** Concatenate each block's shell.css. Each block's CSS is wrapped in
     *  @layer block-shell already (by the block's own shell.css), so we just
     *  emit the contents in order. */
    private static function blockShellLayer(array $manifests): array
    {
        $lines = ['/* ── block-shell layer ─────────────────────────────────── */'];
        $blocksDir = dirname(__DIR__) . '/blocks';
        foreach ($manifests as $name => $_m) {
            $shellPath = "$blocksDir/$name/shell.css";
            if (!is_file($shellPath)) continue;
            $css = trim((string) file_get_contents($shellPath));
            if ($css === '') continue;
            $lines[] = "/* $name */";
            $lines[] = $css;
            $lines[] = '';
        }
        return $lines;
    }

    /** Emit block-defaults from each manifest's `defaults` (+ each variant + sub_target). */
    private static function blockDefaultsLayer(array $manifests): array
    {
        $lines = ['@layer block-defaults {'];
        foreach ($manifests as $name => $m) {
            $decls = self::declsFromGroups($m['defaults'], $m['vars']);
            if ($decls) {
                $lines[] = "  {$m['selector']} {";
                foreach ($decls as $d) $lines[] = "    $d";
                $lines[] = "  }";
            }
            /* Variants */
            foreach ($m['variants'] as $vname => $vdef) {
                $variantGroups = [
                    'container' => ($vdef['container'] ?? []),
                    'text'      => ($vdef['text']      ?? []),
                ];
                $variantDecls = self::declsFromGroups($variantGroups, $m['vars']);
                if ($variantDecls) {
                    $lines[] = "  {$m['selector']}--{$vname} {";
                    foreach ($variantDecls as $d) $lines[] = "    $d";
                    $lines[] = "  }";
                }
            }
            /* Sub-targets: own selector, own declared vars. */
            foreach ($m['sub_targets'] as $stKey => $stDef) {
                $subDecls = self::declsFromGroups($stDef['defaults'], $stDef['vars']);
                if ($subDecls) {
                    $lines[] = "  {$stDef['selector']} {";
                    foreach ($subDecls as $d) $lines[] = "    $d";
                    $lines[] = "  }";
                }
            }
        }
        $lines[] = '}';
        return $lines;
    }

    /** Emit context layer: each named context is one rule, selector list
     *  built from all blocks that declared participation. */
    private static function contextLayer(array $manifests): array
    {
        $byCtx = [];   /* context name → [block selectors] */
        foreach ($manifests as $name => $m) {
            foreach ($m['context_overrides'] as $ctxName) {
                if (!isset(self::CONTEXTS[$ctxName])) continue;
                $byCtx[$ctxName][] = $m['selector'];
            }
        }
        if (!$byCtx) return ['/* no context rules */'];

        $lines = ['@layer context {'];
        foreach ($byCtx as $ctxName => $selectors) {
            $template = self::CONTEXTS[$ctxName]['selector_template'];
            $list = array_map(fn($s) => str_replace('{selector}', $s, $template), $selectors);
            $lines[] = '  ' . implode(",\n  ", $list) . ' {';
            foreach (self::CONTEXTS[$ctxName]['vars'] as $k => $v) {
                $lines[] = "    --lg-$k: $v;";
            }
            $lines[] = '  }';
        }
        $lines[] = '}';
        return $lines;
    }

    /** Emit dash layer. Order within the layer:
     *    1. Global (selector: `:where(.lg-image, .lg-prose, …)` — zero specificity)
     *    2. Per-block, alpha order. Skipped if entry._inherit_global is true.
     *    3. Variants, then sub_targets, per block.
     *
     *  Because :where() is specificity-0, any per-block plain selector beats
     *  global within this layer; no !important needed. Layer ordering puts
     *  this whole block above block-defaults regardless of selector games. */
    private static function dashLayer(array $manifests, array $dashOverrides): array
    {
        $lines = ['@layer dash {'];
        $any   = false;

        /* 1. Global. Uses the canonical container+text var sets, not any
              one block's manifest vars, since global cuts across blocks. */
        $globalEntry = is_array($dashOverrides['_global'] ?? null) ? $dashOverrides['_global'] : [];
        if ($globalEntry) {
            $canonicalVars = [
                'container' => Dash::CANONICAL_CONTAINER,
                'text'      => Dash::CANONICAL_TEXT,
            ];
            $gDecls = self::declsFromGroups($globalEntry, $canonicalVars);
            if ($gDecls) {
                /* Only blocks whose manifest opts in get global. Structural
                   blocks (e.g. divider, opted out via inherits_global=false)
                   keep their manifest defaults intact. */
                $selectorList = [];
                foreach ($manifests as $m) {
                    if (($m['inherits_global'] ?? true) === false) continue;
                    $selectorList[] = $m['selector'];
                }
                if ($selectorList) {
                    $any = true;
                    $lines[] = '  :where(' . implode(', ', $selectorList) . ') {';
                    foreach ($gDecls as $d) $lines[] = "    $d";
                    $lines[] = '  }';
                }
            }
        }

        /* 2. Per-block. */
        ksort($dashOverrides);
        foreach ($dashOverrides as $name => $entry) {
            if ($name === '_global') continue;
            if (!isset($manifests[$name]) || !is_array($entry)) continue;
            /* Block opted into global-only: skip per-block emission entirely. */
            if (!empty($entry['_inherit_global'])) continue;
            $m = $manifests[$name];

            $decls = self::declsFromGroups($entry, $m['vars']);
            if ($decls) {
                $any = true;
                $lines[] = "  {$m['selector']} {";
                foreach ($decls as $d) $lines[] = "    $d";
                $lines[] = "  }";
            }

            /* Variant overrides. */
            if (!empty($entry['variants']) && is_array($entry['variants'])) {
                foreach ($entry['variants'] as $vname => $vEntry) {
                    if (!isset($m['variants'][$vname]) || !is_array($vEntry)) continue;
                    $vDecls = self::declsFromGroups($vEntry, $m['vars']);
                    if (!$vDecls) continue;
                    $any = true;
                    $lines[] = "  {$m['selector']}--{$vname} {";
                    foreach ($vDecls as $d) $lines[] = "    $d";
                    $lines[] = "  }";
                }
            }

            /* Sub-target overrides. */
            if (!empty($entry['sub_targets']) && is_array($entry['sub_targets'])) {
                foreach ($entry['sub_targets'] as $stKey => $sEntry) {
                    if (!isset($m['sub_targets'][$stKey]) || !is_array($sEntry)) continue;
                    $stDef  = $m['sub_targets'][$stKey];
                    $sDecls = self::declsFromGroups($sEntry, $stDef['vars']);
                    if (!$sDecls) continue;
                    $any = true;
                    $lines[] = "  {$stDef['selector']} {";
                    foreach ($sDecls as $d) $lines[] = "    $d";
                    $lines[] = "  }";
                }
            }
        }
        $lines[] = '}';
        return $any ? $lines : ['/* no dash overrides */'];
    }

    /* ── Helpers ─────────────────────────────────────────────────── */

    /**
     * Convert a {container: {…}, text: {…}} group structure into ordered
     * CSS variable declarations, ordered per manifest.vars (not by the order
     * they appear in defaults), so output is deterministic regardless of
     * how the dash serialized the keys.
     */
    private static function declsFromGroups(array $groups, array $vars): array
    {
        $out = [];
        foreach (['container', 'text'] as $group) {
            foreach (($vars[$group] ?? []) as $varName) {
                if (!isset($groups[$group][$varName])) continue;
                $val = (string) $groups[$group][$varName];
                /* Border has a special per-side form ("T R B L STYLE [COLOR]")
                   emitted by the dash when widths differ across sides. CSS
                   shorthand can't express that, so detect it here and emit
                   border-*-width longhand + border-style/-color, bypassing
                   --lg-border for that one declaration. Uniform borders
                   continue to flow through the var as before. */
                if ($varName === 'border') {
                    $perSide = self::parseBorderPerSide($val);
                    if ($perSide !== null) {
                        foreach ($perSide as $prop => $pv) $out[] = "$prop: $pv;";
                        continue;
                    }
                }
                $out[] = "--lg-$varName: $val;";
            }
        }
        return $out;
    }

    /** Detect the per-side border format "T R B L STYLE [COLOR]" and
     *  return an associative array of longhand declarations. Returns null
     *  for any other shape (uniform / empty / unparseable) so the caller
     *  falls back to the standard --lg-border var path. */
    private static function parseBorderPerSide(string $val): ?array
    {
        $val = trim($val);
        if ($val === '' || $val === 'none') return null;
        $parts = preg_split('/\s+/', $val) ?: [];
        if (count($parts) < 5) return null;
        $isW = static function (string $t): bool {
            return $t === '0' || (bool) preg_match('/^(\d+(\.\d+)?(px|em|rem|%)?|var\([^)]+\))$/i', $t);
        };
        if (!$isW($parts[0]) || !$isW($parts[1]) || !$isW($parts[2]) || !$isW($parts[3])) return null;
        $style = $parts[4];
        $color = trim(implode(' ', array_slice($parts, 5)));
        $out = [
            'border-top-width'    => $parts[0],
            'border-right-width'  => $parts[1],
            'border-bottom-width' => $parts[2],
            'border-left-width'   => $parts[3],
            'border-style'        => $style,
        ];
        if ($color !== '') $out['border-color'] = $color;
        return $out;
    }
}
