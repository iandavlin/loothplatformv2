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
        if ( ! array_key_exists( 'patreon', $out ) ) {
            $patreon = PatreonSourceReader::readForUser( $wpUserId );
            if ( $patreon !== null ) {
                $out['patreon'] = $patreon['tier'];
            }
        }

        return $out;
    }
}
