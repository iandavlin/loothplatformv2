<?php
/**
 * bb-mirror/bin/outbox-worker.php — the guarantee behind the fast path.
 *
 * The mu-plugin records every mirror-relevant event in `wp_bb_mirror_outbox`
 * and then fires the usual non-blocking POST. api/v0/_sync.php acks the row once
 * it has materialized the change. This worker exists for the rows nobody acked:
 * it redelivers them over a BLOCKING request whose response is actually read,
 * backs off, dead-letters what stays broken, and makes noise about it.
 *
 * Usage:
 *   sudo -u looth-dev wp eval-file /srv/bb-mirror/bin/outbox-worker.php
 *   BB_MIRROR_OUTBOX_DRYRUN=1 ...     report what it WOULD deliver, send nothing
 *   BB_MIRROR_OUTBOX_PORT=9    ...    REHEARSAL ONLY: deliver at a dead port to
 *                                     rehearse the retry ladder and the alarm.
 *                                     Prints a loud banner; never set in prod.
 *
 * Cron: systemd timer at /etc/systemd/system/bb-mirror-outbox.{service,timer},
 *       every minute. Source of truth: platform/systemd/.
 *
 * EXIT CODES — these are the alert channel, because systemd surfaces them and
 * `wp_mail` on dev2 is a known false positive (mailpit swallows it):
 *   0  nothing wrong
 *   1  DELIVERY IS STUCK — dead rows, or rows pending well past their window.
 *      The mirror is diverging from WordPress right now.
 *
 * ORDERING IS LOAD-BEARING. Events for one object replay in enqueue (id) order
 * and a group STOPS at its first failure. Replaying upsert→delete→upsert out of
 * order is a way to manufacture exactly the ghost this whole lane is about.
 */

require __DIR__ . '/../config.php';

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
if (!function_exists('get_post_meta')) {
    fwrite(STDERR, "Run via: sudo -u looth-dev wp eval-file " . __FILE__ . "\n");
    exit(2);
}

require_once __DIR__ . '/../lib/outbox.php';

$dry = getenv('BB_MIRROR_OUTBOX_DRYRUN') === '1';

// ---------- the outage-rehearsal knob ---------------------------------------
// bb_mirror_outbox_deliver()'s $port parameter already exists so a test can pin
// delivery at a port nothing is listening on and produce a REAL connection
// failure — the same thing curl sees when FPM has no child free and nginx cannot
// place the request. This exposes it to the worker, so the retry ladder and the
// alarm can be rehearsed BY SYSTEMD, on the real unit, without stopping nginx or
// php-fpm box-wide (dev2 is shared — that would be an outage for everyone).
//
// It is LOUD on purpose. A silent delivery-target override left set in
// production would send every event into a hole while the outbox reported
// healthy retries — the exact invisible-divergence failure this whole file
// exists to end. If you see this banner on a real box, something is misconfigured.
$port = 443;
$port_env = getenv('BB_MIRROR_OUTBOX_PORT');
if ($port_env !== false && $port_env !== '' && ctype_digit($port_env) && (int) $port_env !== 443) {
    $port = (int) $port_env;
    $banner = "[bb-mirror outbox] !!! DELIVERY PORT OVERRIDDEN TO $port — REHEARSAL MODE, NOT PRODUCTION !!!";
    echo $banner . "\n";
    fwrite(STDERR, $banner . "\n");
    error_log($banner);
}

if (!bb_mirror_outbox_ensure()) {
    fwrite(STDERR, "outbox table missing and could not be created\n");
    exit(2);
}

$stats = bb_mirror_outbox_stats();
echo "Outbox before: " . json_encode($stats) . ($dry ? "   [DRY RUN]" : "") . "\n";

$rows = bb_mirror_outbox_claim(200);
echo "Due this tick: " . count($rows) . "\n";

// ---------- group by object, preserving enqueue order ----------------------
$groups = [];
foreach ($rows as $r) $groups[$r['dedupe_key']][] = $r;

$delivered = $superseded = $failed = $dead = 0;

foreach ($groups as $key => $group) {
    // COLLAPSE consecutive upserts. An upsert carries no content — the
    // materializer re-reads current WP state when it runs — so of a run of
    // consecutive upserts only the last one can possibly matter. Anything that
    // is NOT an upsert (delete/trash/spam/restore) is a distinct state
    // transition and is never collapsed away.
    $n = count($group);
    for ($i = 0; $i < $n - 1; $i++) {
        if ($group[$i]['action'] === 'upsert' && $group[$i + 1]['action'] === 'upsert') {
            if (!$dry) bb_mirror_outbox_supersede((int) $group[$i]['id']);
            $group[$i] = null;
            $superseded++;
        }
    }
    $group = array_values(array_filter($group));

    foreach ($group as $row) {
        $label = "#{$row['id']} {$row['kind']}#{$row['object_id']} {$row['action']} (attempt " . ((int) $row['attempts'] + 1) . ")";

        if ($dry) { echo "  WOULD DELIVER $label\n"; continue; }

        $res = bb_mirror_outbox_deliver($row, null, 30, $port);

        if ($res['ok']) {
            bb_mirror_outbox_ack((int) $row['id'], true, null, 'worker');
            echo "  ok       $label  {$res['seconds']}s\n";
            $delivered++;
            continue;
        }

        // A 4xx is the endpoint telling us this request is WRONG, not that it is
        // busy. Retrying an unknown kind/action or a malformed payload twelve
        // times just delays the human. 408/429 are the exceptions — those really
        // do mean "later". Everything else (5xx, curl failures, connection
        // refused) is transient by default and gets the backoff ladder.
        $permanent = $res['http'] >= 400 && $res['http'] < 500
                  && !in_array($res['http'], [408, 429], true);
        if ($permanent) {
            $row['attempts'] = BB_MIRROR_OUTBOX_MAX_ATTEMPTS - 1;   // -> dead now
        }

        $state = bb_mirror_outbox_fail($row, $res['http'], $res['error']);
        echo "  " . ($state === 'dead' ? 'DEAD    ' : 'retry   ') . "$label  {$res['error']}\n";
        $state === 'dead' ? $dead++ : $failed++;

        // STOP THIS OBJECT'S GROUP. Its later events must not overtake the one
        // that just failed.
        if ($state !== 'dead') break;
    }
}

// ---------- retention -------------------------------------------------------
// Delivered/superseded rows age out; pending and dead never do. A dead row is an
// open incident, and pruning it would re-hide the failure this file exists to
// surface.
$pruned = $dry ? 0 : bb_mirror_outbox_prune(7);

$after = bb_mirror_outbox_stats();
echo "Delivered $delivered, superseded $superseded, retrying $failed, dead $dead, pruned $pruned\n";
echo "Outbox after:  " . json_encode($after) . "\n";

// ---------- the alert -------------------------------------------------------
$alerts = [];
if ($after['dead']  > 0) $alerts[] = "{$after['dead']} DEAD row(s) — delivery failed permanently";
if ($after['stuck'] > 0) $alerts[] = "{$after['stuck']} row(s) pending > " . BB_MIRROR_OUTBOX_ALERT_AGE . "s";

if ($alerts) {
    $msg = "[bb-mirror outbox] " . implode('; ', $alerts)
         . " — the mirror is diverging from WordPress. "
         . "Inspect: SELECT * FROM " . bb_mirror_outbox_table()
         . " WHERE status IN ('dead','pending') ORDER BY id;";
    error_log($msg);
    fwrite(STDERR, $msg . "\n");

    // Also bank it where the mirror's own operators look. sync_state is the
    // existing bookkeeping table; reconcile writes its bookmark there.
    try {
        $db = bb_mirror_db(readonly: false);
        $db->prepare(bb_mirror_upsert_sql('sync_state', ['key', 'value', 'updated_at'], 'key'))
           ->execute(['outbox_alert', substr($msg, 0, 2000), bb_mirror_ts(time())]);
    } catch (Throwable $e) {
        fwrite(STDERR, "could not record alert in sync_state: " . $e->getMessage() . "\n");
    }
    exit(1);
}

// Clear a stale alert once things are healthy again, so the key means "right
// now" rather than "at some point in the past".
try {
    $db = bb_mirror_db(readonly: false);
    $db->prepare("DELETE FROM sync_state WHERE key = 'outbox_alert'")->execute();
} catch (Throwable $e) { /* non-fatal */ }

exit(0);
