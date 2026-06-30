<?php
declare(strict_types=1);
/**
 * /profile-api/v0/directory/pins-public — the ANONYMOUS member-map layer.
 *
 * The Strava-heatmap pattern (Ian 6/12): logged-out visitors see the REAL
 * spread of the community as aggregated, de-identified density — cluster
 * bubbles with counts — and identity/zoom detail is what logging in unlocks.
 *
 * Privacy contract (strictly coarser than anything already public):
 *   - NO names, slugs, UUIDs, or anything clickable-through. Payload is
 *     grid cells only: [lat, lng, count].
 *   - Coordinates rounded to 1 decimal (~11 km cells).
 *   - POPULATION = the same set the finder already shows anon as anonymous
 *     teaser DOTS (Ian 6/12 pm ruling): members-map members — location on
 *     the layout, members-precision not 'private', master switch not
 *     private. Aggregating that set into count cells is strictly LESS
 *     information than the finder's own per-member dots at the same
 *     rounding. (The original public-opt-in-only filter predated the dots
 *     ruling and left this layer near-empty — 2 cells vs 659 finder dots.)
 *
 * Cacheable: the aggregate changes slowly; 15 min public cache.
 */
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Db;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') profile_app_json(405, ['error' => 'method_not_allowed']);

$pg = Db::pg();
$rows = $pg->query("
    SELECT round(lat::numeric, 1) AS cell_lat,
           round(lng::numeric, 1) AS cell_lng,
           count(*)               AS n
      FROM users u
     WHERE u.archived_at IS NULL
       AND u.lat IS NOT NULL AND u.lng IS NOT NULL
       AND (u.profile_layout IS NULL OR u.profile_layout @> '[\"location\"]'::jsonb)
       AND u.profile_visibility = 'public'                                 -- master switch: private = owner-only everywhere
       AND COALESCE(u.location_members_precision, 'city') <> 'private'     -- members-private = off every map (ruling 4 exception)
     GROUP BY cell_lat, cell_lng
")->fetchAll();

$cells = [];
$total = 0;
foreach ($rows as $r) {
    $n = (int)$r['n'];
    $total += $n;
    $cells[] = [(float)$r['cell_lat'], (float)$r['cell_lng'], $n];
}

header('Cache-Control: public, max-age=900');
profile_app_json(200, ['count' => $total, 'cells' => $cells]);
