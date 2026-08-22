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
 *     "cta_public":   [...],
 *     "rows":         [{ "id":..., "type":..., "query": {...} }, ...],
 *     "featured_member": { "enabled":..., "name":..., "role":..., "pinned":..., ... },
 *                                                                          // flat map
 *     "member_greeting": { "body":... }                                    // flat map
 *   }
 *
 * Any subset is accepted — missing keys leave the existing config value alone.
 *
 * SANITIZATION (see _html-sanitize.php): `rows[].query.html` is the one field
 * that reaches the public page as raw markup, so it is whitelist-sanitized here.
 * `video_id` and `aspect` are likewise normalized because both are interpolated
 * into the rendered document. This is the ONLY write path to config.json, so
 * both writers (wp-admin dash, front-end editor) are covered by construction.
 * GET returns the current saved config (no auth needed; nginx already
 * loopback-gates this whole location).
 *
 * 204 on POST success, 200 + JSON on GET, 4xx/5xx with JSON {error} otherwise.
 */

declare(strict_types=1);
require __DIR__ . '/../../config.php';
// The write boundary owns sanitization: rows[].query.html reaches the public
// page as raw markup, so every writer (wp-admin dash AND the front-end editor)
// gets scrubbed here rather than each being trusted to scrub itself.
require __DIR__ . '/_html-sanitize.php';

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
        // Keep this list in step with $allowed_keys below — a key that is
        // writable but missing here reads back as its default, which is how a
        // form ends up silently reverting a saved value on its next save.
        $effective = $defaults;
        foreach (['sponsors','local_looths','cta_member','cta_public','rows',
                  'featured_member','member_greeting','hub_teaser'] as $k) {
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
//
// Two shapes live here (see $assoc_keys): LIST-of-maps keys (sponsors, CTAs…)
// and FLAT-map keys (featured_member, member_greeting). featured_member has been
// readable in defaults.php + index.php since the Bento band shipped but was
// never in this list, so it could not be saved through the webhook at all — the
// value in the live config.json predates the whitelist. The front-end editor
// edits it, so it joins the list here.
$allowed_keys = ['sponsors', 'local_looths', 'cta_member', 'cta_public', 'rows',
                 'featured_member', 'member_greeting'];

/** Keys whose value is a flat key→scalar map, not a list of rows. */
$assoc_keys = ['featured_member', 'member_greeting'];
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
    } elseif (in_array($k, $assoc_keys, true)) {
        // Flat key→scalar map. Every value is rendered through h() on the
        // front page, so the scalar filter is the whole contract here.
        $clean = [];
        foreach ($v as $mk => $mv) {
            if (!is_string($mk)) continue;
            if (is_scalar($mv) || $mv === null) $clean[$mk] = $mv;
        }
        // featured-members lane (backlog 18) — MERGE onto the existing map for
        // THIS key only, never wholesale-replace. Found in review 2026-08-15:
        // FeaturedMemberDash's Feature action sends only
        // {enabled, member_uuid, name, role, chosen_by} — it deliberately
        // does not cache avatar/where/bio/cta_href/cta_label, because
        // index.php LIVE-resolves those from profile_app on every request
        // rather than trusting a stale copy. A wholesale $merged[$k]=$clean
        // would silently DELETE those keys from config.json on every Feature
        // click. That is invisible while member_uuid is present (index.php's
        // flag-on resolver ignores them entirely) but breaks the moment the
        // flag is later turned back off: with member_uuid set the null
        // fallback correctly blanks the band, but the ORIGINAL hand-typed
        // card (avatar/bio/etc, unrelated to this feature) is now gone from
        // config.json too — "flag off" stops meaning "exactly as before
        // this feature ever existed" the first time an admin uses it.
        // member_greeting is NOT changed here — no known caller sends a
        // partial payload for it, and merging every assoc_key by default
        // would be a wider, unreviewed behaviour change for a problem only
        // featured_member actually has today.
        if ($k === 'featured_member') {
            $clean = $clean + (is_array($existing['featured_member'] ?? null) ? $existing['featured_member'] : []);
        }
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

// Featured-member HISTORY (featured-members lane, backlog 18; ruling item 3:
// "ONE at a time"). This is the ONE write path to `featured_member`, so it is
// also the one place a transition can be observed — no second door, no drift
// between config.json's "who is featured now" and featured_history's "who was,
// and when". Fires only when `featured_member` was actually part of THIS
// payload (not on every unrelated save, e.g. a sponsors edit).
if (in_array('featured_member', $allowed_keys, true) && array_key_exists('featured_member', $payload)) {
    $prevUuid = (string) ($existing['featured_member']['member_uuid'] ?? '');
    $prevOn   = !empty($existing['featured_member']['enabled']) && $prevUuid !== '';
    $nextUuid = (string) ($merged['featured_member']['member_uuid'] ?? '');
    $nextOn   = !empty($merged['featured_member']['enabled']) && $nextUuid !== '';

    if ($prevUuid !== $nextUuid || $prevOn !== $nextOn) {
        try {
            $hpdo = lg_archive_poc_pdo();
            if ($hpdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
                if ($prevOn) {
                    // Close whatever was open. Idempotent by construction —
                    // if nothing is open this affects 0 rows, never errors.
                    $hpdo->prepare('UPDATE discovery.featured_history SET ended_at = now() WHERE ended_at IS NULL')->execute();
                }
                if ($nextOn) {
                    $name = (string) ($merged['featured_member']['name'] ?? '');
                    $by   = $merged['featured_member']['chosen_by'] ?? null;
                    // #200 — WAS THIS STINT CONSENTED OR PINNED? Without it this
                    // table reads as a list of members who agreed to be
                    // featured, and after Ian's override ruling that is no
                    // longer what it is. Every row predating the column was a
                    // consented pick, so its `false` default is accurate
                    // history rather than a convenient guess.
                    //
                    // ⚠️ COLUMN-PROBED, NOT ASSUMED. tools/migrations/
                    // 200-featured-history-pinned.sql has not run on live yet
                    // and cannot from here (live writes are Ian's). This whole
                    // block is wrapped in a catch by design, so an INSERT
                    // naming a missing column would not error visibly — it
                    // would just silently stop recording stints on live
                    // altogether. Same probe shape as u.php's featured_opt_in
                    // check, and it costs one cached query per save.
                    $hasPinned = false;
                    try {
                        $chk = $hpdo->query(
                            "SELECT 1 FROM information_schema.columns
                              WHERE table_schema = 'discovery'
                                AND table_name = 'featured_history'
                                AND column_name = 'pinned' LIMIT 1"
                        );
                        $hasPinned = (bool) ($chk && $chk->fetchColumn());
                    } catch (Throwable $e) {
                        $hasPinned = false;
                    }
                    if ($hasPinned) {
                        $ins = $hpdo->prepare(
                            'INSERT INTO discovery.featured_history (member_uuid, display_name, chosen_by, pinned)
                             VALUES (:u, :n, :b, :p)'
                        );
                        $ins->execute([':u' => $nextUuid, ':n' => $name, ':b' => $by,
                                       ':p' => !empty($merged['featured_member']['pinned'])]);
                    } else {
                        $ins = $hpdo->prepare(
                            'INSERT INTO discovery.featured_history (member_uuid, display_name, chosen_by) VALUES (:u, :n, :b)'
                        );
                        $ins->execute([':u' => $nextUuid, ':n' => $name, ':b' => $by]);
                    }
                }
            }
        } catch (Throwable $e) {
            // History is a RECORD of the change, not a gate on it — config.json
            // (the thing the front page actually reads) must still save even if
            // the history write fails. Loud in the log, silent to the caller.
            error_log('[featured-history] write failed: ' . $e->getMessage());
        }
    }
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
                        // Three query keys are not opaque scalars — they are
                        // rendered into the page and must be normalized HERE,
                        // at the only write boundary, not trusted from a client:
                        //   html     → echoed RAW by _render-main-row.php:476
                        //   video_id → interpolated into the YouTube iframe src
                        //   aspect   → interpolated into a CSS class name
                        if ($qk === 'html') {
                            $q[$qk] = lg_archive_poc_sanitize_html((string) $qv);
                        } elseif ($qk === 'video_id') {
                            $q[$qk] = lg_archive_poc_youtube_id((string) $qv);
                        } elseif ($qk === 'aspect') {
                            $q[$qk] = lg_archive_poc_aspect((string) $qv);
                        } else {
                            $q[$qk] = $qv;
                        }
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
