<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

function profile_app_json(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Per-key sliding-window rate gate (file-backed — no Redis on this box). Used to
 * cap user uploads (R2 write cost + churn). FAIL-OPEN on any fs error so a
 * transient problem never blocks legitimate uploads; sends 429 + exits when over.
 */
function profile_app_rate_gate(string $key, int $max, int $windowSec): void
{
    $dir = sys_get_temp_dir() . '/lg-rate';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $fh = @fopen($dir . '/' . hash('sha256', $key), 'c+');
    if (!$fh) return;                                  // fail-open
    if (!flock($fh, LOCK_EX)) { fclose($fh); return; }
    $now = time();
    $ts  = array_values(array_filter(
        array_map('intval', explode("\n", trim((string) stream_get_contents($fh)))),
        static fn ($t) => $t > $now - $windowSec
    ));
    if (count($ts) >= $max) {
        flock($fh, LOCK_UN); fclose($fh);
        profile_app_json(429, ['error' => 'rate_limited', 'retry_after' => $windowSec]);
    }
    $ts[] = $now;
    ftruncate($fh, 0); rewind($fh); fwrite($fh, implode("\n", $ts)); fflush($fh);
    flock($fh, LOCK_UN); fclose($fh);
}

/**
 * Is $host within the looth_id cookie's own site (*.loothgroup.com)? That is
 * exactly the set of origins a victim's browser could carry the cookie to, so
 * it is the correct allowlist for a same-site CSRF check.
 */
function profile_app_request_is_same_site(string $host): bool
{
    $host = strtolower($host);
    if ($host === '') return false;
    if (defined('LG_PROFILE_APP_HOST') && $host === LG_PROFILE_APP_HOST) return true;
    return $host === 'loothgroup.com' || str_ends_with($host, '.loothgroup.com');
}

/**
 * CSRF defense for state-changing, cookie-authenticated requests (audit H3).
 * SameSite=Lax on the looth_id cookie already blocks classic cross-site form
 * POSTs; this is the belt-and-suspenders Origin check for the /me/* mutation
 * surface. Runs from _bootstrap.php, so EVERY endpoint inherits it.
 *
 * Deliberately narrow — it fires only when there is something to protect:
 *   - Mutating methods only (POST/PUT/PATCH/DELETE). Reads are CSRF-safe.
 *   - Only when the looth_id cookie is present, i.e. an ambient browser
 *     credential a cross-site page could ride. Server-to-server internal calls
 *     (poller, whoami shim) authenticate via X-LG-Internal-Auth / X-Hook-Secret
 *     and send no looth_id cookie — they're not forgeable cross-site and are
 *     also nginx loopback-locked, so they're skipped here. Pure Bearer-token
 *     callers (native app) carry no ambient cookie either → skipped.
 *
 * We reject only a PRESENT, FOREIGN Origin (Referer fallback). A browser
 * cannot be made to suppress the Origin header on a cross-site non-GET request,
 * so a real cross-site forgery is always identifiable by a foreign Origin.
 * A genuinely absent Origin AND Referer means a same-origin or non-browser
 * caller — not a forgeable browser context — so it is allowed and SameSite=Lax
 * remains the primary gate. This keeps legitimate same-origin XHR (and the
 * visibility-matrix harness) working without an explicit Origin header.
 */
function profile_app_csrf_guard(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') return;
    if (empty($_COOKIE['looth_id'])) return;   // no ambient browser credential → not CSRF-exposed

    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') $origin = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($origin === '') return;                // no browser-origin signal → SameSite=Lax governs

    $host = (string)(parse_url($origin, PHP_URL_HOST) ?? '');
    if (!profile_app_request_is_same_site($host)) {
        profile_app_json(403, ['error' => 'csrf_origin_rejected']);
    }
}

profile_app_csrf_guard();
