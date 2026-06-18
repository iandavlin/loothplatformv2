<?php
/**
 * profile-app — full-snapshot location rebackfill from BuddyBoss.
 *
 * Pre-cutover, BB is authoritative for location. For every user with a
 * xprofile field_id=96 value or a usermeta geocode_96 pin:
 *
 *   - location_text     = BB text (html_entity_decode first)
 *   - location_address  = BB text (same source; the exact-precision tier
 *                         per the block-system spec). 2026-05-29 add.
 *   - lat, lng          = BB pin (autocomplete-time, so it's the real one)
 *   - city / region / country / postcode = reverse-geocoded via Nominatim
 *
 * Idempotent: skip when lat + location_text + location_address already
 * match BB source.
 *
 * Slice 2.75 supersedes the lat/lng-only `regeocode-from-bb.php` and the
 * earlier partial version of this script. The partial version's
 * "user-edit guard" is dropped — the slice-2.5 editor saved freeform text
 * with mismatched coords, which is exactly the bug the snapshot fixes.
 *
 * Block-system carryover (slice 4): users.location_address is the
 * exact-precision tier used by the new identity-block location field.
 * Populating it from field 96 at cutover means users don't need a
 * back-pass through the editor to re-enter what BB already knows.
 * See docs/spec-block-identity-location.md.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config.php';

use Looth\ProfileApp\Db;

const NOMINATIM_HOST = 'https://nominatim.openstreetmap.org';
const NOMINATIM_UA   = 'looth-profile-app/0.3 (admin: ian.davlin@gmail.com)';
const NOMINATIM_SLEEP_USEC = 1_100_000;   // 1.1s — keep below the 1 rps policy.

$mysqlSocket = '/var/run/mysqld/mysqld.sock';
$mysqlUser   = posix_getpwuid(posix_geteuid())['name'] ?? 'profile-app';
$wp = new PDO(
    'mysql:unix_socket=' . $mysqlSocket . ';dbname=' . LG_PROFILE_APP_MYSQL_DB,
    $mysqlUser, '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pg = Db::pg();

$sql = "
    SELECT u.id AS wp_user_id,
           xp.value      AS xprofile_text,
           um.meta_value AS geocode
    FROM wp_users u
    LEFT JOIN wp_bp_xprofile_data xp
      ON xp.user_id = u.id AND xp.field_id = 96
    LEFT JOIN wp_usermeta um
      ON um.user_id = u.id AND um.meta_key = 'geocode_96'
    WHERE (xp.value IS NOT NULL AND xp.value <> '')
       OR (um.meta_value IS NOT NULL AND um.meta_value <> '')
";
$src = $wp->query($sql);

$bridge = $pg->prepare("SELECT user_id FROM wp_user_bridge WHERE wp_user_id = :w");
$selCur = $pg->prepare("SELECT location_text, location_address, lat, lng,
                               location_city, location_region, location_country, location_postcode
                        FROM users WHERE id = :id");
$upd = $pg->prepare("
    UPDATE users SET
        location_text     = :text,
        location_address  = :address,
        lat               = :lat,
        lng               = :lng,
        location_city     = :city,
        location_region   = :region,
        location_country  = :country,
        location_postcode = :postcode
    WHERE id = :id
");

$counts = ['updated' => 0, 'skipped' => 0, 'no_bridge' => 0, 'no_source' => 0, 'reverse_failed' => 0];
$noBridgeIds = [];
$started = microtime(true);

while ($row = $src->fetch(PDO::FETCH_ASSOC)) {
    $wpId    = (int)$row['wp_user_id'];
    $text    = $row['xprofile_text'];
    $geocode = $row['geocode'];

    // Parse the BB pin.
    $lat = $lng = null;
    if (is_string($geocode) && preg_match('/^(-?\d+\.\d+),(-?\d+\.\d+)$/', trim($geocode), $m)) {
        $la = (float)$m[1]; $ln = (float)$m[2];
        if (($la !== 0.0 || $ln !== 0.0) && $la >= -90 && $la <= 90 && $ln >= -180 && $ln <= 180) {
            $lat = $la; $lng = $ln;
        }
    }
    $hasText = is_string($text) && trim($text) !== '';
    if (!$hasText && $lat === null) { $counts['no_source']++; continue; }

    $tgtText = $hasText ? html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;

    // Resolve bridge.
    $bridge->execute([':w' => $wpId]);
    $paId = $bridge->fetchColumn();
    if (!$paId) {
        $counts['no_bridge']++;
        if (count($noBridgeIds) < 30) $noBridgeIds[] = $wpId;
        continue;
    }

    $selCur->execute([':id' => $paId]);
    $cur = $selCur->fetch(PDO::FETCH_ASSOC);
    if (!$cur) { $counts['no_bridge']++; continue; }

    // Idempotent check: text + address + coords match the BB source.
    // location_address shares the same source string as location_text (BB
    // field 96 carries the exact-precision address text); separate columns
    // for the block-system's exact/approximate split.
    $curLat = $cur['lat'] !== null ? round((float)$cur['lat'], 6) : null;
    $curLng = $cur['lng'] !== null ? round((float)$cur['lng'], 6) : null;
    $newLat = $lat !== null ? round($lat, 6) : null;
    $newLng = $lng !== null ? round($lng, 6) : null;
    $textMatches    = ($cur['location_text'] ?? null) === $tgtText;
    $addressMatches = ($cur['location_address'] ?? null) === $tgtText;
    $coordsMatch    = $curLat === $newLat && $curLng === $newLng;
    $hasComponents  = $cur['location_country'] !== null || $cur['location_city'] !== null;
    if ($textMatches && $addressMatches && $coordsMatch && ($lat === null || $hasComponents)) {
        $counts['skipped']++;
        continue;
    }

    // Reverse-geocode for components (if we have coords).
    $city = $region = $country = $postcode = null;
    if ($lat !== null) {
        $rev = nominatim_reverse($lat, $lng);
        if ($rev === null) {
            $counts['reverse_failed']++;
            // Continue with text+coords only.
        } else {
            $a        = $rev['address'] ?? [];
            $city     = $a['city'] ?? $a['town'] ?? $a['village'] ?? $a['hamlet'] ?? $a['suburb'] ?? null;
            $region   = $a['state'] ?? $a['region'] ?? null;
            $country  = $a['country'] ?? null;
            $postcode = $a['postcode'] ?? null;
        }
    }

    $upd->execute([
        ':text'     => $tgtText,
        ':address'  => $tgtText,
        ':lat'      => $lat,
        ':lng'      => $lng,
        ':city'     => $city,
        ':region'   => $region,
        ':country'  => $country,
        ':postcode' => $postcode,
        ':id'       => $paId,
    ]);
    $counts['updated']++;

    if ($lat !== null) usleep(NOMINATIM_SLEEP_USEC);
}

$elapsed = microtime(true) - $started;
printf("snapshot-location-from-bb complete in %.1fs\n", $elapsed);
foreach ($counts as $k => $v) printf("  %-16s %d\n", $k, $v);
if ($noBridgeIds) {
    printf("\nno_bridge wp_user_ids (first %d):\n  %s\n",
        count($noBridgeIds), implode(', ', $noBridgeIds));
}


function nominatim_reverse(float $lat, float $lng): ?array
{
    $url = NOMINATIM_HOST . '/reverse?'
        . 'format=json&addressdetails=1'
        . '&lat=' . urlencode((string)$lat)
        . '&lon=' . urlencode((string)$lng);
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => 'User-Agent: ' . NOMINATIM_UA . "\r\nAccept: application/json\r\n",
        'timeout' => 6,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return null;
    $r = json_decode($body, true);
    return is_array($r) ? $r : null;
}
