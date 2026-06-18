<?php
/**
 * Plugin Name: LG Layout v2
 * Description: JSON-driven article layouts for Looth Group CPTs. Successor to lg-layout. Cascade-layer CSS, manifest-driven blocks, data-driven editor.
 * Version: 0.1.67
 * Author: Looth Group
 * Requires PHP: 8.1
 *
 * Architecture: docs/ARCHITECTURE.md (read first)
 * Block contract: docs/MANIFEST.md
 * This file is the WordPress entry point. The engine itself lives in src/
 * and runs identically in CLI (bin/render-test.php) and in WP — this entry
 * just bootstraps WP-specific glue.
 *
 * Coexistence with v1 (lg-layout): both plugins can be active simultaneously.
 *   - v1 reads post meta `_lg_layout`
 *   - v2 reads post meta `_lg_layout_v2`
 *   A post with v2 meta is served by v2; one without falls back to v1.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) exit;

/* ── Constants ─────────────────────────────────────────────────────── */
define('LG_LAYOUT_V2_VERSION',         '0.1.67');
define('LG_LAYOUT_V2_FILE',            __FILE__);
define('LG_LAYOUT_V2_DIR',             plugin_dir_path(__FILE__));
define('LG_LAYOUT_V2_URL',             plugin_dir_url(__FILE__));
define('LG_LAYOUT_V2_BLOCKS_DIR',      LG_LAYOUT_V2_DIR . 'blocks');
define('LG_LAYOUT_V2_META_KEY',        '_lg_layout_v2');
define('LG_LAYOUT_V2_RENDERED_AT_META','_lg_layout_v2_rendered_at');
define('LG_LAYOUT_V2_STYLE_OPTION',    'lg_layout_v2_block_styles');
define('LG_LAYOUT_V2_BRAND_OPTION',    'lg_layout_v2_brand_palette');
define('LG_LAYOUT_V2_BUNDLE_CSS',      'assets/lg-layout-v2-bundle.css');

/* Isolation toggle. When true, Isolate dequeues every theme/plugin asset on
   v2-managed posts and demotes survivors into `layer(legacy)` so the v2
   cascade architecture actually wins. Flip to false to fall back to the
   unisolated state for debugging or if a plugin proves load-bearing. */
if (!defined('LG_LAYOUT_V2_ISOLATE')) define('LG_LAYOUT_V2_ISOLATE', true);

/* ── Autoloader ────────────────────────────────────────────────────── */
require_once LG_LAYOUT_V2_DIR . 'src/Autoload.php';

/* ── Boot ──────────────────────────────────────────────────────────── */
\LG\LayoutV2\Manifest::configure(LG_LAYOUT_V2_BLOCKS_DIR);
\LG\LayoutV2\Plugin::boot();

/* ── Lifecycle hooks ───────────────────────────────────────────────── */
register_activation_hook(__FILE__, [\LG\LayoutV2\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\LG\LayoutV2\Plugin::class, 'deactivate']);
