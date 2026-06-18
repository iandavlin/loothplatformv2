<?php
/**
 * Plugin Name: LG Siteurl From Env
 * Description: Drives WP siteurl/home from /etc/looth/env (LG_PUBLIC_HOST) so a
 *   box standup never needs a per-host DB search-replace. Falls back to the
 *   stored option when the env file is absent (graceful — behaves as before).
 */
if (!defined('ABSPATH')) return;
(static function () {
    $host = '';
    if (is_file('/srv/lg-shared/lg-env.php')) {
        require_once '/srv/lg-shared/lg-env.php';
        $e = function_exists('lg_env') ? lg_env() : [];
        $host = (string)($e['host'] ?? '');
    }
    if ($host === '') return;            // no env host -> leave DB value untouched
    $url = 'https://' . $host;
    add_filter('pre_option_siteurl', static fn() => $url);
    add_filter('pre_option_home',    static fn() => $url);
})();
