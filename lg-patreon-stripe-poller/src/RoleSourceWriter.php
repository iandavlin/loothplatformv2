<?php

declare(strict_types=1);

namespace LGMS;

use LGMS\Patreon\PatreonSourceReader;
use PDO;

/**
 * Reads/writes per-source role opinions in lg_role_sources, plus a
 * read-only merge of the Patreon adapter's view.
 *
 * Each row says "source X thinks user Y has tier Z." Stripe + manual_admin
 * are persisted in lg_role_sources (this class writes them). The Patreon
 * source is read live from LGPO's usermeta via PatreonSourceReader — the
 * adapter is read-only and never reports() through this class.
 *
 * The Arbiter picks the winner across all sources.
 */
final class RoleSourceWriter
{
    public static function report(int $wpUserId, string $source, ?string $tier): void
    {
        Db::pdo()->prepare(
            'INSERT INTO lg_role_sources (wp_user_id, source, tier)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE tier = VALUES(tier)'
        )->execute( [ $wpUserId, $source, $tier ] );
    }

    /** @return array<string, ?string> source => tier */
    public static function readAllForUser(int $wpUserId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT source, tier FROM lg_role_sources WHERE wp_user_id = ?'
        );
        $stmt->execute( [ $wpUserId ] );
        $out = [];
        foreach ( $stmt->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
            $out[ (string) $row['source'] ] = $row['tier'] !== null ? (string) $row['tier'] : null;
        }

        // Surface a Patreon source ONLY when the sweep has not already
        // persisted one. The sweep is the authority for Patreon: it writes the
        // API-derived tier to the lg_role_sources 'patreon' row
        // (RoleSourceWriter::report) on every poll. We must NOT overwrite that
        // row here — the old code re-derived 'patreon' from the member's
        // CURRENT WP role, which was circular and blocked upgrades (the Arbiter
        // only ever heard back the role it had just written). The adapter is a
        // fallback for a patreon-managed user with no persisted row yet (e.g. a
        // fresh onboard before the first sweep); its tier may be null (lapsed).
        //
        // NULL-SHADOW FIX (flagged): array_key_exists is TRUE for a persisted
        // row whose tier is NULL, so a stale NULL row SHADOWS a reader that
        // would have said looth2/looth3 — that is how four paying members were
        // nearly demoted (STRIPE-PHASE0-FINDINGS §3.1), and live carries two
        // more (612, 1768: $11 active patrons whose ONLY source row is a
        // May-07 patreon NULL — a wrongful demotion loaded to fire on their
        // next Arbiter run). With lgms_null_shadow_fix ON, a NULL-tier row no
        // longer blocks the reader: when the reader speaks (payment_source =
        // patreon) its answer replaces the shadowing NULL; when it is silent,
        // the NULL stands. A NON-null persisted row remains authoritative in
        // both states — the sweep wrote it from the Patreon API, and consulting
        // the reader over it would reopen the circular-read bug above.
        //
        // Behind its own flag, DEFAULT OFF, because it changes the Arbiter's
        // computed outcome for members with a persisted NULL patreon row —
        // measured 2026-08-09: 2 members on live (612, 1768), 3 on dev2 (17,
        // 612, 1768), every one of them a paying member whose FIXED outcome
        // equals the tier they already hold. See classify-null-shadow.php.
        $hasPatreonRow = array_key_exists( 'patreon', $out );
        $nullShadowFix = $hasPatreonRow
            && $out['patreon'] === null
            && (bool) get_option( 'lgms_null_shadow_fix', false );

        if ( ! $hasPatreonRow || $nullShadowFix ) {
            $patreon = PatreonSourceReader::readForUser( $wpUserId );
            if ( $patreon !== null ) {
                $out['patreon'] = $patreon['tier'];
            }
        }

        return $out;
    }
}
