<?php
/**
 * Pipeline — orchestrate the v2 render pipeline.
 *
 * The 6 stages from docs/ARCHITECTURE.md:
 *
 *   1. Input         — parsed JSON (caller's responsibility)
 *   2. Validation    — Validator::validate
 *   3. Block tree    — implicit, the layout's `blocks` array
 *   4. Var resolution — VarResolver (TODO: extracted, for now CssBuilder owns the logic)
 *   5. CSS bundle    — CssBuilder::build
 *   6. HTML output   — Renderer::render
 *
 * Used by:
 *   - bin/render-test.php   (CLI harness)
 *   - Phase 2 WP plugin     (the_content filter handler)
 *
 * Returns one Result object that bundles everything the caller might need.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class Pipeline
{
    /**
     * @param array $layout         The article JSON: { schema, _meta, blocks }
     * @param array $brandTokens    name → value at :root
     * @param array $dashOverrides  block name → { container: {...}, text: {...} }
     * @param array $ctx            Render context, see Renderer::render
     * @return array {
     *     @type array              $validation    Validator errors
     *     @type string             $html          Article HTML (empty if fatal validation)
     *     @type string             $css           Full CSS bundle
     *     @type array              $variables     Resolved vars per block instance
     *     @type bool               $fatal         True if rendering bailed
     * }
     */
    public static function run(array $layout, array $brandTokens = [], array $dashOverrides = [], array $ctx = []): array
    {
        $errors = Validator::validate($layout);
        $fatal  = Validator::hasFatal($errors);

        $manifests = Manifest::all();
        $css       = CssBuilder::build($manifests, $brandTokens, $dashOverrides);

        /* Defensive: even when Validator flagged a fatal "blocks must be an
           array" error, we still build CSS + return a result object. But the
           variable resolver and renderer expect an array — pass [] when the
           layout is malformed so they don't TypeError on the way out. The
           validation errors travel back to the caller via $errors. */
        $blocksArr = isset($layout['blocks']) && is_array($layout['blocks']) ? $layout['blocks'] : [];
        $variables = self::resolveVariablesForLayout($blocksArr, $manifests, $dashOverrides, /* parentContext */ []);

        $html = $fatal ? '' : Renderer::render($layout, $ctx);

        return [
            'validation' => $errors,
            'html'       => $html,
            'css'        => $css,
            'variables'  => $variables,
            'fatal'      => $fatal,
        ];
    }

    /**
     * Walk the block tree and resolve every CSS variable for every instance.
     * Output is the same shape consumed by mockup/render-pipeline.html's
     * stage-4 table, so the harness can snapshot it for cascade testing.
     *
     * @return array<int, array{id: ?string, type: string, resolution: array}>
     */
    public static function resolveVariablesForLayout(array $blocks, array $manifests, array $dashOverrides, array $parentContext): array
    {
        $results = [];
        foreach ($blocks as $b) {
            if (!is_array($b) || !isset($b['type'])) continue;
            $type = $b['type'];
            if (!isset($manifests[$type])) continue;

            $results[] = [
                'id'         => $b['id'] ?? null,
                'type'       => $type,
                'resolution' => self::resolveOne($b, $manifests[$type], $dashOverrides[$type] ?? [], $parentContext),
            ];

            /* Columns store children as per-column buckets. Each column's
               blocks inherit the 'columns' context. Other container blocks
               (none today) walk $b['blocks'] without context. */
            if ($type === 'columns' && isset($b['columns']) && is_array($b['columns'])) {
                foreach ($b['columns'] as $col) {
                    if (!is_array($col)) continue;
                    $colBlocks = is_array($col['blocks'] ?? null) ? $col['blocks'] : [];
                    $results = array_merge($results, self::resolveVariablesForLayout($colBlocks, $manifests, $dashOverrides, ['columns']));
                }
            } elseif (isset($b['blocks']) && is_array($b['blocks'])) {
                $results = array_merge($results, self::resolveVariablesForLayout($b['blocks'], $manifests, $dashOverrides, []));
            }
        }
        return $results;
    }

    /** For one block: walk the layers, record the trail for every declared var. */
    private static function resolveOne(array $block, array $manifest, array $dashEntry, array $parentContext): array
    {
        /* Re-use CssBuilder's CONTEXTS table by importing via a known method.
           To avoid duplication, we read CONTEXTS via reflection on CssBuilder.
           Cheap; happens once per block instance per render. */
        static $contexts = null;
        if ($contexts === null) {
            $r = new \ReflectionClass(CssBuilder::class);
            $contexts = $r->getConstant('CONTEXTS');
        }

        $out = ['container' => [], 'text' => []];
        foreach (['container', 'text'] as $group) {
            foreach (($manifest['vars'][$group] ?? []) as $varName) {
                $trail = [];

                /* block-defaults */
                $def = $manifest['defaults'][$group][$varName] ?? null;
                if ($def !== null) $trail[] = ['layer' => 'block-defaults', 'value' => $def];

                /* context */
                foreach ($parentContext as $ctxName) {
                    $ctxRule = $contexts[$ctxName] ?? null;
                    if (!$ctxRule) continue;
                    if (!in_array($ctxName, $manifest['context_overrides'], true)) continue;
                    if (isset($ctxRule['vars'][$varName])) {
                        $trail[] = ['layer' => "context($ctxName)", 'value' => $ctxRule['vars'][$varName]];
                    }
                }

                /* dash */
                if (isset($dashEntry[$group][$varName])) {
                    $trail[] = ['layer' => 'dash', 'value' => $dashEntry[$group][$varName]];
                }

                /* variant — applied on top of the rest if the block has one selected */
                $variant = $block['variant'] ?? null;
                if ($variant !== null && isset($manifest['variants'][$variant][$group][$varName])) {
                    $trail[] = ['layer' => "variant($variant)", 'value' => $manifest['variants'][$variant][$group][$varName]];
                }

                $effective = $trail ? end($trail)['value'] : '(unset)';
                $out[$group][$varName] = ['trail' => $trail, 'effective' => $effective];
            }
        }
        return $out;
    }
}
