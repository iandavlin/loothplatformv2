<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\GeoIP;

// Location autocomplete proxy. The editor's location picker hits this and
// gets a debounced search result from OSM Nominatim. We proxy server-side
// so we can:
//   - set a polite User-Agent (Nominatim's usage policy requires it)
//   - bias the viewbox to ~500km around the caller's IP
//   - throttle per-IP at the nginx layer (see deploy/nginx-rate-limit.example.conf)
//
// Returns the Nominatim payload verbatim plus a stripped summary list keyed
// to what the client renders.

if ($_SERVER['REQUEST_METHOD'] !== 'GET') profile_app_json(405, ['error' => 'method_not_allowed']);
Auth::requireUser();   // never expose this endpoint to anon

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') profile_app_json(400, ['error' => 'empty_query']);
if (strlen($q) > 200) profile_app_json(400, ['error' => 'query_too_long']);

$ua = 'looth-profile-app/0.3 (admin: ian.davlin@gmail.com)';
$nominatimHost = getenv('LOOTH_NOMINATIM_HOST') ?: 'https://nominatim.openstreetmap.org';

$viewbox = '';
$ip      = GeoIP::callerIp();
$pin     = GeoIP::lookup($ip);
$geoStatus = $pin === null ? (is_readable(GeoIP::DB_PATH_DEFAULT) ? 'no_match' : 'missing') : 'ok';
if ($pin !== null) {
    $viewbox = '&viewbox=' . urlencode(GeoIP::viewboxAround($pin[0], $pin[1], 500));
}

$url = $nominatimHost . '/search?'
    . 'q='              . urlencode($q)
    . '&format=json'
    . '&addressdetails=1'
    . '&limit=5'
    . $viewbox
    . '&bounded=0';

$ctx = stream_context_create(['http' => [
    'method'        => 'GET',
    'header'        => "User-Agent: $ua\r\nAccept: application/json\r\n",
    'timeout'       => 4,
    'ignore_errors' => true,
]]);

$body = @file_get_contents($url, false, $ctx);
if ($body === false) profile_app_json(502, ['error' => 'nominatim_unreachable']);

$status = 0;
if (!empty($http_response_header)) {
    foreach ($http_response_header as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int)$m[1]; break; }
    }
}
if ($status !== 200) profile_app_json(502, ['error' => 'nominatim_status_'.$status]);

$rows = json_decode($body, true);
if (!is_array($rows)) profile_app_json(502, ['error' => 'nominatim_bad_json']);

// Summary shape — what the picker renders. Full rows passed under `raw`
// so the save call back to /me/location can echo the picked row verbatim.
$items = [];
foreach ($rows as $r) {
    $a = $r['address'] ?? [];
    $city = $a['city'] ?? $a['town'] ?? $a['village'] ?? $a['hamlet'] ?? $a['suburb'] ?? null;
    $parts = array_filter([$city, $a['state'] ?? null, $a['country'] ?? null]);
    $short = $parts ? implode(', ', $parts) : substr((string)($r['display_name'] ?? ''), 0, 80);
    $items[] = [
        'display_name' => $r['display_name'] ?? '',
        'short'        => $short,
        'lat'          => isset($r['lat']) ? (float)$r['lat'] : null,
        'lng'          => isset($r['lon']) ? (float)$r['lon'] : null,
        'raw'          => $r,
    ];
}

profile_app_json(200, [
    'items'      => $items,
    'geo_status' => $geoStatus,
]);
