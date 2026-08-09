<?php
/**
 * archive-poc/web/sitemap.php — SEO sitemap (cut lane 2026-06-15, "don't lose our search").
 *
 * A lightweight, dependency-free sitemap (Rank Math was removed for perf — this is the
 * custom replacement). Served UNGATED like /robots.txt so Googlebot (anonymous) reaches
 * it at the cut; it lists only ALREADY-PUBLIC data (public/lite content paths + public
 * profile slugs), so there is nothing to leak.
 *
 *   /sitemap.xml            → sitemap index (points at the three section sitemaps)
 *   /sitemap-static.xml     → front, hub, archive, calendar, sponsors, about
 *   /sitemap-content.xml    → discovery.content_item, tier IN (public,lite),
 *                             EXCLUDING cpt='sponsor-product' (kind=misc, not user-facing).
 *                             tier='pro' is paywalled → omitted (don't advertise gated URLs).
 *   /sitemap-profiles.xml   → profile_app.users, profile_visibility='public' (~1,904)
 *
 * URLs are emitted on the CURRENT request host, so the same file is correct on dev today
 * and on loothgroup.com after the cut (no baked host). Section is parsed from REQUEST_URI.
 */

declare(strict_types=1);
require __DIR__ . '/../config.php';

header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');                 // the sitemap file itself is not a page
header('Cache-Control: public, max-age=3600');

$host = $_SERVER['HTTP_HOST'] ?? 'loothgroup.com';
$base = 'https://' . $host;

function sm_esc(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// Section from /sitemap-<section>.xml (PHP parses the path itself — no nginx param needed).
$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
$section = '';
if (preg_match('#/sitemap-([a-z]+)\.xml$#', $path, $m)) {
    $section = $m[1];
}

// ---- sitemap index ----
if ($section === '') {
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach (['static', 'content', 'profiles', 'discussions'] as $s) {
        echo '  <sitemap><loc>' . sm_esc("$base/sitemap-$s.xml") . '</loc></sitemap>' . "\n";
    }
    echo '</sitemapindex>' . "\n";
    exit;
}

// ---- a urlset section ----
$emit = function (string $loc, ?string $lastmod = null, ?string $changefreq = null) use ($base): void {
    echo '  <url><loc>' . sm_esc($base . $loc) . '</loc>';
    if ($lastmod)    echo '<lastmod>' . sm_esc($lastmod) . '</lastmod>';
    if ($changefreq) echo '<changefreq>' . sm_esc($changefreq) . '</changefreq>';
    echo '</url>' . "\n";
};

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

try {
    if ($section === 'static') {
        // Only advertise URLs that are index,follow at the page level. /archive/
        // and /calendar/ are noindex (search/thin); /about/ + /sponsors/ are
        // noindex placeholder stubs (the shared _page-shell hard-codes noindex).
        // Listing a noindex URL in the sitemap is a self-contradiction Google
        // flags — drop them here. When /about/ + /sponsors/ get real copy and are
        // flipped to index (add an $index param to lg_page_open), re-add them.
        $emit('/', null, 'daily');
        $emit('/hub/', null, 'daily');

    } elseif ($section === 'content') {
        $db = lg_archive_poc_pdo(); // looth, search_path = discovery
        $rows = $db->query("
            SELECT url, published_at
              FROM content_item
             WHERE tier IN ('public', 'lite')
               AND cpt <> 'sponsor-product'
             ORDER BY published_at DESC
        ");
        foreach ($rows as $r) {
            $p = parse_url((string) $r['url'], PHP_URL_PATH); // host-strip → emit on current host
            if (!$p) continue;
            $lm = !empty($r['published_at']) ? date('Y-m-d', strtotime((string) $r['published_at'])) : null;
            $emit($p, $lm);
        }

    } elseif ($section === 'profiles') {
        // profile_app is a separate DB; archive-poc has a column-scoped SELECT grant
        // (slug, profile_visibility, updated_at) — see tools/cut/sitemap-grants.sql.
        $pdo = new PDO('pgsql:host=/var/run/postgresql;dbname=profile_app', null, null);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $rows = $pdo->query("
            SELECT slug, updated_at
              FROM users
             WHERE profile_visibility = 'public'
               AND slug IS NOT NULL AND slug <> ''
               -- Exclude auto-generated patreon_<NNNNN> placeholder slugs
               -- (~1,639 of 1,915): thin/empty profiles that burn crawl budget
               -- and risk a thin-content penalty. /u.php noindexes the same set,
               -- so sitemap and page-level robots agree.
               AND slug !~ '^patreon_[0-9]+$'
             ORDER BY updated_at DESC
        ");
        foreach ($rows as $r) {
            $lm = !empty($r['updated_at']) ? date('Y-m-d', strtotime((string) $r['updated_at'])) : null;
            $emit('/u/' . rawurlencode((string) $r['slug']), $lm);
        }

    } elseif ($section === 'discussions') {
        /* THE DISCUSSIONS, and why they were missing until 2026-08-09.
         *
         * Every discussion has had a clean, server-rendered, correctly-titled page
         * all along — /hub/<forum-slug>/<topic-slug>/ — and the hub cards link to
         * it. What it never had was a way for Google to FIND it:
         *
         *   - This sitemap listed content_item and users, never the forum tables.
         *   - The rich listing that links to all of them is /hub/?type=discussions,
         *     and robots.txt says `Disallow: /hub/?` — a correct rule against
         *     infinite faceted crawl space that also happens to block the only
         *     complete index of the discussions.
         *   - Bare /hub/ is crawlable but shows ~38 links: whatever is on the front
         *     page today. Everything older was unreachable.
         *
         * So the community's own content — the reason anyone searches for this site
         * — was invisible while /login/ and /merch/ ranked. Listing the clean URLs
         * here bypasses the robots-blocked listing entirely.
         *
         * GATING, and it is not one column: `visibility` is the forum's own privacy
         * (public/private/hidden) and `tier_gate` is the paywall. Measured on live:
         * 1335 topics public/public, 14 in HIDDEN forums, 3 in PRIVATE. A hidden
         * forum's topics must never appear in a public sitemap, so both are asserted
         * — and tier_gate mirrors content_item's rule above rather than inventing a
         * second policy.
         *
         * lastmod is last_active_at, not created_at: a thread that gains a reply is
         * changed content and should be re-crawled. That is the whole point of the
         * field, and the hub-sort bug of 8/3 is a reminder these two clocks differ.
         */
        $db = lg_archive_poc_pdo();
        $rows = $db->query("
            SELECT f.slug AS forum_slug, t.slug AS topic_slug, t.last_active_at
              FROM forums.topic t
              JOIN forums.forum f ON f.id = t.forum_id
             WHERE f.visibility = 'public'
               AND f.tier_gate IN ('public', 'lite')
               AND t.slug IS NOT NULL AND t.slug <> ''
               AND f.slug IS NOT NULL AND f.slug <> ''
             ORDER BY t.last_active_at DESC NULLS LAST
        ");
        foreach ($rows as $r) {
            $lm = !empty($r['last_active_at'])
                ? date('Y-m-d', strtotime((string) $r['last_active_at']))
                : null;
            $emit(
                '/hub/' . rawurlencode((string) $r['forum_slug'])
                . '/' . rawurlencode((string) $r['topic_slug']) . '/',
                $lm
            );
        }
    }
} catch (Throwable $e) {
    error_log('sitemap: ' . $e->getMessage());
    // emit a valid (possibly short) urlset rather than a 500 to a crawler
}

echo '</urlset>' . "\n";
