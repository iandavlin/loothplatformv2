<?php
/**
 * Plugin Name: LG Host Options From Env
 * Description: Drives host-derived WP options from /etc/looth/env (LG_PUBLIC_HOST)
 *   so a box standup needs ZERO per-host DB pins: siteurl, home, the content/
 *   upload/plugin/include URLs, the Patreon OAuth redirect, and the BuddyBoss
 *   login/logout/register redirects. Falls back to the stored option when the
 *   env file is absent.
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

    // Host-rewrite: swap a live-host prefix for the env host, preserving the
    // rest of the URL. Idempotent on live AND on already-env-host URLs (the
    // anchored prefix won't match e.g. https://dev2.loothgroup.com).
    $rehost = static function ($v) use ($host) {
        return (is_string($v) && $v !== '')
            ? preg_replace('#^https?://(www\.)?loothgroup\.com#i', 'https://' . $host, $v)
            : $v;
    };

    // WP freezes the WP_CONTENT_URL / WP_PLUGIN_URL constants from the RAW db
    // siteurl during wp-settings.php -- BEFORE mu-plugins load -- so the
    // pre_option_siteurl filter above can't reach content/upload/plugin URLs
    // (a live-cloned DB would keep the live host => broken media on a non-live
    // box). Rewrite the host on the generated URLs instead. This is what lets
    // the box run with NO per-host DB pin of siteurl/home.
    add_filter('content_url',  $rehost);
    add_filter('plugins_url',  $rehost);
    add_filter('includes_url', $rehost);
    add_filter('upload_dir', static function ($u) use ($rehost) {
        foreach (['url', 'baseurl'] as $k) {
            if (isset($u[$k])) $u[$k] = $rehost($u[$k]);
        }
        return $u;
    });

    // BuddyBoss stores ABSOLUTE post-auth redirect URLs (live host in a DB
    // dump), which bounce login/logout/register to the live domain on a
    // non-live box. Rewrite ONLY the host, preserving the path.
    foreach (['bb-custom-login-redirection', 'bb-custom-logout-redirection', 'bb-custom-register-redirection'] as $opt) {
        add_filter("option_$opt", $rehost);
    }
})();
