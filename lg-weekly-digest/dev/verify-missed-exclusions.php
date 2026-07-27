<?php
/**
 * verify-missed-exclusions.php — "what you missed", not "your week".
 *
 *   sudo -u profile-app php <this file>        (needs the profile_app PG role)
 *
 * Asserts the exclusion that is NOT free from is_read: a connection notification
 * stays unread in the bell even after the member has gone to the profile and
 * accepted, so the EDGE's live status is the authority. Runs against the real
 * store and names the real rows it excludes, then checks a pending control is
 * still shown — an exclusion that drops everything is not a passing test.
 *
 * Read-only.
 */
require_once '/srv/profile-app/config.php';
require_once '/home/ubuntu/worktrees/weekly-digest-recap/profile-app/src/Recap.php';
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Recap;

// The members holding an unread connection_request whose edge is ALREADY accepted.
$st = Db::pg()->query("
  SELECT b.wp_user_id, u.display_name, n.id notif_id, c.status
    FROM notifications n
    JOIN connections c ON c.id = n.connection_id
    JOIN users u ON u.uuid = n.user_uuid
    LEFT JOIN wp_user_bridge b ON b.user_id = u.id
   WHERE n.is_read = false AND n.type = 'connection_request' AND c.status <> 'pending'");
$stale = $st->fetchAll();

echo "stale unread connection_request rows (edge already actioned): " . count($stale) . "\n";
foreach ($stale as $r) {
    $wp = (int)$r['wp_user_id'];
    printf("  notif #%-4s wp:%-5s %-34s edge=%s\n", $r['notif_id'], $wp ?: '-', substr($r['display_name'],0,32), $r['status']);
    if (!$wp) { echo "      (unbridged - not a digest recipient)\n"; continue; }
    $out = Recap::forWpIds([$wp], 3650);          // window wide open, so ONLY the new rule can exclude it
    $types = array_column($out[$wp]['notifications'] ?? [], 'type');
    $leaked = in_array('connection_request', $types, true);
    printf("      recap types: %-46s %s\n", $types ? implode(',', $types) : '(none)',
        $leaked ? 'FAIL - stale request still listed' : 'OK - excluded');
}

// Control: a member with a genuinely PENDING request must still see it.
$st = Db::pg()->query("
  SELECT b.wp_user_id FROM notifications n
    JOIN connections c ON c.id = n.connection_id
    JOIN users u ON u.uuid = n.user_uuid JOIN wp_user_bridge b ON b.user_id = u.id
   WHERE n.is_read = false AND n.type='connection_request' AND c.status='pending' LIMIT 1");
$wp = (int)$st->fetchColumn();
$out = Recap::forWpIds([$wp], 3650);
$types = array_column($out[$wp]['notifications'] ?? [], 'type');
echo "\ncontrol - member with a PENDING request (wp:$wp)\n";
printf("      recap types: %-46s %s\n", implode(',', $types),
    in_array('connection_request', $types, true) ? 'OK - still shown' : 'FAIL - wrongly excluded');
