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
 * ⚠️ THIS CLASS ENFORCES NOTHING, AND MUST NOT START. It is a read-only
 * predicate. Nothing here demotes anybody, writes any meta, or schedules
 * anything. Re-arming comp expiry is **#183**, ruled and queued; Ian ruled the
 * two already-overdue accounts are LEFT ALONE for now. This exists so that
 * #183 inherits one predicate rather than writing a second one, and so that
 * #181's gate asserts the right rule instead of encoding "comped forever".
 *
 * THE GAP IT DESCRIBES, measured 2026-08-21 and true on both boxes. The meta
 * `looth4_expires_at` exists on 2 of the comp holders and BOTH dates are
 * already past (users 1829 and 1865). Nothing enforces them: the expiry plugin
 * is not installed, not in mu-plugins, not in `active_plugins`, and no cron
 * event mentions it. There is not one reference to the meta key anywhere in
 * this monorepo — this file is the first. So today an expired comp still holds
 * looth4, still reads as `pro` to `lg-viewer-tier.php`, and is still skipped by
 * `Arbiter::sync`'s looth4 early-return. That is the #183 hole, stated; it is
 * not this lane's to close.
 *
 * ⚠️ THE TIMEZONE IS DELIBERATELY NOT SETTLED HERE, and #183 must settle it
 * before anything enforces. The stored values are bare `Y-m-d H:i:s` with no
 * offset, so "has it passed" is ambiguous by up to a day at the boundary. This
 * class reads them in the SITE's timezone when WordPress can tell it one, and
 * UTC otherwise — a choice that is safe for a predicate nobody acts on and
 * would be a real decision for one that demotes people. Note the trap waiting
 * there: `wp_date('G', current_time('timestamp'))` double-shifts, which is how
 * a "08:00" digest once computed hour 7 at 11:45 local.
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

        $tz = null;
        if ( function_exists( 'wp_timezone' ) ) {
            try {
                $tz = wp_timezone();
            } catch ( \Throwable $e ) {
                $tz = null;
            }
        }

        try {
            $dt = new \DateTimeImmutable( $raw, $tz ?? new \DateTimeZone( 'UTC' ) );
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
     * Holds the comp role but the date has passed. Today this is a REPORTING
     * state and nothing more — nobody is demoted for being in it (#183).
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
