<?php

declare(strict_types=1);

namespace LGMS;

/**
 * The soft-launch cohort's WRITE model (docs/STRIPE-SOFT-LAUNCH-ALLOWLIST.md;
 * Ian 2026-08-11: "I want an admin dash where I can add them as I can find
 * users who agree to the terms etc.").
 *
 * Storage is EXACTLY the option StripeLifecycle's gate reads —
 * lgms_stripe_lifecycle_allowlist, a plain zero-indexed array of positive
 * ints. The Admin dash edits the cohort only through this class, so the
 * write shape and the read shape cannot drift (gated by
 * deploy/remediation/test-soft-launch-allowlist.php §5). Editing the option
 * by hand (`wp option update ... --format=json`) remains equivalent: the
 * gate's normalization accepts numeric strings, and the next dash write
 * re-canonicalizes.
 *
 * A companion option lgms_stripe_lifecycle_allowlist_added records WHEN each
 * id landed (uid => 'Y-m-d H:i:s' UTC) for the dash's "date added" column.
 * The lifecycle gate NEVER reads it — losing it costs display data only.
 */
final class CohortAllowlist
{
    public const OPT       = StripeLifecycle::ALLOWLIST_OPT;
    public const ADDED_OPT = 'lgms_stripe_lifecycle_allowlist_added';

    /** @return int[] the cohort, normalized exactly as the lifecycle gate reads it */
    public static function ids(): array
    {
        return array_keys( StripeLifecycle::allowlist() );
    }

    /** @return bool true if the id landed, false if already present / invalid */
    public static function add( int $wpUserId ): bool
    {
        if ( $wpUserId <= 0 ) {
            return false;
        }
        $ids = self::ids();
        if ( in_array( $wpUserId, $ids, true ) ) {
            return false;
        }
        $ids[] = $wpUserId;
        self::write( $ids );

        $added                = self::addedMap();
        $added[ $wpUserId ]   = gmdate( 'Y-m-d H:i:s' );
        update_option( self::ADDED_OPT, $added, false );
        return true;
    }

    /** @return bool true if removed, false if it was not in the cohort */
    public static function remove( int $wpUserId ): bool
    {
        $ids  = self::ids();
        $keep = array_values( array_filter( $ids, static fn ( int $id ): bool => $id !== $wpUserId ) );
        if ( count( $keep ) === count( $ids ) ) {
            return false;
        }
        self::write( $keep );

        $added = self::addedMap();
        unset( $added[ $wpUserId ] );
        update_option( self::ADDED_OPT, $added, false );
        return true;
    }

    /** When the id was added via the dash, or null (hand-set ids have no date). */
    public static function addedAt( int $wpUserId ): ?string
    {
        return self::addedMap()[ $wpUserId ] ?? null;
    }

    /**
     * Canonical write: zero-indexed list of positive ints, ascending —
     * stable for diffing `wp option get` output between boxes.
     *
     * @param int[] $ids
     */
    private static function write( array $ids ): void
    {
        $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
        sort( $ids );
        update_option( self::OPT, $ids, false );
    }

    /** @return array<int,string> */
    private static function addedMap(): array
    {
        $raw = get_option( self::ADDED_OPT, [] );
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $out = [];
        foreach ( $raw as $k => $v ) {
            if ( (int) $k > 0 && is_string( $v ) ) {
                $out[ (int) $k ] = $v;
            }
        }
        return $out;
    }
}
