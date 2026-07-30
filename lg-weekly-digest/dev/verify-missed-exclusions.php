<?php
/**
 * verify-missed-exclusions.php — "what you missed", not "your week".
 *
 *   sudo -u profile-app php <this file>        (needs the profile_app PG role)
 *
 * Asserts that the EDGE's live status — not `is_read` — decides whether a connection
 * request is listed. Runs against the real store, names the real rows it excludes,
 * and checks a pending control is still shown: an exclusion that drops everything is
 * not a passing test.
 *
 * ── IT NEEDS A DIFFERENT RUNNER FROM THE REST OF THE SUITE ───────────────────
 * `sudo -u profile-app php`, NOT wp-cli. It talks to Postgres directly through
 * profile-app's own config, and the WP pool (`looth-dev`) holds zero grants on
 * `profile_app`. Run under wp-cli it fatals on the first query. run-suite.sh knows
 * this and invokes it correctly; that is why the runner is per-test.
 *
 * ── TWO DEFECTS FIXED 2026-07-29 ─────────────────────────────────────────────
 * 1. **IT COULD NEVER FAIL A SUITE.** It printed OK/FAIL inline and always exited 0.
 *    A test that cannot report failure to its caller is decoration. It now counts
 *    failures, prints a sentinel, and exits non-zero.
 * 2. It asserted the OLD rule. `is_read` no longer suppresses a connection request at
 *    all (Ian, 2026-07-28) — the two-register design needs a RESOLVED signal, not a
 *    SEEN one, to know when to stop counting. So the decisive new assertion is the
 *    third block: **a request that has been READ but is still PENDING must STILL be
 *    listed.** That is the case `bottom-nav.js:1128` creates every time someone
 *    glances at the mobile notification sheet, and getting it wrong silences a
 *    member's whole digest now that empty means no email.
 *
 * Read-only. No writes, no mail, no network.
 */
require_once '/srv/profile-app/config.php';
require_once '/home/ubuntu/worktrees/weekly-digest-recap/profile-app/src/Recap.php';
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Recap;

$fail = 0;

// Members holding a connection_request whose edge is ALREADY actioned. Note there is
// no is_read predicate: under the 2026-07-28 ruling the edge decides on its own.
$st = Db::pg()->query("
  SELECT b.wp_user_id, u.display_name, n.id notif_id, c.status, n.is_read
    FROM notifications n
    JOIN connections c ON c.id = n.connection_id
    JOIN users u ON u.uuid = n.user_uuid
    LEFT JOIN wp_user_bridge b ON b.user_id = u.id
   WHERE n.type = 'connection_request' AND c.status <> 'pending' LIMIT 25");
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
    if ($leaked) { $fail++; }
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
$shown = in_array('connection_request', $types, true);
printf("      recap types: %-46s %s\n", implode(',', $types), $shown ? 'OK - still shown' : 'FAIL - wrongly excluded');
if (!$shown) { $fail++; }

// ── THE ASSERTION THE 2026-07-28 RULING ADDED, AND THE ONE MOST WORTH KEEPING ──
// A request the member has LOOKED AT but not ANSWERED must still be listed. Reading
// is not resolving. The mobile notification sheet auto-marks every row read 700ms
// after it opens (bottom-nav.js:1128), so this is not a rare state — it is what
// happens to anyone who glances at their phone. If is_read ever creeps back into the
// suppression, this is the assertion that catches it.
$st = Db::pg()->query("
  SELECT b.wp_user_id FROM notifications n
    JOIN connections c ON c.id = n.connection_id
    JOIN users u ON u.uuid = n.user_uuid JOIN wp_user_bridge b ON b.user_id = u.id
   WHERE n.is_read = true AND n.type='connection_request' AND c.status='pending' LIMIT 1");
$wp_read = (int)$st->fetchColumn();
echo "\nREAD but still PENDING - reading is not resolving\n";
if (!$wp_read) {
    // Do NOT report this as a pass. The check simply did not run.
    echo "      NO SUCH ROW ON THIS BOX - assertion NOT EXERCISED (not a pass).\n";
    echo "      On live 2026-07-28 there were 5 such rows across 3 members.\n";
} else {
    $types = array_column(Recap::forWpIds([$wp_read], 3650)[$wp_read]['notifications'] ?? [], 'type');
    $shown = in_array('connection_request', $types, true);
    printf("      wp:%-5d recap types: %-38s %s\n", $wp_read, implode(',', $types) ?: '(none)',
        $shown ? 'OK - still shown despite being read' : 'FAIL - is_read wrongly suppressed a live to-do');
    if (!$shown) { $fail++; }
}

echo $fail ? "\n$fail FAILED\n" : "\nEDGE STATUS IS THE AUTHORITY\n";
exit($fail ? 1 : 0);
