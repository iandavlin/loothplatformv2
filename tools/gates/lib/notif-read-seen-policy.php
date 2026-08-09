<?php
/**
 * notif-read-seen-policy.php — the FLAG half of notif-read-seen-gate.
 *
 *   sudo -u profile-app php notif-read-seen-policy.php <branch-root> <config-path>
 *
 * Exercises Notifications::applySeenRead — the ONE place the flag is branched —
 * under a config the caller supplies, so BOTH directions are measured against the
 * real decision code rather than argued from its source.
 *
 * The config path arrives as a CONSTANT (LG_NOTIF_CONFIG_PATH), not an env var:
 * sudo strips the environment, and a flag-ON gate run that silently exercises the
 * OFF path is a whole failure class on this platform — an assertion that passes
 * while testing the opposite of what it claims.
 *
 * Same posture as the core helper: one transaction, never committed.
 */
declare(strict_types=1);

$root = $argv[1] ?? dirname(__DIR__, 3);
$cfg  = $argv[2] ?? '';
if ($cfg !== '') define('LG_NOTIF_CONFIG_PATH', $cfg);

require_once $root . '/profile-app/config.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Notifications.php';

use Looth\ProfileApp\Db;
use Looth\ProfileApp\Notifications;

$out = ['ok' => false, 'config' => $cfg];
$pg  = Db::pg();

try {
    $pg->beginTransaction();

    $me = $pg->query(
        "SELECT u.uuid, b.wp_user_id FROM users u
           JOIN wp_user_bridge b ON b.user_id = u.id
          ORDER BY b.wp_user_id LIMIT 1"
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$me) { $out['cannot_run'] = 'no bridged member'; echo json_encode($out); exit; }

    $pg->prepare('UPDATE notifications SET is_read = true WHERE user_uuid = :u')
       ->execute([':u' => $me['uuid']]);

    $ins = $pg->prepare(
        "INSERT INTO notifications
            (user_uuid, actor_uuid, type, is_read, created_at, target_kind, target_id, target_url, actor_count)
         VALUES (:u, NULL, 'forum.reply_to_topic', false, now() - (:h || ' hours')::interval,
                 'topic', :t, :url, 1)
         RETURNING id"
    );
    $ids = [];
    for ($i = 1; $i <= 12; $i++) {
        $ins->execute([':u' => $me['uuid'], ':h' => $i, ':t' => 993000 + $i,
                       ':url' => '/hub/?topic=' . (993000 + $i)]);
        $ids[] = (int)$ins->fetchColumn();
    }
    $seen   = array_slice($ids, 0, 8);
    $unseen = array_slice($ids, 8);

    $out['flag_read_seen_only'] = Notifications::readSeenOnly();
    $out['max_ids']             = Notifications::maxIds();

    // The whole point: hand it the SEEN ids and see what the store looks like after.
    $out['result'] = Notifications::applySeenRead($me['uuid'], $seen);

    $st = $pg->prepare('SELECT id, is_read FROM notifications
                         WHERE id = ANY(string_to_array(:i, \',\')::bigint[])');
    $st->execute([':i' => implode(',', $ids)]);
    $read = [];
    foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) $read[(int)$r['id']] = (bool)$r['is_read'];

    $out['seen_read']           = count(array_filter($seen,   fn ($i) => $read[$i] ?? false));
    $out['unseen_read']         = count(array_filter($unseen, fn ($i) => $read[$i] ?? false));
    $out['unseen_still_unread'] = count(array_filter($unseen, fn ($i) => !($read[$i] ?? true)));
    $out['unread_total']        = Notifications::unreadCount($me['uuid']);

    // The id cap, measured rather than trusted: with max_ids below the list length,
    // applySeenRead under 'seen' must mark at most max_ids rows.
    $out['cap_respected'] = ($out['result']['policy'] !== 'seen')
        || ($out['result']['marked'] <= Notifications::maxIds());

    $out['ok'] = true;
} catch (\Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
} finally {
    if ($pg->inTransaction()) $pg->rollBack();
}

echo json_encode($out);
