<?php
/**
 * Plugin Name: Looth Whoami Shim
 * Description: WP-shim proxying GET /wp-json/looth/v1/whoami to profile-app's
 *              canonical /profile-api/v0/whoami endpoint. Same response shape
 *              as the upstream — this is a convenience for WP-side consumers
 *              that don't want to call profile-app directly. STRANGLER-COORDINATION.md §2.
 *
 * Auth: forwards the caller's cookies (WP login + looth_id JWT) verbatim, so
 * the upstream sees the same identity. No bearer-token rewriting.
 *
 * Cache: passes through ETag and Cache-Control. The 30s Redis cache is on
 * profile-app's side; this shim is stateless.
 */

if (!defined('ABSPATH')) exit;

// Suppress WP REST's nonce check on this single route. Without this filter,
// a logged-in user without a valid X-WP-Nonce header gets 403
// `rest_cookie_invalid_nonce` BEFORE our callback runs — which defeats the
// point of a read-only cookie-driven identity shim. We re-implement the
// auth ourselves (wp_validate_auth_cookie) inside the callback.
add_filter('rest_authentication_errors', function ($result) {
    if ($result === true || is_wp_error($result)) {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        if (strpos($uri, '/wp-json/looth/v1/whoami') === 0
         || strpos($uri, '/looth/v1/whoami')        !== false) {
            // Only suppress the cookie-nonce error (preserves other failures).
            if (is_wp_error($result) && $result->get_error_code() === 'rest_cookie_invalid_nonce') {
                return null;
            }
        }
    }
    return $result;
}, 100);

add_action('rest_api_init', function () {
    register_rest_route('looth/v1', '/whoami', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function (WP_REST_Request $req) {
            $url = 'https://127.0.0.1/profile-api/v0/whoami';

            $headers = [
                'Host: ' . ($_SERVER['HTTP_HOST'] ?? 'dev.loothgroup.com'),
                'Accept: application/json',
            ];

            // WP-session bridge: if the caller is logged into WP, identify
            // them to profile-app via trusted headers (shared-secret guarded)
            // instead of relying on a looth_id JWT cookie that may not exist.
            //
            // WP REST cookie auth requires a nonce to set current_user; we
            // bypass that by validating the wordpress_logged_in_* cookie
            // directly via wp_validate_auth_cookie() — same primitive WP
            // uses internally. No nonce required for read-only identity
            // resolution, and the shared-secret check at the upstream side
            // makes the trusted-header path uniterally safe.
            $wpUserId = 0;
            foreach ($_COOKIE as $cName => $cVal) {
                if (strpos($cName, 'wordpress_logged_in_') === 0) {
                    $uid = wp_validate_auth_cookie((string)$cVal, 'logged_in');
                    if ($uid) { $wpUserId = (int)$uid; break; }
                }
            }
            // Always forward caller cookies — the upstream /profile-api/v0/*
            // is behind nginx's dev cookie gate (loothdev_auth), and the
            // anon-fallback path needs whatever JWT the caller has.
            $cookieHeader = (string) ($_SERVER['HTTP_COOKIE'] ?? '');
            if ($cookieHeader !== '') $headers[] = 'Cookie: ' . $cookieHeader;

            // If logged into WP, layer trusted-headers on top — upstream
            // picks these over JWT/cookie identity when both are present.
            if ($wpUserId > 0 && defined('LG_INTERNAL_SECRET') && LG_INTERNAL_SECRET !== '') {
                $headers[] = 'X-LG-WP-User-Id: ' . $wpUserId;
                $headers[] = 'X-LG-Internal-Auth: ' . LG_INTERNAL_SECRET;
            }

            $ifNoneMatch = $req->get_header('If-None-Match');
            if ($ifNoneMatch) $headers[] = 'If-None-Match: ' . $ifNoneMatch;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_HEADER         => true,
                CURLOPT_HTTPHEADER     => $headers,
            ]);
            $resp     = curl_exec($ch);
            $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $hdrSize  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            if (!is_string($resp) || $status === 0) {
                return new WP_Error('upstream_unreachable',
                    'profile-app /whoami unreachable', ['status' => 502]);
            }

            $hdrBlob = substr($resp, 0, $hdrSize);
            $body    = substr($resp, $hdrSize);

            // Pass through ETag + Cache-Control from upstream.
            foreach (explode("\r\n", $hdrBlob) as $h) {
                if (stripos($h, 'ETag:') === 0)          header($h);
                if (stripos($h, 'Cache-Control:') === 0) header($h);
            }

            if ($status === 304) {
                http_response_code(304);
                exit;
            }

            // Body is already JSON — emit verbatim.
            header('Content-Type: application/json');
            http_response_code($status);
            echo $body;
            exit;
        },
    ]);
});
