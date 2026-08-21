<?php

declare(strict_types=1);

namespace LGMS\Membership;

/**
 * Does this member hold an UNEXPIRED comp membership?
 *
 * Ian, 2026-08-21: *"looth4 is the everything bypass the stripe side of
 * membeship needs to respect what we have built there."* Keeper sharpened it
 * the same day: respect **unexpired** looth4, not looth4.
 *
 * ⚠️ THIS CLASS STILL ENFORCES NOTHING, AND MUST NOT START. It is a read-only
 * predicate: nothing here demotes anybody, writes any meta, or schedules
 * anything. #183 re-armed comp expiry around it — see `CompExpiry` for the
 * policy and the sweep, and `Arbiter::sync` for the one place a role is ever
 * written. Keeping the question ("has this comp lapsed?") separate from the
 * answer ("then what?") is what let #181 ask it safely before anything acted.
 *
 * ── THE TIMEZONE, SETTLED (#183, 2026-08-21) ────────────────────────────────
 * `looth4_expires_at` holds a bare `Y-m-d H:i:s` with no offset. #181 left the
 * zone deliberately unsettled — safe for a predicate nobody acted on, a real
 * decision for one that demotes people — and read it in the SITE's timezone.
 * **It is UTC**, on two independent proofs:
 *
 *   1. The writer's own source, captured at cutover
 *      (cutover/batch-output/BATCH-04-results.md:158):
 *          define( 'LG_L4E_META_EXPIRES', 'looth4_expires_at' );
 *                                      // stored as Y-m-d H:i:s UTC
 *   2. The data agrees, independently of the comment. `user_registered` is
 *      UTC. User 1829 registered 2026-04-21 21:11:27 and their expiry reads
 *      2026-07-28 21:11:00 — the same minute-of-day. User 1865: registered
 *      15:26:04, expiry 15:25:00. Two for two.
 *
 * ⚠️ SO THE SITE-TIMEZONE READ WAS A BUG, AND A DIRECTIONAL ONE. Both boxes
 * run `timezone_string = America/New_York`, so reading these values locally
 * placed every expiry **four hours LATE** (2026-07-28 21:11 became 01:11 UTC on
 * the 29th). Harmless while nothing enforced. Not harmless now. Anything that
 * WRITES this meta must write UTC too — the Comp Timers tab says so on the
 * field.
 *
 * THE STATE OF THE WORLD it describes, measured 2026-08-21 and true on both
 * boxes: 14 comp holders on live (15 on dev2), of whom exactly 2 carry an
 * expiry at all — users 1829 and 1865 — and BOTH dates are already past. Ian
 * ruled those two LEFT ALONE; `CompExpiry`'s cutover fence is what holds them.
 */
final class CompStanding
{
    public const ROLE = 'looth4';
    public const META = 'looth4_expires_at';

    /**
     * The expiry as a unix timestamp, or null when the member carries no
     * expiry at all.
     *
     * ⚠️ NULL MEANS "NEVER EXPIRES", NOT "EXPIRED". It is the state 12 of the
     * 14 live comp holders are in, so getting this backwards would read every
     * one of them as lapsed.
     */
    public static function expiresAt( int $wpUserId ): ?int
    {
        if ( $wpUserId <= 0 ) {
            return null;
        }
        $raw = trim( (string) get_user_meta( $wpUserId, self::META, true ) );
        if ( $raw === '' ) {
            return null;
        }

        return self::parse( $raw );
    }

    /**
     * Parse a stored expiry string to a timestamp, or null if it is not a date.
     *
     * UTC, always — see the class docblock. Split out from expiresAt() so the
     * gate can assert the zone directly on a value, and so the admin screen can
     * validate what an operator types through the same code that reads it back.
     */
    public static function parse( string $raw ): ?int
    {
        $raw = trim( $raw );
        if ( $raw === '' ) {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable( $raw, new \DateTimeZone( 'UTC' ) );
        } catch ( \Throwable $e ) {
            // An unparseable date is NOT an expiry. Treating garbage as "lapsed"
            // would demote a comp member because somebody fat-fingered a field.
            return null;
        }

        return $dt->getTimestamp();
    }

    /** Does the member carry the comp role at all, expired or not? */
    public static function holdsComp( int $wpUserId ): bool
    {
        if ( $wpUserId <= 0 ) {
            return false;
        }
        $user = get_user_by( 'id', $wpUserId );
        return $user !== false && in_array( self::ROLE, (array) ( $user->roles ?? [] ), true );
    }

    /**
     * THE PREDICATE keeper specified: holds looth4 AND (no expiry OR it is in
     * the future).
     */
    public static function isActiveComp( int $wpUserId ): bool
    {
        if ( ! self::holdsComp( $wpUserId ) ) {
            return false;
        }
        $exp = self::expiresAt( $wpUserId );
        return $exp === null || $exp > time();
    }

    /**
     * Holds the comp role but the date has passed.
     *
     * ⚠️ THIS IS NOT "WILL BE DEMOTED". Whether a lapsed comp is actually
     * acted on is `CompExpiry`'s question, and it holds anything whose timer
     * ran out before the enforcement cutover — which today is both of them.
     */
    public static function isExpiredComp( int $wpUserId ): bool
    {
        if ( ! self::holdsComp( $wpUserId ) ) {
            return false;
        }
        $exp = self::expiresAt( $wpUserId );
        return $exp !== null && $exp <= time();
    }

    /** One short phrase for a log line or an operator notice. */
    public static function describe( int $wpUserId ): string
    {
        if ( ! self::holdsComp( $wpUserId ) ) {
            return 'not a comp member';
        }
        $exp = self::expiresAt( $wpUserId );
        if ( $exp === null ) {
            return 'active comp member (looth4, no expiry set)';
        }
        return sprintf(
            'comp member (looth4, %s %s)',
            $exp > time() ? 'expires' : 'EXPIRED',
            gmdate( 'Y-m-d', $exp ),
        );
    }
}
