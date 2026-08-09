<?php

declare(strict_types=1);

namespace LGMS\Wp;

use LGMS\Db;
use PDO;

/**
 * The identity front door for the Stripe pipeline (audit R1).
 *
 * `UserProvisioner::findOrProvision` keys on EMAIL AND NOTHING ELSE, and its
 * third branch is `wp_insert_user`. The platform continuously rewrites a
 * member's WP email to their *current Patreon* email, so a Stripe customer
 * whose Stripe email differs — a second address, or an Apple Private Relay
 * alias, both of which occur on live — misses the email lookup and MINTS A
 * SECOND ACCOUNT. That is the duplicate-account problem the dupe-merge lane
 * spent a week cleaning up, with a fresh source.
 *
 * This class replaces "email, then mint" with an ordered chain of identity
 * claims, strongest first. Email survives as ONE SIGNAL AMONG SEVERAL, never
 * as grounds to create anything.
 *
 *   1. wp_user_bridge on customer_id     — authoritative, already decided
 *   2. customer metadata `wp_user_id`    — an EXPLICIT bridge, set at checkout
 *                                          by a logged-in member. This is the
 *                                          one we actually want to grow.
 *   3. customer metadata `patreon_user_id` -> `lgpo_patreon_user_id` usermeta
 *                                          — the stable cross-system id;
 *                                          1,777 accounts carry it on live.
 *   4. email — accepted only when it resolves to exactly one account that is
 *      not already bridged to a DIFFERENT customer.
 *   5. nothing matched -> return null. The caller refuses to mint.
 *
 * `looth_uid` is deliberately absent from the chain: the audit named it, but
 * it does not exist as usermeta on live (0 rows), so building on it would be
 * building on nothing.
 *
 * Every step also refuses a candidate already bridged to a different customer.
 * That closes the read side of R3, where `writeBridge`'s ON DUPLICATE KEY
 * clause fires on `uk_wp_user` and updates the OTHER customer's row instead,
 * silently leaving the new customer unbridged forever.
 */
final class IdentityMatcher
{
    /**
     * @return array{wp_user_id:int, via:string}|null
     */
    public static function match( int $customerId, string $email ): ?array
    {
        $meta = self::customerMetadata( $customerId );

        // 2. explicit bridge asserted at checkout
        $claimed = isset( $meta['wp_user_id'] ) ? (int) $meta['wp_user_id'] : 0;
        if ( $claimed > 0 && get_user_by( 'id', $claimed ) && self::bridgeFreeFor( $claimed, $customerId ) ) {
            return [ 'wp_user_id' => $claimed, 'via' => 'customer.metadata.wp_user_id' ];
        }

        // 3. stable cross-system id
        $pid = isset( $meta['patreon_user_id'] ) ? trim( (string) $meta['patreon_user_id'] ) : '';
        if ( $pid !== '' ) {
            $hit = get_users( [
                'meta_key'   => 'lgpo_patreon_user_id',
                'meta_value' => $pid,
                'fields'     => 'ID',
                'number'     => 2,
            ] );
            if ( count( $hit ) === 1 && self::bridgeFreeFor( (int) $hit[0], $customerId ) ) {
                return [ 'wp_user_id' => (int) $hit[0], 'via' => 'lgpo_patreon_user_id' ];
            }
        }

        // 4. email, as a signal — never as grounds to create
        if ( $email !== '' ) {
            $u = get_user_by( 'email', $email );
            if ( $u && self::bridgeFreeFor( (int) $u->ID, $customerId ) ) {
                return [ 'wp_user_id' => (int) $u->ID, 'via' => 'email' ];
            }
        }

        return null;
    }

    /**
     * True when this WP user is bridgeable by $customerId — i.e. it is either
     * unbridged, or already bridged to this same customer. A user bridged to a
     * DIFFERENT customer is never stolen; the caller must escalate instead.
     */
    private static function bridgeFreeFor( int $wpUserId, int $customerId ): bool
    {
        $st = Db::pdo()->prepare( 'SELECT customer_id FROM wp_user_bridge WHERE wp_user_id = ? LIMIT 1' );
        $st->execute( [ $wpUserId ] );
        $owner = $st->fetchColumn();
        return $owner === false || (int) $owner === $customerId;
    }

    /** @return array<string,mixed> */
    private static function customerMetadata( int $customerId ): array
    {
        $st = Db::pdo()->prepare( 'SELECT metadata FROM customers WHERE id = ? LIMIT 1' );
        $st->execute( [ $customerId ] );
        $raw = $st->fetchColumn();
        if ( ! is_string( $raw ) || $raw === '' ) {
            return [];
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * Who else holds this email / bridge — used to build an operator-readable
     * refusal so a blocked checkout is a 30-second fix, not an investigation.
     */
    public static function describeConflict( int $customerId, string $email ): string
    {
        $bits = [];
        $u = $email !== '' ? get_user_by( 'email', $email ) : false;
        if ( $u ) {
            $st = Db::pdo()->prepare( 'SELECT customer_id FROM wp_user_bridge WHERE wp_user_id = ? LIMIT 1' );
            $st->execute( [ (int) $u->ID ] );
            $owner = $st->fetchColumn();
            $bits[] = $owner === false
                ? "email matches WP #{$u->ID} (unbridged)"
                : "email matches WP #{$u->ID}, already bridged to customer {$owner}";
        } else {
            $bits[] = 'no WP account carries this email';
        }
        $meta = self::customerMetadata( $customerId );
        $bits[] = $meta === [] ? 'customer has no metadata' : 'customer metadata keys: ' . implode( ',', array_keys( $meta ) );
        return implode( '; ', $bits );
    }
}
