<?php
/**
 * profile-app slice 2.5 geocode backfill — Nominatim (OSM) variant.
 *
 * Iterates users WHERE location_text IS NOT NULL AND lat IS NULL.
 * Uses Nominatim because the wp_options Google keys are referer-restricted
 * and Google refuses server-side Geocoding API calls with them. Nominatim
 * is free + needs no key but rate-limits to 1 req/sec and asks for a
 * descriptive User-Agent.
 *
 * Idempotent. Re-runs only hit rows still missing lat.
 *
 * Switching to Google later: only this script needs to change. Replace the
 * fetch + parse blocks; the column writes are unchanged.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config.php';

use Looth\ProfileApp\Db;

const NOMINATIM_UA = 'looth-profile-app/0.2 (admin: ian.davlin@gmail.com)';
const SLEEP_USEC   = 1050000;   // 1.05s — Nominatim asks for ≤ 1 rps

function nominatim_query(string $q): ?array {
    $url = 'https://nominatim.openstreetmap.org/search?'
         . http_build_query(['q' => $q, 'format' => 'json', 'limit' => 1, 'addressdetails' => 1]);
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "User-Agent: " . NOMINATIM_UA . "\r\nAccept: application/json\r\n",
        'timeout' => 15,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return null;
    $data = json_decode($resp, true);
    return is_array($data) && !empty($data) ? $data[0] : null;
}

$pg = Db::pg();
$rows = $pg->query("SELECT id, location_text FROM users
                    WHERE location_text IS NOT NULL AND location_text <> ''
                      AND lat IS NULL
                    ORDER BY id")->fetchAll();

printf("Geocoding %d rows via Nominatim (~%.1f min @ 1 rps)…\n",
    count($rows), count($rows) * 1.05 / 60);

$stats = ['seeded' => 0, 'no_match' => 0, 'failed' => 0];
$failures = [];

$upd = $pg->prepare("UPDATE users SET
    location_text=:t, place_id=:p, lat=:la, lng=:ln,
    location_country=:co, location_region=:re, location_city=:ci, location_postcode=:po,
    place_result=:raw::jsonb
    WHERE id=:i");

$started = microtime(true);
foreach ($rows as $i => $r) {
    $hit = nominatim_query($r['location_text']);
    if (!$hit) {
        $stats['no_match']++;
        $failures[] = "id={$r['id']} no_match: " . $r['location_text'];
        usleep(SLEEP_USEC);
        continue;
    }
    $addr = $hit['address'] ?? [];
    $city = $addr['city']    ?? ($addr['town']     ?? ($addr['village'] ?? ($addr['hamlet']  ?? null)));
    $region = $addr['state'] ?? ($addr['region']   ?? ($addr['county']  ?? null));
    $country = $addr['country'] ?? null;
    $postcode = $addr['postcode'] ?? null;
    $lat = isset($hit['lat']) ? (float)$hit['lat'] : null;
    $lng = isset($hit['lon']) ? (float)$hit['lon'] : null;

    if ($lat === null || $lng === null) {
        $stats['no_match']++; usleep(SLEEP_USEC); continue;
    }

    try {
        $upd->execute([
            ':t' => $hit['display_name'] ?? $r['location_text'],
            ':p' => isset($hit['place_id']) ? 'osm:' . $hit['place_id'] : null,
            ':la' => $lat, ':ln' => $lng,
            ':co' => $country, ':re' => $region, ':ci' => $city, ':po' => $postcode,
            ':raw' => json_encode($hit, JSON_UNESCAPED_SLASHES),
            ':i'  => (int)$r['id'],
        ]);
        $stats['seeded']++;
    } catch (Throwable $e) {
        $stats['failed']++;
        $failures[] = "id={$r['id']} db_err: " . $e->getMessage();
    }

    if (($i + 1) % 50 === 0) {
        printf("  %d/%d  seeded=%d  no_match=%d  failed=%d  (%.0fs elapsed)\n",
            $i + 1, count($rows), $stats['seeded'], $stats['no_match'], $stats['failed'],
            microtime(true) - $started);
    }
    usleep(SLEEP_USEC);
}

$elapsed = microtime(true) - $started;
echo "\n============ GEOCODE SUMMARY (Nominatim) ============\n";
foreach ($stats as $k => $v) printf("  %-9s %d\n", $k, $v);
printf("  elapsed  %.1fs\n", $elapsed);
if ($failures) {
    echo "\n  first failures:\n";
    foreach (array_slice($failures, 0, 10) as $f) echo "    $f\n";
    if (count($failures) > 10) printf("    ... and %d more\n", count($failures) - 10);
}
echo "=====================================================\n";
