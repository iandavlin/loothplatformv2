<?php

declare(strict_types=1);

namespace LGMS\Wp;

use LGMS\Arbiter;
use LGMS\RoleSourceWriter;
use Throwable;

/**
 * Capture manual WP-admin tier-role edits as a `manual_admin` source (G3).
 *
 * Without this, an admin who changes a member's tier on the WP Users screen
 * writes only wp_capabilities — no lg_role_sources row — so the next Arbiter
 * tick recomputes from the (unchanged) stripe/patreon sources and silently
 * clobbers the admin's edit. Here we record the admin's intent as a
 * `manual_admin` source and re-run the Arbiter so the change sticks and is
 * reconciled against the other sources (a higher paid tier still wins).
 *
 * Only genuine wp-admin edits are captured: REST (gift-auth), cron sweeps, and
 * front-end onboard all set roles too, but they own their own source rows, so
 * they're excluded. Arbiter itself writes via add_role/remove_role, which do
 * NOT fire `set_user_role`, so there is no Arbiter re-entrancy; the suppress
 * flag only guards the brief window of our own sync call.
 */
final class AdminRoleCapture
{
    /** Arbiter-managed tier roles (customer is intentionally excluded). */
    private const TIER_ROLES = [ 'looth1', 'looth2', 'looth3', 'looth4' ];

    private static bool $suppress = false;

    public static function boot(): void
    {
        add_action( 'set_user_role', [ self::class, 'onSetUserRole' ], 10, 3 );
    }

    /**
     * @param string[] $oldRoles
     */
    public static function onSetUserRole( int $userId, string $role, array $oldRoles ): void
    {
        if ( self::$suppress ) {
            return;
        }
        if ( ! in_array( $role, self::TIER_ROLES, true ) ) {
            return;
        }
        // Genuine wp-admin edit only — not REST (gift-auth), not cron, not the
        // front-end onboard, and only by someone who can promote users.
        if ( ! is_admin() ) {
            return;
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return;
        }
        if ( wp_doing_cron() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
            return;
        }
        if ( ! current_user_can( 'promote_users' ) ) {
            return;
        }

        try {
            RoleSourceWriter::report( $userId, 'manual_admin', $role );
        } catch ( Throwable $e ) {
            error_log( 'AdminRoleCapture: source write failed for #' . $userId . ': ' . $e->getMessage() );
            return;
        }

        self::$suppress = true;
        try {
            Arbiter::sync( $userId );
        } catch ( Throwable $e ) {
            error_log( 'AdminRoleCapture: Arbiter sync failed for #' . $userId . ': ' . $e->getMessage() );
        } finally {
            self::$suppress = false;
        }
    }
}
