<?php
/**
 * measure-empty-prevalence.php — how often is the section empty?
 *
 *   sudo -u profile-app php <this file>        (needs the profile_app PG role)
 *
 * Ian's refinement makes the EMPTY section the common case rather than an edge
 * case, so this measures it across the real weekly list instead of assuming.
 * At the shipping 7-day window on dev2: 99.8% of subscribers get no section.
 * Reads /tmp/lg-wdr/out/list3-ids.json (the list-3 wp ids, dumped via wp-cli).
 *
 * Read-only. Counts only — no bodies, no names.
 */
require_once '/srv/profile-app/config.php';
require_once '/home/ubuntu/worktrees/weekly-digest-recap/profile-app/src/Recap.php';
use Looth\ProfileApp\Recap;
$ids = json_decode(file_get_contents('/tmp/lg-wdr/out/list3-ids.json'), true);
foreach ([7, 30, 365] as $days) {
    $with = 0; $without = 0; $rows = 0;
    foreach (array_chunk($ids, 400) as $chunk) {
        foreach (Recap::forWpIds($chunk, $days) as $wp => $r) {
            $n = count($r['notifications']) + count($r['dms']);
            if ($n) { $with++; $rows += $n; } else { $without++; }
        }
    }
    $tot = $with + $without;
    printf("window %3dd : %4d get a section (%4.1f%%),  %4d get NOTHING (%4.1f%%),  %d rows total\n",
        $days, $with, $tot?100*$with/$tot:0, $without, $tot?100*$without/$tot:0, $rows);
}
