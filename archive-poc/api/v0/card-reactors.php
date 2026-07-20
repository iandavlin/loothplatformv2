<?php
/**
 * archive-poc/api/v0/card-reactors.php — WHO-REACTED read (GET) for ONE card.
 *
 * The read half of the who-reacted modal. Runs on the archive-poc FPM pool with NO
 * WordPress boot — like comments.php: it reads the SAME discovery.card_reactions
 * store the counts come from (lg_card_reactions_for_items / feed_reactions_bar SSR)
 * and resolves reactor identity LIVE from the profile_app DB, so renames + new
 * avatars follow. This is a pure READ — no nonce, no write, no second tally: each
 * returned group's user-count EQUALS that slug's rendered chip count (the
 * count-source contract).
 *
 *   GET ?post_type=<topic|reply|managed-cpt>&item_id=<id>
 *       → { ok, post_type, item_id,
 *           order:  [slug, …],                       // slugs present, count desc
 *           groups: { slug: { count:int, label:string,
 *                             users:[{name,slug,avatar_url}, …] } } }
 *
 * Visibility: reaction COUNTS are already public (server-rendered to anon in
 * _feed.php on the bb-mirror SELECT grant), so the reactor list is equally public —
 * gated only by the same dev cookie the sibling read endpoints sit behind (nginx).
 * The post-type whitelist mirrors card-react.php's LG_CARD_REACT_TYPES exactly.
 */

declare(strict_types=1);
require_once __DIR__ . '/_reactions.php';   // lg_card_reactions_users + palette (require_once _comments.php)

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Vary: Cookie');

function lg_krx_json($payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Same surfaceable post types as the write door (card-react.php). Keep in lockstep.
const LG_CARD_REACTORS_TYPES = ['post-imgcap','post-type-videos','sponsor-post','loothprint',
                                'loothcuts','useful_links','member-benefit','topic','reply'];

$postType = isset($_GET['post_type']) ? trim((string) $_GET['post_type']) : '';
$itemId   = isset($_GET['item_id']) ? (int) $_GET['item_id'] : 0;

if (!in_array($postType, LG_CARD_REACTORS_TYPES, true) || $itemId <= 0) {
    lg_krx_json(['ok' => false, 'error' => 'bad_request'], 400);
}

// Slug → human label, so the modal can title each group without re-deriving the
// palette client-side (the client renders the glyph from its own server-rendered
// picker; the label rides along for the header/tooltip).
$labels = [];
if (function_exists('lg_reactions_palette')) {
    foreach (lg_reactions_palette() as $rx) {
        $labels[(string) $rx['slug']] = (string) ($rx['label'] ?? $rx['slug']);
    }
}

try {
    $res = lg_card_reactions_users(lg_comments_pdo(), $postType, $itemId);
} catch (Throwable $e) {
    error_log('[lg-card-reactors] ' . $e->getMessage());
    lg_krx_json(['ok' => false, 'error' => 'server_error'], 500);
}

$groups = [];
foreach ($res['groups'] as $slug => $g) {
    $groups[$slug] = [
        'count' => (int) $g['count'],
        'label' => $labels[$slug] ?? $slug,
        'users' => $g['users'],
    ];
}

lg_krx_json([
    'ok'        => true,
    'post_type' => $postType,
    'item_id'   => $itemId,
    'order'     => $res['order'],
    'groups'    => (object) $groups,   // {} not [] when empty
]);
