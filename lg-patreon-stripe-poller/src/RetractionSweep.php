<?php

declare(strict_types=1);

namespace LGMS;

use PDO;
use Throwable;

/**
 * Retraction sweep — Phase 1 of the retraction protocol (design doc §4).
 *
 * The root defect, named by the audit and found live twice: NOTHING EVER
 * RETRACTS AN OPINION WHEN ITS SOURCE DIES. lg_role_sources is a per-source
 * opinion table, the Arbiter takes the max, so a dead opinion keeps voting
 * forever. The 41 orphans Phase 0 cleaned by hand are that bug; at Stripe
 * volume it recurs continuously.
 *
 * This is the permanent, periodic leg. It iterates THE OPINIONS —
 * lg_role_sources WHERE source='stripe' — never the customers table.
 * Sweeping customers is precisely how the 41 survived: their customer rows
 * were deleted, so nothing ever visited their opinions again. Iterate the
 * opinions, not the subjects.
 *
 * An opinion is JUSTIFIED when it traces to a live justification:
 * a bridge row -> a live (undeleted) customer -> whose active membership
 * entitlement backs the asserted tier. A tier=NULL opinion with a live
 * bridged customer asserts nothing and is the correct retracted state, not
 * a finding. Everything else is a finding, by reason:
 *
 *   no_bridge         no wp_user_bridge row — the opinion has no subject at
 *                     all (the class all 41 orphans were)
 *   customer_gone     bridged, but the customer row is gone
 *   customer_deleted  bridged, customer soft-deleted (deleted_at set)
 *   no_entitlement    live customer, but the opinion asserts a tier and no
 *                     active membership entitlement exists
 *   tier_mismatch     live customer, active entitlement, but for a
 *                     DIFFERENT tier than the opinion asserts
 *
 * V1 IS DETECTION ONLY. The tick journals findings (lgms_retraction_findings,
 * autoload off) and notifies through LGMS\Log (file, else syslog — never a
 * web-served path). It NEVER writes lg_role_sources, never runs the Arbiter,
 * never moves a member. Actual retraction requires the explicit
 * deploy/remediation/apply-retraction-sweep.php invocation, which classifies
 * every finding by member impact, holds anything member-visible for a
 * per-member release, and journals through the same wp_options machinery the
 * Phase 0 revoke script proved.
 *
 * Flag: lgms_retraction_sweep — an option, not an env var (WP-Cron carries
 * no environment). DEFAULT ABSENT = OFF, and OFF is a total no-op: no DB
 * read, no option write, no log line. Companion to the stuck-source detector
 * (deploy/remediation/check-stuck-sources.php, 2c2f0f5), which reads the
 * same defect class from the operator side across ALL sources.
 */
final class RetractionSweep
{
    public const FLAG        = 'lgms_retraction_sweep';
    public const FINDINGS    = 'lgms_retraction_findings';

    public static function enabled(): bool
    {
        return (bool) get_option( self::FLAG, false );
    }

    /**
     * Every source=stripe opinion that no longer traces to a live
     * justification. Read-only.
     *
     * @return array<int, array{wp_user_id:int, tier:?string, updated_at:?string,
     *                          customer_id:?int, reason:string, justified:?string}>
     */
    public static function detect(): array
    {
        $rows = Db::pdo()->query(
            "SELECT r.wp_user_id,
                    r.tier,
                    r.updated_at,
                    b.customer_id,
                    c.id IS NOT NULL AS customer_exists,
                    c.deleted_at,
                    (SELECT MAX(e.ref) FROM entitlements e
                      WHERE e.customer_id = b.customer_id
                        AND e.kind = 'membership_tier'
                        AND e.revoked_at IS NULL
                        AND e.starts_at <= CURRENT_TIMESTAMP
                        AND (e.expires_at IS NULL OR e.expires_at > CURRENT_TIMESTAMP)
                    ) AS justified_tier
               FROM lg_role_sources r
               LEFT JOIN wp_user_bridge b ON b.wp_user_id = r.wp_user_id
               LEFT JOIN customers c      ON c.id = b.customer_id
              WHERE r.source = 'stripe'
              ORDER BY r.wp_user_id"
        )->fetchAll( PDO::FETCH_ASSOC );

        $findings = [];
        foreach ( $rows as $row ) {
            $uid       = (int) $row['wp_user_id'];
            $tier      = $row['tier'] !== null ? (string) $row['tier'] : null;
            $justified = $row['justified_tier'] !== null ? (string) $row['justified_tier'] : null;

            if ( $row['customer_id'] === null ) {
                $reason = 'no_bridge';
            } elseif ( ! (int) $row['customer_exists'] ) {
                $reason = 'customer_gone';
            } elseif ( $row['deleted_at'] !== null ) {
                $reason = 'customer_deleted';
            } elseif ( $tier !== null && $justified === null ) {
                $reason = 'no_entitlement';
            } elseif ( $tier !== null && $tier !== $justified ) {
                $reason = 'tier_mismatch';
            } else {
                continue;   // justified — or a NULL opinion asserting nothing
            }

            $findings[ $uid ] = [
                'wp_user_id'  => $uid,
                'tier'        => $tier,
                'updated_at'  => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
                'customer_id' => $row['customer_id'] !== null ? (int) $row['customer_id'] : null,
                'reason'      => $reason,
                'justified'   => $justified,
            ];
        }
        return $findings;
    }

    /**
     * The tick pass: detect, journal, notify. Never mutates member state —
     * see the class doc. Own try/catch so a sweep failure can never take
     * down the passes after it (the tick wraps it again, belt and braces).
     */
    public static function tick(): void
    {
        if ( ! self::enabled() ) {
            return;   // OFF = total no-op: no DB read, no option, no log line
        }

        try {
            $findings = self::detect();
        } catch ( Throwable $e ) {
            Log::line( sprintf(
                "[%s] retraction sweep FAILED: %s\n",
                gmdate( 'c' ),
                $e->getMessage(),
            ) );
            return;
        }

        $prev     = get_option( self::FINDINGS, [] );
        $known    = is_array( $prev ) && isset( $prev['findings'] ) && is_array( $prev['findings'] )
            ? $prev['findings'] : [];

        $now = gmdate( 'c' );
        $new = [];
        foreach ( $findings as $uid => $f ) {
            // first_seen survives across ticks so an operator can see how
            // long a finding has been standing, not just that it stands.
            $f['first_seen'] = isset( $known[ $uid ] ) && isset( $known[ $uid ]['first_seen'] )
                ? (string) $known[ $uid ]['first_seen']
                : $now;
            if ( ! isset( $known[ $uid ] ) ) {
                $new[] = $f;
            }
            $findings[ $uid ] = $f;
        }
        $cleared = array_diff_key( $known, $findings );

        update_option( self::FINDINGS, [
            'updated_at' => $now,
            'count'      => count( $findings ),
            'findings'   => $findings,
        ], false );

        foreach ( $new as $f ) {
            Log::line( sprintf(
                "[%s] retraction sweep: NEW unjustifiable stripe opinion — user %d tier=%s reason=%s customer=%s justified=%s\n",
                $now,
                $f['wp_user_id'],
                $f['tier'] ?? 'NULL',
                $f['reason'],
                $f['customer_id'] === null ? '(none)' : (string) $f['customer_id'],
                $f['justified'] ?? '(none)',
            ) );
        }
        foreach ( $cleared as $uid => $f ) {
            Log::line( sprintf(
                "[%s] retraction sweep: finding CLEARED — user %d (was %s)\n",
                $now,
                (int) $uid,
                $f['reason'] ?? '?',
            ) );
        }

        Log::line( sprintf(
            "[%s] retraction sweep: %d unjustifiable stripe opinions (%d new, %d cleared). Detection only — retract via deploy/remediation/apply-retraction-sweep.php\n",
            $now,
            count( $findings ),
            count( $new ),
            count( $cleared ),
        ) );
    }
}
