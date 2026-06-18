<?php
// Local-only router for `php -S 127.0.0.1:8765 -t . bin/local-router.php`.
// Maps the same URL surface that nginx will serve in production:
//   /archive-poc/*       → web/*
//   /archive-api/v0/*    → api/v0/<endpoint>.php
// Used for end-to-end smoke testing before nginx is wired.

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = realpath(__DIR__ . '/..');

// Frontend at /archive-poc/
if (preg_match('#^/archive-poc(/.*)?$#', $uri, $m)) {
    $rel = $m[1] ?? '/';
    if ($rel === '' || $rel === '/') $rel = '/index.html';
    $path = $root . '/web' . $rel;
    if (is_file($path)) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $types = ['html'=>'text/html','css'=>'text/css','js'=>'application/javascript','png'=>'image/png','jpg'=>'image/jpeg','svg'=>'image/svg+xml','json'=>'application/json'];
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        readfile($path);
        return true;
    }
    http_response_code(404); echo "not found: $rel"; return true;
}

// API at /archive-api/v0/<endpoint>[/<id>]
if (preg_match('#^/archive-api/v0/(search|item)(?:/(\d+))?/?$#', $uri, $m)) {
    if (!empty($m[2])) $_GET['id'] = $m[2];
    require $root . '/api/v0/' . $m[1] . '.php';
    return true;
}

http_response_code(404); echo "no route: $uri"; return true;
