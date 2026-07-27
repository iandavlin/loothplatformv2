<?php
/**
 * dest-capture-gate.php — the hostile-value table for lg_dest_capture().
 *
 * Every door into the site now funnels its post-auth destination through
 * lg_dest_capture() (lg-shared/lg-destination.php). That makes it the single
 * place an open redirect could be introduced, so it gets the single table that
 * proves it isn't.
 *
 *   php tools/gates/dest-capture-gate.php            # gate the shared helper
 *   php tools/gates/dest-capture-gate.php --legacy   # RED baseline: the guard
 *                                                    # this lane replaced
 *
 * The --legacy run is not decoration. It runs the SAME table against the inline
 * guard that lgpo_handle_connect() used before this lane —
 *
 *     preg_match('#^/[^/]#', $candidate) && strpos($candidate, "\n") === false
 *
 * — and it fails, loudly, on the cases that matter. That is the RED this gate
 * was written against; the helper is what turns it green.
 *
 * Also gates the JS twin (lg-shared/lg-destination.js) against the same table
 * when node is available, so the two implementations cannot drift apart
 * silently — a door built client-side must reject exactly what a door built
 * server-side rejects.
 */

declare(strict_types=1);

$legacy = in_array('--legacy', $argv, true);

// HTTP_HOST is what the WP-free core validates absolute URLs against.
$_SERVER['HTTP_HOST']   = 'dev2.loothgroup.com';
$_SERVER['REQUEST_URI'] = '/hub/gear/some-thread/?highlight=42';

require_once __DIR__ . '/../../lg-shared/lg-destination.php';

/** The guard this lane replaced — kept ONLY to prove the table goes red on it. */
function lg_dest_legacy_capture($raw): string
{
    if (!is_string($raw)) return '';
    return (preg_match('#^/[^/]#', $raw) && strpos($raw, "\n") === false) ? $raw : '';
}

$long = '/' . str_repeat('a', 600);

/** [label, input, expected] — expected '' means "must be rejected". */
$cases = [
    // ── the hostile table (Ian 2026-07-27) ──────────────────────────────────
    ['scheme-relative host',        '//evil.example',                    ''],
    ['absolute off-host',           'https://evil.example',              ''],
    ['absolute off-host w/ path',   'https://evil.example/hub/',         ''],
    ['backslash scheme-relative',   '/\\evil.example',                   ''],
    ['backslash mixed',             '/\\/evil.example',                  ''],
    ['javascript: pseudo-scheme',   'javascript:alert(1)',               ''],
    ['data: pseudo-scheme',         'data:text/html,x',                  ''],
    ['newline injected',            "/hub/\nSet-Cookie: a=b",            ''],
    ['CR injected',                 "/hub/\r\nLocation: //evil",         ''],
    ['NUL injected',                "/hub/\x00.png",                     ''],
    ['auth path — the login loop',  '/wp-login.php',                     ''],
    ['auth path — patreon-password','/patreon-password',                 ''],
    ['600-char path',               $long,                               ''],
    ['query survives WHOLE',        '/hub/?topic=x&y=2',                 '/hub/?topic=x&y=2'],

    // ── the rest of the contract ────────────────────────────────────────────
    ['empty',                       '',                                  ''],
    ['non-string (null)',           null,                                ''],
    ['non-string (array)',          ['/hub/'],                           ''],
    ['relative, no leading slash',  'hub/gear/',                         ''],
    ['auth path w/ trailing slash', '/patreon-password/',                ''],
    ['auth path w/ query',          '/wp-login.php?action=logout',       ''],
    ['auth path — patreon-connect', '/patreon-connect?return=/hub/',     ''],
    ['auth path — case-insensitive','/WP-Login.PHP',                     ''],
    ['userinfo smuggling',          'https://dev2.loothgroup.com@evil.example/', ''],
    ['mailto:',                     'mailto:a@b.example',                ''],
    ['tab injected',                "/hub/\t/x",                         ''],
    ['leading space',               ' /hub/',                            ''],
    ['plain path',                  '/hub/gear/thread/',                 '/hub/gear/thread/'],
    ['root',                        '/',                                 '/'],
    ['fragment dropped',            '/hub/gear/#reply-9',                '/hub/gear/'],
    ['fragment dropped, query kept','/hub/?topic=x#r9',                  '/hub/?topic=x'],
    ['same-host absolute reduced',  'https://dev2.loothgroup.com/hub/?topic=x&y=2', '/hub/?topic=x&y=2'],
    ['same-host absolute, port',    'https://dev2.loothgroup.com:443/u/mike',       '/u/mike'],
    ['same-host, case-insensitive', 'https://DEV2.LoothGroup.com/u/mike', '/u/mike'],
    ['encoded chars preserved',     '/u/mike%20smith?q=a%2Bb',           '/u/mike%20smith?q=a%2Bb'],
    ['exactly at the length cap',   '/' . str_repeat('a', 511),          '/' . str_repeat('a', 511)],
    ['one byte over the cap',       '/' . str_repeat('a', 512),          ''],
];

$fn   = $legacy ? 'lg_dest_legacy_capture' : 'lg_dest_capture';
$fail = 0;
$pass = 0;

echo $legacy
    ? "=== dest-capture: RED BASELINE (the pre-lane inline guard) ===\n"
    : "=== dest-capture: lg_dest_capture() (lg-shared/lg-destination.php) ===\n";

foreach ($cases as [$label, $input, $expect]) {
    $got = $fn($input);
    if ($got === $expect) {
        $pass++;
        continue;
    }
    $fail++;
    printf(
        "  FAIL  %-30s in=%s\n         expected=%s\n         got     =%s\n",
        $label,
        var_export(is_string($input) ? substr($input, 0, 60) : $input, true),
        var_export($expect, true),
        var_export(substr((string) $got, 0, 60), true)
    );
}

printf("  %d passed, %d failed (%d cases)\n", $pass, $fail, count($cases));

// ── lg_dest_here() / lg_dest_login_url() ────────────────────────────────────
if (!$legacy) {
    echo "\n=== dest-capture: lg_dest_here() + lg_dest_login_url() ===\n";
    $urlCases = [
        ['here() reflects REQUEST_URI',
            lg_dest_here(), '/hub/gear/some-thread/?highlight=42'],
        ['bare login is byte-identical',
            lg_dest_login_url(''), '/wp-login.php'],
        ['hostile dest falls back to bare login',
            lg_dest_login_url('//evil.example'), '/wp-login.php'],
        ['auth-path dest falls back to bare login',
            lg_dest_login_url('/wp-login.php'), '/wp-login.php'],
        ['query is encoded, not split',
            lg_dest_login_url('/hub/?topic=x&y=2'),
            '/wp-login.php?redirect_to=' . rawurlencode('/hub/?topic=x&y=2')],
        ['base that already has a query gets &',
            lg_dest_login_url('/hub/', '/wp-login.php?action=x'),
            '/wp-login.php?action=x&redirect_to=' . rawurlencode('/hub/')],
        ['absolute base (cross-host surface) preserved',
            lg_dest_login_url('/u/mike', 'https://dev2.loothgroup.com/wp-login.php'),
            'https://dev2.loothgroup.com/wp-login.php?redirect_to=' . rawurlencode('/u/mike')],
        ['round-trip: redirect_to decodes back whole',
            rawurldecode((string) parse_url(lg_dest_login_url('/hub/?topic=x&y=2'), PHP_URL_QUERY) === null
                ? '' : substr((string) parse_url(lg_dest_login_url('/hub/?topic=x&y=2'), PHP_URL_QUERY), strlen('redirect_to='))),
            '/hub/?topic=x&y=2'],
    ];
    foreach ($urlCases as [$label, $got, $expect]) {
        if ($got === $expect) { $pass++; continue; }
        $fail++;
        printf("  FAIL  %-40s\n         expected=%s\n         got     =%s\n",
            $label, var_export($expect, true), var_export($got, true));
    }
    printf("  %d url assertions checked\n", count($urlCases));
}

// ── the JS twin must answer the table identically ───────────────────────────
if (!$legacy) {
    echo "\n=== dest-capture: JS twin parity (lg-shared/lg-destination.js) ===\n";
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        echo "  SKIP  node not on PATH — JS twin NOT gated on this run\n";
        $fail++;   // a skipped parity check is not a pass; make it visible.
        echo "  (counted as a failure so a silent skip can't read as green)\n";
    } else {
        // Only string inputs cross into node; the non-string cases are PHP-only.
        $jsCases = array_values(array_filter($cases, static fn($c) => is_string($c[1])));
        $payload = json_encode(array_map(static fn($c) => ['label' => $c[0], 'in' => $c[1], 'expect' => $c[2]], $jsCases));
        $script  = __DIR__ . '/dest-capture-gate.node.js';
        file_put_contents($script, <<<'JS'
// Harness for the JS twin: fakes just enough of window/location for the module.
const fs = require('fs');
const path = require('path');
const src = fs.readFileSync(path.join(__dirname, '../../lg-shared/lg-destination.js'), 'utf8');
const w = { location: { hostname: 'dev2.loothgroup.com', pathname: '/hub/gear/some-thread/', search: '?highlight=42' } };
global.window = w;
new Function('window', src + '\nreturn window;')(w);
const cases = JSON.parse(process.argv[2]);
let fail = 0, pass = 0;
for (const c of cases) {
  const got = w.lgDest.capture(c.in);
  if (got === c.expect) { pass++; continue; }
  fail++;
  console.log(`  FAIL  ${c.label.padEnd(30)} expected=${JSON.stringify(c.expect)} got=${JSON.stringify(got)}`);
}
const here = w.lgDest.here();
if (here !== '/hub/gear/some-thread/?highlight=42') { fail++; console.log(`  FAIL  here() got=${JSON.stringify(here)}`); } else pass++;
if (w.lgDest.loginUrl('') !== '/wp-login.php') { fail++; console.log('  FAIL  bare loginUrl'); } else pass++;
if (w.lgDest.loginUrl('//evil.example') !== '/wp-login.php') { fail++; console.log('  FAIL  hostile loginUrl'); } else pass++;
console.log(`  ${pass} passed, ${fail} failed (JS twin)`);
process.exit(fail ? 1 : 0);
JS);
        $out = [];
        $rc  = 0;
        exec(escapeshellcmd($node) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg((string) $payload) . ' 2>&1', $out, $rc);
        echo implode("\n", $out) . "\n";
        @unlink($script);
        if ($rc !== 0) $fail++;
    }
}

echo "\n";
if ($fail > 0) {
    echo $legacy
        ? "###### RED BASELINE CONFIRMED — the pre-lane guard fails {$fail} of these ######\n"
        : "###### dest-capture GATE RED — do not push ######\n";
    exit($legacy ? 0 : 1);   // --legacy is EXPECTED to be red; it exits 0 so the
                             // runner can record the baseline without failing.
}
echo $legacy
    ? "###### UNEXPECTED: the legacy guard passed the table. Re-check the baseline. ######\n"
    : "###### dest-capture GATE GREEN ######\n";
exit($legacy ? 1 : 0);
