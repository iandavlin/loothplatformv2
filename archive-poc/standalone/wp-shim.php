<?php
/**
 * archive-poc/standalone/wp-shim.php
 *
 * The materialize-on-save BOUNDARY, expressed as code.
 *
 * The lg-layout-v2 render engine is ~95% portable (CssBuilder, TierResolver,
 * Renderer dispatch = zero WP calls). The ONLY WP coupling left in the render
 * path is article *identity*: post-header / post-footer / GateCta read the
 * title / author / date / terms / avatar / featured-image with ~18 WP
 * functions (see design-layout-standalone.md §3).
 *
 * This shim defines exactly those functions, each backed by a flat PostContext
 * array at $GLOBALS['LG_PC']. The unmodified engine + its unmodified block
 * render.php files then run with NO WordPress boot — they call the same
 * function names, but those names now resolve to a plain-array read instead of
 * a live DB query.
 *
 * ⇒ The shape this shim reads IS the contract the production save-hook
 *   materializer must produce. Whatever keys lg_pc()/lg_standalone_media_resolver
 *   touch below, the materializer must bake into the blob at save time. See
 *   RENDER-STANDALONE-POC.md "PostContext contract".
 *
 * Defining ~20 pure functions is NOT a WordPress boot: no wp-load, no plugins,
 * no DB, no hooks. It's the read-side mirror, same idea as archive-poc's own
 * SQLite-backed page renders.
 *
 * NOTE (copy/extract discipline, bootstrap §): this file lives in the
 * standalone lane and is never loaded inside WordPress. The live plugin keeps
 * using the real WP functions; nothing here shadows them (every def is
 * function_exists-guarded, and WP is never in scope here).
 */

declare(strict_types=1);

/* The hand-materialized PostContext for the current render. The harness sets
   $GLOBALS['LG_PC'] before invoking the engine. */
function lg_pc(): array { return $GLOBALS['LG_PC'] ?? []; }

/* Author sub-array helper. */
function lg_pc_author(): array { return lg_pc()['author'] ?? []; }

/* Media resolver injected into the render $ctx (replaces WpMedia::resolve).
   Same return shape: { id, url, alt, mime, sizes }. Backed by the blob's
   `media` map — the materializer pre-resolves every attachment the layout
   references at save time. */
function lg_standalone_media_resolver(int $id): array {
    $m = lg_pc()['media'][(string) $id] ?? lg_pc()['media'][$id] ?? null;
    if (!is_array($m)) return ['id' => $id, 'url' => '', 'alt' => '', 'mime' => '', 'sizes' => [],
                               'title' => '', 'filename' => '', 'filesize_human' => ''];
    return [
        'id'             => $id,
        'url'            => (string) ($m['url'] ?? ''),
        'alt'            => (string) ($m['alt'] ?? ''),
        'mime'           => (string) ($m['mime'] ?? ''),
        'sizes'          => is_array($m['sizes'] ?? null) ? $m['sizes'] : [],
        // Download-block fields - pre-baked in the media map by the materializer.
        'title'          => (string) ($m['title'] ?? ''),
        'filename'       => (string) ($m['filename'] ?? ''),
        'filesize_human' => (string) ($m['filesize_human'] ?? ''),
    ];
}

/* Build the lightweight term objects the blocks expect (->name ->slug ->link). */
function lg_pc_terms(string $tax) {
    $raw = lg_pc()['terms'][$tax] ?? null;
    if (!is_array($raw) || $raw === []) return false;     // WP returns false when none
    $out = [];
    foreach ($raw as $t) {
        if (!is_array($t)) continue;
        $o = new \stdClass();
        $o->name    = (string) ($t['name'] ?? '');
        $o->slug    = (string) ($t['slug'] ?? '');
        $o->term_id = (int)    ($t['term_id'] ?? 0);
        $o->link    = (string) ($t['link'] ?? '#');
        $out[] = $o;
    }
    return $out ?: false;
}

/* ── Constants the engine probes via defined() ───────────────────────── */
if (!defined('LG_LAYOUT_V2_META_KEY')) define('LG_LAYOUT_V2_META_KEY', '_lg_layout_v2');
if (!defined('HOUR_IN_SECONDS'))       define('HOUR_IN_SECONDS', 3600);

/* ── Filters / options ───────────────────────────────────────────────── */
if (!function_exists('apply_filters')) {
    // Passthrough: standalone has no plugin filter chain. Returns the value
    // unchanged (the engine only uses filters as optional override seams).
    function apply_filters($tag, $value = null) { return $value; }
}
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return lg_pc()['options'][$name] ?? $default;
    }
}

/* ── Post identity ───────────────────────────────────────────────────── */
if (!function_exists('get_the_title')) {
    function get_the_title($post = 0): string { return (string) (lg_pc()['title'] ?? ''); }
}
if (!function_exists('get_permalink')) {
    function get_permalink($post = 0): string { return (string) (lg_pc()['permalink'] ?? ''); }
}
if (!function_exists('get_the_date')) {
    function get_the_date($format = '', $post = null): string { return (string) (lg_pc()['date'] ?? ''); }
}
if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = ''): string {
        return $show === 'name' ? (string) (lg_pc()['bloginfo_name'] ?? '') : '';
    }
}
if (!function_exists('get_post_field')) {
    function get_post_field($field, $post = null, $context = 'display') {
        if ($field === 'post_author') return (int) (lg_pc_author()['id'] ?? 0);
        return '';
    }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false) {
        if ($key === LG_LAYOUT_V2_META_KEY || $key === '_lg_layout_v2') {
            // Read-time word count reads the layout back from "meta".
            return lg_pc()['layout'] ?? [];
        }
        if ($key === '_wp_attachment_image_alt') {
            $m = lg_pc()['media'][(string) $post_id] ?? lg_pc()['media'][$post_id] ?? [];
            return (string) ($m['alt'] ?? '');
        }
        return $single ? '' : [];
    }
}

/* ── Featured image ──────────────────────────────────────────────────── */
if (!function_exists('get_post_thumbnail_id')) {
    function get_post_thumbnail_id($post = null): int { return (int) (lg_pc()['featured_image']['id'] ?? 0); }
}
if (!function_exists('get_the_post_thumbnail_url')) {
    function get_the_post_thumbnail_url($post = null, $size = 'post-thumbnail') {
        $url = (string) (lg_pc()['featured_image']['url'] ?? '');
        return $url !== '' ? $url : false;
    }
}
if (!function_exists('wp_get_attachment_image_url')) {
    function wp_get_attachment_image_url($attachment_id, $size = 'thumbnail', $icon = false) {
        $m = lg_pc()['media'][(string) $attachment_id] ?? lg_pc()['media'][$attachment_id] ?? null;
        $url = is_array($m) ? (string) ($m['url'] ?? '') : '';
        return $url !== '' ? $url : false;
    }
}

/* ── Author ──────────────────────────────────────────────────────────── */
if (!function_exists('get_userdata')) {
    function get_userdata($user_id) {
        $a = lg_pc_author();
        if ((int) ($a['id'] ?? 0) !== (int) $user_id) return false;
        $o = new \stdClass();
        $o->ID           = (int) ($a['id'] ?? 0);
        $o->display_name = (string) ($a['display_name'] ?? '');
        return $o;
    }
}
if (!function_exists('get_the_author_meta')) {
    function get_the_author_meta($field = '', $user_id = false) {
        $a = lg_pc_author();
        if ($field === 'display_name') return (string) ($a['display_name'] ?? '');
        return (string) ($a['meta'][$field] ?? '');
    }
}
if (!function_exists('get_user_meta')) {
    function get_user_meta($user_id, $key = '', $single = false) {
        $v = (string) (lg_pc_author()['meta'][$key] ?? '');
        return $single ? $v : ($v === '' ? [] : [$v]);
    }
}
if (!function_exists('get_avatar_url')) {
    function get_avatar_url($id_or_email, $args = []) { return (string) (lg_pc_author()['avatar_url'] ?? ''); }
}
if (!function_exists('get_author_posts_url')) {
    function get_author_posts_url($author_id, $author_nicename = '') { return (string) (lg_pc_author()['archive_url'] ?? ''); }
}

/* ── Taxonomies ──────────────────────────────────────────────────────── */
if (!function_exists('get_the_terms')) {
    function get_the_terms($post, $taxonomy) { return lg_pc_terms((string) $taxonomy); }
}
if (!function_exists('get_term_link')) {
    function get_term_link($term, $taxonomy = '') {
        return is_object($term) ? (string) ($term->link ?? '#') : '#';
    }
}

/* ── Sponsor brand kit (ACF) — served from materialized post_context.sponsor ──
 * The post-header "sponsor" variant calls get_field('brand_*', 'user_<id>'); the
 * materializer pre-resolved that kit (incl. the logo as {url:…}) into LG_PC.sponsor,
 * so this returns the baked value by field name and ignores the user-id selector. */
if (!function_exists('get_field')) {
    function get_field($selector, $id = false, $format_value = true) {
        $sp = $GLOBALS['LG_PC']['sponsor'] ?? null;
        return (is_array($sp) && array_key_exists((string) $selector, $sp)) ? $sp[(string) $selector] : null;
    }
}

/* ── Viewer auth (comments gate only; harness sets the flag per render) ── */
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool { return !empty($GLOBALS['LG_VIEWER_AUTH']); }
}

/* ── Text helpers WP exposes that a couple of blocks lean on ──────────── */
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text, $remove_breaks = false): string {
        $text = strip_tags((string) $text);
        return trim($remove_breaks ? preg_replace('/[\r\n\t ]+/', ' ', $text) : $text);
    }
}

/*
 * Deliberately NOT defined (so function_exists() is false and the engine takes
 * its no-WP branch):
 *   bp_core_get_user_domain  → BP member-profile link slot is skipped
 *   wp_oembed_get / wp_remote_get / get_transient → YouTube/Instagram render
 *                              via pure-regex facades; generic oEmbed is the
 *                              only path that would need these (not in this PoC)
 *   comments_template / WP_Query / get_post_type → post-footer gates these
 *                              behind show_comments / show_related, both false
 *                              in the PoC blob (see RENDER-STANDALONE-POC.md
 *                              §"What the materializer still owns").
 */

// WP_Query stub — standalone render has no related-posts DB; return empty.
if (!class_exists("WP_Query")) {
    class WP_Query {
        public array $posts = [];
        public function __construct(array $args = []) { $this->posts = []; }
    }
}

// get_post_type() — returns the CPT slug baked into post_context.
if (!function_exists('get_post_type')) {
    function get_post_type($post = null): string { return (string) (lg_pc()['post_type'] ?? 'post-imgcap'); }
}

/* ── Comments: standalone has NO live WP comment system ──────────────────
 * post-footer/render.php runs add_filter()+comments_template() for logged-in
 * viewers to render WP's live comments. That path is WP-coupled and cannot run
 * standalone (it fataled the whole authed render: add_filter undefined). Comments
 * are a future materialized snapshot; until then these no-ops make the comments
 * <section> render empty instead of crashing. (apply_filters already stubbed above.) */
if (!function_exists('add_filter'))        { function add_filter($tag = '', $cb = null, $prio = 10, $args = 1) { return true; } }
if (!function_exists('remove_filter'))     { function remove_filter($tag = '', $cb = null, $prio = 10) { return true; } }
if (!function_exists('comments_template')) { function comments_template($file = '', $separate = false) { /* no live comments standalone */ } }
