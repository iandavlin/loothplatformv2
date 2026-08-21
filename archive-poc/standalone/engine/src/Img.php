<?php
/**
 * Img — route a content image through the on-the-fly resizer.
 *
 * CLAUDE.md's standing law: "Images: always the resizer (/img.php?w=) + srcset
 * + width/height — never raw uploads, never one-size." Until 2026-08-21 (#187)
 * the article renderer obeyed none of it: /loothprint/fret-sander-v2/ shipped
 * 11 <img> tags, 0 through /img.php, 17 raw uploads URLs and 1 srcset, with a
 * 2000px hero landing in a 780px slot. Measured on that hero:
 *
 *     raw original      105,396 bytes
 *     /img.php w=400      5,842 bytes
 *     /img.php w=800     18,132 bytes   <- what a phone actually renders
 *     /img.php w=1200    34,872 bytes
 *
 * The resizer is bb-mirror/web/img.php, deployed at /var/www/dev/img.php. This
 * class is the standalone engine's front door to it, and is a deliberate mirror
 * of the trio already proven in bb-mirror/web/forums/_feed.php (lg_cover_src /
 * lg_cover_srcset / lg_cover_dims) — same URL shape, same buckets. It differs in
 * exactly one respect, and that difference is the point:
 *
 *   ⚠️ DIMENSIONS COME FROM METADATA, NEVER FROM THE FILESYSTEM. The feed's
 *   lg_cover_dims() calls getimagesize() on the R2 uploads mount. This renderer
 *   runs in the archive-poc FPM pool, which has no business touching that mount
 *   and (measured) no permission to. It does not need to: the materialized blob
 *   already carries width AND height for every generated variant, so the aspect
 *   ratio is a pure read of data we were handed. No stat, no R2 hop, no
 *   per-image cost on render.
 *
 * @see bb-mirror/web/img.php          the resizer itself (ALLOWED_W below)
 * @see bb-mirror/web/forums/_feed.php the pattern this mirrors
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class Img
{
    /**
     * The resizer's allowed widths — bb-mirror/web/img.php ALLOWED_W, verbatim.
     * A w= outside this list is silently clamped to 800 by img.php, so asking
     * for one is asking for the wrong image; every width here goes through
     * bucket() first.
     */
    public const BUCKETS = [96, 240, 400, 480, 600, 800, 960, 1200, 1600];

    /**
     * The variant keys WordPress generates by SCALING, so their width:height is
     * the source's. Everything else in the sizes map may be a CROP —
     * `thumbnail` is 150x150 on a 16:9 photo, and a ratio taken from it would
     * declare a square hero and shove the page down on load. Restricting the
     * read to this list is what makes dims() safe to trust.
     */
    private const UNCROPPED = ['2048x2048', '1536x1536', 'large', 'medium_large', 'medium'];

    /**
     * Rewrite one of OUR uploads URLs to the resizer at a display width.
     * Anything else — a foreign host, a data: URI, an avatar served by
     * BuddyBoss — passes through untouched.
     *
     * Host-relative on purpose, and it fixes a second defect for free: a blob
     * cut from the other box carries THAT box's host in every stored media URL
     * (PAGE.md, 2026-08-21 — 46 of 60 hub cards linked live from dev2). Matching
     * on the /wp-content/uploads/ tail and emitting a host-relative URL means
     * the image is fetched from the box the reader is actually on. If the file
     * genuinely is not here, img.php 302s to the same host-relative original —
     * i.e. exactly today's behaviour, minus the wrong host.
     */
    public static function src(?string $url, int $w, array $sizes = []): string
    {
        $url = self::source((string) $url, $sizes);
        if ($url === '') return '';
        $rel = self::uploadsPath($url);
        return $rel === '' ? $url : '/img.php?s=' . rawurlencode($rel) . '&w=' . self::bucket($w);
    }

    /**
     * The file the resizer should READ FROM — the widest uncropped VARIANT when
     * the blob lists one, and only then the original.
     *
     * ⚠️ THIS IS NOT AN OPTIMISATION, IT IS THE THING THAT STOPS THE FIX BREAKING
     * PHOTOS. MEASURED 2026-08-21: 1,196 distinct media files on this box are
     * stored with the LIVE host in their URL (a box cut from live keeps the other
     * box's host — PAGE.md), and 11 of them have no ORIGINAL on dev2 while their
     * variants are all present. Rewriting those to a host-relative resizer URL
     * therefore turned four working heroes into 404s — bridge-wing-flattener's
     * among them, verified before this guard existed.
     *
     * A variant is the safer read because the materializer PROVES it: it drops
     * any size whose file is absent from disk before the blob is written
     * (archive-poc/bin/materializer.php, "Defensive srcset filter"). Nothing
     * makes that promise about the original. And nothing is lost by it — the
     * widest variant is 1536px against buckets that top out at 1600, so what
     * the reader receives is the same image at the same width.
     */
    private static function source(string $url, array $sizes): string
    {
        if ($url === '') return '';
        $best = 0; $pick = '';
        foreach (self::UNCROPPED as $key) {
            $w = (int) ($sizes[$key]['width'] ?? 0);
            $u = (string) ($sizes[$key]['url'] ?? '');
            if ($w > $best && $u !== '') { $best = $w; $pick = $u; }
        }
        return $pick !== '' ? $pick : $url;
    }

    /**
     * A srcset across the resizer's buckets, so a phone stops paying for a
     * desktop photo. Returns '' when there is nothing honest to say.
     *
     * ⚠️ NEVER OFFERS A CANDIDATE WIDER THAN THE SOURCE. img.php does not
     * upscale, so a "1600w" candidate cut from a 1024px original is the SAME
     * bytes wearing a bigger descriptor — the browser believes the lie, picks
     * it on a retina screen, and downloads a 1024px image it could have had
     * from the 1200w entry. The cap is the widest UNCROPPED variant in the
     * blob, which is a lower bound on the original (WordPress only generates a
     * size when the source is at least that wide), so it can under-promise but
     * never over-promise.
     *
     * No cap derivable (an old import with no generated sizes) => NO srcset at
     * all, just the single resized src. One right image beats several
     * candidates whose widths we are guessing at.
     */
    public static function srcset(?string $url, array $sizes, array $widths): string
    {
        $url = self::source((string) $url, $sizes);
        if ($url === '' || self::uploadsPath($url) === '') return '';
        $cap = self::intrinsic($url, $sizes)[0] ?? 0;
        if ($cap <= 0) return '';

        $entries = [];
        foreach ($widths as $w) {
            $b = self::bucket((int) $w);
            if ($b > $cap) continue;
            $entries[$b] = self::src($url, $b) . ' ' . $b . 'w';   /* $url is already the resolved source */
        }
        // The cap itself is a legitimate top candidate when the ladder is all
        // below it — otherwise a 1536px source would top out at 1200w for no
        // reason. Only when a bucket actually fits under the cap.
        if (count($entries) < 2) return '';
        ksort($entries);
        return implode(', ', $entries);
    }

    /**
     * width/height for the <img>, so the browser reserves the box before the
     * photo lands. Returns '' when the ratio is not derivable — an absent attr
     * pair is exactly today's behaviour, whereas a guessed one moves the page.
     *
     * The width reported is the width the reader will ACTUALLY receive:
     * min(bucket, source), because img.php never upscales. Declaring 1600 on a
     * 1024px source would make the attrs disagree with the bytes, which is the
     * layout shift these attrs exist to prevent.
     */
    public static function dims(?string $url, array $sizes, int $w): string
    {
        $url = self::source((string) $url, $sizes);
        if ($url === '' || self::uploadsPath($url) === '') return '';
        [$cap, $srcH] = self::intrinsic($url, $sizes) ?: [0, 0];
        if ($cap <= 0 || $srcH <= 0) return '';
        $tw = min(self::bucket($w), $cap);
        $th = (int) round($tw * $srcH / $cap);
        return $th > 0 ? ' width="' . $tw . '" height="' . $th . '"' : '';
    }

    /** The uploads-relative path of one of our URLs, or '' if it is not ours. */
    private static function uploadsPath(string $url): string
    {
        // Query/fragment first: without this, an already-resized URL or a
        // cache-busted one would be swallowed whole into s=.
        $clean = (string) preg_replace('/[?#].*$/', '', $url);
        if (strpos($clean, '/img.php') !== false) return '';      // already resized
        return preg_match('#/wp-content/uploads/(.+)$#', $clean, $m) ? $m[1] : '';
    }

    /** Smallest allowed bucket that covers $w; the largest when nothing does. */
    private static function bucket(int $w): int
    {
        foreach (self::BUCKETS as $b) {
            if ($b >= $w) return $b;
        }
        return (int) end(self::BUCKETS);
    }

    /**
     * [width, height] of the source, or null when it cannot be known.
     *
     * TWO sources, in order of trust:
     *
     * 1. THE BLOB'S sizes MAP — the widest UNCROPPED variant. Its width is a
     *    lower bound on the original (WordPress only generates a size when the
     *    source is at least that wide) and its ratio IS the source's.
     *
     * 2. THE FILENAME. Not every image arrives with a sizes map: the related-post
     *    cards are handed a bare URL, and old imports predate intermediate sizes
     *    entirely. But WordPress names a generated variant `<stem>-<W>x<H>.<ext>`,
     *    and that suffix is authoritative about the file it names — img.php's own
     *    bb_medias fallback reads widths the same way. Without this, exactly the
     *    images with no metadata would get no srcset and no dims, which is the
     *    "never one-size" half of the law going unenforced on the shabbiest data.
     *
     * A bare original (`photo.webp`, no suffix, no map) still yields null, and
     * the callers degrade to a single resized src — smaller than today, and
     * never a guessed aspect ratio.
     *
     * @return array{0:int,1:int}|null
     */
    private static function intrinsic(string $url, array $sizes): ?array
    {
        $best = 0; $bestH = 0;
        foreach (self::UNCROPPED as $key) {
            $w = (int) ($sizes[$key]['width'] ?? 0);
            $h = (int) ($sizes[$key]['height'] ?? 0);
            if ($w > $best && $h > 0) { $best = $w; $bestH = $h; }
        }
        if ($best > 0) return [$best, $bestH];

        $rel = self::uploadsPath($url);
        if ($rel !== '' && preg_match('/-(\d+)x(\d+)\.[a-z0-9]+$/i', $rel, $m)) {
            $w = (int) $m[1]; $h = (int) $m[2];
            if ($w > 0 && $h > 0) return [$w, $h];
        }
        return null;
    }
}
