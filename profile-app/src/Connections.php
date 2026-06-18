<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

require_once __DIR__ . '/Notifications.php';

/**
 * Connections (mutual friends / requests / blocks). Keyed on looth_id (= users.uuid).
 * Plan: docs/plan-profile-2.0-social-layer.md. Table: sql/2026-05-30-social-layer.sql.
 *
 * MUTUAL ONLY (Ian, 2026-05-30): connections are symmetric — ONE row per pair,
 * queried both directions. There is NO `follow` feature; if a downstream feature
 * (feed, etc.) ever needs a follow signal it is AUTO-DERIVED from an accepted
 * connection (accepted = mutual follow), never a separate graph or UI.
 * `blocked` is a status, and hard-stops DM eligibility.
 *
 * NOTE (vs coordinator relay): read paths keep Ian's stub names/vocabulary
 * (edgeState, pending_out/pending_in). Mutating paths are id-based
 * (accept/decline/cancel/block by connection_id + acting uuid) to serve the
 * REST contract `PATCH /profile-api/v0/connections/<id>`. Flagged in report.
 */
final class Connections
{
    public const STATUS = ['pending', 'accepted', 'blocked'];

    /** The single edge row between two users (either direction), or null. */
    private static function edge(string $a, string $b): ?array
    {
        $st = Db::pg()->prepare(
            'SELECT * FROM connections
              WHERE (requester_uuid = :a AND addressee_uuid = :b)
                 OR (requester_uuid = :b AND addressee_uuid = :a)
              LIMIT 1'
        );
        $st->execute([':a' => $a, ':b' => $b]);
        return $st->fetch() ?: null;
    }

    /**
     * Edge state + the connection id (null when none) between viewer and subject.
     * One query; the /u/ widget needs the id to wire accept/decline/cancel.
     * state ∈ 'none'|'pending_out'|'pending_in'|'accepted'|'blocked'.
     */
    public static function stateWithId(string $viewerUuid, string $subjectUuid): array
    {
        $e = self::edge($viewerUuid, $subjectUuid);
        if (!$e) return ['state' => 'none', 'id' => null];
        if ($e['status'] === 'accepted') $state = 'accepted';
        elseif ($e['status'] === 'blocked') $state = 'blocked';
        else $state = $e['requester_uuid'] === $viewerUuid ? 'pending_out' : 'pending_in';
        return ['state' => $state, 'id' => (int)$e['id']];
    }

    /**
     * Edge state between viewer and subject → drives the /u/ button label.
     * Returns one of: 'none'|'pending_out'|'pending_in'|'accepted'|'blocked'.
     */
    public static function edgeState(string $viewerUuid, string $subjectUuid): string
    {
        return self::stateWithId($viewerUuid, $subjectUuid)['state'];
    }

    /** A & B are accepted connections (symmetric). Gates DM + contact-reveal. */
    public static function areConnected(string $aUuid, string $bUuid): bool
    {
        $e = self::edge($aUuid, $bUuid);
        return $e !== null && $e['status'] === 'accepted';
    }

    /** Can $viewer DM $subject? RULED (Ian, 2026-05-30): CONNECTIONS-ONLY. */
    public static function canMessage(string $viewerUuid, string $subjectUuid): bool
    {
        if ($viewerUuid === $subjectUuid) return false;
        // accepted implies neither side is blocked (block overwrites the row).
        return self::areConnected($viewerUuid, $subjectUuid);
    }

    /** Send a friend request. Rejects if any edge already exists (either direction). */
    public static function request(string $fromUuid, string $toUuid): array
    {
        if ($fromUuid === $toUuid) return ['ok' => false, 'error' => 'self_connect'];
        if (self::edge($fromUuid, $toUuid)) {
            return ['ok' => false, 'error' => 'edge_exists',
                    'state' => self::edgeState($fromUuid, $toUuid)];
        }
        $st = Db::pg()->prepare(
            "INSERT INTO connections (requester_uuid, addressee_uuid, status)
             VALUES (:r, :a, 'pending') RETURNING id"
        );
        $st->execute([':r' => $fromUuid, ':a' => $toUuid]);
        $id = (int)$st->fetchColumn();
        Notifications::push($toUuid, 'connection_request', $id, $fromUuid);
        return ['ok' => true, 'id' => $id, 'state' => 'pending_out'];
    }

    /** Addressee accepts a pending request (by id). Verifies $userUuid is the addressee. */
    public static function accept(int $connectionId, string $userUuid): array
    {
        $st = Db::pg()->prepare(
            "UPDATE connections SET status = 'accepted'
              WHERE id = :id AND addressee_uuid = :u AND status = 'pending'
              RETURNING requester_uuid"
        );
        $st->execute([':id' => $connectionId, ':u' => $userUuid]);
        $requester = $st->fetchColumn();
        if ($requester === false) return ['ok' => false, 'error' => 'not_pending_for_user'];
        Notifications::push((string)$requester, 'connection_accept', $connectionId, $userUuid);
        return ['ok' => true, 'state' => 'accepted'];
    }

    /**
     * Remove a PENDING edge — addressee declining or requester cancelling.
     * Deletion (not a 'declined' status) lets a fresh request happen later.
     */
    public static function decline(int $connectionId, string $userUuid): array
    {
        $st = Db::pg()->prepare(
            "DELETE FROM connections
              WHERE id = :id AND status = 'pending'
                AND (addressee_uuid = :u OR requester_uuid = :u)"
        );
        $st->execute([':id' => $connectionId, ':u' => $userUuid]);
        return ['ok' => $st->rowCount() > 0];
    }

    /** Requester withdrawing their own pending request (same as decline, by party). */
    public static function cancel(int $connectionId, string $userUuid): array
    {
        return self::decline($connectionId, $userUuid);
    }

    /**
     * Either party removes an ACCEPTED connection (un-connect). Deletes the edge so a
     * fresh request can be made later. Distinct from block() (which keeps a blocked row).
     */
    public static function disconnect(int $connectionId, string $userUuid): array
    {
        $st = Db::pg()->prepare(
            "DELETE FROM connections
              WHERE id = :id AND status = 'accepted'
                AND (addressee_uuid = :u OR requester_uuid = :u)"
        );
        $st->execute([':id' => $connectionId, ':u' => $userUuid]);
        return ['ok' => $st->rowCount() > 0, 'state' => 'none'];
    }

    /**
     * $userUuid blocks the other party in connection $connectionId. Normalizes the
     * row so requester = blocker, addressee = blocked, status = 'blocked'.
     */
    public static function block(int $connectionId, string $userUuid): array
    {
        $pg = Db::pg();
        $st = $pg->prepare('SELECT requester_uuid, addressee_uuid FROM connections WHERE id = :id');
        $st->execute([':id' => $connectionId]);
        $row = $st->fetch();
        if (!$row) return ['ok' => false, 'error' => 'not_found'];
        if ($row['requester_uuid'] !== $userUuid && $row['addressee_uuid'] !== $userUuid) {
            return ['ok' => false, 'error' => 'not_a_party'];
        }
        $other = $row['requester_uuid'] === $userUuid ? $row['addressee_uuid'] : $row['requester_uuid'];
        $pg->prepare(
            "UPDATE connections
                SET requester_uuid = :blocker, addressee_uuid = :blocked, status = 'blocked'
              WHERE id = :id"
        )->execute([':blocker' => $userUuid, ':blocked' => $other, ':id' => $connectionId]);
        return ['ok' => true, 'state' => 'blocked'];
    }

    /** Block a user by uuid (creates a blocked edge if none exists). */
    public static function blockUser(string $blockerUuid, string $blockedUuid): array
    {
        if ($blockerUuid === $blockedUuid) return ['ok' => false, 'error' => 'self_block'];
        $e = self::edge($blockerUuid, $blockedUuid);
        if ($e) return self::block((int)$e['id'], $blockerUuid);
        $st = Db::pg()->prepare(
            "INSERT INTO connections (requester_uuid, addressee_uuid, status)
             VALUES (:r, :a, 'blocked') RETURNING id"
        );
        $st->execute([':r' => $blockerUuid, ':a' => $blockedUuid]);
        return ['ok' => true, 'id' => (int)$st->fetchColumn(), 'state' => 'blocked'];
    }

    /**
     * Grouped lists for the friends modal + counts, each hydrated with the OTHER
     * party's identity (uuid, display_name, slug, avatar_url).
     *  accepted     → confirmed connections
     *  pending_in   → incoming requests (actionable)
     *  pending_out  → outgoing requests still awaiting
     */
    public static function listFor(string $uuid): array
    {
        $pg = Db::pg();

        $accepted = $pg->prepare(
            "SELECT c.id, c.created_at, u.uuid, u.display_name, u.slug, u.avatar_url
               FROM connections c
               JOIN users u ON u.uuid = CASE WHEN c.requester_uuid = :u
                                             THEN c.addressee_uuid ELSE c.requester_uuid END
              WHERE c.status = 'accepted' AND :u IN (c.requester_uuid, c.addressee_uuid)
              ORDER BY u.display_name"
        );
        $accepted->execute([':u' => $uuid]);

        $pendingIn = $pg->prepare(
            "SELECT c.id, c.created_at, u.uuid, u.display_name, u.slug, u.avatar_url
               FROM connections c JOIN users u ON u.uuid = c.requester_uuid
              WHERE c.status = 'pending' AND c.addressee_uuid = :u
              ORDER BY c.created_at DESC"
        );
        $pendingIn->execute([':u' => $uuid]);

        $pendingOut = $pg->prepare(
            "SELECT c.id, c.created_at, u.uuid, u.display_name, u.slug, u.avatar_url
               FROM connections c JOIN users u ON u.uuid = c.addressee_uuid
              WHERE c.status = 'pending' AND c.requester_uuid = :u
              ORDER BY c.created_at DESC"
        );
        $pendingOut->execute([':u' => $uuid]);

        return [
            'accepted'    => $accepted->fetchAll(),
            'pending_in'  => $pendingIn->fetchAll(),
            'pending_out' => $pendingOut->fetchAll(),
        ];
    }

    /** Count of incoming pending requests → me-social-counts. */
    public static function pendingCount(string $uuid): int
    {
        $st = Db::pg()->prepare(
            "SELECT COUNT(*) FROM connections WHERE addressee_uuid = :u AND status = 'pending'"
        );
        $st->execute([':u' => $uuid]);
        return (int)$st->fetchColumn();
    }
}
