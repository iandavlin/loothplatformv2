<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

/**
 * Completeness — the profile %-complete meter (Ian 8/12: "Can we have a
 * percent completed of the profile and have it be a percentage?"; the eight
 * items LOCKED 8/14, docs/IAN-RULINGS-2026-08-14.md item 6).
 *
 * THE EIGHT ITEMS, 12.5% each, in the ruled order — photo, location, what-you-do,
 * bio, links, skills+instruments, work photos, banner. Every one maps to a field
 * that already exists; nothing here is new data collection.
 *
 * ⚠️ THIS DEFINITION MUST STAY IN LOCKSTEP WITH tools/profile-completeness-report.sql.
 * That file is the reproducible, read-only measurement keeper and Ian have both
 * been shown numbers from (66 card-ready, 978 at 12%, etc.) — if this class and
 * that SQL diverge, the dash will show a member a different score than the one
 * the report measured the membership by, and nobody will notice until the
 * numbers stop adding up. There is no single source both can include (one is a
 * live PHP/PDO query, the other a standalone psql report against a box that
 * usually isn't this one) — so a mismatch has to be caught by review, not
 * automation, and this comment is that review's memory.
 *
 * The card-READY question is deliberately separate from the percentage: a
 * member can be 62% and have a perfect card, or 87% and be missing the one
 * line the card actually shows. See CARD_ITEMS. Ian ruled "accept any %" for
 * the tickbox (no floor) — this class computes both numbers; the endpoints
 * decide what each does with them.
 */
final class Completeness
{
    /**
     * Strip a double-escaping artifact found in 32 live `business_name` rows
     * (2026-08-15 audit while building this class): `Doc\'s Guitar Shop`,
     * `\"Marine Guitars\"` — a literal backslash baked into the stored bytes,
     * always immediately before a quote. `display_name` and `at_a_glance` are
     * clean (0 rows each); this is isolated to `business_name`.
     *
     * WHY THIS MATTERS HERE, TWICE:
     *  1. what_you_do's "is business_name just a slice of display_name"
     *     suffix check is BYTE comparison. Postgres's `LIKE`, which the
     *     SQL report uses, treats `\` as its (default) escape character and
     *     silently absorbs it — so `NOT LIKE '%'||business_name` reports
     *     these AS a suffix-match (correct) while a naive PHP
     *     str_ends_with() does not (byte-for-byte, no escape processing) and
     *     WOULD misclassify 30 of these 32 as "real, independent content".
     *     Found by cross-checking this class against
     *     tools/profile-completeness-report.sql member-by-member — they must
     *     never silently diverge (see the class doc comment).
     *  2. business_name is also the public CARD's role-line fallback
     *     (Completeness doesn't render it; the card assembly does) — so
     *     without this, a featured member's card would show the raw
     *     backslash to the public: "Doc\'s Guitar Shop".
     * The underlying data bug (wherever double-escaping happens on write) is
     * NOT this lane's to fix; this only cleans the value in the two places
     * this feature reads it.
     */
    public static function deEscape(?string $s): string
    {
        return $s === null ? '' : str_replace(["\\'", '\\"'], ["'", '"'], $s);
    }

    /** Order matches the ruled list and the mock's rendering order. */
    public const ITEMS = ['photo', 'location', 'what_you_do', 'bio', 'links', 'craft', 'gallery', 'banner'];

    /** The subset the front-page card actually renders. Card-ready = all three. */
    private const CARD_ITEMS = ['photo', 'what_you_do', 'location'];

    public const ITEM_LABELS = [
        'photo'       => 'A photo of you',
        'location'    => 'Where you are',
        'what_you_do' => 'What you do — one line',
        'bio'         => 'A bit about you',
        'links'       => 'A link or two',
        'craft'       => 'What you work on (skills, instruments)',
        'gallery'     => 'A few photos of your work',
        'banner'      => 'A banner image',
    ];

    /**
     * @return array{items: array<string,bool>, score: int, pct: int, card_ready: bool, next: ?string}
     *   `score` 0-8, `pct` 0-100 in steps of 12 (rounds down; matches the SQL
     *   report's `items*100/8` integer division — 1/8 reports as 12%, not 13%).
     *   `next` is the first not-yet-done item in ITEMS order, or null at 100%.
     */
    public static function forUser(int $userId): array
    {
        $pg = Db::pg();

        $u = $pg->prepare(
            'SELECT avatar_version, banner_version, at_a_glance, business_name, display_name,
                    location_city, location_region
               FROM users WHERE id = :id'
        );
        $u->execute([':id' => $userId]);
        $row = $u->fetch();
        if ($row === false) {
            throw new \InvalidArgumentException("Completeness::forUser: no such user $userId");
        }

        $exists = function (string $sql) use ($pg, $userId): bool {
            $st = $pg->prepare($sql);
            $st->execute([':id' => $userId]);
            return (bool) $st->fetchColumn();
        };

        $items = [
            'photo'       => ((int) $row['avatar_version']) > 0,
            'location'    => trim((string) $row['location_city']) !== '' || trim((string) $row['location_region']) !== '',
            'what_you_do' => trim((string) $row['at_a_glance']) !== ''
                              || (trim(self::deEscape($row['business_name'])) !== ''
                                  && !str_ends_with((string) $row['display_name'], self::deEscape($row['business_name']))),
            'bio'         => $exists("SELECT 1 FROM profile_sections WHERE user_id = :id AND key = 'about'
                                         AND coalesce(data->>'text','') <> '' LIMIT 1"),
            'links'       => $exists('SELECT 1 FROM profile_socials WHERE user_id = :id LIMIT 1'),
            'craft'       => $exists('SELECT 1 FROM profile_skills WHERE user_id = :id LIMIT 1')
                              || $exists('SELECT 1 FROM profile_instruments WHERE user_id = :id LIMIT 1'),
            'gallery'     => $exists("SELECT 1 FROM profile_sections WHERE user_id = :id AND key LIKE 'gallery%' LIMIT 1"),
            'banner'      => ((int) $row['banner_version']) > 0,
        ];

        $score = 0;
        foreach (self::ITEMS as $k) { if ($items[$k]) $score++; }

        $next = null;
        foreach (self::ITEMS as $k) { if (!$items[$k]) { $next = $k; break; } }

        $cardReady = true;
        foreach (self::CARD_ITEMS as $k) { if (!$items[$k]) { $cardReady = false; break; } }

        return [
            'items'      => $items,
            'score'      => $score,
            'pct'        => intdiv($score * 100, 8),
            'card_ready' => $cardReady,
            'next'       => $next,
        ];
    }
}
