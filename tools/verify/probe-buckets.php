<?php
/* probe-buckets.php — throwaway. Proves each instance really reaches the store and
   can see an event, by asking for the PAST bucket (which the landing page no longer
   renders but the code path still serves). Without this, a "0 upcoming cards" result
   is indistinguishable from a broken DB connection. */
declare(strict_types=1);
$tree = $argv[1] ?? '/home/ubuntu/worktrees/events-fix';
require $tree . '/events/config.php';
require $tree . '/events/lib/events-query.php';

foreach ([false => 'UPCOMING', true => 'PAST'] as $past => $label) {
    $rows = lg_events_list((bool)$past, '');
    printf("%-9s : %d row(s)\n", $label, count($rows));
    foreach ($rows as $r) {
        printf("            id=%d  %s  |  %s\n", $r['id'], $r['when']['line'] ?: '(no date line)', $r['title']);
    }
}
