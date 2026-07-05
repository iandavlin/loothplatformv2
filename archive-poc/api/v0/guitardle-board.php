<?php
/**
 * archive-poc/api/v0/guitardle-board.php — weekly Guitardle leaderboard (READ).
 *
 * Runs on the archive-poc FPM pool (no WP boot — this renders inside the game
 * on every front-page load, so it must be fast). Read-only over
 * discovery.guitardle_results joined to discovery.person for display names.
 *
 * Score math (Ian 6/11): each WIN is worth (11 − moves) points, floor 1 —
 * DOUBLED for hardcore-mode wins; a loss is 0. Weekly score = sum of points.
 * Ties: fewer total moves on wins, then who got there first.
 *
 * WEEK DEFINITION (Ian 7/04 champion feature): a week is ISO Monday→Sunday
 * over play_date — and play_date is the PLAYER'S LOCAL calendar day (see
 * guitardle-score.php), so the board groups the days players actually saw,
 * not UTC days. Which week is "current" is anchored on the requester's own
 * local day: the client passes ?local_date, validated with the same ±1-day
 * clamp as the score API (fallback: the server's UTC day). The window is
 * bounded on BOTH sides — an east-of-UTC player's Monday rows must not bleed
 * into the prior week's board on a UTC Sunday.
 *
 * Full ranked list — no top-N cap (Ian: "do the entire count").
 *
 *   GET [?week=current|last] [?local_date=YYYY-MM-DD] [?champion=1]
 *     → { week, week_start, week_end, leaders: [{rank, name, profile_url,
 *         points, wins, best_moves}], champion?: {name, profile_url, points,
 *         wins}|null }
 *
 *   week      window shown (default current); 'last' = the week before.
 *   champion  include rank-1 of the week BEFORE the shown week (the game
 *             page fetches week=current&champion=1 → last week's champion
 *             plus this week's full board in ONE request).
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

// Twin of lg_gdle_local_date() in guitardle-score.php (kept in sync by hand —
// that file boots WP on another pool, so they can't share an include cheaply):
// honour a client-supplied YYYY-MM-DD only when it's a real date within ±1 day
// of the server's UTC date; anything else → null (caller falls back to UTC).
function lg_gdle_board_local_date($raw): ?string {
    if (!is_string($raw) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) return null;
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return null;
    $client = strtotime($raw . ' 00:00:00 UTC');
    $server = strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC');
    if ($client === false || $server === false) return null;
    $diffDays = (int) round(($client - $server) / 86400);
    return ($diffDays >= -1 && $diffDays <= 1) ? $raw : null;
}

// Ranked winners for one bounded week window [start, endExcl).
function lg_gdle_week_rows(PDO $pdo, string $start, string $endExcl): array {
    $st = $pdo->prepare(
        "SELECT r.wp_user_id,
                COALESCE(NULLIF(p.display_name, ''), 'Member') AS name,
                SUM(GREATEST(11 - r.moves, 1) * (CASE WHEN r.hardcore THEN 2 ELSE 1 END)) FILTER (WHERE r.won)::int AS points,
                COUNT(*) FILTER (WHERE r.won)::int                       AS wins,
                COALESCE(SUM(r.moves) FILTER (WHERE r.won), 0)::int      AS win_moves,
                MIN(r.moves) FILTER (WHERE r.won)::int                   AS best_moves
         FROM guitardle_results r
         LEFT JOIN person p ON p.id = r.wp_user_id
         WHERE r.play_date >= ?::date AND r.play_date < ?::date
         GROUP BY r.wp_user_id, p.display_name
         HAVING COUNT(*) FILTER (WHERE r.won) > 0
         ORDER BY points DESC, win_moves ASC, MIN(r.created_at) ASC");
    $st->execute([$start, $endExcl]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

try {
    $pdo = lg_likes_pdo();

    // Anchor day → Monday of its ISO week (all math in whole UTC days).
    $anchor = lg_gdle_board_local_date($_GET['local_date'] ?? null) ?? gmdate('Y-m-d');
    $ts     = strtotime($anchor . ' 00:00:00 UTC');
    $monTs  = $ts - (((int) gmdate('N', $ts)) - 1) * 86400;

    $week = (($_GET['week'] ?? 'current') === 'last') ? 'last' : 'current';
    if ($week === 'last') $monTs -= 7 * 86400;

    $weekStart   = gmdate('Y-m-d', $monTs);
    $weekEndExcl = gmdate('Y-m-d', $monTs + 7 * 86400);
    $weekEnd     = gmdate('Y-m-d', $monTs + 6 * 86400);   // inclusive Sunday, for display

    $rows = lg_gdle_week_rows($pdo, $weekStart, $weekEndExcl);

    // Champion = rank 1 of the week before the shown week.
    $wantChampion = !empty($_GET['champion']);
    $champRow     = null;
    if ($wantChampion) {
        $champRows = lg_gdle_week_rows($pdo, gmdate('Y-m-d', $monTs - 7 * 86400), $weekStart);
        $champRow  = $champRows[0] ?? null;
    }

    // Names + profile links from profile-app (the identity source; its slugs
    // are NOT WP nicenames). One batched lookup for leaders + champion. Best
    // effort: on any miss, fall back to the person-mirror name with no link.
    $ids = array_column($rows, 'wp_user_id');
    if ($champRow) $ids[] = $champRow['wp_user_id'];
    $profiles = [];
    try {
        foreach (lg_comments_profile_lookup('wp_ids', array_values(array_unique($ids))) as $it) {
            $wid = (int) ($it['wp_user_id'] ?? 0);
            if ($wid > 0) $profiles[$wid] = $it;
        }
    } catch (Throwable $e) { /* board still renders unlinked */ }

    $resolve = function (array $r) use ($profiles): array {
        $prof = $profiles[(int) $r['wp_user_id']] ?? null;
        $name = $prof['display_name'] ?? null;
        if (!is_string($name) || $name === '') {
            $name = html_entity_decode((string) $r['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $slug = isset($prof['slug']) && is_string($prof['slug']) && $prof['slug'] !== '' ? $prof['slug'] : null;
        return [$name, $slug !== null ? '/u/' . rawurlencode($slug) : null];
    };

    $leaders = [];
    foreach ($rows as $i => $r) {
        [$name, $url] = $resolve($r);
        $leaders[] = [
            'rank'        => $i + 1,
            'name'        => $name,
            'profile_url' => $url,
            'points'      => (int) $r['points'],
            'wins'        => (int) $r['wins'],
            'best_moves'  => (int) $r['best_moves'],
        ];
    }

    $payload = [
        'week'       => $week,
        'week_start' => $weekStart,
        'week_end'   => $weekEnd,
        'leaders'    => $leaders,
    ];
    if ($wantChampion) {
        $champion = null;
        if ($champRow) {
            [$name, $url] = $resolve($champRow);
            $champion = [
                'name'        => $name,
                'profile_url' => $url,
                'points'      => (int) $champRow['points'],
                'wins'        => (int) $champRow['wins'],
            ];
        }
        $payload['champion'] = $champion;
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[lg-guitardle-board] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'server_error']);
}
