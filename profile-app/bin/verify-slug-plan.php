<?php
declare(strict_types=1);

/**
 * verify-slug-plan.php — independent safety check on a slug backfill plan.
 *
 * WHY THIS IS A SEPARATE PROGRAM
 * ------------------------------
 * backfill-slugs.php already avoids collisions. This re-checks its OUTPUT without
 * reusing any of its logic, because "the tool that made the plan says the plan is
 * fine" is not evidence. Both would have to be wrong the same way for a bad handle
 * to get through, and they share no code path here.
 *
 * WHY IT MUST BE RUN AGAIN AT APPLY TIME
 * --------------------------------------
 * `--apply` does NOT read the dry-run report. It re-derives from the database at the
 * moment it runs. So a report verified on Monday says nothing about what Friday's
 * apply will write: members join, members rename, and a handle that was free becomes
 * taken. The pre-flight in docs/atlas/SLUG-BACKFILL-LIVE-APPLY.md therefore generates
 * a FRESH plan and runs this against it. Comparing summary COUNTS is not enough — the
 * totals can match while the individual rows have moved underneath them.
 *
 * Three ways an apply can hurt a member, tested separately:
 *   A  proposal is a handle SOMEONE ELSE already holds  -> we steal it, or hit the
 *      unique index and fail the member
 *   B  two members in the same run get the same handle   -> the plan is internally
 *      inconsistent; one of them loses
 *   C  proposal is a RETIRED handle in slug_history      -> every old link pointing at
 *      its former owner silently starts following someone else. This is the quiet one:
 *      nothing errors, the member just inherits a stranger's inbound links.
 *   D  malformed shape                                   -> an embarrassing URL
 *
 * Usage — against the live database (the apply-time check):
 *   sudo -u profile-app php bin/backfill-slugs.php --scope=repair --db-only --tsv=/tmp/plan.tsv
 *   sudo -u profile-app php bin/verify-slug-plan.php --plan=/tmp/plan.tsv
 *
 * Usage — offline, against an export (checking a report on another box):
 *   php bin/verify-slug-plan.php --plan=<plan.tsv> --owners-tsv=<owners.tsv>
 *
 * Exits 0 only if A-D are all empty. Any finding exits 1 — it is meant to gate a run.
 */

$PLAN = null; $OWNERS = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--plan='))       $PLAN   = substr($a, 7);
    if (str_starts_with($a, '--owners-tsv=')) $OWNERS = substr($a, 13);
}
if ($PLAN === null) {
    fwrite(STDERR, "usage: verify-slug-plan.php --plan=<plan.tsv> [--owners-tsv=<owners.tsv>]\n");
    exit(2);
}

$readTsv = function (string $path): array {
    $fh = fopen($path, 'r');
    if ($fh === false) { fwrite(STDERR, "cannot read $path\n"); exit(2); }
    $head = fgetcsv($fh, 0, "\t", '"', '\\');
    $rows = [];
    while (($r = fgetcsv($fh, 0, "\t", '"', '\\')) !== false) {
        if ($r === [null] || $r === false) continue;
        $rows[] = array_combine($head, array_pad(array_slice($r, 0, count($head)), count($head), ''));
    }
    fclose($fh);
    return $rows;
};

// ── ownership: every handle held by anyone, live or retired ──────────────────
// Offline reads an export; online goes to Postgres through the app's own config so
// there are no second credentials to keep in step. Archived and unbridged rows COUNT:
// a ghost that cannot log in still squats on its handle.
$ownerOf = [];   // lower(slug) => [user_id]
$retired = [];   // lower(slug) => user_id
if ($OWNERS !== null) {
    foreach ($readTsv($OWNERS) as $r) {
        $ownerOf[strtolower(trim((string) $r['slug']))][] = (int) $r['owner_id'];
    }
    $source = 'export ' . basename($OWNERS) . ' (retired handles included only if that export had them)';
} else {
    require dirname(__DIR__) . '/config.php';
    $pg = \Looth\ProfileApp\Db::pg();
    foreach ($pg->query('SELECT id, lower(slug) s FROM users WHERE slug IS NOT NULL AND slug <> \'\'') as $r) {
        $ownerOf[$r['s']][] = (int) $r['id'];
    }
    foreach ($pg->query('SELECT user_id, lower(slug) s FROM slug_history') as $r) {
        $ownerOf[$r['s']][] = (int) $r['user_id'];
        $retired[$r['s']]   = (int) $r['user_id'];
    }
    $source = 'LIVE database (users + slug_history)';
}

$plan = [];
foreach ($readTsv($PLAN) as $r) {
    $p = strtolower(trim((string) ($r['proposed'] ?? '')));
    if ($p === '') continue;                       // ruling rows propose nothing
    $plan[] = ['id' => (int) $r['user_id'], 'slug' => $p, 'name' => (string) ($r['name'] ?? '')];
}

printf("plan      : %s (%d rows carrying a proposal)\n", basename($PLAN), count($plan));
printf("ownership : %s (%d handles held)\n\n", $source, count($ownerOf));

// A — taken by someone else
$A = [];
foreach ($plan as $p) {
    $others = array_diff($ownerOf[$p['slug']] ?? [], [$p['id']]);
    if ($others) $A[] = "member {$p['id']} ({$p['name']}) -> /u/{$p['slug']}  already held by " . implode(',', $others);
}
// B — two members want the same handle
$seen = [];
foreach ($plan as $p) $seen[$p['slug']][] = $p['id'];
$B = [];
foreach ($seen as $s => $ids) if (count($ids) > 1) $B[] = "/u/$s wanted by " . implode(',', $ids);
// C — lands on a retired handle belonging to someone else
$C = [];
foreach ($plan as $p) {
    if (isset($retired[$p['slug']]) && $retired[$p['slug']] !== $p['id']) {
        $C[] = "member {$p['id']} ({$p['name']}) -> /u/{$p['slug']}  RETIRED by user {$retired[$p['slug']]}";
    }
}
// D — shapes we would be embarrassed to put in a URL
$D = [];
foreach ($plan as $p) {
    $s = $p['slug'];
    if ($s === '' || strlen($s) < 2 || $s[0] === '-' || substr($s, -1) === '-'
        || str_contains($s, '--') || preg_match('/[^a-z0-9-]/', $s)) {
        $D[] = "member {$p['id']} -> /u/$s";
    }
}
// E — advisory only. A trailing digit is a defect ONLY if we invented it to break a
// tie; "Diamondboat 1" is a member's actual name and their handle should keep it.
// Reported, never fatal, because this check cannot tell the two apart on its own.
$E = [];
foreach ($plan as $p) {
    if (preg_match('/-\d+$/', $p['slug'])) $E[] = "member {$p['id']} ({$p['name']}) -> /u/{$p['slug']}";
}

$show = function (string $label, array $rows, bool $fatal) {
    printf("%s : %d%s\n", $label, count($rows), $fatal ? '' : '   (advisory)');
    foreach (array_slice($rows, 0, 20) as $r) echo "     $r\n";
    if (count($rows) > 20) printf("     … and %d more\n", count($rows) - 20);
};
$show('A. proposal already held by ANOTHER user', $A, true);
$show('B. two members proposed the SAME handle ', $B, true);
// Offline, an owners export is a flat list of held handles — it does not say which were
// retired. So C cannot fire, and printing "C: 0" would read as "checked and clean" when
// the truth is "not tested". A retired handle is still in that file, so A catches it;
// say so rather than showing a zero that means nothing.
if ($OWNERS !== null) {
    echo "C. proposal lands on a RETIRED handle    : n/a offline — the owners export does not\n"
       . "     distinguish retired from live handles, so this case is caught by A instead.\n";
} else {
    $show('C. proposal lands on a RETIRED handle   ', $C, true);
}
$show('D. malformed slug shape                 ', $D, true);
$show('E. proposal ends in -<digits>           ', $E, false);
if ($E) {
    echo "     ^ check each against the member's NAME. Digits the member actually has are\n"
       . "       theirs to keep; a suffix WE minted to break a tie violates the contract.\n";
}

$bad = count($A) + count($B) + count($C) + count($D);
echo "\nVERDICT: " . ($bad ? "BLOCKED — $bad member-harming conflict(s). Do NOT --apply."
                           : "no member-harming conflict found.") . "\n";
exit($bad ? 1 : 0);
