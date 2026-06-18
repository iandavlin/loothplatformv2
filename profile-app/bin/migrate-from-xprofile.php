<?php
/**
 * profile-app — slim BB xprofile → profile-app migration.
 *
 * WRITTEN in slice 2.75. RUN at slice 3 cutover.
 *
 * Post-audit scope decision: only port the three things we trust to be clean
 * in BB and useful day-one in profile-app:
 *
 *   1. Full Name (xprofile field 1)  → users.display_name
 *   2. Business Name (field 2)       → users.business_name
 *   - URL slug                       → users.slug from wp_users.user_nicename
 *
 * Location came across in 2.75 via bin/snapshot-location-from-bb.php and is
 * NOT touched here.
 *
 * Everything else (xprofile work history, references, resume, shop pics,
 * education, handle, phone, website) is intentionally dropped — users rebuild
 * via the editor. The legacy_xprofile jsonb column was removed; this script
 * does not write to it.
 *
 * Idempotent — only fills empty fields. Re-running won't clobber edits.
 * Dry-run by default. Pass --commit to mutate.
 *
 * Usage:
 *   sudo -u profile-app php bin/migrate-from-xprofile.php           # dry-run
 *   sudo -u profile-app php bin/migrate-from-xprofile.php --commit
 *   sudo -u profile-app php bin/migrate-from-xprofile.php --user 42 # one wp_user_id
 */

declare(strict_types=1);
require_once __DIR__ . '/../config.php';

use Looth\ProfileApp\Db;

$COMMIT   = in_array('--commit', $argv, true);
$ONE_USER = null;
foreach ($argv as $i => $a) if ($a === '--user' && isset($argv[$i+1])) $ONE_USER = (int)$argv[$i+1];

echo $COMMIT ? "*** COMMIT MODE — mutations will be written ***\n"
             : "(dry-run; pass --commit to write)\n";

$mysqlSocket = '/var/run/mysqld/mysqld.sock';
$mysqlUser   = posix_getpwuid(posix_geteuid())['name'] ?? 'profile-app';
$wp = new PDO('mysql:unix_socket=' . $mysqlSocket . ';dbname=' . LG_PROFILE_APP_MYSQL_DB,
              $mysqlUser, '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pg = Db::pg();

$counts = [
    'users_walked'         => 0,
    'no_bridge'            => 0,
    'display_name_updates' => 0,
    'business_updates'     => 0,
    'slug_updates'         => 0,
    'slug_collisions'      => 0,
];

$bridge       = $pg->prepare("SELECT user_id FROM wp_user_bridge WHERE wp_user_id = :w");
$selUser      = $pg->prepare("SELECT display_name, business_name, slug FROM users WHERE id = :i");
$selSlugTaken = $pg->prepare("SELECT id FROM users WHERE slug = :s AND id <> :self");
$updName      = $pg->prepare("UPDATE users SET display_name  = :n WHERE id = :i");
$updBiz       = $pg->prepare("UPDATE users SET business_name = :b WHERE id = :i");
$updSlug      = $pg->prepare("UPDATE users SET slug          = :s WHERE id = :i");

$where = $ONE_USER ? "WHERE u.ID = " . (int)$ONE_USER : "";
$wpUsers = $wp->query("SELECT u.ID, u.user_nicename FROM wp_users u $where ORDER BY u.ID");

if ($COMMIT) $pg->beginTransaction();

while ($wpu = $wpUsers->fetch(PDO::FETCH_ASSOC)) {
    $wpId      = (int)$wpu['ID'];
    $nicename  = trim((string)$wpu['user_nicename']);
    $bridge->execute([':w' => $wpId]);
    $paId = $bridge->fetchColumn();
    if (!$paId) { $counts['no_bridge']++; continue; }
    $counts['users_walked']++;

    $xp = fetch_xprofile($wp, $wpId);
    $selUser->execute([':i' => $paId]);
    $cur = $selUser->fetch(PDO::FETCH_ASSOC);

    $diff = [];

    // 1. display_name from xprofile field 1 (only if current is empty)
    $bbName = trim(html_entity_decode((string)($xp[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($bbName !== '' && empty(trim((string)$cur['display_name']))) {
        $diff[] = "display_name: '' → '$bbName'";
        if ($COMMIT) $updName->execute([':n' => $bbName, ':i' => $paId]);
        $counts['display_name_updates']++;
    }

    // 2. business_name from xprofile field 2 (only if current is empty)
    $bbBiz = trim(html_entity_decode((string)($xp[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($bbBiz !== '' && empty(trim((string)$cur['business_name']))) {
        $diff[] = "business_name: '' → '$bbBiz'";
        if ($COMMIT) $updBiz->execute([':b' => $bbBiz, ':i' => $paId]);
        $counts['business_updates']++;
    }

    // 3. slug from wp_users.user_nicename (only if current is empty)
    if ($nicename !== '' && empty(trim((string)$cur['slug']))) {
        $selSlugTaken->execute([':s' => $nicename, ':self' => $paId]);
        if ($selSlugTaken->fetchColumn()) {
            $diff[] = "slug COLLISION: wanted '$nicename' but taken; left blank";
            $counts['slug_collisions']++;
        } else {
            $diff[] = "slug: '' → '$nicename'";
            if ($COMMIT) $updSlug->execute([':s' => $nicename, ':i' => $paId]);
            $counts['slug_updates']++;
        }
    }

    if ($diff) printf("wp=%d pa=%s\n  %s\n", $wpId, $paId, implode("\n  ", $diff));
}

if ($COMMIT) $pg->commit();

echo "\n";
foreach ($counts as $k => $v) printf("  %-24s %d\n", $k, $v);
echo $COMMIT ? "\nCOMMITTED.\n" : "\n(dry-run — re-run with --commit to apply)\n";


function fetch_xprofile(PDO $wp, int $wpId): array
{
    $s = $wp->prepare("SELECT field_id, value FROM wp_bp_xprofile_data WHERE user_id = :u AND field_id IN (1,2)");
    $s->execute([':u' => $wpId]);
    $out = [];
    while ($r = $s->fetch(PDO::FETCH_ASSOC)) {
        $out[(int)$r['field_id']] = $r['value'];
    }
    return $out;
}
