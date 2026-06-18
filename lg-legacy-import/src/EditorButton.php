<?php
/**
 * EditorButton — classic-editor metabox on legacy post-imgcap posts that
 * lets an editor preview or download the v2 layout JSON before committing.
 *
 *   Preview  → in-page <textarea> with the JSON, for eyeball + copy/paste
 *   Download → admin-post.php streams a `post-<id>.json` file attachment
 *   Apply    → writes the JSON to _lg_layout_v2 directly (one-click takeover)
 *
 * Authorization: requires `edit_post` on the post. Apply also nonce-checked.
 * Shown only on `post-imgcap` posts that don't already have _lg_layout_v2.
 */

declare(strict_types=1);

namespace LG_Legacy_Import;

final class EditorButton
{
    /** Post the metabox rendered for, captured during render so admin_footer
     *  can emit the matching <form> elements outside #post. Browsers silently
     *  drop nested <form>s and merge their inputs into the outer form, which
     *  pollutes $_POST['action'] on the main post save and causes the Update
     *  button to bounce to the Posts list. Rendering the forms in the footer
     *  keeps them outside #post entirely. */
    private static ?\WP_Post $form_owner = null;

    public static function boot(): void
    {
        add_action('add_meta_boxes', [self::class, 'register_metabox']);
        add_action('admin_post_lg_legacy_download', [self::class, 'handle_download']);
        add_action('admin_post_lg_legacy_apply',    [self::class, 'handle_apply']);
        add_action('admin_footer', [self::class, 'render_external_forms']);

        /* Diagnostic hooks — tell us which dispatch path admin-post.php
           took. If `admin_post_nopriv_*` fires, the auth cookie didn't
           validate (browser stripped cookies on a cross-origin POST, or
           a security plugin invalidated the session). If the generic
           `admin_post` fires but our specific action doesn't, something
           between dispatch and our handler is dying. */
        $traceCb = function () {
            $hook = current_filter() ?: '(no current_filter)';
            $a    = $_REQUEST['action'] ?? '(none)';
            $auth = function_exists('wp_validate_auth_cookie') ? (wp_validate_auth_cookie() ? 'y' : 'n') : '?';
            self::trace("hook=$hook action=$a uid=" . get_current_user_id() . " auth_cookie_valid=$auth");
        };
        add_action('admin_post_nopriv_lg_legacy_download', $traceCb);
        add_action('admin_post_nopriv_lg_legacy_apply',    $traceCb);
        add_action('admin_post',        $traceCb);
        add_action('admin_post_nopriv', $traceCb);

        /* Diagnostic — write to a known file path independent of WP_DEBUG_LOG
           so we can tell whether the request is even reaching our handler.
           File lives next to the plugin so it's easy to find/delete later. */
        add_action('admin_init', function () {
            $a = $_REQUEST['action'] ?? '';
            if (str_starts_with($a, 'lg_legacy_')) {
                self::trace("admin_init reached action=$a post=" . ($_REQUEST['post'] ?? '?') . " uid=" . get_current_user_id());
            }
        });
        /* Once-per-load breadcrumb so we know the plugin is even loading
           on admin-post.php requests. Without this we can't tell file-not-
           writable from handler-never-runs. */
        if (is_admin() && !empty($_SERVER['REQUEST_URI']) && str_contains((string) $_SERVER['REQUEST_URI'], 'admin-post.php')) {
            self::trace('plugin booted on admin-post.php uri=' . $_SERVER['REQUEST_URI']);
        }
    }

    /** Diagnostic log writer that bypasses WP_DEBUG_LOG routing. Writes to
     *  the plugin directory; create/append. Failures are silently ignored
     *  (the trace is a debugging aid, not a feature). */
    private static function trace(string $msg): void
    {
        $path = LG_LEGACY_IMPORT_DIR . 'lg-legacy-trace.log';
        @file_put_contents($path, '[' . gmdate('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND | LOCK_EX);
    }

    public static function register_metabox(): void
    {
        global $post;
        if (!$post) return;
        if (!in_array($post->post_type, Extractor::supported_post_types(), true)) return;
        /* Hide on posts already managed by v2 — there's nothing to convert. */
        if (get_post_meta($post->ID, '_lg_layout_v2', true)) return;

        add_meta_box(
            'lg-legacy-export',
            'LG Legacy → v2 Conversion',
            [self::class, 'render_metabox'],
            $post->post_type,
            'side',
            'high'
        );
    }

    public static function render_metabox(\WP_Post $post): void
    {
        $admin_post = esc_url(admin_url('admin-post.php'));
        $nonce      = wp_create_nonce('lg_legacy_' . $post->ID);

        /* Visible diagnostic — print what WP thinks the admin URL is here
           so we can compare it against the URL the editor was loaded from.
           If they don't match (cross-host, http vs https, www vs apex),
           form submits go to the wrong place and bounce to Posts list. */
        $current_url = (is_ssl() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '?') . ($_SERVER['REQUEST_URI'] ?? '?');
        $home_opt    = get_option('home');
        $site_opt    = get_option('siteurl');

        /* Build the JSON once for the preview textarea. Mapper failures
           surface as the error text inside the textarea so the editor sees
           what broke rather than a silent empty box. */
        try {
            $ext    = Extractor::extract((int) $post->ID);
            $layout = Mapper::to_layout($ext);
            $json   = wp_json_encode($layout, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $blocks = count($layout['blocks']);
            $err    = '';
        } catch (\Throwable $e) {
            $json   = '';
            $blocks = 0;
            $err    = $e->getMessage();
        }
        ?>
        <p style="font-size: 12px; color: #555; margin: 4px 0 10px;">
            <?php if ($err): ?>
                <strong style="color: #c33;">Error:</strong> <?= esc_html($err) ?>
            <?php else: ?>
                Walks this post's ACF repeater + body content and generates a v2 layout. <strong><?= (int) $blocks ?> blocks</strong> will be produced.
            <?php endif; ?>
        </p>

        <p style="font-size: 10px; color: #999; margin: 0 0 6px;">
            lg-legacy-import v<?= esc_html(defined('LG_LEGACY_IMPORT_VERSION') ? LG_LEGACY_IMPORT_VERSION : '?') ?>
        </p>

        <details style="font-size: 11px; color: #777; margin: 4px 0 10px; padding: 6px; background: #f8f8f8; border: 1px solid #eee; border-radius: 3px;">
            <summary style="cursor: pointer;">Diagnostic URLs</summary>
            <div style="margin-top: 6px; font-family: ui-monospace, monospace; word-break: break-all;">
                <div><strong>form posts to:</strong> <?= esc_html($admin_post) ?></div>
                <div><strong>page loaded from:</strong> <?= esc_html($current_url) ?></div>
                <div><strong>WP home option:</strong> <?= esc_html($home_opt) ?></div>
                <div><strong>WP siteurl option:</strong> <?= esc_html($site_opt) ?></div>
            </div>
            <p style="margin-top: 6px; color: #888;">
                If "form posts to" doesn't match the host of "page loaded from," submits cross-origin and the session cookies don't follow — that's the bounce-to-Posts symptom.
            </p>
        </details>

        <?php /* Buttons here are plain type="button" — no forms, no name="action"
                 inputs that would pollute the outer #post form. The real <form>
                 elements are rendered in admin_footer, outside #post, and
                 submitted by the JS handlers below. */ ?>
        <button type="button" class="button button-primary" id="lg-legacy-download-btn" <?= $err ? 'disabled' : '' ?>>
            Download JSON
        </button>

        <button type="button" class="button" id="lg-legacy-toggle-preview" style="margin-left: 6px;">
            Preview ▾
        </button>

        <button type="button" class="button" id="lg-legacy-apply-btn" style="margin-left: 6px;" <?= $err ? 'disabled' : '' ?>>
            Apply →
        </button>
        <?php self::$form_owner = $post; ?>

        <textarea id="lg-legacy-preview" readonly style="width:100%; height: 240px; margin-top: 12px; font: 11px/1.4 ui-monospace, Menlo, monospace; display: none;"><?= esc_textarea($json) ?></textarea>

        <script>
            (function () {
                var btn = document.getElementById('lg-legacy-toggle-preview');
                var ta  = document.getElementById('lg-legacy-preview');
                if (btn && ta) {
                    btn.addEventListener('click', function () {
                        var open = ta.style.display !== 'none';
                        ta.style.display = open ? 'none' : 'block';
                        btn.textContent  = open ? 'Preview ▾' : 'Hide preview ▴';
                        if (!open) ta.focus();
                    });
                }
                /* External forms are emitted later by admin_footer, so we
                   resolve them inside the click handler — by then the DOM
                   is fully parsed. Looking them up here at script-run time
                   would return null and silently skip wiring. */
                var dl = document.getElementById('lg-legacy-download-btn');
                if (dl) {
                    dl.addEventListener('click', function () {
                        var f = document.getElementById('lg-legacy-form-download');
                        if (f) f.submit();
                    });
                }
                var ap = document.getElementById('lg-legacy-apply-btn');
                if (ap) {
                    ap.addEventListener('click', function () {
                        if (!confirm('Apply v2 layout to this post? The post will start rendering via lg-layout-v2. Reversible by deleting _lg_layout_v2 postmeta.')) return;
                        var f = document.getElementById('lg-legacy-form-apply');
                        if (f) f.submit();
                    });
                }
            })();
        </script>
        <?php
    }

    /** Emit the two real <form> elements outside #post so the browser can't
     *  fold their inputs into the main post form. Only renders when the
     *  metabox actually rendered for a post on this page (admin_footer
     *  fires on every admin page; we gate on $form_owner). */
    public static function render_external_forms(): void
    {
        $post = self::$form_owner;
        if (!$post) return;

        $admin_post = esc_url(admin_url('admin-post.php'));
        $nonce      = wp_create_nonce('lg_legacy_' . $post->ID);
        $post_id    = (int) $post->ID;
        ?>
        <form id="lg-legacy-form-download" method="post" action="<?= $admin_post ?>" style="display:none;">
            <input type="hidden" name="action" value="lg_legacy_download" />
            <input type="hidden" name="post"   value="<?= $post_id ?>" />
            <input type="hidden" name="_lg_nonce" value="<?= esc_attr($nonce) ?>" />
        </form>
        <form id="lg-legacy-form-apply" method="post" action="<?= $admin_post ?>" style="display:none;">
            <input type="hidden" name="action" value="lg_legacy_apply" />
            <input type="hidden" name="post"   value="<?= $post_id ?>" />
            <input type="hidden" name="_lg_nonce" value="<?= esc_attr($nonce) ?>" />
        </form>
        <?php
    }

    /** Stream the layout JSON as a file download. */
    public static function handle_download(): void
    {
        self::trace('[lg-legacy] handle_download ENTER post=' . ($_POST['post'] ?? '?') . ' uid=' . get_current_user_id());

        $post_id = (int) ($_POST['post'] ?? 0);
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            self::trace('[lg-legacy] handle_download REJECT cap-check post_id=' . $post_id);
            wp_die('not allowed');
        }
        if (!wp_verify_nonce((string) ($_POST['_lg_nonce'] ?? ''), 'lg_legacy_' . $post_id)) {
            self::trace('[lg-legacy] handle_download REJECT bad-nonce post_id=' . $post_id);
            wp_die('bad nonce');
        }

        try {
            $ext    = Extractor::extract($post_id);
            $layout = Mapper::to_layout($ext);
        } catch (\Throwable $e) {
            wp_die('conversion failed: ' . esc_html($e->getMessage()));
        }

        $json = wp_json_encode($layout, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="post-' . $post_id . '.json"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    /** Apply the layout to _lg_layout_v2 and render a success page with a
     *  link back. No redirect — wp_safe_redirect's fallback behavior on
     *  some sites (when home_url/site_url disagree on host/scheme, or when
     *  a third-party plugin filters the redirect chain) bounces the editor
     *  to /wp-admin/edit.php (the Posts list) on submit, which reads as
     *  "the form silently failed." Avoiding the redirect entirely makes
     *  the success/failure state explicit on every host. */
    public static function handle_apply(): void
    {
        self::trace('[lg-legacy] handle_apply ENTER post=' . ($_POST['post'] ?? '?') . ' uid=' . get_current_user_id());

        $post_id = (int) ($_POST['post'] ?? 0);
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            self::trace('[lg-legacy] handle_apply REJECT cap-check post_id=' . $post_id . ' uid=' . get_current_user_id());
            wp_die('not allowed');
        }
        if (!wp_verify_nonce((string) ($_POST['_lg_nonce'] ?? ''), 'lg_legacy_' . $post_id)) {
            self::trace('[lg-legacy] handle_apply REJECT bad-nonce post_id=' . $post_id);
            wp_die('bad nonce');
        }

        try {
            $ext    = Extractor::extract($post_id);
            $layout = Mapper::to_layout($ext);
        } catch (\Throwable $e) {
            self::trace('[lg-legacy] handle_apply EXCEPTION ' . $e->getMessage());
            wp_die('conversion failed: ' . esc_html($e->getMessage()));
        }

        update_post_meta($post_id, '_lg_layout_v2', wp_slash(wp_json_encode($layout)));
        update_option('lg_layout_v2_cache_epoch', time());

        self::trace('[lg-legacy] handle_apply OK post=' . $post_id . ' blocks=' . count($layout['blocks']));
        self::render_success_page($post_id, count($layout['blocks']));
        exit;
    }

    /** Tiny standalone success page. Self-contained HTML so it doesn't
     *  depend on any wp-admin template loading that might be off on live. */
    private static function render_success_page(int $post_id, int $blocks): void
    {
        $edit_url = admin_url('post.php?post=' . $post_id . '&action=edit');
        $view_url = get_permalink($post_id) ?: home_url('/?p=' . $post_id);
        $title    = esc_html(get_the_title($post_id) ?: ('post ' . $post_id));

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!doctype html>
        <html><head><meta charset="utf-8"><title>v2 layout applied</title>
        <style>
            body { font: 14px/1.5 -apple-system, system-ui, sans-serif; max-width: 640px; margin: 80px auto; color: #1a1d1a; }
            .card { padding: 24px 28px; border: 1px solid #d4e0b8; border-radius: 8px; background: #fbfbf8; }
            h1 { margin: 0 0 12px; font-size: 22px; }
            p { margin: 8px 0; }
            a { color: #2d5016; text-decoration: underline; }
            .btn { display: inline-block; padding: 8px 14px; background: #1a1d1a; color: #fff; border-radius: 6px; text-decoration: none; margin-right: 8px; }
            .btn--ghost { background: transparent; color: #1a1d1a; border: 1px solid #ccc; }
        </style></head><body>
        <div class="card">
            <h1>✓ v2 layout applied</h1>
            <p><strong><?= $title ?></strong> — <?= (int) $blocks ?> blocks written to <code>_lg_layout_v2</code>.</p>
            <p>The post now renders via lg-layout-v2. Open it in a new tab to eyeball, or jump back to the editor.</p>
            <p style="margin-top: 20px;">
                <a class="btn" href="<?= esc_url($view_url) ?>" target="_blank" rel="noopener">View post →</a>
                <a class="btn btn--ghost" href="<?= esc_url($edit_url) ?>">Back to editor</a>
            </p>
            <p style="margin-top: 24px; font-size: 12px; color: #666;">
                To revert: <code>wp post meta delete <?= (int) $post_id ?> _lg_layout_v2</code>
                (the original ACF data is untouched).
            </p>
        </div>
        </body></html>
        <?php
    }
}
