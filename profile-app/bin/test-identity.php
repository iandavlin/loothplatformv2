<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

use Looth\ProfileApp\Identity;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Mint;

$cases = [
    ['ian@example.com',         'ian@example.com'],
    ['  IAN@Example.COM  ',     'ian@example.com'],
    ["Ian@Example.com\n",       'ian@example.com'],
];

$ok = true;
$canonical = Identity::computeUuid('ian@example.com');
foreach ($cases as [$in, $expEqualTo]) {
    $got = Identity::computeUuid($in);
    $exp = Identity::computeUuid($expEqualTo);
    $pass = $got === $exp;
    echo sprintf("[%s] %-30s → %s\n", $pass ? 'OK' : 'FAIL', json_encode($in), $got);
    if (!$pass) $ok = false;
}

// Different emails must produce different uuids.
$alt = Identity::computeUuid('someoneelse@example.com');
if ($alt === $canonical) {
    echo "[FAIL] distinct emails collided\n"; $ok = false;
} else {
    echo "[OK]   distinct emails → distinct uuids ($alt)\n";
}

// Namespace stability: assert against a precomputed expected value so a
// future namespace rotation gets caught loudly.
$expectedNs = 'eaef23f7-9bc9-4a95-ac49-ffff632e6646';
if (LOOTH_IDENTITY_NAMESPACE !== $expectedNs) {
    echo "[FAIL] namespace changed — every existing uuid would be orphaned\n";
    $ok = false;
}

// ── sub-stability: a WP email change must NOT drift the token subject ──────
// The token `sub` is the STORED users.uuid (reached via wp_user_bridge), seeded
// from the email ONCE at create and never recomputed. This is the G4 silent-
// logout invariant and the contract the option (b) WP minter must honor.
// Runs against postgres, so it must run as the `profile-app` role
// (`sudo -u profile-app php bin/test-identity.php`); the whole fixture lives in
// a rolled-back transaction so it leaves no residue. If pg is unreachable
// (e.g. invoked as the wrong user) the block SKIPs loudly rather than passing.
try {
    $pg = Db::pg();
} catch (Throwable $e) {
    fwrite(STDERR, "[SKIP] sub-stability: postgres unavailable — run as the profile-app role ({$e->getMessage()})\n");
    $pg = null;
}

if ($pg !== null) {
    $wp = 2000000000 + (function_exists('posix_getpid') ? (posix_getpid() % 1000000) : 424242);
    $e1 = "drift-seed+{$wp}@example.com";        // identity-seeding email
    $e2 = "changed-addr+{$wp}@example.com";       // the member later changes to this
    $seedUuid = Identity::computeUuid($e1);

    $pg->beginTransaction();
    try {
        $pg->prepare('INSERT INTO users (uuid, primary_email, billing_email, contact_email, display_name)
                      VALUES (:u, :e, :e, :e, :n) ON CONFLICT (uuid) DO NOTHING')
           ->execute([':u' => $seedUuid, ':e' => Identity::normalizeEmail($e1), ':n' => 'Drift Test']);
        $uid = (int) $pg->query('SELECT id FROM users WHERE uuid = ' . $pg->quote($seedUuid))->fetchColumn();
        $pg->prepare('INSERT INTO wp_user_bridge (wp_user_id, user_id) VALUES (:w, :i)
                      ON CONFLICT (wp_user_id) DO UPDATE SET user_id = EXCLUDED.user_id')
           ->execute([':w' => $wp, ':i' => $uid]);

        // 1. sub resolves to the stored uuid via the bridge.
        $sub1 = Mint::subForWpUserId($wp);
        $p1 = ($sub1 === $seedUuid);
        echo sprintf("[%s] sub resolves to stored uuid via bridge (%s)\n", $p1 ? 'OK' : 'FAIL', $sub1 ?? 'null');
        if (!$p1) $ok = false;

        // 2. member changes their WP email → stored uuid (and thus sub) unchanged.
        $pg->prepare('UPDATE users SET primary_email = :e WHERE id = :i')
           ->execute([':e' => Identity::normalizeEmail($e2), ':i' => $uid]);
        $sub2 = Mint::subForWpUserId($wp);
        $p2 = ($sub2 !== null && $sub2 === $sub1);
        echo sprintf("[%s] email change does NOT drift sub (%s)\n", $p2 ? 'OK' : 'FAIL', $sub2 ?? 'null');
        if (!$p2) $ok = false;

        // 3. …and sub is the bridge-stored value, NOT UUIDv5(new email) — the
        //    exact recompute the WP minter used to do (the bug being fixed).
        $p3 = ($sub2 !== Identity::computeUuid($e2));
        echo sprintf("[%s] sub is bridge-stored, not UUIDv5(new email)\n", $p3 ? 'OK' : 'FAIL');
        if (!$p3) $ok = false;
    } finally {
        $pg->rollBack();   // synthetic fixture — never persists
    }
}

exit($ok ? 0 : 1);
