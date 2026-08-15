#!/usr/bin/env php
<?php
/**
 * stripe-broker.php — the lg-secrets-helper pattern, applied to Stripe keys.
 *
 * WHY THIS EXISTS. Ian's workaround question. Key material was ending up in
 * conversations because the only way to answer "is this key alive?" was to read
 * it — which meant a model tier that can read secrets had to be the one holding
 * the conversation, and work bounced between tiers to get a key looked at. This
 * moves the key operations behind a tool, so ANY tier (Fable keeper included)
 * can drive them while the key material never enters the transcript.
 *
 *   stripe-broker.php list     [--actor=NAME]
 *   stripe-broker.php validate [--actor=NAME]           # read-only vs Stripe
 *   stripe-broker.php status   [--actor=NAME]
 *   stripe-broker.php wire <candidate> [--actor=NAME]
 *
 * THE HARD RULES, and each is enforced rather than documented:
 *
 *   1. NO SECRET REACHES stdout OR stderr, EVER. Everything is masked to
 *      prefix-8 + length + sha8. As a second line of defence every byte this
 *      script prints goes through redact(), which strips any known key value
 *      from any string — including text that came back from a subprocess or an
 *      API error, which is where a leak would actually come from.
 *   2. IT WILL NOT WIRE A LIVE KEY before cutover. Checked on the sk_live_ /
 *      rk_live_ prefix, and released only by an explicit cutover marker — so
 *      the rule survives the day it is meant to stop applying, rather than
 *      being commented out in a hurry.
 *   3. EVERY INVOCATION IS AUDITED, including refusals and failures.
 *
 * TWO MECHANICAL POINTS THAT MATTER MORE THAN THEY LOOK:
 *   - The key is never an argv element. `wp option update` reads the value from
 *     STDIN, and Stripe is called through PHP curl with CURLOPT_USERPWD — so
 *     the key never appears in `ps`, where any user on the box could read it.
 *   - validate() only ever performs `GET /v1/account`. It creates nothing,
 *     charges nothing and changes nothing; it is the cheapest question that
 *     distinguishes alive from dead and test from live.
 */

declare(strict_types=1);

const WP_PATH  = '/var/www/dev';
const WP_USER  = 'looth-dev';
const AUDIT    = '/home/ubuntu/.stripe-broker-audit.log';

/**
 * Where the lifecycle actually reads its key from.
 *
 * Overridable ONLY from the process environment, which a web request cannot
 * set — and it exists for one reason, learned the hard way. Proving the
 * live-key guard works means DISABLING it and checking the bad thing happens;
 * with a fixed target, that experiment wires a live payments key into the real
 * config. It did, for about 45 seconds, on 2026-08-15. Snapshotting the file
 * under test is not enough when the code under test writes somewhere ELSE: the
 * test has to write somewhere disposable.
 */
function wire_target(): string
{
    $t = getenv('STRIPE_BROKER_TARGET');
    return ($t !== false && $t !== '') ? $t : 'lgms_stripe_secret_key';
}

/** The marker that releases rule 2. Absent/falsey = pre-cutover = no live keys. */
const CUTOVER_OPT = 'lgms_stripe_cutover_done';

/**
 * The candidate registry — every WP option on this box known to hold a Stripe
 * key. Names are not secret; values never leave this process unmasked.
 */
const CANDIDATES = [
    'sandbox'        => ['opt' => 'pmpro_sandbox_stripe_connect_secretkey',      'note' => 'old membership plugin, sandbox secret'],
    'sandbox-pub'    => ['opt' => 'pmpro_sandbox_stripe_connect_publishablekey', 'note' => 'old membership plugin, sandbox publishable'],
    'live'           => ['opt' => 'pmpro_live_stripe_connect_secretkey',         'note' => 'old membership plugin, LIVE secret — rotation owed'],
    'live-pub'       => ['opt' => 'pmpro_live_stripe_connect_publishablekey',    'note' => 'old membership plugin, LIVE publishable'],
    'dce-test'       => ['opt' => 'dce_stripe_api_secret_key_test',              'note' => 'dynamic-content plugin, test slot'],
    'elementor-test' => ['opt' => 'elementor_pro_stripe_test_secret_key',        'note' => 'elementor pro, test slot'],
    'wired'          => ['opt' => 'lgms_stripe_secret_key',                                    'note' => 'what our lifecycle reads today'],
];

/* ---------------------------------------------------------------------- *
 * Output discipline
 * ---------------------------------------------------------------------- */

/** Every key value seen this run, so redact() can strip it from anything. */
$GLOBALS['SEEN'] = [];

/** Strip any known secret from a string. The last line of defence. */
function redact(string $s): string
{
    foreach ($GLOBALS['SEEN'] as $secret) {
        if ($secret !== '' && strlen($secret) >= 12) {
            $s = str_replace($secret, '[REDACTED]', $s);
        }
    }
    // Belt and braces: any Stripe-shaped token at all, even one we never stored.
    return (string) preg_replace('/\b((?:sk|rk|pk)_(?:test|live)_)[A-Za-z0-9]{12,}/', '$1[REDACTED]', $s);
}

function out(array $payload, int $code = 0): void
{
    fwrite(STDOUT, redact(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) . "\n");
    exit($code);
}

function fail(string $msg, int $code = 1): void { out(['ok' => false, 'error' => redact($msg)], $code); }

function audit(string $actor, string $action, string $subject, string $result): void
{
    @file_put_contents(AUDIT, sprintf("%s actor=%s action=%s subject=%s result=%s\n",
        gmdate('c'), $actor ?: '-', $action, $subject, $result), FILE_APPEND | LOCK_EX);
    @chmod(AUDIT, 0600);
}

/* ---------------------------------------------------------------------- *
 * Masking
 * ---------------------------------------------------------------------- */

/**
 * A key, described without disclosing it.
 *
 * The 8-char prefix is deliberate and safe: for Stripe it is exactly
 * "sk_test_" / "sk_live_" — the MODE, which is the one thing an operator needs
 * and which reveals nothing. sha8 lets two stores be compared for sameness
 * without either being read.
 */
function mask(?string $v): array
{
    if ($v === null || $v === '') {
        return ['present' => false, 'len' => 0, 'prefix' => '', 'sha8' => '', 'mode' => 'absent'];
    }
    $prefix = substr($v, 0, 8);
    $mode   = 'unknown';
    if (preg_match('/^(sk|rk|pk)_test_/', $v)) { $mode = 'test'; }
    if (preg_match('/^(sk|rk|pk)_live_/', $v)) { $mode = 'live'; }
    return [
        'present' => true,
        'len'     => strlen($v),
        'prefix'  => $prefix,
        'sha8'    => substr(hash('sha256', $v), 0, 8),
        'mode'    => $mode,
        'secret'  => str_starts_with($v, 'sk_') || str_starts_with($v, 'rk_'),
    ];
}

/* ---------------------------------------------------------------------- *
 * Reading and writing, without the key ever hitting argv
 * ---------------------------------------------------------------------- */

function wp_read_option(string $name): ?string
{
    $cmd = sprintf('sudo -u %s -H wp --path=%s option get %s 2>/dev/null',
        escapeshellarg(WP_USER), escapeshellarg(WP_PATH), escapeshellarg($name));
    $val = shell_exec($cmd);
    if ($val === null) { return null; }
    $val = trim((string) preg_replace('/^PHP Warning:.*$/m', '', $val));
    if ($val === '') { return null; }
    $GLOBALS['SEEN'][] = $val;          // so redact() can strip it from anything
    return $val;
}

/** Writes via STDIN so the value is never an argv element (never in `ps`). */
function wp_write_option(string $name, string $value): bool
{
    $cmd = sprintf('sudo -u %s -H wp --path=%s option update %s 2>/dev/null',
        escapeshellarg(WP_USER), escapeshellarg(WP_PATH), escapeshellarg($name));
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return false; }
    fwrite($pipes[0], $value);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return proc_close($p) === 0;
}

/* ---------------------------------------------------------------------- *
 * Stripe, read-only
 * ---------------------------------------------------------------------- */

/**
 * GET /v1/account with the key. Creates nothing, changes nothing.
 * The key goes in via CURLOPT_USERPWD — never a command line.
 */
function stripe_whoami(string $key): array
{
    $ch = curl_init('https://api.stripe.com/v1/account');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $key . ':',
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if (!is_string($body) || $body === '') {
        return ['alive' => false, 'why' => $err !== '' ? 'network: ' . $err : 'empty response'];
    }
    $d = json_decode($body, true);
    if (!is_array($d)) { return ['alive' => false, 'why' => 'unparseable response']; }
    if (isset($d['error'])) {
        return ['alive' => false, 'why' => (string) ($d['error']['type'] ?? 'error') . ': '
              . substr((string) ($d['error']['message'] ?? ''), 0, 120)];
    }
    return [
        'alive'    => true,
        'account'  => (string) ($d['id'] ?? ''),
        'name'     => (string) (($d['settings']['dashboard']['display_name'] ?? null) ?: ($d['business_profile']['name'] ?? '')),
        'country'  => (string) ($d['country'] ?? ''),
        'currency' => (string) ($d['default_currency'] ?? ''),
    ];
}

/* ---------------------------------------------------------------------- *
 * Subcommands
 * ---------------------------------------------------------------------- */

function cmd_list(string $actor): void
{
    $rows = [];
    foreach (CANDIDATES as $id => $c) { $rows[] = ['id' => $id, 'option' => $c['opt'], 'note' => $c['note']]; }
    audit($actor, 'list', '-', 'ok');
    out(['ok' => true, 'candidates' => $rows]);
}

function cmd_validate(string $actor): void
{
    $rows = [];
    foreach (CANDIDATES as $id => $c) {
        $v = wp_read_option($c['opt']);
        $m = mask($v);
        $row = ['id' => $id, 'option' => $c['opt'], 'note' => $c['note']] + $m;

        if ($m['present'] && ($m['secret'] ?? false)) {
            $w = stripe_whoami((string) $v);
            $row['verdict'] = $w['alive'] ? 'ALIVE' : 'DEAD';
            if ($w['alive']) {
                $row += ['account' => $w['account'], 'account_name' => $w['name'],
                         'country' => $w['country'], 'currency' => $w['currency']];
            } else {
                $row['why'] = $w['why'];
            }
        } else {
            $row['verdict'] = $m['present'] ? 'not-a-secret-key' : 'ABSENT';
        }
        $rows[] = $row;
        audit($actor, 'validate', $id, (string) $row['verdict']);
    }
    out(['ok' => true, 'checked' => count($rows), 'candidates' => $rows]);
}

function cmd_status(string $actor): void
{
    $wired  = wp_read_option(wire_target());
    $wm     = mask($wired);
    $source = null;
    if ($wm['present']) {
        foreach (CANDIDATES as $id => $c) {
            if ($c['opt'] === wire_target()) { continue; }
            $m = mask(wp_read_option($c['opt']));
            if (($m['sha8'] ?? '') !== '' && $m['sha8'] === $wm['sha8']) { $source = $id; break; }
        }
    }
    $cut = wp_read_option(CUTOVER_OPT);
    audit($actor, 'status', wire_target(), $wm['present'] ? 'wired:' . $wm['mode'] : 'unwired');
    out(['ok' => true, 'wired_into' => wire_target(), 'key' => $wm,
         'matches_candidate' => $source,
         'cutover_done' => ($cut === '1' || $cut === 'true'),
         'live_wiring_allowed' => ($cut === '1' || $cut === 'true')]);
}

function cmd_wire(string $actor, string $which): void
{
    if (!isset(CANDIDATES[$which])) {
        audit($actor, 'wire', $which, 'refused:unknown-candidate');
        fail('unknown candidate: ' . $which . ' (try `list`)');
    }
    $opt = CANDIDATES[$which]['opt'];
    if ($opt === wire_target()) {
        audit($actor, 'wire', $which, 'refused:target-is-source');
        fail('that candidate IS the wiring target; nothing to do');
    }

    $v = wp_read_option($opt);
    $m = mask($v);
    if (!$m['present']) {
        audit($actor, 'wire', $which, 'refused:absent');
        fail('candidate ' . $which . ' is empty on this box — nothing to wire');
    }
    if (!($m['secret'] ?? false)) {
        audit($actor, 'wire', $which, 'refused:not-a-secret-key');
        fail('candidate ' . $which . ' is not a secret key (prefix ' . $m['prefix'] . ')');
    }

    // RULE 2 — the whole point of the tool.
    if ($m['mode'] === 'live') {
        $cut = wp_read_option(CUTOVER_OPT);
        if (!($cut === '1' || $cut === 'true')) {
            audit($actor, 'wire', $which, 'REFUSED:live-key-pre-cutover');
            fail('REFUSED: ' . $which . ' is a LIVE key (' . $m['prefix'] . ') and cutover has not happened. '
               . 'Cutover is Ian\'s, personally. Set ' . CUTOVER_OPT . ' only when he has done it.', 2);
        }
    }

    // Validate before wiring: wiring a dead key is a worse outcome than refusing.
    $w = stripe_whoami((string) $v);
    if (!$w['alive']) {
        audit($actor, 'wire', $which, 'refused:dead-key');
        fail('candidate ' . $which . ' does not authenticate (' . $w['why'] . ') — not wiring a dead key');
    }

    if (!wp_write_option(wire_target(), (string) $v)) {
        audit($actor, 'wire', $which, 'FAILED:write');
        fail('the write to ' . wire_target() . ' failed — nothing changed');
    }

    $after = mask(wp_read_option(wire_target()));
    $okSame = ($after['sha8'] ?? '') === $m['sha8'];
    audit($actor, 'wire', $which, $okSame ? 'ok:' . $m['mode'] : 'FAILED:verify');
    out(['ok' => $okSame, 'wired' => $which, 'into' => wire_target(),
         'key' => $after, 'account' => $w['account'], 'account_name' => $w['name'],
         'verified' => $okSame], $okSame ? 0 : 1);
}

/* ---------------------------------------------------------------------- */

$args  = array_slice($_SERVER['argv'], 1);
$actor = '-';
$pos   = [];
foreach ($args as $a) {
    if (str_starts_with($a, '--actor=')) { $actor = substr($a, 8); continue; }
    $pos[] = $a;
}
$cmd = $pos[0] ?? '';

switch ($cmd) {
    case 'list':     cmd_list($actor); break;
    case 'validate': cmd_validate($actor); break;
    case 'status':   cmd_status($actor); break;
    case 'wire':
        if (!isset($pos[1])) { audit($actor, 'wire', '-', 'refused:no-candidate'); fail('usage: wire <candidate>'); }
        cmd_wire($actor, $pos[1]);
        break;
    default:
        fail("usage: stripe-broker.php list|validate|status|wire <candidate> [--actor=NAME]");
}
