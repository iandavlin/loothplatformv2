<?php
/**
 * Icons — single source of truth for inline SVG glyphs.
 *
 * Used by the callout block's item rows (icon dropdown per row) and intended
 * to be the destination for the inlined SVGs currently living in post-header /
 * post-footer render.php files (port pending).
 *
 * SVG strings are emitted directly into the rendered HTML — they are NOT
 * author-supplied. Callers should pass a known key; unknown keys fall back to
 * the generic `link` glyph. The set is filterable via
 * `lg_layout_v2_icon_presets` so themes/plugins can extend it.
 *
 * All glyphs share the same viewBox (0 0 24 24) and inherit color via
 * `currentColor`, so CSS owns the color/size.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class Icons
{
    /** Built-in icon palette. Keep alphabetized within groups. */
    public const PRESETS = [
        /* Web / link */
        'globe'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2c3 3 3 17 0 20M12 2c-3 3-3 17 0 20"/></svg>',
        'link'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
        'linktree'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v8m-5-5l5 5 5-5M7 14h10M9 18h6M11 22h2"/></svg>',

        /* Social */
        'instagram' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor"/></svg>',
        'facebook'  => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M13 10h3l.5-3H13V5c0-.9.3-1.5 1.6-1.5H17V.8C16.6.7 15.3.5 13.9.5 11 .5 9.1 2.3 9.1 5.6V7H6v3h3.1v8H13v-8z"/></svg>',
        'youtube'   => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M21.6 7.2s-.2-1.4-.8-2c-.7-.8-1.5-.8-1.9-.8C16 4.2 12 4.2 12 4.2s-4 0-6.9.2c-.4.1-1.2.1-1.9.8-.6.6-.8 2-.8 2S2.2 8.8 2.2 10.5v1.5c0 1.7.2 3.3.2 3.3s.2 1.4.8 2c.7.8 1.7.7 2.1.8 1.6.2 6.7.2 6.7.2s4 0 6.9-.2c.4-.1 1.2-.1 1.9-.8.6-.6.8-2 .8-2s.2-1.7.2-3.3v-1.5c0-1.7-.2-3.3-.2-3.3zM10 14V8l5 3-5 3z"/></svg>',
        'x'         => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2H21l-6.55 7.49L22 22h-6.79l-4.73-6.18L4.83 22H2.07l7.02-8.03L2 2h6.91l4.28 5.66L18.244 2zm-1.19 18h1.88L7.05 4H5.09l11.964 16z"/></svg>',
        'github'    => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 .5C5.65.5.5 5.65.5 12c0 5.08 3.29 9.39 7.86 10.91.58.1.79-.25.79-.55v-2c-3.2.7-3.87-1.37-3.87-1.37-.52-1.33-1.28-1.68-1.28-1.68-1.05-.71.08-.7.08-.7 1.16.08 1.77 1.19 1.77 1.19 1.03 1.76 2.7 1.25 3.36.96.1-.75.4-1.26.73-1.55-2.55-.29-5.23-1.27-5.23-5.67 0-1.25.45-2.27 1.18-3.07-.12-.29-.51-1.45.11-3.03 0 0 .97-.31 3.18 1.17a11.1 11.1 0 0 1 5.79 0c2.21-1.48 3.18-1.17 3.18-1.17.62 1.58.23 2.74.11 3.03.74.8 1.18 1.82 1.18 3.07 0 4.41-2.69 5.37-5.25 5.66.41.36.78 1.06.78 2.14v3.17c0 .31.21.66.8.55C20.21 21.39 23.5 17.08 23.5 12 23.5 5.65 18.35.5 12 .5z"/></svg>',

        /* Contact */
        'email'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><path d="M22 6l-10 7L2 6"/></svg>',
        'phone'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.37 1.9.72 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.35 1.85.6 2.81.72A2 2 0 0 1 22 16.92z"/></svg>',

        /* Files */
        'file'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>',
        'file-pdf'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 18v-6M9 15h6"/></svg>',
        'file-zip'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8v13H3V8M1 3h22v5H1z"/><path d="M10 12h4"/></svg>',
        'file-dxf'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>',

        /* Misc */
        'avatar'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/></svg>',
        'chevron'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>',
    ];

    public static function all(): array
    {
        $set = self::PRESETS;
        if (function_exists('apply_filters')) {
            $set = apply_filters('lg_layout_v2_icon_presets', $set);
        }
        return is_array($set) ? $set : self::PRESETS;
    }

    public static function svg(string $key): string
    {
        $set = self::all();
        return $set[$key] ?? $set['link'] ?? '';
    }

    /** @return string[] sorted alphabetically — used by the picker UI. */
    public static function keys(): array
    {
        $keys = array_keys(self::all());
        sort($keys);
        return $keys;
    }
}
