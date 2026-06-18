<?php
/**
 * profile-app — avatar backfill (crib step 4).
 *
 * For every users row with avatar_url IS NULL that has a real BuddyBoss avatar
 * upload (/wp-content/uploads/avatars/<wp_user_id>/*-bpfull.*), copy the bytes
 * into the app-owned store and point avatar_url at the versioned served URL —
 * the same model as me-avatar.php / tools/backfill-bb-avatars.sh:
 *
 *   store:  /srv/profile-app-media/avatars/<uuid>/1.<ext>     (NOT wp-content)
 *   serve:  /profile-media/avatars/<uuid>/1.<ext>?v=1          (+ avatar_version=1)
 *
 * Rows with NO BB upload stay NULL — the renderer's initials fallback is the
 * canonical no-avatar state. There is deliberately NO Gravatar fallback any
 * more: the old one stamped 1,300+ rows with placeholder gravatar URLs that a
 * later pass (tools/backfill-bb-avatars.sh) had to repair, and every crib
 * re-run re-rotted newly provisioned users. Provision::ensure() leaves
 * avatar_url NULL on create for the same reason — don't reintroduce a fake
 * default at either end.
 *
 * Idempotent (only touches NULL avatar_url rows; re-run is a no-op).
 */

declare(strict_types=1);
require_once __DIR__ . '/../config.php';

use Looth\ProfileApp\Db;

const AVATAR_BASE_FS  = '/var/www/dev/wp-content/uploads/avatars';
const AVATAR_STORE    = '/srv/profile-app-media/avatars';
const AVATAR_URL_BASE = '/profile-media/avatars';

// Source override for cut day (live path differs) and testing.
$bbBase = getenv('LG_BB_AVATAR_DIR') ?: AVATAR_BASE_FS;

// LOUD abort beats a silent all-NULL pass: on dev the uploads dir is the
// uid-locked rclone R2 mount — as the profile-app user every glob comes back
// empty and the step would "succeed" having copied nothing.
if (!is_dir($bbBase) || !is_readable($bbBase)) {
    fwrite(STDERR, "ABORT — BB avatar dir not readable: $bbBase\n"
        . "  Run as a user that can read it (dev: the rclone mount is uid-locked → root),\n"
        . "  or point LG_BB_AVATAR_DIR at the right uploads/avatars path.\n");
    exit(3);
}

$pg = Db::pg();

$rows = $pg->query("
    SELECT u.id, u.uuid, b.wp_user_id
    FROM users u
    JOIN wp_user_bridge b ON b.user_id = u.id
    WHERE u.avatar_url IS NULL
")->fetchAll(PDO::FETCH_ASSOC);

printf("backfill-avatars: %d candidates (NULL avatar_url)\n", count($rows));

$upd = $pg->prepare("UPDATE users SET avatar_url = :url, avatar_version = 1 WHERE id = :id");
$counts = ['bb' => 0, 'no_bb_left_null' => 0, 'copy_failed' => 0];

foreach ($rows as $r) {
    $wpId = (int)$r['wp_user_id'];
    $uuid = strtolower((string)$r['uuid']);

    $src = bb_avatar_file($bbBase, $wpId);
    if ($src === null) {
        // No BB upload → stay NULL → initials at render. NOT an error.
        $counts['no_bb_left_null']++;
        continue;
    }

    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION)) ?: 'jpg';
    $dir = AVATAR_STORE . '/' . $uuid;
    $dst = $dir . '/1.' . $ext;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "  ! u{$r['id']} (wp$wpId): store dir unwritable: $dir\n");
        $counts['copy_failed']++;
        continue;
    }
    if (!@copy($src, $dst)) {
        fwrite(STDERR, "  ! u{$r['id']} (wp$wpId): copy failed: $src -> $dst\n");
        $counts['copy_failed']++;
        continue;
    }
    @chmod($dst, 0644);
    @chown($dst, 'profile-app');   // no-op unless running as root (cut-day sudo run)
    @chgrp($dst, 'profile-app');

    $upd->execute([
        ':url' => AVATAR_URL_BASE . '/' . $uuid . '/1.' . $ext . '?v=1',
        ':id'  => $r['id'],
    ]);
    $counts['bb']++;
}

foreach ($counts as $k => $v) printf("  %-16s %d\n", $k, $v);

function bb_avatar_file(string $base, int $wpId): ?string
{
    if ($wpId < 1) return null;   // dir 0 is BB's own placeholder — never a real upload
    $dir = $base . '/' . $wpId;
    if (!is_dir($dir)) return null;
    $matches = glob($dir . '/*-bpfull.*') ?: [];
    if (!$matches) return null;
    // Pick newest (BB rewrites on upload).
    usort($matches, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $matches[0];
}
