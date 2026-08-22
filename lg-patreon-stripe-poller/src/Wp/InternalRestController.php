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

        /**
         * STRIPE TEST GROUP, as a CAPABILITY (Ian 2026-08-15: "a way for only
         * white listed users to be able to see the menu for stripe, or the
         * pages for stripe").
         *
         * Computed HERE, once, rather than by each surface reading the option:
         * this is what `capabilities` already is, it rides whoami to every
         * consumer exactly as manage_options does, and no caller has to be
         * taught to pass a user id it may not have. A surface that asks
         * "should this person see the Stripe menu?" gets the same answer the
         * page gate will give when they click it — which is the whole point,
         * because a menu entry that leads to a refusal is worse than no entry.
         *
         * Administrators keep their pre-launch access: the list ADDS people,
         * it never takes Ian's own QA route away.
         */
        $caps['stripe_testgroup'] = ( $caps['manage_options'] ?? false )
            || \LGMS\StripeLifecycle::inCohort( $wpUserId );

        /**
         * IS PATREON CHARGING THIS MEMBER RIGHT NOW?  (#196.)
         *
         * Ian, 2026-08-22, verbatim: "Can you check and see if a user that has
         * a patreon would have a menu for join in the profile chip? If so we
         * need to change that to switch."
         *
         * Measured before this line existed: user 1953 is a listed tester with
         * an active paid Patreon pledge (looth2, next charge 2026-09-02), and
         * the account menu offered her Join. #150's guard would refuse her at
         * checkout — the presence-is-not-reachability trap on a money door, and
         * on dev2 the guard is not even armed (`lgms_double_pay_block` absent),
         * so the menu is the ONLY thing between her and a second charge.
         *
         * WHY A CAPABILITY, and why here. The shared header renders on seven
         * apps under seven unix users with NO database of their own, so it
         * cannot ask this question itself. `capabilities` is the channel that
         * already answers per-viewer questions for it — it is how
         * stripe_testgroup reaches the same menu — and it costs nothing to
         * extend: `wp_user_id` is the PRIMARY KEY of `lg_patreon_members`, so
         * this is a PK read on a path that already makes a loopback HTTP call.
         *
         * COMPUTED UNCONDITIONALLY, not only for the cohort. Narrowing it to
         * `stripe_testgroup` would save one PK read and make `false` mean two
         * different things — "not paying Patreon" and "not a tester" — which is
         * the same shape as the named-pass-through discard that cost a day on
         * 8/16. One fact, one answer.
         *
         * NO SECOND DEFINITION. PatreonStanding is the one place that decides
         * what "already paying" means (#150); all three purchase doors ask it,
         * and so does this. Never `payment_source` — one slot, descriptive
         * only, and the two rails overwrite each other in it.
         *
         * FAILS CLOSED TO FALSE, which is today's behaviour (Join). An unknown
         * must never send a member who has no Patreon to a page about
         * cancelling one, so the catch is a `false`, not a `true`.
         */
        $caps['patreon_paying'] = false;
        try {
            $caps['patreon_paying'] =
                \LGMS\Membership\PatreonStanding::forUser( $wpUserId )['active'] === true;
        } catch ( \Throwable $e ) {
            error_log( 'LGMS InternalRestController patreon_paying: ' . $e->getMessage() );
        }

        return $caps;
    }
}
