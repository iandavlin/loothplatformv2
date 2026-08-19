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
 */
final class PatreonStandingRestController
{
    public const NAMESPACE = 'lg-member-sync/v1';
    public const ROUTE     = '/patreon-standing';

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
        return hash_equals( $expected, (string) $req->get_header( 'x_lgms_token' ) );
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
