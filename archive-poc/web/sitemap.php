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
    foreach (['static', 'content', 'profiles'] as $s) {
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
        $emit('/', null, 'daily');
        $emit('/hub/', null, 'daily');
        $emit('/archive/', null, 'daily');
        $emit('/calendar/', null, 'weekly');
        $emit('/sponsors/', null, 'weekly');
        $emit('/about/', null, 'monthly');

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
             ORDER BY updated_at DESC
        ");
        foreach ($rows as $r) {
            $lm = !empty($r['updated_at']) ? date('Y-m-d', strtotime((string) $r['updated_at'])) : null;
            $emit('/u/' . rawurlencode((string) $r['slug']), $lm);
        }
    }
} catch (Throwable $e) {
    error_log('sitemap: ' . $e->getMessage());
    // emit a valid (possibly short) urlset rather than a 500 to a crawler
}

echo '</urlset>' . "\n";
