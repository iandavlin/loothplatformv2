<?php
/**
 * archive-poc/api/v0/download.php — entitlement-gated in-stream download.
 *
 * GET /archive-api/v0/download?item_id=<id>
 *
 * The stream links here, NEVER to the raw uploads URL. This endpoint:
 *   1. Re-checks entitlement server-side (item tier vs /whoami tier) — it does
 *      NOT trust the feed's gating; a hand-crafted request to a gated item's id
 *      is rejected here too.
 *   2. Hands the file off to nginx via X-Accel-Redirect to an INTERNAL location
 *      (/__lg_dl/) that aliases wp-uploads — so PHP never streams bytes and the
 *      filesystem path is never exposed to the client.
 *
 * Runs on the archive-poc FPM pool (has /whoami + SQLite read), cookie-gated.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';   // PDO + whoami + tier consts

if (!defined('LG_STREAM_TIER_RANK')) {
    define('LG_STREAM_TIER_RANK', ['public' => 0, 'lite' => 1, 'pro' => 2]);
}

function dl_fail(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

$itemId = isset($_GET['item_id']) ? (int) $_GET['item_id'] : 0;
if ($itemId <= 0) dl_fail(400, 'bad request');

// Look up the item (SQLite index).
try {
    $db = lg_archive_poc_pdo();
    $st = $db->prepare('SELECT kind, tier, ' . lg_bool_sel($db, 'has_download', 'has_download') . ', download_path, title
                        FROM content_item WHERE id = ? LIMIT 1');
    $st->execute([$itemId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('download.php: ' . $e->getMessage());
    dl_fail(500, 'server error');
}
if (!$row || empty($row['has_download']) || empty($row['download_path'])) {
    dl_fail(404, 'not found');
}

// Server-side entitlement: item tier vs viewer tier from /whoami (verbatim).
$itemTier = strtolower((string) ($row['tier'] ?? 'public'));
if (!isset(LG_STREAM_TIER_RANK[$itemTier])) $itemTier = 'public';
$who        = lg_archive_poc_whoami() ?: [];
$viewerTier = (!empty($who['authenticated']) && isset(LG_STREAM_TIER_RANK[$who['tier'] ?? '']))
            ? (string) $who['tier'] : 'public';
if (LG_STREAM_TIER_RANK[$itemTier] > LG_STREAM_TIER_RANK[$viewerTier]) {
    dl_fail(403, 'not entitled');   // gated payload stays out of reach
}

// Path hygiene: download_path is built by the indexer (uploads-relative), but
// re-validate — reject traversal / absolute paths defensively.
$rel = ltrim((string) $row['download_path'], '/');
if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, "\0") !== false) {
    dl_fail(404, 'not found');
}

// Hand off to the internal nginx location (alias → wp-uploads). nginx streams
// the bytes; PHP exits. URL-encode each segment so spaces/specials are safe.
$encoded = implode('/', array_map('rawurlencode', explode('/', $rel)));
$filename = basename($rel);

// Explicit Content-Type by extension — FPM otherwise stamps text/html, which
// survives X-Accel-Redirect. octet-stream fallback keeps unknown types a safe
// attachment download.
$MIME = [
    'pdf' => 'application/pdf', 'zip' => 'application/zip',
    'stl' => 'model/stl', '3mf' => 'model/3mf', 'step' => 'model/step', 'stp' => 'model/step',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
];
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

header('X-Accel-Redirect: /__lg_dl/' . $encoded);
header('Content-Type: ' . ($MIME[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
header('X-Content-Type-Options: nosniff');
exit;
