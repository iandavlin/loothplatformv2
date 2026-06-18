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

        // Merge Patreon adapter's view. Only present when LGPO owns the
        // user (payment_source=patreon). PatreonSourceReader returns null
        // otherwise — Stripe-owned users are never given a patreon row.
        // Persisted lg_role_sources rows for 'patreon' (legacy / from
        // earlier sessions) are overwritten by the live read so the
        // Arbiter sees current truth.
        $patreon = PatreonSourceReader::readForUser( $wpUserId );
        if ( $patreon !== null ) {
            $out['patreon'] = (string) $patreon['tier'];
        }

        return $out;
    }
}
