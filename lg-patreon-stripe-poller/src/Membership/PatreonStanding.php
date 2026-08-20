<?php

declare(strict_types=1);

namespace LGMS\Membership;

use LGMS\Db;
use Throwable;

/**
 * Does this member have an ACTIVE PAID Patreon standing right now?
 *
 * Ian, 2026-08-19, verbatim: "We should disallow double payment source for the
 * same user." This class is the one place that answers what "already paying"
 * means; all three purchase doors ask it rather than deciding for themselves
 * (#150). Two doors cannot disagree if there is only one answer.
 *
 * WHAT IT KEYS ON, and why not the obvious field:
 *
 *   `payment_source` usermeta is ONE SLOT and the two rails fight over it — the
 *   Patreon sweep writes 'patreon' on upgrade, the Stripe side writes 'stripe',
 *   and a downgrade clears it. It is DESCRIPTIVE ONLY ("where to manage
 *   billing"), a vestige of the single-source world, and a dual holder's value
 *   is whichever rail wrote last. Keying a payment block on it would let a
 *   paying patron through the moment anything stamped 'stripe' on them. So this
 *   class never reads it (docs/domains/MEMBERSHIP.md; gate 74 §2 asserts the
 *   absence AND that mutating the field alone moves no verdict).
 *
 *   Instead: the Patreon LINK (`lgpo_patreon_user_id`), the entitled tier the
 *   sweep persists (`lgpo_patreon_tier_id` through `lgpo_tier_map`), and the
 *   patron's live status in `lg_patreon_members` — the same three facts
 *   LGPO_Sync_Engine::determine_role() decides a member's ROLE from, so the
 *   block and the grant can never drift apart.
 *
 * ONE DELIBERATE WIDENING. determine_role() asks "what role does Patreon give
 * this member"; this class asks "is Patreon CHARGING this member", which is not
 * the same question. A tier missing from `lgpo_tier_map` grants no role and
 * still bills them every month, so a positive `currently_entitled_amount_cents`
 * counts as paying even with no mapped tier. Blocking is the safe side of that
 * ambiguity: the cost of a wrong block is a support message, the cost of a
 * wrong pass is a member charged twice.
 *
 * STALENESS IS NOT A TEST. `synced_at` is last-CHANGED, not last-checked (it is
 * `ON UPDATE CURRENT_TIMESTAMP`), so the longest-standing patrons — the ones
 * whose pledge has not moved in a year — carry the oldest rows. A freshness
 * cutoff here would unblock exactly the wrong members. The admin surface shows
 * the sweep's real age from `lgpo_last_sync_time`; nothing gates on it.
 */
final class PatreonStanding
{
    /**
     * The switch, and the ONLY one. A wp_option so that all three readers —
     * this plugin, the standalone membership-pages app (which reads wp_options
     * over SQL), and the Slim billing app (which asks this plugin, at a route
     * that only exists while this is on) — address the same single row and
     * cannot drift. Absent or anything but a literal on-value reads OFF.
     *
     * Not an FPM pool env: the readers span three pools plus WP-cron, a pool
     * env reaches FPM only, and dev2's pool files are symlinks into the serving
     * checkout, so flipping one there dirties the checkout that must only pull.
     */
    public const FLAG = 'lgms_double_pay_block';

    /** Reasons. Distinct on purpose: "never linked" and "lapsed" are different facts. */
    public const R_NOT_LINKED  = 'no_patreon_link';
    public const R_NO_RECORD   = 'no_patreon_record';
    public const R_NOT_ACTIVE  = 'not_active_patron';
    public const R_ACTIVE_FREE = 'active_patron_not_paying';
    public const R_PAYING      = 'active_paid_patron';

    public static function flagOn(): bool
    {
        $v = get_option( self::FLAG, false );
        // Strict: absent, '0', '', a malformed string and a stray array all
        // read OFF. `(bool)` would turn 'no' and 'off' into ON.
        return $v === true || $v === 1 || $v === '1';
    }

    /**
     * @return array{active:bool,tier:?string,tier_id:?string,patron_status:?string,
     *               tier_label:?string,amount_cents:?int,next_charge_date:?string,
     *               wp_user_id:int,reason:string}
     */
    public static function forUser( int $wpUserId ): array
    {
        if ( $wpUserId <= 0 ) {
            return self::verdict( $wpUserId, false, self::R_NOT_LINKED );
        }

        $patreonId = trim( (string) get_user_meta( $wpUserId, 'lgpo_patreon_user_id', true ) );
        if ( $patreonId === '' ) {
            return self::verdict( $wpUserId, false, self::R_NOT_LINKED );
        }

        $row = self::memberRow( $wpUserId );
        if ( $row === null ) {
            // Linked but no snapshot: the sweep has never seen them. That is an
            // unknown, and an unknown is not a payment.
            return self::verdict( $wpUserId, false, self::R_NO_RECORD );
        }

        // A row that says NOTHING is not a row that says PAYING. `patron_status`
        // is nullable and array_key_exists() is true for null, so an IFNULL-style
        // read here would turn "we have no opinion" into a status string.
        $status = isset( $row['patron_status'] ) && $row['patron_status'] !== null
            ? (string) $row['patron_status']
            : null;

        $tierId = trim( (string) get_user_meta( $wpUserId, 'lgpo_patreon_tier_id', true ) );
        $tierId = $tierId !== '' ? $tierId : null;
        $tier   = self::mappedPaidTier( $tierId );
        $cents  = isset( $row['currently_entitled_amount_cents'] ) && $row['currently_entitled_amount_cents'] !== null
            ? (int) $row['currently_entitled_amount_cents']
            : null;

        $extra = [
            'tier'             => $tier,
            'tier_id'          => $tierId,
            'patron_status'    => $status,
            'tier_label'       => isset( $row['tier_label'] ) && $row['tier_label'] !== null ? (string) $row['tier_label'] : null,
            'amount_cents'     => $cents,
            'next_charge_date' => isset( $row['next_charge_date'] ) && $row['next_charge_date'] !== null ? (string) $row['next_charge_date'] : null,
        ];

        if ( $status !== 'active_patron' ) {
            // Covers former_patron, declined_patron (the charge did not land),
            // and a null opinion. None of them is money leaving the member.
            return self::verdict( $wpUserId, false, self::R_NOT_ACTIVE, $extra );
        }

        $paying = $tier !== null || ( $cents !== null && $cents > 0 );
        return self::verdict(
            $wpUserId,
            $paying,
            $paying ? self::R_PAYING : self::R_ACTIVE_FREE,
            $extra,
        );
    }

    /**
     * Same verdict, addressed by email — the Slim checkout door knows an email
     * and nothing else. An address with no WP account is nobody, and nobody is
     * not paying.
     */
    public static function forEmail( string $email ): array
    {
        $email = trim( $email );
        if ( $email === '' ) {
            return self::verdict( 0, false, self::R_NOT_LINKED );
        }
        $user = get_user_by( 'email', $email );
        if ( ! $user || (int) $user->ID <= 0 ) {
            return self::verdict( 0, false, self::R_NOT_LINKED );
        }
        return self::forUser( (int) $user->ID );
    }

    /**
     * The copy the member reads, at every door. Ian sees this exact wording
     * with the build; it is one string so that two doors cannot describe two
     * different ways to leave Patreon.
     *
     * It names the GAP on purpose. A patron who cancels keeps Patreon access to
     * the end of the period they have already paid for, so "cancel then join"
     * without that sentence invites them to double-pay anyway — the very thing
     * this refusal exists to prevent.
     */
    public static function refusalMessage( array $standing = [] ): string
    {
        return 'Your membership is already paid through Patreon, so buying here would charge you twice.'
             . ' To move your billing to the site, cancel your pledge on Patreon first — your Patreon'
             . ' membership keeps running to the end of the period you have already paid for — then come'
             . ' back and join here once it lapses.';
    }

    /** Where the member goes to cancel. Same option /manage-subscription/ links. */
    public static function manageUrl(): string
    {
        $url = trim( (string) get_option( 'lgpo_patreon_link', '' ) );
        return $url !== '' ? $url : 'https://www.patreon.com/';
    }

    /** looth2 / looth3, or null. looth1 is the free floor; looth4 is comp and never Patreon-granted. */
    private static function mappedPaidTier( ?string $tierId ): ?string
    {
        if ( $tierId === null ) {
            return null;
        }
        $map = get_option( 'lgpo_tier_map', [] );
        if ( ! is_array( $map ) || ! isset( $map[ $tierId ] ) ) {
            return null;
        }
        $mapped = (string) $map[ $tierId ];
        return ( $mapped === 'looth2' || $mapped === 'looth3' ) ? $mapped : null;
    }

    private static function memberRow( int $wpUserId ): ?array
    {
        try {
            $st = Db::pdo()->prepare(
                'SELECT patron_status, last_charge_status, next_charge_date,
                        currently_entitled_amount_cents, will_pay_amount_cents,
                        tier_label, synced_at
                   FROM lg_patreon_members
                  WHERE wp_user_id = ?
                  LIMIT 1'
            );
            $st->execute( [ $wpUserId ] );
            $row = $st->fetch( \PDO::FETCH_ASSOC );
            return is_array( $row ) && $row !== [] ? $row : null;
        } catch ( Throwable $e ) {
            // A database this door cannot read is an UNKNOWN, not a licence to
            // block: refusing every sale because one query failed is a worse
            // outcome than the rare double-payment the sweep then surfaces.
            error_log( 'LGMS PatreonStanding::memberRow: ' . $e->getMessage() );
            return null;
        }
    }

    private static function verdict( int $wpUserId, bool $active, string $reason, array $extra = [] ): array
    {
        return $extra + [
            'active'           => $active,
            'tier'             => null,
            'tier_id'          => null,
            'patron_status'    => null,
            'tier_label'       => null,
            'amount_cents'     => null,
            'next_charge_date' => null,
            'wp_user_id'       => $wpUserId,
            'reason'           => $reason,
        ];
    }
}
