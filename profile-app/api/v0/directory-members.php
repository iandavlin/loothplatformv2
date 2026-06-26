<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Block.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Profile;
use Looth\ProfileApp\Block;
use Looth\ProfileApp\Visibility;

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
 * Shared by the paginated list and the map-pin feed, so a pin can never expose
 * more precision than the card does. The DECISION (master switch, layout flag,
 * audience dials, admin rule, public-never-out-resolves-members) lives in
 * Visibility::locationPrecision — only the coarsening math stays here. Returns
 * the display array (lat/lng/text/zoom/kind) or null when private for this viewer.
 */
function dir_member_display(array $r, array $vArr): ?array
{
    $precision = Visibility::locationPrecision($vArr, $r);
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
 * Honors the drop-off block's own visibility via the one truth table. NOT
 * coarsened — these are storefront/partner addresses the owner deliberately
 * published. Empty array when none visible.
 */
function dir_visible_dropoffs(?array $do, array $r, array $vArr): array
{
    if (!$do) return [];
    if (!Visibility::profileVisible($vArr, $r)) return [];
    $dvis = in_array($do['vis'], ['public', 'members', 'private'], true) ? $do['vis'] : 'members';
    if (!Visibility::audienceCanSee(Visibility::audience($vArr, (int)$r['id']), $dvis)) return [];
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

// PUBLIC LUTHIER FINDER (Ian 2026-06-12, supersedes the 6/11 login wall):
// logged-out visitors ARE the finder's audience — customers looking for a
// luthier don't have accounts. Anonymous requests get the PUBLIC-audience
// view: only what each member's location_public_precision allows (city
// default, 'private' = invisible), names + slugs are public-profile data.
// What the 6/11 lockdown was actually protecting against is handled
// surgically instead: anon payloads carry NO uuid (the /profile-media file
// key) and no connection state; file-level auth on gated media remains the
// profile-app follow-up.
$viewer       = Auth::currentUser();   // null = anonymous (public audience)
$vArr         = Visibility::viewer();  // the one viewer struct (id / uuid / admin)
$viewerUserId = $vArr['id'];
$isAdmin      = $vArr['admin'];        // admins see every member at full precision unless dialed members-private
$audience     = $viewerUserId !== 0 ? 'members' : 'public';

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
// Sort whitelist (mapless card mode adds A–Z/Z–A, nearest-first, and most/least
// recently online). 'distance_asc' only ranks when a location is set; the two
// 'online_*' modes need the OPTIONAL users.last_seen_at column (detected below)
// and fall back to join-date until that dependency lands — never a faked time.
$sortOpts = ['joined_asc', 'joined_desc', 'name_asc', 'name_desc', 'distance_asc', 'online_desc', 'online_asc'];
$sort   = in_array(($_GET['sort'] ?? ''), $sortOpts, true) ? (string)$_GET['sort'] : 'joined_asc';
// Single-member fetch: the map pin popup lazy-loads ONE member's full card by slug
// (Ian 6/15). Additive — constrains the list query to that slug; every existing
// visibility/precision guard below still applies, so a slug fetch can never expose
// more than the pin/card already does (a private or non-opt-in slug returns nothing).
$slug   = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';
if ($slug !== '') { $page = 1; $offset = 0; $pageSize = 1; }

$pg = Db::pg();

// "Most recently online" backing column is an OPTIONAL dependency: populated from
// BuddyPress wp_usermeta.last_activity via the wp_user_bridge (see the
// directory-mapless handoff). Detect once so online_* sorts degrade to join-date
// until the column + sync land — the endpoint never fabricates a presence time.
$hasLastSeen = (bool)$pg->query(
    "SELECT EXISTS (SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'users' AND column_name = 'last_seen_at')"
)->fetchColumn();

$wheres = [
    'EXISTS (SELECT 1 FROM profiles p WHERE p.user_id = u.id)',
    'u.archived_at IS NULL',
    // MASTER SWITCH (Visibility model): a private profile is owner-only —
    // no card, no pin, no teaser dot, for members too; admins excepted.
    "(u.profile_visibility = 'public' OR u.id = :vuid OR :vadmin = 1)",
];
$params = [':vuid' => $viewerUserId, ':vadmin' => $isAdmin ? 1 : 0];

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
// Base ordering from the sort whitelist. 'distance_asc' is resolved inside the
// location block below (it needs the computed distance column); online_* fall
// back to join-date when last_seen_at is absent.
switch ($sort) {
    case 'joined_desc': $orderBy = 'u.created_at DESC, u.id DESC'; break;
    case 'name_asc':    $orderBy = 'lower(u.display_name) ASC, u.id ASC'; break;
    case 'name_desc':   $orderBy = 'lower(u.display_name) DESC, u.id DESC'; break;
    case 'online_desc': $orderBy = $hasLastSeen ? 'u.last_seen_at DESC NULLS LAST, u.id DESC' : 'u.created_at DESC, u.id DESC'; break;
    case 'online_asc':  $orderBy = $hasLastSeen ? 'u.last_seen_at ASC  NULLS LAST, u.id ASC'  : 'u.created_at ASC,  u.id ASC';  break;
    default:            $orderBy = 'u.created_at ASC, u.id ASC';   // joined_asc + distance_asc (pre-location)
}
if ($lat !== null && $lng !== null) {
    // earthdistance: point(lng, lat) <@> point(lng, lat) returns miles.
    //
    // TRILATERATION GUARD (Ian 6/12 "as secure as possible"): the radius test
    // and the ranked distance run on the COARSENED point — the same precision
    // the viewer's pin/card displays — never on true coordinates. Probing
    // radii from shifted centers can therefore never resolve a member beyond
    // what they chose to show this audience. Admins keep true coordinates.
    if ($isAdmin) {
        $pt = 'point(u.lng, u.lat)';
    } elseif ($audience === 'public') {
        // Opt-ins at their public dial; non-opt-ins ('private') only ever
        // reach the PIN feed as anonymous dots — test those at the dot's own
        // ~11km rounding.
        $pt = "point(
            CASE COALESCE(u.location_public_precision, 'private')
                 WHEN 'street'  THEN u.lng
                 WHEN 'state'   THEN round(u.lng::numeric, 0)::float8
                 WHEN 'private' THEN round(u.lng::numeric, 1)::float8
                 ELSE                round(u.lng::numeric, 2)::float8 END,
            CASE COALESCE(u.location_public_precision, 'private')
                 WHEN 'street'  THEN u.lat
                 WHEN 'state'   THEN round(u.lat::numeric, 0)::float8
                 WHEN 'private' THEN round(u.lat::numeric, 1)::float8
                 ELSE                round(u.lat::numeric, 2)::float8 END)";
    } else {
        $pt = "point(
            CASE COALESCE(u.location_members_precision, 'city')
                 WHEN 'street' THEN u.lng
                 WHEN 'state'  THEN round(u.lng::numeric, 0)::float8
                 ELSE               round(u.lng::numeric, 2)::float8 END,
            CASE COALESCE(u.location_members_precision, 'city')
                 WHEN 'street' THEN u.lat
                 WHEN 'state'  THEN round(u.lat::numeric, 0)::float8
                 ELSE               round(u.lat::numeric, 2)::float8 END)";
    }
    $selectDistance = ", ($pt <@> point(:lng, :lat)) AS distance_mi";
    // A members-precision-'private' location is off the map for EVERYONE but
    // the owner — members, anon dots, and admins alike (ruling 4's standing
    // exception). The layout flag (Location block removed) is the same.
    $wheres[] = "(u.lat IS NOT NULL AND u.lng IS NOT NULL AND ($pt <@> point(:lng, :lat)) <= :radius
                  AND (u.profile_layout IS NULL OR u.profile_layout @> '[\"location\"]'::jsonb)
                  AND (u.id = :vuid OR COALESCE(u.location_members_precision, 'city') <> 'private'))";
    // "Near me" ranks by distance; any OTHER sort (A–Z, newest, online…) still
    // works WITHIN the radius filter, so a location search isn't forced to
    // distance order anymore.
    if ($sort === 'distance_asc') $orderBy = 'distance_mi ASC';
    $params[':lat'] = $lat; $params[':lng'] = $lng; $params[':radius'] = $radius;
}

// The STACK (paginated cards) shows only members visible to this audience:
// for anon that means public-finder opt-ins ("Public sees" dial ≠ private) —
// non-opt-ins appear ONLY as anonymous dots in the pin feed (Ian 6/12 pm:
// "show dots for anon, keep finder stack vis only"). The pin feed keeps the
// shared $wheres (no opt-in cut) so the dots still plot.
$listWheres = $wheres;
if ($audience === 'public' && !$isAdmin) {
    $listWheres[] = "COALESCE(u.location_public_precision, 'private') <> 'private'";
}
// Slug fetch rides on top of the full visibility stack above — it only ever narrows.
if ($slug !== '') {
    $listWheres[] = 'u.slug = :slug';
    $params[':slug'] = $slug;
}

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
    $pinSql = "SELECT u.id, u.display_name, u.slug, u.profile_visibility,
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
        $kids = dir_visible_dropoffs($dropoffsByUser[(int)$pr['id']] ?? null, $pr, $vArr);

        // Home pin at this viewer's precision; null when the Location block is off the
        // profile (opted off the map) or private for this audience.
        $disp = dir_member_display($pr, $vArr);
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
        // visible drop-offs stays off the map entirely. Logged-out viewers get an anonymous
        // coarse DOT (~11km rounding — density without identity) for members-only members.
        // Public never sees more than members: a members-precision-'private' location gets
        // no dot either, and the message never discloses WHICH setting the member chose.
        if (empty($pr['loc_on_profile'])) continue;
        if ($audience !== 'public' || $isAdmin) continue;
        if ((Block::precisionFromInput($pr['location_members_precision']) ?? 'city') === 'private') continue;
        $gLat = Block::coarsen($pr['lat'] !== null ? (float)$pr['lat'] : null, 1);
        $gLng = Block::coarsen($pr['lng'] !== null ? (float)$pr['lng'] : null, 1);
        if ($gLat === null || $gLng === null) continue;
        $pins[] = [
            'lat'     => (float)$gLat,
            'lng'     => (float)$gLng,
            'gated'   => true,
            'message' => 'This member is only visible to signed-in members.',
        ];
    }
    profile_app_json(200, ['pins' => $pins, 'total' => count($pins)]);
}

$sql = "SELECT u.id, u.uuid, u.display_name, u.avatar_url, u.banner_url, u.header_lights, u.profile_visibility,
               u.location_text, u.location_address, u.location_city, u.location_region, u.location_country, u.location_postcode,
               u.lat, u.lng, u.location_members_precision, u.location_public_precision, u.slug,
               (u.profile_layout IS NULL OR u.profile_layout @> '[\"location\"]'::jsonb) AS loc_on_profile
               $selectDistance
        FROM users u
        WHERE " . implode(' AND ', $listWheres) . "
        ORDER BY $orderBy
        LIMIT $pageSize OFFSET $offset";
$stmt = $pg->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Total count (no distance — separate query). Reuses $listWheres / $params so
// it matches the visible stack, never the dot population.
$countSql = "SELECT COUNT(*) FROM users u WHERE " . implode(' AND ', $listWheres);
$cStmt = $pg->prepare($countSql);
$cStmt->execute($params);
$total = (int)$cStmt->fetchColumn();

// Card chips show each member's actual SKILLS (live), not the curated
// Highlights picker — so editing Skills updates the cards immediately
// (Ian 2026-06-16). Shape matches the old highlights rows (kind/slug/name)
// so the .hl-chips render at web/directory-members.php is unchanged; the
// render consumes h.name. Ordered like the editor (Profile::skills:
// ps.sort_order, s.sort_order, s.name) and capped to the highlights cap (3)
// so card height stays the same despite SKILLS_MAX=40.
$results = [];
if ($rows) {
    $userIds = array_map(fn($r) => (int)$r['id'], $rows);
    $hPh = implode(',', array_fill(0, count($userIds), '?'));
    $hStmt = $pg->prepare("
        SELECT ps.user_id, sc.slug, sc.name
        FROM profile_skills ps
        JOIN skill_catalog sc ON sc.id = ps.skill_id
        WHERE ps.user_id IN ($hPh)
        ORDER BY ps.user_id, ps.sort_order, sc.sort_order, sc.name");
    $hStmt->execute($userIds);
    $highlightsByUser = [];
    foreach ($userIds as $uid) { $skillCount[$uid] = 0; }
    while ($h = $hStmt->fetch()) {
        $suid = (int)$h['user_id'];
        if (($skillCount[$suid] ?? 0) >= Profile::HIGHLIGHTS_MAX) continue;
        $skillCount[$suid] = ($skillCount[$suid] ?? 0) + 1;
        $highlightsByUser[$suid][] = ['kind' => 'skill', 'slug' => $h['slug'], 'name' => $h['name']];
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
        // Contact links require LOGIN — never to anonymous viewers, even when the
        // socials block is 'public' (Ian 2026-06-11: "must be scrape proof"). Anon
        // gets zero links so emails/handles can't be bulk-harvested off the bulk
        // directory. Deliberately STRICTER than the per-profile rule (anon drops
        // even 'public' links here); within that, the module's truth table decides.
        $aud = Visibility::audience($vArr, $suid);
        if ($aud === 'public') continue;
        if (!Visibility::audienceCanSee($aud, $svis)) continue;
        // Contact PII (email/phone) NEVER ships in the BULK directory, even to
        // members (Ian 2026-06-11: "scrape proof"): one logged-in account would
        // otherwise harvest every member's email in ~4 paged calls. These live on
        // the individual rate-limited profile only. Social/web handles (discovery,
        // not bulk-PII) still ship to members per the visibility gate above.
        $kind = (string)$so['kind'];
        if (in_array($kind, ['email', 'phone', 'tel', 'whatsapp', 'sms'], true)) continue;
        $val = trim((string)$so['value']);
        if ($val === '') continue;
        $linksByUser[$suid][] = ['kind' => $kind, 'value' => $val];
    }

    foreach ($rows as $r) {
        $subjectId = (int)$r['id'];
        // Per-audience precision via the Visibility module (owner→street,
        // admin→street unless members-private, else dial-coarsened/hidden).
        $disp = dir_member_display($r, $vArr);
        $loc  = $disp
            ? ['text' => $disp['text'], 'lat' => $disp['lat'], 'lng' => $disp['lng'], 'zoom' => $disp['zoom'], 'kind' => $disp['kind']]
            : ['hidden' => true];

        // Distance from the DISPLAYED (coarsened) point, so it matches the pin's precision.
        $dist = null;
        if ($disp && $lat !== null && $lng !== null && $disp['lat'] !== null && $disp['lng'] !== null) {
            $dist = round(dir_haversine_mi((float)$lat, (float)$lng, (float)$disp['lat'], (float)$disp['lng']), 1);
        }

        // (Per-member anonymous teaser CARDS removed, Ian 6/12 pm: anon non-opt-ins
        // appear only as coarse dots in the pin feed — the stack is visible profiles only.)

        $results[] = [
            'uuid'         => $r['uuid'],
            'slug'         => $r['slug'] ?: (string)$subjectId,
            'display_name' => $r['display_name'],
            'avatar_url'   => $r['avatar_url'],
            'banner_url'   => $r['banner_url'],
            'location'     => $loc,
            'highlights'   => $highlightsByUser[$subjectId] ?? [],
            'links'        => $linksByUser[$subjectId] ?? [],
            // Header availability "status lights" (same widgets as the /u/ profile header),
            // resolved from the row we already have — no extra query per member.
            'lights'       => Block::mapHeaderLights($r['header_lights'] ?? null),
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

// Anonymous viewers never receive uuids — they're the key space for
// /profile-media file URLs (Buck 6/11 audit). Slugs (public profile links)
// are the anon identifier; the connect block above is already login-only.
if ($viewerUserId === 0) {
    foreach ($results as &$it) { unset($it['uuid']); }
    unset($it);
}

profile_app_json(200, [
    'total'     => $total,
    'page'      => $page,
    'page_size' => $pageSize,
    'has_more'  => ($offset + count($results)) < $total,
    'items'     => $results,
]);
