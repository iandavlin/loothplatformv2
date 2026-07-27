<?php
declare(strict_types=1);

/**
 * purge-stale-looth-slug-mirror.php — find (and emit the fix for) WordPress
 * `_looth_slug` usermeta rows that disagree with profile-app's `users.slug`.
 *
 * THE SPLIT-BRAIN THIS EXISTS TO CLOSE
 * ------------------------------------
 * `/u/<slug>` resolves from ONE place: Postgres `users.slug`. WordPress keeps a COPY
 * in `_looth_slug` usermeta, and that copy is a pure cache — profile-auth.php reads it
 * to fill the `slug` claim on the looth_id JWT, which the shared site header turns into
 * the member's own "My Profile" link.
 *
 * Nothing invalidates that cache when the slug changes. So after any backfill or rename
 * the member's own profile link points at their OLD address. It still resolves (slug
 * history 301s it), so nothing looks broken — which is exactly why it went unnoticed:
 * on dev2, 216 of 267 mirrors (81%) were stale, every one of them still handing members
 * a patreon_<id> URL the backfill had already retired.
 *
 * WHY THIS SCRIPT ONLY *EMITS* THE FIX
 * ------------------------------------
 * Neither process can see both stores AND write the one that is wrong:
 *   - profile-app reads Postgres, and has SELECT-ONLY on the WP MySQL database.
 *   - WordPress can write usermeta, but has no Postgres access at all.
 * So this runs as profile-app (reads BOTH, writes NEITHER) and prints the exact WP-side
 * command to run. Same reason the slug backfill hands Ian a command instead of a result.
 *
 * Deleting rather than rewriting is deliberate: profile-auth.php already re-resolves a
 * MISSING mirror from the gate-exempt internal slug endpoint and re-stamps it (throttled
 * ~1/min/user). Deleting the stale value therefore self-heals to the correct one on the
 * member's next pageview, with no second source of truth to keep in step.
 *
 *   sudo -u profile-app php purge-stale-looth-slug-mirror.php [--sql]
 */

require dirname(__DIR__) . '/config.php';

use Looth\ProfileApp\Db;

$AS_SQL = in_array('--sql', $argv, true);

// current truth
$cur = [];
foreach (Db::pg()->query('
    SELECT b.wp_user_id, u.slug
    FROM users u JOIN wp_user_bridge b ON b.user_id = u.id
    WHERE u.slug IS NOT NULL AND u.slug <> \'\'
')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $cur[(int) $r['wp_user_id']] = (string) $r['slug'];
}

// the cache
$my = new PDO(
    'mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=' . LG_PROFILE_APP_MYSQL_DB,
    posix_getpwuid(posix_geteuid())['name'] ?? 'profile-app', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$mirrors = $my->query('
    SELECT user_id, meta_value FROM wp_usermeta
    WHERE meta_key = "_looth_slug" AND meta_value <> ""
')->fetchAll(PDO::FETCH_ASSOC);

$stale = [];
$inSync = 0;
$noPg   = 0;
foreach ($mirrors as $r) {
    $wp = (int) $r['user_id'];
    $mv = (string) $r['meta_value'];
    if (!isset($cur[$wp]))                      { $noPg++;  continue; }
    if (strcasecmp($mv, $cur[$wp]) === 0)       { $inSync++; continue; }
    $stale[] = ['wp' => $wp, 'mirror' => $mv, 'pg' => $cur[$wp]];
}

if ($AS_SQL) {
    if (!$stale) { echo "-- nothing stale\n"; exit(0); }
    echo "-- Drops ONLY the stale cached copies; profile-auth.php re-resolves each from\n";
    echo "-- Postgres on the member's next pageview and re-stamps the correct value.\n";
    echo "DELETE FROM wp_usermeta WHERE meta_key = '_looth_slug' AND user_id IN (\n  "
       . implode(",\n  ", array_map(fn($s) => (string) $s['wp'], $stale)) . "\n);\n";
    exit(0);
}

printf("mirrors=%d  in-sync=%d  STALE=%d  no-pg-row=%d\n", count($mirrors), $inSync, count($stale), $noPg);
foreach (array_slice($stale, 0, 20) as $s) {
    printf("  wp#%-6d cached=%-28s actual=%s\n", $s['wp'], $s['mirror'], $s['pg']);
}
if (count($stale) > 20) printf("  ... and %d more\n", count($stale) - 20);

if ($stale) {
    echo "\nTo fix (WP side — this script cannot write MySQL):\n";
    echo "  sudo -u profile-app php " . basename(__FILE__) . " --sql > /tmp/slug-mirror.sql\n";
    // NOT `-u www-data`: it cannot read /etc/looth/live-wp-keys.php and wp-cli fatals.
    echo "  sudo wp --allow-root --path=/var/www/dev db query < /tmp/slug-mirror.sql\n";
    echo "\nSafe to re-run; it converges to 0 as members are seen.\n";
}
