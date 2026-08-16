<?php
/**
 * notif-type-filter-probe — the PHP half of tools/gates/notif-type-filter-gate.py.
 *
 * Drives the REAL me-notifications.php endpoint (included, never re-implemented)
 * as a REAL authenticated member, and prints its JSON on stdout.
 *
 * Two things make it honest:
 *   - the endpoint file is included, so Auth, the flag reads and every branch are
 *     the shipped ones;
 *   - the flag moves via Flags::forTest(), which is CLI-only by enforcement, so
 *     both states are exercised without ever editing the tracked config on disk.
 *
 * The probe member is PER-RUN and PID-keyed, created here and destroyed by the
 * gate, so a concurrent suite can never collide with it — and a BYSTANDER member
 * is created alongside, because "only that member" is the assertion that matters
 * most and it cannot be tested with one account.
 *
 * argv: <repo-root> setup|teardown <pid>
 *       <repo-root> <off|on> <METHOD> <json-query> <probe-uuid> [<count-uuid>]
 */
$root = $argv[1];
require $root . '/profile-app/config.php';

use Looth\ProfileApp\Db;

/* ── setup / teardown ────────────────────────────────────────────────────────
   PER-RUN and PID-keyed so two suites can never collide, and REPAIRS ON ENTRY
   rather than only on exit — a run killed half-way (a reboot did exactly that to
   this box tonight) must not leave probe members sitting in the real directory. */
if ($argv[2] === 'setup' || $argv[2] === 'teardown') {
    $pid = (int)$argv[3];
    $pg  = Db::pg();
    $slugs = ["ntf-probe-$pid-me", "ntf-probe-$pid-bystander"];
    foreach ($slugs as $sl) {
        $pg->prepare("DELETE FROM notifications WHERE user_uuid IN (SELECT uuid FROM users WHERE slug = :s)")
           ->execute([':s' => $sl]);
        $pg->prepare("DELETE FROM users WHERE slug = :s")->execute([':s' => $sl]);
    }
    if ($argv[2] === 'teardown') { echo "ok\n"; exit(0); }

    $mk = function (string $sl) use ($pg) {
        $st = $pg->prepare("INSERT INTO users (uuid, display_name, slug, primary_email)
                            VALUES (gen_random_uuid(), :n, :s, :e) RETURNING uuid");
        $st->execute([':n' => 'Notif Probe', ':s' => $sl, ':e' => $sl . '@probe.invalid']);
        return $st->fetchColumn();
    };
    $me = $mk($slugs[0]); $by = $mk($slugs[1]);
    $seq = 0;
    $note = function (string $u, string $t) use ($pg, &$seq) {
        $seq++;
        $bare = in_array($t, ['message','connection_request','connection_accept'], true);
        $pg->prepare("INSERT INTO notifications (user_uuid, type, is_read, target_kind, target_id, target_url)
                      VALUES (:u,:t,false,:k,:i,:url)")
           ->execute([':u'=>$u, ':t'=>$t, ':k'=>$bare?null:'post',
                      ':i'=>$bare?null:$seq, ':url'=>$bare?null:"/probe/$seq"]);
    };
    foreach ([1,2,3] as $_) $note($me, 'connection_accept');
    foreach ([1,2]   as $_) $note($me, 'reaction.on_post');
    $note($by, 'connection_accept');
    echo json_encode(['me' => $me, 'bystander' => $by]) . "\n";
    exit(0);
}

$state = $argv[2]; $method = $argv[3]; $query = $argv[4]; $uuid = $argv[5];

use Looth\ProfileApp\Flags;

Flags::forTest('notifications', [
    'filter_and_bulk_by_type'  => ($state === 'on'),
    'dismiss_instead_of_delete' => false,     // pin: live has no dismissed_at column
    'read_seen_only'           => false,
    'max_ids'                  => 200,
]);

// Authenticate AS the probe member without minting a JWT: Auth::currentUser() is
// cached behind a static, so the endpoint reads whatever we prime here. This is
// the one seam the probe needs and it exists for exactly this purpose.
$row = \Looth\ProfileApp\Db::pg()->prepare('SELECT * FROM users WHERE uuid = :u');
$row->execute([':u' => $uuid]);
$user = $row->fetch();
if (!$user) { fwrite(STDERR, "probe user $uuid not found\n"); exit(2); }
\Looth\ProfileApp\Auth::pinUserForTest($user);

$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['REQUEST_URI']    = '/profile-api/v0/me/notifications/';
$_GET = json_decode($query, true) ?: [];

include $root . '/profile-app/api/v0/me-notifications.php';
