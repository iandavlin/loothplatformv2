<?php
/**
 * Manifest — load + validate block manifests from blocks/<name>/manifest.json.
 *
 * The manifest is the contract a block declares: variables, defaults, schema
 * for post props, editor affordances. See docs/MANIFEST.md.
 *
 * Manifests are loaded once per request and cached in a static map. Callers
 * receive the canonical (validated, normalized) array — never the raw JSON.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class Manifest
{
    /** @var array<string, array> name → manifest, populated lazily */
    private static array $cache = [];

    /** @var string|null absolute path to blocks/ directory, set by configure() */
    private static ?string $blocksDir = null;

    /** Configure where blocks/ lives. Defaults to <project>/blocks. */
    public static function configure(string $blocksDir): void
    {
        self::$blocksDir = rtrim($blocksDir, '/');
        self::$cache = [];
    }

    private static function blocksDir(): string
    {
        if (self::$blocksDir === null) self::$blocksDir = dirname(__DIR__) . '/blocks';
        return self::$blocksDir;
    }

    /**
     * Return an array of every block name found in blocks/.
     * Sorted alphabetically for deterministic CSS output.
     *
     * @return string[]
     */
    public static function list(): array
    {
        $out = [];
        foreach (glob(self::blocksDir() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_file($dir . '/manifest.json')) $out[] = basename($dir);
        }
        sort($out);
        return $out;
    }

    /**
     * Load + validate one block's manifest. Throws on a malformed manifest —
     * a broken manifest is a programmer error that should never reach prod.
     *
     * @throws \RuntimeException
     */
    public static function get(string $name): array
    {
        if (isset(self::$cache[$name])) return self::$cache[$name];

        $path = self::blocksDir() . '/' . $name . '/manifest.json';
        if (!is_file($path)) {
            throw new \RuntimeException("manifest not found: blocks/$name/manifest.json");
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            throw new \RuntimeException("manifest is not valid JSON: blocks/$name/manifest.json");
        }

        $errors = self::validateShape($raw, $name);
        if ($errors) {
            throw new \RuntimeException("manifest invalid for $name:\n  - " . implode("\n  - ", $errors));
        }

        return self::$cache[$name] = self::normalize($raw);
    }

    /**
     * Load every manifest at once. Used by the Pipeline + CssBuilder so they
     * don't re-walk the filesystem.
     *
     * @return array<string, array> name → manifest
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::list() as $name) $out[$name] = self::get($name);
        return $out;
    }

    /** Check that the manifest has the shape docs/MANIFEST.md describes. */
    private static function validateShape(array $m, string $expectedName): array
    {
        $errors = [];

        foreach (['name', 'version', 'selector', 'description', 'schema', 'vars', 'defaults'] as $required) {
            if (!isset($m[$required])) $errors[] = "missing required top-level field '$required'";
        }
        if ($errors) return $errors;

        if ($m['name'] !== $expectedName) {
            $errors[] = "manifest.name '{$m['name']}' does not match directory '$expectedName'";
        }
        if (!is_int($m['version'])) {
            $errors[] = "manifest.version must be an integer";
        }
        if (!is_string($m['selector']) || $m['selector'] === '') {
            $errors[] = "manifest.selector must be a non-empty string";
        }

        if (!isset($m['schema']['props']) || !is_array($m['schema']['props'])) {
            $errors[] = "manifest.schema.props must be an object";
        } else {
            foreach ($m['schema']['props'] as $propName => $propDef) {
                if (!is_array($propDef) || !isset($propDef['type'])) {
                    $errors[] = "schema.props.$propName must declare 'type'";
                }
            }
        }

        foreach (['container', 'text'] as $group) {
            if (isset($m['vars'][$group]) && !is_array($m['vars'][$group])) {
                $errors[] = "vars.$group must be an array of var names";
            }
            if (isset($m['defaults'][$group]) && !is_array($m['defaults'][$group])) {
                $errors[] = "defaults.$group must be an object";
            }
        }

        /* every default must reference a declared var */
        $declared = [];
        foreach (['container', 'text'] as $group) {
            foreach (($m['vars'][$group] ?? []) as $vname) {
                $declared["$group.$vname"] = true;
            }
        }
        foreach (['container', 'text'] as $group) {
            foreach (($m['defaults'][$group] ?? []) as $k => $v) {
                if (!isset($declared["$group.$k"])) {
                    $errors[] = "defaults.$group.$k is not declared in vars.$group";
                }
                if ($v === '' || $v === null) {
                    $errors[] = "defaults.$group.$k is empty";
                }
            }
        }

        /* variants must extend a known config + only override declared vars */
        if (isset($m['variants']) && is_array($m['variants'])) {
            foreach ($m['variants'] as $vname => $vdef) {
                if (!is_array($vdef)) {
                    $errors[] = "variants.$vname must be an object";
                    continue;
                }
                $extends = $vdef['extends'] ?? 'defaults';
                if ($extends !== 'defaults' && !isset($m['variants'][$extends])) {
                    $errors[] = "variants.$vname.extends '$extends' is not a known variant";
                }
                foreach (['container', 'text'] as $group) {
                    foreach (($vdef[$group] ?? []) as $k => $_v) {
                        if (!isset($declared["$group.$k"])) {
                            $errors[] = "variants.$vname.$group.$k is not declared in vars.$group";
                        }
                    }
                }
            }
        }

        /* sub_targets: each has its own selector, vars, defaults. They emit
           as additional rules in block-defaults and get their own dash sub-panels.
           This is how sub-elements (badge, overlay caption, etc.) become
           dash-tweakable without polluting the block's main vars. */
        if (isset($m['sub_targets']) && is_array($m['sub_targets'])) {
            foreach ($m['sub_targets'] as $stKey => $stDef) {
                if (!is_array($stDef)) {
                    $errors[] = "sub_targets.$stKey must be an object";
                    continue;
                }
                foreach (['selector', 'label'] as $req) {
                    if (empty($stDef[$req])) {
                        $errors[] = "sub_targets.$stKey missing required field '$req'";
                    }
                }
                if (isset($stDef['selector']) && !str_starts_with((string) $stDef['selector'], $m['selector'])) {
                    $errors[] = "sub_targets.$stKey.selector must start with the block's selector ({$m['selector']})";
                }
                /* every default must reference a declared sub-target var */
                $subDeclared = [];
                foreach (['container', 'text'] as $group) {
                    foreach (($stDef['vars'][$group] ?? []) as $vname) {
                        $subDeclared["$group.$vname"] = true;
                    }
                }
                foreach (['container', 'text'] as $group) {
                    foreach (($stDef['defaults'][$group] ?? []) as $k => $v) {
                        if (!isset($subDeclared["$group.$k"])) {
                            $errors[] = "sub_targets.$stKey.defaults.$group.$k is not declared in sub_targets.$stKey.vars.$group";
                        }
                        if ($v === '' || $v === null) {
                            $errors[] = "sub_targets.$stKey.defaults.$group.$k is empty";
                        }
                    }
                }
            }
        }

        return $errors;
    }

    /** Fill in optional fields so callers don't have to ?? everything. */
    private static function normalize(array $m): array
    {
        $m['vars']     = $m['vars']     + ['container' => [], 'text' => []];
        $m['defaults'] = $m['defaults'] + ['container' => [], 'text' => []];
        $m['variants'] = $m['variants'] ?? [];
        $m['sub_targets'] = $m['sub_targets'] ?? [];
        foreach ($m['sub_targets'] as $k => &$st) {
            $st['vars']     = ($st['vars']     ?? []) + ['container' => [], 'text' => []];
            $st['defaults'] = ($st['defaults'] ?? []) + ['container' => [], 'text' => []];
        }
        unset($st);
        $m['editor']   = $m['editor']   ?? ['insertable' => false, 'inline_editable_props' => [], 'custom_picker' => null, 'pill_buttons' => ['edit', 'tier', 'delete']];
        $m['context_overrides'] = $m['context_overrides'] ?? [];
        $m['schema']['required'] = $m['schema']['required'] ?? [];
        /* Whether this block participates in the dash "Global Defaults"
           cascade. Default true: most blocks are card-like containers that
           benefit from inheriting sitewide chrome (padding, bg, border,
           radius). Set false on structural blocks (e.g. divider) whose
           manifest defaults are the truth and should never be overridden
           by global card styling. */
        $m['inherits_global'] = !array_key_exists('inherits_global', $m) || $m['inherits_global'] !== false;
        return $m;
    }
}
