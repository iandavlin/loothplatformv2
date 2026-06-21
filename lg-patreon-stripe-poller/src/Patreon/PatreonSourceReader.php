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
     * @return array{source:string,tier:?string,tier_id:?string}|null
     *   null = not Patreon-managed; otherwise the source record. 'tier' may be
     *   null = patreon-managed but no active paid tier (lapsed / declined / free).
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

        $tierId = get_user_meta( $wpUserId, 'lgpo_patreon_tier_id', true );
        $tierId = is_string( $tierId ) && $tierId !== '' ? $tierId : null;

        // Derive the tier from the Patreon API truth the sweep persists — the
        // entitled tier_id mapped through lgpo_tier_map — NOT from $user->roles.
        // Reading the current WP role was circular: the Arbiter would only ever
        // hear back the role the member already had, so an upgrade could never
        // apply (it could only ever confirm the status quo). A patreon-managed
        // member with no mapped PAID tier (lapsed, declined, or free looth1)
        // resolves to null, so the Arbiter performs a real downgrade instead of
        // pinning the member at their current role.
        $tier = null;
        if ( $tierId !== null ) {
            $map = get_option( 'lgpo_tier_map', [] );
            if ( is_array( $map ) && isset( $map[ $tierId ] ) ) {
                $mapped = (string) $map[ $tierId ];
                // Only paid Patreon tiers are asserted by this source. looth1 is
                // the free floor (treated as null); looth4 is comp/manual and is
                // never granted via Patreon — leave it to its own source.
                if ( $mapped === 'looth2' || $mapped === 'looth3' ) {
                    $tier = $mapped;
                }
            }
        }

        return [
            'source'  => 'patreon',
            'tier'    => $tier,
            'tier_id' => $tierId,
        ];
    }
}
