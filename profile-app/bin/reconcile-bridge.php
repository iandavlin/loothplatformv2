<?php
/**
 * profile-app — wp_user → users bridge reconciler.
 *
 * Walks every wp_users row and guarantees a matching profile-app users +
 * wp_user_bridge entry. Slice 1 surprised us with 115 empty-email ghosts
 * that the webhook had skipped; the rebackfill found 41 more. This script
 * paints over those gaps before the cutover migration runs.
 *
 *   - Doesn't archive anything (archived_at stays NULL — that's slice-3
 *     triage's call).
 *   - location_visibility defaults to 'members' via DEFAULT — no explicit set.
 *   - Idempotent. Logs created vs existing.
 *
 * Empty-email rows get a synthetic email of the form `looth-<wp_id>@invalid`
 * so the NOT NULL + UNIQUE primary_email constraint holds. The cutover
 * triage script can null-it/archive these later.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config.php';

use Looth\ProfileApp\Db;
use Looth\ProfileApp\Identity;

$mysqlSocket = '/var/run/mysqld/mysqld.sock';
$mysqlUser   = posix_getpwuid(posix_geteuid())['name'] ?? 'profile-app';
$wp = new PDO(
    'mysql:unix_socket=' . $mysqlSocket . ';dbname=' . LG_PROFILE_APP_MYSQL_DB,
    $mysqlUser, '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pg = Db::pg();

$rows = $wp->query("SELECT ID, user_login, user_email, user_nicename, display_name FROM wp_users ORDER BY ID");

$existsBridge = $pg->prepare("SELECT 1 FROM wp_user_bridge WHERE wp_user_id = :w");
$existsEmail  = $pg->prepare("SELECT id FROM users WHERE primary_email = :e");
$insUser = $pg->prepare("
    INSERT INTO users (uuid, primary_email, display_name, slug)
    VALUES (:uuid, :email, :name, :slug)
    RETURNING id
");
$insBridge = $pg->prepare("INSERT INTO wp_user_bridge (user_id, wp_user_id) VALUES (:u, :w) ON CONFLICT DO NOTHING");

$counts = ['created' => 0, 'existing' => 0, 'linked' => 0, 'errors' => 0];

while ($r = $rows->fetch(PDO::FETCH_ASSOC)) {
    $wpId  = (int)$r['ID'];
    $email = trim((string)$r['user_email']);
    $name  = trim((string)$r['display_name']) ?: trim((string)$r['user_login']);
    $slug  = trim((string)$r['user_nicename']) ?: ('user-' . $wpId);

    $existsBridge->execute([':w' => $wpId]);
    if ($existsBridge->fetchColumn()) { $counts['existing']++; continue; }

    // Synth email for ghosts so we can satisfy NOT NULL + UNIQUE.
    if ($email === '') $email = sprintf('looth-%d@invalid', $wpId);

    // If a users row already exists for this email, just link the bridge.
    $existsEmail->execute([':e' => $email]);
    $existingId = $existsEmail->fetchColumn();

    try {
        if ($existingId) {
            $insBridge->execute([':u' => $existingId, ':w' => $wpId]);
            $counts['linked']++;
        } else {
            $uuid = Identity::computeUuid($email);
            // Slug uniqueness: tack on -<wp_id> on collision.
            $tryslug = $slug;
            $existsSlug = $pg->prepare("SELECT 1 FROM users WHERE slug = :s");
            $existsSlug->execute([':s' => $tryslug]);
            if ($existsSlug->fetchColumn()) $tryslug = $slug . '-' . $wpId;

            $insUser->execute([':uuid' => $uuid, ':email' => $email, ':name' => $name, ':slug' => $tryslug]);
            $newId = (int)$insUser->fetchColumn();
            $insBridge->execute([':u' => $newId, ':w' => $wpId]);
            $counts['created']++;
        }
    } catch (Throwable $e) {
        $counts['errors']++;
        fprintf(STDERR, "reconcile-bridge error wp=%d email=%s: %s\n", $wpId, $email, $e->getMessage());
    }
}

foreach ($counts as $k => $v) printf("  %-10s %d\n", $k, $v);
