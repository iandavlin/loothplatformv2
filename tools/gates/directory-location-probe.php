<?php
/**
 * directory-location-probe — the PHP half of tools/gates/directory-location-gate.py.
 *
 * Runs the REAL directory endpoint file (not a re-implementation of it) in-process
 * under a chosen flag state, and prints its JSON on stdout. Two things make that
 * honest rather than a copy of the code under test:
 *
 *   - The endpoint is INCLUDED, so every layer the web request goes through —
 *     _bootstrap, Auth, Visibility::locationPrecision, dir_member_display,
 *     Block::locationDisplay — is the shipped one. "Verify the thing, not the
 *     thing next to it."
 *   - The flag is moved with Flags::forTest(), which is CLI-ONLY BY
 *     ENFORCEMENT, so this proof can exercise BOTH states without ever editing
 *     the tracked config file on disk. A harness that mutates tracked config is
 *     how a lane once wiped uncommitted work under test.
 *
 * The app root is passed in, so the gate exercises THE BRANCH IT IS RUNNING FROM
 * rather than whatever /srv/profile-app happens to symlink to — a gate that
 * silently audits main is a known way to pass a branch that never got tested.
 *
 * argv: <app-root> <off|on|default|mint|mint-admin> <json-query> [-]  ('-' = token on stdin)
 */
$root  = $argv[1] ?? '';
$state = $argv[2] ?? 'default';
$query = $argv[3] ?? '{}';
$tokf  = $argv[4] ?? '';   // '-' means: read the viewer bearer from stdin

if ($root === '' || !is_file($root . '/config.php')) {
    fwrite(STDERR, "directory-location-probe: no config.php under app root '$root'\n");
    exit(2);
}
require $root . '/config.php';

if ($state !== 'default') {
    \Looth\ProfileApp\Flags::forTest('directory-location', [
        'coarsen_list_location' => ($state === 'on'),
    ]);
}

// mint mode: print a signed viewer token for an ordinary member, so the gate can
// exercise the LOGGED-IN audience with a real bearer instead of asserting from the
// code that both audiences share a chokepoint. Picks a member whose own location is
// never at street precision, so the viewer's own row (self always sees self at
// full precision) can never be mistaken for one of the rows under test.
if ($state === 'mint' || $state === 'mint-admin') {
    if ($state === 'mint-admin') {
        // The ADMIN audience is the biggest leak of the three: precisionForAudience()
        // hands admins 'street' for EVERY member, so an admin's directory page was
        // ~1,900 raw addresses, not 7. Pick a real WP administrator that also has a
        // profile-app bridge row, the same capability read Auth::isAdmin() does.
        $u  = posix_getpwuid(posix_geteuid())['name'] ?? 'profile-app';
        $my = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=' . LG_PROFILE_APP_MYSQL_DB,
                      $u, '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $ids = $my->query("SELECT user_id FROM wp_usermeta WHERE meta_key='wp_capabilities'
                            AND meta_value LIKE '%administrator%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $wpid) {
            $t = \Looth\ProfileApp\Mint::mintForWpUserId((int)$wpid);
            if ($t && !empty($t['token'])) { echo $t['token']; exit(0); }
        }
        fwrite(STDERR, "no WP administrator has a profile-app bridge row\n");
        exit(2);
    }
    $row = \Looth\ProfileApp\Db::pg()->query(
        "SELECT b.wp_user_id FROM users u JOIN wp_user_bridge b ON b.user_id = u.id
          WHERE u.location_public_precision = 'private'
            AND u.location_members_precision <> 'street'
            AND u.slug IS NOT NULL
          ORDER BY u.id LIMIT 1"
    )->fetch();
    if (!$row) { fwrite(STDERR, "no eligible viewer to mint\n"); exit(2); }
    $t = \Looth\ProfileApp\Mint::mintForWpUserId((int)$row['wp_user_id']);
    if (!$t || empty($t['token'])) { fwrite(STDERR, "mint failed\n"); exit(2); }
    echo $t['token'];
    exit(0);
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/profile-api/v0/directory/members';
$_GET = json_decode($query, true) ?: [];

// A viewer token turns this from the anon audience into the members audience.
// Both are LIST surfaces and the charter binds both.
//
// It arrives on STDIN and is never written to disk. It is a signed bearer for a
// real member: a token file under /tmp readable by the profile-app user is also
// readable by every other local user, and handing out a member's credential is
// the same class of defect this gate exists to catch.
if ($tokf === '-') {
    $tok = trim((string)stream_get_contents(STDIN));
    if ($tok !== '') $_COOKIE[\Looth\ProfileApp\Auth::COOKIE] = $tok;
}

include $root . '/api/v0/directory-members.php';
