<?php
/**
 * bb-mirror/api/v0/author-activity.php — loopback-only: does this WP user
 * have any Hub activity (a published discussion or a tier-any content item)?
 *
 * GET /bb-mirror-api/v0/author-activity?wp_id=<int>
 *   → {"has_activity": bool, "count": int, "author_name": string|null}
 *
 * Backlog 27 (the profile archive-icon) / backlog 38's own trace. Keeper
 * 2026-08-16: the icon's visibility must be "THE SAME predicate the
 * destination uses — never a parallel count." Calls the ONE shared function
 * (hub_author_activity_count, _hub-filters.php) the author banner already
 * uses — not a second, independently-written query in a second language
 * against a second database — so the icon and the ?author= destination it
 * links to can never disagree about whether a member has posted.
 *
 * ALL THREE content tiers, not a viewer's: this answers "did they ever
 * author anything," not "can the CURRENT viewer see it" — tier-gating is a
 * viewer-relative concept and applies again, correctly, whenever someone
 * actually visits the destination.
 *
 * author_name resolves via forums.person (id = WP user id) — the same
 * identity table the Hub's own suggest/avatar joins already use — so the
 * name handed back is the SAME string ?author= would need to match.
 *
 * Loopback-only, mirroring _sync.php: this is a server-to-server call from
 * profile-app's u.php, never browser-callable. nginx pins the location to
 * 127.0.0.1/::1; this is a double-check, not the only gate.
 */

declare(strict_types=1);

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403); exit('not loopback');
}

require __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../web/forums/_hub-filters.php'; // hub_author_activity_count

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$wpId = (int)($_GET['wp_id'] ?? 0);
if ($wpId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'wp_id required']);
    return;
}

$db = bb_mirror_db();
$st = $db->prepare('SELECT display_name FROM forums.person WHERE id = :id LIMIT 1');
$st->bindValue(':id', $wpId, PDO::PARAM_INT);
$st->execute();
$name = $st->fetchColumn();

if ($name === false || $name === null || (string)$name === '') {
    // No person row — never authored anything the mirror has ever seen.
    echo json_encode(['has_activity' => false, 'count' => 0, 'author_name' => null]);
    return;
}

$count = hub_author_activity_count($db, (string)$name, ['public', 'lite', 'pro']);
echo json_encode(['has_activity' => $count > 0, 'count' => $count, 'author_name' => (string)$name]);
