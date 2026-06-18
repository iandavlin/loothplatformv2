<?php
/**
 * Dash — admin authoring surface for v2 block styles.
 *
 * Walks Manifest::all() and emits one panel per block from its declared vars
 * and defaults. No per-block hand-written HTML — adding a block to the dash
 * is just adding a manifest entry. Plus a brand-palette panel for :root token
 * overrides and a global-defaults panel that applies to every block at low
 * specificity. Save goes to two options:
 *
 *   - lg_layout_v2_block_styles    (_global + per-block container/text/variants/sub_targets)
 *   - lg_layout_v2_brand_palette   (token name → override value)
 *
 * Cascade slot for "global":
 *   @layer dash {
 *     :where(.lg-image, .lg-prose, …) { … }   ← global, zero specificity
 *     .lg-image { … }                          ← per-block, beats global on overlap
 *   }
 *
 * Layer ordering puts global above block-defaults (manifest). Specificity
 * within the dash layer puts per-block above global. No !important.
 *
 * Each per-block panel has an "Inherit Global Defaults" toggle; when on, the
 * block emits no per-block rule (just the global rule applies via :where).
 *
 * Read docs/MANIFEST.md#dash-generation for the contract this surface implements.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class Dash
{
    public const MENU_SLUG    = 'lg-layout-v2';
    public const CAPABILITY   = 'manage_options';
    public const NONCE_ACTION = 'lg_layout_v2_dash_save';
    public const SAVE_ACTION  = 'lg_layout_v2_dash_save';

    /** The "global" option entry uses this key inside lg_layout_v2_block_styles. */
    public const GLOBAL_KEY = '_global';

    /** Canonical container + text vars: the union surfaced in the Global panel.
     *  Every in-tree block declares this set (or a subset) in its manifest. */
    public const CANONICAL_CONTAINER = ['padding', 'margin-block', 'bg', 'border', 'radius', 'shadow', 'gap'];
    public const CANONICAL_TEXT      = ['color', 'link-color', 'font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'text-align'];

    /** Datalist preset sets keyed by ID. Values author-typeable; presets are
     *  hints, not enums — the input still accepts anything. */
    public const PRESETS = [
        'size'        => ['0', '4px', '8px', '12px', '16px', '20px', '24px', '32px', '48px', '64px'],
        'border-line' => ['none', '1px solid', '2px solid', '3px solid', '1px dashed', '2px dashed', '1px dotted'],
        'radius'      => ['0', '4px', '8px', '12px', '16px', '24px', '9999px'],
        'font-size'   => ['12px', '14px', '16px', '18px', '20px', '22px', '24px', '28px', '32px', '40px', '48px'],
        'shadow'      => ['none', 'var(--lg-shadow-soft)', 'var(--lg-shadow-card)', 'var(--lg-shadow-badge)', 'var(--lg-shadow-modal)'],
        'line-height' => ['1', '1.2', '1.4', '1.5', '1.6', '1.8', '2'],
    ];

    /** Border style options. The width-stepper + style-select pair composes
     *  into the line half of the --lg-border composite (`<width> <style>`). */
    public const BORDER_STYLES = [
        ''       => '— inherit —',
        'none'   => 'none',
        'solid'  => 'solid',
        'dashed' => 'dashed',
        'dotted' => 'dotted',
        'double' => 'double',
        'groove' => 'groove',
        'ridge'  => 'ridge',
    ];

    /** Select-field options: real <select> dropdowns. Stored values not in
     *  the preset list are added as one-off options on render so authors
     *  don't lose custom CSS they pasted in. */
    public const SELECTS = [
        'font-weight' => [
            '' => '— inherit —',
            '300' => '300 light',
            '400' => '400 regular',
            '500' => '500 medium',
            '600' => '600 semi',
            '700' => '700 bold',
        ],
        'text-align' => [
            '' => '— inherit —',
            'left' => 'left', 'center' => 'center', 'right' => 'right', 'justify' => 'justify',
        ],
        'font-family' => [
            ''                                                  => '— inherit —',
            "var(--lg-font-sans)"                               => 'Brand sans (Jost)',
            "var(--lg-font-serif)"                              => 'Brand serif (Cormorant)',
            "system-ui, -apple-system, sans-serif"              => 'System sans',
            "Georgia, 'Times New Roman', serif"                 => 'Georgia serif',
            "'Playfair Display', Georgia, serif"                => 'Playfair display',
            "ui-monospace, 'SF Mono', Menlo, monospace"         => 'Monospace',
        ],
        /* 'border' is decomposed in field_border (width stepper + style
           select + color picker); no flat SELECTS entry needed. */
        'shadow' => [
            ''                                  => '— inherit —',
            'none'                              => 'none',
            'var(--lg-shadow-soft)'             => 'Soft (subtle elevation)',
            'var(--lg-shadow-card)'             => 'Card',
            'var(--lg-shadow-badge)'            => 'Badge (dramatic)',
            'var(--lg-shadow-modal)'            => 'Modal (chunky)',
        ],
    ];

    public static function boot(): void
    {
        add_action('admin_menu',                          [self::class, 'register_page']);
        add_action('admin_post_' . self::SAVE_ACTION,     [self::class, 'handle_save']);
        add_action('wp_ajax_lg_v2_get_layout',            [self::class, 'ajax_get_layout']);
        add_action('wp_ajax_lg_v2_preview_css',           [self::class, 'ajax_preview_css']);
    }

    /** AJAX: rebuild the v2 CSS bundle from POSTed form values (in-flight
     *  brand + styles, before save). Used by the dash Preview modal so the
     *  iframe re-styles live as authors tweak fields. Returns raw text/css.
     *  Reuses sanitize_block_entry + sanitize_group so the preview cannot
     *  diverge from what would actually save. */
    public static function ajax_preview_css(): void
    {
        if (!current_user_can(self::CAPABILITY)) wp_die('forbidden', 403);
        $nonce = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die('bad nonce', 403);

        $rawBrand  = isset($_POST['brand'])  && is_array($_POST['brand'])  ? wp_unslash($_POST['brand'])  : [];
        $rawStyles = isset($_POST['styles']) && is_array($_POST['styles']) ? wp_unslash($_POST['styles']) : [];

        $tokens     = Theme::tokens();
        $cleanBrand = [];
        foreach ($tokens as $tokenName => $meta) {
            $v = isset($rawBrand[$tokenName]) ? trim((string) $rawBrand[$tokenName]) : '';
            if ($v === '') continue;
            if (strcasecmp($v, (string) $meta['default']) === 0) continue;
            $cleanBrand[$tokenName] = self::sanitize_css_value($v);
        }

        $manifests = Manifest::all();
        $clean     = [];

        $rawG = is_array($rawStyles[self::GLOBAL_KEY] ?? null) ? $rawStyles[self::GLOBAL_KEY] : [];
        $gC = self::sanitize_group($rawG['container'] ?? [], self::CANONICAL_CONTAINER, []);
        $gT = self::sanitize_group($rawG['text']      ?? [], self::CANONICAL_TEXT,      []);
        $g  = [];
        if ($gC) $g['container'] = $gC;
        if ($gT) $g['text']      = $gT;
        if ($g) $clean[self::GLOBAL_KEY] = $g;

        foreach ($manifests as $name => $m) {
            $rawEntry = is_array($rawStyles[$name] ?? null) ? $rawStyles[$name] : [];
            $entry    = self::sanitize_block_entry($rawEntry, $m);
            if ($entry) $clean[$name] = $entry;
        }

        $brandTokens = Theme::resolve($cleanBrand);
        $css = CssBuilder::build($manifests, $brandTokens, $clean);

        header('Content-Type: text/css; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo $css;
        wp_die();
    }

    /** AJAX: return the stored layout JSON for a given post.
     *  Backs the dash Export tab. Same data the metabox textarea + CLI
     *  exporter use — single source of truth via post meta. */
    public static function ajax_get_layout(): void
    {
        if (!current_user_can(self::CAPABILITY)) wp_send_json_error('forbidden', 403);
        check_ajax_referer('lg_v2_export', 'nonce');
        $postId = (int) ($_GET['post_id'] ?? 0);
        if ($postId <= 0) wp_send_json_error('post_id required', 400);
        $layout = get_post_meta($postId, LG_LAYOUT_V2_META_KEY, true);
        if (!is_array($layout)) wp_send_json_error('no v2 layout stored on this post', 404);
        wp_send_json_success($layout);
    }

    public static function is_dash_screen(string $hook): bool
    {
        return $hook === 'toplevel_page_' . self::MENU_SLUG;
    }

    public static function register_page(): void
    {
        add_menu_page(
            'LG Layout v2', 'LG Layout v2',
            self::CAPABILITY, self::MENU_SLUG,
            [self::class, 'render_page'],
            'dashicons-layout', 59
        );
    }

    /* ── Render ──────────────────────────────────────────────────── */

    public static function render_page(): void
    {
        if (!current_user_can(self::CAPABILITY)) wp_die('Forbidden');

        $manifests = Manifest::all();
        $tokens    = Theme::tokens();
        $brandRaw  = get_option(LG_LAYOUT_V2_BRAND_OPTION, []);
        $brand     = is_array($brandRaw) ? $brandRaw : [];
        $stylesRaw = get_option(LG_LAYOUT_V2_STYLE_OPTION, []);
        $styles    = is_array($stylesRaw) ? $stylesRaw : [];
        $msg       = isset($_GET['updated']) ? 'Saved. Bundle regenerated.' : '';

        ?>
        <div class="wrap lg-v2-dash">
            <h1>LG Layout v2 — Block Styles</h1>
            <p class="description">
                Per-block overrides win over global, which wins over manifest defaults. Empty fields
                fall through to defaults (pre-filled here so you can tweak from a starting point).
            </p>
            <?php if ($msg): ?><div class="notice notice-success"><p><?php echo esc_html($msg); ?></p></div><?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>" />
                <input type="hidden" name="active_tab" id="lg-v2-active-tab" value="" />
                <?php wp_nonce_field(self::NONCE_ACTION); ?>

                <?php self::render_datalists(); ?>

                <div class="lg-v2-tabs">
                    <nav class="lg-v2-tablist" role="tablist">
                        <button type="button" class="lg-v2-tab is-brand"  data-target="lg-v2-_brand">🎨 Brand Palette</button>
                        <button type="button" class="lg-v2-tab is-global" data-target="lg-v2-_global">★ Global Defaults</button>
                        <button type="button" class="lg-v2-tab is-export" data-target="lg-v2-_export">📤 Export</button>
                        <?php foreach ($manifests as $name => $_m): ?>
                            <button type="button" class="lg-v2-tab" data-target="lg-v2-<?php echo esc_attr($name); ?>"><?php echo esc_html($name); ?></button>
                        <?php endforeach; ?>
                    </nav>

                    <?php self::render_brand_panel($tokens, $brand); ?>
                    <?php self::render_global_panel(is_array($styles[self::GLOBAL_KEY] ?? null) ? $styles[self::GLOBAL_KEY] : []); ?>
                    <?php self::render_export_panel(); ?>

                    <?php foreach ($manifests as $name => $m): ?>
                        <?php self::render_block_panel($name, $m, is_array($styles[$name] ?? null) ? $styles[$name] : []); ?>
                    <?php endforeach; ?>
                </div>

                <?php submit_button('Save & Regenerate Bundle'); ?>
            </form>
        </div>

        <?php self::render_preview_modal($manifests); ?>
        <?php self::print_styles(); ?>
        <?php self::print_script(); ?>
        <?php
    }

    /** Preview modal shell + per-block sample-markup payload. The iframe
     *  inside is populated from $previewData (block name → { baseClass,
     *  variants, preview }) by the dash JS when a Preview button is clicked.
     *  Sample markup lives in blocks/<name>/preview.html so authors can
     *  edit it without touching PHP. */
    private static function render_preview_modal(array $manifests): void
    {
        $blocksDir = dirname(__DIR__) . '/blocks';
        $previewData = [];
        foreach ($manifests as $name => $m) {
            $path = "$blocksDir/$name/preview.html";
            $preview = is_file($path) ? (string) file_get_contents($path) : '';
            if ($preview === '') continue;
            /* Derive the base class from the manifest selector — strip the
               leading dot and any pseudo / descendant suffix. Used by JS to
               toggle the --variant modifier. */
            $sel = (string) ($m['selector'] ?? '');
            $baseClass = ltrim(strtok($sel, ' :['), '.');
            $previewData[$name] = [
                'baseClass' => $baseClass,
                'variants'  => array_keys((array) ($m['variants'] ?? [])),
                'preview'   => $preview,
            ];
        }
        ?>
        <script id="lg-v2-preview-data" type="application/json"><?php
            echo wp_json_encode($previewData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        ?></script>
        <div id="lg-v2-preview-modal" class="lg-v2-modal" hidden>
            <div class="lg-v2-modal__backdrop" data-lg-preview-close></div>
            <div class="lg-v2-modal__dialog" role="dialog" aria-modal="true" aria-label="Block preview">
                <header class="lg-v2-modal__head">
                    <strong class="lg-v2-modal__title">Preview</strong>
                    <nav class="lg-v2-modal__variants" data-lg-preview-variant-tabs></nav>
                    <span class="lg-v2-modal__status" data-lg-preview-status></span>
                    <button type="button" class="button" data-lg-preview-close aria-label="Close preview">Close</button>
                </header>
                <div class="lg-v2-modal__body">
                    <iframe class="lg-v2-modal__frame" data-lg-preview-iframe title="Block preview"></iframe>
                </div>
            </div>
        </div>
        <?php
    }

    /** One <datalist> per preset type, shared across every input that opts in. */
    private static function render_datalists(): void
    {
        foreach (self::PRESETS as $key => $values) {
            echo '<datalist id="lg-v2-dl-' . esc_attr($key) . '">';
            foreach ($values as $v) echo '<option value="' . esc_attr($v) . '"></option>';
            echo '</datalist>';
        }
    }

    /** Brand palette panel — token name → input. Values pre-fill with the
     *  token's default so authors see what the current effective color is. */
    private static function render_brand_panel(array $tokens, array $brand): void
    {
        $byCat = [];
        foreach ($tokens as $name => $meta) $byCat[$meta['category'] ?? 'other'][$name] = $meta;
        ksort($byCat);
        ?>
        <section class="lg-v2-panel" id="lg-v2-_brand">
            <header>
                <h2>Brand Palette</h2>
                <p class="description">
                    Override the <code>:root</code> CSS variables that every block inherits.
                    Stored in <code><?php echo esc_html(LG_LAYOUT_V2_BRAND_OPTION); ?></code>.
                    Values matching the manifest default are dropped on save (no noise).
                </p>
            </header>
            <?php foreach ($byCat as $cat => $list): ?>
                <fieldset class="lg-v2-fields">
                    <legend><?php echo esc_html(ucfirst((string) $cat)); ?></legend>
                    <?php foreach ($list as $tokenName => $meta):
                        $default = (string) $meta['default'];
                        $stored  = isset($brand[$tokenName]) ? (string) $brand[$tokenName] : '';
                        /* Pre-fill with default when no override stored. */
                        $value   = $stored !== '' ? $stored : $default;
                        $isColor = ($meta['category'] ?? '') === 'color';
                    ?>
                        <div class="lg-v2-row">
                            <label>
                                <span class="lg-v2-label-main"><?php echo esc_html($tokenName); ?></span>
                                <span class="lg-v2-label-desc"><?php echo esc_html($meta['description'] ?? ''); ?></span>
                            </label>
                            <input type="text"
                                   class="lg-v2-input <?php echo $isColor ? 'lg-v2-color' : ''; ?>"
                                   name="brand[<?php echo esc_attr($tokenName); ?>]"
                                   value="<?php echo esc_attr($value); ?>"
                                   placeholder="<?php echo esc_attr($default); ?>"
                                   data-default="<?php echo esc_attr($default); ?>"
                                   autocomplete="off" />
                        </div>
                    <?php endforeach; ?>
                </fieldset>
            <?php endforeach; ?>
        </section>
        <?php
    }

    /** Global Defaults panel — canonical container + text vars; no manifest
     *  defaults to pre-fill from (there's no "default global"), so fields
     *  start blank. */
    private static function render_global_panel(array $stored): void
    {
        $container = is_array($stored['container'] ?? null) ? $stored['container'] : [];
        $text      = is_array($stored['text']      ?? null) ? $stored['text']      : [];
        ?>
        <section class="lg-v2-panel" id="lg-v2-_global">
            <header>
                <h2>
                    ★ Global Defaults
                    <button type="button"
                            class="button lg-v2-reset"
                            title="Clear all Global Defaults. Empty = no global rule emitted; blocks fall through to manifest defaults.">
                        Reset all
                    </button>
                </h2>
                <p class="description">
                    Applied to every block at zero specificity via <code>:where(...)</code>. Per-block
                    panels override on overlap. Use this for sitewide defaults you don't want to repeat per block.
                </p>
            </header>

            <fieldset class="lg-v2-fields">
                <legend>Container</legend>
                <?php foreach (self::CANONICAL_CONTAINER as $v):
                    self::render_field("styles[" . self::GLOBAL_KEY . "][container]", $v, $container[$v] ?? '', '');
                endforeach; ?>
            </fieldset>

            <fieldset class="lg-v2-fields">
                <legend>Text</legend>
                <?php foreach (self::CANONICAL_TEXT as $v):
                    self::render_field("styles[" . self::GLOBAL_KEY . "][text]", $v, $text[$v] ?? '', '');
                endforeach; ?>
            </fieldset>
        </section>
        <?php
    }

    /** Export panel — pick a converted post, view + download its layout JSON.
     *  Lives inside the main settings form for layout consistency, but uses
     *  only type="button" controls + AJAX so nothing leaks into save. */
    private static function render_export_panel(): void
    {
        global $wpdb;
        /* Every post that has a stored v2 layout, regardless of post_type or
           status, sorted most-recently-modified first. Cap at 500 — beyond
           that the <select> is awkward and we'd want search; revisit then. */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_type, p.post_status
               FROM {$wpdb->posts} p
               INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
               WHERE pm.meta_key = %s
                 AND p.post_status NOT IN ('trash','auto-draft','inherit')
               ORDER BY p.post_modified DESC
               LIMIT 500",
            LG_LAYOUT_V2_META_KEY
        ));
        $nonce   = wp_create_nonce('lg_v2_export');
        $ajaxUrl = admin_url('admin-ajax.php');
        ?>
        <section class="lg-v2-panel" id="lg-v2-_export">
            <header>
                <h2>📤 Export layout JSON</h2>
                <p class="description">
                    Pick a converted post to view + download its <code>_lg_layout_v2</code> layout. Same payload
                    as the per-post metabox download and <code>wp lg-layout-v2 export</code>.
                </p>
            </header>

            <fieldset class="lg-v2-fields">
                <legend>Post</legend>
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:10px;">
                    <select id="lg-v2-export-post" style="min-width:480px;">
                        <option value="">— choose a post (<?php echo (int) count($rows); ?> available) —</option>
                        <?php foreach ($rows as $r):
                            $label = sprintf('[%s · %s] #%d — %s',
                                $r->post_type,
                                $r->post_status,
                                (int) $r->ID,
                                $r->post_title !== '' ? $r->post_title : '(untitled)'
                            );
                        ?>
                            <option value="<?php echo (int) $r->ID; ?>"
                                    data-name="lg-layout-v2-post-<?php echo (int) $r->ID; ?>.json">
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="button" id="lg-v2-export-load">Load</button>
                    <button type="button" class="button button-primary" id="lg-v2-export-download" disabled>Download JSON</button>
                    <a href="#" class="button" id="lg-v2-export-edit" target="_blank" rel="noopener" hidden>Open in editor →</a>
                </div>
                <textarea id="lg-v2-export-textarea" rows="22" readonly
                          style="width:100%; font-family:Menlo,Consolas,monospace; font-size:12px;"
                          placeholder="Layout JSON will appear here after Load."></textarea>
            </fieldset>
        </section>
        <script>
        (function () {
            var sel  = document.getElementById('lg-v2-export-post');
            var load = document.getElementById('lg-v2-export-load');
            var dl   = document.getElementById('lg-v2-export-download');
            var edit = document.getElementById('lg-v2-export-edit');
            var ta   = document.getElementById('lg-v2-export-textarea');
            if (!sel || !load || !dl || !ta || load._lgBound) return;
            load._lgBound = true;
            var nonce   = <?php echo wp_json_encode($nonce);   ?>;
            var ajaxUrl = <?php echo wp_json_encode($ajaxUrl); ?>;

            function updateEditLink() {
                if (!edit) return;
                var id = sel.value;
                if (!id) { edit.hidden = true; return; }
                edit.hidden = false;
                edit.href = '<?php echo esc_url_raw(admin_url('post.php')); ?>'
                          + '?post=' + encodeURIComponent(id) + '&action=edit';
            }
            sel.addEventListener('change', function () {
                ta.value = '';
                dl.disabled = true;
                updateEditLink();
            });
            updateEditLink();

            load.addEventListener('click', function () {
                var id = sel.value;
                if (!id) { alert('Pick a post first.'); return; }
                ta.value = 'Loading…';
                dl.disabled = true;
                var url = ajaxUrl + '?action=lg_v2_get_layout'
                        + '&post_id=' + encodeURIComponent(id)
                        + '&nonce='   + encodeURIComponent(nonce);
                fetch(url, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (j && j.success) {
                            ta.value = JSON.stringify(j.data, null, 2);
                            dl.disabled = false;
                        } else {
                            ta.value = 'Error: ' + ((j && j.data) || 'unknown');
                        }
                    })
                    .catch(function (e) { ta.value = 'Fetch failed: ' + e; });
            });

            dl.addEventListener('click', function () {
                if (!ta.value) return;
                var opt  = sel.options[sel.selectedIndex];
                var name = (opt && opt.getAttribute('data-name')) || 'lg-layout-v2.json';
                var href = null;
                try {
                    if (window.URL && typeof window.URL.createObjectURL === 'function') {
                        href = window.URL.createObjectURL(
                            new Blob([ta.value], { type: 'application/json' })
                        );
                    }
                } catch (err) { href = null; }
                if (!href) href = 'data:application/json;charset=utf-8,' + encodeURIComponent(ta.value);
                var a = document.createElement('a');
                a.href = href; a.download = name;
                document.body.appendChild(a); a.click(); document.body.removeChild(a);
                if (href.indexOf('blob:') === 0) {
                    setTimeout(function () {
                        try { window.URL.revokeObjectURL(href); } catch (e) {}
                    }, 1000);
                }
            });
        })();
        </script>
        <?php
    }

    /** One block's panel — inherit toggle + container + text + variants + sub_targets. */
    private static function render_block_panel(string $name, array $m, array $stored): void
    {
        $inherit   = !empty($stored['_inherit_global']);
        $container = is_array($stored['container'] ?? null) ? $stored['container'] : [];
        $text      = is_array($stored['text']      ?? null) ? $stored['text']      : [];
        ?>
        <section class="lg-v2-panel <?php echo $inherit ? 'is-inheriting' : ''; ?>" id="lg-v2-<?php echo esc_attr($name); ?>">
            <header>
                <h2>
                    <?php echo esc_html($name); ?>
                    <button type="button"
                            class="button lg-v2-copy-global"
                            data-lg-copy-from-global
                            title="Copy the current Global Defaults values into this block's fields. Unset block fields become explicit overrides.">
                        Copy Global → Local
                    </button>
                    <button type="button"
                            class="button lg-v2-reset"
                            title="Clear this block's stored overrides. Manifest defaults take over on save.">
                        Reset to manifest defaults
                    </button>
                    <button type="button"
                            class="button button-primary lg-v2-preview-btn"
                            data-lg-preview-block="<?php echo esc_attr($name); ?>"
                            title="Preview this block with the current (unsaved) field values. Updates live as you edit.">
                        Preview
                    </button>
                </h2>
                <p class="description">
                    Selector: <code><?php echo esc_html($m['selector']); ?></code>.
                    <?php echo esc_html($m['description'] ?? ''); ?>
                </p>

                <label class="lg-v2-inherit">
                    <input type="checkbox"
                           name="styles[<?php echo esc_attr($name); ?>][_inherit_global]"
                           value="1"
                           data-lg-inherit-toggle
                           <?php checked($inherit); ?> />
                    <strong>Inherit Global Defaults</strong>
                    <span class="lg-v2-hint">— skip per-block rules; let the global panel decide.</span>
                </label>
            </header>

            <?php if (!empty($m['vars']['container'])): ?>
                <fieldset class="lg-v2-fields">
                    <legend>Container</legend>
                    <?php foreach ($m['vars']['container'] as $varName):
                        $default = (string) ($m['defaults']['container'][$varName] ?? '');
                        /* Pass the *stored* value only — empty when not stored.
                           The leaf input already surfaces the default via its
                           placeholder, so an unset field reads as inherited. */
                        $value   = isset($container[$varName]) ? (string) $container[$varName] : '';
                        self::render_field("styles[$name][container]", $varName, $value, $default);
                    endforeach; ?>
                </fieldset>
            <?php endif; ?>

            <?php if (!empty($m['vars']['text'])): ?>
                <fieldset class="lg-v2-fields">
                    <legend>Text</legend>
                    <?php foreach ($m['vars']['text'] as $varName):
                        $default = (string) ($m['defaults']['text'][$varName] ?? '');
                        $value   = isset($text[$varName]) ? (string) $text[$varName] : '';
                        self::render_field("styles[$name][text]", $varName, $value, $default);
                    endforeach; ?>
                </fieldset>
            <?php endif; ?>

            <?php foreach (($m['variants'] ?? []) as $vname => $vdef):
                $vStored = is_array($stored['variants'][$vname] ?? null) ? $stored['variants'][$vname] : [];
                self::render_variant_or_sub(
                    label:     "variant: $vname",
                    selector:  $m['selector'] . '--' . $vname,
                    fieldBase: "styles[$name][variants][$vname]",
                    vars:      $m['vars'],
                    defaults:  self::resolve_variant_defaults($m, $vname),
                    stored:    $vStored,
                    isVariant: true,
                    variantKey: $vname,
                );
            endforeach; ?>

            <?php foreach (($m['sub_targets'] ?? []) as $stKey => $stDef):
                $sStored = is_array($stored['sub_targets'][$stKey] ?? null) ? $stored['sub_targets'][$stKey] : [];
                self::render_variant_or_sub(
                    label:     (string) ($stDef['label'] ?? $stKey),
                    selector:  (string) $stDef['selector'],
                    fieldBase: "styles[$name][sub_targets][$stKey]",
                    vars:      $stDef['vars'],
                    defaults:  $stDef['defaults'],
                    stored:    $sStored,
                );
            endforeach; ?>
        </section>
        <?php
    }

    /** Shared renderer for variant + sub_target sub-panels.
     *  $isVariant — when true, render an editable "Display name" input
     *  at the top so authors can rename what shows in the FE editor's
     *  Variant pill (e.g. "Framed" instead of "variant-1"). */
    private static function render_variant_or_sub(
        string $label, string $selector, string $fieldBase,
        array $vars, array $defaults, array $stored,
        bool $isVariant = false, string $variantKey = ''
    ): void {
        $container   = is_array($stored['container'] ?? null) ? $stored['container'] : [];
        $text        = is_array($stored['text']      ?? null) ? $stored['text']      : [];
        $storedLabel = (string) ($stored['__label']  ?? '');
        ?>
        <fieldset class="lg-v2-fields lg-v2-fields--sub">
            <legend>
                <?php echo esc_html($label); ?>
                <span class="lg-v2-hint"><code><?php echo esc_html($selector); ?></code></span>
            </legend>
            <?php if ($isVariant): ?>
                <div class="lg-v2-row" data-var="__label">
                    <label><span class="lg-v2-label-main">Display name</span>
                        <span class="lg-v2-label-desc">Shown on the FE editor's Variant pill.</span></label>
                    <div class="lg-v2-field">
                        <input type="text"
                               class="lg-v2-input"
                               name="<?php echo esc_attr($fieldBase . '[__label]'); ?>"
                               value="<?php echo esc_attr($storedLabel); ?>"
                               placeholder="<?php echo esc_attr($variantKey); ?>"
                               autocomplete="off" />
                    </div>
                </div>
            <?php endif; ?>
            <?php if (!empty($vars['container'])): ?>
                <div class="lg-v2-subgroup"><strong>Container</strong></div>
                <?php foreach ($vars['container'] as $varName):
                    $default = (string) ($defaults['container'][$varName] ?? '');
                    $value   = isset($container[$varName]) ? (string) $container[$varName] : '';
                    self::render_field("{$fieldBase}[container]", $varName, $value, $default);
                endforeach; ?>
            <?php endif; ?>
            <?php if (!empty($vars['text'])): ?>
                <div class="lg-v2-subgroup"><strong>Text</strong></div>
                <?php foreach ($vars['text'] as $varName):
                    $default = (string) ($defaults['text'][$varName] ?? '');
                    $value   = isset($text[$varName]) ? (string) $text[$varName] : '';
                    self::render_field("{$fieldBase}[text]", $varName, $value, $default);
                endforeach; ?>
            <?php endif; ?>
        </fieldset>
        <?php
    }

    /** Resolve effective defaults for a variant by walking its `extends` chain. */
    private static function resolve_variant_defaults(array $m, string $vname): array
    {
        $chain = []; $cur = $vname; $guard = 0;
        while ($cur !== 'defaults' && $guard++ < 16) {
            if (!isset($m['variants'][$cur])) break;
            $chain[] = $m['variants'][$cur];
            $cur = $m['variants'][$cur]['extends'] ?? 'defaults';
        }
        $resolved = ['container' => $m['defaults']['container'] ?? [], 'text' => $m['defaults']['text'] ?? []];
        foreach (array_reverse($chain) as $layer) {
            foreach (['container', 'text'] as $group) {
                if (!empty($layer[$group]) && is_array($layer[$group])) {
                    $resolved[$group] = array_merge($resolved[$group], $layer[$group]);
                }
            }
        }
        return $resolved;
    }

    /* ── Field renderer dispatch ─────────────────────────────────── */

    /**
     * Render one var's input(s). Dispatches by var name:
     *   - padding         → 4 inputs (top/right/bottom/left)
     *   - border          → 1 input (width+style) + 1 color input
     *   - bg / *color     → color picker
     *   - radius/size/etc → text input with a datalist of presets
     *   - font-weight / text-align → <select>
     */
    private static function render_field(string $prefix, string $varName, string $value, string $default): void
    {
        $kind = self::field_kind($varName);
        $label = '--lg-' . $varName;

        echo '<div class="lg-v2-row" data-var="' . esc_attr($varName) . '">';
        echo '<label><span class="lg-v2-label-main">' . esc_html($label) . '</span></label>';
        echo '<div class="lg-v2-field">';

        match ($kind) {
            'padding'      => self::field_padding($prefix, $value, $default),
            'margin-block' => self::field_margin_block($prefix, $value, $default),
            'border'       => self::field_border($prefix, $value, $default),
            'color'        => self::field_color($prefix, $varName, $value, $default),
            'select'       => self::field_select($prefix, $varName, $value, $default),
            default        => self::field_text($prefix, $varName, $value, $default, $kind),
        };

        echo '</div></div>';
    }

    private static function field_kind(string $var): string
    {
        /* Compound fields dispatched to their own renderers: */
        if ($var === 'padding')      return 'padding';        /* 4-up + link */
        if ($var === 'margin-block') return 'margin-block';   /* 2-up + link */
        if ($var === 'border')       return 'border';         /* width + style + color */

        if ($var === 'bg' || $var === 'color' || str_ends_with($var, '-color')) return 'color';
        if (isset(self::SELECTS[$var])) return 'select';
        if ($var === 'letter-spacing') return 'text-free';
        return match ($var) {
            'radius'      => 'radius',
            'font-size'   => 'font-size',
            'line-height' => 'line-height',
            default       => 'size',
        };
    }

    /** Padding: 4 stepper inputs (T/R/B/L) + a chain-link toggle. When linked,
     *  typing in the top input mirrors the value into R/B/L. Stored composite
     *  is parsed on load; if all 4 sides are equal we infer "linked" mode. */
    private static function field_padding(string $prefix, string $value, string $default): void
    {
        $cur = self::parse_padding($value);
        $def = self::parse_padding($default);
        $linked = ($cur['top'] !== '' &&
                   $cur['top'] === $cur['right'] &&
                   $cur['right'] === $cur['bottom'] &&
                   $cur['bottom'] === $cur['left']);
        /* For a fresh field (all empty) we still want link ON by default —
           one click into the top input fills all four. */
        if ($cur['top'] === '' && $cur['right'] === '' && $cur['bottom'] === '' && $cur['left'] === '') {
            $linked = true;
        }
        ?>
        <div class="lg-v2-pad <?php echo $linked ? 'is-linked' : ''; ?>">
            <?php foreach (['top','right','bottom','left'] as $side): ?>
                <div class="lg-v2-pad__cell">
                    <?php self::stepper_input(
                        name:       $prefix . '[padding][' . $side . ']',
                        value:      $cur[$side],
                        default:    $def[$side],
                        step:       4,
                        unit:       'px',
                        extraClass: 'lg-v2-input--xs lg-v2-pad__input'
                    ); ?>
                    <span class="lg-v2-pad__lbl"><?php echo esc_html(strtoupper($side[0])); ?></span>
                </div>
            <?php endforeach; ?>
            <button type="button"
                    class="lg-v2-pad__link"
                    aria-pressed="<?php echo $linked ? 'true' : 'false'; ?>"
                    title="Link all sides">
                <span class="lg-v2-pad__link-on" aria-hidden="true">🔗</span>
                <span class="lg-v2-pad__link-off" aria-hidden="true">⛓️‍💥</span>
            </button>
        </div>
        <?php
    }

    /** Stepper input: text input + ± buttons, like Elementor's spacing
     *  controls. Value is text (not numeric) so var() / em / % values still
     *  round-trip; the buttons parse the leading number, bump, preserve unit. */
    private static function stepper_input(
        string $name, string $value, string $default, float $step, string $unit, string $extraClass = ''
    ): void {
        ?>
        <div class="lg-v2-stepper" data-step="<?php echo esc_attr((string) $step); ?>" data-unit="<?php echo esc_attr($unit); ?>">
            <button type="button" class="lg-v2-stepper__btn lg-v2-stepper__dec" tabindex="-1" aria-label="decrease">−</button>
            <input type="text"
                   class="lg-v2-input lg-v2-stepper__input <?php echo esc_attr($extraClass); ?>"
                   name="<?php echo esc_attr($name); ?>"
                   value="<?php echo esc_attr($value); ?>"
                   placeholder="<?php echo esc_attr($default); ?>"
                   autocomplete="off" />
            <button type="button" class="lg-v2-stepper__btn lg-v2-stepper__inc" tabindex="-1" aria-label="increase">+</button>
        </div>
        <?php
    }

    /** Margin-block: 2 stepper inputs (top + bottom) with a link toggle.
     *  Composed into the standard 1- or 2-value margin-block shorthand. */
    private static function field_margin_block(string $prefix, string $value, string $default): void
    {
        $cur = self::parse_margin_block($value);
        $def = self::parse_margin_block($default);
        $linked = ($cur['top'] !== '' && $cur['top'] === $cur['bottom'])
               || ($cur['top'] === '' && $cur['bottom'] === '');
        ?>
        <div class="lg-v2-pad <?php echo $linked ? 'is-linked' : ''; ?>">
            <?php foreach (['top','bottom'] as $side): ?>
                <div class="lg-v2-pad__cell">
                    <?php self::stepper_input(
                        name:       $prefix . '[margin-block][' . $side . ']',
                        value:      $cur[$side],
                        default:    $def[$side],
                        step:       4,
                        unit:       'px',
                        extraClass: 'lg-v2-input--xs lg-v2-pad__input'
                    ); ?>
                    <span class="lg-v2-pad__lbl"><?php echo esc_html(strtoupper($side[0])); ?></span>
                </div>
            <?php endforeach; ?>
            <button type="button"
                    class="lg-v2-pad__link"
                    aria-pressed="<?php echo $linked ? 'true' : 'false'; ?>"
                    title="Link top and bottom">
                <span class="lg-v2-pad__link-on" aria-hidden="true">🔗</span>
                <span class="lg-v2-pad__link-off" aria-hidden="true">⛓️‍💥</span>
            </button>
        </div>
        <?php
    }

    /** Border: three controls.
     *    - Width stepper (px), key border__width
     *    - Style select  (none/solid/dashed/dotted/double/...), key border__style
     *    - Color picker  (wpColorPicker), key border__color
     *  Composed at save time into the single --lg-border composite. */
    private static function field_border(string $prefix, string $value, string $default): void
    {
        $cur = self::parse_border_full($value);
        $def = self::parse_border_full($default);
        /* Widths are 4-tuples (T R B L). Chain-lock when all four agree. */
        $linked = ($cur['width_t'] !== '' &&
                   $cur['width_t'] === $cur['width_r'] &&
                   $cur['width_r'] === $cur['width_b'] &&
                   $cur['width_b'] === $cur['width_l']);
        if ($cur['width_t'] === '' && $cur['width_r'] === '' && $cur['width_b'] === '' && $cur['width_l'] === '') {
            $linked = true;
        }
        ?>
        <div class="lg-v2-pad lg-v2-pad--border <?php echo $linked ? 'is-linked' : ''; ?>">
            <?php foreach (['top','right','bottom','left'] as $side):
                $k = 'width_' . $side[0]; ?>
                <div class="lg-v2-pad__cell">
                    <?php self::stepper_input(
                        name:       $prefix . '[border__width][' . $side . ']',
                        value:      $cur[$k],
                        default:    $def[$k],
                        step:       1,
                        unit:       'px',
                        extraClass: 'lg-v2-input--xs lg-v2-pad__input'
                    ); ?>
                    <span class="lg-v2-pad__lbl"><?php echo esc_html(strtoupper($side[0])); ?></span>
                </div>
            <?php endforeach; ?>
            <button type="button"
                    class="lg-v2-pad__link"
                    aria-pressed="<?php echo $linked ? 'true' : 'false'; ?>"
                    title="Link all sides">
                <span class="lg-v2-pad__link-on" aria-hidden="true">🔗</span>
                <span class="lg-v2-pad__link-off" aria-hidden="true">⛓️‍💥</span>
            </button>
        </div>
        <?php /* Style select */ ?>
        <select class="lg-v2-input lg-v2-select"
                name="<?php echo esc_attr($prefix . '[border__style]'); ?>"
                data-default="<?php echo esc_attr($def['style']); ?>">
            <?php foreach (self::BORDER_STYLES as $optV => $optL): ?>
                <option value="<?php echo esc_attr($optV); ?>" <?php selected($optV, $cur['style']); ?>>
                    <?php echo esc_html($optL); ?><?php if ($optV !== '' && $optV === $def['style']): ?> (default)<?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php /* Color picker */ ?>
        <input type="text"
               class="lg-v2-input lg-v2-input--md lg-v2-color"
               name="<?php echo esc_attr($prefix . '[border__color]'); ?>"
               value="<?php echo esc_attr($cur['color']); ?>"
               placeholder="<?php echo esc_attr($def['color'] ?: 'color'); ?>"
               data-default="<?php echo esc_attr($def['color']); ?>"
               autocomplete="off" />
        <?php
    }

    private static function field_color(string $prefix, string $varName, string $value, string $default): void
    {
        ?>
        <input type="text"
               class="lg-v2-input lg-v2-color"
               name="<?php echo esc_attr($prefix . '[' . $varName . ']'); ?>"
               value="<?php echo esc_attr($value); ?>"
               placeholder="<?php echo esc_attr($default); ?>"
               data-default="<?php echo esc_attr($default); ?>"
               autocomplete="off" />
        <?php
    }

    private static function field_select(string $prefix, string $varName, string $value, string $default): void
    {
        $opts = self::SELECTS[$varName] ?? [];
        /* If the stored value isn't in the preset list, surface it as a
           one-off "Custom" option so editing a hand-typed value doesn't
           silently revert it on save. */
        $hasCustom = $value !== '' && !array_key_exists($value, $opts);
        ?>
        <select class="lg-v2-input lg-v2-select"
                name="<?php echo esc_attr($prefix . '[' . $varName . ']'); ?>"
                data-default="<?php echo esc_attr($default); ?>">
            <?php foreach ($opts as $optV => $optL): ?>
                <option value="<?php echo esc_attr($optV); ?>" <?php selected($optV, $value); ?>>
                    <?php echo esc_html($optL); ?><?php if ($optV !== '' && $optV === $default): ?> (default)<?php endif; ?>
                </option>
            <?php endforeach; ?>
            <?php if ($hasCustom): ?>
                <option value="<?php echo esc_attr($value); ?>" selected>
                    Custom: <?php echo esc_html($value); ?>
                </option>
            <?php endif; ?>
        </select>
        <?php
    }

    /** Generic text input. Numeric kinds get an Elementor-style stepper
     *  (input + ± buttons). Free-text kinds (font-family, shadow) stay
     *  plain. Datalist for shadow stays as a backup hint. */
    private static function field_text(string $prefix, string $varName, string $value, string $default, string $kind): void
    {
        $stepMap = [
            'size'        => ['step' => 4,   'unit' => 'px'],
            'radius'      => ['step' => 4,   'unit' => 'px'],
            'font-size'   => ['step' => 2,   'unit' => 'px'],
            'line-height' => ['step' => 0.1, 'unit' => ''],
        ];
        if (isset($stepMap[$kind])) {
            self::stepper_input(
                name:    $prefix . '[' . $varName . ']',
                value:   $value,
                default: $default,
                step:    (float) $stepMap[$kind]['step'],
                unit:    (string) $stepMap[$kind]['unit'],
            );
            return;
        }

        /* shadow gets a datalist (qualitative presets, not numeric). */
        $list = $kind === 'shadow' ? 'lg-v2-dl-shadow' : '';
        ?>
        <input type="text"
               <?php if ($list): ?>list="<?php echo esc_attr($list); ?>"<?php endif; ?>
               class="lg-v2-input"
               name="<?php echo esc_attr($prefix . '[' . $varName . ']'); ?>"
               value="<?php echo esc_attr($value); ?>"
               placeholder="<?php echo esc_attr($default); ?>"
               autocomplete="off" />
        <?php
    }

    /* ── Composite value parsing ─────────────────────────────────── */

    /** Parse a padding shorthand into per-side values. Empty input → all empty. */
    public static function parse_padding(string $v): array
    {
        $v = trim($v);
        if ($v === '') return ['top' => '', 'right' => '', 'bottom' => '', 'left' => ''];
        $parts = preg_split('/\s+/', $v) ?: [];
        return match (count($parts)) {
            1       => ['top' => $parts[0], 'right' => $parts[0], 'bottom' => $parts[0], 'left' => $parts[0]],
            2       => ['top' => $parts[0], 'right' => $parts[1], 'bottom' => $parts[0], 'left' => $parts[1]],
            3       => ['top' => $parts[0], 'right' => $parts[1], 'bottom' => $parts[2], 'left' => $parts[1]],
            default => ['top' => $parts[0], 'right' => $parts[1], 'bottom' => $parts[2], 'left' => $parts[3]],
        };
    }

    /** Compose 4 sides back into the shortest valid CSS shorthand. */
    public static function compose_padding(array $sides): string
    {
        $t = trim((string) ($sides['top']    ?? ''));
        $r = trim((string) ($sides['right']  ?? ''));
        $b = trim((string) ($sides['bottom'] ?? ''));
        $l = trim((string) ($sides['left']   ?? ''));
        if ($t === '' && $r === '' && $b === '' && $l === '') return '';
        /* Partial input → fill missing sides with "0" rather than guessing. */
        $t = $t !== '' ? $t : '0';
        $r = $r !== '' ? $r : '0';
        $b = $b !== '' ? $b : '0';
        $l = $l !== '' ? $l : '0';
        if ($t === $r && $r === $b && $b === $l) return $t;
        if ($t === $b && $r === $l)             return "$t $r";
        if ($r === $l)                          return "$t $r $b";
        return "$t $r $b $l";
    }

    /** Parse "1px solid #abc123" into line + color. Used by the two-piece
     *  legacy border parser; kept because callers outside the new 3-piece
     *  form may rely on it. */
    public static function parse_border(string $v): array
    {
        $v = trim($v);
        if ($v === '' || $v === 'none') return ['line' => $v, 'color' => ''];
        if (preg_match('/^(.*?)\s+(var\([^)]+\)|#[0-9a-fA-F]{3,8}|rgba?\([^)]+\)|hsla?\([^)]+\))\s*$/', $v, $m)) {
            return ['line' => trim($m[1]), 'color' => $m[2]];
        }
        return ['line' => $v, 'color' => ''];
    }

    public static function compose_border(string $line, string $color): string
    {
        $line = trim($line); $color = trim($color);
        if ($line === '' || $line === 'none') return $line === 'none' ? 'none' : '';
        return $color !== '' ? "$line $color" : $line;
    }

    /** Parse a border value into widths (T R B L) + style + color.
     *  Accepts both uniform ("1px solid #abc") and per-side
     *  ("1px 2px 3px 4px solid #abc") forms. The uniform form fills all
     *  four width slots with the same value so the dash UI's chain-lock
     *  can light up consistently. */
    public static function parse_border_full(string $v): array
    {
        $two = self::parse_border($v);             /* split off color first */
        $line = trim((string) ($two['line']  ?? ''));
        $color = trim((string) ($two['color'] ?? ''));

        $empty = ['width_t' => '', 'width_r' => '', 'width_b' => '', 'width_l' => '', 'style' => '', 'color' => '', 'width' => ''];
        if ($line === 'none') return array_merge($empty, ['style' => 'none']);
        if ($line === '')     return array_merge($empty, ['color' => $color]);

        $parts = preg_split('/\s+/', $line) ?: [];
        /* Per-side width form: 5+ tokens where the first 4 look like widths
           (digit-led length, var(), 0, or "none"-equivalent skipped). */
        $isWidthTok = function (string $t): bool {
            return $t === '0' || (bool) preg_match('/^(\d+(\.\d+)?(px|em|rem|%)?|var\([^)]+\))$/i', $t);
        };
        if (count($parts) >= 5 && $isWidthTok($parts[0]) && $isWidthTok($parts[1]) && $isWidthTok($parts[2]) && $isWidthTok($parts[3])) {
            $style = $parts[4];
            return [
                'width_t' => $parts[0], 'width_r' => $parts[1],
                'width_b' => $parts[2], 'width_l' => $parts[3],
                'style'   => $style,
                'color'   => $color,
                'width'   => $parts[0],   /* legacy single-width fallback for back-compat */
            ];
        }
        /* Uniform: width + style (+ optional color already split). */
        if (count($parts) >= 2) {
            $w = $parts[0]; $s = $parts[1];
            return [
                'width_t' => $w, 'width_r' => $w, 'width_b' => $w, 'width_l' => $w,
                'style'   => $s, 'color' => $color, 'width' => $w,
            ];
        }
        /* Single token: width if digit/unit, else style. */
        if (preg_match('/^\d/', $parts[0]) || preg_match('/(px|em|rem|%)$/', $parts[0])) {
            $w = $parts[0];
            return [
                'width_t' => $w, 'width_r' => $w, 'width_b' => $w, 'width_l' => $w,
                'style'   => '', 'color' => $color, 'width' => $w,
            ];
        }
        return array_merge($empty, ['style' => $parts[0], 'color' => $color]);
    }

    /** Compose a border CSS-storage string from per-side widths + style + color.
     *  When all four widths agree, emits the legacy uniform form ("Wpx style color")
     *  so downstream CssBuilder still uses --lg-border. When they differ, emits the
     *  4-width form ("T R B L style color") which CssBuilder detects and translates
     *  into border-*-width longhand. */
    public static function compose_border_full(array|string $widthOrLegacy, string $style, string $color): string
    {
        $style = trim($style); $color = trim($color);
        if ($style === 'none') return 'none';

        /* Back-compat: legacy single-string width arg still accepted. */
        if (is_string($widthOrLegacy)) {
            $w = trim($widthOrLegacy);
            $widths = ['top' => $w, 'right' => $w, 'bottom' => $w, 'left' => $w];
        } else {
            $widths = [
                'top'    => trim((string) ($widthOrLegacy['top']    ?? '')),
                'right'  => trim((string) ($widthOrLegacy['right']  ?? '')),
                'bottom' => trim((string) ($widthOrLegacy['bottom'] ?? '')),
                'left'   => trim((string) ($widthOrLegacy['left']   ?? '')),
            ];
        }
        $allEmpty   = ($widths['top'] === '' && $widths['right'] === '' && $widths['bottom'] === '' && $widths['left'] === '');
        $allEqual   = (!$allEmpty
            && $widths['top'] === $widths['right']
            && $widths['right'] === $widths['bottom']
            && $widths['bottom'] === $widths['left']);

        if ($allEqual) {
            /* Uniform: legacy "W S C" form, consumed by CssBuilder as --lg-border. */
            $line = '';
            if ($style !== '') $line = "{$widths['top']} $style";
            else               $line = $widths['top'];
            return $color !== '' ? "$line $color" : $line;
        }
        if (!$allEmpty) {
            /* Per-side: 6-token form. CssBuilder detects this and emits
               border-*-width longhand instead of --lg-border. Empty sides
               become "0" so the CSS string is always well-formed. */
            $t = $widths['top']    !== '' ? $widths['top']    : '0';
            $r = $widths['right']  !== '' ? $widths['right']  : '0';
            $b = $widths['bottom'] !== '' ? $widths['bottom'] : '0';
            $l = $widths['left']   !== '' ? $widths['left']   : '0';
            $effectiveStyle = $style !== '' ? $style : 'solid';
            $line = "$t $r $b $l $effectiveStyle";
            return $color !== '' ? "$line $color" : $line;
        }
        /* No widths set — fall through to legacy uniform with style/color only. */
        $line = $style;
        if ($line === '') return '';
        return $color !== '' ? "$line $color" : $line;
    }

    /** Parse margin-block shorthand: 1 value = both sides, 2 values = top/bottom. */
    public static function parse_margin_block(string $v): array
    {
        $v = trim($v);
        if ($v === '') return ['top' => '', 'bottom' => ''];
        $parts = preg_split('/\s+/', $v) ?: [];
        return count($parts) === 1
            ? ['top' => $parts[0], 'bottom' => $parts[0]]
            : ['top' => $parts[0], 'bottom' => $parts[1]];
    }

    public static function compose_margin_block(array $sides): string
    {
        $t = trim((string) ($sides['top']    ?? ''));
        $b = trim((string) ($sides['bottom'] ?? ''));
        if ($t === '' && $b === '') return '';
        $t = $t !== '' ? $t : '0';
        $b = $b !== '' ? $b : '0';
        return $t === $b ? $t : "$t $b";
    }

    /* ── Save ────────────────────────────────────────────────────── */

    public static function handle_save(): void
    {
        /* DIAGNOSTIC: write directly to a file so we can prove this
           runs even if error_log is being suppressed. */
        @file_put_contents(
            WP_CONTENT_DIR . '/lg-v2-save-trace.log',
            '[' . date('c') . '] handle_save ENTER ' . php_sapi_name() . "\n",
            FILE_APPEND
        );
        if (!current_user_can(self::CAPABILITY)) wp_die('Forbidden');
        check_admin_referer(self::NONCE_ACTION);

        /* Brand palette: only persist values that differ from the baked default. */
        $rawBrand   = isset($_POST['brand']) && is_array($_POST['brand']) ? wp_unslash($_POST['brand']) : [];
        $tokens     = Theme::tokens();
        $cleanBrand = [];
        foreach ($tokens as $tokenName => $meta) {
            $v = isset($rawBrand[$tokenName]) ? trim((string) $rawBrand[$tokenName]) : '';
            if ($v === '') continue;
            if (strcasecmp($v, (string) $meta['default']) === 0) continue;
            $cleanBrand[$tokenName] = self::sanitize_css_value($v);
        }
        update_option(LG_LAYOUT_V2_BRAND_OPTION, $cleanBrand, true);

        /* Block styles: _global + per-block. */
        $rawStyles = isset($_POST['styles']) && is_array($_POST['styles']) ? wp_unslash($_POST['styles']) : [];
        $manifests = Manifest::all();
        $clean     = [];

        /* Global. */
        $rawG = is_array($rawStyles[self::GLOBAL_KEY] ?? null) ? $rawStyles[self::GLOBAL_KEY] : [];
        $gC = self::sanitize_group($rawG['container'] ?? [], self::CANONICAL_CONTAINER, []);
        $gT = self::sanitize_group($rawG['text']      ?? [], self::CANONICAL_TEXT,      []);
        $globalEntry = [];
        if ($gC) $globalEntry['container'] = $gC;
        if ($gT) $globalEntry['text']      = $gT;
        if ($globalEntry) $clean[self::GLOBAL_KEY] = $globalEntry;

        /* Per-block. */
        foreach ($manifests as $name => $m) {
            $rawEntry = is_array($rawStyles[$name] ?? null) ? $rawStyles[$name] : [];
            $entry    = self::sanitize_block_entry($rawEntry, $m);
            if ($entry) $clean[$name] = $entry;
        }

        update_option(LG_LAYOUT_V2_STYLE_OPTION, $clean, true);

        /* Object-cache flush for the two options we just wrote. Some hosts
           run Redis/Memcached drop-ins that return stale alloptions inside
           the same request that wrote to them — which would make the regen
           below build CSS from yesterday's values. Belt: pass the freshly
           sanitized arrays straight into regen so it never has to re-read.
           Suspenders: still flush so any other code in this request reads
           the new values via get_option. */
        wp_cache_delete(LG_LAYOUT_V2_BRAND_OPTION, 'options');
        wp_cache_delete(LG_LAYOUT_V2_STYLE_OPTION, 'options');
        wp_cache_delete('alloptions', 'options');

        @file_put_contents(
            WP_CONTENT_DIR . '/lg-v2-save-trace.log',
            sprintf("[%s] about to regen blocks=%d brand=%d\n", date('c'), count($clean), count($cleanBrand)),
            FILE_APPEND
        );
        $regenOk = WpAssets::regenerate_bundle($cleanBrand, $clean);
        @file_put_contents(
            WP_CONTENT_DIR . '/lg-v2-save-trace.log',
            '[' . date('c') . '] regen returned: ' . ($regenOk ? 'true' : 'false') . "\n",
            FILE_APPEND
        );

        /* Bust the per-post rendered-HTML cache for anonymous viewers so
           a style tweak shows up on next page-load instead of next cache
           expiry. Logged-in users always render fresh; this is for guests. */
        update_option('lg_layout_v2_cache_epoch', (string) time(), false);

        $redirect = add_query_arg(['page' => self::MENU_SLUG, 'updated' => 1], admin_url('admin.php'));
        /* Preserve the active tab across the save round-trip. The browser
           strips fragments from POST requests, so the form's submit handler
           writes the current panel id into a hidden field; here we append it
           as the URL fragment so the dash JS's hash-handler reopens it. */
        $activeTab = isset($_POST['active_tab']) ? (string) $_POST['active_tab'] : '';
        if (preg_match('/^lg-v2-[a-z0-9_\-]+$/i', $activeTab)) {
            $redirect .= '#' . $activeTab;
        }
        wp_safe_redirect($redirect);
        exit;
    }

    /** Sanitize one block's submitted entry against its manifest. */
    private static function sanitize_block_entry(array $raw, array $m): array
    {
        $out = [];

        /* Inherit toggle wins: if on, skip everything else; the block just
           rides on global + manifest defaults. */
        if (!empty($raw['_inherit_global'])) {
            return ['_inherit_global' => true];
        }

        $container = self::sanitize_group(
            $raw['container'] ?? [],
            $m['vars']['container'] ?? [],
            $m['defaults']['container'] ?? []
        );
        if ($container) $out['container'] = $container;

        $text = self::sanitize_group(
            $raw['text'] ?? [],
            $m['vars']['text'] ?? [],
            $m['defaults']['text'] ?? []
        );
        if ($text) $out['text'] = $text;

        /* Variants */
        $variants = [];
        foreach (($m['variants'] ?? []) as $vname => $_vdef) {
            $vraw = is_array($raw['variants'][$vname] ?? null) ? $raw['variants'][$vname] : [];
            $vDef = self::resolve_variant_defaults($m, $vname);
            $vC = self::sanitize_group($vraw['container'] ?? [], $m['vars']['container'] ?? [], $vDef['container']);
            $vT = self::sanitize_group($vraw['text']      ?? [], $m['vars']['text'] ?? [],      $vDef['text']);
            $vEntry = [];
            if ($vC) $vEntry['container'] = $vC;
            if ($vT) $vEntry['text']      = $vT;
            $vLabel = isset($vraw['__label']) ? sanitize_text_field((string) $vraw['__label']) : '';
            if ($vLabel !== '') $vEntry['__label'] = $vLabel;
            if ($vEntry) $variants[$vname] = $vEntry;
        }
        if ($variants) $out['variants'] = $variants;

        /* Sub-targets */
        $subs = [];
        foreach (($m['sub_targets'] ?? []) as $stKey => $stDef) {
            $sraw = is_array($raw['sub_targets'][$stKey] ?? null) ? $raw['sub_targets'][$stKey] : [];
            $sC = self::sanitize_group($sraw['container'] ?? [], $stDef['vars']['container'] ?? [], $stDef['defaults']['container'] ?? []);
            $sT = self::sanitize_group($sraw['text']      ?? [], $stDef['vars']['text']      ?? [], $stDef['defaults']['text']      ?? []);
            $sEntry = [];
            if ($sC) $sEntry['container'] = $sC;
            if ($sT) $sEntry['text']      = $sT;
            if ($sEntry) $subs[$stKey] = $sEntry;
        }
        if ($subs) $out['sub_targets'] = $subs;

        return $out;
    }

    /**
     * Read each declared var from the raw form data, extract the value
     * (handling padding 4-up + border line/color composites), drop values
     * that match the supplied default (no-op overrides are noise).
     */
    private static function sanitize_group(array $values, array $declared, array $defaults): array
    {
        /* $defaults is still part of the signature for parity with callers
           (some compose helpers read it), but values that happen to equal
           the default are NO LONGER dropped — the previous "sparse store"
           optimization erased explicit user intent (e.g. clicking the T
           transparent button → bg="transparent" looked unsaved because it
           matched the manifest default and got stripped). Empty inputs
           still drop, so clearing a field still falls back to default. */
        unset($defaults);
        $out = [];
        foreach ($declared as $varName) {
            $v = self::extract_var_value($values, $varName);
            if ($v === '') continue;
            $out[$varName] = self::sanitize_css_value($v);
        }
        return $out;
    }

    /** Pull a single var's value out of the form data, handling composite shapes. */
    private static function extract_var_value(array $values, string $varName): string
    {
        if ($varName === 'padding') {
            $pad = $values['padding'] ?? null;
            return is_array($pad) ? self::compose_padding($pad) : trim((string) ($pad ?? ''));
        }
        if ($varName === 'margin-block') {
            $mb = $values['margin-block'] ?? null;
            return is_array($mb) ? self::compose_margin_block($mb) : trim((string) ($mb ?? ''));
        }
        if ($varName === 'border') {
            $w     = $values['border__width'] ?? '';
            $style = trim((string) ($values['border__style'] ?? ''));
            $color = trim((string) ($values['border__color'] ?? ''));
            /* Form may submit either a flat string (legacy single-width
               input) or an array shape {top,right,bottom,left} from the
               new per-side steppers. compose_border_full handles both. */
            $widthArg = is_array($w) ? $w : (string) $w;
            return self::compose_border_full($widthArg, $style, $color);
        }
        return trim((string) ($values[$varName] ?? ''));
    }

    /** Strip characters that could break out of a declaration. */
    private static function sanitize_css_value(string $v): string
    {
        return (string) preg_replace('/[\\\\;{}<>]/', '', $v);
    }

    /* ── Inline styles + script ──────────────────────────────────── */

    private static function print_styles(): void
    {
        ?>
        <style>
            .lg-v2-dash .lg-v2-tablist { display:flex; flex-wrap:wrap; gap:4px; border-bottom:1px solid #c3c4c7; margin:16px 0 0; }
            .lg-v2-dash .lg-v2-tab { background:#f6f7f7; border:1px solid #c3c4c7; border-bottom:none; padding:6px 12px; cursor:pointer; font-size:13px; border-radius:4px 4px 0 0; }
            .lg-v2-dash .lg-v2-tab.is-active { background:#fff; font-weight:600; }
            .lg-v2-dash .lg-v2-tab.is-brand { background:#e8f0d6; }
            .lg-v2-dash .lg-v2-tab.is-brand.is-active { background:#fff; }
            .lg-v2-dash .lg-v2-tab.is-global { background:#fff3d6; }
            .lg-v2-dash .lg-v2-tab.is-global.is-active { background:#fff; }
            .lg-v2-dash .lg-v2-panel { display:none; background:#fff; border:1px solid #c3c4c7; border-top:none; padding:18px 22px; }
            .lg-v2-dash .lg-v2-panel.is-active { display:block; }
            .lg-v2-dash .lg-v2-panel header h2 { display:flex; align-items:center; gap:12px; margin-top:0; }
            .lg-v2-dash .lg-v2-fields { border:1px solid #dcdcde; padding:12px 16px; margin:12px 0; border-radius:4px; }
            .lg-v2-dash .lg-v2-fields legend { padding:0 6px; font-weight:600; }
            .lg-v2-dash .lg-v2-fields--sub { background:#fafafa; }
            .lg-v2-dash .lg-v2-hint { font-weight:400; color:#666; font-size:12px; margin-left:6px; }
            .lg-v2-dash .lg-v2-subgroup { margin:10px 0 4px; font-size:12px; color:#444; }
            .lg-v2-dash .lg-v2-row { display:grid; grid-template-columns:200px 1fr; align-items:center; gap:10px; margin:8px 0; }
            .lg-v2-dash .lg-v2-label-main { display:block; font-family:Menlo,Consolas,monospace; font-size:12px; color:#1d2327; }
            .lg-v2-dash .lg-v2-label-desc { display:block; font-size:11px; color:#666; }
            .lg-v2-dash .lg-v2-field { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
            .lg-v2-dash .lg-v2-input { font-family:Menlo,Consolas,monospace; font-size:12px; }
            .lg-v2-dash .lg-v2-field > .lg-v2-input { width:100%; max-width:380px; }
            .lg-v2-dash .lg-v2-input--md { max-width:200px; }
            .lg-v2-dash .lg-v2-input--xs { max-width:64px; }
            .lg-v2-dash .lg-v2-select { min-width:200px; height:30px; padding:0 24px 0 8px; border:1px solid #d0d5dd; border-radius:4px; background-color:#fff; }
            .lg-v2-dash .lg-v2-select:focus { border-color:#2271b1; box-shadow:0 0 0 1px #2271b1; outline:none; }
            .lg-v2-dash .lg-v2-reset { font-size:12px; padding:2px 10px; }
            .lg-v2-dash .lg-v2-inherit { display:block; margin-top:8px; font-size:13px; }
            .lg-v2-dash .lg-v2-panel.is-inheriting .lg-v2-fields { opacity:0.5; pointer-events:none; }

            /* ── Inherit-vs-local field state ──────────────────────────────
               A row with no stored value renders empty inputs whose placeholder
               shows the inherited default. A row with any locally-set value
               gets a subtle accent so authors can scan a panel and see at a
               glance which fields are overrides. */
            .lg-v2-dash .lg-v2-row { padding-left:8px; border-left:3px solid transparent; transition:border-color 120ms ease; }
            .lg-v2-dash .lg-v2-row.is-locally-set { border-left-color:#2271b1; }
            .lg-v2-dash .lg-v2-row.is-locally-set .lg-v2-label-main { color:#2271b1; font-weight:600; }
            .lg-v2-dash .lg-v2-input::placeholder,
            .lg-v2-dash .lg-v2-stepper__input::placeholder { color:#9aa0a6; font-style:italic; }

            .lg-v2-dash .lg-v2-copy-global { font-size:12px; padding:2px 10px; }

            /* "T" transparent button next to each color picker. wpColorPicker
               can't represent `transparent`, so this button bypasses the
               picker entirely by injecting a hidden input that submits the
               keyword. When active, the picker is dimmed and a checkerboard
               background hints at the locked-transparent state. */
            .lg-v2-dash .lg-v2-tx-btn {
                margin-left:6px; width:28px; height:30px; padding:0;
                border:1px solid #d0d5dd; border-radius:4px; background:#f6f7f9;
                cursor:pointer; font-size:12px; font-weight:600; color:#555;
                vertical-align:middle;
            }
            .lg-v2-dash .lg-v2-tx-btn:hover { background:#eef0f3; color:#1d2327; }
            .lg-v2-dash .lg-v2-tx-btn[aria-pressed="true"] {
                background:#dde4f0; border-color:#2271b1; color:#2271b1;
            }
            .lg-v2-dash .wp-picker-container.lg-v2-tx-locked { opacity:0.45; pointer-events:none; }
            .lg-v2-dash .wp-picker-container.lg-v2-tx-locked + .lg-v2-tx-btn::after {
                content:" — transparent"; font-weight:400; color:#2271b1; margin-left:6px;
            }

            /* ── Elementor-style stepper ───────────────────────────────── */
            .lg-v2-dash .lg-v2-stepper { display:inline-flex; align-items:stretch; border:1px solid #d0d5dd; border-radius:4px; background:#fff; overflow:hidden; height:30px; }
            .lg-v2-dash .lg-v2-stepper:focus-within { border-color:#2271b1; box-shadow:0 0 0 1px #2271b1; }
            .lg-v2-dash .lg-v2-stepper__btn { background:#f6f7f9; border:none; border-right:1px solid #d0d5dd; width:24px; cursor:pointer; font-size:14px; line-height:1; color:#555; padding:0; user-select:none; }
            .lg-v2-dash .lg-v2-stepper__btn:last-child { border-right:none; border-left:1px solid #d0d5dd; }
            .lg-v2-dash .lg-v2-stepper__btn:hover { background:#eef0f3; color:#1d2327; }
            .lg-v2-dash .lg-v2-stepper__btn:active { background:#e1e4e8; }
            .lg-v2-dash .lg-v2-stepper__input { border:none !important; outline:none; box-shadow:none !important; padding:4px 6px; min-width:0; flex:1; text-align:center; background:transparent; }
            .lg-v2-dash .lg-v2-stepper__input:focus { outline:none !important; box-shadow:none !important; }

            /* ── Padding 4-up + link ──────────────────────────────────── */
            .lg-v2-dash .lg-v2-pad { display:flex; gap:6px; align-items:flex-start; flex-wrap:wrap; }
            .lg-v2-dash .lg-v2-pad__cell { display:flex; flex-direction:column; align-items:center; gap:2px; }
            .lg-v2-dash .lg-v2-pad__lbl { font-size:10px; color:#666; text-transform:uppercase; letter-spacing:0.04em; }
            .lg-v2-dash .lg-v2-pad__link { width:30px; height:30px; padding:0; border:1px solid #d0d5dd; border-radius:4px; background:#f6f7f9; cursor:pointer; font-size:14px; line-height:1; color:#555; margin-left:4px; align-self:flex-start; position:relative; }
            .lg-v2-dash .lg-v2-pad__link:hover { background:#eef0f3; color:#1d2327; }
            .lg-v2-dash .lg-v2-pad__link[aria-pressed="true"] { background:#dde4f0; border-color:#2271b1; color:#2271b1; }
            .lg-v2-dash .lg-v2-pad__link-off { display:none; }
            .lg-v2-dash .lg-v2-pad__link[aria-pressed="false"] .lg-v2-pad__link-on { display:none; }
            .lg-v2-dash .lg-v2-pad__link[aria-pressed="false"] .lg-v2-pad__link-off { display:inline; }
            .lg-v2-dash .lg-v2-pad.is-linked .lg-v2-pad__input { background:#fafafa; }
            .lg-v2-dash .lg-v2-pad.is-linked .lg-v2-pad__cell:not(:first-child) .lg-v2-stepper { opacity:0.55; }

            /* ── Preview modal ─────────────────────────────────────────── */
            .lg-v2-dash .lg-v2-preview-btn { font-size:12px; padding:2px 12px; }
            .lg-v2-modal[hidden] { display:none; }
            .lg-v2-modal { position:fixed; inset:0; z-index:160000; display:flex; align-items:stretch; justify-content:center; }
            .lg-v2-modal__backdrop { position:absolute; inset:0; background:rgba(20,22,24,0.55); }
            .lg-v2-modal__dialog { position:relative; margin:24px; width:100%; max-width:1200px; background:#fff; border-radius:6px; box-shadow:0 18px 60px rgba(0,0,0,0.4); display:flex; flex-direction:column; overflow:hidden; }
            .lg-v2-modal__head { display:flex; align-items:center; gap:12px; padding:10px 14px; border-bottom:1px solid #dcdcde; background:#f6f7f7; flex-wrap:wrap; }
            .lg-v2-modal__title { font-size:14px; }
            .lg-v2-modal__variants { display:flex; gap:4px; flex-wrap:wrap; flex:1; }
            .lg-v2-modal__variant { background:#fff; border:1px solid #c3c4c7; padding:4px 10px; font-size:12px; cursor:pointer; border-radius:3px; }
            .lg-v2-modal__variant.is-active { background:#2271b1; color:#fff; border-color:#2271b1; }
            .lg-v2-modal__status { font-size:11px; color:#666; font-style:italic; min-width:60px; text-align:right; }
            .lg-v2-modal__body { flex:1; background:#fafafa; padding:16px; overflow:auto; }
            .lg-v2-modal__frame { width:100%; min-height:60vh; height:60vh; border:1px solid #dcdcde; background:#fff; border-radius:4px; display:block; }
        </style>
        <?php
    }

    private static function print_script(): void
    {
        ?>
        <script>
        (function () {
            var tabs   = document.querySelectorAll('.lg-v2-dash .lg-v2-tab');
            var panels = document.querySelectorAll('.lg-v2-dash .lg-v2-panel');
            function activate(id) {
                tabs.forEach(function (t) { t.classList.toggle('is-active', t.dataset.target === id); });
                panels.forEach(function (p) { p.classList.toggle('is-active', p.id === id); });
                try { history.replaceState(null, '', '#' + id); } catch (e) {}
                /* Mirror into the hidden form field so the save handler can
                   bounce us back to the same tab via URL fragment. */
                var hf = document.getElementById('lg-v2-active-tab');
                if (hf) hf.value = id;
            }
            tabs.forEach(function (t) { t.addEventListener('click', function () { activate(t.dataset.target); }); });
            var hash = location.hash.replace('#', '');
            var initial = hash && document.getElementById(hash) ? hash : 'lg-v2-_brand';
            if (document.getElementById(initial)) activate(initial);

            /* Inherit toggle: visually disable per-block fields when on. The
               disabled inputs don't post their values, so the save handler
               sees only _inherit_global and skips emission for the block. */
            document.querySelectorAll('[data-lg-inherit-toggle]').forEach(function (cb) {
                function sync() {
                    var panel = cb.closest('.lg-v2-panel');
                    if (!panel) return;
                    panel.classList.toggle('is-inheriting', cb.checked);
                    panel.querySelectorAll('.lg-v2-fields input, .lg-v2-fields select').forEach(function (i) {
                        i.disabled = cb.checked;
                    });
                }
                sync();
                cb.addEventListener('change', sync);
            });

            /* Row state: mark .lg-v2-row as .is-locally-set when any of its
               inputs holds a non-empty value. Drives the accent CSS so authors
               see at a glance which fields are overrides. Selects with empty
               value (the "— inherit —" option) count as inherited. */
            function rowHasValue(row) {
                var inputs = row.querySelectorAll('input[type=text], input[type=hidden], select');
                for (var i = 0; i < inputs.length; i++) {
                    var el = inputs[i];
                    /* wpColorPicker injects helper inputs (.wp-color-result, etc.)
                       — only count inputs that carry a name attribute, since those
                       are the ones that submit. */
                    if (!el.name) continue;
                    if ((el.value || '').trim() !== '') return true;
                }
                return false;
            }
            function syncRow(row) {
                row.classList.toggle('is-locally-set', rowHasValue(row));
            }
            function syncAllRows(scope) {
                (scope || document).querySelectorAll('.lg-v2-row').forEach(syncRow);
            }
            syncAllRows();
            document.querySelectorAll('.lg-v2-dash').forEach(function (dash) {
                dash.addEventListener('input',  function (e) { var r = e.target.closest('.lg-v2-row'); if (r) syncRow(r); });
                dash.addEventListener('change', function (e) { var r = e.target.closest('.lg-v2-row'); if (r) syncRow(r); });
            });

            /* Copy Global → Local: snapshot the global panel's current input
               values into this block's matching inputs, by rewriting the
               styles[_global][...] prefix to styles[<blockName>][...]. Only
               fields that exist in both panels are touched; block-specific
               fields (variants, sub_targets, padding sides global doesn't have)
               are left alone. */
            document.querySelectorAll('[data-lg-copy-from-global]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var panel = btn.closest('.lg-v2-panel');
                    if (!panel) return;
                    var blockName = panel.id.replace(/^lg-v2-/, '');
                    var globalPanel = document.getElementById('lg-v2-_global');
                    if (!globalPanel) return;
                    if (!confirm('Copy current Global Defaults values into ' + blockName + '? Existing local values for those fields will be overwritten.')) return;

                    var count = 0;
                    globalPanel.querySelectorAll('input[name], select[name]').forEach(function (src) {
                        if (!src.name.startsWith('styles[_global]')) return;
                        /* Resolve effective source value: stored value, or — if
                           empty — the input's placeholder (which is the theme
                           default the global panel is currently inheriting). */
                        var srcVal = (src.value || '').trim();
                        if (srcVal === '' && src.placeholder) srcVal = src.placeholder;
                        if (srcVal === '') return;

                        var targetName = src.name.replace('styles[_global]', 'styles[' + blockName + ']');
                        var dst = panel.querySelector('[name="' + targetName.replace(/"/g, '\\"') + '"]');
                        if (!dst) return;
                        dst.value = srcVal;
                        dst.dispatchEvent(new Event('input',  { bubbles: true }));
                        dst.dispatchEvent(new Event('change', { bubbles: true }));
                        if (window.jQuery) { try { window.jQuery(dst).trigger('change'); } catch (e) {} }
                        count++;
                    });
                    syncAllRows(panel);
                });
            });

            /* Reset: clear every input under the panel so manifest/global defaults
               re-take effect on save. Color-picker inputs are doubled in the DOM
               by wpColorPicker; clearing the underlying input + triggering change
               keeps the swatch in sync. */
            document.querySelectorAll('.lg-v2-reset').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var panel = btn.closest('.lg-v2-panel');
                    if (!panel) return;
                    if (!confirm('Clear stored overrides for this panel? Defaults take over on save.')) return;
                    panel.querySelectorAll('input[type=text]').forEach(function (inp) {
                        inp.value = '';
                        if (window.jQuery) { try { window.jQuery(inp).trigger('change'); } catch (e) {} }
                    });
                    panel.querySelectorAll('select').forEach(function (s) { s.value = ''; });
                    syncAllRows(panel);
                });
            });

            /* Stepper: parse leading number, bump by step, preserve unit.
               Supports any text value — "12px", "1.5em", "var(--foo)" (skipped). */
            function bumpStepper(input, step) {
                var v = input.value || input.placeholder || '';
                var m = v.match(/^(-?\d*\.?\d+)(.*)$/);
                var unit = input.closest('.lg-v2-stepper')?.dataset.unit || '';
                if (!m) {
                    /* Non-numeric value: just write the unit baseline. */
                    input.value = (step > 0 ? Math.abs(step) : 0) + unit;
                } else {
                    var num = parseFloat(m[1]);
                    var existingUnit = (m[2] || unit || '').trim();
                    var next = num + step;
                    if (next < 0) next = 0;
                    /* Keep one decimal for fractional steps; otherwise integer. */
                    var disp = (Math.abs(step) < 1) ? next.toFixed(1) : next.toFixed(0);
                    input.value = disp + existingUnit;
                }
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
            document.querySelectorAll('.lg-v2-stepper').forEach(function (s) {
                var input = s.querySelector('.lg-v2-stepper__input');
                var step  = parseFloat(s.dataset.step || '4');
                s.querySelector('.lg-v2-stepper__inc')?.addEventListener('click', function () { bumpStepper(input, +step); });
                s.querySelector('.lg-v2-stepper__dec')?.addEventListener('click', function () { bumpStepper(input, -step); });
            });

            /* Padding / margin-block link toggle: any N>=2 cells. When linked,
               typing in any cell mirrors to all others. Clicking the chain
               flips state; on re-link the first cell wins. */
            document.querySelectorAll('.lg-v2-pad').forEach(function (pad) {
                var link = pad.querySelector('.lg-v2-pad__link');
                var inputs = Array.prototype.slice.call(pad.querySelectorAll('.lg-v2-pad__input'));
                if (!link || inputs.length < 2) return;

                function isLinked() { return link.getAttribute('aria-pressed') === 'true'; }
                function mirror(fromInput) {
                    if (!isLinked()) return;
                    inputs.forEach(function (i) { if (i !== fromInput) i.value = fromInput.value; });
                }

                link.addEventListener('click', function () {
                    var next = !isLinked();
                    link.setAttribute('aria-pressed', next ? 'true' : 'false');
                    pad.classList.toggle('is-linked', next);
                    if (next) mirror(inputs[0]);
                });

                inputs.forEach(function (inp) {
                    inp.addEventListener('input', function () { mirror(inp); });
                });
            });

            /* Color picker: wpColorPicker shows a swatch but the input stays a
               normal text input so non-hex values (var(--lg-…), rgba()) survive.
               Defer init to window.load so any plugin that *patches* wpColorPicker
               (e.g. ACF Pro's wp-color-picker-alpha) finishes loading first;
               otherwise widgets initialize against the unpatched library and
               miss values like #abc123 because the alpha shim never wires up. */
            /* wpColorPicker is backed by Iris, which only understands hex/rgb
               literals. Any keyword-style value (transparent, currentColor,
               none, inherit, var(--…), rgba()) is silently reverted to the
               last-valid hex on blur. We need those values to round-trip
               because our manifests use `transparent`, `var(--lg-cream)`,
               etc. as defaults — so we intercept blur and paste, and if the
               user typed a non-hex keyword we re-assert it after Iris has
               finished its validation pass. */
            var KEYWORD_COLOR_RE = /^(?:transparent|currentcolor|inherit|initial|unset|none|revert(?:-layer)?)$/i;
            function isKeywordColor(v) {
                v = (v || '').trim();
                if (v === '') return false;
                if (KEYWORD_COLOR_RE.test(v)) return true;
                if (/^var\s*\(/i.test(v))   return true;   /* var(--foo) */
                if (/^rgba?\s*\(/i.test(v)) return true;   /* rgba(…) — Iris half-supports rgb but rejects spaces/percent variants */
                if (/^hsla?\s*\(/i.test(v)) return true;
                return false;
            }
            function preserveKeyword(input) {
                var $i = window.jQuery(input);
                var raw = (input.value || '').trim();
                if (!isKeywordColor(raw)) return;
                /* Iris reads .val() on blur and may re-write it to its
                   internal hex. Re-assert ours on the next tick, after
                   Iris's handler has run. Also propagate to the visible
                   swatch's bookkeeping so the picker doesn't visually
                   contradict the stored value. */
                setTimeout(function () {
                    $i.val(raw);
                    try { $i.wpColorPicker('color', raw); } catch (e) { /* Iris will reject; ignore */ }
                    $i.val(raw); /* in case wpColorPicker('color', …) re-cleared it */
                    input.dispatchEvent(new Event('input',  { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }, 0);
            }
            /* Per-color "T" button. wpColorPicker (Iris) refuses to round-trip
               keyword values, so instead of fighting it we route around it:
               when T is pressed, we strip the `name` attribute off the picker
               input (so its hex value never submits) and inject a sibling
               hidden input holding `transparent` under the original name.
               Clicking T again restores the picker. */
            function lockTransparent(input) {
                var origName = input.getAttribute('name') || input.dataset.lgName || '';
                if (!origName) return;
                input.dataset.lgName = origName;
                input.removeAttribute('name');
                var wrap = input.closest('.wp-picker-container') || input.parentElement;
                if (!wrap) return;
                var hidden = wrap.parentElement.querySelector('input[type=hidden][data-lg-tx-for="' + CSS.escape(origName) + '"]');
                if (!hidden) {
                    hidden = document.createElement('input');
                    hidden.type  = 'hidden';
                    hidden.name  = origName;
                    hidden.value = 'transparent';
                    hidden.dataset.lgTxFor = origName;
                    wrap.parentElement.insertBefore(hidden, wrap.nextSibling);
                }
                wrap.classList.add('lg-v2-tx-locked');
                var row = input.closest('.lg-v2-row');
                if (row) row.classList.add('is-locally-set');
                var btn = wrap.parentElement.querySelector('.lg-v2-tx-btn[data-lg-tx-btn-for="' + CSS.escape(origName) + '"]');
                if (btn) { btn.setAttribute('aria-pressed', 'true'); btn.title = 'Stored as transparent — click to restore picker'; }
            }
            function unlockTransparent(input) {
                var origName = input.dataset.lgName || '';
                if (!origName) return;
                input.setAttribute('name', origName);
                var wrap = input.closest('.wp-picker-container') || input.parentElement;
                var hidden = wrap.parentElement.querySelector('input[type=hidden][data-lg-tx-for="' + CSS.escape(origName) + '"]');
                if (hidden) hidden.remove();
                wrap.classList.remove('lg-v2-tx-locked');
                var btn = wrap.parentElement.querySelector('.lg-v2-tx-btn[data-lg-tx-btn-for="' + CSS.escape(origName) + '"]');
                if (btn) { btn.setAttribute('aria-pressed', 'false'); btn.title = 'Set value to transparent'; }
                var row = input.closest('.lg-v2-row');
                if (row) setTimeout(function () { syncRow(row); }, 0);
            }
            function attachTransparentBtn(input) {
                var origName = input.getAttribute('name') || '';
                if (!origName) return;
                var wrap = input.closest('.wp-picker-container');
                if (!wrap) return;
                /* Don't double-attach. */
                if (wrap.parentElement.querySelector('.lg-v2-tx-btn[data-lg-tx-btn-for="' + CSS.escape(origName) + '"]')) return;
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'lg-v2-tx-btn';
                btn.dataset.lgTxBtnFor = origName;
                btn.setAttribute('aria-pressed', 'false');
                btn.title = 'Set value to transparent';
                btn.textContent = 'T';
                wrap.parentElement.insertBefore(btn, wrap.nextSibling);
                btn.addEventListener('click', function () {
                    var pressed = btn.getAttribute('aria-pressed') === 'true';
                    if (pressed) unlockTransparent(input);
                    else         lockTransparent(input);
                });
                /* If the stored value already is "transparent", start locked. */
                if ((input.value || '').trim().toLowerCase() === 'transparent') {
                    lockTransparent(input);
                }
            }

            function initColorPickers() {
                if (!window.jQuery || !window.jQuery.fn.wpColorPicker) return;
                var palette = (window.lgLayoutV2Dash && window.lgLayoutV2Dash.palette) || [];
                window.jQuery('.lg-v2-color').each(function () {
                    var $i = window.jQuery(this);
                    if ($i.data('lg-cp-init')) return;
                    $i.data('lg-cp-init', 1);
                    $i.wpColorPicker({ palettes: palette });
                    /* Bind on the underlying input itself, not the
                       Iris-injected helpers. Capture phase so we run
                       before Iris's own blur handler doesn't matter
                       — we want to run *after* it, hence setTimeout. */
                    this.addEventListener('blur',  function () { preserveKeyword(this); });
                    this.addEventListener('paste', function () { var el = this; setTimeout(function () { preserveKeyword(el); }, 0); });
                    attachTransparentBtn(this);
                });
            }
            if (document.readyState === 'complete') {
                initColorPickers();
            } else {
                window.addEventListener('load', initColorPickers);
            }
        })();

        /* ── Preview modal ─────────────────────────────────────────────
           Opens an iframe with sample markup for the clicked block,
           re-fetches the v2 CSS bundle from the AJAX preview endpoint
           on every form edit (debounced), and swaps variant modifier
           classes + per-variant innerHTML when a variant tab is clicked. */
        (function () {
            var dataEl = document.getElementById('lg-v2-preview-data');
            var modal  = document.getElementById('lg-v2-preview-modal');
            if (!dataEl || !modal) return;
            var data;
            try { data = JSON.parse(dataEl.textContent || '{}'); } catch (e) { data = {}; }
            var iframe       = modal.querySelector('[data-lg-preview-iframe]');
            var variantTabs  = modal.querySelector('[data-lg-preview-variant-tabs]');
            var titleEl      = modal.querySelector('.lg-v2-modal__title');
            var statusEl     = modal.querySelector('[data-lg-preview-status]');
            var form         = document.querySelector('.lg-v2-dash form');
            var ajaxUrl      = (window.ajaxurl) || '/wp-admin/admin-ajax.php';
            var currentBlock = null;
            var currentVar   = '_default';
            var debounceTimer = null;
            var inflight    = null;

            function setStatus(s) { if (statusEl) statusEl.textContent = s || ''; }

            function buildSrcdoc(html) {
                return '<!doctype html><html><head><meta charset="utf-8">'
                    + '<style id="lg-v2-bundle"></style></head>'
                    + '<body><div class="lg-article">' + html + '</div></body></html>';
            }

            function fetchCss() {
                if (!form || !currentBlock) return;
                setStatus('updating…');
                var fd = new FormData(form);
                fd.set('action', 'lg_v2_preview_css');
                if (inflight && typeof inflight.abort === 'function') {
                    try { inflight.abort(); } catch (e) {}
                }
                var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
                inflight = ctrl;
                fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: fd,
                    signal: ctrl ? ctrl.signal : undefined
                }).then(function (r) { return r.text(); })
                  .then(function (css) {
                      var doc = iframe.contentDocument;
                      if (!doc) return;
                      var st = doc.getElementById('lg-v2-bundle');
                      if (st) st.textContent = css;
                      setStatus('live');
                      setTimeout(function(){ if (statusEl && statusEl.textContent === 'live') setStatus(''); }, 600);
                  })
                  .catch(function (e) {
                      if (e && e.name === 'AbortError') return;
                      setStatus('error');
                  });
            }

            function debouncedFetchCss() {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(fetchCss, 220);
            }

            function applyVariant(v) {
                currentVar = v;
                variantTabs.querySelectorAll('.lg-v2-modal__variant').forEach(function (b) {
                    b.classList.toggle('is-active', b.dataset.variant === v);
                });
                var doc = iframe.contentDocument;
                if (!doc) return;
                var entry = data[currentBlock];
                if (!entry) return;
                var root  = doc.querySelector('[data-lg-preview-root]');
                if (!root) return;
                var base  = entry.baseClass;
                /* Strip any prior variant modifier on the root. */
                var cls = root.className.split(/\s+/).filter(function (c) {
                    return c && c.indexOf(base + '--') !== 0;
                });
                if (v && v !== '_default') cls.push(base + '--' + v);
                root.className = cls.join(' ').trim();
                /* Per-variant innerHTML (for blocks where variants change
                   structure, e.g. callout note vs links). */
                var vmapScript = doc.querySelector('[data-lg-preview-variants]');
                if (vmapScript) {
                    var vmap = {};
                    try { vmap = JSON.parse(vmapScript.textContent || '{}'); } catch (e) {}
                    var html = vmap[v];
                    if (typeof html !== 'string' && v !== '_default') html = vmap._default;
                    if (typeof html === 'string') root.innerHTML = html;
                }
            }

            function openFor(blockName) {
                var entry = data[blockName];
                if (!entry) return;
                currentBlock = blockName;
                titleEl.textContent = 'Preview: ' + blockName;
                variantTabs.innerHTML = '';
                var addTab = function (label, v) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'lg-v2-modal__variant';
                    b.dataset.variant = v;
                    b.textContent = label;
                    b.addEventListener('click', function () { applyVariant(v); });
                    variantTabs.appendChild(b);
                };
                addTab('default', '_default');
                (entry.variants || []).forEach(function (v) { addTab(v, v); });
                modal.hidden = false;
                iframe.srcdoc = buildSrcdoc(entry.preview);
                var onload = function () {
                    iframe.removeEventListener('load', onload);
                    applyVariant('_default');
                    fetchCss();
                };
                iframe.addEventListener('load', onload);
            }

            function closeModal() {
                modal.hidden = true;
                currentBlock = null;
                setStatus('');
            }

            document.querySelectorAll('.lg-v2-preview-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    openFor(btn.getAttribute('data-lg-preview-block'));
                });
            });
            modal.querySelectorAll('[data-lg-preview-close]').forEach(function (el) {
                el.addEventListener('click', closeModal);
            });
            document.addEventListener('keydown', function (e) {
                if (!modal.hidden && e.key === 'Escape') closeModal();
            });

            if (form) {
                form.addEventListener('input',  function () { if (!modal.hidden) debouncedFetchCss(); });
                form.addEventListener('change', function () { if (!modal.hidden) debouncedFetchCss(); });
            }
        })();
        </script>
        <?php
    }
}
