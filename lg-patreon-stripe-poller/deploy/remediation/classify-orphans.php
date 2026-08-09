<?php
/**
 * Offline replica of Arbiter + RoleSourceWriter + PatreonSourceReader, run over
 * the live-exported orphan rows, to classify what deleting each source=stripe
 * row would actually do to the member's effective tier.
 *
 * Replicated from (branch stripe-build):
 *   src/Arbiter.php::sync / computeWinningTier
 *   src/RoleSourceWriter.php::readAllForUser
 *   src/Patreon/PatreonSourceReader.php::readForUser
 */
declare(strict_types=1);

// live wp_options lgpo_tier_map, read 2026-08-08
$TIER_MAP = [
    10455112 => 'looth1', 24295274 => 'looth4', 5735635 => 'looth2',
    7757819  => 'looth2', 22199086 => 'looth2', 6401900 => 'looth3',
    8603192  => 'looth3', 9220984  => 'looth3', 9517681 => 'looth3',
    22226742 => 'looth3', 7757908  => 'looth3', 22207438 => 'looth3',
    5735762  => 'looth3', 25496422 => 'looth3', 25496403 => 'looth3',
];
const TIER_ROLES = ['looth1','looth2','looth3','looth4'];

function computeWinningTier(array $sources): ?string {
    if ($sources === []) return null;
    $best = null;
    foreach ($sources as $tier) {
        if ($tier === null) continue;
        if (!in_array($tier, TIER_ROLES, true)) continue;
        if ($best === null || strcmp($tier, $best) > 0) $best = $tier;
    }
    return $best ?? 'looth1';
}

/** Arbiter's post-sync role set, given current roles and a winning tier. */
function applyArbiter(array $roles, ?string $winning): array {
    $out = $roles;
    foreach (TIER_ROLES as $role) {
        if ($role === $winning) continue;
        if ($role === 'looth1' && $winning === null) continue;
        $out = array_values(array_diff($out, [$role]));
    }
    if ($winning !== null && !in_array($winning, $out, true)) $out[] = $winning;
    return $out;
}

function highestTier(array $roles): ?string {
    $best = null;
    foreach (TIER_ROLES as $r) if (in_array($r, $roles, true)) if ($best===null||strcmp($r,$best)>0) $best=$r;
    return $best;
}

$rows = [];
foreach (file($argv[1], FILE_IGNORE_NEW_LINES) as $line) {
    if ($line === '') continue;
    $f = explode("\t", $line);
    $n = fn($v) => ($v === '\\N' || $v === '\\\\N') ? null : $v;
    $rows[] = [
        'uid'        => (int)$f[0],
        'stripe'     => $n($f[1]),
        'email'      => $f[2],
        'pat_tier'   => $n($f[3]),
        'pat_rows'   => (int)$f[4],
        'man_tier'   => $n($f[5]),
        'man_rows'   => (int)$f[6],
        'all_src'    => explode(',', $f[7]),
        'pay_src'    => $n($f[8]),
        'pat_tid'    => $n($f[9]),
        'caps'       => $n($f[10]),
        'patron_st'  => $n($f[11]),
        'cents'      => $n($f[12]),
        'label'      => $n($f[13]),
        'updated'    => $f[14],
    ];
}

$classes = [];
foreach ($rows as $r) {
    // current roles from wp_capabilities
    $roles = [];
    if ($r['caps'] !== null) {
        $caps = @unserialize($r['caps']);
        if (is_array($caps)) $roles = array_keys(array_filter($caps));
    }
    $currentTier = highestTier($roles);

    // ---- build the source map exactly as RoleSourceWriter::readAllForUser does
    $build = function (bool $withStripe) use ($r, $TIER_MAP): array {
        $s = [];
        if ($withStripe) $s['stripe'] = $r['stripe'];
        if ($r['pat_rows'] > 0) $s['patreon'] = $r['pat_tier'];
        if ($r['man_rows'] > 0) $s['manual_admin'] = $r['man_tier'];
        // adapter fallback: only when NO persisted patreon row
        if (!array_key_exists('patreon', $s)) {
            if ($r['pay_src'] === 'patreon') {
                $tier = null;
                if ($r['pat_tid'] !== null && isset($TIER_MAP[(int)$r['pat_tid']])) {
                    $m = $TIER_MAP[(int)$r['pat_tid']];
                    if ($m === 'looth2' || $m === 'looth3') $tier = $m;
                }
                $s['patreon'] = $tier;   // adapter returns a record; tier may be null
            }
        }
        return $s;
    };

    $srcBefore = $build(true);
    $srcAfter  = $build(false);

    // ---- Arbiter guards
    $protected = in_array('looth4', $roles, true);
    $stripeSkip = ($r['pay_src'] === 'stripe' && !in_array('looth1', $roles, true));

    if ($protected) {
        $classes[] = ['r'=>$r,'cur'=>$currentTier,'after'=>$currentTier,'before'=>$currentTier,
                      'class'=>'INERT','why'=>'looth4 protected — Arbiter returns early'];
        continue;
    }
    if ($stripeSkip) {
        $classes[] = ['r'=>$r,'cur'=>$currentTier,'after'=>$currentTier,'before'=>$currentTier,
                      'class'=>'INERT','why'=>'payment_source=stripe skip guard'];
        continue;
    }

    $winBefore = computeWinningTier($srcBefore);
    $winAfter  = computeWinningTier($srcAfter);
    $rolesAfter = applyArbiter($roles, $winAfter);
    $tierAfter  = highestTier($rolesAfter);

    if ($tierAfter === $currentTier) {
        $latent = ($winBefore !== $currentTier);
        $classes[] = ['r'=>$r,'cur'=>$currentTier,'after'=>$tierAfter,'before'=>$winBefore,
                      'class'=> $latent ? 'DEFUSES' : 'INERT',
                      'why'=> $latent
                        ? "latent: stale row would have moved them to ".var_export($winBefore,true)." on next Arbiter run"
                        : 'no effective change'];
    } else {
        $classes[] = ['r'=>$r,'cur'=>$currentTier,'after'=>$tierAfter,'before'=>$winBefore,
                      'class'=>'VISIBLE','why'=>'effective tier changes'];
    }
}

// ---- report
$order = ['VISIBLE'=>0,'DEFUSES'=>1,'INERT'=>2];
usort($classes, fn($a,$b) => [$order[$a['class']],$a['r']['uid']] <=> [$order[$b['class']],$b['r']['uid']]);

$counts = [];
printf("%-6s %-8s %-8s %-8s %-8s %-9s %-16s %-7s %s\n",
  'uid','stripe','current','after','patrow','patron','label/cents','class','why');
echo str_repeat('-',150),"\n";
foreach ($classes as $c) {
    $r = $c['r'];
    $counts[$c['class']] = ($counts[$c['class']] ?? 0) + 1;
    printf("%-6d %-8s %-8s %-8s %-8s %-9s %-16s %-7s %s\n",
        $r['uid'],
        $r['stripe'] ?? 'NULL',
        $c['cur'] ?? '(none)',
        $c['after'] ?? '(NONE)',
        $r['pat_rows'] > 0 ? ($r['pat_tier'] ?? 'row:NULL') : 'no-row',
        $r['patron_st'] ?? '-',
        ($r['label'] ?? '-').'/'.($r['cents'] ?? '-'),
        $c['class'],
        $c['why']);
}
echo str_repeat('-',150),"\n";
foreach ($counts as $k=>$v) echo "$k: $v\n";
echo "TOTAL: ".count($classes)."\n\n";
echo "VISIBLE uids: ".implode(',', array_map(fn($c)=>$c['r']['uid'], array_filter($classes, fn($c)=>$c['class']==='VISIBLE')))."\n";
echo "SAFE uids (INERT+DEFUSES): ".implode(',', array_map(fn($c)=>$c['r']['uid'], array_filter($classes, fn($c)=>$c['class']!=='VISIBLE')))."\n";
