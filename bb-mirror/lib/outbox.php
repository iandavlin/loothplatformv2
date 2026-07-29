<?php
/**
 * bb-mirror/lib/outbox.php — the durable half of the WP→mirror dispatch.
 *
 * WHY THIS EXISTS
 * ---------------
 * Every mirror-relevant WordPress event used to reach the mirror exactly one
 * way: a fire-and-forget `wp_remote_post(..., 'blocking' => false, timeout 1)`
 * whose result nobody read, logged or retried. If that request was dropped, WP
 * never found out and the mirror silently diverged — permanently. atlas
 * docs/atlas/MIRROR-ATTACHMENT-ORPHANS.md §10 traces four of five mirror defect
 * classes (ghosts, never-rendered replies, author drift, the miscredited post)
 * back to that one line.
 *
 * Measured on dev2 2026-07-29, which is what makes the drop mechanism concrete
 * rather than theoretical:
 *   * the /_sync endpoint takes 7.3s cold and ~1.1s warm to answer, against a
 *     dispatch whose timeout is 1;
 *   * it runs on the `looth-dev` FPM pool, pm.max_children = 8 — the SAME pool
 *     that serves member page loads.
 * So a bulk delete of N posts fires N of these at once and saturates the pool.
 * Whatever nginx cannot place is dropped, and the fire-and-forget caller cannot
 * tell the difference between "delivered" and "dropped on the floor".
 *
 * THE SHAPE
 * ---------
 * This is an outbox, not a retry bolted onto the existing call:
 *
 *   1. the WP hook ENQUEUES a row here, in MySQL, at the moment of the change;
 *   2. the existing non-blocking POST stays as the FAST PATH — unchanged
 *      latency, still fire-and-forget, still the thing that does the work 99%
 *      of the time. Its payload now carries this row's `outbox_id`;
 *   3. api/v0/_sync.php ACKS the row after it has materialized successfully.
 *      The ack travels through the DATABASE, not the HTTP response — which is
 *      the trick that lets the fast path stay non-blocking and still be
 *      verifiable. The receiver has WP loaded already, so $wpdb is right there;
 *   4. bin/outbox-worker.php sweeps rows nobody acked, redelivers them with a
 *      BLOCKING raw curl whose response is actually read, backs off, and
 *      dead-letters + alerts when delivery stays failed.
 *
 * A row that is still `pending` past its grace window IS the alarm. That is the
 * whole point: the failure that used to be invisible is now a row in a table.
 *
 * WHY MySQL AND NOT THE MIRROR'S POSTGRES
 * ---------------------------------------
 * The event is recorded in the same database as the fact that generated it. If
 * the post write succeeded, MySQL is up, so the outbox write succeeds too.
 * Writing the outbox to Postgres would put the durability record in the exact
 * failure domain it is supposed to survive — Postgres being down is precisely
 * when the mirror is unreachable and the outbox matters most.
 *
 * TRANSPORT: raw curl with CURLOPT_RESOLVE, per the repo convention
 * (lg-patreon-stripe-poller/CLAUDE.md:25 — Cloudflare challenges PHP-curl, and
 * wp_remote_post does NOT honor the resolve pin). Pinning the real hostname to
 * 127.0.0.1 also means SNI and the Host header agree, which the old
 * https://127.0.0.1/ + Host-override form could not manage.
 *
 * Consumers — all three run with WP loaded, so $wpdb is always available:
 *   platform/mu-plugins/bb-mirror-sync.php   enqueue + fast path
 *   bb-mirror/api/v0/_sync.php               ack
 *   bb-mirror/bin/outbox-worker.php          claim / redeliver / alert
 */

declare(strict_types=1);

if (!defined('BB_MIRROR_OUTBOX_LOADED')) {
define('BB_MIRROR_OUTBOX_LOADED', true);

// define() rather than const: this whole file is wrapped in a conditional guard
// (it is required from three different runtimes, two of which may load it twice
// in one request), and `const` is a compile-time construct that PHP does not
// allow inside a block.

/** Seconds before the WORKER first touches a row — the fast path's grace window.
 *  Must comfortably exceed the endpoint's cold-start time (7.3s measured) so a
 *  slow-but-fine dispatch is never redelivered underneath itself. */
define('BB_MIRROR_OUTBOX_GRACE', 60);

/** Attempts before a row is declared dead and a human is needed. */
define('BB_MIRROR_OUTBOX_MAX_ATTEMPTS', 12);

/** Backoff ladder in seconds, indexed by attempt count; last value repeats. */
define('BB_MIRROR_OUTBOX_BACKOFF', [60, 60, 120, 300, 600, 900, 1800, 3600]);

/** A pending row older than this (seconds) means delivery is genuinely stuck. */
define('BB_MIRROR_OUTBOX_ALERT_AGE', 900);

/** Schema version — bump to force a re-install pass. */
define('BB_MIRROR_OUTBOX_SCHEMA_VERSION', 1);

function bb_mirror_outbox_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'bb_mirror_outbox';
}

/**
 * Idempotent table create. Deploy on these boxes is ONE PULL, so the schema has
 * to install itself rather than wait for someone to remember a migration — see
 * the standing rule in ~/.claude/CLAUDE.md. Guarded by an autoloaded option, so
 * the steady-state cost is one array lookup on an already-loaded option, not a
 * SHOW TABLES per request.
 */
function bb_mirror_outbox_ensure(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;

    if (!function_exists('get_option')) { $ok = false; return $ok; }
    if ((int) get_option('bb_mirror_outbox_schema', 0) === BB_MIRROR_OUTBOX_SCHEMA_VERSION) {
        $ok = true;
        return $ok;
    }
    $ok = bb_mirror_outbox_install();
    if ($ok) update_option('bb_mirror_outbox_schema', BB_MIRROR_OUTBOX_SCHEMA_VERSION, true);
    return $ok;
}

function bb_mirror_outbox_install(): bool {
    global $wpdb;
    $table   = bb_mirror_outbox_table();
    $collate = $wpdb->get_charset_collate();

    // `dedupe_key` is (kind:object_id) — deliberately NOT unique. Ordering
    // matters more than uniqueness here: upsert→delete→upsert is a legal and
    // meaningful sequence, and collapsing it on a unique key would corrupt it.
    // The key exists so the enqueue-side "is the newest pending row for this
    // object already this action?" lookup is an index seek.
    $sql = "CREATE TABLE IF NOT EXISTS `$table` (
        `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `kind`            VARCHAR(20)     NOT NULL,
        `object_id`       BIGINT          NOT NULL,
        `action`          VARCHAR(20)     NOT NULL,
        `payload`         TEXT            NOT NULL,
        `dedupe_key`      VARCHAR(64)     NOT NULL,
        `status`          VARCHAR(12)     NOT NULL DEFAULT 'pending',
        `attempts`        INT UNSIGNED    NOT NULL DEFAULT 0,
        `created_at`      DATETIME        NOT NULL,
        `next_attempt_at` DATETIME        NOT NULL,
        `delivered_at`    DATETIME        NULL,
        `delivered_by`    VARCHAR(12)     NULL,
        `last_http`       INT             NULL,
        `last_error`      TEXT            NULL,
        PRIMARY KEY (`id`),
        KEY `idx_claim`  (`status`, `next_attempt_at`, `id`),
        KEY `idx_object` (`dedupe_key`, `status`, `id`),
        KEY `idx_created`(`created_at`)
    ) $collate";

    $wpdb->query($sql);
    return (bool) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
}

/**
 * Record a mirror-relevant event durably. Returns the row id, or 0 if the row
 * could not be written (in which case the caller still fires the fast path —
 * a broken outbox must never make the site worse than it was before it existed).
 *
 * DEDUPE, and why it is deliberately narrow: we skip the insert only when the
 * NEWEST still-pending row for this same object already carries this same
 * action. That collapses the genuinely redundant case (several hooks firing
 * 'upsert' for one post in one request) without ever reordering or swallowing a
 * meaningful sequence — upsert→delete→upsert must survive intact, because
 * replaying it out of order is how you manufacture a ghost.
 */
function bb_mirror_outbox_enqueue(string $kind, int $id, string $action, array $extra = []): int {
    global $wpdb;
    if ($id <= 0 || $kind === '' || $action === '') return 0;
    if (!bb_mirror_outbox_ensure()) return 0;

    $table  = bb_mirror_outbox_table();
    $dedupe = $kind . ':' . $id;

    $newest_pending_action = $wpdb->get_var($wpdb->prepare(
        "SELECT `action` FROM `$table` WHERE `dedupe_key` = %s AND `status` = 'pending'
         ORDER BY `id` DESC LIMIT 1", $dedupe));
    if ($newest_pending_action === $action) return 0;

    $now = gmdate('Y-m-d H:i:s');
    $payload = wp_json_encode(array_merge(
        ['kind' => $kind, 'id' => $id, 'action' => $action], $extra));

    $inserted = $wpdb->insert($table, [
        'kind'            => $kind,
        'object_id'       => $id,
        'action'          => $action,
        'payload'         => $payload,
        'dedupe_key'      => $dedupe,
        'status'          => 'pending',
        'attempts'        => 0,
        'created_at'      => $now,
        'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + BB_MIRROR_OUTBOX_GRACE),
    ]);
    return $inserted ? (int) $wpdb->insert_id : 0;
}

/**
 * The receiver's ack. Called by api/v0/_sync.php once the row has actually been
 * materialized — i.e. this records that the WORK completed, not merely that an
 * HTTP request arrived.
 */
function bb_mirror_outbox_ack(int $outbox_id, bool $ok, ?string $error = null, string $by = 'fastpath'): void {
    global $wpdb;
    if ($outbox_id <= 0) return;
    if (!function_exists('get_option') || !bb_mirror_outbox_ensure()) return;
    $table = bb_mirror_outbox_table();

    if ($ok) {
        $wpdb->query($wpdb->prepare(
            "UPDATE `$table` SET `status` = 'delivered', `delivered_at` = %s, `delivered_by` = %s,
                    `last_http` = 200, `last_error` = NULL
              WHERE `id` = %d AND `status` <> 'delivered'",
            gmdate('Y-m-d H:i:s'), $by, $outbox_id));
        return;
    }
    // Failure recorded by the receiver: leave it PENDING so the worker retries,
    // but bank the reason so a human reading the table sees why.
    $wpdb->query($wpdb->prepare(
        "UPDATE `$table` SET `attempts` = `attempts` + 1, `last_error` = %s,
                `next_attempt_at` = %s
          WHERE `id` = %d AND `status` = 'pending'",
        (string) $error, gmdate('Y-m-d H:i:s', time() + BB_MIRROR_OUTBOX_GRACE), $outbox_id));
}

/**
 * Rows the worker should consider this tick: pending and past their next
 * attempt time, oldest first. Ordering by id is load-bearing — id order is
 * enqueue order, and the worker replays per object in exactly that order.
 */
function bb_mirror_outbox_claim(int $limit = 200): array {
    global $wpdb;
    if (!bb_mirror_outbox_ensure()) return [];
    $table = bb_mirror_outbox_table();
    return (array) $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM `$table`
          WHERE `status` = 'pending' AND `next_attempt_at` <= %s
          ORDER BY `id` ASC LIMIT %d",
        gmdate('Y-m-d H:i:s'), $limit), ARRAY_A);
}

/** Mark a row superseded by a later one for the same object (never delivered). */
function bb_mirror_outbox_supersede(int $outbox_id): void {
    global $wpdb;
    $table = bb_mirror_outbox_table();
    $wpdb->query($wpdb->prepare(
        "UPDATE `$table` SET `status` = 'superseded', `delivered_at` = %s
          WHERE `id` = %d AND `status` = 'pending'",
        gmdate('Y-m-d H:i:s'), $outbox_id));
}

/** Record a failed worker delivery: bump attempts, back off, dead-letter at the cap. */
function bb_mirror_outbox_fail(array $row, int $http, string $error): string {
    global $wpdb;
    $table    = bb_mirror_outbox_table();
    $attempts = (int) $row['attempts'] + 1;
    $dead     = $attempts >= BB_MIRROR_OUTBOX_MAX_ATTEMPTS;

    $ladder = BB_MIRROR_OUTBOX_BACKOFF;
    $delay  = $ladder[min($attempts, count($ladder) - 1)];

    $wpdb->query($wpdb->prepare(
        "UPDATE `$table` SET `attempts` = %d, `status` = %s, `last_http` = %d,
                `last_error` = %s, `next_attempt_at` = %s
          WHERE `id` = %d",
        $attempts, $dead ? 'dead' : 'pending', $http, $error,
        gmdate('Y-m-d H:i:s', time() + $delay), (int) $row['id']));

    return $dead ? 'dead' : 'pending';
}

/**
 * Deliver one row over the loopback, BLOCKING, and actually read the answer.
 * This is the difference between this and what it replaces.
 *
 * @return array{ok:bool, http:int, error:string, seconds:float}
 */
// $port exists so bin/test-outbox.php can pin delivery at a port nothing is
// listening on and produce a REAL connection failure — the same thing curl sees
// when FPM has no child free and nginx cannot place the request. Production
// callers never pass it.
function bb_mirror_outbox_deliver(array $row, ?string $host = null, int $timeout = 30, int $port = 443): array {
    $host = $host ?: bb_mirror_outbox_host();
    $url  = 'https://' . $host . ($port === 443 ? '' : ':' . $port) . '/bb-mirror-api/v0/_sync';

    // The payload the worker sends carries this row's id, exactly as the fast
    // path does, so the receiver's ack path is identical for both.
    $payload = json_decode((string) $row['payload'], true);
    if (!is_array($payload)) $payload = ['kind' => $row['kind'], 'id' => (int) $row['object_id'], 'action' => $row['action']];
    $payload['outbox_id'] = (int) $row['id'];

    $started = microtime(true);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        // The repo convention: pin the real hostname to loopback so Cloudflare
        // never sees this, and SNI + Host agree. wp_remote_post cannot do this.
        CURLOPT_RESOLVE        => ["{$host}:{$port}:127.0.0.1"],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-BB-Mirror-Sync: 1',
        ],
    ]);
    $body    = curl_exec($ch);
    $http    = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlerr = curl_error($ch);
    curl_close($ch);
    $seconds = round(microtime(true) - $started, 3);

    if ($curlerr !== '') return ['ok' => false, 'http' => $http, 'error' => "curl: $curlerr", 'seconds' => $seconds];
    if ($http !== 200)   return ['ok' => false, 'http' => $http, 'error' => 'http ' . $http . ': ' . substr((string) $body, 0, 300), 'seconds' => $seconds];

    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        return ['ok' => false, 'http' => $http, 'error' => 'bad body: ' . substr((string) $body, 0, 300), 'seconds' => $seconds];
    }
    return ['ok' => true, 'http' => $http, 'error' => '', 'seconds' => $seconds];
}

/** Loopback Host for delivery. Mirrors the mu-plugin's resolver precedence. */
function bb_mirror_outbox_host(): string {
    if (function_exists('bb_mirror_sync_host')) return bb_mirror_sync_host();
    if (defined('LG_BB_MIRROR_HOST')) return (string) constant('LG_BB_MIRROR_HOST');
    if (is_file('/srv/lg-shared/lg-env.php')) {
        require_once '/srv/lg-shared/lg-env.php';
        if (function_exists('lg_env')) {
            $h = lg_env()['host'] ?? '';
            if ($h !== '') return $h;
        }
    }
    return 'loothgroup.com';
}

/**
 * Health snapshot. `stuck` is the number that matters: rows still pending well
 * past the point where the fast path plus several worker retries should have
 * carried them. A non-zero `stuck` or `dead` means the mirror is diverging from
 * WordPress RIGHT NOW — which is exactly the condition that used to be
 * completely invisible.
 */
function bb_mirror_outbox_stats(): array {
    global $wpdb;
    if (!bb_mirror_outbox_ensure()) return ['pending' => 0, 'stuck' => 0, 'dead' => 0, 'delivered' => 0, 'superseded' => 0];
    $table = bb_mirror_outbox_table();

    $out = ['pending' => 0, 'stuck' => 0, 'dead' => 0, 'delivered' => 0, 'superseded' => 0];
    foreach ((array) $wpdb->get_results("SELECT `status`, COUNT(*) c FROM `$table` GROUP BY `status`", ARRAY_A) as $r) {
        if (isset($out[$r['status']])) $out[$r['status']] = (int) $r['c'];
    }
    $out['stuck'] = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `$table` WHERE `status` = 'pending' AND `created_at` <= %s",
        gmdate('Y-m-d H:i:s', time() - BB_MIRROR_OUTBOX_ALERT_AGE)));
    return $out;
}

/**
 * Retention. Delivered/superseded rows are an audit trail, not a queue — keep a
 * window of them so "did this event actually land?" stays answerable after the
 * fact, then drop them. Pending/dead rows are NEVER pruned: a dead row is an
 * open incident and deleting it would re-hide the failure this whole file
 * exists to surface.
 */
function bb_mirror_outbox_prune(int $keep_days = 7): int {
    global $wpdb;
    if (!bb_mirror_outbox_ensure()) return 0;
    $table = bb_mirror_outbox_table();
    return (int) $wpdb->query($wpdb->prepare(
        "DELETE FROM `$table`
          WHERE `status` IN ('delivered','superseded') AND `delivered_at` < %s",
        gmdate('Y-m-d H:i:s', time() - $keep_days * 86400)));
}

} // BB_MIRROR_OUTBOX_LOADED
