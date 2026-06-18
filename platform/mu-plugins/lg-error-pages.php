<?php
/**
 * Plugin Name: LG Error Pages
 * Description: WP permalink misses render the standalone branded 404 from
 *              /srv/lg-shared/errors/ instead of the BB-theme 404 template
 *              (Ian 2026-06-12). REST/admin untouched — template path only.
 */

defined('ABSPATH') || exit;

add_action('template_redirect', function () {
    if (!is_404()) {
        return;
    }
    $page = '/srv/lg-shared/errors/404.html';
    if (!is_readable($page)) {
        return; // shared page missing — fall through to the theme template
    }
    status_header(404);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    readfile($page);
    exit;
}, 0);
