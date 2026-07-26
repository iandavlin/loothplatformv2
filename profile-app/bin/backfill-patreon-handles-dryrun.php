<?php
declare(strict_types=1);

/**
 * DRY-RUN generator: patreon_* handle cleanup, sourced from the Patreon API.
 *
 * Ian ruling 2026-07-25 (board 22:33): junk handles are re-minted from identity pulled
 * FRESH via the creator token — per member: Patreon full name → Patreon vanity → email
 * local-part (+tag stripped) — then cleaned + @steve2-suffixed. This script produces the
 * member→proposed-handle REPORT for Ian's approval. It writes NOTHING, ever.
 *
 *   sudo -u profile-app php backfill-patreon-handles-dryrun.php [--db-only] [--tsv=/path]
 *
 * --db-only skips the API (offline fallback derivation only) — for boxes without creds.
 * Candidates = active profile_app users whose slug matches ^patreon[_-]?\d+$ (the junk
 * shape; the digits ARE the patreon user id). Uniqueness domain for suffixing = live
 * slugs ∪ slug_history ∪ handles already proposed in this batch (batch-internal
 * collisions matter when re-minting hundreds on live).
 *
 * Derivation preference (Ian 7/25 23:45 revision — profile-name PRIMARY):
 *   1. EXISTING profile display_name, when REAL (not patreon_* junk, not empty/numeric
 *      after clean) — identical to the live rename rule, business tails included
 *      (consistency with forward behavior beats brevity).
 *   2. API full_name        (fresh — fallback for junk/empty names only)
 *   3. API vanity           (fresh — NOT in the poller's sweep fields; requested here)
 *   4. API email local-part (+tag stripped)
 *   5. profile_app primary_email local-part (OFFLINE fallback, e.g. member left Patreon)
 *   6. 'member'
 */

require dirname(__DIR__) . '/config.php';

use Looth\ProfileApp\Db;

$DB_ONLY = in_array('--db-only', $argv, true);
$TSV     = null;
foreach ($argv as $a) if (str_starts_with($a, '--tsv=')) $TSV = substr($a, 6);

$clean = function (string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-');
};
$emailLocal = fn(string $e): string => preg_replace('/\+.*$/', '', explode('@', $e)[0] ?? '');

$pg = Db::pg();

// ── 1. candidates ────────────────────────────────────────────────────────────
$cand = $pg->query("
    SELECT u.id, u.uuid, u.display_name, u.slug, u.primary_email, b.wp_user_id
    FROM users u LEFT JOIN wp_user_bridge b ON b.user_id = u.id
    WHERE u.archived_at IS NULL AND u.slug ~* '^patreon[_-]?[0-9]+\$'
    ORDER BY u.id
")->fetchAll(PDO::FETCH_ASSOC);

fwrite(STDERR, 'candidates: ' . count($cand) . "\n");
if (!$cand) { echo "No patreon_* handles to re-mint. Nothing to report.\n"; exit(0); }

// ── 2. fresh identity from the Patreon API (campaign members sweep) ─────────
$api = [];          // patreon_user_id => ['full_name'=>, 'vanity'=>, 'email'=>]
$apiStatus = 'skipped (--db-only)';
if (!$DB_ONLY) {
    try {
        // creds live in wp_options; profile-app@localhost has SELECT on looth_import.
        $my = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=' . LG_PROFILE_APP_MYSQL_DB,
            posix_getpwuid(posix_geteuid())['name'] ?? 'profile-app', '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $opt = fn(string $k) => (string) ($my->query(
            "SELECT option_value FROM wp_options WHERE option_name = " . $my->quote($k))->fetchColumn() ?: '');
        $token    = $opt('lgpo_creator_access_token');
        $campaign = $opt('lgpo_campaign_id');
        if ($token === '' || $campaign === '') throw new RuntimeException('missing creator token / campaign id');

        $cursor = null; $pages = 0;
        do {
            $params = [
                'include'        => 'user',
                'fields[member]' => 'email,full_name,patron_status',
                'fields[user]'   => 'email,full_name,vanity',   // vanity: this script's addition
                'page[count]'    => 200,
            ];
            if ($cursor !== null) $params['page[cursor]'] = $cursor;
            $url = 'https://www.patreon.com/api/oauth2/v2/campaigns/' . rawurlencode($campaign)
                 . '/members?' . http_build_query($params);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($code !== 200) throw new RuntimeException("members sweep http=$code page=$pages");
            $j = json_decode((string) $body, true);

            foreach (($j['included'] ?? []) as $inc) {
                if (($inc['type'] ?? '') !== 'user') continue;
                $api[(string) $inc['id']] = [
                    'full_name' => (string) ($inc['attributes']['full_name'] ?? ''),
                    'vanity'    => (string) ($inc['attributes']['vanity'] ?? ''),
                    'email'     => (string) ($inc['attributes']['email'] ?? ''),
                ];
            }
            // member-level email/full_name fallback keyed by the related user id
            foreach (($j['data'] ?? []) as $m) {
                $uid = (string) ($m['relationships']['user']['data']['id'] ?? '');
                if ($uid === '') continue;
                $api[$uid]['full_name'] = $api[$uid]['full_name'] ?? '';
                if ($api[$uid]['full_name'] === '') $api[$uid]['full_name'] = (string) ($m['attributes']['full_name'] ?? '');
                if (($api[$uid]['email'] ?? '') === '') $api[$uid]['email'] = (string) ($m['attributes']['email'] ?? '');
            }
            $cursor = $j['meta']['pagination']['cursors']['next'] ?? null;
            $pages++;
        } while ($cursor !== null && $pages < 100);
        $apiStatus = 'OK — ' . count($api) . " users across $pages pages";
    } catch (Throwable $e) {
        $apiStatus = 'UNAVAILABLE (' . $e->getMessage() . ') — offline fallback for all rows';
        $api = [];
    }
}
fwrite(STDERR, "api: $apiStatus\n");

// ── 3. uniqueness domain ─────────────────────────────────────────────────────
$takenLive = array_column($pg->query('SELECT lower(slug) s FROM users WHERE slug IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC), 's');
$takenHist = array_column($pg->query('SELECT lower(slug) s FROM slug_history')->fetchAll(PDO::FETCH_ASSOC), 's');
$taken     = array_fill_keys(array_merge($takenLive, $takenHist), true);

$suffix = function (string $base) use (&$taken): string {
    $c = $base;
    for ($i = 2; $i <= 999; $i++) {
        if (!isset($taken[strtolower($c)])) break;
        $c = $base . $i;                       // @steve2 scheme (Ian-confirmed, no dash)
    }
    $taken[strtolower($c)] = true;             // claim for batch-internal uniqueness
    return $c;
};

// ── 4. derive + report ───────────────────────────────────────────────────────
$rows = []; $bySource = [];
foreach ($cand as $c) {
    // release the member's own junk slug from the domain — it dies in the re-mint
    unset($taken[strtolower((string) $c['slug'])]);

    preg_match('/(\d+)/', (string) $c['slug'], $m);
    $pid  = $m[1] ?? '';
    $id   = $api[$pid] ?? null;

    // profile-name PRIMARY (Ian 7/25 23:45): a real display name wins outright —
    // same derivation the live rename rule applies, business tails and all.
    $dn        = (string) $c['display_name'];
    $dnIsJunk  = (bool) preg_match('/^patreon[_-]?\d+$/i', trim($dn));
    $dnClean   = $dnIsJunk ? '' : $clean($dn);
    if (preg_match('/^\d+$/', $dnClean)) $dnClean = '';   // numeric-only never a handle
    $chain = [
        'profile-name'  => $dnClean,
        'api-full-name' => $id ? $clean($id['full_name']) : '',
        'api-vanity'    => $id ? $clean($id['vanity']) : '',
        'api-email'     => $id ? $clean($emailLocal($id['email'])) : '',
        'offline-email' => $clean($emailLocal((string) $c['primary_email'])),
        'fallback'      => 'member',
    ];
    $source = 'fallback'; $base = 'member';
    foreach ($chain as $src => $val) {
        if ($val !== '') { $source = $src; $base = $val; break; }
    }
    $proposed = $suffix($base);
    $bySource[$source] = ($bySource[$source] ?? 0) + 1;

    $rows[] = [
        'user_id'   => $c['id'],
        'wp_id'     => $c['wp_user_id'] ?? '',
        'patreon'   => $pid,
        'name_now'  => $c['display_name'],
        'junk_slug' => $c['slug'],
        'in_api'    => $id ? 'yes' : 'NO (left campaign?)',
        'source'    => $source,
        'proposed'  => $proposed,
        'suffixed'  => ($proposed !== $base) ? 'yes' : '',
    ];
}

// ── 5. emit ──────────────────────────────────────────────────────────────────
$cols = array_keys($rows[0]);
echo "# patreon_* handle re-mint — DRY RUN (" . date('Y-m-d H:i') . ")\n";
echo "# candidates: " . count($rows) . " | api: $apiStatus\n";
echo "# sources: " . json_encode($bySource) . "\n\n";
echo implode("\t", $cols) . "\n";
$tsv = null;
if ($TSV) {
    $tsv = @fopen($TSV, 'w');
    if ($tsv === false) { fwrite(STDERR, "tsv: cannot write $TSV — stdout only\n"); $TSV = null; }
    else fputcsv($tsv, $cols, "\t", '"', '\\');
}
foreach ($rows as $r) {
    if ($TSV) fputcsv($tsv, array_values($r), "\t", '"', '\\');
    else echo implode("\t", array_map(fn($v) => (string) $v, $r)) . "\n";
}
if ($TSV) { fclose($tsv); fwrite(STDERR, "tsv: $TSV\n"); }
echo "\n# NO WRITES PERFORMED. Apply is a separate, Ian-gated step.\n";
