<?php
declare(strict_types=1);

/**
 * Looth Web-Push sender core.
 *
 * Reads the VAPID keypair (root-only /etc/lg-vapid) + the subscription rows from
 * the WP MySQL table wp_lg_push_subscriptions, signs + delivers encrypted payloads
 * via minishlink/web-push, and prunes subscriptions the push service reports as
 * gone (404/410).
 *
 * DB access is a thin PDO connection built from the WP config (no full WP bootstrap)
 * so this stays cheap enough to run from cron. The VAPID PRIVATE key is read here and
 * never leaves the process; callers must run as a user that can read /etc/lg-vapid
 * (root cron), which is why the publish path ENQUEUES instead of sending inline.
 *
 * No emoji. Self-contained beyond the composer vendor dir.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

const LGPUSH_VAPID_DIR  = '/etc/lg-vapid';
const LGPUSH_WP_CONFIG  = '/var/www/dev/wp-config.php';
const LGPUSH_SUBJECT    = 'mailto:admin@loothgroup.com';

function lgpush_vapid(): array
{
    $pub  = trim((string) @file_get_contents(LGPUSH_VAPID_DIR . '/vapid_public.b64url'));
    $priv = trim((string) @file_get_contents(LGPUSH_VAPID_DIR . '/vapid_private.b64url'));
    if ($pub === '' || $priv === '') {
        fwrite(STDERR, "lgpush: cannot read VAPID keys from " . LGPUSH_VAPID_DIR . " (run as root)\n");
        exit(2);
    }
    return ['subject' => LGPUSH_SUBJECT, 'publicKey' => $pub, 'privateKey' => $priv];
}

function lgpush_db(): PDO
{
    $cfg = (string) @file_get_contents(LGPUSH_WP_CONFIG);
    if ($cfg === '') {
        fwrite(STDERR, "lgpush: cannot read " . LGPUSH_WP_CONFIG . "\n");
        exit(2);
    }
    $val = function (string $k) use ($cfg): string {
        if (preg_match("/define\\(\\s*['\"]" . preg_quote($k, '/') . "['\"]\\s*,\\s*['\"](.*?)['\"]\\s*\\)/s", $cfg, $m)) {
            return $m[1];
        }
        return '';
    };
    $name = $val('DB_NAME');
    $user = $val('DB_USER');
    $pass = $val('DB_PASSWORD');
    $host = $val('DB_HOST') ?: 'localhost';
    if (preg_match("/\\\$table_prefix\\s*=\\s*['\"](.*?)['\"]/", $cfg, $m)) {
        $GLOBALS['lgpush_prefix'] = $m[1];
    } else {
        $GLOBALS['lgpush_prefix'] = 'wp_';
    }

    // DB_HOST may be "host", "host:port", or "localhost:/path/to/socket".
    $dsn = '';
    if (strpos($host, ':') !== false) {
        [$h, $rest] = explode(':', $host, 2);
        if ($rest !== '' && $rest[0] === '/') {
            $dsn = "mysql:unix_socket={$rest};dbname={$name};charset=utf8mb4";
        } else {
            $port = is_numeric($rest) ? (int) $rest : 3306;
            $dsn  = "mysql:host={$h};port={$port};dbname={$name};charset=utf8mb4";
        }
    } else {
        $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
    }
    return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function lgpush_table(): string
{
    return ($GLOBALS['lgpush_prefix'] ?? 'wp_') . 'lg_push_subscriptions';
}

function lgpush_count(?string $where = null, array $params = []): int
{
    $pdo = lgpush_db();
    $sql = 'SELECT count(*) FROM ' . lgpush_table();
    if ($where) $sql .= " WHERE {$where}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int) $st->fetchColumn();
}

/**
 * Send $payload (title/body/url/icon/tag) to the subscriptions matching $where.
 * Returns ['total','sent','failed','pruned'].
 */
function lgpush_send(array $payload, ?string $where = null, array $params = []): array
{
    $pdo = lgpush_db();
    $tbl = lgpush_table();
    $sql = "SELECT id, endpoint, p256dh, auth FROM {$tbl}";
    if ($where) $sql .= " WHERE {$where}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $subs = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$subs) {
        return ['total' => 0, 'sent' => 0, 'failed' => 0, 'pruned' => 0];
    }

    $webPush = new WebPush(['VAPID' => lgpush_vapid()]);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

    $byEndpoint = [];
    foreach ($subs as $s) {
        $sub = Subscription::create([
            'endpoint' => $s['endpoint'],
            'keys'     => ['p256dh' => $s['p256dh'], 'auth' => $s['auth']],
        ]);
        $webPush->queueNotification($sub, $json);
        $byEndpoint[$s['endpoint']] = (int) $s['id'];
    }

    $sent = 0;
    $failed = 0;
    $pruneIds = [];
    foreach ($webPush->flush() as $report) {
        $ep = $report->getEndpoint();
        if ($report->isSuccess()) {
            $sent++;
        } else {
            $failed++;
            // 404/410 from the push service = subscription is gone; prune it.
            if ($report->isSubscriptionExpired() && isset($byEndpoint[$ep])) {
                $pruneIds[] = $byEndpoint[$ep];
            }
        }
    }

    $pruned = 0;
    if ($pruneIds) {
        $in  = implode(',', array_fill(0, count($pruneIds), '?'));
        $del = $pdo->prepare("DELETE FROM {$tbl} WHERE id IN ({$in})");
        $del->execute($pruneIds);
        $pruned = $del->rowCount();
    }

    return ['total' => count($subs), 'sent' => $sent, 'failed' => $failed, 'pruned' => $pruned];
}
