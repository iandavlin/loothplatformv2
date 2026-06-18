<?php
/**
 * archive-poc/api/v0/guitardle-score.php — daily Guitardle result recording for
 * LOGGED-IN members (guitardle front-page block, Ian 2026-06-11).
 *
 * Runs on the looth-dev WP FPM pool (NOT the archive-poc pool), exactly like
 * card-react.php: the participation gate is the WP login cookie, because an
 * unbridged member is anon to /whoami but has a valid WP cookie. Anonymous
 * players never hit this with effect — the game plays local-only for them.
 *
 *   GET  → { authenticated:false }
 *        | { authenticated:true, wp_user_id, nonce, today: {won,moves,streak}|null }
 *   POST { phrase_id, won, moves, streak, _wpnonce (or X-WP-Nonce header) }
 *        → { ok:true, recorded:bool }   recorded=false → already had a row today
 *
 * IDOR-proof like the comment/reaction doors: the player is get_current_user_id()
 * — never client-supplied. One row per member per server-day; the FIRST result
 * wins (ON CONFLICT DO NOTHING) so a replay from a cleared browser can't
 * overwrite. This table is the future leaderboard's source of truth; the
 * leaderboard UI ships later.
 */

declare(strict_types=1);
require_once __DIR__ . '/_comments.php';   // lg_comments_pdo() + config.php

// Boot WordPress (looth-dev pool) for cookie/session + nonce.
if (!isset($_SERVER['HTTP_HOST']))   $_SERVER['HTTP_HOST']   = LG_ARCHIVE_POC_HOST;
if (!isset($_SERVER['REQUEST_URI'])) $_SERVER['REQUEST_URI'] = '/';
if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
require LG_ARCHIVE_POC_WP_LOAD;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Vary: Cookie');

function lg_gdle_json($payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uid    = (int) get_current_user_id();

// ---- GET: viewer state (nonce + today's recorded result, if any) ----------
if ($method === 'GET') {
    if ($uid <= 0) lg_gdle_json(['authenticated' => false]);

    $today = null;
    try {
        $st = lg_comments_pdo()->prepare(
            'SELECT won, moves, streak FROM guitardle_results
             WHERE wp_user_id = ? AND play_date = CURRENT_DATE');
        $st->execute([$uid]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $today = ['won' => (bool) $row['won'], 'moves' => (int) $row['moves'],
                      'streak' => (int) $row['streak']];
        }
    } catch (Throwable $e) {
        error_log('[lg-guitardle] GET: ' . $e->getMessage());
    }
    lg_gdle_json([
        'authenticated' => true,
        'wp_user_id'    => $uid,
        'nonce'         => wp_create_nonce('lg_guitardle_score'),
        'today'         => $today,
    ]);
}

if ($method !== 'POST') {
    lg_gdle_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

// ---- Same-origin guard (defense-in-depth) ---------------------------------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $host = parse_url($origin, PHP_URL_HOST) ?: '';
    if (strcasecmp($host, LG_ARCHIVE_POC_HOST) !== 0) {
        lg_gdle_json(['ok' => false, 'error' => 'bad_origin'], 403);
    }
}

// ---- Gate: must be a logged-in WP user (the WP login cookie) --------------
if ($uid <= 0) lg_gdle_json(['ok' => false, 'error' => 'auth_required'], 401);

// ---- Input ------------------------------------------------------------------
$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;

// CSRF: WP nonce, from header or body.
$nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? ($body['_wpnonce'] ?? '');
if (!wp_verify_nonce((string) $nonce, 'lg_guitardle_score')) {
    lg_gdle_json(['ok' => false, 'error' => 'bad_csrf'], 403);
}

$phraseId = isset($body['phrase_id']) ? (int) $body['phrase_id'] : 0;
$won      = !empty($body['won']);
$moves    = isset($body['moves']) ? (int) $body['moves'] : 0;
$streak   = isset($body['streak']) ? max(0, (int) $body['streak']) : 0;
$hardcore = !empty($body['hardcore']);
if ($moves < 1 || $moves > 99 || $phraseId < 0 || $phraseId > 100000) {
    lg_gdle_json(['ok' => false, 'error' => 'bad_request'], 400);
}

// ---- Record (first result of the day wins) ----------------------------------
try {
    $st = lg_comments_pdo()->prepare(
        'INSERT INTO guitardle_results (wp_user_id, play_date, phrase_id, won, moves, streak, hardcore)
         VALUES (?, CURRENT_DATE, ?, ?, ?, ?, ?)
         ON CONFLICT (wp_user_id, play_date) DO NOTHING');
    $st->execute([$uid, $phraseId, $won ? 'true' : 'false', $moves, $streak, $hardcore ? 'true' : 'false']);
    $recorded = $st->rowCount() > 0;
} catch (Throwable $e) {
    error_log('[lg-guitardle] ' . $e->getMessage());
    lg_gdle_json(['ok' => false, 'error' => 'server_error'], 500);
}

lg_gdle_json(['ok' => true, 'recorded' => $recorded]);
