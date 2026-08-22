<?php
/**
 * archive-poc/api/v0/_featured-history.php — read-only, the "Featured
 * history" table on the WP admin dash (featured-members lane, backlog 18).
 *
 * Loopback-only, same secret as _config.php (X-LG-Config-Secret against
 * /etc/lg-archive-poc-secret) — this is a read into the SAME trust boundary
 * _config.php already writes (discovery.featured_history, written by that
 * one file's own history-tracking block), not a new one. No separate secret
 * file for one more read of the same data domain.
 *
 * GET → { history: [ { member_uuid, display_name, started_at, ended_at, pinned?,
 *                       chosen_by }, ... ] }   newest-first, capped at 30 —
 *   the dash mock (dash.html) shows a handful of recent stints, not a full
 *   archive; a member-facing "my history" surface would be a different,
 *   unbounded endpoint if ever wanted.
 */

declare(strict_types=1);
require __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if ($remote !== '127.0.0.1' && $remote !== '::1') {
    http_response_code(403);
    echo json_encode(['error' => 'loopback only']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET only']);
    exit;
}
$expected = LG_ARCHIVE_POC_CONFIG_SECRET;
$provided = $_SERVER['HTTP_X_LG_CONFIG_SECRET'] ?? '';
if ($expected === '' || !hash_equals($expected, (string) $provided)) {
    http_response_code(401);
    echo json_encode(['error' => 'bad or missing X-LG-Config-Secret']);
    exit;
}

try {
    $pdo = lg_archive_poc_pdo();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
        // sqlite backend (local dev without the pg migration applied) — the
        // table simply doesn't exist there. Empty history, not an error.
        echo json_encode(['history' => []]);
        exit;
    }
    // #200 — `pinned` tells a stint the member ASKED for from one an admin
    // placed. Selected only when the column exists, because
    // tools/migrations/200-featured-history-pinned.sql has not run on live and
    // naming a missing column here would 500 the whole history read rather than
    // degrade — the dash would then show "No one has been featured yet", which
    // is a confident lie about a table full of rows.
    //
    // The key is then ABSENT from the payload on an unmigrated box, and the dash
    // renders a dash rather than "opted in": "I do not know how this member got
    // here" and "they consented" must not look alike, and this is the column
    // someone auditing consent would read.
    $hasPinned = false;
    try {
        $chk = $pdo->query(
            "SELECT 1 FROM information_schema.columns
              WHERE table_schema = 'discovery' AND table_name = 'featured_history'
                AND column_name = 'pinned' LIMIT 1"
        );
        $hasPinned = (bool) ($chk && $chk->fetchColumn());
    } catch (Throwable $e) {
        $hasPinned = false;
    }
    $rows = $pdo->query(
        'SELECT member_uuid, display_name, started_at, ended_at, chosen_by'
        . ($hasPinned ? ', pinned' : '') . '
           FROM discovery.featured_history
          ORDER BY started_at DESC LIMIT 30'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[featured-history read] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'db_error']);
    exit;
}

echo json_encode(['history' => $rows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
