<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Db;
use Looth\ProfileApp\Visibility;

/**
 * @mention autocomplete.
 *
 *   GET /profile-api/v0/mention-suggest?q=<text>
 *       200 { items: [ {uuid, slug, display_name, avatar_url}, … ] }   (max 10)
 *       q may span spaces ("doug spec") — tokens AND across slug/display_name.
 *
 * WHY THIS LIVES HERE and not in bb-mirror's existing /hub/?suggest=author:
 * that endpoint reads `forums.person`, which is a CACHE of the WP user_nicename. The
 * nicename is NOT the handle a member controls — the handle is profile_app.users.slug.
 * Suggesting from the cache would hand the composer a stale name and re-create the exact
 * bug this lane exists to fix. Mention suggestions must come from the identity store, live.
 *
 * MATCHES on slug and display_name — a member types "@mark" and finds "markus" whether
 * they are thinking of the handle or the human. Prefix matches rank above substring ones,
 * so "@mar" offers "markus" before "guitars-by-mar".
 *
 * VISIBILITY: private profiles are never suggested (their /u/ page 404s — offering them
 * would be a dead mention and an identity leak). Members-only is fine: this endpoint is
 * members-only itself. Archived members are excluded.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    profile_app_json(405, ['error' => 'method_not_allowed']);
}

// Members only. Same reasoning as api/v0/users.php: an open handle→identity endpoint is a
// scrapeable member directory. Loopback (our own server-side consumers) is exempt.
$viewer   = Visibility::viewer();
$internal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if (!$internal && (int) ($viewer['id'] ?? 0) === 0) {
    profile_app_json(401, ['error' => 'auth_required']);
}

$q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$q = ltrim($q, '@');                       // the composer may send the '@' along
if (mb_strlen($q) < 2) profile_app_json(200, ['items' => [], 'q' => $q]);
if (mb_strlen($q) > 40) $q = mb_substr($q, 0, 40);

// MULTI-WORD (Ian 2026-07-25, FB-style): the query may span spaces — split on
// whitespace (cap 3 tokens) and AND the tokens, each matching slug OR display_name,
// so "doug spec" finds "Doug Proper - Guitar Specialist" non-contiguously. A single
// word keeps the original semantics exactly.
$tokens = array_values(array_filter(preg_split('/\s+/u', mb_strtolower($q)) ?: [], 'strlen'));
$tokens = array_slice($tokens, 0, 3);
if (!$tokens) profile_app_json(200, ['items' => [], 'q' => $q]);

// Escape LIKE wildcards so a member typing "%" doesn't match everyone. The escape
// char is '!' — NOT backslash: a '\' ESCAPE literal makes PDO's placeholder parser
// read \' as an escaped quote, swallowing the next :placeholder into the "string"
// (HY093 invalid parameter number at execute).
$like = fn(string $s): string => str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);

$params = [];
$where  = [];
foreach ($tokens as $i => $tok) {
    $where[] = "(lower(slug) LIKE :mid{$i}s ESCAPE '!' OR lower(display_name) LIKE :mid{$i}n ESCAPE '!')";
    $params[":mid{$i}s"] = $params[":mid{$i}n"] = '%' . $like($tok) . '%';
}

// SCRUNCHED match (Ian 2026-07-25, caught live: q=dougproper returned 0): normalize
// BOTH sides to bare [a-z0-9] so a glued query matches across spaces/hyphens in the
// name AND the slug ("dougproper" ~ "Doug Proper - …"; "theguitarspec" ~
// "the-guitar-specialist"). OR-branch beside the token match; scrunch-only hits rank
// BELOW the prefix/substring tiers (rank tier added below). The scrunched value is
// alnum-only, so no LIKE wildcards can survive in it.
$scr = preg_replace('/[^a-z0-9]/', '', mb_strtolower($q)) ?? '';
$scrunchExpr = "regexp_replace(lower(coalesce(display_name,'')), '[^a-z0-9]', '', 'g')";
$scrunchSlug = "regexp_replace(lower(slug), '[^a-z0-9]', '', 'g')";
$hasScr = mb_strlen($scr) >= 3;
if ($hasScr) {
    $params[':scrn'] = $params[':scrs'] = '%' . $scr . '%';
}

if (count($tokens) === 1) {
    // single word: slug-prefix > name-prefix > substring (unchanged behavior)
    $rank = "CASE WHEN lower(slug) LIKE :pre1 ESCAPE '!' THEN 0
                  WHEN lower(display_name) LIKE :pre2 ESCAPE '!' THEN 1
                  WHEN lower(slug) LIKE :mid0s2 ESCAPE '!' OR lower(display_name) LIKE :mid0n2 ESCAPE '!' THEN 2
                  ELSE 3 END";   // 3 = scrunch-only hit, below every plain tier
    $params[':pre1'] = $params[':pre2'] = $like($tokens[0]) . '%';
    $params[':mid0s2'] = $params[':mid0n2'] = '%' . $like($tokens[0]) . '%';
} else {
    // multi word: best rank when EVERY token prefixes a word of the display name
    // ("doug spec" → "Doug … Specialist"); space-boundary approximation is fine here.
    $conds = [];
    $plain = [];
    foreach ($tokens as $i => $tok) {
        $conds[] = "(lower(display_name) LIKE :wp{$i}a ESCAPE '!' OR lower(display_name) LIKE :wp{$i}b ESCAPE '!')";
        $params[":wp{$i}a"] = $like($tok) . '%';
        $params[":wp{$i}b"] = '% ' . $like($tok) . '%';
        $plain[] = "(lower(slug) LIKE :pm{$i}s ESCAPE '!' OR lower(display_name) LIKE :pm{$i}n ESCAPE '!')";
        $params[":pm{$i}s"] = $params[":pm{$i}n"] = '%' . $like($tok) . '%';
    }
    $rank = 'CASE WHEN ' . implode(' AND ', $conds) . ' THEN 0 '
          . 'WHEN ' . implode(' AND ', $plain) . ' THEN 1 ELSE 2 END';   // 2 = scrunch-only
}

// Ranking fix (Ian 2026-07-25, live evidence: q=doug returned 8/8 patreon_* rows and
// buried the real member): within a rank, (a) MACHINE slugs (patreon_<id> import
// artifacts) sort LAST — a junk handle should never outrank a human; (b) the old
// length(slug) tiebreak is replaced by MATCH POSITION (earlier hit in name/slug wins)
// then alphabetical display name — a short machine slug no longer length-beats a
// member whose human slug happens to be long. LIMIT raised 8 → 10.
$params[':pos1'] = $params[':pos2'] = $tokens[0];
$st = Db::pg()->prepare("
    SELECT uuid, slug, display_name, avatar_url,
           {$rank} AS rank,
           (slug ~* '^patreon[_-]?[0-9]+\$')::int AS machine,
           LEAST(coalesce(nullif(position(:pos1 in lower(display_name)), 0), 999),
                 coalesce(nullif(position(:pos2 in lower(slug)), 0), 999)) AS mpos
    FROM users
    WHERE archived_at IS NULL
      AND slug IS NOT NULL
      AND profile_visibility <> 'private'
      AND ((" . implode("\n      AND ", $where) . ")" . ($hasScr ? "
           OR ({$scrunchExpr} LIKE :scrn ESCAPE '!' OR {$scrunchSlug} LIKE :scrs ESCAPE '!')" : '') . ")
    ORDER BY rank, machine, mpos, lower(display_name), lower(slug)
    LIMIT 10
");
$st->execute($params);

$items = [];
while ($r = $st->fetch()) {
    $items[] = [
        'uuid'         => (string) $r['uuid'],
        'slug'         => (string) $r['slug'],
        'display_name' => $r['display_name'] !== null ? (string) $r['display_name'] : null,
        'avatar_url'   => $r['avatar_url']   !== null ? (string) $r['avatar_url']   : null,
    ];
}

profile_app_json(200, ['items' => $items, 'q' => $q, 'count' => count($items)]);
