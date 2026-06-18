<?php
/**
 * profile-app — social-media backfill from BB xprofile + ACF author socials.
 *
 * WRITTEN 2026-05-29 (post-BATCH-06). RUN at slice-4 cutover (after
 * migrate-from-xprofile.php has populated identity).
 *
 * ## Sources (BATCH-06 live recon)
 *
 *   Primary (xprofile field 266, type 'socialnetworks'):
 *     - serialized PHP keyed by platform slug. Platforms confirmed on live:
 *       facebook, instagram, twitter, reddit, youTube. Empty strings for unset.
 *     - 123 users w/ at least one URL.
 *
 *   Fallback (ACF author_* user-meta):
 *     - author_instagram, author_youtube, author_facebook, author_website,
 *       author_linktree — full URLs, sparse coverage. Dirty in spots
 *       (saw a linktr.ee URL inside `author_facebook`); copy literally.
 *
 * ## Mapping → Profile::SOCIAL_KINDS (locked 2026-05-29)
 *
 *   facebook / instagram / youTube / website  → facebook / instagram / youtube / web
 *   twitter                                   → x
 *   reddit                                    → web (folded — preserve URL)
 *   linktree                                  → linktree (NEW kind, added in
 *                                                2026-05-29 schema migration)
 *
 * ## Precedence (per user × kind)
 *
 *   1. profile_socials already has the kind → KEEP (never clobber editor edit)
 *   2. else xprofile (primary)               → use xprofile value
 *   3. else ACF author_*                     → use ACF value
 *   4. else                                  → skip
 *
 * Writes kind + url ONLY. No per-row visibility (block-level pmp wins per
 * the converged block-system design; profile_socials has no vis column).
 *
 * Idempotent. Dry-run by default. Pass --commit to mutate.
 *
 * Usage:
 *   sudo -u profile-app php bin/migrate-socials.php             # dry-run
 *   sudo -u profile-app php bin/migrate-socials.php --commit
 *   sudo -u profile-app php bin/migrate-socials.php --user 1929 # one wp_user_id
 */

declare(strict_types=1);
require_once __DIR__ . '/../config.php';

use Looth\ProfileApp\Db;

$COMMIT   = in_array('--commit', $argv, true);
$ONE_USER = null;
foreach ($argv as $i => $a) if ($a === '--user' && isset($argv[$i+1])) $ONE_USER = (int)$argv[$i+1];

echo $COMMIT ? "*** COMMIT MODE — mutations will be written ***\n"
             : "(dry-run; pass --commit to write)\n";

$mysqlSocket = '/var/run/mysqld/mysqld.sock';
$mysqlUser   = posix_getpwuid(posix_geteuid())['name'] ?? 'profile-app';
$wp = new PDO('mysql:unix_socket=' . $mysqlSocket . ';dbname=' . LG_PROFILE_APP_MYSQL_DB,
              $mysqlUser, '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pg = Db::pg();

/** xprofile platform slug → SOCIAL_KINDS */
const XPROFILE_MAP = [
    'facebook'  => 'facebook',
    'instagram' => 'instagram',
    'twitter'   => 'x',
    'reddit'    => 'web',     // folded — preserve URL, not its own kind
    'youtube'   => 'youtube',
    'youTube'   => 'youtube', // BB stores the camelCase variant
    'website'   => 'web',     // not observed in live recon but defensible
];

/** ACF user_meta key → SOCIAL_KINDS */
const ACF_MAP = [
    'author_instagram' => 'instagram',
    'author_youtube'   => 'youtube',
    'author_facebook'  => 'facebook',
    'author_website'   => 'web',
    'author_linktree'  => 'linktree',
];

const XPROFILE_SOCIALS_FIELD_ID = 266;

$counts = [
    'users_walked'      => 0,
    'no_bridge'         => 0,
    'kept_existing'     => 0,
    'inserted_xprofile' => 0,
    'inserted_acf'      => 0,
    'skipped_empty'     => 0,
];

$bridge = $pg->prepare("SELECT user_id FROM wp_user_bridge WHERE wp_user_id = :w");
$selExisting = $pg->prepare("SELECT kind FROM profile_socials WHERE user_id = :u");
$insSocial = $pg->prepare("
    INSERT INTO profile_socials (user_id, kind, value, sort_order)
    VALUES (:u, :k, :v,
        (SELECT COALESCE(MAX(sort_order), -1) + 1
         FROM profile_socials WHERE user_id = :u))
");

$xprofileQ = $wp->prepare("
    SELECT value FROM wp_bp_xprofile_data
    WHERE user_id = :u AND field_id = :f
");

$acfQ = $wp->prepare("
    SELECT meta_key, meta_value FROM wp_usermeta
    WHERE user_id = :u AND meta_key IN ('" . implode("','", array_keys(ACF_MAP)) . "')
");

$where = $ONE_USER ? "WHERE u.ID = " . (int)$ONE_USER : "";
$wpUsers = $wp->query("SELECT u.ID FROM wp_users u $where ORDER BY u.ID");

if ($COMMIT) $pg->beginTransaction();

while ($wpu = $wpUsers->fetch(PDO::FETCH_ASSOC)) {
    $wpId = (int)$wpu['ID'];
    $bridge->execute([':w' => $wpId]);
    $paId = (int)$bridge->fetchColumn();
    if (!$paId) { $counts['no_bridge']++; continue; }
    $counts['users_walked']++;

    // Existing rows — never clobber an editor edit.
    $selExisting->execute([':u' => $paId]);
    $existingKinds = [];
    while ($r = $selExisting->fetch(PDO::FETCH_ASSOC)) {
        $existingKinds[$r['kind']] = true;
    }

    // === Source 1: xprofile field 266 (primary) ===
    $xprofileSocials = [];   // kind => url
    $xprofileQ->execute([':u' => $wpId, ':f' => XPROFILE_SOCIALS_FIELD_ID]);
    $raw = $xprofileQ->fetchColumn();
    if (is_string($raw) && $raw !== '') {
        $parsed = @unserialize($raw, ['allowed_classes' => false]);
        if (is_array($parsed)) {
            foreach ($parsed as $platform => $url) {
                $url = is_string($url) ? trim($url) : '';
                if ($url === '') continue;
                $kind = XPROFILE_MAP[$platform] ?? null;
                if ($kind === null) continue;
                // First-wins within a platform: don't let later platforms
                // overwrite earlier mappings (e.g., if both 'website' and
                // 'reddit' both map to web, keep the first observed URL).
                if (!isset($xprofileSocials[$kind])) {
                    $xprofileSocials[$kind] = $url;
                }
            }
        }
    }

    // === Source 2: ACF author_* (fallback) ===
    $acfSocials = [];        // kind => url
    $acfQ->execute([':u' => $wpId]);
    while ($r = $acfQ->fetch(PDO::FETCH_ASSOC)) {
        $url = is_string($r['meta_value']) ? trim($r['meta_value']) : '';
        if ($url === '') continue;
        $kind = ACF_MAP[$r['meta_key']] ?? null;
        if ($kind === null) continue;
        if (!isset($acfSocials[$kind])) {
            $acfSocials[$kind] = $url;
        }
    }

    if (!$xprofileSocials && !$acfSocials) {
        $counts['skipped_empty']++;
        continue;
    }

    $diff = [];

    // Walk the union of (xprofile keys ∪ acf keys), applying precedence.
    foreach (array_unique(array_merge(array_keys($xprofileSocials), array_keys($acfSocials))) as $kind) {
        if (isset($existingKinds[$kind])) {
            $counts['kept_existing']++;
            continue;
        }
        if (isset($xprofileSocials[$kind])) {
            $url = $xprofileSocials[$kind];
            $diff[] = "+xp:$kind=$url";
            if ($COMMIT) $insSocial->execute([':u' => $paId, ':k' => $kind, ':v' => $url]);
            $counts['inserted_xprofile']++;
        } elseif (isset($acfSocials[$kind])) {
            $url = $acfSocials[$kind];
            $diff[] = "+acf:$kind=$url";
            if ($COMMIT) $insSocial->execute([':u' => $paId, ':k' => $kind, ':v' => $url]);
            $counts['inserted_acf']++;
        }
    }

    if ($diff) printf("wp=%d pa=%d\n  %s\n", $wpId, $paId, implode("\n  ", $diff));
}

if ($COMMIT) $pg->commit();

echo "\n";
foreach ($counts as $k => $v) printf("  %-24s %d\n", $k, $v);
echo $COMMIT ? "\nCOMMITTED.\n" : "\n(dry-run — re-run with --commit to apply)\n";
