<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Block.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Profile;
use Looth\ProfileApp\Block;

/** Great-circle miles — distance is computed from the DISPLAYED (precision-coarsened) point so it never leaks precision. */
function dir_haversine_mi(float $la1, float $lo1, float $la2, float $lo2): float
{
    $r = 3958.8;
    $dLa = deg2rad($la2 - $la1);
    $dLo = deg2rad($lo2 - $lo1);
    $a = sin($dLa / 2) ** 2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dLo / 2) ** 2;
    return $r * 2 * asin(min(1.0, sqrt($a)));
}

/**
 * Coarsen a member row to the point/text the viewer is allowed to see.
 * Single source of truth shared by the paginated list and the map-pin feed,
 * so a pin can never expose more precision than the card does. Returns the
 * display array (lat/lng/text/zoom/kind) or null when private for this audience.
 */
function dir_member_display(array $r, int $viewerUserId, bool $isAdmin, string $audience): ?array
{
    // If the owner removed the Location block from their profile (it's in the caddy, not on the
    // layout), they've opted off the map entirely — private for everyone, admin included.
    if (empty($r['loc_on_profile'])) return null;
    $subjectId = (int)$r['id'];
    if ($subjectId === $viewerUserId) {
        $precision = 'street';                                          // owner sees self exactly
    } elseif ($isAdmin) {
        // Admin oversight: exact pin for every member, UNLESS they made it private to members.
        $mp = Block::precisionFromInput($r['location_members_precision']) ?? 'city';
        $precision = $mp === 'private' ? 'private' : 'street';
    } else {
        $raw = $audience === 'members' ? $r['location_members_precision'] : $r['location_public_precision'];
        $precision = Block::precisionFromInput($raw) ?? 'city';   // default precision is now city for both audiences
    }
    $place = [
        'address'  => $r['location_address'],
        'postcode' => $r['location_postcode'],
        'city'     => $r['location_city'],
        'region'   => $r['location_region'],
        'country'  => $r['location_country'],
        'lat'      => $r['lat'] !== null ? (float)$r['lat'] : null,
        'lng'      => $r['lng'] !== null ? (float)$r['lng'] : null,
        'text'     => $r['location_text'],
    ];
    return Block::locationDisplay($place, $precision);          // null when private for this audience
}

/**
 * The drop-off points a viewer may see for a member, as map-pin kids [{lat,lng,name}].
 * Honors the drop-off block's own visibility (owner-self/admin always; else
 * public->public-only, members->members+public). NOT coarsened — these are storefront
 * /partner addresses the owner deliberately published. Empty array when none visible.
 */
function dir_visible_dropoffs(?array $do, array $r, int $viewerUserId, bool $isAdmin, string $audience): array
{
    if (!$do) return [];
    $dvis   = in_array($do['vis'], ['public', 'members', 'private'], true) ? $do['vis'] : 'members';
    $canSee = ((int)$r['id'] === $viewerUserId) || $isAdmin
              || $dvis === 'public'
              || ($dvis === 'members' && $audience === 'members');
    if (!$canSee) return [];
    $dd = json_decode($do['data'], true) ?: [];
    $kids = [];
    foreach (($dd['items'] ?? []) as $it) {
        if (!is_array($it) || !isset($it['lat'], $it['lng'])
            || !is_numeric($it['lat']) || !is_numeric($it['lng'])) continue;
        $kids[] = ['lat' => (float)$it['lat'], 'lng' => (float)$it['lng'], 'name' => (string)($it['name'] ?? '')];
    }
    return $kids;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') profile_app_json(405, ['error' => 'method_not_allowed']);

$viewer       = Auth::currentUser();
$viewerUserId = $viewer ? (int)$viewer['id'] : 0;
$role         = $viewer ? 'member' : 'public';

$lat    = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$lng    = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
$radius = isset($_GET['radius']) ? max(1, min(500, (int)$_GET['radius'])) : 50;
$insts  = isset($_GET['inst'])  ? (array)$_GET['inst']  : [];
$skills = isset($_GET['skill']) ? (array)$_GET['skill'] : [];
$music  = isset($_GET['music']) ? (array)$_GET['music'] : [];
$creds  = isset($_GET['cred'])  ? (array)$_GET['cred']  : [];
$page   = max(1, (int)($_GET['page'] ?? 1));
$pageSize = isset($_GET['page_size']) ? max(1, min(200, (int)$_GET['page_size'])) : 20;
$offset   = ($page - 1) * $pageSize;
$sort   = ($_GET['sort'] ?? 'joined_asc') === 'joined_desc' ? 'joined_desc' : 'joined_asc';

$pg = Db::pg();

$wheres = [
    'EXISTS (SELECT 1 FROM profiles p WHERE p.user_id = u.id)',
    'u.archived_at IS NULL',
];
$params = [];

if ($insts) {
    // Match the full list (profile_instruments) OR the featured highlights (profile_highlights),
    // since migrated profiles carry instruments as highlights. Distinct param names per subquery.
    $ph1 = []; $ph2 = [];
    foreach ($insts as $i => $s) {
        $ph1[] = ":i$i"; $ph2[] = ":ih$i";
        $params[":i$i"] = (string)$s; $params[":ih$i"] = (string)$s;
    }
    $wheres[] = "(EXISTS (SELECT 1 FROM profile_instruments pi
                          JOIN instrument_catalog ic ON ic.id = pi.instrument_id
                          WHERE pi.user_id = u.id AND ic.slug IN (" . implode(',', $ph1) . "))
              OR EXISTS (SELECT 1 FROM profile_highlights h
                          JOIN instrument_catalog ic ON ic.id = h.ref_id
                          WHERE h.user_id = u.id AND h.kind = 'instrument' AND ic.slug IN (" . implode(',', $ph2) . ")))";
}
if ($skills) {
    $ph1 = []; $ph2 = [];
    foreach ($skills as $i => $s) {
        $ph1[] = ":sk$i"; $ph2[] = ":skh$i";
        $params[":sk$i"] = (string)$s; $params[":skh$i"] = (string)$s;
    }
    $wheres[] = "(EXISTS (SELECT 1 FROM profile_skills ps
                          JOIN skill_catalog sc ON sc.id = ps.skill_id
                          WHERE ps.user_id = u.id AND sc.slug IN (" . implode(',', $ph1) . "))
              OR EXISTS (SELECT 1 FROM profile_highlights h
                          JOIN skill_catalog sc ON sc.id = h.ref_id
                          WHERE h.user_id = u.id AND h.kind = 'skill' AND sc.slug IN (" . implode(',', $ph2) . ")))";
}
if ($music) {
    $ph = [];
    foreach ($music as $i => $s) { $k = ":g$i"; $ph[] = $k; $params[$k] = (string)$s; }
    $wheres[] = "EXISTS (SELECT 1 FROM profile_genres pgn
                         JOIN genre_catalog gc ON gc.id = pgn.genre_id
                         WHERE pgn.user_id = u.id AND gc.slug IN (" . implode(',', $ph) . "))";
}
if ($creds) {
    $ph = [];
    foreach ($creds as $i => $s) { $k = ":cr$i"; $ph[] = $k; $params[$k] = (string)$s; }
    $wheres[] = "EXISTS (SELECT 1 FROM profile_credentials pc
                         JOIN credential_catalog cc ON cc.id = pc.catalog_id
                         WHERE pc.owner_type='profile' AND pc.owner_id = u.id AND cc.slug IN (" . implode(',', $ph) . "))";
}

$selectDistance = '';
// Default: oldest join date first. A location filter overrides with distance ASC (below).
$orderBy = $sort === 'joined_desc' ? 'u.created_at DESC, u.id DESC' : 'u.created_at ASC, u.id ASC';
if ($lat !== null && $lng !== null) {
    // earthdistance: point(lng, lat) <@> point(lng, lat) returns miles.
    // Geo-filter implicitly hides users we can't see location for (their
    // lat/lng never enter the query); that's correct — they're invisible
    // on the map but still surface in the un-filtered list.
    $selectDistance = ', (point(u.lng, u.lat) <@> point(:lng, :lat)) AS distance_mi';
    // Privacy: a user only appears on the map when their precision for THIS audience isn't 'private'
    // (both audiences now default to city; individuals can dial down to state/private).
    $wheres[] = '(u.lat IS NOT NULL AND u.lng IS NOT NULL AND (point(u.lng, u.lat) <@> point(:lng, :lat)) <= :radius
                  AND (u.profile_layout IS NULL OR u.profile_layout @> \'["location"]\'::jsonb)
                  AND (CASE WHEN :authed = 1 THEN COALESCE(u.location_members_precision, \'city\')
                                             ELSE COALESCE(u.location_public_precision, \'city\') END) <> \'private\')';
    $orderBy  = 'distance_mi ASC';
    $params[':lat'] = $lat; $params[':lng'] = $lng; $params[':radius'] = $radius;
    $params[':authed'] = $viewerUserId !== 0 ? 1 : 0;
}

// Viewer audience/oversight — computed once; shared by the pin feed and the list.
$audience = $viewerUserId !== 0 ? 'members' : 'public';
$isAdmin  = Auth::isAdmin();   // admins see every member at full precision unless they set it private

// Map-pin feed: coarsened coords for the ENTIRE filtered set (not just the current
// page), so all matching members plot. Slim payload — no highlights, no pagination.
// Same precision/visibility path as the list (dir_member_display), so a pin never
// leaks more than the card.
if (!empty($_GET['pins'])) {
    // Preload every drop-off block once (niche block -> small set), keyed by user_id,
    // so a member pin can carry its visible drop-off points as expandable children
    // ("collapsed pin" -> click to fan out the shop's drop-off locations).
    $dropoffsByUser = [];
    foreach ($pg->query("SELECT user_id, visibility, data FROM profile_sections WHERE key = 'dropoffs'")->fetchAll() as $dr) {
        $dropoffsByUser[(int)$dr['user_id']] = ['vis' => (string)$dr['visibility'], 'data' => (string)$dr['data']];
    }
    $pinSql = "SELECT u.id, u.display_name, u.slug,
                      u.location_text, u.location_address, u.location_city, u.location_region, u.location_country, u.location_postcode,
                      u.lat, u.lng, u.location_members_precision, u.location_public_precision,
                      (u.profile_layout IS NULL OR u.profile_layout @> '[\"location\"]'::jsonb) AS loc_on_profile
               FROM users u
               WHERE " . implode(' AND ', $wheres) . "
               LIMIT 5000";
    $pStmt = $pg->prepare($pinSql);
    $pStmt->execute($params);
    $pins = [];
    while ($pr = $pStmt->fetch()) {
        // Drop-off points this viewer may see (honors the drop-off block's visibility).
        $kids = dir_visible_dropoffs($dropoffsByUser[(int)$pr['id']] ?? null, $pr, $viewerUserId, $isAdmin, $audience);

        // Home pin at this viewer's precision; null when the Location block is off the
        // profile (opted off the map) or private for this audience.
        $disp = dir_member_display($pr, $viewerUserId, $isAdmin, $audience);
        if ($disp && $disp['lat'] !== null && $disp['lng'] !== null) {
            // Visible to this viewer — full card. Drop-offs fan out as children.
            $pin = [
                'slug'         => $pr['slug'] ?: (string)(int)$pr['id'],
                'display_name' => $pr['display_name'],
                'lat'          => (float)$disp['lat'],
                'lng'          => (float)$disp['lng'],
                'text'         => $disp['text'],
                'gated'        => false,
            ];
            if ($kids) $pin['dropoffs'] = $kids;
            $pins[] = $pin;
            continue;
        }

        // No visible home pin, but the member published drop-off locations — a shop using
        // ONLY the drop-off block still belongs on the map. Anchor the card at the first
        // drop-off; any others fan out as children (degrades to one clean pin for a single
        // drop-off). Drop-off visibility was already enforced above.
        if ($kids) {
            $anchor = $kids[0];
            $pin = [
                'slug'         => $pr['slug'] ?: (string)(int)$pr['id'],
                'display_name' => $pr['display_name'],
                'lat'          => $anchor['lat'],
                'lng'          => $anchor['lng'],
                'text'         => $anchor['name'],
                'gated'        => false,
            ];
            $rest = array_slice($kids, 1);
            if ($rest) $pin['dropoffs'] = $rest;
            $pins[] = $pin;
            continue;
        }

        // Truly hidden for this viewer. A member who removed the Location block AND has no
        // visible drop-offs stays off the map entirely. Otherwise: logged-out viewers get an
        // anonymized CITY pin (density without identity) + a "sign in" nudge; the message
        // reflects whether the member is members-only or fully private. Logged-in members
        // keep the member-precision behavior (no gated pins).
        if (empty($pr['loc_on_profile'])) continue;
        if ($audience !== 'public') continue;
        $gLat = Block::coarsen($pr['lat'] !== null ? (float)$pr['lat'] : null, 1);
        $gLng = Block::coarsen($pr['lng'] !== null ? (float)$pr['lng'] : null, 1);
        if ($gLat === null || $gLng === null) continue;
        $membersPrivate = (Block::precisionFromInput($pr['location_members_precision']) ?? 'city') === 'private';
        $pins[] = [
            'lat'     => (float)$gLat,
            'lng'     => (float)$gLng,
            'gated'   => true,
            'message' => $membersPrivate
                ? 'This member has their profile set to private.'
                : 'This member is only showing their profile to members.',
        ];
    }
    profile_app_json(200, ['pins' => $pins, 'total' => count($pins)]);
}

$sql = "SELECT u.id, u.uuid, u.display_name, u.avatar_url, u.banner_url,
               u.location_text, u.location_address, u.location_city, u.location_region, u.location_country, u.location_postcode,
               u.lat, u.lng, u.location_members_precision, u.location_public_precision, u.slug,
               (u.profile_layout IS NULL OR u.profile_layout @> '[\"location\"]'::jsonb) AS loc_on_profile
               $selectDistance
        FROM users u
        WHERE " . implode(' AND ', $wheres) . "
        ORDER BY $orderBy
        LIMIT $pageSize OFFSET $offset";
$stmt = $pg->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Total count (no distance — separate query). Reuses $wheres / $params so
// it matches the filtered result set.
$countSql = "SELECT COUNT(*) FROM users u WHERE " . implode(' AND ', $wheres);
$cStmt = $pg->prepare($countSql);
$cStmt->execute($params);
$total = (int)$cStmt->fetchColumn();

// Pull highlights for each result.
$results = [];
if ($rows) {
    $userIds = array_map(fn($r) => (int)$r['id'], $rows);
    $hPh = implode(',', array_fill(0, count($userIds), '?'));
    $hStmt = $pg->prepare("
        SELECT h.user_id, h.kind, h.ref_id, h.sort_order,
               CASE WHEN h.kind='instrument' THEN ic.slug ELSE sc.slug END AS slug,
               CASE WHEN h.kind='instrument' THEN ic.name ELSE sc.name END AS name
        FROM profile_highlights h
        LEFT JOIN instrument_catalog ic ON h.kind='instrument' AND ic.id = h.ref_id
        LEFT JOIN skill_catalog      sc ON h.kind='skill'      AND sc.id = h.ref_id
        WHERE h.user_id IN ($hPh) ORDER BY h.user_id, h.sort_order");
    $hStmt->execute($userIds);
    $highlightsByUser = [];
    while ($h = $hStmt->fetch()) {
        $highlightsByUser[(int)$h['user_id']][] = ['kind' => $h['kind'], 'slug' => $h['slug'], 'name' => $h['name']];
    }

    // Social links per result, gated by each member's links-block visibility
    // (owner-self/admin always; else public->public-only, members->members+public).
    $socVis = [];
    $svStmt = $pg->prepare("SELECT user_id, visibility FROM profile_sections WHERE key = 'socials' AND user_id IN ($hPh)");
    $svStmt->execute($userIds);
    while ($sv = $svStmt->fetch()) { $socVis[(int)$sv['user_id']] = (string)$sv['visibility']; }
    $linksByUser = [];
    $soStmt = $pg->prepare("SELECT user_id, kind, value FROM profile_socials WHERE user_id IN ($hPh) ORDER BY user_id, sort_order, id");
    $soStmt->execute($userIds);
    while ($so = $soStmt->fetch()) {
        $suid = (int)$so['user_id'];
        $svis = (isset($socVis[$suid]) && in_array($socVis[$suid], ['public', 'members', 'private'], true)) ? $socVis[$suid] : 'members';
        $canSee = ($suid === $viewerUserId) || $isAdmin || $svis === 'public' || ($svis === 'members' && $audience === 'members');
        if (!$canSee) continue;
        $val = trim((string)$so['value']);
        if ($val === '') continue;
        $linksByUser[$suid][] = ['kind' => (string)$so['kind'], 'value' => $val];
    }

    foreach ($rows as $r) {
        $subjectId = (int)$r['id'];
        // Per-audience precision (owner→street, admin→street unless private, else coarsened/hidden).
        $disp = dir_member_display($r, $viewerUserId, $isAdmin, $audience);
        $loc  = $disp
            ? ['text' => $disp['text'], 'lat' => $disp['lat'], 'lng' => $disp['lng'], 'zoom' => $disp['zoom'], 'kind' => $disp['kind']]
            : ['hidden' => true];

        // Distance from the DISPLAYED (coarsened) point, so it matches the pin's precision.
        $dist = null;
        if ($disp && $lat !== null && $lng !== null && $disp['lat'] !== null && $disp['lng'] !== null) {
            $dist = round(dir_haversine_mi((float)$lat, (float)$lng, (float)$disp['lat'], (float)$disp['lng']), 1);
        }

        $results[] = [
            'uuid'         => $r['uuid'],
            'slug'         => $r['slug'] ?: (string)$subjectId,
            'display_name' => $r['display_name'],
            'avatar_url'   => $r['avatar_url'],
            'banner_url'   => $r['banner_url'],
            'location'     => $loc,
            'highlights'   => $highlightsByUser[$subjectId] ?? [],
            'links'        => $linksByUser[$subjectId] ?? [],
            'distance_mi'  => $dist,
        ];
    }
}

// Viewer-relative connection state per member, for the directory Connect buttons.
// Logged-in only: anon viewers get NO connect field (so no button renders). One
// batched query over the symmetric connections table; own card => 'self'.
$viewerUuid = $viewer['uuid'] ?? null;
if ($viewerUuid && $results) {
    $otherUuids = array_values(array_filter(array_map(static fn($x) => $x['uuid'], $results)));
    $stateByUuid = [];
    if ($otherUuids) {
        $ph = implode(',', array_fill(0, count($otherUuids), '?'));
        $cq = $pg->prepare(
            "SELECT id, requester_uuid, addressee_uuid, status
               FROM connections
              WHERE (requester_uuid = ? AND addressee_uuid IN ($ph))
                 OR (addressee_uuid = ? AND requester_uuid IN ($ph))"
        );
        $cq->execute(array_merge([$viewerUuid], $otherUuids, [$viewerUuid], $otherUuids));
        while ($e = $cq->fetch()) {
            $other = $e['requester_uuid'] === $viewerUuid ? $e['addressee_uuid'] : $e['requester_uuid'];
            if ($e['status'] === 'accepted')    $stt = 'accepted';
            elseif ($e['status'] === 'blocked') $stt = 'blocked';
            else $stt = $e['requester_uuid'] === $viewerUuid ? 'pending_out' : 'pending_in';
            $stateByUuid[$other] = ['state' => $stt, 'id' => (int) $e['id']];
        }
    }
    foreach ($results as &$it) {
        $it['connect'] = ($it['uuid'] === $viewerUuid)
            ? ['state' => 'self', 'id' => null]
            : ($stateByUuid[$it['uuid']] ?? ['state' => 'none', 'id' => null]);
    }
    unset($it);
}

profile_app_json(200, [
    'total'     => $total,
    'page'      => $page,
    'page_size' => $pageSize,
    'has_more'  => ($offset + count($results)) < $total,
    'items'     => $results,
]);
