<?php
/**
 * archive-poc/api/v0/_config.php — dash-driven front-page config receiver.
 *
 * Loopback-only (nginx restricts $remote_addr to 127.0.0.1). Authed via
 * X-LG-Config-Secret header against /etc/lg-archive-poc-secret. Writes the
 * validated payload to LG_ARCHIVE_POC_CONFIG_JSON atomically.
 *
 * Request body (JSON):
 *   {
 *     "sponsors":     [{ "name":..., "url":..., "logo":..., "bg":... }, ...],
 *     "local_looths": [{ "name":..., "url":..., "avatar":... }, ...],
 *     "cta_member":   [{ "label":..., "url":..., "style":..., "icon"?:..., "action"?:..., "attr"?:... }, ...],
 *     "cta_public":   [...]
 *   }
 *
 * Any subset is accepted — missing keys leave the existing config value alone.
 * GET returns the current saved config (no auth needed; nginx already
 * loopback-gates this whole location).
 *
 * 204 on POST success, 200 + JSON on GET, 4xx/5xx with JSON {error} otherwise.
 */

declare(strict_types=1);
require __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if ($remote !== '127.0.0.1' && $remote !== '::1') {
    http_response_code(403);
    echo json_encode(['error' => 'loopback only']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $saved = [];
    if (is_file(LG_ARCHIVE_POC_CONFIG_JSON)) {
        $rawSaved = @file_get_contents(LG_ARCHIVE_POC_CONFIG_JSON);
        if ($rawSaved !== false) {
            $parsedSaved = json_decode($rawSaved, true);
            if (is_array($parsedSaved)) $saved = $parsedSaved;
        }
    }

    // ?effective=1 → return defaults overlaid with the saved file. Used by
    // the dash on initial form load so authors see what's actually rendering
    // (not a blank form that would wipe defaults on save).
    if (!empty($_GET['effective'])) {
        $defaultsPath = realpath(__DIR__ . '/../../web/defaults.php');
        $defaults     = $defaultsPath && is_file($defaultsPath) ? (require $defaultsPath) : [];
        if (!is_array($defaults)) $defaults = [];

        // Default rows come from rows.json, not defaults.php.
        if (defined('LG_ARCHIVE_POC_ROWS_JSON') && is_file(LG_ARCHIVE_POC_ROWS_JSON)) {
            $rowsRaw    = @file_get_contents(LG_ARCHIVE_POC_ROWS_JSON);
            $rowsParsed = $rowsRaw !== false ? json_decode($rowsRaw, true) : null;
            if (is_array($rowsParsed) && is_array($rowsParsed['rows'] ?? null)) {
                $defaults['rows'] = $rowsParsed['rows'];
            }
        }

        // Per-key overlay (matches index.php's semantics): saved key replaces
        // defaults key wholesale; missing saved key falls through to defaults.
        $effective = $defaults;
        foreach (['sponsors','local_looths','cta_member','cta_public','rows'] as $k) {
            if (isset($saved[$k]) && is_array($saved[$k])) $effective[$k] = $saved[$k];
        }
        echo json_encode($effective, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    echo json_encode($saved, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST or GET']);
    exit;
}

$expected = LG_ARCHIVE_POC_CONFIG_SECRET;
if ($expected === '') {
    http_response_code(500);
    echo json_encode(['error' => 'server secret unconfigured']);
    exit;
}
$provided = $_SERVER['HTTP_X_LG_CONFIG_SECRET'] ?? '';
if (!hash_equals($expected, (string) $provided)) {
    http_response_code(401);
    echo json_encode(['error' => 'bad or missing X-LG-Config-Secret']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$payload = $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON object body required']);
    exit;
}

// Whitelist + shape-validate each top-level key. Anything not in this set is
// silently dropped, so a poisoned dash request can't add arbitrary keys.
// `rows` is the front-page row list — overlays rows.json when present.
$allowed_keys = ['sponsors', 'local_looths', 'cta_member', 'cta_public', 'rows'];
$existing = [];
if (is_file(LG_ARCHIVE_POC_CONFIG_JSON)) {
    $existing_raw = @file_get_contents(LG_ARCHIVE_POC_CONFIG_JSON);
    $existing = $existing_raw !== false ? (json_decode($existing_raw, true) ?: []) : [];
}
$merged  = $existing;
$applied = [];
foreach ($allowed_keys as $k) {
    if (!array_key_exists($k, $payload)) continue;
    $v = $payload[$k];
    if (!is_array($v)) {
        http_response_code(400);
        echo json_encode(['error' => "'$k' must be an array"]);
        exit;
    }
    if ($k === 'rows') {
        // Rows can have nested `query` objects + arbitrary metadata, so we
        // accept any associative structure — just require an `id` and `type`
        // per row.
        $clean = lg_normalize_rows($v);
    } else {
        // Sponsor / CTA / Looth rows are flat key→scalar maps.
        $clean = [];
        foreach ($v as $row) {
            if (!is_array($row)) continue;
            $rowClean = [];
            foreach ($row as $rk => $rv) {
                if (!is_string($rk)) continue;
                if (is_scalar($rv) || $rv === null) $rowClean[$rk] = $rv;
            }
            if ($rowClean) $clean[] = $rowClean;
        }
    }
    $merged[$k] = $clean;
    $applied[] = $k . ':' . count($clean);
}

/**
 * Normalize the front-page `rows` array:
 *   - drop rows without an `id` or `type`
 *   - sanitize nested `query` to a key→scalar map (1 level deep)
 */
function lg_normalize_rows(array $rows): array {
    $clean = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        if (empty($row['id']) || empty($row['type'])) continue;

        $r = [];
        foreach ($row as $k => $v) {
            if (!is_string($k)) continue;
            if ($k === 'query' && is_array($v)) {
                // Allow scalar-valued query keys; `exclude` (array of slugs)
                // is the one exception — accept it as a list of strings.
                $q = [];
                foreach ($v as $qk => $qv) {
                    if (!is_string($qk)) continue;
                    if (is_scalar($qv) || $qv === null) {
                        $q[$qk] = $qv;
                    } elseif ($qk === 'exclude' && is_array($qv)) {
                        $q[$qk] = array_values(array_filter(array_map('strval', $qv)));
                    } elseif ($qk === 'exclude_kinds' && is_array($qv)) {
                        $q[$qk] = array_values(array_filter(array_map('strval', $qv)));
                    }
                }
                $r[$k] = $q;
            } elseif (is_scalar($v) || $v === null) {
                $r[$k] = $v;
            }
        }
        $clean[] = $r;
    }
    return $clean;
}

// Atomic write: temp file + rename. Avoids torn reads from SSR pool.
$json = json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$tmp  = LG_ARCHIVE_POC_CONFIG_JSON . '.tmp.' . bin2hex(random_bytes(4));
if (file_put_contents($tmp, $json, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'write failed']);
    exit;
}
if (!rename($tmp, LG_ARCHIVE_POC_CONFIG_JSON)) {
    @unlink($tmp);
    http_response_code(500);
    echo json_encode(['error' => 'rename failed']);
    exit;
}
@chmod(LG_ARCHIVE_POC_CONFIG_JSON, 0644);

header('X-LG-Config-Applied: ' . implode(',', $applied));
http_response_code(204);
