<?php
/**
 * Licenses — the Creative Commons licences a post can carry, as data.
 *
 * One source of truth for three consumers that must not be allowed to drift:
 *   - blocks/license/render.php  (draws the licence)
 *   - EditorPickers::license-choice + the FE editor (offers the four choices)
 *   - Plugin::default_loothprint_layout() (synthesizes the block)
 *
 * ── Where the four choices come from ────────────────────────────────────────
 * `loothprint_creative_commons` is an ACF radio whose choice KEYS are the prose
 * itself, e.g. "BY NC SA (Credit given to creator, Non-Commercial only,
 * Adaptations shared with same terms)". Measured on dev2: 257 rows, four
 * distinct values, every one an exact match for a declared ACF choice — there
 * is no free-text drift to defend against.
 *
 * ⚠️ The ACF choice "BY ND NC (…No Derivatives, Adaptations shared with same
 * terms)" is self-contradictory: ND forbids adaptations, so a share-alike term
 * has nothing to govern. It names BY-NC-ND, so that is what it resolves to and
 * that is what gets drawn — credit / non-commercial / no-derivatives, without
 * the impossible share-alike clause. The stored value is NOT rewritten; the
 * wording is a form bug and fixing it is Ian's call.
 *
 * Matching is on the CC element tokens and is ORDER-INSENSITIVE, so the source's
 * non-canonical "BY ND NC" and the canonical "BY-NC-ND" both resolve.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class Licenses
{
    /** Canonical CC deed version linked from the block. */
    private const VERSION = '4.0';

    /**
     * The four licences, keyed by code. `clauses` are CC element keys in the
     * order they are drawn; each maps into CLAUSES below.
     */
    public const CODES = [
        'by' => [
            'name'    => 'Attribution 4.0',
            'short'   => 'CC BY 4.0',
            'clauses' => ['by'],
        ],
        'by-sa' => [
            'name'    => 'Attribution–ShareAlike 4.0',
            'short'   => 'CC BY-SA 4.0',
            'clauses' => ['by', 'sa'],
        ],
        'by-nc-sa' => [
            'name'    => 'Attribution–NonCommercial–ShareAlike 4.0',
            'short'   => 'CC BY-NC-SA 4.0',
            'clauses' => ['by', 'nc', 'sa'],
        ],
        'by-nc-nd' => [
            'name'    => 'Attribution–NonCommercial–NoDerivatives 4.0',
            'short'   => 'CC BY-NC-ND 4.0',
            'clauses' => ['by', 'nc', 'nd'],
        ],
    ];

    /** What each CC element actually obliges, in words a reader can act on. */
    public const CLAUSES = [
        'by' => ['label' => 'Credit required',      'icon' => 'cc-by', 'description' => 'Credit the creator when you use or share this.'],
        'nc' => ['label' => 'Non-commercial only',  'icon' => 'cc-nc', 'description' => 'You may not use this for commercial purposes.'],
        'sa' => ['label' => 'Share-alike',          'icon' => 'cc-sa', 'description' => 'Adaptations must be shared under these same terms.'],
        'nd' => ['label' => 'No derivatives',       'icon' => 'cc-nd', 'description' => 'You may share this, but not modified versions of it.'],
    ];

    /** The code the ACF field defaults to (251 of 257 posts carry it). */
    public const DEFAULT_CODE = 'by-nc-sa';

    /**
     * The four ACF choice strings, VERBATIM, mapped to codes.
     *
     * These exist for one job: recognising a legacy licence callout with
     * certainty. 164 of the 168 stored loothprint layouts carry the licence as
     * a prose `callout` variant `note` whose body is exactly one of these four
     * strings (measured: exactly 4 distinct bodies, all exact ACF choices).
     *
     * Matching on the WHOLE string rather than sniffing for licence-ish words
     * is deliberate: a note callout that merely MENTIONS a licence inside a
     * longer paragraph must not be swallowed and replaced, which would delete
     * an author's prose.
     */
    public const ACF_CHOICES = [
        'BY (Credit given to creator)'                                                                 => 'by',
        'BY SA (Credit given to creator, Adaptations shared with same terms)'                          => 'by-sa',
        'BY NC SA (Credit given to creator, Non-Commercial only, Adaptations shared with same terms)'  => 'by-nc-sa',
        'BY ND NC (Credit given to creator, No Derivatives, Adaptations shared with same terms)'       => 'by-nc-nd',
    ];

    /**
     * Resolve ONLY an exact ACF choice string to its code; '' for anything
     * else. Whitespace is normalised (the stored bodies arrive as `<p>…</p>`
     * and strip to the choice with incidental spacing), but nothing else is
     * tolerated — this is the recogniser for a legacy licence block, and a
     * loose match here would rewrite author content.
     */
    public static function from_exact_prose(string $text): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($t === '') return '';
        foreach (self::ACF_CHOICES as $choice => $code) {
            if (strcasecmp($t, $choice) === 0) return $code;
        }
        return '';
    }

    /** True if $code names one of the four licences. */
    public static function is_valid(string $code): bool
    {
        return isset(self::CODES[$code]);
    }

    /** Every code, in the order the picker should offer them. */
    public static function codes(): array
    {
        return array_keys(self::CODES);
    }

    /**
     * Resolve a stored ACF value (or any CC-ish string) to one of the four
     * codes. Returns '' when nothing recognizable is present — callers treat
     * that as "no licence", never as a default, because silently defaulting
     * would put a licence on a post whose author never chose one.
     */
    public static function from_meta(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';

        /* Already a code? */
        $lower = strtolower($raw);
        if (isset(self::CODES[$lower])) return $lower;

        /* The prose form carries its code up front, before the parenthetical
           gloss: "BY NC SA (Credit given to creator, …)". Cut the gloss off —
           it contains words like "Non-Commercial" that would otherwise be
           re-parsed, and in the ND case it contradicts the code. */
        $head = $raw;
        if (($paren = strpos($head, '(')) !== false) {
            $head = substr($head, 0, $paren);
        }

        /* Collect CC element tokens, order-insensitively. Separators vary
           (space, hyphen, underscore); "CC" and a version number are noise. */
        $tokens = preg_split('/[^A-Za-z]+/', strtoupper($head), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $has = ['BY' => false, 'NC' => false, 'SA' => false, 'ND' => false];
        foreach ($tokens as $t) {
            if (isset($has[$t])) $has[$t] = true;
        }

        /* BY is present in all four CC licences we offer; without it there is
           nothing to resolve. */
        if (!$has['BY']) return '';

        /* Resolution rule for token sets that are not exactly one of the four:
           pick the offered licence that grants NO MORE than the stated one.
           Erring toward the more restrictive licence can only ever under-claim
           permission the author gave; erring the other way would tell a reader
           they may do something the author forbade, which is the unrecoverable
           direction. So BY-NC (not offered) resolves up to BY-NC-SA, and a bare
           BY-ND resolves up to BY-NC-ND.

           ND and SA are mutually exclusive — ND forbids the adaptations SA
           would govern — and the source's fourth choice asserts both. ND is the
           stronger term and the one its code names, so ND wins. */
        if ($has['ND']) return 'by-nc-nd';
        if ($has['NC']) return 'by-nc-sa';
        if ($has['SA']) return 'by-sa';

        return 'by';
    }

    /** Full licence name, e.g. "Attribution–NonCommercial–ShareAlike 4.0". */
    public static function name(string $code): string
    {
        return (string) (self::CODES[$code]['name'] ?? '');
    }

    /** Short form, e.g. "CC BY-NC-SA 4.0". */
    public static function short(string $code): string
    {
        return (string) (self::CODES[$code]['short'] ?? '');
    }

    /** The clause rows to draw for a code: [{key,label,icon,description}, …]. */
    public static function clauses(string $code): array
    {
        $out = [];
        foreach ((array) (self::CODES[$code]['clauses'] ?? []) as $key) {
            if (!isset(self::CLAUSES[$key])) continue;
            $out[] = ['key' => $key] + self::CLAUSES[$key];
        }
        return $out;
    }

    /** Canonical deed URL for a code. */
    public static function deed_url(string $code): string
    {
        if (!self::is_valid($code)) return '';
        return 'https://creativecommons.org/licenses/' . $code . '/' . self::VERSION . '/';
    }

    /**
     * Choices for the editor picker: the four licences plus the "follow the
     * post" option that clears an explicit code back to ''.
     */
    public static function picker_choices(): array
    {
        $out = [[
            'code'  => '',
            'label' => 'Follow the post’s licence',
            'hint'  => 'Tracks the licence set in the post’s own details. Recommended.',
        ]];
        foreach (self::CODES as $code => $spec) {
            $clauses = [];
            foreach (self::clauses($code) as $c) $clauses[] = $c['label'];
            $out[] = [
                'code'  => $code,
                'label' => $spec['short'],
                'hint'  => implode(' · ', $clauses),
            ];
        }
        return $out;
    }
}
