<?php
declare(strict_types=1);

/**
 * Slice-4 migration CRIB — one pass into the dev-FINAL spine. Orchestrates the
 * existing slice scripts (it does NOT re-implement them) so real member profiles
 * land in the block-model spine:
 *   1. migrate-from-xprofile.php    — display_name + business_name (xprofile 1/2)   [--commit]
 *   2. snapshot-location-from-bb.php — location_text + location_address + coords (96) [direct write, idempotent]
 *   3. migrate-socials.php           — field 266 + ACF author_* (locked mapping)      [--commit]
 *   4. backfill-avatars.php          — avatar_url (app-owned copy of BB upload; no-BB rows stay NULL/initials) [direct write, NULL-only]
 *
 * Precedence (locked): existing profile_socials > xprofile > ACF > skip. Three-tier
 * is per (user, kind); cross-kind additive. NOT swept: at_a_glance (no clean BB
 * source — user authors / LLM later), tier_badge (derived), brand_* (practice-side).
 *
 * Header default: members-only at cut is achieved by the HEADER_DEFAULT fallback
 * (Block::headerCeiling → 'members' when no header section row exists) — so the crib
 * does NOT seed 1,812 redundant header rows; a profile is members-only out of the box.
 *
 * Usage:
 *   sudo -u profile-app php bin/migrate-crib-slice4.php            # DRY-RUN (default)
 *   sudo -u profile-app php bin/migrate-crib-slice4.php --commit   # run for real
 *
 * Dry-run: runs the two dry-run-capable sub-scripts in preview mode + read-only
 * candidate counts for the two direct-write ones; diffs vs the slice-3.5 rehearsal
 * (1812 users · 165 xprofile + 45 ACF social inserts · 2 kept_existing). No writes.
 *
 * Idempotent: re-running --commit is safe — every sub-script guards its own writes
 * (xprofile UPDATE-from-source; snapshot skips when lat+text+address already match BB;
 * socials precedence keeps existing → kept_existing; avatars only touches NULL rows).
 */

require __DIR__ . '/../config.php';

use Looth\ProfileApp\Db;

if (!function_exists('exec')) {
    fwrite(STDERR, "ABORT — exec() is disabled; the crib can't orchestrate the sub-scripts.\n");
    exit(5);
}

$COMMIT = in_array('--commit', $argv, true);
$BIN    = __DIR__;
$PHP    = PHP_BINARY ?: 'php';

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }
function hr(string $t): void  { fwrite(STDOUT, "\n══════ $t ══════\n"); }

$pg = Db::pg();

/* ---- 0. spine schema sanity (abort if the dev-final spine isn't applied) ---- */
hr($COMMIT ? 'SLICE-4 CRIB — COMMIT' : 'SLICE-4 CRIB — DRY-RUN (no writes)');
$need = [
    ['users', 'at_a_glance'], ['users', 'location_address'],
    ['users', 'location_exact_visibility'], ['users', 'location_pin_precision'],
    ['practices', 'type'],
];
$colChk = $pg->prepare("SELECT 1 FROM information_schema.columns WHERE table_name = :t AND column_name = :c");
$missing = [];
foreach ($need as [$t, $c]) {
    $colChk->execute([':t' => $t, ':c' => $c]);
    if (!$colChk->fetchColumn()) $missing[] = "$t.$c";
}
if ($missing) {
    fwrite(STDERR, "ABORT — spine columns missing: " . implode(', ', $missing) . "\n"
        . "  apply sql/2026-05-30-block-system-spine.sql + sql/2026-05-30-location-pin-precision.sql first.\n");
    exit(3);
}
out("spine schema: OK (" . count($need) . " columns present). (avatar_version intentionally NOT required — deferred to the avatar single-source increment.)");

/* ---- bridge + population sanity ---- */
$bridge = (int) $pg->query("SELECT count(*) FROM wp_user_bridge")->fetchColumn();
$usersN = (int) $pg->query("SELECT count(*) FROM users")->fetchColumn();
out("bridge: $bridge wp↔profile rows · users: $usersN  (slice-3.5 baseline: 1812)");
if ($bridge < 1000) {
    out("  ⚠ bridge looks low — run `bin/reconcile-bridge.php --commit` BEFORE the crib so the sub-scripts can resolve users.");
}

/* ---- MySQL (read-only previews + fixture spot-check) ---- */
$mysqlUser = posix_getpwuid(posix_geteuid())['name'] ?? 'profile-app';
try {
    $wp = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=' . LG_PROFILE_APP_MYSQL_DB,
        $mysqlUser, '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    fwrite(STDERR, "ABORT — cannot reach WP MySQL (" . LG_PROFILE_APP_MYSQL_DB . "): " . $e->getMessage() . "\n");
    exit(6);
}

/* ---- fixture spot-check: must SKIP/MERGE, never clobber (user id 3 / wp 1918) ---- */
hr('fixture spot-check — user id 3 (must not be clobbered)');
$fx = $pg->prepare("SELECT u.display_name, u.slug, u.avatar_url, u.location_text, b.wp_user_id,
        (SELECT count(*) FROM profile_socials s WHERE s.user_id = u.id) AS socials,
        (SELECT visibility FROM profile_sections ps WHERE ps.user_id = u.id AND ps.key='header') AS header_vis
    FROM users u LEFT JOIN wp_user_bridge b ON b.user_id = u.id WHERE u.id = 3");
$fx->execute();
$f = $fx->fetch();
if (!$f) {
    out("  (no user id 3 on this DB — skipping fixture check.)");
} else {
    $fxWp = (int) $f['wp_user_id'];
    out(sprintf("  spine now: name=%s slug=%s avatar=%s loc=%s socials=%d header_vis=%s  (wp_user_id=%d)",
        var_export($f['display_name'], true), var_export($f['slug'], true),
        $f['avatar_url'] ? 'set' : 'NULL', var_export($f['location_text'], true),
        (int)$f['socials'], var_export($f['header_vis'] ?? '(default member)', true), $fxWp));
    if ($fxWp) {
        $has = function (string $sql, array $p) use ($wp): bool {
            $s = $wp->prepare($sql); $s->execute($p); return (bool) $s->fetchColumn();
        };
        $xName = $has("SELECT 1 FROM wp_bp_xprofile_data WHERE user_id=? AND field_id IN (1,2) AND value<>'' LIMIT 1", [$fxWp]);
        $xLoc  = $has("SELECT 1 FROM wp_bp_xprofile_data WHERE user_id=? AND field_id=96 AND value<>'' LIMIT 1", [$fxWp]);
        $xSoc  = $has("SELECT 1 FROM wp_bp_xprofile_data WHERE user_id=? AND field_id=266 AND value<>'' LIMIT 1", [$fxWp]);
        $aSoc  = $has("SELECT 1 FROM wp_usermeta WHERE user_id=? AND meta_key LIKE 'author_%' AND meta_value<>'' LIMIT 1", [$fxWp]);
        out(sprintf("  BB source for wp=%d: xprofile name=%s loc96=%s soc266=%s · ACF author_*=%s",
            $fxWp, $xName?'Y':'-', $xLoc?'Y':'-', $xSoc?'Y':'-', $aSoc?'Y':'-'));
        // Per-sub-script clobber semantics:
        //   name/business/slug → ONLY-IF-EMPTY (merge, never clobbers existing) — safe.
        //   location           → FULL SNAPSHOT (overwrites from field 96) — the one to watch.
        //   socials            → precedence keeps existing rows — safe.
        //   avatar             → NULL-only — safe.
        $nameEmpty = trim((string)$f['display_name']) === '';
        $touch = [];
        if ($xName && $nameEmpty) $touch[] = 'name/business (current EMPTY → xprofile fills)';
        if ($xLoc)  $touch[] = '⚠ LOCATION (field 96 OVERWRITES location_text/address — full snapshot, not only-if-empty)';
        if (!$f['avatar_url']) $touch[] = 'avatar (NULL → app-owned copy of BB upload, if one exists; else stays NULL/initials)';
        out("  semantics: name/business/slug only-if-empty (merge); socials precedence-protected; avatar NULL-only; LOCATION overwrites.");
        out($touch
            ? "  → crib WOULD touch: " . implode('; ', $touch) . ".\n    (To preserve the fixture's location exactly, re-seed user 3 after --commit, or skip if wp=$fxWp has no field 96.)"
            : "  → no clobbering BB source for wp=$fxWp → fixture spine data stays INTACT (name non-empty, socials kept, only a NULL avatar fills).");
    }
}

/* ---- the orchestration ---- */
$steps = [
    ['label' => '1. name/business', 'script' => 'migrate-from-xprofile.php',    'dryrun' => true],
    ['label' => '2. location',      'script' => 'snapshot-location-from-bb.php', 'dryrun' => false],
    ['label' => '3. socials',       'script' => 'migrate-socials.php',           'dryrun' => true],
    ['label' => '4. avatars',       'script' => 'backfill-avatars.php',          'dryrun' => false],
];

hr($COMMIT ? 'running sub-scripts in order' : 'previewing sub-scripts');
foreach ($steps as $s) {
    $path = $BIN . '/' . $s['script'];
    if (!is_file($path)) { fwrite(STDERR, "ABORT — missing sub-script: $path\n"); exit(7); }

    // Dry-run mode + a sub-script with no native dry-run → read-only preview count, don't run.
    if (!$COMMIT && !$s['dryrun']) {
        out("\n→ {$s['label']} ({$s['script']}): no dry-run mode (idempotent direct-write) — preview only:");
        if ($s['script'] === 'snapshot-location-from-bb.php') {
            $n = (int) $wp->query("SELECT count(DISTINCT u.id) FROM wp_users u
                LEFT JOIN wp_bp_xprofile_data xp ON xp.user_id=u.id AND xp.field_id=96
                LEFT JOIN wp_usermeta um ON um.user_id=u.id AND um.meta_key='geocode_96'
                WHERE (xp.value IS NOT NULL AND xp.value<>'') OR (um.meta_value IS NOT NULL AND um.meta_value<>'')")->fetchColumn();
            out("   candidates with a BB location (field 96 / geocode_96): $n  (idempotent: skips rows already matching)");
        } elseif ($s['script'] === 'backfill-avatars.php') {
            $n = (int) $pg->query("SELECT count(*) FROM users WHERE avatar_url IS NULL")->fetchColumn();
            out("   candidates with NULL avatar_url: $n  (fills from BB upload only — no-BB rows stay NULL/initials; re-run is a no-op)");
        }
        continue;
    }

    // Run: dry-run-capable get --commit only in commit mode; direct-write ones run bare.
    $flag = ($COMMIT && $s['dryrun']) ? ' --commit' : '';
    $cmd  = escapeshellarg($PHP) . ' ' . escapeshellarg($path) . $flag;
    out("\n→ {$s['label']}: $cmd");
    $o = []; $rc = 0;
    exec($cmd . ' 2>&1', $o, $rc);
    foreach ($o as $line) out('   ' . $line);
    if ($rc !== 0) {
        fwrite(STDERR, "\nABORT — {$s['label']} exited $rc; chain stopped (nothing after it ran).\n");
        exit(4);
    }
}

/* ---- diff vs the slice-3.5 rehearsal ---- */
hr('rehearsal baseline (slice-3.5) to diff against');
out("  users walked 1812 · xprofile social inserts 165 · ACF social inserts 45 · kept_existing 2");
out("  (compare the per-step counts above; large drift ⇒ investigate before/after --commit.)");

hr($COMMIT ? 'COMMIT complete' : 'DRY-RUN complete');
out($COMMIT
    ? "Spine seeded. Re-running --commit is idempotent (each sub-script guards its own writes)."
    : "No writes made. Re-run with --commit to apply.");
exit(0);
