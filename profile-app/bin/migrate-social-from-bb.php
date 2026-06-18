<?php
declare(strict_types=1);

/**
 * SOCIAL crib — seed connections + messaging from BuddyPress, ONE pass, history
 * preserved. Joins the slice-4 crib (bin/migrate-crib-slice4.php). CUT-DAY-REQUIRED.
 * Plan: docs/plan-profile-2.0-social-layer.md §3.
 *
 * RUNS ONLY AFTER sql/2026-05-30-social-layer.sql is reviewed dev-final + applied.
 * Reads BB MySQL (unix socket, LG_PROFILE_APP_MYSQL_DB), maps wp_user_id →
 * users.uuid via wp_user_bridge ⋈ users (NOT a users.wp_user_id column).
 *
 * RULINGS (Ian, 2026-05-30): MUTUAL-ONLY connections — wp_bp_follow is NOT
 * migrated. Notifications start FRESH (no BB port); --seed-notifications optionally
 * raises current-unread DM + pending-request bells at cut so it isn't empty.
 *
 * Idempotent re-runs:
 *   - messages/threads via bp_thread_id / bp_message_id UNIQUE (ON CONFLICT DO NOTHING).
 *   - recipients via (thread_id,user_uuid) PK.
 *   - connections via the pair (existence check in EITHER direction) — the pair is
 *     the natural key; re-run skips an existing pair (won't flip pending→accepted).
 *
 * Usage:
 *   php bin/migrate-social-from-bb.php                 # DRY RUN (default) — counts only
 *   php bin/migrate-social-from-bb.php --commit        # write connections + messaging
 *   php bin/migrate-social-from-bb.php --commit --seed-notifications
 *   php bin/migrate-social-from-bb.php --thread <bp_thread_id>   # spot-check one thread
 */

require __DIR__ . '/../config.php';

use Looth\ProfileApp\Db;

$COMMIT    = in_array('--commit', $argv, true);
$SEED_NOTIF= in_array('--seed-notifications', $argv, true);
$SPOT      = null;
foreach ($argv as $i => $a) if ($a === '--thread' && isset($argv[$i + 1])) $SPOT = (int)$argv[$i + 1];

fwrite(STDERR, $COMMIT ? "*** COMMIT MODE — writes will be made ***\n" : "(dry-run; pass --commit to write)\n");

$mysqlSocket = '/var/run/mysqld/mysqld.sock';
$mysqlUser   = posix_getpwuid(posix_geteuid())['name'] ?? 'profile-app';
$wp = new PDO('mysql:unix_socket=' . $mysqlSocket . ';dbname=' . LG_PROFILE_APP_MYSQL_DB,
              $mysqlUser, '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                               PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pg = Db::pg();

/* ---- wp_user_id → users.uuid map (bridge ⋈ users) ---------------------------- */
$bridge = [];
foreach ($pg->query('SELECT b.wp_user_id, u.uuid FROM wp_user_bridge b JOIN users u ON u.id = b.user_id')
         as $r) {
    $bridge[(int)$r['wp_user_id']] = $r['uuid'];
}
$uuidOf = static fn (int $wpId): ?string => $bridge[$wpId] ?? null;
fwrite(STDERR, sprintf("bridge: %d wp_user_id → uuid mappings loaded\n", count($bridge)));

/* ---- spot-check mode: dump one thread end-to-end ----------------------------- */
if ($SPOT !== null) {
    $q = $wp->prepare('SELECT id, sender_id, subject, message, date_sent, is_deleted
                         FROM wp_bp_messages_messages WHERE thread_id = :t ORDER BY date_sent, id');
    $q->execute([':t' => $SPOT]);
    echo "=== BB thread $SPOT ===\n";
    foreach ($q as $m) {
        $u = $uuidOf((int)$m['sender_id']);
        printf("  #%d  %s  sender=%s%s  %s\n", $m['id'], $m['date_sent'],
            $u ?? "wp:{$m['sender_id']}(NO BRIDGE)", $m['is_deleted'] ? ' [deleted]' : '',
            substr(str_replace("\n", ' ', (string)$m['message']), 0, 60));
    }
    exit(0);
}

$c = ['fr_total'=>0,'fr_mapped'=>0,'fr_accepted'=>0,'fr_pending'=>0,'fr_skip_bridge'=>0,'fr_skip_exists'=>0,
      'th_total'=>0,'th_inserted'=>0,'msg_total'=>0,'msg_inserted'=>0,'msg_skip_deleted'=>0,'msg_skip_bridge'=>0,
      'rcpt_total'=>0,'rcpt_inserted'=>0,'rcpt_skip_bridge'=>0];

if ($COMMIT) $pg->beginTransaction();

/* ============================ CONNECTIONS ===================================== */
$edgeExists = $pg->prepare(
    'SELECT 1 FROM connections
      WHERE (requester_uuid = :a AND addressee_uuid = :b)
         OR (requester_uuid = :b AND addressee_uuid = :a) LIMIT 1'
);
$insEdge = $pg->prepare(
    'INSERT INTO connections (requester_uuid, addressee_uuid, status, created_at)
     VALUES (:r, :a, :s, :ts)'
);
foreach ($wp->query('SELECT initiator_user_id, friend_user_id, is_confirmed, date_created FROM wp_bp_friends') as $f) {
    $c['fr_total']++;
    $r = $uuidOf((int)$f['initiator_user_id']);
    $a = $uuidOf((int)$f['friend_user_id']);
    if (!$r || !$a || $r === $a) { $c['fr_skip_bridge']++; continue; }
    $c['fr_mapped']++;
    $status = ((int)$f['is_confirmed'] === 1) ? 'accepted' : 'pending';
    $status === 'accepted' ? $c['fr_accepted']++ : $c['fr_pending']++;

    $edgeExists->execute([':a' => $r, ':b' => $a]);
    if ($edgeExists->fetchColumn()) { $c['fr_skip_exists']++; continue; }
    if ($COMMIT) {
        $insEdge->execute([':r' => $r, ':a' => $a, ':s' => $status, ':ts' => $f['date_created']]);
    }
}

/* ============================ MESSAGING ======================================= */
// threads — bp_thread_id → our thread id (subject = earliest message's subject)
$bpToThread = [];
$threadAgg = $wp->query(
    'SELECT thread_id, MIN(date_sent) AS first_at, MAX(date_sent) AS last_at
       FROM wp_bp_messages_messages GROUP BY thread_id'
);
$subjQ = $wp->prepare(
    'SELECT subject FROM wp_bp_messages_messages WHERE thread_id = :t ORDER BY date_sent, id LIMIT 1'
);
$findThread = $pg->prepare('SELECT id FROM message_threads WHERE bp_thread_id = :b');
$insThread  = $pg->prepare(
    'INSERT INTO message_threads (subject, last_message_at, bp_thread_id, created_at)
     VALUES (:subj, :last, :bp, :first) ON CONFLICT (bp_thread_id) DO NOTHING RETURNING id'
);
foreach ($threadAgg as $t) {
    $c['th_total']++;
    $bpId = (int)$t['thread_id'];
    $findThread->execute([':b' => $bpId]);
    $tid = $findThread->fetchColumn();
    if ($tid !== false) { $bpToThread[$bpId] = (int)$tid; continue; }   // already imported
    $subjQ->execute([':t' => $bpId]);
    $subject = $subjQ->fetchColumn() ?: null;
    if ($COMMIT) {
        $insThread->execute([':subj' => $subject, ':last' => $t['last_at'], ':bp' => $bpId, ':first' => $t['first_at']]);
        $newId = $insThread->fetchColumn();
        if ($newId !== false) { $bpToThread[$bpId] = (int)$newId; $c['th_inserted']++; }
    } else {
        $c['th_inserted']++;
    }
}

// messages
$insMsg = $pg->prepare(
    'INSERT INTO messages (thread_id, sender_uuid, body, created_at, bp_message_id)
     VALUES (:t, :s, :b, :ts, :bp) ON CONFLICT (bp_message_id) DO NOTHING'
);
foreach ($wp->query('SELECT id, thread_id, sender_id, message, date_sent, is_deleted FROM wp_bp_messages_messages') as $m) {
    $c['msg_total']++;
    if ((int)$m['is_deleted'] === 1) { $c['msg_skip_deleted']++; continue; }
    $sender = $uuidOf((int)$m['sender_id']);
    if (!$sender) { $c['msg_skip_bridge']++; continue; }
    $tid = $bpToThread[(int)$m['thread_id']] ?? null;
    if ($tid === null) { continue; }   // thread not imported (dry-run, or unmapped)
    if ($COMMIT) {
        $insMsg->execute([':t' => $tid, ':s' => $sender, ':b' => (string)$m['message'],
                          ':ts' => $m['date_sent'], ':bp' => (int)$m['id']]);
    }
    $c['msg_inserted']++;
}

// recipients (sender_only rows kept = sender sees their own thread; is_hidden folded into is_deleted)
$insRcpt = $pg->prepare(
    'INSERT INTO message_recipients (thread_id, user_uuid, unread_count, is_deleted)
     VALUES (:t, :u, :unread, :del) ON CONFLICT (thread_id, user_uuid) DO NOTHING'
);
foreach ($wp->query('SELECT user_id, thread_id, unread_count, is_deleted, is_hidden FROM wp_bp_messages_recipients') as $rc) {
    $c['rcpt_total']++;
    $u = $uuidOf((int)$rc['user_id']);
    if (!$u) { $c['rcpt_skip_bridge']++; continue; }
    $tid = $bpToThread[(int)$rc['thread_id']] ?? null;
    if ($tid === null) { continue; }
    $del = ((int)$rc['is_deleted'] === 1 || (int)$rc['is_hidden'] === 1);
    if ($COMMIT) {
        $insRcpt->execute([':t' => $tid, ':u' => $u, ':unread' => (int)$rc['unread_count'], ':del' => $del ? 'true' : 'false']);
    }
    $c['rcpt_inserted']++;
}

if ($COMMIT) $pg->commit();

/* ============================ NOTIFICATION SEED (opt-in, cut-time) ============ */
if ($SEED_NOTIF && $COMMIT) {
    $seeded = seedNotifications($pg);
    fwrite(STDERR, sprintf("seeded notifications: %d message, %d connection_request\n", $seeded['msg'], $seeded['req']));
}

/* ============================ REPORT ========================================== */
printf("\n=== SOCIAL CRIB %s ===\n", $COMMIT ? 'COMMIT' : 'DRY RUN');
printf("CONNECTIONS  bb=%d mapped=%d (accepted=%d pending=%d) skip_bridge=%d skip_exists=%d\n",
    $c['fr_total'], $c['fr_mapped'], $c['fr_accepted'], $c['fr_pending'], $c['fr_skip_bridge'], $c['fr_skip_exists']);
printf("THREADS      bb=%d new=%d\n", $c['th_total'], $c['th_inserted']);
printf("MESSAGES     bb=%d new=%d skip_deleted=%d skip_bridge=%d\n",
    $c['msg_total'], $c['msg_inserted'], $c['msg_skip_deleted'], $c['msg_skip_bridge']);
printf("RECIPIENTS   bb=%d new=%d skip_bridge=%d\n", $c['rcpt_total'], $c['rcpt_inserted'], $c['rcpt_skip_bridge']);
printf("\nSnapshot expectation: ~1,881 msgs / 370 threads / 219 senders + ~10,978 friend edges (7,346 accepted).\n");
if (!$COMMIT) fwrite(STDERR, "\n(dry-run complete — no writes. Re-run with --commit after dev-FINAL sign-off.)\n");

/**
 * Seed CURRENT-UNREAD bells (Ian's ruling) so the bell isn't empty at cut:
 *  - one 'message' notification per recipient row with unread_count>0
 *    (actor = the thread's most-recent sender)
 *  - one 'connection_request' per pending connection (actor = requester)
 * Uses the same dedup upsert targets as Notifications::push().
 */
function seedNotifications(PDO $pg): array
{
    $msg = $pg->exec(
        "INSERT INTO notifications (user_uuid, actor_uuid, type, thread_id)
         SELECT mr.user_uuid,
                (SELECT m.sender_uuid FROM messages m
                  WHERE m.thread_id = mr.thread_id ORDER BY m.created_at DESC LIMIT 1),
                'message', mr.thread_id
           FROM message_recipients mr
          WHERE mr.unread_count > 0 AND mr.is_deleted = false
         ON CONFLICT (user_uuid, thread_id) WHERE type = 'message' DO NOTHING"
    );
    $req = $pg->exec(
        "INSERT INTO notifications (user_uuid, actor_uuid, type, connection_id)
         SELECT addressee_uuid, requester_uuid, 'connection_request', id
           FROM connections WHERE status = 'pending'
         ON CONFLICT (user_uuid, connection_id) WHERE connection_id IS NOT NULL DO NOTHING"
    );
    return ['msg' => (int)$msg, 'req' => (int)$req];
}
