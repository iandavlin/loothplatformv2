<?php
/**
 * REMEDIATION #4 — retract the orphaned `source=stripe` role opinions.
 * PREPARED FOR LIVE — Ian runs this by hand. NOT wired into any cron.
 *
 * Background (docs/atlas/STRIPE-PHASE0-FINDINGS.md, and the 7/30
 * docs/atlas/STRIPE-MEMBERSHIP-AUDIT.md §5.2 it corrects):
 *
 * 44 rows in `lg_role_sources` carry source='stripe'. Only 3 correspond to a
 * bridged customer. The other 41 are orphans of the decommissioned Apr–Jun
 * alpha: the customers were deleted, the role opinions were not. The Arbiter
 * takes the highest tier across all sources, so a dead opinion keeps voting
 * forever. Nothing in the system ever retracts a source's opinion when its
 * source dies — that is the defect class this script exists to clear.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS NOT "DELETE 41 ROWS"
 * ---------------------------------------------------------------------------
 * A naive delete demotes FOUR ACTIVELY-PAYING MEMBERS, two of them two tiers
 * (looth3 -> looth1, both $11 Looth-Pro). Their persisted `patreon` row says
 * tier=NULL while Patreon says they are paying, and the dead Stripe row is the
 * only thing holding their tier up.
 *
 * The mechanism is in RoleSourceWriter::readAllForUser — it consults the live
 * Patreon adapter ONLY when there is no persisted 'patreon' row:
 *
 *     if ( ! array_key_exists( 'patreon', $out ) ) { ...adapter... }
 *
 * array_key_exists is TRUE for a row whose tier is NULL, so a stale NULL row
 * SHADOWS an adapter that would have said looth2/looth3. "No patreon row" and
 * "patreon row asserting nothing" are opposite situations that look identical
 * to any query that IFNULLs the tier.
 *
 * So each row is classified, and only then acted on:
 *
 *   INERT     effective tier identical before and after -> delete
 *   DEFUSES   invisible today, but the stale row is a loaded upgrade that
 *             fires the next time anything runs the Arbiter -> delete
 *   REPAIR    a paying member whose patreon row is stale -> repair that row to
 *             the tier their live pledge entitles, THEN delete. Only ever done
 *             when the repair is a PROVEN NO-OP (see the four conditions in
 *             classify()); anything else is a real correction and is HELD.
 *   VISIBLE   the member's effective tier really moves -> HELD by default.
 *             Member-visible changes are Ian's, member by member.
 *
 * ---------------------------------------------------------------------------
 * MODES
 * ---------------------------------------------------------------------------
 *   wp eval-file revoke-orphan-stripe-sources.php
 *       REVIEW. Writes nothing. Prints the full per-row plan.
 *
 *   wp eval-file revoke-orphan-stripe-sources.php apply
 *       Apply INERT + DEFUSES + REPAIR. Never touches a VISIBLE row.
 *
 *   wp eval-file revoke-orphan-stripe-sources.php apply allow=1863,1869
 *       Additionally apply the named VISIBLE rows. One member per decision —
 *       this flag takes explicit ids and deliberately has no "all" form.
 *
 *   wp eval-file revoke-orphan-stripe-sources.php revert <batch-id>
 *   wp eval-file revoke-orphan-stripe-sources.php revert          (lists batches)
 *   wp eval-file revoke-orphan-stripe-sources.php verify
 *
 * Overrides, separate so waiving one never silently waives another:
 *   accept-drift   live no longer matches the 2026-08-08 pinned expectation.
 *                  Read the printed diff first — drift on this data means a
 *                  member's entitlement moved.
 *
 * ---------------------------------------------------------------------------
 * SAFETY PROPERTIES
 * ---------------------------------------------------------------------------
 *  - Classification is RECOMPUTED from live at run time. The pinned id lists
 *    below are a staleness tripwire, never the input.
 *  - The journal is written to wp_options BEFORE the first mutation.
 *  - Arbiter::sync() is called ONLY for rows whose effective tier actually
 *    moves. An INERT row is deleted without a sync because by construction
 *    nothing changes — and a needless sync would still fire bp_set_member_type
 *    and the looth_tier_changed hook.
 *  - Nothing is deleted from `customers`, `entitlements` or `wp_user_bridge`.
 *  - No user is created, deleted, or emailed. No Stripe API call is made.
 *  - Idempotent: re-running after a clean pass finds nothing to do.
 */

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

// define() rather than const: the red-first gate includes this file repeatedly
// in one process, and a re-executed `const` warns.
defined( 'ROSS_JOURNAL_PREFIX' ) || define( 'ROSS_JOURNAL_PREFIX', 'lgms_orphan_revoke_journal_' );
defined( 'ROSS_INDEX_OPT' )      || define( 'ROSS_INDEX_OPT', 'lgms_orphan_revoke_batches' );

/**
 * Pinned expectation, measured on live 2026-08-08 22:0x UTC by the stripe-build
 * lane. This is a TRIPWIRE: if live no longer classifies this way, the data has
 * moved since it was ruled on and applying blind would act on a stale decision.
 */
defined( 'ROSS_PINNED' ) || define( 'ROSS_PINNED', [
    'total'   => 41,
    'repair'  => [ 1817, 1840, 1861, 1881 ],
    'visible' => [ 1860, 1862, 1884, 1863, 1869, 1870 ],
    'defuses' => [ 1879 ],
] );

/** Highest looth tier present in a role list. */
$ross_highest = function ( array $roles ): ?string {
    $best = null;
    foreach ( [ 'looth1', 'looth2', 'looth3', 'looth4' ] as $r ) {
        if ( in_array( $r, $roles, true ) && ( $best === null || strcmp( $r, $best ) > 0 ) ) {
            $best = $r;
        }
    }
    return $best;
};

/** Verbatim port of Arbiter::computeWinningTier. */
$ross_winning = function ( array $sources ) {
    if ( $sources === [] ) { return null; }
    $best = null;
    foreach ( $sources as $tier ) {
        if ( $tier === null ) { continue; }
        if ( ! in_array( $tier, [ 'looth1', 'looth2', 'looth3', 'looth4' ], true ) ) { continue; }
        if ( $best === null || strcmp( $tier, $best ) > 0 ) { $best = $tier; }
    }
    return $best ?? 'looth1';
};

/** The role set Arbiter::sync would leave behind for a given winning tier. */
$ross_project = function ( array $roles, $winning ): array {
    $out = $roles;
    foreach ( [ 'looth1', 'looth2', 'looth3', 'looth4' ] as $role ) {
        if ( $role === $winning ) { continue; }
        if ( $role === 'looth1' && $winning === null ) { continue; }
        $out = array_values( array_diff( $out, [ $role ] ) );
    }
    if ( $winning !== null && ! in_array( $winning, $out, true ) ) { $out[] = $winning; }
    return $out;
};

// ---------------------------------------------------------------- preflight

if ( ! class_exists( '\\LGMS\\Db' ) || ! class_exists( '\\LGMS\\Arbiter' ) ) {
    echo "REFUSING: the LGMS\\* namespace is not loaded. Run this through the\n";
    echo "site's own WP bootstrap (the poller mu-plugin must be active).\n";
    return;
}

try {
    $pdo = \LGMS\Db::pdo();
} catch ( \Throwable $e ) {
    echo "REFUSING: cannot reach lg_membership: " . $e->getMessage() . "\n";
    return;
}

$ARGS  = $args ?? [];
$MODE  = 'review';
$BATCH = null;
$ALLOW = [];
$ACCEPT_DRIFT = in_array( 'accept-drift', $ARGS, true );

foreach ( $ARGS as $i => $a ) {
    if ( $a === 'apply' )  { $MODE = 'apply'; }
    if ( $a === 'verify' ) { $MODE = 'verify'; }
    if ( $a === 'revert' ) { $MODE = 'revert'; $BATCH = $ARGS[ $i + 1 ] ?? null; }
    if ( strpos( (string) $a, 'allow=' ) === 0 ) {
        foreach ( explode( ',', substr( $a, 6 ) ) as $id ) {
            $id = (int) trim( $id );
            if ( $id > 0 ) { $ALLOW[] = $id; }
        }
    }
}

// ---------------------------------------------------------------- revert

if ( $MODE === 'revert' ) {
    if ( ! $BATCH ) {
        $index = get_option( ROSS_INDEX_OPT, [] );
        echo "Usage: wp eval-file revoke-orphan-stripe-sources.php revert <batch-id>\n\n";
        if ( empty( $index ) ) { echo "No apply batches on record.\n"; return; }
        echo "Known batches:\n";
        foreach ( $index as $bid ) {
            $j = get_option( ROSS_JOURNAL_PREFIX . $bid, [] );
            printf( "  %s  applied %s  rows=%d  reverted=%s\n",
                $bid, $j['created'] ?? '?', count( $j['entries'] ?? [] ),
                $j['reverted_at'] ? $j['reverted_at'] : 'no' );
        }
        return;
    }

    $j = get_option( ROSS_JOURNAL_PREFIX . $BATCH, null );
    if ( ! $j ) { echo "No journal found for batch '{$BATCH}'.\n"; return; }
    if ( ! empty( $j['reverted_at'] ) ) {
        echo "Batch {$BATCH} was already reverted at {$j['reverted_at']}. Refusing.\n";
        return;
    }

    printf( "=== REVERT batch %s (applied %s, %d rows) ===\n\n",
        $BATCH, $j['created'] ?? '?', count( $j['entries'] ) );

    $n_row = $n_pat = $n_arb = 0;
    foreach ( $j['entries'] as $e ) {
        $uid = (int) $e['wp_user_id'];

        // 1. restore the deleted stripe row, original tier and timestamp
        $st = $pdo->prepare(
            'INSERT INTO lg_role_sources (wp_user_id, source, tier, updated_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE tier = VALUES(tier), updated_at = VALUES(updated_at)'
        );
        $st->execute( [ $uid, 'stripe', $e['stripe_tier'], $e['stripe_updated_at'] ] );
        $n_row++;

        // 2. undo a patreon-row repair, exactly as it was found
        if ( ! empty( $e['patreon_repaired'] ) ) {
            if ( $e['patreon_row_existed'] ) {
                $pdo->prepare( 'UPDATE lg_role_sources SET tier = ? WHERE wp_user_id = ? AND source = ?' )
                    ->execute( [ $e['patreon_tier_before'], $uid, 'patreon' ] );
            } else {
                $pdo->prepare( 'DELETE FROM lg_role_sources WHERE wp_user_id = ? AND source = ?' )
                    ->execute( [ $uid, 'patreon' ] );
            }
            $n_pat++;
        }

        // 3. re-run the Arbiter only where we ran it on the way in
        if ( ! empty( $e['arbiter_ran'] ) ) {
            \LGMS\Arbiter::sync( $uid );
            $n_arb++;
        }
        printf( "  #%-5d stripe row restored (%s)%s\n", $uid,
            $e['stripe_tier'] === null ? 'NULL' : $e['stripe_tier'],
            ! empty( $e['patreon_repaired'] ) ? ', patreon row un-repaired' : '' );
    }

    $j['reverted_at'] = current_time( 'mysql' );
    update_option( ROSS_JOURNAL_PREFIX . $BATCH, $j, false );

    printf( "\nReverted batch %s — stripe rows restored: %d, patreon repairs undone: %d, arbiter re-runs: %d.\n",
        $BATCH, $n_row, $n_pat, $n_arb );
    echo "NOTE: a Patreon sweep that ran between apply and revert may have\n";
    echo "      legitimately rewritten a patreon row since. Re-run `verify`.\n";
    return;
}

// ---------------------------------------------------------------- gather

$tier_map = get_option( 'lgpo_tier_map', [] );
if ( ! is_array( $tier_map ) || $tier_map === [] ) {
    echo "REFUSING: lgpo_tier_map is empty — cannot resolve a Patreon tier id.\n";
    return;
}

$orphans = $pdo->query(
    'SELECT r.wp_user_id, r.tier, r.updated_at
       FROM lg_role_sources r
       LEFT JOIN wp_user_bridge b ON b.wp_user_id = r.wp_user_id
      WHERE r.source = \'stripe\' AND b.wp_user_id IS NULL
      ORDER BY r.wp_user_id'
)->fetchAll( PDO::FETCH_ASSOC );

$bridged = (int) $pdo->query(
    'SELECT COUNT(*) FROM lg_role_sources r
       JOIN wp_user_bridge b ON b.wp_user_id = r.wp_user_id
      WHERE r.source = \'stripe\''
)->fetchColumn();

printf( "=== revoke-orphan-stripe-sources — %s ===\n", strtoupper( $MODE ) );
printf( "source=stripe rows: %d orphaned, %d bridged (bridged are never touched)\n\n",
    count( $orphans ), $bridged );

// ---------------------------------------------------------------- classify

$plan = [];
foreach ( $orphans as $row ) {
    $uid  = (int) $row['wp_user_id'];
    $user = get_user_by( 'id', $uid );

    if ( ! $user ) {
        $plan[] = [ 'uid' => $uid, 'row' => $row, 'class' => 'NOUSER',
                    'why' => 'no such WP user — row is pure debris', 'cur' => null, 'after' => null ];
        continue;
    }

    $roles   = (array) $user->roles;
    $cur     = $ross_highest( $roles );
    $pay_src = get_user_meta( $uid, 'payment_source', true );

    // Persisted rows for this user, other than the stripe row under test.
    $st = $pdo->prepare( 'SELECT source, tier FROM lg_role_sources WHERE wp_user_id = ? AND source <> \'stripe\'' );
    $st->execute( [ $uid ] );
    $persisted = [];
    foreach ( $st->fetchAll( PDO::FETCH_ASSOC ) as $p ) {
        $persisted[ (string) $p['source'] ] = $p['tier'] !== null ? (string) $p['tier'] : null;
    }
    $patreon_row_existed = array_key_exists( 'patreon', $persisted );
    $patreon_tier_before = $patreon_row_existed ? $persisted['patreon'] : null;

    // What PatreonSourceReader would contribute, if consulted.
    $adapter_tier   = null;
    $adapter_speaks = ( $pay_src === 'patreon' );
    if ( $adapter_speaks ) {
        $tid = get_user_meta( $uid, 'lgpo_patreon_tier_id', true );
        if ( is_string( $tid ) && $tid !== '' && isset( $tier_map[ $tid ] ) ) {
            $m = (string) $tier_map[ $tid ];
            if ( $m === 'looth2' || $m === 'looth3' ) { $adapter_tier = $m; }
        }
    }

    $build = function ( bool $with_stripe ) use ( $persisted, $patreon_row_existed, $adapter_speaks, $adapter_tier, $row ) {
        $s = $persisted;
        if ( $with_stripe ) { $s['stripe'] = $row['tier'] !== null ? (string) $row['tier'] : null; }
        if ( ! $patreon_row_existed && $adapter_speaks ) { $s['patreon'] = $adapter_tier; }
        return $s;
    };

    // Arbiter guards that short-circuit before any tier is computed.
    if ( in_array( 'looth4', $roles, true ) ) {
        $plan[] = [ 'uid' => $uid, 'row' => $row, 'class' => 'INERT', 'cur' => $cur, 'after' => $cur,
                    'why' => 'looth4 protected — Arbiter returns early' ];
        continue;
    }
    if ( $pay_src === 'stripe' && ! in_array( 'looth1', $roles, true ) ) {
        $plan[] = [ 'uid' => $uid, 'row' => $row, 'class' => 'INERT', 'cur' => $cur, 'after' => $cur,
                    'why' => 'payment_source=stripe skip guard' ];
        continue;
    }

    $win_before = $ross_winning( $build( true ) );
    $win_after  = $ross_winning( $build( false ) );
    $after      = $ross_highest( $ross_project( $roles, $win_after ) );

    if ( $after === $cur ) {
        $latent = ( $win_before !== $cur );
        $plan[] = [ 'uid' => $uid, 'row' => $row, 'class' => $latent ? 'DEFUSES' : 'INERT',
                    'cur' => $cur, 'after' => $after,
                    'why' => $latent
                        ? 'latent: stale row would promote to ' . var_export( $win_before, true ) . ' on next Arbiter run'
                        : 'no effective change' ];
        continue;
    }

    // The tier moves. Is this the shadowed-patreon-row case, repairable to a no-op?
    $repairable = false;
    $repair_to  = null;
    if ( $patreon_row_existed && $patreon_tier_before === null && $adapter_tier !== null ) {
        $pm = $pdo->prepare(
            'SELECT patron_status, currently_entitled_amount_cents AS cents, tier_label
               FROM lg_patreon_members WHERE wp_user_id = ? LIMIT 1'
        );
        $pm->execute( [ $uid ] );
        $snap = $pm->fetch( PDO::FETCH_ASSOC ) ?: [];

        // FOUR conditions, all required. The last is the one that matters: we
        // only ever repair when doing so provably changes nothing for the
        // member. A repair that would MOVE them is a real correction and
        // belongs in the held bucket where Ian rules on it.
        $repairable =
               ( ( $snap['patron_status'] ?? '' ) === 'active_patron' )
            && ( (int) ( $snap['cents'] ?? 0 ) > 0 )
            && ( $adapter_tier === $cur )
            && ( $ross_highest( $ross_project( $roles, $ross_winning( array_merge( $build( false ), [ 'patreon' => $adapter_tier ] ) ) ) ) === $cur );
        if ( $repairable ) { $repair_to = $adapter_tier; }
    }

    if ( $repairable ) {
        $plan[] = [ 'uid' => $uid, 'row' => $row, 'class' => 'REPAIR', 'cur' => $cur, 'after' => $cur,
                    'repair_to' => $repair_to, 'patreon_row_existed' => $patreon_row_existed,
                    'patreon_tier_before' => $patreon_tier_before,
                    'why' => 'paying member, stale patreon row — repair to ' . $repair_to . ' then delete (net no-op)' ];
    } else {
        $plan[] = [ 'uid' => $uid, 'row' => $row, 'class' => 'VISIBLE', 'cur' => $cur, 'after' => $after,
                    'why' => 'effective tier moves ' . ( $cur ?? '(none)' ) . ' -> ' . ( $after ?? '(none)' ) ];
    }
}

// ---------------------------------------------------------------- report

$by = [ 'INERT' => [], 'DEFUSES' => [], 'REPAIR' => [], 'VISIBLE' => [], 'NOUSER' => [] ];
foreach ( $plan as $p ) { $by[ $p['class'] ][] = $p; }

foreach ( [ 'VISIBLE', 'REPAIR', 'DEFUSES', 'NOUSER', 'INERT' ] as $cls ) {
    if ( ! $by[ $cls ] ) { continue; }
    printf( "--- %s (%d)\n", $cls, count( $by[ $cls ] ) );
    foreach ( $by[ $cls ] as $p ) {
        $u = get_user_by( 'id', $p['uid'] );
        printf( "  #%-5d %-34s stripe=%-6s %s -> %s   %s\n",
            $p['uid'], $u ? $u->user_email : '(no user)',
            $p['row']['tier'] === null ? 'NULL' : $p['row']['tier'],
            $p['cur'] ?? '(none)', $p['after'] ?? '(none)', $p['why'] );
    }
    echo "\n";
}

// ---------------------------------------------------------------- tripwire

$ids = function ( string $cls ) use ( $by ) {
    $out = array_map( fn( $p ) => (int) $p['uid'], $by[ $cls ] );
    sort( $out );
    return $out;
};
$pin = function ( array $a ) { sort( $a ); return $a; };

$drift = [];
if ( count( $plan ) !== ROSS_PINNED['total'] ) {
    $drift[] = sprintf( 'orphan count %d, pinned %d', count( $plan ), ROSS_PINNED['total'] );
}
foreach ( [ 'repair' => 'REPAIR', 'visible' => 'VISIBLE', 'defuses' => 'DEFUSES' ] as $k => $cls ) {
    if ( $ids( $cls ) !== $pin( ROSS_PINNED[ $k ] ) ) {
        $drift[] = sprintf( "%s now [%s], pinned [%s]", $cls,
            implode( ',', $ids( $cls ) ), implode( ',', $pin( ROSS_PINNED[ $k ] ) ) );
    }
}

if ( $drift ) {
    echo "!! DRIFT from the 2026-08-08 pinned expectation:\n";
    foreach ( $drift as $d ) { echo "     - {$d}\n"; }
    echo "   Drift on this data means a member's entitlement moved since this was\n";
    echo "   ruled on. Read the plan above before proceeding.\n";
    if ( $MODE === 'apply' && ! $ACCEPT_DRIFT ) {
        echo "   REFUSING to apply. Re-run with `accept-drift` once you have read it.\n";
        return;
    }
    echo "\n";
} else {
    echo "Pinned expectation matches live exactly (41 rows; repair/visible/defuses sets identical).\n\n";
}

// ---------------------------------------------------------------- verify

if ( $MODE === 'verify' ) {
    $left = count( $plan );
    printf( "VERIFY: %d orphaned source=stripe rows remain.\n", $left );
    printf( "        %d held as member-visible, %d actionable.\n",
        count( $by['VISIBLE'] ), $left - count( $by['VISIBLE'] ) );
    echo $left === count( $by['VISIBLE'] )
        ? "        Clean: everything that can be retracted without moving a member has been.\n"
        : "        Not yet clean — re-run `apply`.\n";
    return;
}

// ---------------------------------------------------------------- plan

$actionable = [];
foreach ( $plan as $p ) {
    if ( in_array( $p['class'], [ 'INERT', 'DEFUSES', 'REPAIR', 'NOUSER' ], true ) ) {
        $actionable[] = $p;
    } elseif ( $p['class'] === 'VISIBLE' && in_array( (int) $p['uid'], $ALLOW, true ) ) {
        $actionable[] = $p;
    }
}
$held = array_filter( $by['VISIBLE'], fn( $p ) => ! in_array( (int) $p['uid'], $ALLOW, true ) );

printf( "PLAN: %d rows to delete (%d repair-first), %d HELD as member-visible.\n",
    count( $actionable ), count( $by['REPAIR'] ), count( $held ) );
if ( $held ) {
    echo "      Held: " . implode( ', ', array_map( fn( $p ) => '#' . $p['uid'], $held ) ) . "\n";
    echo "      Release one with: apply allow=<id>  (per member, after Ian's go)\n";
}

if ( $MODE !== 'apply' ) {
    echo "\nREVIEW ONLY — nothing was written. Re-run with `apply` to execute.\n";
    return;
}

// ---------------------------------------------------------------- apply

$batch   = 'ross-' . gmdate( 'Ymd-His' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 6 );
$journal = [];

// Journal the FULL before-state first, and persist it BEFORE the first
// mutation. Money + roles: the rollback path must exist before the damage can.
foreach ( $actionable as $p ) {
    $u = get_user_by( 'id', $p['uid'] );
    $journal[] = [
        'wp_user_id'          => (int) $p['uid'],
        'email'               => $u ? $u->user_email : null,
        'class'               => $p['class'],
        'stripe_tier'         => $p['row']['tier'],
        'stripe_updated_at'   => $p['row']['updated_at'],
        'roles_before'        => $u ? array_values( (array) $u->roles ) : [],
        'tier_before'         => $p['cur'],
        'tier_after_expected' => $p['after'],
        'patreon_repaired'    => $p['class'] === 'REPAIR',
        'patreon_row_existed' => $p['patreon_row_existed'] ?? null,
        'patreon_tier_before' => $p['patreon_tier_before'] ?? null,
        'patreon_tier_after'  => $p['repair_to'] ?? null,
        'arbiter_ran'         => ( $p['cur'] !== $p['after'] ),
    ];
}

update_option( ROSS_JOURNAL_PREFIX . $batch, [
    'batch'       => $batch,
    'created'     => current_time( 'mysql' ),
    'entries'     => $journal,
    'reverted_at' => null,
], false );
$index   = get_option( ROSS_INDEX_OPT, [] );
$index[] = $batch;
update_option( ROSS_INDEX_OPT, $index, false );

// Read it straight back. If the journal is not durable, nothing else happens.
$check = get_option( ROSS_JOURNAL_PREFIX . $batch, null );
if ( ! $check || count( $check['entries'] ) !== count( $journal ) ) {
    echo "REFUSING: journal did not persist. Nothing was changed.\n";
    return;
}
printf( "\nJournal %s persisted (%d entries). Applying…\n\n", $batch, count( $journal ) );

$n_del = $n_rep = $n_arb = 0;
foreach ( $actionable as $p ) {
    $uid = (int) $p['uid'];

    if ( $p['class'] === 'REPAIR' ) {
        $pdo->prepare(
            'INSERT INTO lg_role_sources (wp_user_id, source, tier) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE tier = VALUES(tier)'
        )->execute( [ $uid, 'patreon', $p['repair_to'] ] );
        $n_rep++;
        printf( "  #%-5d patreon row repaired NULL -> %s\n", $uid, $p['repair_to'] );
    }

    $pdo->prepare( 'DELETE FROM lg_role_sources WHERE wp_user_id = ? AND source = ?' )
        ->execute( [ $uid, 'stripe' ] );
    $n_del++;

    // Only sync where the tier genuinely moves — see SAFETY PROPERTIES.
    if ( $p['cur'] !== $p['after'] ) {
        $res = \LGMS\Arbiter::sync( $uid );
        $n_arb++;
        printf( "  #%-5d stripe row deleted, Arbiter: %s -> %s\n", $uid,
            $p['cur'] ?? '(none)', $res['winning_tier'] ?? '(none)' );
    } else {
        printf( "  #%-5d stripe row deleted (%s), no sync needed\n", $uid,
            $p['row']['tier'] === null ? 'NULL' : $p['row']['tier'] );
    }
}

printf( "\nApplied batch %s — rows deleted: %d, patreon rows repaired: %d, arbiter runs: %d.\n",
    $batch, $n_del, $n_rep, $n_arb );
printf( "Held (member-visible, untouched): %d\n", count( $held ) );
echo "\nRevert with:\n  wp eval-file revoke-orphan-stripe-sources.php revert {$batch}\n";
echo "Then confirm with:\n  wp eval-file revoke-orphan-stripe-sources.php verify\n";
