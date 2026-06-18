<?php

declare(strict_types=1);

namespace LGMS\Patreon;

/**
 * Read-only adapter that surfaces Patreon-attributed tier state from the
 * data LGPO (lg-patreon-onboard) writes to WP usermeta + roles.
 *
 * LGPO already owns the Patreon API polling cron — this adapter does NOT
 * make any Patreon API calls. It just reads what LGPO has cached on-box.
 *
 * Wire-in: RoleSourceWriter::readAllForUser() merges this reader's output
 * into its source-map under the 'patreon' key so the Arbiter and
 * InternalRestController see Patreon alongside stripe / manual_admin.
 *
 * Coexistence with Stripe: LGPO skips users with payment_source=stripe,
 * and this reader returns null for non-patreon payment_source. So a
 * Stripe-owned user never has a patreon source row materialised.
 */
final class PatreonSourceReader
{
    /**
     * @return array{source:string,tier:string,tier_id:?string}|null
     *   null = not Patreon-managed; otherwise the source record.
     */
    public static function readForUser( int $wpUserId ): ?array
    {
        if ( $wpUserId <= 0 ) {
            return null;
        }

        $paymentSource = get_user_meta( $wpUserId, 'payment_source', true );
        if ( $paymentSource !== 'patreon' ) {
            return null;
        }

        $user = get_userdata( $wpUserId );
        if ( ! $user ) {
            return null;
        }

        $roles = (array) $user->roles;
        $tier  = 'looth1';
        foreach ( [ 'looth3', 'looth2', 'looth1' ] as $r ) {
            if ( in_array( $r, $roles, true ) ) {
                $tier = $r;
                break;
            }
        }

        $tierId = get_user_meta( $wpUserId, 'lgpo_patreon_tier_id', true );

        return [
            'source'  => 'patreon',
            'tier'    => $tier,
            'tier_id' => is_string( $tierId ) && $tierId !== '' ? $tierId : null,
        ];
    }
}
