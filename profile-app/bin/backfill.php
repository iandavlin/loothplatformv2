<?php
/**
 * profile-app — slice zero backfill.
 *
 * Sources:
 *   1. looth_dev.wp_users                                (identity + display)
 *   2. looth_dev.wp_bp_xprofile_data field_id=96         (raw location string)
 *   3. lg_membership.customers                           (existing billing identity)
 *
 * For each WP user with a non-empty email:
 *   - compute v5 UUID over normalize(email)
 *   - INSERT users (uuid, primary_email, billing_email, contact_email,
 *                   display_name, location_text, member_since)
 *     ON CONFLICT (uuid) DO NOTHING
 *   - UPSERT wp_user_bridge(user_id, wp_user_id)
 *   - UPSERT email_aliases(email_normalized → user_id, source='wp')
 *
 * lg-stripe-billing reconciliation (READ NOTE in handoff):
 *   The existing customers.uuid values are random v4s — NOT v5(email). So we
 *   can't reconcile by UUID equality, only by email match. We count email
 *   matches separately and report them.
 *
 * Geocoding is NOT done here; that's slice one.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

use Looth\ProfileApp\Db;
use Looth\ProfileApp\Identity;

$mysqlSocket = '/var/run/mysqld/mysqld.sock';
$mysqlUser   = posix_getpwuid(posix_geteuid())['name'] ?? 'profile-app';
$wp   = new PDO('mysql:unix_socket=' . $mysqlSocket . ';dbname=' . LG_PROFILE_APP_MYSQL_DB,         $mysqlUser, '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$bill = new PDO('mysql:unix_socket=' . $mysqlSocket . ';dbname=' . LG_PROFILE_APP_MYSQL_BILLING_DB, $mysqlUser, '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pg     = Db::pg();

// --- pre-load lookups -----------------------------------------------------
echo "[1/4] loading xprofile location strings (field_id=96)…\n";
$locByUser = [];
$loc = $wp->query("SELECT user_id, value FROM wp_bp_xprofile_data WHERE field_id=96 AND value<>''");
while ($r = $loc->fetch(PDO::FETCH_ASSOC)) {
    $locByUser[(int)$r['user_id']] = (string)$r['value'];
}
echo "      " . count($locByUser) . " users have a location string\n";

echo "[2/4] loading lg_membership.customers emails for reconciliation count…\n";
$billingByEmail = [];
$bc = $bill->query("SELECT id, uuid, email, stripe_customer_id FROM customers");
while ($r = $bc->fetch(PDO::FETCH_ASSOC)) {
    $e = strtolower(trim((string)$r['email']));
    if ($e !== '') $billingByEmail[$e] = $r;
}
echo "      " . count($billingByEmail) . " billing customers loaded\n";

// --- main loop ------------------------------------------------------------
echo "[3/4] streaming wp_users…\n";
$users = $wp->query("SELECT ID, user_email, display_name, user_registered FROM wp_users WHERE user_email<>''");

$ins = $pg->prepare('
    INSERT INTO users (uuid, primary_email, billing_email, contact_email,
                       display_name, location_text, member_since)
    VALUES (:uuid, :email, :email, :email, :name, :loc, :since)
    ON CONFLICT (uuid) DO UPDATE
       SET location_text = COALESCE(users.location_text, EXCLUDED.location_text),
           display_name  = COALESCE(users.display_name,  EXCLUDED.display_name),
           member_since  = COALESCE(users.member_since,  EXCLUDED.member_since)
    RETURNING id
');
$bridge = $pg->prepare('
    INSERT INTO wp_user_bridge (user_id, wp_user_id) VALUES (:u, :w)
    ON CONFLICT (user_id) DO UPDATE SET wp_user_id = EXCLUDED.wp_user_id, synced_at = now()
');
$alias = $pg->prepare('
    INSERT INTO email_aliases (email_normalized, user_id, source)
    VALUES (:e, :u, :s) ON CONFLICT (email_normalized) DO NOTHING
');

$stats = [
    'wp_total'         => 0,
    'seeded'           => 0,
    'failed'           => 0,
    'with_location'    => 0,
    'reconciled_email' => 0,    // matched lg_membership customer by email
    'reconciled_uuid'  => 0,    // matched lg_membership customer by computed v5 uuid (expect 0)
];
$failures = [];

$pg->beginTransaction();
try {
    while ($u = $users->fetch(PDO::FETCH_ASSOC)) {
        $stats['wp_total']++;
        $wpId  = (int)$u['ID'];
        $email = trim((string)$u['user_email']);
        if ($email === '') continue;
        $norm  = strtolower($email);

        try {
            $uuid = Identity::computeUuid($email);
            $since = $u['user_registered'];
            if ($since === '0000-00-00 00:00:00' || $since === null || $since === '') $since = null;

            $loc = $locByUser[$wpId] ?? null;

            $ins->execute([
                ':uuid'  => $uuid,
                ':email' => $norm,
                ':name'  => $u['display_name'] ?: null,
                ':loc'   => $loc,
                ':since' => $since,
            ]);
            $userId = (int)$ins->fetchColumn();
            $bridge->execute([':u' => $userId, ':w' => $wpId]);
            $alias ->execute([':e' => $norm, ':u' => $userId, ':s' => 'wp']);

            $stats['seeded']++;
            if ($loc !== null) $stats['with_location']++;

            if (isset($billingByEmail[$norm])) {
                $stats['reconciled_email']++;
                if (strtolower((string)$billingByEmail[$norm]['uuid']) === $uuid) {
                    $stats['reconciled_uuid']++;
                }
            }
        } catch (Throwable $e) {
            $stats['failed']++;
            if (count($failures) < 10) {
                $failures[] = ['wp_id' => $wpId, 'email' => $email, 'err' => $e->getMessage()];
            }
        }
    }
    $pg->commit();
} catch (Throwable $e) {
    $pg->rollBack();
    fwrite(STDERR, "ABORTED: " . $e->getMessage() . "\n");
    exit(1);
}

echo "[4/4] done.\n\n";
echo "================ BACKFILL SUMMARY ================\n";
foreach ($stats as $k => $v) echo sprintf("  %-20s %d\n", $k, $v);
if ($failures) {
    echo "\n  sample failures:\n";
    foreach ($failures as $f) echo "    wp_id={$f['wp_id']}  email={$f['email']}  err={$f['err']}\n";
}
echo "==================================================\n";
