<?php
require __DIR__.'/../config.php';
/**
 * archive-poc/bin/verify-thumbs.php
 *
 * HEAD-checks every non-null thumb_url in batches via curl_multi.
 * Marks thumb_broken=1 for 4xx/5xx and clears thumb_url so the frontend
 * uses the kind placeholder. Read-only on WP; writes only to index.sqlite.
 *
 * Usage:
 *   php bin/verify-thumbs.php
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$SQLITE = __DIR__ . '/../index.sqlite';
$BATCH  = 20;
$TIMEOUT = 2;          // per request, seconds
$RESET  = in_array('--reset', $argv, true);

$db = new PDO('sqlite:' . $SQLITE);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ($RESET) {
    $db->exec("UPDATE content_item SET thumb_broken = 0 WHERE thumb_broken = 1");
}

$rows = $db->query("
    SELECT id, thumb_url FROM content_item
    WHERE thumb_url IS NOT NULL AND thumb_url != ''
      AND thumb_broken = 0
    ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);

$total = count($rows);
echo "checking $total thumbs (batch=$BATCH, timeout={$TIMEOUT}s)\n";

$upd_broken = $db->prepare("UPDATE content_item SET thumb_broken = 1, thumb_url = NULL WHERE id = ?");

// Local-host fast path: thumbs hosted on dev.loothgroup.com map to
// /var/www/dev/<path>. Verify by filesystem stat — orders of magnitude
// faster than HTTP and bypasses the cookie gate.
$LOCAL_HOSTS = [
    // (auto-derived from config)
];

$external = [];
$ok = 0; $bad = 0; $checked = 0;

foreach ($rows as $r) {
    $url = $r['thumb_url'];
    $local = null;
    foreach ($LOCAL_HOSTS as $prefix => $root) {
        if (str_starts_with($url, $prefix)) {
            $local = $root . substr($url, strlen($prefix));
            break;
        }
    }
    if ($local !== null) {
        // Strip query string if any (uploads typically don't have one).
        $local = explode('?', $local, 2)[0];
        if (is_file($local) && filesize($local) > 0) {
            $ok++;
        } else {
            $upd_broken->execute([(int)$r['id']]);
            $bad++;
        }
        $checked++;
    } else {
        $external[] = $r;
    }
}
echo "  local: ok=$ok bad=$bad (of $checked checked via filesystem)\n";
echo "  external: " . count($external) . " — HEAD-checking\n";

for ($i = 0; $i < count($external); $i += $BATCH) {
    $chunk = array_slice($external, $i, $BATCH);
    $mh = curl_multi_init();
    $handles = [];
    foreach ($chunk as $r) {
        $ch = curl_init($r['thumb_url']);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => $TIMEOUT,
            CURLOPT_USERAGENT      => 'archive-poc-thumbcheck/0.1',
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[(int)$r['id']] = $ch;
    }
    do { $status = curl_multi_exec($mh, $active); if ($active) curl_multi_select($mh, $TIMEOUT); }
    while ($active && $status === CURLM_OK);

    foreach ($handles as $pid => $ch) {
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_errno($ch);
        if ($err || $code === 0 || $code >= 400) { $upd_broken->execute([$pid]); $bad++; }
        else { $ok++; }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
}
$checked = $total;

echo "\n=== DONE ===\n";
printf("checked: %d  ok: %d  broken: %d (%.1f%%)\n", $total, $ok, $bad, $total ? 100.0*$bad/$total : 0);

$summary = $db->query("
    SELECT kind, COUNT(*) total,
           SUM(thumb_url IS NOT NULL) with_thumb,
           SUM(thumb_broken)         broken
    FROM content_item GROUP BY kind ORDER BY kind
")->fetchAll(PDO::FETCH_ASSOC);
echo "\nper-kind:\n";
foreach ($summary as $r) {
    printf("  %-12s total=%d  with_thumb=%d  broken=%d\n", $r['kind'], $r['total'], $r['with_thumb'], $r['broken']);
}
