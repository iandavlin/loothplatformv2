<?php
/**
 * FAILURE-SEMANTICS DRIVE for the #150 double-pay probe.
 *
 *   php tests/patreon-standing-probe-drive.php
 *
 * Exit 0 pass, 1 a real defect, 3 cannot run.
 *
 * WHY THIS EXISTS BESIDE GATE 74. That gate exercises DoublePayGuard against a
 * STUBBED probe, deliberately: it must never touch the network, or it flakes
 * under load and blocks every lane. But the part of this feature most likely to
 * be wrong in production is exactly the part a stub cannot reach — what curl
 * does when WordPress answers 404, answers slowly, answers garbage, or refuses
 * the token. So this drives HttpPatreonStandingProbe over a REAL socket against
 * a canned WordPress, and is kept OUT of run-all.sh on purpose.
 *
 * THE ONE RULE UNDER TEST: every failure mode must ALLOW the purchase. The flag
 * being off, the route being absent, a timeout, a bad secret, a 500, malformed
 * JSON — all of them are "unknown", and an unknown must never stop a sale.
 * Failing closed would mean a WordPress hiccup takes the shop down; the rare
 * miss is surfaced by the Dual Payers tab (#149) instead.
 *
 * Measured when written (2026-08-19, loopback): a 404 costs ~1ms, which is the
 * honest price of the flag-OFF state on a non-gift checkout, and the 2-second
 * cap holds against a 5-second WordPress.
 */

declare(strict_types=1);

$B = dirname(__DIR__);
foreach ([ '/src/Contracts/SettingsStore.php', '/src/Contracts/PatreonStandingProbe.php',
           '/src/Adapters/HttpPatreonStandingProbe.php', '/src/Core/DoublePayGuard.php' ] as $f) {
    if (!is_readable($B . $f)) { fwrite(STDERR, "CANNOT RUN: missing $f\n"); exit(3); }
    require $B . $f;
}
if (!function_exists('curl_init')) { fwrite(STDERR, "CANNOT RUN: no curl\n"); exit(3); }

/** Canned WordPress. One file, so the drive is self-contained. */
$routerSrc = <<<'PHP'
<?php
$u = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
header('Content-Type: application/json');
switch ($u) {
    case '/active':
        echo json_encode(['active'=>true,'tier'=>'looth2','reason'=>'active_paid_patron',
            'message'=>'Your membership is already paid through Patreon, so buying here would charge you twice.',
            'manage_url'=>'https://www.patreon.com/loothgroup']); break;
    case '/inactive': echo json_encode(['active'=>false,'tier'=>null,'message'=>null,'manage_url'=>null]); break;
    case '/notfound': http_response_code(404); echo json_encode(['code'=>'rest_no_route']); break;
    case '/boom':     http_response_code(500); echo 'upstream exploded'; break;
    case '/garbage':  echo 'not json at all'; break;
    case '/noactive': echo json_encode(['tier'=>'looth2']); break;
    case '/slow':     sleep(5); echo json_encode(['active'=>true]); break;
    case '/unauth':   http_response_code(401); echo json_encode(['code'=>'rest_forbidden']); break;
    default:          http_response_code(404); echo '{}';
}
PHP;

$dir = sys_get_temp_dir() . '/lgsb-probe-drive-' . getmypid();
@mkdir($dir, 0700, true);
file_put_contents($dir . '/router.php', $routerSrc);

// Per-PID port, so two of these can run at once without inventing false REDs
// for each other (feedback-gate-probe-must-be-per-run).
$port = 8600 + (getmypid() % 900);
$desc = [ 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w'] ];
$srv  = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s %s', $port, escapeshellarg($dir), escapeshellarg($dir . '/router.php')),
    $desc, $pipes);
if (!is_resource($srv)) { fwrite(STDERR, "CANNOT RUN: could not start the canned server\n"); exit(3); }

// Wait for it to actually listen — asserting against a server that has not
// booted yet would pass every case for the wrong reason.
$up = false;
for ($i = 0; $i < 50; $i++) {
    $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
    if ($c) { fclose($c); $up = true; break; }
    usleep(100000);
}
if (!$up) { proc_terminate($srv); fwrite(STDERR, "CANNOT RUN: canned server never listened on $port\n"); exit(3); }

final class DriveSettings implements LGSB\Contracts\SettingsStore
{
    public function __construct(private string $url, private string $secret = 'test-secret') {}
    public function getSecretKey(): string { return ''; }
    public function getPublishableKey(): string { return ''; }
    public function getCheckoutReturnUrl(): string { return ''; }
    public function getHomeUrl(): string { return ''; }
    public function getSyncEndpointUrl(): string { return ''; }
    public function getGiftMailUrl(): string { return ''; }
    public function getSyncSharedSecret(): string { return $this->secret; }
    public function getPatreonStandingUrl(): string { return $this->url; }
    public function getWebhookSecret(): string { return ''; }
    public function getBulkDiscountTiers(): array { return []; }
    public function getRegionalFailUrl(): string { return ''; }
    public function getReturnSuccessUrl(): string { return ''; }
}

$base = 'http://127.0.0.1:' . $port;
$fail = 0;
$drive = static function (string $url, bool $isGift, string $secret = 'test-secret'): array {
    $t0    = microtime(true);
    $probe = new LGSB\Adapters\HttpPatreonStandingProbe(new DriveSettings($url, $secret));
    $r     = (new LGSB\Core\DoublePayGuard($probe))->refusalFor('someone@example.com', $isGift);
    return [ $r, (int) round((microtime(true) - $t0) * 1000) ];
};
$check = static function (bool $ok, string $what, string $got, int $ms) use (&$fail): void {
    if (!$ok) { $fail++; }
    printf("  %s  %-40s -> %-6s (%4dms)\n", $ok ? 'ok  ' : 'FAIL', $what, $got, $ms);
};

echo "HttpPatreonStandingProbe — failure semantics over a real socket\n\n";

// The one case that must REFUSE.
[$r, $ms] = $drive($base . '/active', false);
$check($r !== null && stripos((string) ($r['error'] ?? ''), 'patreon') !== false,
    'WordPress says PAYING', $r === null ? 'allow' : 'refuse', $ms);
$check(is_array($r) && ($r['manage_url'] ?? null) === 'https://www.patreon.com/loothgroup',
    '...and carries the manage-on-Patreon link', is_array($r) ? (string) ($r['manage_url'] ?? '-') : '-', 0);

// Everything else must ALLOW.
// ⚠️ /slow runs LAST on purpose. PHP's built-in server is single-threaded, so
// a case scheduled after it inherits its 5-second sleep and reads as ~2000ms —
// a measurement artefact that looks exactly like a second slow endpoint.
foreach ([
    '/inactive' => 'WordPress says not paying',
    '/notfound' => '404 — the flag is OFF, no route exists',
    '/boom'     => '500 — WordPress could not answer',
    '/garbage'  => 'a 200 that is not JSON',
    '/noactive' => 'JSON with no "active" key',
    '/unauth'   => '401 — the shared secret is wrong',
    '/slow'     => 'a slow WordPress (5s, against a 2s cap)',
] as $path => $what) {
    [$r, $ms] = $drive($base . $path, false);
    $check($r === null, $what, $r === null ? 'allow' : 'refuse', $ms);
    if ($path === '/slow') {
        $check($ms < 4000, '...and the cap held rather than hanging checkout', $ms . 'ms', $ms);
    }
}

[$r, $ms] = $drive($base . '/active', true);
$check($r === null, 'a GIFT, while WordPress says PAYING', $r === null ? 'allow' : 'refuse', $ms);

[$r, $ms] = $drive('', false);
$check($r === null, 'the URL blanked (the emergency valve)', $r === null ? 'allow' : 'refuse', $ms);

[$r, $ms] = $drive($base . '/active', false, '');
$check($r === null, 'no shared secret configured', $r === null ? 'allow' : 'refuse', $ms);

[$r, $ms] = $drive('gopher://127.0.0.1/active', false);
$check($r === null, 'a non-http scheme is refused before curl runs', $r === null ? 'allow' : 'refuse', $ms);

proc_terminate($srv);
proc_close($srv);
@unlink($dir . '/router.php');
@rmdir($dir);

echo "\n";
if ($fail > 0) {
    echo "RED — the probe does not fail open. A WordPress hiccup would stop sales.\n";
    exit(1);
}
echo "PASS — exactly one answer refuses a purchase, and every failure mode allows it.\n";
