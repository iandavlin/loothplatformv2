<?php
/**
 * profile-app — one-shot backfill: mirror every member's About plain-text projection
 * into WP usermeta `author_about` (the article author-box field).
 *
 *   sudo -u profile-app php bin/backfill-author-about.php            # DRY-RUN (default)
 *   sudo -u profile-app php bin/backfill-author-about.php --apply    # write
 *
 * Runs as the `profile-app` role — the one context that reaches BOTH the profile_app
 * Postgres (peer auth) AND the WP MySQL (unix socket) — using the SAME select →
 * update/insert channel me-about.php uses for the live per-save mirror. Idempotent:
 * a row already equal to the projection is left untouched; a second run writes nothing.
 *
 * ⚠ MUST run AFTER the grant lands. Today `profile-app@localhost` is SELECT-ONLY on the
 * WP DB (deploy/profile-app-live-bootstrap.sh), so --apply will fail every write until
 * `GRANT INSERT, UPDATE ON <wp_db>.wp_usermeta TO 'profile-app'@'localhost'` is applied
 * (see the profile-enhance report + memory profileapp-wp-write-grant-blocked). DRY-RUN
 * works today (reads only). Do NOT --apply before the grant.
 *
 * Projection source = the SAME derivation me-about stores: htmlToPlainText(data.html)
 * when a rich body exists, else the stored plain data.text. So the author box shows the
 * exact plain text the profile About shows — never markup.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("backfill-author-about: CLI only\n");
}

require_once __DIR__ . '/../config.php';

use Looth\ProfileApp\Block;
use Looth\ProfileApp\Db;

$apply = in_array('--apply', $argv, true);
$mode  = $apply ? 'APPLY' : 'DRY-RUN';
fwrite(STDERR, "backfill-author-about: mode={$mode}\n");

$pg = Db::pg();

// Every bridged member who has an About row. (user_id, key) is unique, so one row each.
$rows = $pg->query("
    SELECT u.id AS uid, b.wp_user_id AS wp, ps.data AS data
    FROM profile_sections ps
    JOIN users u          ON u.id = ps.user_id
    JOIN wp_user_bridge b ON b.user_id = u.id
    WHERE ps.key = 'about'
    ORDER BY u.id
")->fetchAll(PDO::FETCH_ASSOC);

// WP MySQL handle (needed for the current-value read AND the write). If it can't open,
// abort before touching anything.
try {
    $un = posix_getpwuid(posix_geteuid())['name'] ?? 'profile-app';
    $my = new PDO(
        'mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=' . LG_PROFILE_APP_MYSQL_DB,
        $un, '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'FATAL: cannot open WP MySQL: ' . $e->getMessage() . "\n");
    exit(3);
}

$c = ['scanned' => 0, 'empty' => 0, 'already_ok' => 0, 'would_write' => 0, 'written' => 0, 'failed' => 0];

foreach ($rows as $r) {
    $c['scanned']++;
    $d    = json_decode((string) $r['data'], true) ?: [];
    $html = (string) ($d['html'] ?? '');
    $text = (string) ($d['text'] ?? '');
    // Same projection me-about stores: derive from sanitized html when present.
    $proj = $html !== '' ? Block::htmlToPlainText($html) : trim($text);
    if ($proj === '') { $c['empty']++; continue; }

    $wpId = (int) $r['wp'];
    $sel  = $my->prepare("SELECT umeta_id, meta_value FROM wp_usermeta WHERE user_id = ? AND meta_key = 'author_about' LIMIT 1");
    $sel->execute([$wpId]);
    $cur = $sel->fetch(PDO::FETCH_ASSOC);

    if ($cur !== false && (string) $cur['meta_value'] === $proj) { $c['already_ok']++; continue; }

    if (!$apply) {
        $c['would_write']++;
        fwrite(STDOUT, sprintf("would set wp=%d (uid=%d) len=%d\n", $wpId, (int) $r['uid'], mb_strlen($proj)));
        continue;
    }

    try {
        if ($cur !== false) {
            $my->prepare('UPDATE wp_usermeta SET meta_value = ? WHERE umeta_id = ?')->execute([$proj, $cur['umeta_id']]);
        } else {
            $my->prepare("INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (?, 'author_about', ?)")->execute([$wpId, $proj]);
        }
        $c['written']++;
    } catch (Throwable $e) {
        $c['failed']++;
        fwrite(STDERR, sprintf("FAIL wp=%d: %s\n", $wpId, $e->getMessage()));
    }
}

fwrite(STDERR, sprintf(
    "done: scanned=%d empty=%d already_ok=%d would_write=%d written=%d failed=%d (mode=%s)\n",
    $c['scanned'], $c['empty'], $c['already_ok'], $c['would_write'], $c['written'], $c['failed'], $mode
));

// Non-zero on any write failure (e.g. the grant isn't in place) so a wrapper/CI notices.
exit($c['failed'] > 0 ? 1 : 0);
