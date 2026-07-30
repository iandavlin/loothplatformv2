<?php
/**
 * archive-poc/api/v0/fp-save.php — front-page EDITOR save proxy (admin only).
 *
 * WHY A PROXY
 * -----------
 * The config receiver (_config.php) is loopback-only and authed by a shared
 * secret in /etc/lg-archive-poc-secret. A browser can satisfy neither. The
 * wp-admin dash gets away with posting to it directly because the dash IS
 * server-side. The front-end editor is not, so this endpoint is the bridge:
 * it runs on the looth-dev pool (boots WP), proves the caller is a real WP
 * admin, then makes the loopback call on their behalf.
 *
 * AUTHZ — deliberately the strongest check available, per the standing rule
 * that a profile-app token is NOT a WP capability:
 *   1. nginx: dev cookie gate (see strangler-archive-poc.conf).
 *   2. WP login cookie -> get_current_user_id().
 *   3. current_user_can('manage_options') — WordPress's own answer, taken
 *      inside a booted WP. Not /whoami, not a tier cookie, not a JWT claim.
 *   4. WP nonce (X-WP-Nonce or _wpnonce) — CSRF, since 2+3 ride on cookies.
 *   5. Origin host must match, when the browser sends one.
 * Same shape as comment-post.php / save-post.php, one capability stricter.
 *
 *   GET  -> { authenticated, is_admin, nonce?, config? }   (config: admins only)
 *   POST -> { ok, applied } | { ok:false, error }
 *
 * POST body — a PATCH, never the whole document:
 *   {
 *     "row_patch":       { "id":"video-promo-members", "title"?:…,
 *                          "query"?:{ "html"?:…, "video_id"?:…, "aspect"?:… } },
 *     "featured_member"?:{ … }, "member_greeting"?:{ … },
 *     "sponsors"?:[…], "local_looths"?:[…], "cta_member"?:[…], "cta_public"?:[…]
 *   }
 * row_patch is merged server-side into the CURRENT rows array, so the client
 * never has to echo back rows it isn't editing — a client that forgot one
 * can't delete it.
 *
 * SANITIZATION happens at _config.php (the single write boundary), not here.
 * This file deliberately does not clean HTML: one authority, no drift.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

if (!isset($_SERVER['HTTP_HOST']))   $_SERVER['HTTP_HOST']   = LG_ARCHIVE_POC_HOST;
if (!isset($_SERVER['REQUEST_URI'])) $_SERVER['REQUEST_URI'] = '/';
if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
require LG_ARCHIVE_POC_WP_LOAD;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Vary: Cookie');

const LG_FP_NONCE   = 'lg_fp_save';
const LG_FP_WEBHOOK = 'https://127.0.0.1/archive-api/v0/_config';

function lg_fp_json($p, int $c = 200): void {
    http_response_code($c);
    echo json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Top-level keys the editor may write. Mirrors _config.php's own whitelist. */
const LG_FP_KEYS = ['sponsors', 'local_looths', 'cta_member', 'cta_public',
                    'featured_member', 'member_greeting'];

/** Row query fields the editor may patch. */
const LG_FP_QUERY_KEYS = ['html', 'video_id', 'aspect', 'side', 'instagram'];

/**
 * Loopback call to the config receiver.
 * $method GET  -> returns decoded body (or null)
 * $method POST -> returns HTTP status int
 */
function lg_fp_webhook(string $method, ?array $payload = null, bool $effective = false) {
    $url = LG_FP_WEBHOOK . ($effective ? '?effective=1' : '');
    $ch  = curl_init();
    $headers = ['Host: ' . LG_ARCHIVE_POC_HOST];

    if ($method === 'POST') {
        $secret = @file_get_contents('/etc/lg-archive-poc-secret');
        if (!is_string($secret) || trim($secret) === '') return 0;   // unreadable -> refuse
        $headers[] = 'X-LG-Config-Secret: ' . trim($secret);
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($method === 'POST') return $code;
    return ($code === 200 && is_string($body)) ? json_decode($body, true) : null;
}

/* ── gate ───────────────────────────────────────────────────────────────── */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uid    = (int) get_current_user_id();
$isAdmin = $uid > 0 && current_user_can('manage_options');

if ($method === 'GET') {
    // Anon/member: the honest minimum. No nonce, no config, nothing to mine.
    if (!$isAdmin) lg_fp_json(['authenticated' => $uid > 0, 'is_admin' => false]);
    $cfg = lg_fp_webhook('GET', null, /*effective*/ true);
    lg_fp_json([
        'authenticated' => true,
        'is_admin'      => true,
        'nonce'         => wp_create_nonce(LG_FP_NONCE),
        'config'        => is_array($cfg) ? $cfg : null,
    ]);
}

if ($method !== 'POST') lg_fp_json(['ok' => false, 'error' => 'method_not_allowed'], 405);

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && strcasecmp(parse_url($origin, PHP_URL_HOST) ?: '', LG_ARCHIVE_POC_HOST) !== 0) {
    lg_fp_json(['ok' => false, 'error' => 'bad_origin'], 403);
}
if ($uid <= 0)  lg_fp_json(['ok' => false, 'error' => 'auth_required'], 401);
if (!$isAdmin)  lg_fp_json(['ok' => false, 'error' => 'admin_required'], 403);

$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;

$nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? ($body['_wpnonce'] ?? '');
if (!wp_verify_nonce((string) $nonce, LG_FP_NONCE)) {
    lg_fp_json(['ok' => false, 'error' => 'bad_csrf'], 403);
}

/* ── build the payload for the write boundary ───────────────────────────── */
$payload = [];
foreach (LG_FP_KEYS as $k) {
    if (array_key_exists($k, $body) && is_array($body[$k])) $payload[$k] = $body[$k];
}

// row_patch: merge into the CURRENT rows array rather than trusting the client
// to round-trip every row it isn't touching.
if (isset($body['row_patch']) && is_array($body['row_patch'])) {
    $patch = $body['row_patch'];
    $rowId = isset($patch['id']) ? trim((string) $patch['id']) : '';
    if ($rowId === '') lg_fp_json(['ok' => false, 'error' => 'row_patch_needs_id'], 400);

    $cur  = lg_fp_webhook('GET', null, /*effective*/ true);
    $rows = (is_array($cur) && is_array($cur['rows'] ?? null)) ? $cur['rows'] : null;
    if ($rows === null) lg_fp_json(['ok' => false, 'error' => 'config_unreadable'], 502);

    $hit = false;
    foreach ($rows as $i => $row) {
        if (!is_array($row) || (string) ($row['id'] ?? '') !== $rowId) continue;
        $hit = true;
        if (array_key_exists('title', $patch)) $rows[$i]['title'] = (string) $patch['title'];
        if (isset($patch['query']) && is_array($patch['query'])) {
            $q = is_array($row['query'] ?? null) ? $row['query'] : [];
            foreach (LG_FP_QUERY_KEYS as $qk) {
                if (array_key_exists($qk, $patch['query'])) $q[$qk] = $patch['query'][$qk];
            }
            $rows[$i]['query'] = $q;
        }
        break;
    }
    if (!$hit) lg_fp_json(['ok' => false, 'error' => 'unknown_row_id'], 404);
    $payload['rows'] = $rows;
}

if (!$payload) lg_fp_json(['ok' => false, 'error' => 'nothing_to_save'], 400);

$code = lg_fp_webhook('POST', $payload);
if ($code === 0)    lg_fp_json(['ok' => false, 'error' => 'secret_unreadable'], 500);
if ($code !== 204)  lg_fp_json(['ok' => false, 'error' => 'webhook_failed', 'status' => $code], 502);

error_log(sprintf('[lg-fp-save] admin wp_user %d saved: %s', $uid, implode(',', array_keys($payload))));
lg_fp_json(['ok' => true, 'applied' => array_keys($payload)]);
