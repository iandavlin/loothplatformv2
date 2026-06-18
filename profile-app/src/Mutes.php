<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

require_once __DIR__ . '/Db.php';

/**
 * Mutes — one-directional, silent author muting. Independent of connection status:
 * a member can mute anyone to hide that author's content from their feed/discussions.
 * NOT block (block hard-stops DM both ways and is symmetric); mute is a private,
 * one-way feed filter the muted user never sees. Store: user_mutes(muter_uuid,
 * muted_uuid, created_at), PK(muter_uuid, muted_uuid) — provisioned by the coordinator.
 * Feed-honoring lives in the Hub lane; this class owns the store + the API surface.
 */
final class Mutes
{
    public static function mute(string $muterUuid, string $mutedUuid): array
    {
        if ($muterUuid === '' || $mutedUuid === '') return ['ok' => false, 'error' => 'bad_uuid'];
        if (strtolower($muterUuid) === strtolower($mutedUuid)) return ['ok' => false, 'error' => 'cannot_mute_self'];
        $st = Db::pg()->prepare(
            "INSERT INTO user_mutes (muter_uuid, muted_uuid)
             VALUES (:m, :t) ON CONFLICT (muter_uuid, muted_uuid) DO NOTHING"
        );
        $st->execute([':m' => $muterUuid, ':t' => $mutedUuid]);
        return ['ok' => true, 'muted' => true];
    }

    public static function unmute(string $muterUuid, string $mutedUuid): array
    {
        $st = Db::pg()->prepare(
            "DELETE FROM user_mutes WHERE muter_uuid = :m AND muted_uuid = :t"
        );
        $st->execute([':m' => $muterUuid, ':t' => $mutedUuid]);
        return ['ok' => true, 'muted' => false];
    }

    public static function isMuted(string $muterUuid, string $mutedUuid): bool
    {
        if ($muterUuid === '' || $mutedUuid === '') return false;
        $st = Db::pg()->prepare(
            "SELECT 1 FROM user_mutes WHERE muter_uuid = :m AND muted_uuid = :t"
        );
        $st->execute([':m' => $muterUuid, ':t' => $mutedUuid]);
        return (bool) $st->fetchColumn();
    }

    /** UUIDs the given user has muted — for the Hub/feed lane's filter. */
    public static function listMutedBy(string $muterUuid): array
    {
        if ($muterUuid === '') return [];
        $st = Db::pg()->prepare(
            "SELECT muted_uuid FROM user_mutes WHERE muter_uuid = :m"
        );
        $st->execute([':m' => $muterUuid]);
        return array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }
}
