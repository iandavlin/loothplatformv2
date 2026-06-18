<?php
/**
 * Validator — check a parsed layout array against block manifests.
 *
 * Returns a list of errors. Each error has:
 *   - path:  dot/bracket path to the offending node (e.g. "blocks[3].caption")
 *   - msg:   human-readable explanation
 *   - fatal: bool — true if rendering should bail; false if it can proceed
 *
 * The validator is shared between save-time (REST endpoints), render-time
 * (degrades gracefully on non-fatal errors), and the test harness.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class Validator
{
    /**
     * @return array<int, array{path: string, msg: string, fatal: bool}>
     */
    public static function validate(array $layout): array
    {
        $errors = [];

        if (($layout['schema'] ?? null) !== 1) {
            $errors[] = ['path' => '/schema', 'msg' => 'schema must equal 1', 'fatal' => false];
        }

        if (!isset($layout['blocks']) || !is_array($layout['blocks'])) {
            $errors[] = ['path' => '/blocks', 'msg' => 'blocks must be an array', 'fatal' => true];
            return $errors;
        }

        $manifests = Manifest::all();
        self::walk($layout['blocks'], '/blocks', $manifests, $errors, /* depth */ 0);
        return $errors;
    }

    public static function hasFatal(array $errors): bool
    {
        foreach ($errors as $e) if (!empty($e['fatal'])) return true;
        return false;
    }

    /** Recurse the block tree and check each one against its manifest. */
    private static function walk(array $blocks, string $path, array $manifests, array &$errors, int $depth): void
    {
        foreach ($blocks as $i => $b) {
            $p = "{$path}[{$i}]";

            if (!is_array($b)) {
                $errors[] = ['path' => $p, 'msg' => 'block must be an object', 'fatal' => false];
                continue;
            }

            $type = $b['type'] ?? null;
            if ($type === null) {
                $errors[] = ['path' => $p, 'msg' => 'missing type', 'fatal' => false];
                continue;
            }

            if (!isset($manifests[$type])) {
                $errors[] = ['path' => $p, 'msg' => "unknown block type '$type'", 'fatal' => false];
                continue;
            }

            $manifest = $manifests[$type];

            /* Required-prop check */
            foreach ($manifest['schema']['required'] as $req) {
                if (!isset($b[$req])) {
                    $errors[] = ['path' => $p, 'msg' => "missing required prop '$req'", 'fatal' => false];
                }
            }

            /* Per-prop type + enum check.
               Skip universal block fields (id, type, blocks, gated_tier,
               variant) — those are not manifest props. */
            $universal = ['id', 'type', 'blocks', 'gated_tier', 'variant'];
            foreach ($manifest['schema']['props'] as $propName => $propDef) {
                if (in_array($propName, $universal, true)) continue;
                if (!isset($b[$propName])) continue;
                $val = $b[$propName];

                if (!self::typeMatches($val, $propDef['type'])) {
                    $errors[] = ['path' => "$p.$propName", 'msg' => "expected {$propDef['type']}, got " . gettype($val), 'fatal' => false];
                }

                /* Sub-schema for array_of_objects: each row's own props are
                   type-checked + enum-checked against propDef.items.props. */
                if ($propDef['type'] === 'array_of_objects' && is_array($val)) {
                    $itemProps = $propDef['items']['props'] ?? [];
                    if (is_array($itemProps)) {
                        foreach ($val as $rowIdx => $row) {
                            if (!is_array($row)) {
                                $errors[] = ['path' => "$p.$propName[$rowIdx]", 'msg' => 'row must be an object', 'fatal' => false];
                                continue;
                            }
                            foreach ($itemProps as $rk => $rdef) {
                                if (!isset($row[$rk])) continue;
                                if (!self::typeMatches($row[$rk], (string) ($rdef['type'] ?? 'string'))) {
                                    $errors[] = ['path' => "$p.$propName[$rowIdx].$rk", 'msg' => "expected {$rdef['type']}", 'fatal' => false];
                                }
                                if (isset($rdef['enum']) && is_array($rdef['enum']) && !in_array($row[$rk], $rdef['enum'], true)) {
                                    $errors[] = ['path' => "$p.$propName[$rowIdx].$rk", 'msg' => 'value not in enum: ' . implode('|', $rdef['enum']), 'fatal' => false];
                                }
                            }
                        }
                    }
                }

                if (isset($propDef['enum']) && is_array($propDef['enum'])) {
                    if (!in_array($val, $propDef['enum'], true)) {
                        $errors[] = ['path' => "$p.$propName", 'msg' => "value not in enum: " . implode('|', $propDef['enum']), 'fatal' => false];
                    }
                }
            }

            /* Variant must be declared */
            if (isset($b['variant']) && !isset($manifest['variants'][$b['variant']])) {
                $errors[] = ['path' => "$p.variant", 'msg' => "unknown variant '{$b['variant']}'", 'fatal' => false];
            }

            /* Gated-tier must be a known tier (validated at render time too) */
            if (isset($b['gated_tier']) && !in_array($b['gated_tier'], TierResolver::TIERS, true)) {
                $errors[] = ['path' => "$p.gated_tier", 'msg' => "unknown tier '{$b['gated_tier']}'", 'fatal' => false];
            }

            /* No nested columns — flat container model only */
            if ($type === 'columns' && $depth > 0) {
                $errors[] = ['path' => $p, 'msg' => 'columns cannot be nested inside another columns block', 'fatal' => false];
            }

            /* Recurse for container blocks. Columns store children under a
               per-column-bucket shape: $b['columns'][colIdx]['blocks']. All
               other container blocks (if any) use the canonical $b['blocks']. */
            if ($type === 'columns' && isset($b['columns']) && is_array($b['columns'])) {
                foreach ($b['columns'] as $colIdx => $col) {
                    if (!is_array($col)) continue;
                    $colBlocks = is_array($col['blocks'] ?? null) ? $col['blocks'] : [];
                    self::walk($colBlocks, "$p.columns[$colIdx].blocks", $manifests, $errors, $depth + 1);
                }
            } elseif (isset($b['blocks']) && is_array($b['blocks'])) {
                self::walk($b['blocks'], "$p.blocks", $manifests, $errors, $depth + 1);
            }
        }
    }

    private static function typeMatches(mixed $val, string $type): bool
    {
        return match ($type) {
            'string'  => is_string($val),
            'integer' => is_int($val),
            'number'  => is_int($val) || is_float($val),
            'boolean' => is_bool($val),
            'array'   => is_array($val) && array_is_list($val),
            'object'  => is_array($val) && !array_is_list($val),
            'array_of_objects' => is_array($val) && (empty($val) || array_is_list($val)),
            default   => true,   /* unknown types pass; manifest validator should have caught them */
        };
    }
}
