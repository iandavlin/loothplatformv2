<?php

declare(strict_types=1);

namespace LGMS\Wp;

use LGMS\Membership\CheckoutAudience;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The Slim billing app's window onto "may this person buy" (#181).
 *
 *   POST /wp-json/lg-member-sync/v1/checkout-audience   body: { email }
 *   Auth: X-LGMS-Token, the shared secret every server-to-server call on this
 *         rail already uses.
 *
 * WHY A ROUTE AND NOT A QUERY: PatreonStandingRestController wrote this down
 * and it has not changed. The billing app's DB user holds `ALL ON
 * lg_membership` and `USAGE ON *.*`, so `wp_options` and `wp_users` are closed
 * to it — measured 2026-08-19, not assumed. It could reach
 * `lg_patreon_members`, which lives in its own database, but the cohort does
 * not live there and inventing a second home for it is the one thing #181 must
 * not do.
 *
 * ⚠️ REGISTERED UNCONDITIONALLY, AND THAT IS A DELIBERATE BREAK FROM THE
 * SIBLING ROUTE. `/patreon-standing` uses THE FLAG IS THE REGISTRATION: with
 * `lgms_double_pay_block` off the route does not exist, the probe gets a 404,
 * and checkout proceeds. That is exactly right for a guard whose OFF state must
 * be invisible and whose failure mode is a missed block.
 *
 * It is exactly wrong here. This guard fails CLOSED, so "route missing" and
 * "state is off" must not be the same observation — a flushed rewrite, a
 * deactivated plugin or a half-finished deploy all produce a 404, and if 404
 * meant `off` then any one of those would silently swing the doors open on the
 * very path #181 exists to close. So the route always exists and ANSWERS the
 * state, including `off`. There is still exactly one switch; it is just
 * reported rather than inferred from an absence.
 *
 * ⚠️ AND IT NEEDS ONE NARROW REPAIR TO BE REACHABLE AT ALL. Measured on dev2
 * 2026-08-21, from 127.0.0.1, with the correct secret: every shared-secret
 * route in this namespace answers
 *   401 {"code":"bb_rest_authorization_required"}
 * because BuddyBoss's `bb_restricate_rest_api` short-circuits the REST stack
 * before any route's own permission_callback runs, whenever the global option
 * `bb-enable-private-rest-apis` is 1 (it is, on dev2 AND live, and it is
 * re-armed by every DB reload). Left alone, this probe could never get an
 * answer, every checkout would read UNKNOWN, and a fence that refuses everyone
 * including the cohort is not a fence — it is an outage.
 *
 * The repair uses BuddyBoss's OWN documented extension point,
 * `bb_exclude_endpoints_from_restriction`, and names EXACTLY ONE route: this
 * one. It is not an auth bypass — `bb_restricate_rest_api` is not this route's
 * authentication, it is a blanket pre-emption of it. The shared-secret
 * permission_callback below still runs, still requires a configured secret,
 * and still compares with hash_equals. A caller without the secret is refused
 * here exactly as it was refused there; the difference is only WHICH check
 * does the refusing, and this one can tell a valid caller apart from an
 * anonymous one.
 *
 * ⚠️ THE SAME 401 WAS SITTING ON THREE OTHER ROUTES AND THEY WERE NOT MINE TO
 * OPEN. `/sync-customer`, `/patreon-standing` and `/send-gift-codes` are all
 * shared-secret routes and were all unreachable — which meant #150's double-pay
 * probe answered UNKNOWN on every call since the option was last re-armed, and
 * the Slim app's post-checkout sync ping was dead (the five-minute
 * `Sync::all()` sweep is what covered for it, which is why nothing looked
 * broken). Reported to keeper as a separate finding with this exact one-line
 * fix. Widening an auth surface beyond the route this lane needs is not a
 * decision to make in passing.
 *
 * ✅ ALL THREE ARE OPEN NOW, each on its own ruling and each by its OWN filter:
 * `/patreon-standing` on #193's rider once that guard was armed and measured
 * refusing nobody, and the other two on #203 — which also found a FOURTH,
 * `/send-gift-recipient`, that this comment's count missed. Six filters on that
 * hook, `/run-now` alone still shut. The list above is left standing because
 * the discipline it describes is what each of those lanes followed.
 */
final class CheckoutAudienceRestController
{
    public const NAMESPACE = 'lg-member-sync/v1';
    public const ROUTE     = '/checkout-audience';

    /** The route as BuddyBoss's restriction filter sees it (exact match). */
    public const FULL_ROUTE = '/' . self::NAMESPACE . self::ROUTE;

    public static function register(): void
    {
        register_rest_route( self::NAMESPACE, self::ROUTE, [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'decide' ],
            'permission_callback' => [ self::class, 'authSharedSecret' ],
        ] );
    }

    /**
     * Let this ONE route past BuddyBoss's blanket REST restriction, so its own
     * shared-secret check is what decides. See the class docblock.
     *
     * @param mixed $endpoints
     * @return mixed
     */
    public static function exemptFromBuddyBossRestriction( $endpoints )
    {
        if ( ! is_array( $endpoints ) ) {
            return $endpoints;   // never replace another plugin's shape
        }
        if ( ! in_array( self::FULL_ROUTE, $endpoints, true ) ) {
            $endpoints[] = self::FULL_ROUTE;
        }
        return $endpoints;
    }

    public static function authSharedSecret( WP_REST_Request $req ): bool
    {
        $expected = (string) get_option( 'lgms_shared_secret', '' );
        if ( $expected === '' ) {
            return false;   // unconfigured is closed, never open
        }
        $given = (string) $req->get_header( 'x-lgms-token' );
        return $given !== '' && hash_equals( $expected, $given );
    }

    /**
     * The answer is deliberately two facts, not one.
     *
     * `allowed` alone would be ambiguous: `true` could mean "on the list" or
     * "the audience is off", and the caller needs to tell those apart to know
     * whether an absent answer is safe. Returning the STATE as well means the
     * Slim guard branches on something it was told rather than on something it
     * inferred.
     */
    public static function decide( WP_REST_Request $req )
    {
        $email = trim( (string) ( $req->get_param( 'email' ) ?? '' ) );
        $state = CheckoutAudience::state();

        return new WP_REST_Response( [
            'state'   => $state,
            'allowed' => CheckoutAudience::allowsEmail( $email ),
            // The copy lives here so the API and the join page cannot describe
            // the same refusal two different ways — PatreonStandingRestController's
            // rule, and the reason its `message` field exists.
            'message' => CheckoutAudience::refusalMessage(),
        ], 200 );
    }
}
