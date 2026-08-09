<?php
/**
 * HEALTH CHECK — members whose tier is held up by a source that no longer exists.
 *
 *   wp eval-file check-stuck-sources.php --path=/var/www/dev          # report
 *   wp eval-file check-stuck-sources.php --path=/var/www/dev quiet    # counts only
 *
 * Exit 0 = clean, 1 = open defect (stuck members found), 3 = cannot run.
 * READ-ONLY. Writes nothing, ever. Safe to run on live at any time.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 * `lg_role_sources` is a per-source opinion table and the Arbiter takes the
 * MAX across sources. Nothing in the system ever retracts an opinion when its
 * source dies. So a dead source keeps voting, forever, and outranks a live one.
 *
 * That defect class has now been found twice:
 *   1. 2026-07-30 audit §5.2 — 41 orphaned source=stripe rows, 3 members named.
 *   2. 2026-08-08 stripe-build — the real number was 10, including FOUR
 *      actively-paying members being held up by dead sandbox rows, and SEVEN
 *      members the live Patreon sweep has been failing to correct every hour.
 *
 * Per docs/CRAFT-STANDARD.md, a defect class discovered twice is encoded as a
 * gate before it is fixed the second time. This is that gate.
 *
 * The signal it keys on is one the engine already computes but throws away.
 * `class-lgpo-sync-engine.php:960` literally says:
 *
 *     "Patreon tier ended; effective role stays {role} (held by another source)."
 *
 * — and then only error_log()s it, into a log that (until this branch) did not
 * exist on live. A member can sit in that state indefinitely with no operator
 * ever seeing it. This turns that invisible state into a countable one.
 *
 * ---------------------------------------------------------------------------
 * WHAT COUNTS AS A DEAD SOURCE
 * ---------------------------------------------------------------------------
 * Deliberately narrow — a false positive here costs somebody an investigation.
 *
 *   stripe       dead when the user has NO row in wp_user_bridge. The bridge is
 *                the only link between an opinion and a real Stripe customer;
 *                without it the opinion has no subject at all.
 *   patreon      dead when the user has no `lgpo_patreon_user_id` AND no row in
 *                lg_patreon_members. (A lapsed patron is NOT dead — they are a
 *                live source correctly reporting "no tier".)
 *   manual_admin never dead. It is a human decision with no external subject to
 *                outlive, and it is exactly how a grandfathered member should
 *                be held. Reporting it would train operators to ignore output.
 *
 * A dead source is only REPORTED when it is also LOAD-BEARING — i.e. removing
 * it would change the member's effective tier. A dead row that changes nothing
 * is debris worth clearing but is not a member-facing defect, and mixing the
 * two is how a report gets ignored.
 */

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

if ( ! class_exists( '\\LGMS\\Db' ) ) {
    fwrite( STDERR, "CANNOT RUN: LGMS namespace not loaded.\n" );
    exit( 3 );
}

try {
    $pdo = \LGMS\Db::pdo();
} catch ( \Throwable $e ) {
    fwrite( STDERR, "CANNOT RUN: lg_membership unreachable: " . $e->getMessage() . "\n" );
    exit( 3 );
}

$QUIET = in_array( 'quiet', $args ?? [], true );
$TIERS = [ 'looth1', 'looth2', 'looth3', 'looth4' ];

/**
 * The role set Arbiter::sync would leave behind. Omitting this was a real bug
 * the gate caught: without it, a member whose sources all disappear computes
 * `winning = null` and looks like they would lose everything — but the Arbiter
 * explicitly PRESERVES looth1 in that branch, so a looth1 holder does not move
 * at all. It produced false positives on every free-tier member.
 */
$project = function ( array $roles, $winning ) use ( $TIERS ) {
    $out = $roles;
    foreach ( $TIERS as $role ) {
        if ( $role === $winning ) { continue; }
        if ( $role === 'looth1' && $winning === null ) { continue; }
        $out = array_values( array_diff( $out, [ $role ] ) );
    }
    if ( $winning !== null && ! in_array( $winning, $out, true ) ) { $out[] = $winning; }
    return $out;
};

$highest = function ( array $roles ) use ( $TIERS ) {
    $best = null;
    foreach ( $TIERS as $r ) {
        if ( in_array( $r, $roles, true ) && ( $best === null || strcmp( $r, $best ) > 0 ) ) { $best = $r; }
    }
    return $best;
};
$winning = function ( array $sources ) use ( $TIERS ) {
    if ( $sources === [] ) { return null; }
    $best = null;
    foreach ( $sources as $t ) {
        if ( $t === null || ! in_array( $t, $TIERS, true ) ) { continue; }
        if ( $best === null || strcmp( $t, $best ) > 0 ) { $best = $t; }
    }
    return $best ?? 'looth1';
};

// Every user carrying at least one source row.
$rows = $pdo->query(
    'SELECT wp_user_id, source, tier FROM lg_role_sources ORDER BY wp_user_id, source'
)->fetchAll( PDO::FETCH_ASSOC );

$byUser = [];
foreach ( $rows as $r ) {
    $byUser[ (int) $r['wp_user_id'] ][ (string) $r['source'] ] = $r['tier'] !== null ? (string) $r['tier'] : null;
}

$bridged = [];
foreach ( $pdo->query( 'SELECT wp_user_id FROM wp_user_bridge' )->fetchAll( PDO::FETCH_COLUMN ) as $u ) {
    $bridged[ (int) $u ] = true;
}
$patreonKnown = [];
foreach ( $pdo->query( 'SELECT wp_user_id FROM lg_patreon_members' )->fetchAll( PDO::FETCH_COLUMN ) as $u ) {
    $patreonKnown[ (int) $u ] = true;
}

$stuck = [];
$debris = 0;
$scanned = 0;

foreach ( $byUser as $uid => $sources ) {
    $user = get_user_by( 'id', $uid );
    if ( ! $user ) { continue; }
    $scanned++;

    $roles = (array) $user->roles;
    if ( in_array( 'looth4', $roles, true ) ) { continue; }   // protected; Arbiter returns early

    $cur = $highest( $roles );

    foreach ( $sources as $source => $tier ) {
        $dead = false;
        if ( $source === 'stripe' ) {
            $dead = empty( $bridged[ $uid ] );
        } elseif ( $source === 'patreon' ) {
            $dead = empty( $patreonKnown[ $uid ] )
                 && ( (string) get_user_meta( $uid, 'lgpo_patreon_user_id', true ) === '' );
        }
        if ( ! $dead ) { continue; }

        // Load-bearing? Recompute without this source, building the map exactly
        // as RoleSourceWriter::readAllForUser does — the live Patreon reader is
        // consulted whenever NO persisted patreon row remains, whichever source
        // we just removed. Scoping that refill to `$source === 'patreon'` was
        // the gate's other catch: it made every member whose Patreon opinion
        // comes from the reader rather than a row look like they would collapse.
        $without = $sources;
        unset( $without[ $source ] );
        if ( ! array_key_exists( 'patreon', $without )
             && get_user_meta( $uid, 'payment_source', true ) === 'patreon' ) {
            $map = get_option( 'lgpo_tier_map', [] );
            $tid = (string) get_user_meta( $uid, 'lgpo_patreon_tier_id', true );
            $m   = ( is_array( $map ) && $tid !== '' && isset( $map[ $tid ] ) ) ? (string) $map[ $tid ] : '';
            $without['patreon'] = ( $m === 'looth2' || $m === 'looth3' ) ? $m : null;
        }

        // Compare ACTUAL tiers, not computed winners: "held up" means retracting
        // the dead source would move THIS member today. A row that merely would
        // have promoted them on some future Arbiter run is latent, belongs in
        // the debris count, and is handled by the retraction script anyway.
        $after = $highest( $project( $roles, $winning( $without ) ) );
        if ( $after === $cur ) { $debris++; continue; }

        $stuck[] = [
            'uid'    => $uid,
            'email'  => $user->user_email,
            'source' => $source,
            'tier'   => $tier,
            'holds'  => $cur,
            'would'  => $after,
        ];
    }
}

printf( "=== stuck-source check — %s ===\n", gmdate( 'c' ) );
printf( "users carrying source rows: %d\n", $scanned );
printf( "dead-but-harmless rows (debris, safe to retract): %d\n", $debris );
printf( "MEMBERS HELD UP BY A DEAD SOURCE: %d\n\n", count( $stuck ) );

if ( $stuck && ! $QUIET ) {
    printf( "  %-6s %-34s %-13s %-8s %-8s %s\n", 'uid', 'email', 'dead source', 'holds', 'without', '' );
    echo '  ' . str_repeat( '-', 92 ) . "\n";
    foreach ( $stuck as $s ) {
        printf( "  %-6d %-34s %-13s %-8s %-8s %s\n",
            $s['uid'], $s['email'], $s['source'] . '=' . ( $s['tier'] ?? 'NULL' ),
            $s['holds'] ?? '(none)', $s['would'] ?? '(none)',
            ( $s['would'] === null || strcmp( (string) $s['holds'], (string) $s['would'] ) > 0 ) ? '<- over-tiered' : '' );
    }
    echo "\n";
}

if ( $stuck ) {
    echo "OPEN DEFECT. Each of these members' tier depends on a source with no live\n";
    echo "subject. Retract via revoke-orphan-stripe-sources.php (which repairs a\n";
    echo "shadowed patreon row before deleting, so a paying member is not demoted).\n";
    exit( 1 );
}

echo "CLEAN — no member's tier is held up by a dead source.\n";
exit( 0 );
