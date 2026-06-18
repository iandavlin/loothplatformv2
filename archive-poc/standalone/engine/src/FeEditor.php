<?php
/**
 * FeEditor — front-end inline editor entry point.
 *
 * Phase 4, slice 1: viewer-gate predicate + header button + asset enqueue.
 * Save path (REST) and editor JS framework land in subsequent slices.
 *
 * Security model — three independent checks must all pass for an edit to
 * persist (see docs/ARCHITECTURE.md Phase 4 when written):
 *   1. Entry gate (this file): server-side predicate decides whether the
 *      button is emitted and whether editor assets/markers load.
 *   2. REST permission_callback (next slice): re-checks the predicate
 *      against the target post id from the payload, not the referrer.
 *   3. wp_rest nonce: standard CSRF guard on every save call.
 *
 * The button is never emitted for unauthorized viewers — no opacity trick,
 * no "secret" DOM. That's the only way the gate actually holds up.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class FeEditor
{
    /** Query var that, when set, boots editor mode on a v2-managed post. */
    public const QUERY_FLAG = 'lg_edit';

    public static function boot(): void
    {
        add_action('wp_body_open',      [self::class, 'render_header_button']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_button_style']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_editor_assets']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_link_edit_assets']);
        add_filter('body_class',        [self::class, 'body_class']);

        /* When editor mode is on, extend Isolate's allowlists so wp.media
           and its dependencies survive the dequeue pass. */
        add_filter('lg_layout_v2_isolate_allowlist_script', [self::class, 'extend_script_allowlist']);
        add_filter('lg_layout_v2_isolate_allowlist_style',  [self::class, 'extend_style_allowlist']);
        add_filter('lg_layout_v2_isolate_no_layer_wrap',    [self::class, 'extend_no_layer_wrap']);
    }

    public static function extend_no_layer_wrap(array $list): array
    {
        global $post;
        if (!self::is_active($post)) return $list;
        /* wp.media's chrome must be unlayered to render correctly — the
           @import-into-layer wrap suppresses the link's src and the rules
           are silently dropped (known Isolate issue, see NO_LAYER_WRAP). */
        return array_unique(array_merge($list, self::expand_deps(self::MEDIA_STYLES, 'style')));
    }

    /** Allowlist additions for the wp.media stack (only matters when active). */
    private const MEDIA_SCRIPTS = [
        'media-editor', 'media-views', 'media-models', 'media-audiovideo',
        'wp-mediaelement', 'mediaelement', 'mediaelement-core', 'mediaelement-migrate',
        'wp-plupload', 'plupload', 'plupload-handlers', 'moxiejs',
        'wp-api-request', 'wp-backbone', 'backbone', 'underscore', 'wp-util',
        'wp-i18n', 'wp-hooks', 'wp-dom-ready', 'imgareaselect',
        /* Classic TinyMCE (wp_enqueue_editor). The shim that exposes
           wp.editor.initialize/getContent/remove + the TinyMCE bundle. */
        'editor', 'wp-tinymce', 'wp-tinymce-root', 'word-count',
        'quicktags', 'wplink',
    ];
    private const MEDIA_STYLES = [
        'media-views', 'wp-mediaelement', 'mediaelement', 'imgareaselect', 'buttons',
        'editor-buttons', 'wp-jquery-ui-dialog',
    ];

    public static function extend_script_allowlist(array $list): array
    {
        global $post;
        if (!self::is_active($post)) return $list;
        /* Walk the dep tree of each media handle so transitive deps
           (utils, jquery-ui-sortable, wp-a11y, clipboard, …) survive
           Isolate's dequeue pass. Without this WP's verify_deps fails
           silently at print time and lg-fe-editor never loads. */
        return array_unique(array_merge($list, self::expand_deps(self::MEDIA_SCRIPTS, 'script')));
    }
    public static function extend_style_allowlist(array $list): array
    {
        global $post;
        if (!self::is_active($post)) return $list;
        return array_unique(array_merge($list, self::expand_deps(self::MEDIA_STYLES, 'style')));
    }

    /**
     * Given a list of handles, return the same list plus every transitive
     * dependency that's currently registered with WP. Bounded by WP's own
     * registry — handles not registered at the time we're called are simply
     * skipped (their deps can't be walked).
     */
    private static function expand_deps(array $handles, string $kind): array
    {
        $reg = $kind === 'script' ? $GLOBALS['wp_scripts'] : $GLOBALS['wp_styles'];
        if (!is_object($reg) || !isset($reg->registered)) return $handles;
        $seen = [];
        $stack = $handles;
        while ($stack) {
            $h = array_pop($stack);
            if (isset($seen[$h])) continue;
            $seen[$h] = true;
            $r = $reg->registered[$h] ?? null;
            if (!$r) continue;
            foreach ((array) $r->deps as $d) {
                if (!isset($seen[$d])) $stack[] = $d;
            }
        }
        return array_keys($seen);
    }

    /**
     * True iff the current viewer may edit $post.
     * Admins (manage_options) on any post; the actual post_author on their
     * own post. Role 'author' does not grant access — only the user whose
     * id matches post_author does.
     */
    public static function can_edit(?\WP_Post $post): bool
    {
        if (!$post instanceof \WP_Post) return false;
        if (!is_user_logged_in()) return false;
        if (!Plugin::manages($post)) return false;
        if (current_user_can('manage_options')) return true;
        return (int) $post->post_author === get_current_user_id();
    }

    /** True iff editor mode is active (predicate passes AND ?lg_edit=1). */
    public static function is_active(?\WP_Post $post): bool
    {
        if (!self::can_edit($post)) return false;
        return isset($_GET[self::QUERY_FLAG]) && $_GET[self::QUERY_FLAG] === '1';
    }

    /**
     * Emit the entry button at the top of <body>. Fixed-position, lives in
     * the header zone visually. Only rendered if the predicate passes.
     * Authors don't see the WP admin bar, so this is their only entry path.
     */
    public static function render_header_button(): void
    {
        if (!is_singular(Plugin::MANAGED_CPTS)) return;
        global $post;
        if (!self::can_edit($post)) return;

        $active = self::is_active($post);
        $href   = $active
            ? esc_url(remove_query_arg(self::QUERY_FLAG))
            : esc_url(add_query_arg(self::QUERY_FLAG, '1'));
        $label  = $active ? 'Exit editor' : 'Edit page';

        printf(
            '<a class="lg-fe-edit-btn%s" href="%s" data-lg-fe-edit="1">%s</a>',
            $active ? ' is-active' : '',
            $href,
            esc_html($label)
        );
    }

    /**
     * Inline a tiny CSS rule for the button. Self-contained so it survives
     * the Isolate dequeue pass — no separate file to allowlist.
     */
    public static function enqueue_button_style(): void
    {
        if (!is_singular(Plugin::MANAGED_CPTS)) return;
        global $post;
        if (!self::can_edit($post)) return;

        $css = <<<CSS
.lg-fe-edit-btn {
    position: fixed; top: 16px; right: 16px; z-index: 99999;
    padding: 8px 14px; border-radius: 6px;
    background: #111; color: #fff; font: 600 13px/1 system-ui, sans-serif;
    text-decoration: none; box-shadow: 0 2px 8px rgba(0,0,0,.25);
    transition: right 120ms ease;
}
.lg-fe-edit-btn:hover { background: #222; color: #fff; }
.lg-fe-edit-btn.is-active { background: #c00; }
/* When the lightbox is open, slide the pill left so it doesn't cover the
   close button (which lives at right:16px inside the lightbox). 76px clears
   the 44px close chip plus a 16px gap. */
body.lg-lightbox-open .lg-fe-edit-btn { right: 76px; }
CSS;
        wp_register_style('lg-fe-edit-btn', false, [], LG_LAYOUT_V2_VERSION);
        wp_enqueue_style('lg-fe-edit-btn');
        wp_add_inline_style('lg-fe-edit-btn', $css);
    }

    /**
     * Enqueue the editor JS + chrome CSS — only when editor mode is
     * actually active (predicate passes AND ?lg_edit=1). Localizes the
     * REST root, current nonce, post id, and all block manifests so the
     * JS framework can build pills + dispatch pickers without per-block
     * branching.
     */
    public static function enqueue_editor_assets(): void
    {
        if (!is_singular(Plugin::MANAGED_CPTS)) return;
        global $post;
        if (!self::is_active($post)) return;

        wp_enqueue_style(
            'lg-fe-editor',
            LG_LAYOUT_V2_URL . 'assets/lg-fe-editor.css',
            [],
            (string) (@filemtime(LG_LAYOUT_V2_DIR . 'assets/lg-fe-editor.css') ?: LG_LAYOUT_V2_VERSION)
        );

        /* wp.media for the image picker. enqueue_media wires media-views,
           media-models, jquery, etc. The picker we open is filtered to
           "uploaded_to_id = current post" so the author sees this post's
           media first, not the entire site library. */
        wp_enqueue_media(['post' => $post->ID]);

        /* Classic TinyMCE for the wysiwyg block. wp_enqueue_editor() pulls
           in the wp-tinymce bundle, quicktags, and the wp.editor JS shim.
           The actual editor instance is created on demand by the FE editor
           JS via wp.editor.initialize(). */
        wp_enqueue_editor();

        wp_enqueue_script(
            'lg-fe-editor',
            LG_LAYOUT_V2_URL . 'assets/lg-fe-editor.js',
            ['media-views'],
            (string) (@filemtime(LG_LAYOUT_V2_DIR . 'assets/lg-fe-editor.js') ?: LG_LAYOUT_V2_VERSION),
            true
        );

        wp_add_inline_script('lg-fe-editor', 'window.LG_FE_EDITOR = ' . wp_json_encode([
            'rest_root' => esc_url_raw(rest_url(EditorRest::NAMESPACE . '/')),
            'nonce'     => wp_create_nonce('wp_rest'),
            'post_id'   => $post->ID,
            'manifests' => self::manifests_for_js(),
            'icons'     => Icons::all(),
        ]) . ';', 'before');
    }

    /** Add `lg-can-edit` body class when the viewer is admin or the post's author.
     *  CSS uses this to reveal pencils + other edit-only affordances. */
    public static function body_class(array $classes): array
    {
        if (!is_singular(Plugin::MANAGED_CPTS)) return $classes;
        global $post;
        if (self::can_edit($post)) $classes[] = 'lg-can-edit';
        return $classes;
    }

    /** Enqueue the link-edit pencil assets — loaded for admins + the post's
     *  author whenever they're viewing a v2 post, NOT gated on ?lg_edit=1.
     *  Pencils let them fix a broken link without entering full editor mode. */
    public static function enqueue_link_edit_assets(): void
    {
        if (!is_singular(Plugin::MANAGED_CPTS)) return;
        global $post;
        if (!self::can_edit($post)) return;

        wp_enqueue_style(
            'lg-link-edit',
            LG_LAYOUT_V2_URL . 'assets/lg-link-edit.css',
            [],
            (string) (@filemtime(LG_LAYOUT_V2_DIR . 'assets/lg-link-edit.css') ?: LG_LAYOUT_V2_VERSION)
        );
        wp_enqueue_script(
            'lg-link-edit',
            LG_LAYOUT_V2_URL . 'assets/lg-link-edit.js',
            [],
            (string) (@filemtime(LG_LAYOUT_V2_DIR . 'assets/lg-link-edit.js') ?: LG_LAYOUT_V2_VERSION),
            true
        );
        wp_add_inline_script('lg-link-edit', 'window.LG_LINK_EDIT = ' . wp_json_encode([
            'rest_root' => esc_url_raw(rest_url(EditorRest::NAMESPACE . '/')),
            'nonce'     => wp_create_nonce('wp_rest'),
            'post_id'   => $post->ID,
        ]) . ';', 'before');
    }

    /** Strip manifest down to just what the JS framework needs (editor + schema.props). */
    private static function manifests_for_js(): array
    {
        $stored = get_option(LG_LAYOUT_V2_STYLE_OPTION, []);
        if (!is_array($stored)) $stored = [];
        $out = [];
        foreach (Manifest::list() as $name) {
            $m = Manifest::get($name);
            $variantPropKey = $m['editor']['variant_prop'] ?? null;
            $variantOptions = [];
            $variantLabels  = [];
            if ($variantPropKey && !empty($m['variants']) && is_array($m['variants'])) {
                /* If editor.variant_options is set, it filters which variant
                   keys are user-pickable (e.g. heading hides h2/h3/h4 which
                   are level-driven, not style-driven). */
                $allowed = $m['editor']['variant_options'] ?? null;
                foreach (array_keys($m['variants']) as $vname) {
                    if (is_array($allowed) && !in_array($vname, $allowed, true)) continue;
                    $variantOptions[] = $vname;
                    $label = (string) ($stored[$name]['variants'][$vname]['__label'] ?? '');
                    $variantLabels[$vname] = $label !== '' ? $label : $vname;
                }
            }
            /* Compact prop summary: name → { type, format?, items? }. The FE
               editor uses this to build modal-style editors for structural
               props (array_of_objects → repeater rows). Keep it minimal —
               full prop defs would bloat the localized payload. */
            $propsCompact = [];
            foreach (($m['schema']['props'] ?? []) as $pname => $pdef) {
                $entry = ['type' => (string) ($pdef['type'] ?? 'string')];
                if (!empty($pdef['format'])) $entry['format'] = (string) $pdef['format'];
                if (($pdef['type'] ?? '') === 'array_of_objects' && isset($pdef['items']['props'])) {
                    $itemProps = [];
                    foreach ((array) $pdef['items']['props'] as $ipName => $ipDef) {
                        $itemProps[$ipName] = ['type' => (string) ($ipDef['type'] ?? 'string')];
                        if (!empty($ipDef['description'])) $itemProps[$ipName]['description'] = (string) $ipDef['description'];
                    }
                    $entry['items'] = ['props' => $itemProps];
                }
                $propsCompact[$pname] = $entry;
            }
            $out[$name] = [
                'editor'         => $m['editor'] ?? [],
                'schema'         => ['props' => $propsCompact],
                'variants'       => $variantOptions,
                'variant_labels' => $variantLabels,
            ];
        }
        return $out;
    }
}
