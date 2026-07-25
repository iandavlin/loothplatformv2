<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Db;

/**
 * @mention RESOLVER — the INGEST-side counterpart to mention-suggest.php.
 *
 *   GET /profile-api/v0/mention-resolve?slugs=<csv>&uuids=<csv>
 *       200 { by_slug: { "<lower-slug>": {uuid, slug, wp_user_id|null}, … },
 *             by_uuid: { "<uuid>":       {uuid, slug, wp_user_id|null}, … } }
 *
 * The write side (bb-mirror reply ingest) hands us the raw @handles a member typed
 * (and the uuids the composer autocomplete inserted) and gets back the STABLE identity
 * it mints into stored content: the immutable uuid (→ data-lg-uuid) and the WP user id
 * (→ the legacy `{{mention_user_id_N}}` href that keeps the BuddyBoss page rendering).
 *
 * WHY a separate endpoint from users.php: that one is LOCKED DOWN and shaped for READ
 * surfaces — it never returns wp_user_id on a uuid lookup and has no slug lookup at all.
 * This is the WRITE side's exact need, slug→(uuid, wp_id), and it is the ONE thing the
 * ingest must know. WHY loopback-only: like users.php this is an identity oracle; only
 * our own server-side ingest calls it, over loopback. A browser gets 403 — there is no
 * reason a client ever resolves a handle to a wp_user_id.
 *
 * WHY it resolves against users.slug (not the WP nicename): the slug is the handle the
 * member controls. A member who renamed their handle has a slug that no longer matches
 * their WP nicename, so BuddyBoss's own @nicename parser would fail to recognise
 * `@new-handle`. Resolving here against the identity store is precisely what lets a
 * freshly-typed mention of a renamed member mint correctly.
 *
 * Visibility: archived members are excluded (a dead mention). Private profiles ARE
 * resolved — the render side masks them to plain text, and a private member should still
 * be reachable by a mention (it can go live if they un-private, and it can ring their bell).
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    profile_app_json(405, ['error' => 'method_not_allowed']);
}

// Loopback-only. Unlike mention-suggest (members-only), this returns wp_user_id, which no
// browser has any business resolving — so it is server-side consumers only, full stop.
$internal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if (!$internal) {
    profile_app_json(403, ['error' => 'forbidden']);
}

$parseCsv = static function (string $key): array {
    $raw = $_GET[$key] ?? '';
    if (!is_string($raw) || $raw === '') return [];
    $out = [];
    foreach (explode(',', $raw) as $v) {
        $v = trim($v);
        if ($v !== '') $out[$v] = true;      // dedup
    }
    return array_keys($out);
};

// slugs: lower-case, restrict to the slug charset, cap at 100.
$slugs = [];
foreach ($parseCsv('slugs') as $s) {
    $s = mb_strtolower($s);
    if (preg_match('/^[a-z0-9][a-z0-9._-]{0,59}$/', $s)) $slugs[$s] = true;
}
$slugs = array_slice(array_keys($slugs), 0, 100);

// uuids: canonical v4-shape, lower-case, cap at 100.
$uuids = [];
foreach ($parseCsv('uuids') as $u) {
    $u = strtolower($u);
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $u)) $uuids[$u] = true;
}
$uuids = array_slice(array_keys($uuids), 0, 100);

if (!$slugs && !$uuids) {
    profile_app_json(400, ['error' => 'slugs_or_uuids_required']);
}

$shape = static function (array $r): array {
    return [
        'uuid'        => (string) $r['uuid'],
        'slug'        => (string) $r['slug'],
        'wp_user_id'  => $r['wp_user_id'] !== null ? (int) $r['wp_user_id'] : null,
    ];
};

$bySlug = [];
$byUuid = [];

if ($slugs) {
    $ph = implode(',', array_fill(0, count($slugs), '?'));
    // LEFT JOIN: a native-only member (no WP bridge row) still resolves — wp_user_id is
    // then null and the ingest stores data-lg-uuid without the legacy href.
    $st = Db::pg()->prepare("
        SELECT u.uuid, u.slug, b.wp_user_id
        FROM users u
        LEFT JOIN wp_user_bridge b ON b.user_id = u.id
        WHERE lower(u.slug) IN ($ph) AND u.archived_at IS NULL
    ");
    $st->execute($slugs);
    while ($r = $st->fetch()) {
        $bySlug[mb_strtolower((string) $r['slug'])] = $shape($r);
    }
}

if ($uuids) {
    $ph = implode(',', array_fill(0, count($uuids), '?'));
    $st = Db::pg()->prepare("
        SELECT u.uuid, u.slug, b.wp_user_id
        FROM users u
        LEFT JOIN wp_user_bridge b ON b.user_id = u.id
        WHERE u.uuid IN ($ph) AND u.archived_at IS NULL
    ");
    $st->execute($uuids);
    while ($r = $st->fetch()) {
        $byUuid[strtolower((string) $r['uuid'])] = $shape($r);
    }
}

profile_app_json(200, ['by_slug' => (object) $bySlug, 'by_uuid' => (object) $byUuid]);
