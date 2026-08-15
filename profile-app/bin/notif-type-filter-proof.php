<?php
/**
 * BACKLOG 11.6 red-first proof — runs against the REAL store methods.
 * Creates a PER-RUN probe member (PID-keyed) with two types of notification,
 * plus a BYSTANDER member, then proves a typed clear touches only the named
 * type and only the caller. Repairs on ENTRY and deletes on exit.
 */
require __DIR__ . '/../config.php';
use Looth\ProfileApp\Db; use Looth\ProfileApp\Notifications; use Looth\ProfileApp\Flags;

$pid = getmypid();
$pg  = Db::pg();
$mk = function(string $tag) use ($pg, $pid) {
    $slug = "b116-probe-$pid-$tag";
    $pg->prepare("DELETE FROM users WHERE slug = :s")->execute([':s'=>$slug]); // repair on ENTRY
    $st = $pg->prepare("INSERT INTO users (uuid, display_name, slug, primary_email)
                        VALUES (gen_random_uuid(), :n, :s, :e) RETURNING uuid");
    $st->execute([':n'=>"B116 Probe $tag", ':s'=>$slug, ':e'=>$slug.'@probe.invalid']);
    return $st->fetchColumn();
};
// notifications_target_shape: anything outside the three connection/message types
// must carry target_kind + target_id + target_url. Satisfy the real constraint
// rather than only inserting the shapes that happen to be exempt.
// uq_notifications_target_unread dedups on (user, type, target_kind, target_id,
// anchor) — a member gets ONE row per post per type, so the probe varies target_id.
$seq = 0;
$note = function(string $uuid, string $type) use ($pg, &$seq) {
    $seq++;
    $bare = in_array($type, ['message','connection_request','connection_accept'], true);
    $pg->prepare("INSERT INTO notifications (user_uuid, type, is_read, target_kind, target_id, target_url)
                  VALUES (:u, :t, false, :k, :i, :url)")
       ->execute([':u'=>$uuid, ':t'=>$type,
                  ':k'=>$bare?null:'post', ':i'=>$bare?null:$seq, ':url'=>$bare?null:"/probe/$seq"]);
};
$count = function(string $uuid, ?string $type=null) use ($pg) {
    $sql = "SELECT count(*) FROM notifications WHERE user_uuid = :u" . ($type ? " AND type = :t" : "");
    $st = $pg->prepare($sql); $st->bindValue(':u',$uuid); if ($type) $st->bindValue(':t',$type);
    $st->execute(); return (int)$st->fetchColumn();
};

$me = null; $other = null;
try {
    $me = $mk('me'); $other = $mk('bystander');
    foreach ([1,2,3] as $_) $note($me, 'connection_accept');
    foreach ([1,2]   as $_) $note($me, 'reaction.on_post');
    $note($other, 'connection_accept');                       // the bystander's row

    printf("  setup: me=3 connection_accept + 2 reaction.on_post, bystander=1 connection_accept\n");

    // RED-FIRST: with the flag OFF the endpoint must refuse a typed bulk. The STORE
    // method is flag-agnostic by design (the endpoint gates it), so here we prove the
    // SQL scoping, then the endpoint gating is asserted separately by the gate.
    Flags::forTest('notifications', ['filter_and_bulk_by_type' => true, 'dismiss_instead_of_delete' => false]);

    $n = Notifications::deleteAllOfType($me, 'connection_accept');
    printf("  cleared connection_accept -> %d rows\n", $n);
    printf("  me connection_accept  now %d   %s\n", $count($me,'connection_accept'), $count($me,'connection_accept')===0?'OK (gone)':'*** FAIL ***');
    printf("  me reaction.on_post   now %d   %s\n", $count($me,'reaction.on_post'),  $count($me,'reaction.on_post')===2?'OK (untouched)':'*** FAIL ***');
    printf("  BYSTANDER still has   %d      %s\n", $count($other), $count($other)===1?'OK (untouched)':'*** FAIL — CROSS-MEMBER LEAK ***');

    // an unknown type must clear NOTHING, never widen
    $n2 = Notifications::deleteAllOfType($me, 'not_a_real_type');
    printf("  unknown type cleared  %d      %s\n", $n2, ($n2===0 && $count($me)===2)?'OK (refused, nothing widened)':'*** FAIL ***');

    // the filtered list must narrow, not fall back
    $all  = Notifications::listFor($me, 30, 0, null);
    $only = Notifications::listFor($me, 30, 0, 'reaction.on_post');
    $none = Notifications::listFor($me, 30, 0, 'forum.mention');
    printf("  listFor all=%d  reaction=%d  mention=%d   %s\n", count($all), count($only), count($none),
        (count($all)===2 && count($only)===2 && count($none)===0)?'OK':'*** FAIL ***');
} finally {
    foreach (array_filter([$me,$other]) as $u) {
        $pg->prepare("DELETE FROM notifications WHERE user_uuid = :u")->execute([':u'=>$u]);
        $pg->prepare("DELETE FROM users WHERE uuid = :u")->execute([':u'=>$u]);
    }
    echo "  probe rows deleted\n";
}
