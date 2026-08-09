<?php
/**
 * RETRACTION SWEEP — the explicit APPLY leg. Ian (or keeper, with Ian's word)
 * runs this by hand. NOT wired into any cron: the tick pass
 * (LGMS\RetractionSweep::tick, behind lgms_retraction_sweep) only DETECTS,
 * journals and notifies. Nothing retracts an opinion until this script is
 * invoked, and nothing member-visible moves without a per-member allow=.
 *
 * Background: design doc §4. lg_role_sources is a per-source opinion table,
 * the Arbiter takes the max, and historically nothing ever retracted an
 * opinion when its source died — the 41 orphans Phase 0 cleaned by hand are
 * that bug. The detection leg iterates the OPINIONS (source='stripe'), never
 * the customers table, and calls a row unjustifiable when it no longer traces
 * to bridge -> live customer -> active membership entitlement backing the
 * asserted tier. This script is what acts on those findings.
 *
 * For the 2026-08-08 ruled set specifically, prefer
 * revoke-orphan-stripe-sources.php — it carries the pinned per-member ruling
 * Ian decided from. This script is the PERMANENT tool for everything the
 * sweep finds after that.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS NOT "ACT ON THE JOURNAL"
 * ---------------------------------------------------------------------------
 * Findings are RECOMPUTED live at run time via RetractionSweep::detect() —
 * the tick's journal is a notification trail, never the input. Between a
 * detection and an apply, a member can pay, lapse, or be re-bridged; acting
 * on a stored list would act on a stale world.
 *
 * Each finding is then classified by MEMBER IMPACT before anything happens,
 * with the same care the Phase 0 script proved:
 *
 *   INERT    effective tier identical before and after -> retract
 *   DEFUSES  invisible today, but the stale opinion is a loaded change that
 *            fires on the next Arbiter run -> retract
 *   REPAIR   a paying member whose persisted patreon row is a stale NULL
 *            shadowing the live reader (the class that nearly demoted four
 *            paying members) -> repair that row to the tier their live
 *            pledge entitles, THEN retract. Only when provably a no-op.
 *   VISIBLE  the member's effective tier really moves -> HELD by default.
 *            Released one member at a time: apply allow=<id>. There is
 *            deliberately no "allow=all".
 *
 * The RETRACTION ITSELF is reason-shaped (design §4: "retraction writes
 * tier=NULL rather than deleting, except on customer deletion — deletion is
 * for debris"):
 *
 *   no_bridge / customer_gone / customer_deleted   DELETE the row — the
 *                                                  opinion has no subject
 *   no_entitlement                                 UPDATE tier -> NULL — a
 *                                                  live source saying "no
 *                                                  tier", audit trail kept
 *   tier_mismatch                                  UPDATE tier -> the
 *                                                  entitled tier (exactly
 *                                                  what Sync writes)
 *
 * ---------------------------------------------------------------------------
 * MODES
 * ---------------------------------------------------------------------------
 *   wp eval-file apply-retraction-sweep.php                    review (default)
 *   wp eval-file apply-retraction-sweep.php apply              INERT+DEFUSES+REPAIR only
 *   wp eval-file apply-retraction-sweep.php apply allow=123    + the named VISIBLE members
 *   wp eval-file apply-retraction-sweep.php revert <batch-id>
 *   wp eval-file apply-retraction-sweep.php revert             (lists batches)
 *   wp eval-file apply-retraction-sweep.php verify
 *
 * SAFETY PROPERTIES — same set the Phase 0 script proved:
 *   - journal written to wp_options and READ BACK before the first mutation
 *   - Arbiter::sync only where the effective tier actually moves
 *   - nothing deleted from customers / entitlements / wp_user_bridge
 *   - no user created, deleted or emailed; no Stripe API call
 *   - idempotent: re-running after a clean pass finds nothing to do
 */

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

defined( 'RSAP_JOURNAL_PREFIX' ) || define( 'RSAP_JOURNAL_PREFIX', 'lgms_retraction_apply_journal_' );
defined( 'RSAP_INDEX_OPT' )      || define( 'RSAP_INDEX_OPT', 'lgms_retraction_apply_batches' );

/** Highest looth tier present in a role list. */
$rsap_highest = function ( array $roles ): ?string {
    $best = null;
    foreach ( [ 'looth1', 'looth2', 'looth3', 'looth4' ] as $r ) {
        if ( in_array( $r, $roles, true ) && ( $best === null || strcmp( $r, $best ) > 0 ) ) {
            $best = $r;
        }
    }
    return $best;
};

/** Verbatim port of Arbiter::computeWinningTier. */
$rsap_winning = function ( array $sources ) {
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
$rsap_project = function ( array $roles, $winning ): array {
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

if ( ! class_exists( '\\LGMS\\Db' ) || ! class_exists( '\\LGMS\\Arbiter' )
     || ! class_exists( '\\LGMS\\RetractionSweep' ) || ! class_exists( '\\LGMS\\RoleSourceWriter' ) ) {
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
        $index = get_option( RSAP_INDEX_OPT, [] );
        echo "Usage: wp eval-file apply-retraction-sweep.php revert <batch-id>\n\n";
        if ( empty( $index ) ) { echo "No apply batches on record.\n"; return; }
        echo "Known batches:\n";
        foreach ( $index as $bid ) {
            $j = get_option( RSAP_JOURNAL_PREFIX . $bid, [] );
            printf( "  %s  applied %s  rows=%d  reverted=%s\n",
                $bid, $j['created'] ?? '?', count( $j['entries'] ?? [] ),
                $j['reverted_at'] ? $j['reverted_at'] : 'no' );
        }
        return;
    }

    $j = get_option( RSAP_JOURNAL_PREFIX . $BATCH, null );
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

        // 1. restore the stripe row exactly as found — original tier AND
        //    timestamp (the upsert's explicit updated_at overrides ON UPDATE)
        $pdo->prepare(
            'INSERT INTO lg_role_sources (wp_user_id, source, tier, updated_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE tier = VALUES(tier), updated_at = VALUES(updated_at)'
        )->execute( [ $uid, 'stripe', $e['stripe_tier'], $e['stripe_updated_at'] ] );
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
    update_option( RSAP_JOURNAL_PREFIX . $BATCH, $j, false );

    printf( "\nReverted batch %s — stripe rows restored: %d, patreon repairs undone: %d, arbiter re-runs: %d.\n",
        $BATCH, $n_row, $n_pat, $n_arb );
    echo "NOTE: a Patreon sweep that ran between apply and revert may have\n";
    echo "      legitimately rewritten a patreon row since. Re-run `verify`.\n";
    return;
}

// ---------------------------------------------------------------- gather

$findings = \LGMS\RetractionSweep::detect();

printf( "=== apply-retraction-sweep — %s ===\n", strtoupper( $MODE ) );
printf( "unjustifiable source=stripe opinions (recomputed live now): %d\n\n", count( $findings ) );

$tier_map = get_option( 'lgpo_tier_map', [] );
if ( ! is_array( $tier_map ) ) { $tier_map = []; }

// ---------------------------------------------------------------- classify

$plan = [];
foreach ( $findings as $uid => $f ) {
    $user = get_user_by( 'id', $uid );

    if ( ! $user ) {
        $plan[] = [ 'uid' => $uid, 'f' => $f, 'class' => 'NOUSER',
                    'why' => 'no such WP user — row is pure debris', 'cur' => null, 'after' => null ];
        continue;
    }

    $roles = (array) $user->roles;
    $cur   = $rsap_highest( $roles );

    // Arbiter guards that short-circuit before any tier is computed: the
    // retraction cannot reach these members' roles at all.
    if ( in_array( 'looth4', $roles, true ) ) {
        $plan[] = [ 'uid' => $uid, 'f' => $f, 'class' => 'INERT', 'cur' => $cur, 'after' => $cur,
                    'why' => 'looth4 protected — Arbiter returns early' ];
        continue;
    }
    if ( get_user_meta( $uid, 'payment_source', true ) === 'stripe'
         && ! in_array( 'looth1', $roles, true ) ) {
        $plan[] = [ 'uid' => $uid, 'f' => $f, 'class' => 'INERT', 'cur' => $cur, 'after' => $cur,
                    'why' => 'payment_source=stripe skip guard' ];
        continue;
    }

    // The REAL deployed reader — including the lgms_null_shadow_fix flag
    // state — is the authority on what the Arbiter would hear. Not a replica.
    $sources = \LGMS\RoleSourceWriter::readAllForUser( $uid );
    $without = $sources;
    if ( $f['reason'] === 'tier_mismatch' ) {
        $without['stripe'] = $f['justified'];       // retraction corrects, not deletes
    } else {
        unset( $without['stripe'] );                // delete and tier->NULL read the same
        if ( $f['reason'] === 'no_entitlement' ) { $without['stripe'] = null; }
    }

    $win_before = $rsap_winning( $sources );
    $win_after  = $rsap_winning( $without );
    $after      = $rsap_highest( $rsap_project( $roles, $win_after ) );

    if ( $after === $cur ) {
        $latent = ( $win_before !== $cur );
        $plan[] = [ 'uid' => $uid, 'f' => $f, 'class' => $latent ? 'DEFUSES' : 'INERT',
                    'cur' => $cur, 'after' => $after,
                    'why' => $latent
                        ? 'latent: stale opinion would move member to ' . var_export( $win_before, true ) . ' on next Arbiter run'
                        : 'no effective change' ];
        continue;
    }

    // The tier moves. Is this the shadowed-patreon-row case, repairable to a
    // no-op? Same four conditions the Phase 0 script proved — only ever
    // repaired when doing so provably changes nothing for the member.
    $st = $pdo->prepare( 'SELECT COUNT(*) FROM lg_role_sources WHERE wp_user_id = ? AND source = ? AND tier IS NULL' );
    $st->execute( [ $uid, 'patreon' ] );
    $null_patreon_row = ( (int) $st->fetchColumn() === 1 );

    $adapter_tier = null;
    if ( get_user_meta( $uid, 'payment_source', true ) === 'patreon' ) {
        $tid = get_user_meta( $uid, 'lgpo_patreon_tier_id', true );
        if ( is_string( $tid ) && $tid !== '' && isset( $tier_map[ $tid ] ) ) {
            $m = (string) $tier_map[ $tid ];
            if ( $m === 'looth2' || $m === 'looth3' ) { $adapter_tier = $m; }
        }
    }

    $repairable = false;
    if ( $null_patreon_row && $adapter_tier !== null ) {
        $pm = $pdo->prepare(
            'SELECT patron_status, currently_entitled_amount_cents AS cents
               FROM lg_patreon_members WHERE wp_user_id = ? LIMIT 1'
        );
        $pm->execute( [ $uid ] );
        $snap = $pm->fetch( PDO::FETCH_ASSOC ) ?: [];

        $repaired_map            = $without;
        $repaired_map['patreon'] = $adapter_tier;
        $repairable =
               ( ( $snap['patron_status'] ?? '' ) === 'active_patron' )
            && ( (int) ( $snap['cents'] ?? 0 ) > 0 )
            && ( $adapter_tier === $cur )
            && ( $rsap_highest( $rsap_project( $roles, $rsap_winning( $repaired_map ) ) ) === $cur );
    }

    if ( $repairable ) {
        $plan[] = [ 'uid' => $uid, 'f' => $f, 'class' => 'REPAIR', 'cur' => $cur, 'after' => $cur,
                    'repair_to' => $adapter_tier, 'patreon_row_existed' => true,
                    'patreon_tier_before' => null,
                    'why' => 'paying member, stale patreon row — repair to ' . $adapter_tier . ' then retract (net no-op)' ];
    } else {
        $plan[] = [ 'uid' => $uid, 'f' => $f, 'class' => 'VISIBLE', 'cur' => $cur, 'after' => $after,
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
        printf( "  #%-5d %-34s %-16s stripe=%-6s %s -> %s   %s\n",
            $p['uid'], $u ? $u->user_email : '(no user)', $p['f']['reason'],
            $p['f']['tier'] === null ? 'NULL' : $p['f']['tier'],
            $p['cur'] ?? '(none)', $p['after'] ?? '(none)', $p['why'] );
    }
    echo "\n";
}

// ---------------------------------------------------------------- verify

if ( $MODE === 'verify' ) {
    $left = count( $plan );
    printf( "VERIFY: %d unjustifiable source=stripe opinions remain.\n", $left );
    printf( "        %d held as member-visible, %d actionable.\n",
        count( $by['VISIBLE'] ), $left - count( $by['VISIBLE'] ) );
    echo $left === count( $by['VISIBLE'] )
        ? "        Clean: everything retractable without moving a member has been retracted.\n"
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

printf( "PLAN: %d opinions to retract (%d repair-first), %d HELD as member-visible.\n",
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

$batch   = 'rsap-' . gmdate( 'Ymd-His' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 6 );
$journal = [];

// Journal the FULL before-state first, and persist it BEFORE the first
// mutation. Money + roles: the rollback path must exist before the damage can.
foreach ( $actionable as $p ) {
    $u = get_user_by( 'id', $p['uid'] );
    $journal[] = [
        'wp_user_id'          => (int) $p['uid'],
        'email'               => $u ? $u->user_email : null,
        'class'               => $p['class'],
        'reason'              => $p['f']['reason'],
        'action'              => in_array( $p['f']['reason'], [ 'no_entitlement', 'tier_mismatch' ], true ) ? 'retract' : 'delete',
        'stripe_tier'         => $p['f']['tier'],
        'stripe_tier_new'     => $p['f']['reason'] === 'tier_mismatch' ? $p['f']['justified']
                               : ( $p['f']['reason'] === 'no_entitlement' ? null : null ),
        'stripe_updated_at'   => $p['f']['updated_at'],
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

update_option( RSAP_JOURNAL_PREFIX . $batch, [
    'batch'       => $batch,
    'created'     => current_time( 'mysql' ),
    'entries'     => $journal,
    'reverted_at' => null,
], false );
$index   = get_option( RSAP_INDEX_OPT, [] );
$index[] = $batch;
update_option( RSAP_INDEX_OPT, $index, false );

// Read it straight back. If the journal is not durable, nothing else happens.
$check = get_option( RSAP_JOURNAL_PREFIX . $batch, null );
if ( ! $check || count( $check['entries'] ) !== count( $journal ) ) {
    echo "REFUSING: journal did not persist. Nothing was changed.\n";
    return;
}
printf( "\nJournal %s persisted (%d entries). Applying…\n\n", $batch, count( $journal ) );

$n_del = $n_null = $n_rep = $n_arb = 0;
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

    if ( $p['f']['reason'] === 'no_entitlement' ) {
        $pdo->prepare( 'UPDATE lg_role_sources SET tier = NULL WHERE wp_user_id = ? AND source = ?' )
            ->execute( [ $uid, 'stripe' ] );
        $n_null++;
        printf( "  #%-5d stripe opinion retracted %s -> NULL (no active entitlement)\n",
            $uid, $p['f']['tier'] ?? 'NULL' );
    } elseif ( $p['f']['reason'] === 'tier_mismatch' ) {
        $pdo->prepare( 'UPDATE lg_role_sources SET tier = ? WHERE wp_user_id = ? AND source = ?' )
            ->execute( [ $p['f']['justified'], $uid, 'stripe' ] );
        $n_null++;
        printf( "  #%-5d stripe opinion corrected %s -> %s (entitled tier)\n",
            $uid, $p['f']['tier'] ?? 'NULL', $p['f']['justified'] ?? 'NULL' );
    } else {
        $pdo->prepare( 'DELETE FROM lg_role_sources WHERE wp_user_id = ? AND source = ?' )
            ->execute( [ $uid, 'stripe' ] );
        $n_del++;
        printf( "  #%-5d stripe row deleted (%s, %s)\n", $uid,
            $p['f']['tier'] === null ? 'NULL' : $p['f']['tier'], $p['f']['reason'] );
    }

    // Only sync where the tier genuinely moves — a needless sync would still
    // fire bp_set_member_type and the looth_tier_changed hook.
    if ( $p['cur'] !== $p['after'] ) {
        $res = \LGMS\Arbiter::sync( $uid );
        $n_arb++;
        printf( "  #%-5d Arbiter: %s -> %s\n", $uid,
            $p['cur'] ?? '(none)', $res['winning_tier'] ?? '(none)' );
    }
}

printf( "\nApplied batch %s — rows deleted: %d, opinions retracted/corrected in place: %d, patreon rows repaired: %d, arbiter runs: %d.\n",
    $batch, $n_del, $n_null, $n_rep, $n_arb );
printf( "Held (member-visible, untouched): %d\n", count( $held ) );
echo "\nRevert with:\n  wp eval-file apply-retraction-sweep.php revert {$batch}\n";
echo "Then confirm with:\n  wp eval-file apply-retraction-sweep.php verify\n";
