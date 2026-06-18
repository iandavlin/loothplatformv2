<?php
/**
 * profile-app — re-backfill lat/lng from BuddyBoss's autocomplete-time
 * geocodes stored at wp_usermeta.geocode_96.
 *
 * BB's location-field type stores the Google Places PlaceResult's
 * coordinates in usermeta when the user picks from autocomplete. Those
 * are way more precise than re-geocoding the displayed string (e.g. a
 * user who picked "Williamsburg, Brooklyn" gets the exact lat/lng of
 * their pick, not the city centroid for "New York, NY").
 *
 * This is the right source — the slice-2.5 Nominatim pass was working
 * around not knowing this data existed.
 *
 * Idempotent. Skips rows where lat/lng already match the geocode_96 value.
 * Safe to run on dev now and again on live at cutover.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config.php';

use Looth\ProfileApp\Db;

$mysqlSocket = '/var/run/mysqld/mysqld.sock';
$mysqlUser   = posix_getpwuid(posix_geteuid())['name'] ?? 'profile-app';
$wp = new PDO(
    'mysql:unix_socket=' . $mysqlSocket . ';dbname=' . LG_PROFILE_APP_MYSQL_DB,
    $mysqlUser, '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pg = Db::pg();

$sql = "SELECT user_id, meta_value
        FROM wp_usermeta
        WHERE meta_key = 'geocode_96'
          AND meta_value REGEXP '^-?[0-9]+\\\\.[0-9]+,-?[0-9]+\\\\.[0-9]+$'";
$src = $wp->query($sql);

$bridge = $pg->prepare("SELECT user_id FROM wp_user_bridge WHERE wp_user_id = :w");
$updPre = $pg->prepare("
    UPDATE users
    SET lat = :lat,
        lng = :lng,
        updated_at = NOW()
    WHERE id = :id
      AND ( lat IS DISTINCT FROM :lat OR lng IS DISTINCT FROM :lng )
");

$counts = ['updated' => 0, 'unchanged' => 0, 'no_bridge' => 0, 'invalid' => 0];
$started = microtime(true);

while ($row = $src->fetch(PDO::FETCH_ASSOC)) {
    $wpId = (int)$row['user_id'];
    [$lat, $lng] = array_map('floatval', explode(',', $row['meta_value']));
    if ($lat == 0.0 && $lng == 0.0) { $counts['invalid']++; continue; }
    if ($lat < -90  || $lat > 90)   { $counts['invalid']++; continue; }
    if ($lng < -180 || $lng > 180)  { $counts['invalid']++; continue; }

    $bridge->execute([':w' => $wpId]);
    $paId = $bridge->fetchColumn();
    if (!$paId) { $counts['no_bridge']++; continue; }

    $updPre->execute([':lat' => $lat, ':lng' => $lng, ':id' => $paId]);
    if ($updPre->rowCount() > 0) {
        $counts['updated']++;
    } else {
        $counts['unchanged']++;
    }
}

$elapsed = microtime(true) - $started;
printf("regeocode-from-bb complete in %.1fs\n", $elapsed);
printf("  updated   %d  (lat/lng changed)\n",       $counts['updated']);
printf("  unchanged %d  (already matched)\n",       $counts['unchanged']);
printf("  no_bridge %d  (wp_user has no pa user)\n", $counts['no_bridge']);
printf("  invalid   %d  (malformed geocode value)\n", $counts['invalid']);

// Coverage diff
$total  = (int)$pg->query("SELECT COUNT(*) FROM users")->fetchColumn();
$hasLat = (int)$pg->query("SELECT COUNT(*) FROM users WHERE lat IS NOT NULL")->fetchColumn();
$missing = (int)$pg->query("
    SELECT COUNT(*) FROM users u
    WHERE u.location_text IS NOT NULL AND u.location_text != ''
      AND u.lat IS NULL
")->fetchColumn();
printf("\ncurrent state:\n");
printf("  users total          %d\n", $total);
printf("  users with lat/lng   %d\n", $hasLat);
printf("  users with text only %d  (no geocode source)\n", $missing);
