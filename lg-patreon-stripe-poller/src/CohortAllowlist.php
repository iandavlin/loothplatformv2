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
 * entry landed (uid|email => 'Y-m-d H:i:s' UTC) for the dash's "date added"
 * column. The lifecycle gate NEVER reads it — losing it costs display data only.
 *
 * ─── #193: THE LIST ALSO TAKES EMAIL ADDRESSES ────────────────────────────
 * Ian, 2026-08-22: *"I thought the whitelist would have them generating a
 * wp-user like a normal new member join."* One store, two forms — positive
 * ints and email strings in the SAME option, read by
 * StripeLifecycle::allowlist() and ::allowlistEmails() respectively.
 *
 * ⚠️ WHICH IS WHY write() BELOW IS UNION-PRESERVING, AND IT IS THE ONE THING IN
 * THIS FILE THAT WOULD HAVE FAILED SILENTLY. It used to rebuild the whole
 * option from the id list with array_map('intval'), so the very first time
 * anybody added or removed a MEMBER through the dash, every email on the list
 * would have been dropped — no error, no notice, and the testers who could no
 * longer buy would have had no way to tell why. Both halves are now read,
 * merged and written back together, and gate 90 asserts it directly rather
 * than trusting the reading.
 */
final class CohortAllowlist
{
    public const OPT       = StripeLifecycle::ALLOWLIST_OPT;
    public const ADDED_OPT = 'lgms_stripe_lifecycle_allowlist_added';

    /** @return int[] the cohort's MEMBER ids, normalized exactly as the lifecycle gate reads it */
    public static function ids(): array
    {
        return array_keys( StripeLifecycle::allowlist() );
    }

    /** @return string[] the cohort's ADDRESSES, normalized exactly as the gate reads them (#193) */
    public static function emails(): array
    {
        return array_keys( StripeLifecycle::allowlistEmails() );
    }

    /**
     * Everything on the list, in one count, for anything that needs to say how
     * many people are admitted — the Health panel's "is the cohort empty?"
     * check most of all. Counting only the ids there would report EMPTY on a
     * list full of addresses and send whoever read it looking for a fault that
     * is not there.
     */
    public static function count(): int
    {
        return count( self::ids() ) + count( self::emails() );
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
        self::write( $ids, self::emails() );

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
        self::write( $keep, self::emails() );

        $added = self::addedMap();
        unset( $added[ $wpUserId ] );
        update_option( self::ADDED_OPT, $added, false );
        return true;
    }

    /**
     * ADD AN ADDRESS THAT MAY NOT HAVE AN ACCOUNT YET (#193).
     *
     * Validated and normalized here to exactly what StripeLifecycle::
     * allowlistEmails() will accept, so the dash can never store something the
     * decider then silently ignores — an entry visible on the list that admits
     * nobody is worse than a refused one.
     *
     * @return bool true if the address landed, false if already present / invalid
     */
    public static function addEmail( string $email ): bool
    {
        $email = self::normalizeEmail( $email );
        if ( $email === '' ) {
            return false;
        }
        if ( in_array( $email, self::emails(), true ) ) {
            return false;
        }
        self::write( self::ids(), array_merge( self::emails(), [ $email ] ) );

        $added            = self::addedMap();
        $added[ $email ]  = gmdate( 'Y-m-d H:i:s' );
        update_option( self::ADDED_OPT, $added, false );
        return true;
    }

    /** @return bool true if removed, false if that address was not on the list */
    public static function removeEmail( string $email ): bool
    {
        $email = self::normalizeEmail( $email );
        if ( $email === '' ) {
            return false;
        }
        $have = self::emails();
        $keep = array_values( array_filter( $have, static fn ( string $e ): bool => $e !== $email ) );
        if ( count( $keep ) === count( $have ) ) {
            return false;
        }
        self::write( self::ids(), $keep );

        $added = self::addedMap();
        unset( $added[ $email ] );
        update_option( self::ADDED_OPT, $added, false );
        return true;
    }

    /**
     * The normalization, in ONE place, shared by the writer and by whatever
     * needs to compare a typed address to a stored one. Mirrors
     * StripeLifecycle::allowlistEmails() exactly; '' means "this widens
     * nothing".
     */
    public static function normalizeEmail( string $email ): string
    {
        $email = strtolower( trim( $email ) );
        if ( $email === '' ) {
            return '';
        }
        $valid = function_exists( 'is_email' )
            ? (bool) is_email( $email )
            : (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );

        return $valid ? $email : '';
    }

    /**
     * When the entry was added via the dash, or null (hand-set entries have no
     * date). Takes an id or an address — the same column serves both.
     */
    public static function addedAt( int|string $entry ): ?string
    {
        if ( is_string( $entry ) ) {
            $entry = self::normalizeEmail( $entry );
            if ( $entry === '' ) {
                return null;
            }
        }
        return self::addedMap()[ $entry ] ?? null;
    }

    /**
     * Canonical write: ascending positive ints FIRST, then ascending addresses
     * — zero-indexed, stable for diffing `wp option get` output between boxes.
     *
     * ⚠️ BOTH HALVES, ALWAYS, EVEN WHEN ONLY ONE IS BEING EDITED (#193). Every
     * caller passes the full picture and this rebuilds the whole option from
     * it. The previous version took ids alone and wrote ids alone, which was
     * correct while ids were all there was and becomes silent data loss the
     * moment they are not: adding one member would have deleted every tester
     * address on the list. Callers pass `self::emails()` when they are only
     * touching ids, and `self::ids()` when they are only touching addresses, so
     * the union is re-stated at every write rather than assumed.
     *
     * @param int[]    $ids
     * @param string[] $emails
     */
    private static function write( array $ids, array $emails ): void
    {
        $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
        $ids = array_values( array_filter( $ids, static fn ( int $i ): bool => $i > 0 ) );
        sort( $ids );

        $clean = [];
        foreach ( $emails as $e ) {
            $e = self::normalizeEmail( (string) $e );
            if ( $e !== '' ) {
                $clean[ $e ] = true;
            }
        }
        $clean = array_keys( $clean );
        sort( $clean );

        update_option( self::OPT, array_merge( $ids, $clean ), false );
    }

    /**
     * @return array<int|string,string> keyed by user id OR by address — the
     *         same column serves both, and an int-only cast here would have
     *         quietly dropped every address's date.
     */
    private static function addedMap(): array
    {
        $raw = get_option( self::ADDED_OPT, [] );
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $out = [];
        foreach ( $raw as $k => $v ) {
            if ( ! is_string( $v ) ) {
                continue;
            }
            if ( is_int( $k ) || ctype_digit( (string) $k ) ) {
                if ( (int) $k > 0 ) {
                    $out[ (int) $k ] = $v;
                }
                continue;
            }
            $e = self::normalizeEmail( (string) $k );
            if ( $e !== '' ) {
                $out[ $e ] = $v;
            }
        }
        return $out;
    }
}
