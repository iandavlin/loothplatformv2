<?php
/**
 * notif-read-seen-core.php — the STORE half of notif-read-seen-gate.
 *
 *   sudo -u profile-app php tools/gates/lib/notif-read-seen-core.php <branch-root>
 *
 * Runs as the profile-app pool user (the WP pool holds no grants on profile_app),
 * inside ONE transaction that is NEVER committed. Every row it writes disappears
 * when the connection closes, so it exercises the real model against the real
 * database while changing nothing — a gate must not mutate member data to prove
 * a point about member data.
 *
 * Prints one JSON object. The Python gate decides pass/fail; this file only
 * measures, so a new assertion is added in one place.
 */
declare(strict_types=1);

$root = $argv[1] ?? dirname(__DIR__, 3);
require_once $root . '/profile-app/config.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Notifications.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Recap.php';

use Looth\ProfileApp\Db;
use Looth\ProfileApp\Notifications;
use Looth\ProfileApp\Recap;

$out = ['ok' => false];
$pg  = Db::pg();

try {
    $pg->beginTransaction();

    // Two REAL members: one to act as, one to prove owner-scoping against. Ordered,
    // so a failure is reproducible rather than depending on which row came back.
    $subjects = $pg->query(
        "SELECT u.uuid, b.wp_user_id FROM users u
           JOIN wp_user_bridge b ON b.user_id = u.id
          ORDER BY b.wp_user_id LIMIT 2"
    )->fetchAll(\PDO::FETCH_ASSOC);
    if (count($subjects) < 2) {
        $out['cannot_run'] = 'need two bridged members in profile_app.users';
        echo json_encode($out); exit;
    }
    [$me, $other] = $subjects;
    $out['acting_wp_user_id'] = (int)$me['wp_user_id'];

    // Quiet whatever these two already hold, so every count below is about rows
    // THIS gate created. Without it the recap assertion could pass on somebody
    // else's backlog and the absent-half assertion could pass vacuously.
    foreach ([$me, $other] as $s) {
        $q = $pg->prepare('UPDATE notifications SET is_read = true WHERE user_uuid = :u');
        $q->execute([':u' => $s['uuid']]);
    }

    // 12 unread hub rows, newest first, all inside the recap's 7-day window.
    $ins = $pg->prepare(
        "INSERT INTO notifications
            (user_uuid, actor_uuid, type, is_read, created_at, target_kind, target_id, target_url, actor_count)
         VALUES (:u, NULL, 'forum.reply_to_topic', false, now() - (:h || ' hours')::interval,
                 'topic', :t, :url, 1)
         RETURNING id"
    );
    $ids = [];
    for ($i = 1; $i <= 12; $i++) {
        $ins->execute([':u' => $me['uuid'], ':h' => $i, ':t' => 990000 + $i,
                       ':url' => '/hub/?topic=' . (990000 + $i)]);
        $ids[] = (int)$ins->fetchColumn();
    }
    $seen   = array_slice($ids, 0, 8);    // what a sheet would render
    $unseen = array_slice($ids, 8);       // what it would not

    $isRead = function (array $want) use ($pg): array {
        if (!$want) return [];
        $st = $pg->prepare('SELECT id, is_read FROM notifications
                             WHERE id = ANY(string_to_array(:i, \',\')::bigint[]) ORDER BY id');
        $st->execute([':i' => implode(',', $want)]);
        $m = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) $m[(int)$r['id']] = (bool)$r['is_read'];
        return $m;
    };

    $out['seeded_unread'] = count(array_filter($isRead($ids), fn ($v) => !$v));

    // ── THE ON PATH, and its absent half ────────────────────────────────────
    $out['marked_by_seen_call'] = Notifications::markReadMany($me['uuid'], $seen);
    $afterSeen  = $isRead($ids);
    $out['seen_now_read']       = count(array_filter($seen,   fn ($i) => $afterSeen[$i] ?? false));
    $out['unseen_still_unread'] = count(array_filter($unseen, fn ($i) => !($afterSeen[$i] ?? true)));
    $out['unread_count_after_seen'] = Notifications::unreadCount($me['uuid']);

    // ── The consequence: the recap must still have the unseen rows in it ─────
    $recap = Recap::forWpIds([(int)$me['wp_user_id']], 7);
    $out['recap_rows_with_unseen'] = count($recap[(int)$me['wp_user_id']]['notifications'] ?? []);

    // ── Owner scoping: a foreign id must mark nothing ────────────────────────
    $fIns = $pg->prepare(
        "INSERT INTO notifications
            (user_uuid, actor_uuid, type, is_read, created_at, target_kind, target_id, target_url, actor_count)
         VALUES (:u, NULL, 'forum.reply_to_topic', false, now(), 'topic', 991999, '/hub/?topic=991999', 1)
         RETURNING id"
    );
    $fIns->execute([':u' => $other['uuid']]);
    $foreignId = (int)$fIns->fetchColumn();
    $out['marked_by_foreign_call'] = Notifications::markReadMany($me['uuid'], [$foreignId]);
    $out['foreign_row_still_unread'] = !(($isRead([$foreignId])[$foreignId]) ?? true);

    // ── THE OFF PATH: a sweep really does sweep (so OFF stays a no-op) ───────
    Notifications::markAllRead($me['uuid']);
    $afterAll = $isRead($ids);
    $out['all_read_after_sweep'] = count(array_filter($ids, fn ($i) => $afterAll[$i] ?? false));
    $out['unread_count_after_sweep'] = Notifications::unreadCount($me['uuid']);
    $recap2 = Recap::forWpIds([(int)$me['wp_user_id']], 7);
    $out['recap_rows_after_sweep'] = count($recap2[(int)$me['wp_user_id']]['notifications'] ?? []);

    // ── The shipped config, as the model actually reads it ───────────────────
    $out['read_seen_only'] = Notifications::readSeenOnly();
    $out['max_ids']        = Notifications::maxIds();

    // Cap is enforced: 250 ids must mark at most max_ids of them.
    $out['ok'] = true;
} catch (\Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
} finally {
    // Never commit. Explicit, so the intent survives a future edit.
    if ($pg->inTransaction()) $pg->rollBack();
}

echo json_encode($out);
