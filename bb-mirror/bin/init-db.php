<?php
/**
 * bb-mirror/bin/init-db.php — apply postgres schema.
 *
 * Idempotent. CREATE TABLE IF NOT EXISTS etc. Re-runs are no-ops.
 *
 * Usage:
 *   sudo -u bb-mirror php bin/init-db.php
 *   sudo -u bb-mirror php bin/init-db.php --recreate   # destructive
 */

require __DIR__ . '/../config.php';

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$recreate = in_array('--recreate', $argv, true);

if ($recreate) {
    echo "Dropping + recreating schema " . LG_BB_MIRROR_PG_SCHEMA . " in " . LG_BB_MIRROR_PG_DB . "\n";
    $admin = new PDO(LG_BB_MIRROR_PG_DSN, null, null);
    $admin->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $admin->exec("DROP SCHEMA IF EXISTS " . LG_BB_MIRROR_PG_SCHEMA . " CASCADE");
    $admin->exec("CREATE SCHEMA " . LG_BB_MIRROR_PG_SCHEMA . " AUTHORIZATION \"bb-mirror\"");
    $admin->exec("GRANT USAGE ON SCHEMA " . LG_BB_MIRROR_PG_SCHEMA . " TO \"profile-app\"");
    $admin->exec("GRANT USAGE ON SCHEMA " . LG_BB_MIRROR_PG_SCHEMA . " TO \"looth-dev\"");
}

if (!is_file(LG_BB_MIRROR_SCHEMA_PG)) {
    fwrite(STDERR, "Cannot read " . LG_BB_MIRROR_SCHEMA_PG . "\n");
    exit(1);
}

$cmd = sprintf('psql -d %s -v ON_ERROR_STOP=1 -f %s 2>&1',
    escapeshellarg(LG_BB_MIRROR_PG_DB),
    escapeshellarg(LG_BB_MIRROR_SCHEMA_PG));
passthru($cmd, $rc);
if ($rc !== 0) { fwrite(STDERR, "psql exited $rc\n"); exit($rc); }

echo "Schema applied to " . LG_BB_MIRROR_PG_DB . "." . LG_BB_MIRROR_PG_SCHEMA . "\n";
