<?php
/**
 * LG App: The Roadman Shop Planner
 *
 * Drag-and-drop shop layout planner for luthiers by J. Roadman.
 * Self-registers with the LGApps framework.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ─── Register assets ─── */

add_action( 'wp_enqueue_scripts', function() {
    $base_url = LGAPPS_URL . 'apps/shop-planner/assets/';

    wp_register_style(
        'lgapps-shop-planner',
        $base_url . 'shop-planner.css',
        [ 'lgapps-base' ],
        LGAPPS_VERSION
    );

    wp_register_script(
        'jspdf',
        'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
        [],
        '2.5.1',
        true
    );

    wp_register_script(
        'lgapps-shop-planner',
        $base_url . 'shop-planner.js',
        [ 'jspdf' ],
        LGAPPS_VERSION,
        true
    );
});

/* ─── Register with framework ─── */

add_action( 'plugins_loaded', function() {
    LGApps_Registry::register( 'shop-planner', [
        'title'        => 'The Roadman Shop Planner',
        'description'  => 'Drag-and-drop shop layout planner for luthiers by J. Roadman.',
        'scripts'      => [ 'jspdf', 'lgapps-shop-planner' ],
        'styles'       => [ 'lgapps-shop-planner' ],
        'render_modal' => 'lgapps_shop_planner_render_modal',
        'shortcode'    => 'shop_planner',
    ] );
}, 20 ); // priority 20 so it runs after the main plugin's plugins_loaded at default (10)

/* ─── Modal markup ─── */

function lgapps_shop_planner_render_modal() {
    // Markup lives in partials/planner-markup.php so the standalone front
    // controller (webroot/shop-layout-planner.php) renders the SAME bytes.
    // __DIR__ is safe here: the partial travels with this file inside the repo.
    if ( ! defined( 'LGAPPS_PARTIAL' ) ) define( 'LGAPPS_PARTIAL', 1 );
    require __DIR__ . '/partials/planner-markup.php';
}
