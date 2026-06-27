<?php
/**
 * REMEDIATION #1 — role-source-aware de-dupe of users holding 2+ looth tier roles.
 * Settled-design item 6. PREPARED FOR LIVE — Ian runs this by hand; it is NOT
 * wired into any cron or run automatically.
 *
 * Background: a historical interaction (the looth4 comp-timer stripped looth4 but
 * left looth1, then a later Patreon sub added looth3) left ~15 users holding two
 * tier roles at once (11 looth1+looth3, 4 looth1+looth2). The Arbiter fix in this
 * branch prevents NEW double-roles; this script cleans up the existing ones.
 *
 * Run (as the WP system user, e.g. on live `sudo -u looth-live wp ...`):
 *   wp eval-file dedupe-multirole.php            # DRY RUN — prints the plan, writes nothing
 *   wp eval-file dedupe-multirole.php apply      # APPLY
 *
 * Per-user outcome (always exactly ONE tier role afterwards):
 *   - looth4 present              -> keep looth4, strip looth1/2/3 (comp/manual wins, protected)
 *   - else has any role-source    -> \LGMS\Arbiter::sync() (arbiter de-dupes to the winning tier)
 *   - else (no source rows)       -> keep the single highest tier role, strip the rest
 *
 * Safety: never deletes users; never touches non-tier roles (administrator,
 * bbp_participant, customer, …); idempotent (re-running is a no-op once clean).
 */

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$APPLY = in_array( 'apply', $args ?? [], true );
$TIERS = [ 'looth1', 'looth2', 'looth3', 'looth4' ];
$RANK  = [ 'looth1' => 1, 'looth2' => 2, 'looth3' => 3, 'looth4' => 4 ];

$all   = get_users( [ 'fields' => [ 'ID' ], 'number' => -1 ] );
$multi = [];
foreach ( $all as $row ) {
    $u    = get_userdata( $row->ID );
    $held = array_values( array_intersect( $TIERS, (array) $u->roles ) );
    if ( count( $held ) >= 2 ) {
        $multi[] = $row->ID;
    }
}

printf( "=== dedupe-multirole — %s ===\n", $APPLY ? 'APPLY' : 'DRY RUN (no writes)' );
printf( "Users holding 2+ tier roles: %d\n\n", count( $multi ) );

$applied = 0;
foreach ( $multi as $id ) {
    $u       = get_userdata( $id );
    $before  = array_values( array_intersect( $TIERS, (array) $u->roles ) );
    $sources = class_exists( '\\LGMS\\RoleSourceWriter' )
        ? \LGMS\RoleSourceWriter::readAllForUser( $id )
        : [];
    $has_src = ! empty( $sources );

    if ( in_array( 'looth4', $before, true ) ) {
        $method = 'looth4-protected'; $keep = 'looth4';
    } elseif ( $has_src ) {
        $method = 'arbiter';          $keep = '(arbiter winner)';
    } else {
        $ordered = $before;
        usort( $ordered, fn( $a, $b ) => $RANK[ $b ] <=> $RANK[ $a ] );
        $method = 'no-source: keep-highest'; $keep = $ordered[0];
    }

    printf(
        "#%-6d %-28s roles=[%s] sources=%s\n          -> keep %s  (%s)\n",
        $id, $u->user_login, implode( ',', $before ),
        $has_src ? wp_json_encode( $sources ) : 'none', $keep, $method
    );

    if ( ! $APPLY ) { continue; }

    if ( $method === 'arbiter' ) {
        \LGMS\Arbiter::sync( $id );
    } else {
        foreach ( $TIERS as $r ) {
            if ( $r !== $keep && in_array( $r, (array) get_userdata( $id )->roles, true ) ) {
                $u->remove_role( $r );
            }
        }
        if ( ! in_array( $keep, (array) get_userdata( $id )->roles, true ) ) {
            $u->add_role( $keep );
        }
    }

    $after = array_values( array_intersect( $TIERS, (array) get_userdata( $id )->roles ) );
    printf( "          applied -> [%s]\n", implode( ',', $after ) );
    if ( count( $after ) === 1 ) { $applied++; }
}

printf( "\nDone. %s\n", $APPLY ? "Cleaned: {$applied}/" . count( $multi ) : 'DRY RUN — re-run with `apply` to execute.' );
