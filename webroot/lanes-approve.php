<?php
/**
 * lanes-approve.php — the one-verb servant (#139, Ian approved 8/19).
 *
 * Adds the `approved` label to ONE open, plan-ready issue. That is its entire
 * vocabulary: it cannot close, edit, comment, or read anything out. POST only,
 * guarded by a per-day HMAC nonce that the lanes-page renderer embeds — the
 * nonce is derived from the GitHub token server-side, so it is unforgeable
 * without the token, and the token itself never leaves the box.
 *
 * Standalone on purpose: no wp-load (trap 7 — __DIR__ resolves through the
 * deploy symlink into the repo, where wp-load.php isn't).
 */
declare(strict_types=1);

header('Content-Type: application/json');

function fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail(405, 'POST only');

$n     = (int)($_POST['issue'] ?? 0);
$nonce = (string)($_POST['nonce'] ?? '');
if ($n < 1 || $nonce === '') fail(400, 'bad input');

$token = '';
foreach (@file('/etc/looth/env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (strpos($line, 'LG_GITHUB_ISSUES_TOKEN=') === 0) {
        $token = trim(substr($line, strlen('LG_GITHUB_ISSUES_TOKEN=')));
        break;
    }
}
if ($token === '') fail(500, 'no token on box');

// Nonce: HMAC(approve:<issue>:<utc-date>, token). Today or yesterday, so a
// page rendered just before midnight still works.
$valid = false;
foreach ([0, 86400] as $back) {
    $expect = hash_hmac('sha256', 'approve:' . $n . ':' . gmdate('Y-m-d', time() - $back), $token);
    if (hash_equals($expect, $nonce)) { $valid = true; break; }
}
if (!$valid) fail(403, 'stale page — reload and retry');

$gh = function (string $method, string $path, ?array $data = null) use ($token): array {
    $ch = curl_init('https://api.github.com/repos/iandavlin/loothplatformv2' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'User-Agent: lanes-approve',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => $data !== null ? json_encode($data) : null,
    ]);
    $out  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, json_decode((string)$out, true)];
};

// Server-side re-check before acting: open + plan-ready, nothing else counts.
[$code, $issue] = $gh('GET', "/issues/$n");
if ($code !== 200 || !is_array($issue)) fail(502, 'issue unreadable');
$labels = array_map(static fn($l) => $l['name'], $issue['labels'] ?? []);
if (($issue['state'] ?? '') !== 'open' || !in_array('plan-ready', $labels, true)) {
    fail(409, 'issue is not open + plan-ready');
}

[$code2] = $gh('POST', "/issues/$n/labels", ['labels' => ['approved']]);
if ($code2 !== 200 && $code2 !== 201) fail(502, 'labeling failed');

echo json_encode(['ok' => true, 'issue' => $n]);
