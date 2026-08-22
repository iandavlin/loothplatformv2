<?php
/**
 * #203 verification, on the RUNNING dev2 WordPress.
 *
 * The dev2 serve runs MAIN — its mu-plugins symlink into the serving checkout —
 * so a branch's REST behaviour cannot be reached over HTTP until it is merged.
 * This drives the same decision the same way instead of asserting it:
 *
 *   1. THIS LANE'S filter code is loaded from the branch working tree (the class
 *      renamed so it cannot collide with the one main already has loaded) and
 *      ASKED for the route strings. Nothing here is transcribed.
 *   2. Those strings are put on BuddyBoss's real hook, and the request is driven
 *      through rest_do_request(), which runs `rest_request_before_callbacks` —
 *      the exact filter bb_restricate_rest_api() is registered on (100, 3).
 *   3. The SAME request is run with the filters OFF first. That is the before,
 *      measured in the same process as the after, so "it answers now" cannot be
 *      a fact about the box rather than about the change.
 *
 * Side-effect free BY CONSTRUCTION: every request carries no body, so each route
 * refuses at its own required-parameter guard. No customer is synced and no gift
 * mail is sent. That refusal IS the "own JSON" the issue asks for — it is this
 * plugin answering, which is the whole thing that was impossible before.
 */
function lg203_verify( string $branchRoot ): void {
    $src = file_get_contents( $branchRoot . '/lg-patreon-stripe-poller/src/Wp/RestController.php' );
    if ( $src === false ) { echo "CANNOT RUN: branch RestController unreadable\n"; return; }

    // Rename so this cannot redeclare the class main already loaded, and strip
    // the `use` lines whose classes we neither need nor want a second copy of.
    $src = preg_replace( '/final class RestController/', 'final class LG203BranchRestController', $src, 1 );
    $src = preg_replace( '/^use [^;]+;$/m', '', $src );
    $src = preg_replace( '/^declare\(strict_types=1\);$/m', '', $src );
    $src = preg_replace( '/^namespace [^;]+;$/m', 'namespace LG203;', $src, 1 );
    $src = preg_replace( '/^<\?php\s*/', '', $src, 1 );
    eval( $src );

    $cls = '\\LG203\\LG203BranchRestController';
    $filters = [
        'exemptSyncCustomerFromBuddyBossRestriction',
        'exemptGiftCodesFromBuddyBossRestriction',
        'exemptGiftRecipientFromBuddyBossRestriction',
    ];

    $routes = [];
    foreach ( $filters as $fn ) {
        $one = $cls::$fn( [] );
        if ( count( $one ) !== 1 ) { echo "CANNOT RUN: $fn did not name exactly one route\n"; return; }
        $routes[] = $one[0];
    }
    echo "the branch's filters name, asked not transcribed:\n";
    foreach ( $routes as $r ) { echo "    $r\n"; }
    echo "\n";

    $secret = (string) get_option( 'lgms_shared_secret', '' );
    echo 'bb-enable-private-rest-apis = ' . var_export( get_option( 'bb-enable-private-rest-apis' ), true )
       . '   (the wall is ' . ( get_option( 'bb-enable-private-rest-apis' ) ? 'UP' : 'down' ) . ")\n";
    echo 'shared secret configured: ' . ( $secret === '' ? 'NO' : 'yes, ' . strlen( $secret ) . " chars\n" );
    echo "\n";

    $ask = function ( string $route, string $token ) {
        $req = new \WP_REST_Request( 'POST', $route );
        $req->set_header( 'X-LGMS-Token', $token );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( '{}' );
        $res  = rest_do_request( $req );
        $data = $res->get_data();
        $code = is_array( $data ) && isset( $data['code'] ) ? $data['code'] : '(own JSON)';
        return [ $res->get_status(), $code, wp_json_encode( $data ) ];
    };

    $apply = null;
    $on = function () use ( &$apply, $routes ) {
        $apply = function ( $eps ) use ( $routes ) {
            foreach ( $routes as $r ) { if ( ! in_array( $r, (array) $eps, true ) ) { $eps[] = $r; } }
            return $eps;
        };
        add_filter( 'bb_exclude_endpoints_from_restriction', $apply );
    };
    $off = function () use ( &$apply ) {
        if ( $apply ) { remove_filter( 'bb_exclude_endpoints_from_restriction', $apply ); $apply = null; }
    };

    $fail = 0;
    printf( "%-38s %-30s %s\n", 'ROUTE', 'BEFORE (filters off)', 'AFTER (branch filters on)' );
    printf( "%s\n", str_repeat( '-', 110 ) );
    foreach ( $routes as $r ) {
        $off();
        [ $bS, $bC, ] = $ask( $r, $secret );
        $on();
        [ $aS, $aC, $aBody ] = $ask( $r, $secret );
        $off();
        printf( "%-38s %-30s %s\n", $r, "$bS $bC", "$aS $aC" );
        if ( $bC !== 'bb_rest_authorization_required' ) { echo "    !! BEFORE was not the wall — nothing to prove here\n"; $fail++; }
        if ( $aC === 'bb_rest_authorization_required' ) { echo "    !! AFTER is still the wall — the exemption did not take\n"; $fail++; }
        echo "       answered: " . substr( (string) $aBody, 0, 120 ) . "\n";
    }

    echo "\nAND THE SECRET STILL DECIDES — an exemption that stopped refusing would be the bypass:\n";
    foreach ( $routes as $r ) {
        $on();
        [ $wS, $wC ] = $ask( $r, 'wrong-secret' );
        [ $nS, $nC ] = $ask( $r, '' );
        $off();
        printf( "  %-38s wrong-secret: %s %-16s   no-secret: %s %s\n", $r, $wS, $wC, $nS, $nC );
        if ( $wC !== 'rest_forbidden' || $nC !== 'rest_forbidden' ) {
            echo "    !! a caller without the secret was NOT refused by our own check\n"; $fail++;
        }
    }

    echo "\n/run-now stays shut ON PURPOSE (ops-only, cron-covered, whole-Tick exposure):\n";
    $on();
    [ $rS, $rC ] = $ask( '/lg-member-sync/v1/run-now', $secret );
    $off();
    printf( "  %-38s %s %s\n", '/lg-member-sync/v1/run-now', $rS, $rC );
    if ( $rC !== 'bb_rest_authorization_required' ) { echo "    !! it is NOT still shut\n"; $fail++; }

    echo "\n" . ( $fail === 0 ? "VERIFIED — every claim above measured, none asserted.\n" : "$fail PROBLEM(S)\n" );
}
/* ⚠️ __DIR__ IS USELESS HERE, AND THAT IS NOT OBVIOUS. `wp eval-file` EVALS the
   file, so __FILE__/__DIR__ resolve to the eval context and dirname() walks off
   into `//` — the first draft of this line silently reported CANNOT RUN. Same
   family as the __DIR__-through-a-symlink trap this box already records. The
   tree under test is therefore passed IN. Run it as the WordPress pool user so
   get_option() and rest_do_request() see the real box:

     cp tools/verify/203-route-exemptions-verify.php /tmp/v.php && chmod 644 /tmp/v.php
     sudo -u looth-dev env LG203_ROOT=$PWD wp eval-file /tmp/v.php --path=/var/www/dev

   ⚠️ THE COPY IS NOT OPTIONAL if your worktree is not readable by that user: wp
   reports an unreadable file as "does not exist", which reads as a typo rather
   than a permission problem. And `env` is not decoration — plain sudo strips
   the variable, and the script would then verify the wrong tree without saying
   so. It refuses rather than guessing a default for exactly that reason. */
$lg203Root = (string) getenv( 'LG203_ROOT' );
if ( $lg203Root === '' || ! is_dir( $lg203Root ) ) {
    echo "CANNOT RUN: set LG203_ROOT to the worktree under test (see the header).\n";
} else {
    lg203_verify( rtrim( $lg203Root, '/' ) );
}
