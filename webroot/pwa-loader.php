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
 * Feature flags the static JS bundles need but cannot read for themselves.
 *
 * This is the ONLY place a flag reaches every DM surface at once. The desktop
 * composer (lg-shared/social-modals.js) has two PHP loaders, and the phone sheet
 * (webroot/messenger-sheet.js) has none at all — pwa.js idle-loads it. But
 * /pwa.js is sub_filter-injected into every page's </head>, and it is served
 * no-cache with an ETag over the whole body, so a flag flip propagates on the
 * next request instead of waiting out a 1y immutable asset cache.
 *
 * ⚠️ EMITTED ONLY WHEN ON, AND THAT IS THE POINT. An OFF feature must add zero
 * bytes here, so the served /pwa.js stays byte-identical to the pre-feature
 * asset and "OFF is a no-op" is a fact about the wire, not a hope. Readers treat
 * absent as OFF (fail-closed), so there is nothing to emit for a false.
 *
 * __DIR__ resolves through the docroot symlink into the repo webroot/, so the
 * repo root is one level up — the same path arithmetic the LG_V map above relies
 * on. Never build this from a docroot-relative path: the file it needs is in the
 * monorepo, not the webroot.
 */
$flags = array();
$_epk  = dirname($dir) . '/platform/config/emoji-picker.php';
if (is_readable($_epk)) {
    $_raw = require $_epk;
    if (is_array($_raw) && ($_raw['enabled'] ?? false) === true) $flags['LG_EMOJI_PICKER'] = 1;
}
// Lane-preview overrides: getenv() for a pool or CLI harness, $_SERVER for a
// single nginx location (a fastcgi_param lands in $_SERVER but NOT reliably in
// the process environment — reading only getenv() serves the OFF path on the
// very preview URL built for Ian to click).
if (getenv('LG_EMOJI_PICKER') === '1' || (($_SERVER['LG_EMOJI_PICKER'] ?? '') === '1')) {
    $flags['LG_EMOJI_PICKER'] = 1;
}

$flagJs = '';
foreach ($flags as $k => $v) $flagJs .= 'window.' . $k . '=' . json_encode($v) . ";\n";

$body = 'window.LG_V=' . json_encode($vers, JSON_UNESCAPED_SLASHES) . ";\n"
      . $flagJs
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
