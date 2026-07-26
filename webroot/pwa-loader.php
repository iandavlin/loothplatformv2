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

$body = 'window.LG_V=' . json_encode($vers, JSON_UNESCAPED_SLASHES) . ";\n"
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
