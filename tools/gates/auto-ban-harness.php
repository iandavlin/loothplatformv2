<?php
/**
 * auto-ban-harness.php — gate 84's WordPress stand-in.
 *
 * Runs the REAL platform/mu-plugins/lg-login-monitor.php and lg-auto-ban.php —
 * the files on this branch, required by path — against a stubbed WordPress, so
 * the gate measures shipped code rather than a paraphrase of it. No database, no
 * network, no web server, no WordPress install. Everything the plugins touch
 * (transients, options, users, mail, the store directory, the config directory)
 * is in memory or in a temp dir the caller owns.
 *
 * ⚠️ THE FLAG IS FLIPPED THROUGH ITS REAL CHANNEL. The scenario writes an actual
 * platform/config/auto-ban.php (and .local.php) into a temp config dir and the
 * plugin's own lg_ab_config() reads it. A gate that toggled a flag by a route the
 * runtime does not read would pass green against a mechanism that had been
 * deleted — that has happened in this repo.
 *
 * Usage:  php auto-ban-harness.php <scenario.json>   -> one JSON object on stdout
 */

/* ── scenario ─────────────────────────────────────────────────────────────── */
$scn = json_decode(file_get_contents($argv[1]), true);
if (!is_array($scn)) { fwrite(STDERR, "bad scenario\n"); exit(2); }

define('ABSPATH', '/nonexistent/wp/');
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);

define('LG_AB_DIR', $scn['state_dir']);
define('LG_AB_CONFIG_DIR', $scn['config_dir']);
define('LG_AB_CF_RANGES', $scn['cf_ranges']);

$GLOBALS['H'] = [
    'hooks' => [], 'filters' => [], 'transients' => [], 'options' => [],
    'mails' => [], 'signals' => [], 'errors' => [], 'menus' => [],
    'redirect' => null, 'died' => null, 'echo' => '',
];

/* ── the stubs ────────────────────────────────────────────────────────────── */
class LgAbHalt extends \Exception {}

function add_action($h, $cb, $p = 10, $a = 1) { $GLOBALS['H']['hooks'][$h][] = $cb; }
function do_action($h, ...$args) {
    if ($h === 'lg_login_stuffing_detected') { $GLOBALS['H']['signals'][] = $args[0]; }
    foreach ($GLOBALS['H']['hooks'][$h] ?? [] as $cb) { $cb(...$args); }
}
function add_filter($h, $cb, $p = 10, $a = 1) { $GLOBALS['H']['filters'][$h][] = $cb; }
function apply_filters($h, $v, ...$rest) {
    foreach ($GLOBALS['H']['filters'][$h] ?? [] as $cb) { $v = $cb($v, ...$rest); }
    return $v;
}
function get_transient($k) { return $GLOBALS['H']['transients'][$k] ?? false; }
function set_transient($k, $v, $ttl = 0) { $GLOBALS['H']['transients'][$k] = $v; return true; }
function get_option($k, $d = false) { return $GLOBALS['H']['options'][$k] ?? $d; }
function update_option($k, $v, $auto = null) { $GLOBALS['H']['options'][$k] = $v; return true; }
function is_email($e) { return (bool) filter_var((string) $e, FILTER_VALIDATE_EMAIL); }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . ltrim((string) $p, '/'); }
function wp_mail($to, $subject, $body, $headers = []) {
    $GLOBALS['H']['mails'][] = ['to' => $to, 'subject' => $subject, 'body' => $body, 'headers' => $headers];
    return true;
}
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }

class WP_User {
    public $ID; public $user_login; public $user_email; public $display_name;
    public function __construct($id, $login, $email, $name = '') {
        $this->ID = $id; $this->user_login = $login; $this->user_email = $email;
        $this->display_name = $name !== '' ? $name : $login;
    }
}
function get_user_by($field, $value) {
    foreach ($GLOBALS['H']['users'] ?? [] as $u) {
        if ($field === 'login' && $u->user_login === $value) return $u;
        if ($field === 'email' && $u->user_email === $value) return $u;
    }
    return false;
}

/* admin-side stubs — only what the dash actually calls */
function add_menu_page($pt, $mt, $cap, $slug, $fn = '', $icon = '', $pos = null) {
    $GLOBALS['H']['menus'][] = ['title' => $pt, 'cap' => $cap, 'slug' => $slug, 'fn' => $fn, 'icon' => $icon];
    return $slug;
}
function current_user_can($cap) { return in_array($cap, $GLOBALS['H']['caps'] ?? [], true); }
function wp_die($m = '') { $GLOBALS['H']['died'] = (string) $m; throw new LgAbHalt('wp_die'); }
function wp_create_nonce($a = '') { return 'nonce-' . md5((string) $a); }
function check_admin_referer($action = '', $qa = '_wpnonce') {
    $sent = $_POST[$qa] ?? '';
    if ($sent !== wp_create_nonce($action)) { wp_die('nonce'); }
    return 1;
}
function wp_get_current_user() { return $GLOBALS['H']['current_user'] ?? new WP_User(0, '', ''); }
function wp_safe_redirect($url, $status = 302) {
    $GLOBALS['H']['redirect'] = $url;
    throw new LgAbHalt('redirect');   // the caller's exit; is never reached
}
function add_query_arg($args, $url = '') {
    $q = http_build_query($args);
    return $url . (strpos($url, '?') === false ? '?' : '&') . $q;
}
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_unslash($s) { return is_string($s) ? stripslashes($s) : $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return (string) $s; }
function wp_date($fmt, $ts = null, $tz = null) { return gmdate($fmt, $ts === null ? time() : (int) $ts); }
function human_time_diff($from, $to = 0) {
    $d = abs(((int) $to ?: time()) - (int) $from);
    if ($d < 3600) return max(1, (int) round($d / 60)) . ' mins';
    if ($d < 86400) return max(1, (int) round($d / 3600)) . ' hours';
    return max(1, (int) round($d / 86400)) . ' days';
}

/* error_log() is a real PHP function; capture it by pointing it at a file. */
ini_set('log_errors', '1');
ini_set('error_log', $scn['state_dir'] . '/php-error.log');

/* ── the code under test, by path, on this branch ─────────────────────────── */
$MU = $scn['mu_dir'];
$GLOBALS['H']['users'] = [];
foreach ($scn['users'] ?? [] as $u) {
    $GLOBALS['H']['users'][] = new WP_User((int) $u['id'], $u['login'], $u['email'], $u['name'] ?? '');
}
$GLOBALS['H']['caps'] = $scn['caps'] ?? ['manage_options'];
if (!empty($scn['current_user'])) {
    $GLOBALS['H']['current_user'] = new WP_User(1, $scn['current_user'], $scn['current_user'] . '@example.test');
}
$GLOBALS['H']['options'] = $scn['options'] ?? [];

require_once $MU . '/lg-auto-ban.php';
require_once $MU . '/lg-login-monitor.php';

// Fire the menu hook so the dash's registration is exercised rather than merely
// declared — an add_action nobody dispatches proves nothing about the page.
do_action('admin_menu');

/* ── drive it ─────────────────────────────────────────────────────────────── */
$_SERVER = array_merge($_SERVER, $scn['server'] ?? []);

$result = ['attempts' => [], 'verbs' => [], 'page' => null];

foreach ($scn['attempts'] ?? [] as $a) {
    if (isset($a['server'])) { foreach ($a['server'] as $k => $v) { $_SERVER[$k] = $v; } }
    lg_login_monitor_wp_failed($a['username']);
    $result['attempts'][] = $a['username'];
}

foreach ($scn['verbs'] ?? [] as $v) {
    $_POST = ['ip' => $v['ip'], '_wpnonce' => $v['nonce'] ?? wp_create_nonce('lg_ab')];
    $GLOBALS['H']['redirect'] = null;
    $GLOBALS['H']['died'] = null;
    try { lg_ab_handle($v['verb']); $out = 'returned'; }
    catch (LgAbHalt $e) { $out = $e->getMessage(); }
    $result['verbs'][] = ['verb' => $v['verb'], 'ip' => $v['ip'], 'outcome' => $out,
                          'redirect' => $GLOBALS['H']['redirect'], 'died' => $GLOBALS['H']['died']];
}

if (!empty($scn['render_page'])) {
    ob_start();
    try { lg_ab_render_page(); } catch (LgAbHalt $e) { /* wp_die */ }
    $result['page'] = ob_get_clean();
}

/* direct calls, for the trust-boundary legs */
foreach ($scn['calls'] ?? [] as $c) {
    $fn = $c['fn'];
    $result['calls'][$fn . ':' . json_encode($c['args'] ?? [])] = $fn(...($c['args'] ?? []));
}

$state_file = rtrim($scn['state_dir'], '/') . '/state.json';
$result['state_exists'] = file_exists($state_file);
$result['state'] = $result['state_exists'] ? json_decode(file_get_contents($state_file), true) : null;
$result['mails'] = $GLOBALS['H']['mails'];
$result['signals'] = $GLOBALS['H']['signals'];
$result['menus'] = $GLOBALS['H']['menus'];
$result['enabled'] = lg_ab_enabled();
$result['config'] = lg_ab_config();
$result['log'] = $GLOBALS['H']['options']['lg_login_monitor_log'] ?? [];
$result['now'] = time();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
