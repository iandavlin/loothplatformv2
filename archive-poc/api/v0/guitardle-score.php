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
 *   GET [?local_date=YYYY-MM-DD]
 *        → { authenticated:false }
 *        | { authenticated:true, wp_user_id, nonce, today: {phrase_id,won,moves,streak}|null }
 *   POST { phrase_id, won, moves, streak, local_date, _wpnonce (or X-WP-Nonce header) }
 *        → { ok:true, recorded:bool }   recorded=false → already had a row that day
 *
 * Behind LG_GUITARDLE_DAILY_CLAIM (backlog 22, Ian 2026-08-14) the daily
 * allowance is claimed at the START of a game instead of recorded at the end,
 * because the leak was never the recording — it was ABANDONING. The GET then
 * also returns `claim:true` and `pending` (a claimed-but-unfinished attempt),
 * and two more POST actions exist:
 *
 *   POST { action:'start', phrase_id, hardcore, local_date }
 *        → { ok:true, claimed:bool }   claimed=false → the day was already taken
 *   POST { action:'save',  state, hardcore, local_date }
 *        → { ok:true, saved:bool }     mid-game position, for cross-device resume
 *
 * With the flag OFF neither action exists (409 not_enabled), no claim row is
 * ever written, and every response above is byte-identical to what it was
 * before — asserted per state by tools/gates/guitardle-claim-gate.py.
 *
 * IDOR-proof like the comment/reaction doors: the player is get_current_user_id()
 * — never client-supplied. One row per member per LOCAL day; the FIRST result
 * wins (ON CONFLICT DO NOTHING) so a replay from a cleared browser can't
 * overwrite. play_date is keyed on the player's LOCAL calendar day (the client
 * sends it), not the DB's UTC CURRENT_DATE — see lg_gdle_local_date() for the
 * ±1-day anti-abuse window. This table is the future leaderboard's source of
 * truth; the leaderboard UI ships later.
 */

declare(strict_types=1);
require_once __DIR__ . '/_comments.php';   // lg_comments_pdo() + config.php
require_once __DIR__ . '/_flags.php';      // LG_GUITARDLE_DAILY_CLAIM (backlog 22)

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

// Resolve the player's LOCAL calendar day into a play_date. Players live in
// local time but the DB runs in UTC, so a result keyed on CURRENT_DATE can land
// a calendar day off the day actually played — a US-Pacific evening is already
// "tomorrow" in UTC. The client sends its own date (YYYY-MM-DD); we honour it
// only when it is a real date within ±1 day of the server's UTC date. That
// window covers every real timezone (max UTC offset is well under 24h, so a
// local day differs from the UTC day by at most one) while blocking a spoofed
// date from back/forward-dating to replay or stuff the board. Returns the
// validated date, or null when absent / malformed / out of range.
function lg_gdle_local_date($raw): ?string {
    if (!is_string($raw) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) return null;
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return null;
    $client = strtotime($raw . ' 00:00:00 UTC');
    $server = strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC');
    if ($client === false || $server === false) return null;
    $diffDays = (int) round(($client - $server) / 86400);
    return ($diffDays >= -1 && $diffDays <= 1) ? $raw : null;
}

// The POST play_date, resolved with the same +/-1 day anti-abuse window as
// above. An ABSENT date falls back to the server UTC day so an older cached
// client still records honestly; a supplied-but-invalid one is an abuse attempt
// (back/forward-dating to replay or stuff the board) and is rejected. Extracted
// verbatim from the record path so a START claim cannot be aimed at a different
// day than the FINISH that fills it.
function lg_gdle_post_play_date($rawLocal): string {
    $playDate = lg_gdle_local_date($rawLocal);
    if ($playDate === null) {
        if ($rawLocal !== null && $rawLocal !== '') {
            lg_gdle_json(['ok' => false, 'error' => 'bad_date'], 400);
        }
        $playDate = gmdate('Y-m-d');
    }
    return $playDate;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uid    = (int) get_current_user_id();

// ---- GET: viewer state (nonce + today's recorded result, if any) ----------
if ($method === 'GET') {
    if ($uid <= 0) {
        // Anon still needs to know the flag state: it drives the honest
        // logged-out line in the game ("Playing for fun -- sign in to compete
        // for the Weekly Top 5."). OFF -> this stays a bare
        // {"authenticated":false}, byte for byte as before.
        $anon = ['authenticated' => false];
        if (LG_GUITARDLE_DAILY_CLAIM) $anon['claim'] = true;
        lg_gdle_json($anon);
    }

    $today   = null;   // a FINISHED result for today
    $pending = null;   // a CLAIMED but unfinished attempt (flag ON only)
    try {
        // The row for the player's LOCAL day (falls back to the UTC day when no
        // valid ?local_date is supplied). Read-only + own-row-only, so an
        // out-of-range date just falls back rather than erroring the page.
        $playDate = lg_gdle_local_date($_GET['local_date'] ?? null) ?? gmdate('Y-m-d');
        $st = lg_comments_pdo()->prepare(
            'SELECT phrase_id, won, moves, streak, hardcore, resume_state
             FROM guitardle_results
             WHERE wp_user_id = ? AND play_date = ?::date');
        $st->execute([$uid, $playDate]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        // moves IS NULL means the allowance was CLAIMED but the game was never
        // finished. Such a row must never read as "today is done" -- under the
        // flag it is a resume, and with the flag OFF it is not this code's
        // business at all (see the rollback note in the migration).
        if ($row !== null && $row['moves'] === null) {
            if (LG_GUITARDLE_DAILY_CLAIM) {
                $decoded = json_decode((string) ($row['resume_state'] ?? ''), true);
                $pending = [
                    'phrase_id' => (int) $row['phrase_id'],
                    'hardcore'  => (bool) $row['hardcore'],
                    'state'     => is_array($decoded) ? $decoded : null,
                ];
            }
            $row = null;
        }
        if ($row !== null) {
            // phrase_id lets the client confirm the recorded row is for the
            // puzzle on screen before locking — play_date is the server's UTC
            // day, which can be a day off the player's local day (see game.js
            // init server-lock). Without it a west-of-UTC member sees an
            // unplayed phrase revealed the morning after an evening play.
            $today = ['phrase_id' => (int) $row['phrase_id'],
                      'won' => (bool) $row['won'], 'moves' => (int) $row['moves'],
                      'streak' => (int) $row['streak']];
        }
    } catch (Throwable $e) {
        error_log('[lg-guitardle] GET: ' . $e->getMessage());
    }
    $out = [
        'authenticated' => true,
        'wp_user_id'    => $uid,
        'nonce'         => wp_create_nonce('lg_guitardle_score'),
        'today'         => $today,
    ];
    if (LG_GUITARDLE_DAILY_CLAIM) {
        $out['claim']   = true;      // the client only takes the new path on this
        $out['pending'] = $pending;
    }
    lg_gdle_json($out);
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

// ---- Claim actions (backlog 22, flag-gated) ---------------------------------
// 'start' takes the day's allowance the moment the first move is made; 'save'
// keeps the mid-game position server-side so another device RESUMES rather than
// being told the day is gone. Both sit behind the CSRF check above. With the
// flag OFF neither exists, and no client asks for them -- the GET never
// advertises `claim`.
$action = isset($body['action']) ? (string) $body['action'] : 'finish';
if ($action === 'start' || $action === 'save') {
    if (!LG_GUITARDLE_DAILY_CLAIM) {
        lg_gdle_json(['ok' => false, 'error' => 'not_enabled'], 409);
    }
    $claimPhrase = isset($body['phrase_id']) ? (int) $body['phrase_id'] : 0;
    if ($claimPhrase < 0 || $claimPhrase > 100000) {
        lg_gdle_json(['ok' => false, 'error' => 'bad_request'], 400);
    }
    $claimDate = lg_gdle_post_play_date($body['local_date'] ?? null);

    try {
        if ($action === 'start') {
            // THE WHOLE FIX IS THIS INSERT. The existing UNIQUE
            // (wp_user_id, play_date) makes it the one daily allowance: a second
            // device claiming the same day hits ON CONFLICT DO NOTHING and is
            // told to resume, not handed a fresh board. No new constraint.
            $st = lg_comments_pdo()->prepare(
                'INSERT INTO guitardle_results
                    (wp_user_id, play_date, phrase_id, hardcore, claimed_at)
                 VALUES (?, ?::date, ?, ?, now())
                 ON CONFLICT (wp_user_id, play_date) DO NOTHING');
            $st->execute([$uid, $claimDate, $claimPhrase,
                          !empty($body['hardcore']) ? 'true' : 'false']);
            lg_gdle_json(['ok' => true, 'claimed' => $st->rowCount() > 0]);
        }

        // 'save' -- only ever touches an UNFINISHED row, so a finished result
        // can never be reopened or overwritten by a late snapshot from a stale
        // tab. Bounded so a client cannot use the column as free storage.
        $stateRaw = json_encode($body['state'] ?? null,
                                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($stateRaw) || strlen($stateRaw) > 4096) {
            lg_gdle_json(['ok' => false, 'error' => 'bad_request'], 400);
        }
        $st = lg_comments_pdo()->prepare(
            'UPDATE guitardle_results
                SET resume_state = ?::jsonb, hardcore = ?
              WHERE wp_user_id = ? AND play_date = ?::date AND moves IS NULL');
        $st->execute([$stateRaw, !empty($body['hardcore']) ? 'true' : 'false',
                      $uid, $claimDate]);
        lg_gdle_json(['ok' => true, 'saved' => $st->rowCount() > 0]);
    } catch (Throwable $e) {
        error_log('[lg-guitardle] ' . $action . ': ' . $e->getMessage());
        lg_gdle_json(['ok' => false, 'error' => 'server_error'], 500);
    }
}

$phraseId = isset($body['phrase_id']) ? (int) $body['phrase_id'] : 0;
$won      = !empty($body['won']);
$moves    = isset($body['moves']) ? (int) $body['moves'] : 0;
$streak   = isset($body['streak']) ? max(0, (int) $body['streak']) : 0;
$hardcore = !empty($body['hardcore']);
if ($moves < 1 || $moves > 99 || $phraseId < 0 || $phraseId > 100000) {
    lg_gdle_json(['ok' => false, 'error' => 'bad_request'], 400);
}

// Effective day = the player's LOCAL calendar day.
$playDate = lg_gdle_post_play_date($body['local_date'] ?? null);

// ---- Record (first FINISHED result of the day wins) -------------------------
try {
    $recorded = false;
    if (LG_GUITARDLE_DAILY_CLAIM) {
        // Fill the claim row taken at the first move -- but ONLY while its
        // result is still NULL. That single predicate is what preserves the old
        // guarantee: the first result to FINISH wins, and a second device that
        // somehow finished too cannot overwrite it.
        $up = lg_comments_pdo()->prepare(
            'UPDATE guitardle_results
                SET won = ?, moves = ?, streak = ?, hardcore = ?, phrase_id = ?,
                    resume_state = NULL
              WHERE wp_user_id = ? AND play_date = ?::date AND moves IS NULL');
        $up->execute([$won ? 'true' : 'false', $moves, $streak,
                      $hardcore ? 'true' : 'false', $phraseId, $uid, $playDate]);
        $recorded = $up->rowCount() > 0;
    }
    if (!$recorded) {
        // No claim row to fill: either the flag is OFF (the original path,
        // unchanged), or the player began before it came on / the start POST was
        // lost. The unique constraint still protects this insert either way.
        $st = lg_comments_pdo()->prepare(
            'INSERT INTO guitardle_results (wp_user_id, play_date, phrase_id, won, moves, streak, hardcore)
             VALUES (?, ?::date, ?, ?, ?, ?, ?)
             ON CONFLICT (wp_user_id, play_date) DO NOTHING');
        $st->execute([$uid, $playDate, $phraseId, $won ? 'true' : 'false', $moves, $streak, $hardcore ? 'true' : 'false']);
        $recorded = $st->rowCount() > 0;
    }
} catch (Throwable $e) {
    error_log('[lg-guitardle] ' . $e->getMessage());
    lg_gdle_json(['ok' => false, 'error' => 'server_error'], 500);
}

lg_gdle_json(['ok' => true, 'recorded' => $recorded]);
