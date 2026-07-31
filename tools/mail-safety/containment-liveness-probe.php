<?php
/**
 * Mail-containment liveness probe (backlog #15).
 *
 * Loads the REAL, UNMODIFIED lg-dev-mail-containment.php inside a minimal WP
 * stub and reports whether it CONTAINS (short-circuits pre_wp_mail) or PROCEEDS.
 *
 * WHY A PROBE AND NOT A UNIT TEST: the failure mode is "mail silently swallowed
 * and reported as SENT". The containment filter returns true even when mailpit
 * is unreachable (see lg-weekly-digest/dev/build-inbox-test.php:9-10), so a test
 * that merely asserts wp_mail() succeeded is a guaranteed false PASS. The only
 * honest question is: did the plugin register the short-circuit, and does that
 * short-circuit claim the send? This probe answers exactly that.
 *
 * The environment is NOT faked in PHP — the runner bind-mounts a real
 * /etc/looth/env (byte-copied from the box being simulated) into a private mount
 * namespace, so the plugin reads the same path with the same reader it uses in
 * production. Nothing here is a stub except WordPress itself.
 *
 * Emits one line of JSON. Usage: see run-liveness-test.sh.
 */

define('ABSPATH', '/var/www/dev/');
define('WPINC', 'wp-includes');
define('WP_CONTENT_DIR', getenv('PROBE_LOG_DIR') ?: sys_get_temp_dir());

if (getenv('PROBE_DEFINE_LG_ENV') !== false && getenv('PROBE_DEFINE_LG_ENV') !== '') {
    define('LG_ENV', getenv('PROBE_DEFINE_LG_ENV'));
}
if (getenv('PROBE_HTTP_HOST') !== false && getenv('PROBE_HTTP_HOST') !== '') {
    $_SERVER['HTTP_HOST'] = getenv('PROBE_HTTP_HOST');
}

$GLOBALS['probe_filters'] = [];
function add_filter($tag, $cb, $prio = 10, $args = 1) {
    $GLOBALS['probe_filters'][$tag][] = $cb;
    return true;
}
function is_email($addr) {
    return (bool) filter_var((string) $addr, FILTER_VALIDATE_EMAIL);
}

$plugin = getenv('PROBE_PLUGIN')
    ?: dirname(__DIR__, 2) . '/platform/mu-plugins/lg-dev-mail-containment.php';

require $plugin;

$registered = !empty($GLOBALS['probe_filters']['pre_wp_mail']);
$returned   = null;
$threw      = null;

if ($registered) {
    // Exercise the short-circuit exactly as wp_mail() would.
    $cb = $GLOBALS['probe_filters']['pre_wp_mail'][0];
    try {
        $returned = $cb(null, [
            'to'      => getenv('PROBE_TO') ?: 'member@example.com',
            'subject' => getenv('PROBE_SUBJECT') ?: 'LG mail-safety probe',
            'message' => 'If you can read this in mailpit, the send was SWALLOWED.',
            'headers' => '',
        ]);
    } catch (\Throwable $e) {
        $threw = $e->getMessage();
    }
}

// CONTAIN = the plugin claimed the send (returned non-null). PROCEED = it left
// delivery alone (no filter, or the filter passed the value through untouched).
$contained = $registered && $returned !== null;

echo json_encode([
    'verdict'                    => $contained ? 'CONTAIN' : 'PROCEED',
    'filter_registered'          => $registered,
    'filter_returned'            => $returned,
    'fluentmail_simulate_emails' => defined('FLUENTMAIL_SIMULATE_EMAILS')
                                      ? (bool) FLUENTMAIL_SIMULATE_EMAILS : false,
    'etc_looth_env'              => is_readable('/etc/looth/env')
                                      ? trim((string) file_get_contents('/etc/looth/env'))
                                      : '(unreadable/absent)',
    'http_host'                  => $_SERVER['HTTP_HOST'] ?? '(none)',
    'running_as_uid'             => posix_geteuid(),
    'running_as_user'            => (posix_getpwuid(posix_geteuid())['name'] ?? '?'),
    'threw'                      => $threw,
]), "\n";
