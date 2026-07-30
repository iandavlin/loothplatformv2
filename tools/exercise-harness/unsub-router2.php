<?php
/**
 * Loopback front controller — FAITHFUL LOAD ORDER variant.
 *
 * Identical to unsub-router.php except the branch mu-plugin is loaded AS A
 * MU-PLUGIN, by pointing WPMU_PLUGIN_DIR at a farm that symlinks every real dev2
 * mu-plugin (same final targets, so __DIR__ resolves exactly as it does today)
 * plus the branch's lg-discussion-unsub.php.
 *
 * WP only defines WPMU_PLUGIN_DIR when it is not already defined
 * (wp-includes/default-constants.php), so defining it here wins — and the plugin
 * then registers its hooks at the real point in wp-settings.php, BEFORE regular
 * plugins such as BuddyPress. That is the ordering question under test.
 */

define('WPMU_PLUGIN_DIR', '/tmp/<lane>-exercise/mu');
define('WPMU_PLUGIN_URL', 'https://dev2.loothgroup.com/wp-content/mu-plugins');

$_SERVER['HTTP_HOST'] = 'dev2.loothgroup.com';
$_SERVER['HTTPS']     = 'on';
if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);

require '/var/www/dev/wp-load.php';

if (isset($_GET['__order'])) {
    header('Content-Type: text/plain');
    global $wp_filter;
    foreach (($wp_filter['template_redirect']->callbacks[10] ?? []) as $id => $c) {
        $f = $c['function'];
        echo is_string($f) ? $f : (is_array($f) ? 'array' : 'closure'), "\n";
    }
    exit;
}

do_action('template_redirect');

http_response_code(204);
echo "PLUGIN DID NOT CLAIM THIS PATH\n";
