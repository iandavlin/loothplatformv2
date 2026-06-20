<?php
/**
 * Plugin Name: LG Host Options From Env
 * Description: Drives host-derived WP options from /etc/looth/env (LG_PUBLIC_HOST)
 *   so a box standup needs zero per-host DB pins: siteurl, home, the Patreon OAuth
 *   redirect, and the BuddyBoss login/logout/register redirects. Falls back to the
 *   stored option when the env file is absent.
 */
if (!defined('ABSPATH')) return;
(static function () {
    $host = '';
    if (is_file('/srv/lg-shared/lg-env.php')) {
        require_once '/srv/lg-shared/lg-env.php';
        $e = function_exists('lg_env') ? lg_env() : [];
        $host = (string)($e['host'] ?? '');
    }
    if ($host === '') return;                 // no env host -> leave DB values
    $base = 'https://' . $host;
    add_filter('pre_option_siteurl',           static fn() => $base);
    add_filter('pre_option_home',              static fn() => $base);
    add_filter('pre_option_lgpo_redirect_uri', static fn() => $base . '/patreon-callback');

    // BuddyBoss stores ABSOLUTE post-auth redirect URLs (live host in a DB dump),
    // which bounce login/logout/register to the live domain on a non-live box.
    // Rewrite ONLY the host to the env host, preserving the path. Idempotent on live.
    $rehost = static function ($v) use ($host) {
        return (is_string($v) && $v !== '')
            ? preg_replace('#^https?://(www\.)?loothgroup\.com#i', 'https://' . $host, $v)
            : $v;
    };
    foreach (['bb-custom-login-redirection', 'bb-custom-logout-redirection', 'bb-custom-register-redirection'] as $opt) {
        add_filter("option_$opt", $rehost);
    }
})();
