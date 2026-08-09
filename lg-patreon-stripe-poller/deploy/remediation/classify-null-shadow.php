<?php
/**
 * OFFLINE replica — blast radius of the null-shadow reader fix.
 *
 *   php classify-null-shadow.php <export.tsv> <tier-map.txt>
 *
 * READ-ONLY over an export produced by null-shadow.sql. No WP, no DB.
 *
 * The defect (handoff §2.1, findings §3): RoleSourceWriter::readAllForUser
 * consults the live PatreonSourceReader only when NO persisted patreon row
 * exists — `array_key_exists('patreon', $out)` is TRUE for tier=NULL, so a
 * stale NULL row SHADOWS a reader that would have said looth2/looth3. Four
 * paying members were nearly demoted through exactly this hole.
 *
 * The fix: a persisted tier=NULL patreon row no longer blocks the reader.
 * When the row is NULL and the reader speaks (payment_source=patreon), the
 * reader's answer is used; when the reader is silent, the NULL stands.
 * A NON-null persisted row stays authoritative — the sweep wrote it from the
 * Patreon API and the old circular-read bug must not come back.
 *
 * For every member with a persisted NULL patreon row this computes the
 * Arbiter outcome under the CURRENT reader (shadowed) and under the FIXED
 * reader, projects both onto their real roles, and reports every member the
 * fix would move. This is the list the charter demands BEFORE the fix ships:
 * if it is non-empty on live, the fix goes behind its own flag, OFF.
 *
 * Replica of: Arbiter::sync guards + computeWinningTier + role projection,
 * RoleSourceWriter::readAllForUser (both variants), PatreonSourceReader.
 * Same modelling as classify-orphans.php, which validated 40/41 against
 * live wp_capabilities with the one disagreement explained.
 */

declare(strict_types=1);

if ( $argc < 3 ) {
    fwrite( STDERR, "usage: php classify-null-shadow.php <export.tsv> <tier-map.txt>\n" );
    exit( 3 );
}

$rows = @file( $argv[1], FILE_IGNORE_NEW_LINES );
$mapS = @file_get_contents( $argv[2] );
if ( $rows === false || $mapS === false ) {
    fwrite( STDERR, "CANNOT RUN: export or tier map unreadable\n" );
    exit( 3 );
}
$MAP = unserialize( trim( explode( "\n", trim( $mapS ) )[0] ) );
if ( ! is_array( $MAP ) || $MAP === [] ) {
    fwrite( STDERR, "CANNOT RUN: tier map did not unserialize\n" );
    exit( 3 );
}

$TIERS = [ 'looth1', 'looth2', 'looth3', 'looth4' ];

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

$n = fn( ?string $v ) => ( $v === null || $v === '\\N' || $v === '\\\\N' ) ? null : $v;

$total = 0; $skipped = 0; $unchanged = 0; $moved = [];
foreach ( $rows as $line ) {
    if ( trim( $line ) === '' ) { continue; }
    $f = explode( "\t", $line );
    $total++;

    $uid     = (int) $f[0];
    $email   = $f[1];
    $stripe  = $n( $f[2] );  $stripeRows = (int) $f[3];
    $manual  = $n( $f[4] );  $manualRows = (int) $f[5];
    $paySrc  = $n( $f[6] );
    $tierId  = $n( $f[7] );
    $capsS   = $n( $f[8] );
    $status  = $n( $f[9] );
    $cents   = $n( $f[10] );
    $label   = $n( $f[11] );

    $roles = [];
    if ( $capsS !== null ) {
        $caps = @unserialize( $capsS );
        if ( is_array( $caps ) ) { $roles = array_keys( array_filter( $caps ) ); }
    }
    $cur = $highest( $roles );

    // Arbiter guards, verbatim: looth4 protected; payment_source=stripe with
    // no looth1 role is skipped. Under BOTH variants — the fix cannot reach
    // these members at all.
    if ( in_array( 'looth4', $roles, true )
         || ( $paySrc === 'stripe' && ! in_array( 'looth1', $roles, true ) ) ) {
        $skipped++;
        continue;
    }

    // What PatreonSourceReader::readForUser would say, if consulted.
    $readerSpeaks = ( $paySrc === 'patreon' );
    $readerTier   = null;
    if ( $readerSpeaks && $tierId !== null && isset( $MAP[ $tierId ] ) ) {
        $m = (string) $MAP[ $tierId ];
        if ( $m === 'looth2' || $m === 'looth3' ) { $readerTier = $m; }
    }

    $persisted = [ 'patreon' => null ];
    if ( $stripeRows > 0 ) { $persisted['stripe'] = $stripe; }
    if ( $manualRows > 0 ) { $persisted['manual_admin'] = $manual; }

    // CURRENT reader: the NULL row shadows — reader never consulted.
    $before = $persisted;

    // FIXED reader: NULL row no longer blocks; reader answer replaces it when
    // the reader speaks. Silent reader leaves the NULL standing.
    $after = $persisted;
    if ( $readerSpeaks ) { $after['patreon'] = $readerTier; }

    $effBefore = $highest( $project( $roles, $winning( $before ) ) );
    $effAfter  = $highest( $project( $roles, $winning( $after ) ) );

    if ( $effBefore === $effAfter ) { $unchanged++; continue; }

    $moved[] = [
        'uid' => $uid, 'email' => $email, 'cur' => $cur,
        'before' => $effBefore, 'after' => $effAfter,
        'reader' => $readerTier, 'tier_id' => $tierId,
        'status' => $status, 'cents' => $cents, 'label' => $label,
    ];
}

printf( "=== null-shadow fix — blast radius over %s ===\n", basename( $argv[1] ) );
printf( "members with a persisted tier=NULL patreon row: %d\n", $total );
printf( "unreachable (looth4 / stripe-skip guard):        %d\n", $skipped );
printf( "effective tier identical under both readers:     %d\n", $unchanged );
printf( "EFFECTIVE TIER WOULD CHANGE UNDER THE FIX:       %d\n\n", count( $moved ) );

if ( $moved ) {
    printf( "  %-6s %-34s %-8s %-9s %-9s %s\n", 'uid', 'email', 'current', 'shadowed', 'fixed', 'patreon truth' );
    echo '  ' . str_repeat( '-', 100 ) . "\n";
    foreach ( $moved as $m ) {
        printf( "  %-6d %-34s %-8s %-9s %-9s %s %s¢ %s (tier_id %s -> %s)\n",
            $m['uid'], $m['email'], $m['cur'] ?? '(none)',
            $m['before'] ?? '(none)', $m['after'] ?? '(none)',
            $m['status'] ?? '?', $m['cents'] ?? '?', $m['label'] ?? '?',
            $m['tier_id'] ?? '-', $m['reader'] ?? 'null' );
    }
    echo "\nNON-EMPTY: per the charter, the fix ships behind its own flag, OFF.\n";
    exit( 1 );
}

echo "EMPTY — on this data the fix moves nobody's effective tier.\n";
exit( 0 );
