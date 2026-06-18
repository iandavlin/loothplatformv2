<?php
/**
 * Plugin Name: LG Legacy Import
 * Description: Convert legacy ACF-driven post-imgcap articles (the
 *   img_cap_images_and_captions_repeater + Elementor era) into lg-layout-v2
 *   layout JSON. WP-CLI only — read-only by default, --apply writes the
 *   _lg_layout_v2 meta into the post directly so v2 takes over the render.
 * Version: 0.2.3
 * Author: Looth Group
 * License: Proprietary
 *
 * The converter walks one ACF repeater row at a time and emits a v2 block
 * sequence per row based on the row's four toggle flags. See README.md
 * (next to this file) for the row → blocks mapping table.
 *
 * Loadable on live for testing. Without `--apply` it never mutates a post.
 */

if (!defined('ABSPATH')) exit;

if (!defined('LG_LEGACY_IMPORT_DIR')) define('LG_LEGACY_IMPORT_DIR', plugin_dir_path(__FILE__));
if (!defined('LG_LEGACY_IMPORT_VERSION')) define('LG_LEGACY_IMPORT_VERSION', '0.2.3');

require_once LG_LEGACY_IMPORT_DIR . 'src/Extractors/BaseExtractor.php';
foreach (['BaselineExtractor','ImgCapExtractor','VideoExtractor','LoothprintExtractor','LoothcutExtractor','ShortyExtractor','UsefulLinkExtractor'] as $cls) {
    require_once LG_LEGACY_IMPORT_DIR . "src/Extractors/{$cls}.php";
}
require_once LG_LEGACY_IMPORT_DIR . 'src/Extractor.php';
require_once LG_LEGACY_IMPORT_DIR . 'src/Mapper.php';
require_once LG_LEGACY_IMPORT_DIR . 'src/Cli.php';
require_once LG_LEGACY_IMPORT_DIR . 'src/EditorButton.php';

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('lg-legacy', LG_Legacy_Import\Cli::class);
}

if (is_admin()) {
    LG_Legacy_Import\EditorButton::boot();
}
