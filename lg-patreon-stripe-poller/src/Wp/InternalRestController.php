<?php

declare(strict_types=1);

namespace LGMS\Wp;

use LGMS\RoleSourceWriter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Internal-only REST channel for the strangler coordination.
 *
 * Namespace: looth-internal/v1
 * Auth: shared-secret header X-LG-Internal-Auth, hash_equals against
 *       LG_INTERNAL_SECRET (loaded from /etc/lg-internal-secret in
 *       wp-config.php).
 *
 * Symmetrical channel: profile-app -> poller for tier lookup (this
 * controller), poller -> profile-app for cache invalidation (PurgeNotifier).
 * Same secret in both directions.
 *
 * NOT mixed into LGMS\Wp\RestController because the auth model is
 * different (shared-secret vs WP cookie+nonce) and the lifecycle is
 * different (this serves only internal service callers, not browsers).
 */
final class InternalRestController
{
    public const NAMESPACE = 'looth-internal/v1';

    /** Tier-role -> public tier vocabulary. Spec: STRANGLER-COORDINATION.md §1. */
    private const TIER_MAP = [
        'looth1' => 'public',
        'looth2' => 'lite',
        'looth3' => 'pro',
        'looth4' => 'pro',
    ];

    /** Capabilities exposed in the response. Add as consumers ask. */
    private const CAPS = [ 'edit_posts', 'manage_options', 'edit_archive_poc' ];

    public static function register(): void
    {
        register_rest_route( self::NAMESPACE, '/user-context/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'userContext' ],
            'permission_callback' => [ self::class, 'authSharedSecret' ],
            'args'                => [
                'id' => [
                    'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0,
                ],
            ],
        ] );
    }

    public static function authSharedSecret( WP_REST_Request $req ): bool
    {
        $expected = defined( 'LG_INTERNAL_SECRET' ) ? (string) LG_INTERNAL_SECRET : '';
        if ( $expected === '' ) {
            return false;
        }
        $provided = (string) $req->get_header( 'x_lg_internal_auth' );
        return hash_equals( $expected, $provided );
    }

    public static function userContext( WP_REST_Request $req )
    {
        $wpUserId = (int) $req['id'];
        $user     = get_user_by( 'id', $wpUserId );
        if ( ! $user ) {
            return new WP_Error( 'no_such_user', 'User not found', [ 'status' => 404 ] );
        }

        $tierRole = self::currentTierRole( (array) $user->roles );
        $tier     = $tierRole === null ? 'public' : ( self::TIER_MAP[ $tierRole ] ?? 'public' );

        return new WP_REST_Response( [
            'tier'         => $tier,
            'provenance'   => self::deriveProvenance( $tierRole, RoleSourceWriter::readAllForUser( $wpUserId ) ),
            'capabilities' => self::capabilities( $wpUserId ),
        ], 200 );
    }

    /** Highest looth* role on the user, or null if none present. */
    public static function currentTierRole( array $roles ): ?string
    {
        $best = null;
        foreach ( [ 'looth1', 'looth2', 'looth3', 'looth4' ] as $role ) {
            if ( in_array( $role, $roles, true ) ) {
                if ( $best === null || strcmp( $role, $best ) > 0 ) {
                    $best = $role;
                }
            }
        }
        return $best;
    }

    /**
     * Derive provenance from lg_role_sources + current tier role.
     *
     * Enum (locked by STRANGLER-COORDINATION.md §1): paid | comp | lapsed | new.
     *
     * TODO: gift-recipient case. Today gift-paid registers as 'paid' because
     * the tier IS paid-for, just by a third party. If a future feature wants
     * to distinguish self-paid from received-as-gift, add a 5th enum value
     * ('gifted') then — do not speculatively expand now.
     */
    public static function deriveProvenance( ?string $tierRole, array $sources ): string
    {
        // looth4 is admin-only (Arbiter protects it from any source-driven
        // change). Always comp.
        if ( $tierRole === 'looth4' ) {
            return 'comp';
        }

        $hasComp        = isset( $sources['manual_admin'] ) && $sources['manual_admin'] !== null;
        $hasActivePaid  = false;
        $hasLapsedPaid  = false;
        foreach ( [ 'stripe', 'patreon' ] as $src ) {
            if ( ! array_key_exists( $src, $sources ) ) {
                continue;
            }
            if ( $sources[ $src ] === null ) {
                $hasLapsedPaid = true;
            } else {
                $hasActivePaid = true;
            }
        }

        if ( $hasComp ) {
            return 'comp';
        }
        if ( $hasActivePaid ) {
            return 'paid';
        }
        if ( $hasLapsedPaid ) {
            return 'lapsed';
        }
        return 'new';
    }

    /**
     * Capability map. user_can() for the named caps, plus a role-membership
     * check for moderate_forums (briefing §1: bbp_moderator OR bbp_keymaster
     * OR administrator).
     */
    private static function capabilities( int $wpUserId ): array
    {
        $caps = [];
        foreach ( self::CAPS as $cap ) {
            $caps[ $cap ] = (bool) user_can( $wpUserId, $cap );
        }

        $user = get_user_by( 'id', $wpUserId );
        $roles = $user ? (array) $user->roles : [];
        $caps['moderate_forums'] = (bool) array_intersect(
            $roles,
            [ 'administrator', 'bbp_keymaster', 'bbp_moderator' ]
        );

        return $caps;
    }
}
