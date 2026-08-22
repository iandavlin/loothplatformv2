<?php

declare(strict_types=1);

namespace LGMS\Wp;

use LGMS\Membership\PatreonStanding;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The Slim billing app's window onto Patreon standing.
 *
 *   POST /wp-json/lg-member-sync/v1/patreon-standing   body: { email }
 *   Auth: X-LGMS-Token, the same shared secret every other server-to-server
 *         call on this rail already uses (RestController::authSharedSecret).
 *
 * WHY A ROUTE AND NOT A QUERY. The billing app cannot read WordPress: its DB
 * user holds `ALL ON lg_membership` and `USAGE ON *.*` and nothing else, so
 * `wp_users` and `wp_options` are closed to it. (The `WP_DB_NAME` /
 * `WP_TABLE_PREFIX` keys in its .env are vestigial — no code reads them.) It
 * could reach `lg_patreon_members`, which lives in its own database, but that
 * would be a SECOND definition of "paying Patreon", keyed on an email instead
 * of on the member, free to drift from the one the sweep uses. One definition,
 * owned by the plugin that owns the rail, is the point of this route.
 *
 * THE FLAG IS THE REGISTRATION. With `lgms_double_pay_block` off this route
 * does not exist, so the Slim probe gets a 404, reports UNKNOWN, and checkout
 * proceeds exactly as it does today — one switch, three doors, and an OFF state
 * that is a real absence rather than a branch that answers "no". Same discipline
 * as CheckoutRestController and the webhook route.
 *
 * ⚠️ AND UNTIL #193 THE ON STATE WAS THE SAME AS THE OFF STATE, WHICH IS WORSE
 * THAN EITHER. Ian flipped `lgms_double_pay_block` ON on dev2 (2026-08-22) and
 * the route, measured immediately afterwards from 127.0.0.1 WITH the correct
 * shared secret, still answered:
 *
 *     401 {"code":"bb_rest_authorization_required"}
 *
 * BuddyBoss's `bb_restricate_rest_api` pre-empts the REST stack before any
 * route's own permission_callback whenever `bb-enable-private-rest-apis` is 1 —
 * it is, on both boxes, and it is re-armed by every DB reload. #181 measured and
 * REPORTED this rather than opening it, correctly, because at that time the flag
 * was off everywhere and nothing was relying on the answer.
 *
 * ⚠️ THE MOMENT THE FLAG WENT ON, THAT REPORT BECAME A LIVE DEFECT, AND IT FAILS
 * IN THE PERMISSIVE DIRECTION. This guard is fail-open by design — a hiccup in
 * the probe must never stop a legitimate sale — so an unreachable route answers
 * UNKNOWN and every checkout is waved through. The guard reads as armed on the
 * dash and refuses nobody: including the listed tester who actively pays
 * Patreon, which is the exact person it exists to stop.
 *
 * KEEPER RULED THE EXEMPTION 2026-08-22, on #193's rider, under the same three
 * conditions as the `/auth` one: this route's own shared-secret check is
 * untouched (it still requires a configured secret and still compares with
 * hash_equals, so a caller without the secret is refused exactly as before —
 * only WHICH check does the refusing changes); the exemption names this ONE
 * route; and gate 86's list of still-restricted routes was updated DELIBERATELY
 * rather than left to drift. `/sync-customer` and `/send-gift-codes` stay shut
 * on purpose — the five-minute `Sync::all()` sweep covers the first, and nothing
 * is waiting on the second.
 *
 * ⚠️ THE FILTER IS REGISTERED UNCONDITIONALLY EVEN THOUGH THE ROUTE IS NOT, and
 * that is deliberate. Naming a route that does not exist changes nothing —
 * WordPress 404s it either way, so the OFF state is untouched — while making the
 * exemption flag-conditional would create a SECOND place the flag has to be read
 * correctly before the guard can work. One switch, and it stays one.
 */
final class PatreonStandingRestController
{
    public const NAMESPACE = 'lg-member-sync/v1';
    public const ROUTE     = '/patreon-standing';

    /** The route as BuddyBoss's restriction filter sees it (exact match). */
    public const FULL_ROUTE = '/' . self::NAMESPACE . self::ROUTE;

    /**
     * Let this ONE route past BuddyBoss's blanket REST restriction, so its own
     * shared-secret check is what decides. See the class docblock for the
     * measurement, the ruling, and why this is a repair rather than a bypass.
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

    public static function maybeRegister(): void
    {
        if ( ! PatreonStanding::flagOn() ) {
            return;   // OFF: no route, no trace.
        }

        register_rest_route( self::NAMESPACE, self::ROUTE, [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'standing' ],
            'permission_callback' => [ self::class, 'authSharedSecret' ],
        ] );
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
     * The answer is deliberately thin: whether they are paying, what it is
     * called, and the exact words to show them. The caller renders nothing of
     * its own, so the copy cannot fork between the API and the join page.
     */
    public static function standing( WP_REST_Request $req )
    {
        $email = trim( (string) ( $req->get_param( 'email' ) ?? '' ) );
        if ( $email === '' ) {
            return new WP_REST_Response( [ 'error' => 'email is required' ], 400 );
        }

        $s = PatreonStanding::forEmail( $email );

        return new WP_REST_Response( [
            'active'     => (bool) $s['active'],
            'tier'       => $s['tier'],
            'tier_label' => $s['tier_label'],
            'reason'     => $s['reason'],
            'message'    => $s['active'] ? PatreonStanding::refusalMessage( $s ) : null,
            'manage_url' => $s['active'] ? PatreonStanding::manageUrl() : null,
        ], 200 );
    }
}
