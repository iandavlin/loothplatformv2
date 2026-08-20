<?php

declare(strict_types=1);

namespace LGMS\Membership;

use LGMS\Db;
use Throwable;

/**
 * Members paying on BOTH rails at once — the list Ian looks at (#149).
 *
 * WHY THIS EXISTS AT ALL, given #150 closes the door: the block only works in
 * the direction we control. Nothing of ours runs at patreon.com, so a member
 * who pays here and then pledges over there cannot be stopped by any code in
 * this repo. That direction is unblockable, so it must be VISIBLE instead —
 * stated plainly rather than quietly reconciled, because a silent fix to
 * somebody's billing is worse than a list Ian can read.
 *
 * IT ASKS PatreonStanding, it does not re-decide. One definition of "paying
 * Patreon" serves the three purchase doors and this screen; a fourth copy here
 * would be the drift the whole design is arranged to prevent.
 *
 * READ ONLY. Nothing in this class writes, and nothing about these members is
 * changed by looking at them. What to DO about a dual payer is Ian's call.
 *
 * Bounded by the number of LIVE Stripe subscriptions, not by the membership,
 * so it stays cheap: it walks the subscriptions and asks about those members,
 * rather than walking 1,700 patrons asking about each.
 */
final class DualPayers
{
    /**
     * @return array<int, array{
     *   wp_user_id:int, login:string, wp_email:string,
     *   patreon:array, customer_id:int, stripe_email:string, stripe_status:string,
     *   stripe_period_end:?string, stripe_tier:?string, stripe_cents:?int,
     *   stripe_interval:?string, matched_by:string, payment_source_says:string }>
     */
    public static function find( int $limit = 500 ): array
    {
        try {
            $st = Db::pdo()->prepare(
                "SELECT s.id AS sub_id, s.status, s.current_period_end,
                        c.id AS customer_id, c.email AS stripe_email,
                        pr.unit_amount_cents, pr.`interval` AS price_interval,
                        prod.ref AS product_ref,
                        b.wp_user_id AS bridged_wp_user_id
                   FROM subscriptions s
                   JOIN customers  c    ON c.id = s.customer_id AND c.deleted_at IS NULL
              LEFT JOIN prices     pr   ON pr.stripe_price_id = s.stripe_price_id
              LEFT JOIN products   prod ON prod.id = pr.product_id
              LEFT JOIN wp_user_bridge b ON b.customer_id = c.id
                  WHERE s.status IN ('active','trialing','past_due')
               ORDER BY s.id DESC
                  LIMIT " . max( 1, min( 5000, $limit ) )
            );
            $st->execute();
            $rows = $st->fetchAll( \PDO::FETCH_ASSOC ) ?: [];
        } catch ( Throwable $e ) {
            error_log( 'LGMS DualPayers::find: ' . $e->getMessage() );
            return [];
        }

        $out = [];
        foreach ( $rows as $row ) {
            // The bridge is authoritative when present. Email is a fallback,
            // and a weaker one — a shared household address matches the wrong
            // person — so how the match was made is carried into the row and
            // shown on screen rather than hidden behind a total.
            $uid        = (int) ( $row['bridged_wp_user_id'] ?? 0 );
            $matchedBy  = 'bridge';
            if ( $uid <= 0 ) {
                $byEmail = get_user_by( 'email', (string) $row['stripe_email'] );
                $uid = $byEmail ? (int) $byEmail->ID : 0;
                $matchedBy = 'email';
            }
            if ( $uid <= 0 ) {
                continue;   // a Stripe customer with no WP account cannot be double-paying
            }

            $standing = PatreonStanding::forUser( $uid );
            if ( empty( $standing['active'] ) ) {
                continue;
            }
            if ( isset( $out[ $uid ] ) ) {
                continue;   // one row per member, not one per subscription
            }

            $user = get_userdata( $uid );
            $out[ $uid ] = [
                'wp_user_id'          => $uid,
                'login'               => $user ? (string) $user->user_login  : '(deleted)',
                'wp_email'            => $user ? (string) $user->user_email : '',
                'patreon'             => $standing,
                'customer_id'         => (int) $row['customer_id'],
                'stripe_email'        => (string) $row['stripe_email'],
                'stripe_status'       => (string) $row['status'],
                'stripe_period_end'   => $row['current_period_end'] !== null ? (string) $row['current_period_end'] : null,
                'stripe_tier'         => $row['product_ref'] !== null ? (string) $row['product_ref'] : null,
                'stripe_cents'        => $row['unit_amount_cents'] !== null ? (int) $row['unit_amount_cents'] : null,
                'stripe_interval'     => $row['price_interval'] !== null ? (string) $row['price_interval'] : null,
                'matched_by'          => $matchedBy,
                // Shown for information only. It is one slot the rails overwrite
                // each other in, so expect it to disagree with the two columns
                // beside it — that disagreement is the point of printing it.
                'payment_source_says' => (string) ( get_user_meta( $uid, 'payment_source', true ) ?: '(unset)' ),
            ];
        }

        ksort( $out );
        return array_values( $out );
    }

    /**
     * When the Patreon sweep last actually RAN — which is the honest age for
     * this screen. `lg_patreon_members.synced_at` is not: it is
     * ON UPDATE CURRENT_TIMESTAMP, so it records the last time a patron's
     * details CHANGED and makes the steadiest members look the most stale.
     *
     * Stored as a unix timestamp, so it is formatted here rather than printed
     * raw — a ten-digit number on screen reads as an error, not as a date.
     */
    public static function lastSweepAt(): ?string
    {
        $t = get_option( 'lgpo_last_sync_time', '' );
        if ( is_numeric( $t ) && (int) $t > 0 ) {
            return gmdate( 'Y-m-d H:i', (int) $t ) . ' UTC';
        }
        return is_string( $t ) && $t !== '' ? $t : null;
    }
}
