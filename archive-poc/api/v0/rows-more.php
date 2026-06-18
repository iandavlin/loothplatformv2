<?php
/**
 * archive-poc/api/v0/rows-more.php
 *
 * Returns the next page of rail cards for a row, by row_id + offset.
 * GET /archive-api/v0/rows-more?row_id=frets-row&offset=10
 * Response: { items_html: "<a>...</a>...", count: 10, has_more: true, next_offset: 20 }
 */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_rowlib.php';

$row_id = param_str('row_id');
$offset = max(0, min(500, param_int('offset', 0)));
$want   = 10; // how many to reveal per click
if ($row_id === '') send_json(['error' => 'row_id required'], 400);

// Load row config (config.json overlay → defaults.php fallback).
$config_path = realpath(__DIR__ . '/../../config.json');
$config = [];
if ($config_path && is_file($config_path)) {
    $raw = @file_get_contents($config_path);
    $config = $raw !== false ? (json_decode($raw, true) ?: []) : [];
}
if (empty($config['rows'])) {
    $defaults = require __DIR__ . '/../../web/defaults.php';
    $config['rows'] = $defaults['rows'] ?? [];
}

$row = null;
foreach ($config['rows'] as $r) {
    if (($r['id'] ?? '') === $row_id) { $row = $r; break; }
}
if (!$row) send_json(['error' => 'row not found'], 404);
if (($row['layout'] ?? '') !== 'rail') send_json(['error' => 'row is not a rail'], 400);

// Viewer tier from /whoami ONLY — this endpoint had NO server check at all,
// so anon + a forged lg_tier=pro cookie paged every gated rail ungated (Buck
// paywall audit 6/11). Mirrors web/index.php; anon fails closed to public.
$viewer_tier = lg_archive_poc_viewer_tier(lg_archive_poc_whoami()); // anon→public, admin→pro (config.php)
$GLOBALS['LG_VIEWER_TIER'] = $viewer_tier;

// Inject offset + force limit=$want for this page.
$row['query']['limit']  = $want;
$row['query']['offset'] = $offset;

$result = archive_poc_run_row($db, $row);
$items  = $result['items'] ?? [];

// --- Render helpers (duplicated minimal set from web/index.php) ----------
if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'); }
}
if (!defined('LG_FALLBACK_IMG')) {
    define('LG_FALLBACK_IMG', 'https://loothgroup.com/wp-content/uploads/2024/11/Featured-Image-Fallback-2.webp');
}
if (!defined('KIND_LABELS')) {
    define('KIND_LABELS', [
        'article' => 'Articles', 'video' => 'Videos', 'loothprint' => 'Loothprints',
        'event' => 'Events', 'discussion' => 'Discussions', 'profile' => 'Profiles',
        'benefit' => 'Benefits', 'misc' => 'Misc',
    ]);
}
if (!function_exists('thumb_url')) {
    function thumb_url(array $it): string {
        if (!empty($it['thumb_url']) && empty($it['thumb_broken'])) return $it['thumb_url'];
        return LG_FALLBACK_IMG;
    }
}
if (!function_exists('tier_label')) {
    function tier_label(string $tier): string {
        return match (strtolower($tier)) { 'pro' => 'Pro', 'lite' => 'Lite', default => 'Public' };
    }
}

require_once __DIR__ . '/../../web/_render-card.php';

$html = '';
foreach ($items as $it) {
    $html .= archive_poc_render_rcard($it, $viewer_tier);
}

send_json([
    'items_html'  => $html,
    'count'       => count($items),
    'has_more'    => count($items) === $want,
    'next_offset' => $offset + count($items),
]);
