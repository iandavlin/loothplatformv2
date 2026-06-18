<?php
/**
 * archive-poc/api/v0/guitardle-board.php — weekly Guitardle leaderboard (READ).
 *
 * Runs on the archive-poc FPM pool (no WP boot — this renders inside the game
 * on every front-page load, so it must be fast). Read-only over
 * discovery.guitardle_results joined to discovery.person for display names.
 *
 * Score math (Ian 6/11): each WIN is worth (11 − moves) points, floor 1 —
 * DOUBLED for hardcore-mode wins; a loss is 0. Weekly score = sum of points, week = ISO week (resets Monday).
 * Ties: fewer total moves on wins, then who got there first.
 *
 * Full ranked list — no top-N cap (Ian: "do the entire count").
 *
 *   GET → { week_start: 'YYYY-MM-DD', leaders: [{rank, name, points, wins,
 *           best_moves}] }
 *
 * Exposes display names + counts only — no ids, no per-day history.
 */

declare(strict_types=1);
require_once __DIR__ . '/_likes.php';      // lg_likes_pdo() → discovery search_path
require_once __DIR__ . '/_comments.php';   // lg_comments_profile_lookup() → profile-app names/slugs

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

try {
    $pdo = lg_likes_pdo();
    $st  = $pdo->query(
        "SELECT r.wp_user_id,
                COALESCE(NULLIF(p.display_name, ''), 'Member') AS name,
                SUM(GREATEST(11 - r.moves, 1) * (CASE WHEN r.hardcore THEN 2 ELSE 1 END)) FILTER (WHERE r.won)::int AS points,
                COUNT(*) FILTER (WHERE r.won)::int                       AS wins,
                COALESCE(SUM(r.moves) FILTER (WHERE r.won), 0)::int      AS win_moves,
                MIN(r.moves) FILTER (WHERE r.won)::int                   AS best_moves
         FROM guitardle_results r
         LEFT JOIN person p ON p.id = r.wp_user_id
         WHERE r.play_date >= date_trunc('week', CURRENT_DATE)::date
         GROUP BY r.wp_user_id, p.display_name
         HAVING COUNT(*) FILTER (WHERE r.won) > 0
         ORDER BY points DESC, win_moves ASC, MIN(r.created_at) ASC");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $weekStart = $pdo->query("SELECT date_trunc('week', CURRENT_DATE)::date")->fetchColumn();

    // Names + profile links from profile-app (the identity source; its slugs
    // are NOT WP nicenames). Best effort: on any miss, fall back to the
    // person-mirror name with no link.
    $profiles = [];
    try {
        foreach (lg_comments_profile_lookup('wp_ids', array_column($rows, 'wp_user_id')) as $it) {
            $wid = (int) ($it['wp_user_id'] ?? 0);
            if ($wid > 0) $profiles[$wid] = $it;
        }
    } catch (Throwable $e) { /* board still renders unlinked */ }

    $leaders = [];
    foreach ($rows as $i => $r) {
        $prof = $profiles[(int) $r['wp_user_id']] ?? null;
        $name = $prof['display_name'] ?? null;
        if (!is_string($name) || $name === '') {
            $name = html_entity_decode((string) $r['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $slug = isset($prof['slug']) && is_string($prof['slug']) && $prof['slug'] !== '' ? $prof['slug'] : null;
        $leaders[] = [
            'rank'        => $i + 1,
            'name'        => $name,
            'profile_url' => $slug !== null ? '/u/' . rawurlencode($slug) : null,
            'points'      => (int) $r['points'],
            'wins'        => (int) $r['wins'],
            'best_moves'  => (int) $r['best_moves'],
        ];
    }
    echo json_encode(['week_start' => $weekStart, 'leaders' => $leaders],
                     JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[lg-guitardle-board] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'server_error']);
}
