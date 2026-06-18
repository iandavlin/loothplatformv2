<?php
/**
 * Theme — registry of brand tokens.
 *
 * Brand tokens are CSS variables that live at :root and cascade to every
 * block. They're the *theme layer* of the cascade. Block shell.css files
 * can reference them (e.g. `background: var(--lg-sage-translucent)`)
 * without declaring them in the block's manifest — the linter checks
 * the token registry here for legitimacy.
 *
 * At runtime the dash can override token values; defaults come from
 * src/theme/tokens.json.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class Theme
{
    /** @var array<string, array{default: string, category: string, description: string}>|null */
    private static ?array $tokensCache = null;

    /** Load + parse src/theme/tokens.json. */
    public static function tokens(): array
    {
        if (self::$tokensCache !== null) return self::$tokensCache;

        $path = __DIR__ . '/theme/tokens.json';
        if (!is_file($path)) {
            throw new \RuntimeException("theme tokens not found: $path");
        }
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw) || !isset($raw['tokens']) || !is_array($raw['tokens'])) {
            throw new \RuntimeException("theme tokens malformed: $path");
        }
        return self::$tokensCache = $raw['tokens'];
    }

    /** Token names only (for linter contains-check). */
    public static function tokenNames(): array
    {
        return array_keys(self::tokens());
    }

    /**
     * Default values keyed by token name. Used by CssBuilder when the caller
     * hasn't provided brand overrides.
     *
     * @return array<string, string>
     */
    public static function defaultValues(): array
    {
        $out = [];
        foreach (self::tokens() as $name => $meta) {
            $out[$name] = $meta['default'];
        }
        return $out;
    }

    /** Effective tokens = defaults overlaid with overrides (dash-supplied). */
    public static function resolve(array $overrides = []): array
    {
        return $overrides + self::defaultValues();
    }
}
