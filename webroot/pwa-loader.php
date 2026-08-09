<?php
/**
 * Serves /pwa.js (nginx `location = /pwa.js` fastcgi-passes here — the URL the
 * sub_filter injects never changes again).
 *
 * Prepends `window.LG_V = { "<file>": <filemtime>, … }` for every *.js/*.css under
 * this directory, then streams pwa.js itself. pwa.js's v() helper reads LG_V to build
 * `?v=<filemtime>` URLs, so the 1y-immutable asset cache busts the moment `git pull`
 * changes a file — no hand-bumped counters, no conf edits (deploy-one-pull 2026-07-26,
 * standing rule: every loader carries ?v=filemtime).
 *
 * __DIR__ resolves through the docroot symlink to the repo webroot/, and filemtime
 * follows the same path — the checkout's mtimes ARE the versions. Strong ETag over
 * the whole map answers If-None-Match with 304, so the steady-state cost matches the
 * old static no-cache pwa.js: one tiny conditional request per page.
 */

$dir  = __DIR__;
$vers = array();
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS)
);
foreach ($it as $f) {
    /** @var SplFileInfo $f */
    if (!$f->isFile() || !preg_match('/\.(js|css)$/', $f->getFilename())) continue;
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
    if (strpos($rel, '.bak') !== false) continue;
    $vers[$rel] = $f->getMTime();
}
ksort($vers);

/**
 * ── The 3.10 service-worker flag (platform/config/pwa-sw.php) ────────────────
 * sw.js is a STATIC docroot asset and cannot read PHP. This loader is the only PHP in
 * the PWA bootstrap path, and pwa.js — which it serves — is what calls
 * navigator.serviceWorker.register(). So the flag rides the REGISTRATION URL: we emit
 * a ready-made query string and pwa.js appends it, meaning pwa.js needs no knowledge
 * of the parameter names and there is no second copy of the flag to drift.
 *
 * The CONFIG is authoritative for all three values, not just the on/off bit — sw.js
 * carries the same numbers only as a fallback for a client that somehow registered
 * without the query, so nothing here is dead config.
 *
 * OFF emits NOTHING — not even an empty string — so the served bytes are exactly what
 * they are today and pwa.js's `window.LG_SW` guard reads undefined. That is what makes
 * OFF byte-identical rather than merely behaviourally equivalent (same rule as
 * platform/config/sheet-embeds.php).
 */
$swGlobal = '';
$swCfgPath = $dir . '/../platform/config/pwa-sw.php';
if (is_file($swCfgPath)) {
    $swCfg = @include $swCfgPath;
    if (is_array($swCfg) && !empty($swCfg['resilient_fetch'])) {
        $q = array('f' => 'resilient');
        if (!empty($swCfg['nav_timeout_ms']))     $q['t'] = (int) $swCfg['nav_timeout_ms'];
        if (!empty($swCfg['sw_bypass_prefixes'])) $q['b'] = implode(',', (array) $swCfg['sw_bypass_prefixes']);
        $swGlobal = 'window.LG_SW=' . json_encode(http_build_query($q)) . ";\n";
    }
}

$body = 'window.LG_V=' . json_encode($vers, JSON_UNESCAPED_SLASHES) . ";\n"
      . $swGlobal
      . file_get_contents($dir . '/pwa.js');
$etag = '"' . md5($body) . '"';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');

$inm = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH']) : '';
if ($inm !== '' && strpos($inm, $etag) !== false) {
    http_response_code(304);
    exit;
}

header('Content-Length: ' . strlen($body));
echo $body;
