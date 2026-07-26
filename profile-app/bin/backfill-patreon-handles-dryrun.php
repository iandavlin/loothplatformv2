<?php
declare(strict_types=1);

/**
 * DRY-RUN generator: the COMBINED IDENTITY-CLEANUP pass (name + business + handle).
 *
 * Keeper spec 2026-07-26 02:22 + 02:25 (extends + supersedes the handle-only dry-run;
 * that pass's apply was HELD for this one). ONE report covering, per active member:
 *
 *   1. NAME    — display_name capped 120→40 going forward; this pass proposes the
 *                word-boundary-trimmed name for members already over the cap, and a
 *                REAL name (Patreon API full_name) for members still displaying
 *                patreon_* junk as their name.
 *   2. BUSINESS— names carrying a business tail are SPLIT: person name + tail. The
 *                Patreon API full_name is the person ANCHOR (display_name starting
 *                with the anchor ⇒ the remainder is the tail). The tail RELOCATES to
 *                the existing users.business_name column (kept at 120) — relocation,
 *                not deletion; it pre-seeds the business-profile feature.
 *   3. HANDLE  — re-derived from the PRUNED name via the standard clean-name rule
 *                (identical to the live rename rule, Provision::maybeSyncSlugFromName),
 *                30-capped at a word boundary. Existing >30 strays (the bulk-mint path
 *                never enforced Slug::MAX_LEN) are re-trimmed even when the name is
 *                untouched. patreon_* junk slugs still re-mint via the 7/25 chain:
 *                profile-name → API full_name → API vanity → API email local-part →
 *                offline email local-part → 'member'. Collisions take the @steve2
 *                suffix scheme against live slugs ∪ slug_history ∪ this batch.
 *
 * Rows where the split could not be anchored (no API identity, heuristic separator
 * split, anchor mismatch, business_name already occupied) are flagged LOW-CONFIDENCE
 * for Ian's eyes. Members with nothing to change and nothing to flag are omitted.
 *
 *   sudo -u profile-app php backfill-patreon-handles-dryrun.php [--db-only] [--tsv=/path]
 *
 * --db-only skips the API (no anchors: splits are heuristic-only and flagged) — for
 * boxes without creds. This script writes NOTHING, ever. Apply is a separate,
 * Ian-gated step.
 */

require dirname(__DIR__) . '/config.php';

use Looth\ProfileApp\Db;
use Looth\ProfileApp\Slug;

$DB_ONLY = in_array('--db-only', $argv, true);
$TSV     = null;
foreach ($argv as $a) if (str_starts_with($a, '--tsv=')) $TSV = substr($a, 6);

const NAME_MAX = 40;    // 02:25 spec: names 120 -> 40 (forward cap lands in me-name.php)
const BIZ_MAX  = 120;   // business_name keeps 120

$clean = function (string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-');
};
$emailLocal = fn(string $e): string => preg_replace('/\+.*$/', '', explode('@', $e)[0] ?? '');
$isJunk     = fn(string $s): bool => (bool) preg_match('/^patreon[_-]?\d+$/i', trim($s));
$squash     = fn(string $s): string => preg_replace('/\s+/u', ' ', trim(mb_strtolower($s))) ?? '';

/** Word-boundary trim of a display NAME to the cap (cut at the last space inside it,
    unless the cap already lands at a word end). */
$nameFit = function (string $s, int $max = NAME_MAX): string {
    $s = trim($s);
    if (mb_strlen($s) <= $max) return $s;
    $cut = mb_substr($s, 0, $max);
    if (!preg_match('/\s/u', mb_substr($s, $max, 1))) {   // cut lands mid-word → back up
        $sp = mb_strrpos($cut, ' ');
        if ($sp !== false && $sp >= 2) $cut = mb_substr($cut, 0, $sp);
    }
    return rtrim($cut, " \t|,/·:;–—-");
};

/** Word-boundary trim of a HANDLE to the cap (cut at the last dash inside it).
    Mirrors Provision::slugFit — the forward mint-path enforcement. */
$slugFit = function (string $s, int $max = Slug::MAX_LEN): string {
    if (strlen($s) <= $max) return $s;
    $cut = substr($s, 0, $max);
    if (($s[$max] ?? '') !== '-') {                       // cut lands mid-word → back up
        $dash = strrpos($cut, '-');
        if ($dash !== false && $dash >= Slug::MIN_LEN) $cut = substr($cut, 0, $dash);
    }
    return rtrim($cut, '-');
};

$pg = Db::pg();

// ── 1. candidates: EVERY active member (the pass decides who gets a row) ─────
$cand = $pg->query("
    SELECT u.id, u.uuid, u.display_name, u.slug, u.primary_email, u.business_name,
           b.wp_user_id
    FROM users u LEFT JOIN wp_user_bridge b ON b.user_id = u.id
    WHERE u.archived_at IS NULL
    ORDER BY u.id
")->fetchAll(PDO::FETCH_ASSOC);

fwrite(STDERR, 'active members: ' . count($cand) . "\n");
if (!$cand) { echo "No active members. Nothing to report.\n"; exit(0); }

// ── 2. fresh identity from the Patreon API (campaign members sweep) ─────────
// Keyed two ways: by patreon user id (junk slugs carry the pid — strongest key)
// and by lower(email) (the only linkage for members with clean slugs).
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
        $apiStatus = 'UNAVAILABLE (' . $e->getMessage() . ') — anchorless: splits heuristic-only, all flagged';
        $api = [];
    }
}
$apiByEmail = [];
foreach ($api as $ident) {
    $e = strtolower(trim($ident['email'] ?? ''));
    if ($e !== '' && !isset($apiByEmail[$e])) $apiByEmail[$e] = $ident;
}
fwrite(STDERR, "api: $apiStatus\n");

// ── 3. uniqueness domain (owner-aware: your own slug never blocks you) ──────
$owners = [];   // lower(slug) => [user_id, ...]  (live + history + batch claims)
foreach ($pg->query('SELECT id, lower(slug) s FROM users WHERE slug IS NOT NULL') as $r) {
    $owners[$r['s']][] = (int) $r['id'];
}
foreach ($pg->query('SELECT user_id, lower(slug) s FROM slug_history') as $r) {
    $owners[$r['s']][] = (int) $r['user_id'];
}
$takenByOther = function (string $slug, int $self) use (&$owners): bool {
    foreach ($owners[strtolower($slug)] ?? [] as $o) if ($o !== $self) return true;
    return false;
};
$suffix = function (string $base, int $self) use (&$owners, $takenByOther, $slugFit): string {
    $c = $slugFit($base);
    for ($i = 2; $i <= 999; $i++) {
        if (!$takenByOther($c, $self)) break;
        // keep base + suffix inside the 30-cap together (mirrors the mint-path fix)
        $c = $slugFit($base, Slug::MAX_LEN - strlen((string) $i)) . $i;   // @steve2 scheme
    }
    $owners[strtolower($c)][] = $self;         // claim for batch-internal uniqueness
    return $c;
};

/** Split display_name into [person, business tail, mode] using the API full_name
    anchor. Returns null when no split applies. */
$split = function (string $name, string $anchor) use ($squash): ?array {
    $n = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    $a = trim(preg_replace('/\s+/u', ' ', $anchor) ?? '');
    if ($n === '') return null;
    if ($a !== '' && mb_strlen($a) >= 3) {
        if ($squash($n) === $squash($a)) return null;              // name IS the person — no tail
        // anchor as a word-prefix of the name, tolerant of a separator after it
        $words = preg_split('/\s+/u', $a) ?: [];
        $pat = '/^\s*' . implode('\s+', array_map(fn($w) => preg_quote($w, '/'), $words))
             . '[\s\-–—|,\/:·]+(\S.*)$/iu';
        if (preg_match($pat, $n, $m)) {
            $tail   = trim($m[1]);
            $person = trim(mb_substr($n, 0, mb_strlen($n) - mb_strlen($m[1])), " \t|,/·:;–—-");
            if ($tail !== '') return [$person, $tail, 'anchored'];
        }
        return [$n, '', 'anchor-mismatch'];                        // anchor exists but doesn't lead the name
    }
    // no anchor: explicit-separator heuristic only (|, –, —, ·, spaced - or /)
    if (preg_match('/^(.{2,80}?)\s*(?:[|–—·]|\s[-\/]\s)\s*(\S.*)$/u', $n, $m)) {
        return [trim($m[1]), trim($m[2]), 'separator'];
    }
    return null;
};

// ── 4. derive + report ───────────────────────────────────────────────────────
$rows = []; $byNameSource = []; $byHandleSource = []; $flagCount = [];
foreach ($cand as $c) {
    $uid      = (int) $c['id'];
    $nameNow  = trim((string) $c['display_name']);
    $slugNow  = (string) $c['slug'];
    $bizNow   = trim((string) ($c['business_name'] ?? ''));
    $slugJunk = $isJunk($slugNow);
    $nameJunk = $nameNow === '' || $isJunk($nameNow) || preg_match('/^\d+$/', $nameNow);

    // identity: pid (junk slugs carry it) beats email linkage
    preg_match('/(\d+)/', $slugNow, $m);
    $pid   = $slugJunk ? ($m[1] ?? '') : '';
    $ident = $api[$pid] ?? $apiByEmail[strtolower(trim((string) $c['primary_email']))] ?? null;
    $inApi = isset($api[$pid]) ? 'yes-pid' : ($ident ? 'yes-email' : 'no');
    $anchor = trim((string) ($ident['full_name'] ?? ''));

    $flags = [];

    // ── NAME: junk fix, business prune, 40-cap ──────────────────────────────
    $nameSource = 'unchanged';
    $person = $nameNow; $biz = '';
    if ($nameJunk) {
        $flags[] = 'junk-name';
        if ($anchor !== '') { $person = $anchor; $nameSource = 'api-full-name'; }
        else                { $flags[] = 'junk-name-unresolvable'; }   // name stays; Ian eyes
    } else {
        $sp = $split($nameNow, $anchor);
        if ($sp !== null) {
            [$p, $t, $mode] = $sp;
            if ($mode === 'anchored' && $t !== '') {
                $person = $p; $biz = $t; $nameSource = 'business-prune';
            } elseif ($mode === 'separator') {
                $person = $p; $biz = $t; $nameSource = 'business-prune';
                $flags[] = 'split-heuristic';                          // no anchor backing it
            } elseif ($mode === 'anchor-mismatch') {
                $flags[] = 'anchor-mismatch';                          // API name ≠ profile name; no split
            }
        } elseif ($anchor === '' && $inApi === 'no' && mb_strlen($nameNow) > NAME_MAX) {
            $flags[] = 'no-api';                                       // long name, nothing to anchor a split on
        }
    }
    $nameProposed = $nameFit($person);
    if ($nameProposed !== $person) { $flags[] = 'name-capped'; if ($nameSource === 'unchanged') $nameSource = 'cap-40'; }
    if ($nameProposed === '') { $nameProposed = $nameNow; $nameSource = 'unchanged'; }   // never propose an empty name

    // captured business → users.business_name (relocation; never clobber silently)
    $bizCaptured = mb_substr($biz, 0, BIZ_MAX);
    if ($bizCaptured !== '' && $bizNow !== '' && $squash($bizNow) !== $squash($bizCaptured)) {
        $flags[] = 'biz-col-occupied';                                 // existing value differs — Ian decides
    }

    // ── HANDLE: from the PRUNED name (the live rename rule), else 7/25 chain ─
    $dnClean = $isJunk($nameProposed) || preg_match('/^\d+$/', $clean($nameProposed)) ? '' : $clean($nameProposed);
    $chain = [
        'profile-name'  => $dnClean,
        'api-full-name' => $ident ? $clean($ident['full_name']) : '',
        'api-vanity'    => $ident ? $clean($ident['vanity']) : '',
        'api-email'     => $ident ? $clean($emailLocal($ident['email'])) : '',
        'offline-email' => $clean($emailLocal((string) $c['primary_email'])),
        'fallback'      => 'member',
    ];
    $handleSource = 'fallback'; $base = 'member';
    foreach ($chain as $src => $val) {
        if ($val !== '') { $handleSource = $src; $base = $val; break; }
    }

    $slugProposed = $slugNow;
    if ($slugJunk || $slugNow === '') {
        $slugProposed = $suffix($base, $uid);                          // junk/missing: full re-mint
    } elseif (strcasecmp($slugFit($base), $slugNow) === 0 || strcasecmp($base, $slugNow) === 0) {
        // already the clean form of the (pruned) name — keep, unless it's an >30 stray
        if (strlen($slugNow) > Slug::MAX_LEN) { $slugProposed = $suffix($base, $uid); $flags[] = 'handle-retrim'; }
    } elseif ($nameProposed !== $nameNow) {
        $slugProposed = $suffix($base, $uid);                          // name moved ⇒ handle follows (rename rule)
    } elseif (strlen($slugNow) > Slug::MAX_LEN) {
        $slugProposed = $suffix($slugFit($clean($slugNow)) !== '' ? $clean($slugNow) : $base, $uid);
        $flags[] = 'handle-retrim';                                    // stray >30: trim in place
    }
    if (strcasecmp($slugProposed, $slugNow) === 0) $slugProposed = $slugNow;   // case-stable no-op

    // ── row inclusion: something changes, or something needs Ian's eyes ─────
    $changed = $nameProposed !== $nameNow || $bizCaptured !== '' || $slugProposed !== $slugNow;
    $lowConf = (bool) array_intersect($flags,
        ['split-heuristic', 'anchor-mismatch', 'biz-col-occupied', 'junk-name-unresolvable', 'no-api']);
    if (!$changed && !$lowConf) continue;

    $byNameSource[$nameSource]     = ($byNameSource[$nameSource] ?? 0) + 1;
    $byHandleSource[$handleSource] = ($byHandleSource[$handleSource] ?? 0) + 1;
    foreach ($flags as $f) $flagCount[$f] = ($flagCount[$f] ?? 0) + 1;

    $rows[] = [
        'user_id'        => $uid,
        'wp_id'          => $c['wp_user_id'] ?? '',
        'in_api'         => $inApi,
        'name_now'       => $nameNow,
        'name_proposed'  => $nameProposed === $nameNow ? '' : $nameProposed,
        'biz_captured'   => $bizCaptured,
        'biz_existing'   => $bizNow,
        'slug_now'       => $slugNow,
        'slug_proposed'  => strcasecmp($slugProposed, $slugNow) === 0 ? '' : $slugProposed,
        'name_source'    => $nameSource,
        'handle_source'  => $slugProposed === $slugNow ? '' : $handleSource,
        'flags'          => implode(',', $flags),
        'low_confidence' => $lowConf ? 'YES' : '',
    ];
}

// ── 5. emit ──────────────────────────────────────────────────────────────────
echo "# IDENTITY-CLEANUP combined pass — DRY RUN (" . date('Y-m-d H:i') . ")\n";
echo "# active: " . count($cand) . " | rows: " . count($rows) . " | api: $apiStatus\n";
echo "# name sources: "   . json_encode($byNameSource) . "\n";
echo "# handle sources: " . json_encode($byHandleSource) . "\n";
echo "# flags: "          . json_encode($flagCount) . "\n";
echo "# low-confidence rows are for Ian's eyes BEFORE any apply.\n\n";
if (!$rows) { echo "Nothing to change, nothing flagged.\n# NO WRITES PERFORMED.\n"; exit(0); }
$cols = array_keys($rows[0]);
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
