<?php
/**
 * profile-app — retention prune for the notifications bell (30-day ruling).
 *
 * DELETEs notification rows older than N days (default 30) regardless of read
 * state; the underlying DM / connection / hub thread is NOT touched. Idempotent
 * and safe to re-run. Invoked by prune-notifications.timer (daily) — NOT on any
 * request path (Notifications.php's prune() comment is explicit about that).
 *
 * Talks straight to Postgres via Db::pg() (peer auth as the `profile-app` role),
 * so it must run as OS user `profile-app` — see platform/systemd/
 * prune-notifications.service. No HTTP, no gate token.
 *
 * Usage:  php bin/prune-notifications.php [days]     (days: positive int, def 30)
 */

declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Notifications.php'; // not in config's require list; callers load it

use Looth\ProfileApp\Notifications;

$days    = isset($argv[1]) ? max(1, (int) $argv[1]) : 30;
$deleted = Notifications::prune($days);

// One journal-friendly line (StandardOutput=journal in the .service).
fwrite(STDOUT, sprintf(
    "[prune-notifications] deleted %d notification(s) older than %d day(s)\n",
    $deleted,
    $days
));
