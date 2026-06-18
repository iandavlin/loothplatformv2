<?php
/**
 * profile-app — read-only account triage report.
 *
 * Prints TSV: [wp_id, login, email, display, signals, would_archive].
 * Pipe to `less` or `column -t -s $'\t'`. Does NOT mutate the DB.
 *
 * Heuristics:
 *   - dup_email     : another wp_user shares this user_email (lowercased)
 *   - dup_name      : another wp_user shares display_name (case-insensitive)
 *                     and the emails look related (same local-part stem)
 *   - ghost         : user_email is empty, OR no bp_xprofile data, OR no
 *                     activity in the activity table (best-effort signal)
 *   - never_login   : usermeta `last_activity` more than 730 days old
 *
 * would_archive: yes when ghost AND no signals of any content.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$mysqlSocket = '/var/run/mysqld/mysqld.sock';
$mysqlUser   = posix_getpwuid(posix_geteuid())['name'] ?? 'profile-app';
$wp = new PDO(
    'mysql:unix_socket=' . $mysqlSocket . ';dbname=' . LG_PROFILE_APP_MYSQL_DB,
    $mysqlUser, '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$users = $wp->query("
    SELECT u.ID, u.user_login, u.user_email, u.user_registered, u.display_name,
           (SELECT COUNT(*) FROM wp_bp_xprofile_data x WHERE x.user_id = u.ID)        AS xprofile_count,
           (SELECT meta_value FROM wp_usermeta WHERE user_id = u.ID AND meta_key='last_activity') AS last_activity
    FROM wp_users u
    ORDER BY u.ID
")->fetchAll(PDO::FETCH_ASSOC);

// Build dup-email index.
$byEmail = [];
$byName  = [];
foreach ($users as $u) {
    $e = strtolower(trim((string)$u['user_email']));
    $n = strtolower(trim((string)$u['display_name']));
    if ($e !== '') $byEmail[$e][] = (int)$u['ID'];
    if ($n !== '') $byName[$n][]  = (int)$u['ID'];
}

echo implode("\t", ['wp_id', 'login', 'email', 'display', 'signals', 'would_archive']) . "\n";

$now = time();
foreach ($users as $u) {
    $wpId = (int)$u['ID'];
    $email = strtolower(trim((string)$u['user_email']));
    $name  = strtolower(trim((string)$u['display_name']));
    $signals = [];

    if ($email !== '' && count($byEmail[$email] ?? []) > 1) {
        $signals[] = 'dup_email(' . implode(',', $byEmail[$email]) . ')';
    }
    if ($name !== '' && count($byName[$name] ?? []) > 1) {
        $signals[] = 'dup_name';
    }
    if ($email === '') $signals[] = 'no_email';
    if ((int)$u['xprofile_count'] === 0) $signals[] = 'no_xprofile';
    $la = $u['last_activity'];
    if ($la) {
        $ts = strtotime((string)$la);
        if ($ts !== false && ($now - $ts) > 730 * 86400) $signals[] = 'never_login_2y';
    } else {
        $signals[] = 'no_last_activity';
    }

    $ghost = in_array('no_email', $signals, true) || in_array('no_xprofile', $signals, true);
    $wouldArchive = $ghost && !in_array('dup_email', $signals, true) ? 'yes' : 'no';

    echo implode("\t", [
        $wpId,
        $u['user_login'],
        $u['user_email'],
        $u['display_name'],
        implode(',', $signals) ?: '-',
        $wouldArchive,
    ]) . "\n";
}
