<?php

declare(strict_types=1);

namespace LGMS;

/**
 * Sole writer of wp_capabilities for looth1..4 tiers.
 *
 * Reads all source rows for a WP user, computes the winning tier
 * (highest across active sources), and writes wp_capabilities
 * preserving every non-tier role (administrator, bbp_participant, etc.).
 *
 * looth4 users are protected: never modified.
 */
final class Arbiter
{
    private const TIER_ROLES = [ 'looth1', 'looth2', 'looth3', 'looth4' ];

    public static function sync(int $wpUserId): array
    {
        $user = get_user_by( 'id', $wpUserId );
        if ( ! $user ) {
            return [ 'ok' => false, 'reason' => 'no such WP user' ];
        }

        // Protected — never touch.
        if ( in_array( 'looth4', $user->roles, true ) ) {
            return [ 'ok' => true, 'reason' => 'looth4 protected, skipped' ];
        }

        // Stripe-source coexistence guard (mirrors LGPO's existing skip):
        // a user with payment_source=stripe and a non-looth1 tier role
        // owns their own role via the Stripe pipeline. If they don't have
        // a current lg_role_sources.stripe row (legacy users, pre-source-
        // writer-system carryover, or replay edge cases), the Arbiter
        // would otherwise compute winning_tier=null from empty sources
        // and silently downgrade them. Skip instead.
        if ( get_user_meta( $wpUserId, 'payment_source', true ) === 'stripe'
             && empty( array_intersect( $user->roles, [ 'looth1' ] ) ) ) {
            return [ 'ok' => true, 'reason' => 'stripe-source w/o source row, skipped' ];
        }

        $oldTier = self::currentTier( (array) $user->roles );
        $sources = RoleSourceWriter::readAllForUser( $wpUserId );
        $winning = self::computeWinningTier( $sources );

        // Remove existing tier roles that aren't the winner.
        // looth1 is the default-for-everyone starter tier — it is never
        // backed by a payment source (gift buyers + every other registered
        // user start there) so Arbiter must NOT remove it. UserProvisioner
        // grants it on signup; from then on it's sticky. Removing it on
        // every tick would strip gift-management capability from every
        // standalone gift buyer right after they signed up.
        foreach ( self::TIER_ROLES as $role ) {
            if ( $role === 'looth1' ) {
                continue;
            }
            if ( in_array( $role, $user->roles, true ) && $role !== $winning ) {
                $user->remove_role( $role );
            }
        }

        // Add the winning role if not already present.
        if ( $winning !== null && ! in_array( $winning, $user->roles, true ) ) {
            $user->add_role( $winning );
        }

        // BB Profile Type sync: looth1-or-nothing → 'starter' (hidden from
        // member directory / network search via the BB type's visibility
        // flags). looth2+ → clear so they reappear. Mirrors role transitions
        // so directory visibility tracks paid status without a custom filter.
        if ( function_exists( 'bp_set_member_type' ) ) {
            if ( $winning === null || $winning === 'looth1' ) {
                bp_set_member_type( $wpUserId, 'starter' );
            } else {
                bp_set_member_type( $wpUserId, '' );
            }
        }

        // Welcome trigger: stamp a one-shot user meta whenever the user is
        // upgraded INTO a paid tier (looth2+) from a lower/null state.
        // The wp_footer modal hook reads this meta on the next page load
        // and shows a "your membership is active" celebration. The flag
        // is consumed (deleted) by the dismiss-welcome REST endpoint.
        // Idempotent: re-running Arbiter on a stable looth2 user does NOT
        // re-set the flag (oldTier === winning).
        if ( self::isUpgradeToPaid( $oldTier, $winning ) ) {
            update_user_meta( $wpUserId, '_lg_pending_welcome', (string) $winning );
            // Fire the welcome email once. WelcomeMailer is idempotent —
            // it tracks delivery via _lg_welcome_email_sent_at user meta
            // and silently bails on repeat calls. The modal handles
            // returning users; this email handles users who don't return
            // on their own (e.g. because they backed out of Stripe and
            // the cron sweep / webhook provisioned silently).
            \LGMS\Wp\WelcomeMailer::sendIfNeeded( $wpUserId, (string) $winning );
        }

        // Cache-invalidation hook for profile-app + any other subscriber.
        // Fires only on actual tier transitions, not no-ops. Provenance
        // mirrors what the /user-context endpoint would compute, so a
        // subscriber re-fetching after the purge gets the same value.
        if ( $oldTier !== $winning ) {
            $provenance = \LGMS\Wp\InternalRestController::deriveProvenance( $winning, $sources );
            do_action( 'looth_tier_changed', $wpUserId, $oldTier, $winning, $provenance );
        }

        return [ 'ok' => true, 'winning_tier' => $winning, 'sources' => $sources, 'old_tier' => $oldTier ];
    }

    /**
     * Highest looth* role currently on the user (lookup, no DB write).
     * Returns null if none of the tier roles are present.
     */
    private static function currentTier( array $roles ): ?string
    {
        $best = null;
        foreach ( self::TIER_ROLES as $role ) {
            if ( in_array( $role, $roles, true ) ) {
                if ( $best === null || strcmp( $role, $best ) > 0 ) {
                    $best = $role;
                }
            }
        }
        return $best;
    }

    /**
     * True when the transition $old → $new represents a real upgrade INTO
     * a paid tier. looth1 is the starter (free) tier and does not trigger
     * the welcome modal; looth2/3/4 are paid and do.
     */
    private static function isUpgradeToPaid( ?string $old, ?string $new ): bool
    {
        if ( $new === null || $new === 'looth1' ) {
            return false;
        }
        if ( ! in_array( $new, [ 'looth2', 'looth3', 'looth4' ], true ) ) {
            return false;
        }
        if ( $old === null ) {
            return true;  // first-ever tier assignment, paid
        }
        return strcmp( $new, $old ) > 0;
    }

    /**
     * Highest of looth1..4 across sources reporting non-null tiers.
     * If we have any rows but none report a tier, fall back to looth1
     * (lapsed). If no rows at all, return null (don't touch the user).
     */
    private static function computeWinningTier(array $sources): ?string
    {
        if ( $sources === [] ) {
            return null;
        }
        $best = null;
        foreach ( $sources as $tier ) {
            if ( $tier === null ) {
                continue;
            }
            if ( ! in_array( $tier, self::TIER_ROLES, true ) ) {
                continue;
            }
            if ( $best === null || strcmp( $tier, $best ) > 0 ) {
                $best = $tier;
            }
        }
        return $best ?? 'looth1';
    }
}
