<?php
/**
 * Plugin — top-level WordPress integration for lg-layout-v2.
 *
 * Registers hooks, declares managed CPTs, exposes the viewer-from-WP-context
 * builder. The actual render + CSS work is delegated to Pipeline (the same
 * code the CLI harness uses).
 *
 * Read docs/ARCHITECTURE.md before changing anything here — this class is the
 * boundary between WP's lifecycle and the engine's pure functions.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class Plugin
{
    /** CPTs whose posts can carry a v2 layout. Start small.
     *  `event` is managed for the events-page migration: only events that
     *  actually carry a `_lg_layout_v2` meta render via v2 (manages() also
     *  requires the meta), so unconverted events keep the legacy template —
     *  an incremental, per-post cutover. */
    public const MANAGED_CPTS = [
        'post-imgcap', 'post-type-videos', 'sponsor-post', 'sponsor-page', 'event',
        'loothprint', 'loothcuts', 'useful_links', 'document', 'member-benefit',
        'shorty',
    ];

    public static function boot(): void
    {
        /* Front-end content filter — priority 9 mirrors v1 so the swap is clean. */
        add_filter('the_content', [WpRenderer::class, 'filter_content'], 9);

        /* Template swap: route v2-managed posts to a lite template so Elementor
           / theme templates don't intercept and bypass the_content. Highest
           priority so we win whatever the theme registered. */
        add_filter('template_include', [self::class, 'template_include'], PHP_INT_MAX);

        /* Asset enqueue (front-end + admin preview). */
        add_action('wp_enqueue_scripts',    [WpAssets::class, 'enqueue_front']);
        add_action('admin_enqueue_scripts', [WpAssets::class, 'enqueue_admin']);

        /* Cache invalidation: any time the v2 meta or block-styles option
           changes, the rendered HTML cache + bundle.css need refresh. */
        add_action('updated_post_meta', [self::class, 'on_post_meta_changed'], 10, 4);
        add_action('added_post_meta',   [self::class, 'on_post_meta_changed'], 10, 4);
        add_action('updated_option',    [self::class, 'on_option_changed'],   10, 3);
        /* Taxonomy term changes — specifically `tier`, which drives gating —
           must also bust the post's render cache. WP fires set_object_terms
           on both create + update. */
        add_action('set_object_terms',  [self::class, 'on_object_terms_set'], 10, 6);
        /* Events render post_content + live postmeta via the default layout,
           so a re-publish (wp_update_post, no layout-meta change) must also
           bust the anon cache. Scoped to the `event` CPT. */
        add_action('save_post_event',   [self::class, 'on_event_saved'], 10, 3);

        /* Structured CPTs whose layouts are synthesized from postmeta — any
           save (ACF form, submission form, wp_update_post) must bust the cache. */
        foreach (['loothprint', 'loothcuts', 'useful_links', 'document', 'member-benefit'] as $cpt) {
            add_action("save_post_{$cpt}", [self::class, 'on_synth_cpt_saved'], 10, 3);
        }

        /* Phase 3: authoring surface. Registers its own admin menu + save route. */
        Dash::boot();

        /* Archive-poc front-page config submenu — talks to the archive-poc
           loopback webhook to write its config.json. */
        ArchivePocDash::boot();

        /* Asset isolation: dequeue/demote everything not on v2's allowlist on
           v2-managed posts. Gated by LG_LAYOUT_V2_ISOLATE. */
        Isolate::boot();

        /* Suppress third-party content rewriters that mangle our rendered
           HTML on v2-managed posts. So far: EWWW Image Optimizer's lazy
           loader replaces our <img src> with a base64 placeholder and relies
           on its lazysizes JS to swap back — but Isolate dequeues that JS,
           so the placeholder sticks and the image appears blank.
           Filter args carry the request URI; we ignore that and gate on
           Plugin::manages() against the global $post. */
        add_filter('eio_do_lazyload', [self::class, 'suppress_eio_on_v2_posts'], 10, 2);

        /* Phase 3: authoring surface on the post-edit screen. */
        MetaBox::boot();

        /* Phase 4 slice 1: front-end editor entry gate + header button. */
        FeEditor::boot();

        /* Phase 4 slice 2: REST endpoints for content/structure mutations.
           JS framework that calls them lands in slice 3. */
        EditorRest::boot();

        /* Site-wide header — replaces BuddyBoss's `buddyboss_header` action
           with our own callback so the same masthead chrome shows on every
           page (BB-themed, Elementor-themed, v2 lite). No child-theme
           override required; rollback is `wp plugin deactivate`.
           Killable per-site via wp-config:
               define('LG_LAYOUT_V2_SITE_HEADER', false);
           — useful when smoke-testing v2 posts on production before
           committing to the global header takeover. */
        if (!defined('LG_LAYOUT_V2_SITE_HEADER') || LG_LAYOUT_V2_SITE_HEADER) {
            SiteHeader::boot();
        }

        /* Site-wide footer — same takeover pattern as SiteHeader. Hooks
           buddyboss_footer, strips BB's callbacks, renders our partial.
           Killable per-site via wp-config:
               define('LG_LAYOUT_V2_SITE_FOOTER', false); */
        if (!defined('LG_LAYOUT_V2_SITE_FOOTER') || LG_LAYOUT_V2_SITE_FOOTER) {
            SiteFooter::boot();
        }

        /* Post-page sidebar — registers a single sidebar that appears
           alongside v2-managed posts on desktop and stacks below the
           article on tablet/mobile. Rendered from single-v2.php; only
           emits markup when at least one widget is active. Manage
           widgets at Appearance → Widgets. */
        add_action('widgets_init', [self::class, 'register_sidebars']);

        /* Site-wide kill: BuddyBoss Moderation injects a "Report" button and
           the per-comment kebab menu (with "Block Member" + "Report Comment")
           via comment_text + comment_reply_link filters; WP ULike injects an
           empty Like button via comment_text. We want clean comment threads
           everywhere on the site, not only on v2-managed posts. Strip those
           filters at init. Killable per-site via wp-config:
               define('LG_LAYOUT_V2_STRIP_COMMENT_CHROME', false); */
        if (!defined('LG_LAYOUT_V2_STRIP_COMMENT_CHROME') || LG_LAYOUT_V2_STRIP_COMMENT_CHROME) {
            add_action('init', [self::class, 'strip_comment_chrome'], 99);
        }

        /* Default author-archive URL → Search & Filter result page.
           Loothgroup.com uses /archive/?_post_author={user_login} as the
           canonical "all posts by X" page. Blocks call apply_filters
           ('lg_layout_v2_author_archive_url', $url, $author_id) when
           building the archive link in the byline + social row; this is
           the default. Killable via:
               define('LG_LAYOUT_V2_SF_AUTHOR_URL', false); */
        if (!defined('LG_LAYOUT_V2_SF_AUTHOR_URL') || LG_LAYOUT_V2_SF_AUTHOR_URL) {
            add_filter('lg_layout_v2_author_archive_url', [self::class, 'sf_author_url'], 10, 2);
        }

        /* WP-CLI commands: validate / import / export layouts. */
        if (defined('WP_CLI') && WP_CLI) {
            Cli::register();
        }
    }

    /**
     * Strip third-party comment-chrome filters site-wide.
     *
     * - WP ULike: `wp_ulike_put_comments` on `comment_text` priority 15.
     * - BuddyBoss Moderation: `BP_Moderation_*`-class callbacks on
     *   `comment_text` (priority 100, "Report" button) and
     *   `comment_reply_link` (priority 10, blocked-user reply gate).
     *
     * Runs once at init priority 99 — after all plugins/themes have wired
     * their hooks, before any front-end render. Doesn't restore; we don't
     * want this chrome anywhere on the site.
     */
    /**
     * Replace WP's default /author/{slug}/ URL with the Search & Filter
     * result page. S&F's "author" filter on loothgroup.com keys off the
     * `_post_author` query var with the user's user_login as value — same
     * value patreon imports use (patreon_NNNNNNN), same value WP uses for
     * regular accounts (the username slug). Returns the original URL
     * unchanged if we can't resolve the user.
     */
    public static function sf_author_url(string $url, int $author_id): string
    {
        if ($author_id <= 0) return $url;
        // Point author archive links at the archive-poc grid with the author
        // filter pre-applied. archive-poc keys off the integer WP user_id.
        return (string) home_url('/archive-poc/?author=' . $author_id);
    }

    public static function strip_comment_chrome(): void
    {
        if (function_exists('wp_ulike_put_comments')) {
            remove_filter('comment_text', 'wp_ulike_put_comments', 15);
        }

        global $wp_filter;
        foreach (['comment_text', 'comment_reply_link'] as $hook) {
            if (empty($wp_filter[$hook]->callbacks)) continue;
            foreach ($wp_filter[$hook]->callbacks as $prio => $cbs) {
                foreach ($cbs as $key => $cb) {
                    if (is_array($cb['function']) && is_object($cb['function'][0])
                        && strpos(get_class($cb['function'][0]), 'BP_Moderation') === 0) {
                        unset($wp_filter[$hook]->callbacks[$prio][$key]);
                    }
                }
            }
        }

        /* BB also prints the Report/Block-Member modal templates into the
           page body via wp_footer (bb_moderation_content_report_popup).
           That's what surfaces the "Harassment or bullying behavior" radio
           list + "Block this member" dialog on every page. Kill it. */
        remove_action('wp_footer', 'bb_moderation_content_report_popup');
    }

    /** Register the post-page sidebar so widgets can be assigned via
     *  Appearance → Widgets. The lite single-v2.php template calls
     *  dynamic_sidebar('lg-v2-post-sidebar') and only emits the wrapper
     *  when the sidebar has at least one widget — empty sidebars leave
     *  the article full-width. Sidebar ID is stable; renaming it would
     *  orphan every assigned widget. */
    public static function register_sidebars(): void
    {
        register_sidebar([
            'id'            => 'lg-v2-post-sidebar',
            'name'          => __('Post Sidebar (v2)', 'lg-layout-v2'),
            'description'   => __('Appears alongside v2-managed posts on desktop. Stacks below the article on tablet and mobile.', 'lg-layout-v2'),
            'before_widget' => '<section id="%1$s" class="lg-v2-sidebar__widget widget %2$s">',
            'after_widget'  => '</section>',
            'before_title'  => '<h2 class="lg-v2-sidebar__title">',
            'after_title'   => '</h2>',
        ]);
    }

    /** template_include filter: swap to single-v2.php when post is v2-managed. */
    public static function template_include(string $template): string
    {
        if (!is_singular(self::MANAGED_CPTS)) return $template;
        global $post;
        if (!self::manages($post)) return $template;
        $v2Template = LG_LAYOUT_V2_DIR . 'templates/single-v2.php';
        return is_file($v2Template) ? $v2Template : $template;
    }

    public static function activate(): void
    {
        /* Ensure the bundle CSS exists at activation so the first front-end
           visit doesn't 404 on the stylesheet. */
        WpAssets::regenerate_bundle();
    }

    public static function deactivate(): void
    {
        /* Intentionally no-op: leave the bundle in place. If v2 is reactivated
           later it'll regenerate on first hook anyway. */
    }

    /* ── Helpers ───────────────────────────────────────────────────── */

    /** True if this post is one v2 should render. */
    public static function manages(?\WP_Post $post): bool
    {
        if (!$post instanceof \WP_Post) return false;
        if (!in_array($post->post_type, self::MANAGED_CPTS, true)) return false;

        /* Synthesized CPTs get a default layout built from postmeta at render
           time, so any published post of these types is managed — no explicit
           `_lg_layout_v2` meta needed. An explicit meta still wins as an
           override (see load_layout). Drafts stay on the legacy template. */
        $synth = ['event', 'loothprint', 'loothcuts', 'useful_links', 'document', 'member-benefit'];
        if (in_array($post->post_type, $synth, true)) {
            if (!empty(get_post_meta($post->ID, LG_LAYOUT_V2_META_KEY, true))) return true;
            return $post->post_status === 'publish';
        }

        $raw = get_post_meta($post->ID, LG_LAYOUT_V2_META_KEY, true);
        return !empty($raw);
    }

    /** Decode the v2 meta to a layout array. Explicit `_lg_layout_v2` meta wins;
     *  for events that lack it, synthesize the default event layout. Null if
     *  neither applies (not present / malformed / non-event). */
    public static function load_layout(int $post_id): ?array
    {
        $raw = get_post_meta($post_id, LG_LAYOUT_V2_META_KEY, true);
        if (!empty($raw)) {
            $data = is_array($raw) ? $raw : json_decode((string) $raw, true);
            return is_array($data) ? $data : null;
        }
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) return null;
        switch ($post->post_type) {
            case 'event':          return self::default_event_layout($post);
            case 'loothprint':     return self::default_loothprint_layout($post);
            case 'loothcuts':      return self::default_loothcuts_layout($post);
            case 'useful_links':   return self::default_useful_links_layout($post);
            case 'document':       return self::default_document_layout($post);
            case 'member-benefit': return self::default_member_benefit_layout($post);
        }
        return null;
    }

    /**
     * Synthesize the standard event page layout from a post that has no
     * explicit `_lg_layout_v2` meta. Built fresh per render so it always
     * reflects current postmeta + content (the showrunner Sheet pipeline
     * stays the source of truth — no re-conversion needed).
     *
     * Shape: post-header → event-header → wysiwyg(body) → post-footer.
     * The body is the post_content with any link to the event's gated Zoom
     * URL stripped out, so the ONLY route to the Zoom link is the gated
     * event-header CTA — never the public body (legacy events embed it as a
     * bare `<h1>ZOOM LINK</h1>`).
     */
    private static function default_event_layout(\WP_Post $post): array
    {
        $zoom = (string) get_post_meta($post->ID, 'zoom_url_for_looth_group_virtual_event', true);
        $body = (string) $post->post_content;
        if ($zoom !== '') $body = self::strip_zoom_links($body, $zoom);
        /* v2 strips the global wpautop filter, so paragraph the body here. */
        $body = function_exists('wpautop') ? wpautop($body) : $body;

        $blocks = [
            ['type' => 'post-header',  'id' => 'evt_header', 'variant' => 'variant-1', 'show_read_time' => false],
            ['type' => 'event-header', 'id' => 'evt_when',   'variant' => 'variant-1'],
        ];
        if (trim(wp_strip_all_tags($body)) !== '') {
            $blocks[] = ['type' => 'wysiwyg', 'id' => 'evt_body', 'style' => 'plain', 'html' => $body];
        }
        $blocks[] = ['type' => 'post-footer', 'id' => 'evt_footer'];

        return [
            'schema' => 1,
            '_meta'  => ['title' => $post->post_title, 'generated' => 'default-event-layout'],
            'blocks' => $blocks,
        ];
    }

    /** Remove links to the event's gated Zoom URL from body HTML: first any
     *  heading that wraps such a link (the legacy `<h1>ZOOM LINK</h1>`), then
     *  any stray anchor to it. Content is author-controlled, not adversarial. */
    private static function strip_zoom_links(string $html, string $zoom): string
    {
        $q = preg_quote($zoom, '~');
        $html = preg_replace('~<h([1-6])\b[^>]*>.*?' . $q . '.*?</h\1>~is', '', $html) ?? $html;
        $html = preg_replace('~<a\b[^>]*href="[^"]*' . $q . '[^"]*"[^>]*>.*?</a>~is', '', $html) ?? $html;
        return $html;
    }

    /**
     * Synthesize a loothprint layout from postmeta.
     * post-header → wysiwyg(desc) → gallery → embed(video) →
     * callout:files(download) → callout:links(onshape) →
     * callout:note(license) → callout:links(bmc) → post-footer
     */
    private static function default_loothprint_layout(\WP_Post $post): array
    {
        $pid = $post->ID;

        $body = wpautop(trim((string) $post->post_content));

        $raw_images = get_post_meta($pid, 'loothprint_more_images', true);
        $image_ids  = is_array($raw_images)
            ? array_values(array_filter(array_map('intval', $raw_images)))
            : [];

        $file_id   = (int) get_post_meta($pid, 'loothprint_3d_file', true);
        $file_url  = $file_id > 0 ? (string) (wp_get_attachment_url($file_id) ?: '') : '';
        $file_name = $file_id > 0 ? (string) (get_the_title($file_id) ?: basename($file_url)) : basename($file_url);

        $cc      = trim((string) get_post_meta($pid, 'loothprint_creative_commons', true));
        $onshape = trim((string) get_post_meta($pid, 'loothprint_onshape_link', true));
        $video   = trim((string) get_post_meta($pid, 'loothprint_video_instructions', true));
        $bmc     = trim((string) get_post_meta($pid, 'loothprint_buy_me_a_coffee', true));
        $gate    = self::synth_gate_tier($pid);

        $blocks = [['type' => 'post-header', 'id' => 'lp_header']];

        if (trim(wp_strip_all_tags($body)) !== '') {
            $blocks[] = ['type' => 'wysiwyg', 'id' => 'lp_desc', 'html' => $body];
        }
        if (!empty($image_ids)) {
            $blocks[] = ['type' => 'gallery', 'id' => 'lp_gallery', 'image_ids' => $image_ids];
        }
        if ($video !== '') {
            // embed is in Renderer::AUTO_GATE_TYPES — auto-gates from post tier, no gated_tier needed
            $blocks[] = ['type' => 'embed', 'id' => 'lp_video', 'url' => $video];
        }
        if ($file_url !== '') {
            $dl = [
                'type' => 'callout', 'id' => 'lp_download', 'variant' => 'files',
                'title' => 'Download',
                'items' => [['icon' => 'file-zip', 'label' => $file_name ?: 'Download File', 'url' => $file_url]],
            ];
            if ($gate) $dl['gated_tier'] = $gate;
            $blocks[] = $dl;
        }
        if ($onshape !== '') {
            $os = [
                'type' => 'callout', 'id' => 'lp_onshape', 'variant' => 'links',
                'title' => 'CAD',
                'items' => [['icon' => 'globe', 'label' => 'Open in OnShape', 'url' => $onshape]],
            ];
            if ($gate) $os['gated_tier'] = $gate;
            $blocks[] = $os;
        }
        if ($cc !== '') {
            $blocks[] = [
                'type' => 'callout', 'id' => 'lp_license', 'variant' => 'note',
                'title' => 'License', 'body' => '<p>' . esc_html($cc) . '</p>',
            ];
        }
        if ($bmc !== '') {
            $blocks[] = [
                'type' => 'callout', 'id' => 'lp_bmc', 'variant' => 'links',
                'items' => [['icon' => 'link', 'label' => 'Support the creator on Buy Me a Coffee', 'url' => $bmc]],
            ];
        }
        $blocks[] = ['type' => 'post-footer', 'id' => 'lp_footer'];

        return ['schema' => 1, '_meta' => ['title' => $post->post_title, 'generated' => 'default-loothprint-layout'], 'blocks' => $blocks];
    }

    /**
     * Synthesize a loothcuts layout. Mirrors loothprint but description lives in
     * loothcut_about_your_loothcut (post_content is empty on these posts).
     */
    private static function default_loothcuts_layout(\WP_Post $post): array
    {
        $pid = $post->ID;

        $about = str_replace('&nbsp;', ' ', trim((string) get_post_meta($pid, 'loothcut_about_your_loothcut', true)));
        $body  = wpautop($about);

        $raw_images = get_post_meta($pid, 'loothcut_more_images', true);
        $image_ids  = is_array($raw_images)
            ? array_values(array_filter(array_map('intval', $raw_images)))
            : [];

        $file_id   = (int) get_post_meta($pid, 'loothcut_cnc_file', true);
        $file_url  = $file_id > 0 ? (string) (wp_get_attachment_url($file_id) ?: '') : '';
        $file_name = $file_id > 0 ? (string) (get_the_title($file_id) ?: basename($file_url)) : basename($file_url);

        $cc      = trim((string) get_post_meta($pid, 'loothcut_creative_commons', true));
        $onshape = trim((string) get_post_meta($pid, 'loothcut_onshape_link', true));
        $video   = trim((string) get_post_meta($pid, 'loothcut_video_instructions', true));
        $gate    = self::synth_gate_tier($pid);

        $blocks = [['type' => 'post-header', 'id' => 'lc_header']];

        if (trim(wp_strip_all_tags($body)) !== '') {
            $blocks[] = ['type' => 'wysiwyg', 'id' => 'lc_desc', 'html' => $body];
        }
        if (!empty($image_ids)) {
            $blocks[] = ['type' => 'gallery', 'id' => 'lc_gallery', 'image_ids' => $image_ids];
        }
        if ($video !== '') {
            $blocks[] = ['type' => 'embed', 'id' => 'lc_video', 'url' => $video];
        }
        if ($file_url !== '') {
            $dl = [
                'type' => 'callout', 'id' => 'lc_download', 'variant' => 'files',
                'title' => 'Download',
                'items' => [['icon' => 'file-zip', 'label' => $file_name ?: 'Download CNC File', 'url' => $file_url]],
            ];
            if ($gate) $dl['gated_tier'] = $gate;
            $blocks[] = $dl;
        }
        if ($onshape !== '') {
            $os = [
                'type' => 'callout', 'id' => 'lc_onshape', 'variant' => 'links',
                'title' => 'CAD',
                'items' => [['icon' => 'globe', 'label' => 'Open in OnShape', 'url' => $onshape]],
            ];
            if ($gate) $os['gated_tier'] = $gate;
            $blocks[] = $os;
        }
        if ($cc !== '') {
            $blocks[] = [
                'type' => 'callout', 'id' => 'lc_license', 'variant' => 'note',
                'title' => 'License', 'body' => '<p>' . esc_html($cc) . '</p>',
            ];
        }
        $blocks[] = ['type' => 'post-footer', 'id' => 'lc_footer'];

        return ['schema' => 1, '_meta' => ['title' => $post->post_title, 'generated' => 'default-loothcuts-layout'], 'blocks' => $blocks];
    }

    /**
     * Synthesize a useful_links layout. These are link cards — no gating (all public).
     * post-header → wysiwyg(body/excerpt) → callout:links(external URL)
     */
    private static function default_useful_links_layout(\WP_Post $post): array
    {
        $pid = $post->ID;

        $url      = trim((string) get_post_meta($pid, 'useful_url', true));
        $body_raw = trim((string) $post->post_content) ?: trim((string) $post->post_excerpt);
        $body     = $body_raw !== '' ? wpautop($body_raw) : '';

        $blocks = [['type' => 'post-header', 'id' => 'ul_header']];

        if ($body !== '' && trim(wp_strip_all_tags($body)) !== '') {
            $blocks[] = ['type' => 'wysiwyg', 'id' => 'ul_desc', 'html' => $body];
        }
        if ($url !== '') {
            $blocks[] = [
                'type' => 'callout', 'id' => 'ul_link', 'variant' => 'links',
                'items' => [['icon' => 'globe', 'label' => $post->post_title ?: $url, 'url' => $url]],
            ];
        }

        return ['schema' => 1, '_meta' => ['title' => $post->post_title, 'generated' => 'default-useful-links-layout'], 'blocks' => $blocks];
    }

    /**
     * Synthesize a document layout. post-header → callout:files(download).
     * Gated at looth-lite when the post carries a non-public tier term.
     */
    private static function default_document_layout(\WP_Post $post): array
    {
        $pid = $post->ID;

        $file_url = trim((string) get_post_meta($pid, 'pdf_url', true));
        if ($file_url === '') $file_url = trim((string) get_post_meta($pid, 'download_url', true));
        if ($file_url === '') {
            $upload_id = (int) get_post_meta($pid, 'file_upload', true);
            if ($upload_id > 0) $file_url = (string) (wp_get_attachment_url($upload_id) ?: '');
        }

        $gate = self::synth_gate_tier($pid);

        $blocks = [['type' => 'post-header', 'id' => 'doc_header']];

        if ($file_url !== '') {
            $ext  = strtolower((string) pathinfo($file_url, PATHINFO_EXTENSION));
            $icon = $ext === 'pdf' ? 'file-pdf' : ($ext === 'zip' ? 'file-zip' : 'file');
            $item = ['icon' => $icon, 'label' => $post->post_title ?: 'Download Document', 'url' => $file_url];
            if ($ext !== '') $item['ext'] = $ext;
            $dl = ['type' => 'callout', 'id' => 'doc_download', 'variant' => 'files', 'title' => 'Download', 'items' => [$item]];
            if ($gate) $dl['gated_tier'] = $gate;
            $blocks[] = $dl;
        }

        return ['schema' => 1, '_meta' => ['title' => $post->post_title, 'generated' => 'default-document-layout'], 'blocks' => $blocks];
    }

    /**
     * Synthesize a member-benefit layout.
     * post-header(hero) → wysiwyg(intro) → gallery →
     * [gated] callout:links(vendor) → [gated] callout:note(code) → [gated] wysiwyg(details)
     * Always gated at looth-lite (member-benefits are member-only by definition).
     */
    private static function default_member_benefit_layout(\WP_Post $post): array
    {
        $pid = $post->ID;

        $hero_id    = (int) get_post_meta($pid, 'member_benefits_hero_image', true);
        $intro_raw  = trim((string) get_post_meta($pid, 'member_benefits_introduction', true))
                   ?: trim((string) $post->post_excerpt);
        $details    = trim((string) get_post_meta($pid, 'member_benefits_full_details', true));
        $link       = trim((string) get_post_meta($pid, 'member_benefits_link', true));
        $link_title = trim((string) get_post_meta($pid, 'member_benefits_link_title', true));
        $code_raw   = trim((string) get_post_meta($pid, 'member_benefits_instructions_or_code', true));

        $raw_gallery = get_post_meta($pid, 'post_addon_gallery', true);
        $gallery_ids = is_array($raw_gallery)
            ? array_values(array_filter(array_map('intval', $raw_gallery)))
            : [];

        $gate = self::synth_gate_tier($pid) ?: 'looth-lite';

        $header = ['type' => 'post-header', 'id' => 'mb_header'];
        if ($hero_id > 0) $header['featured_image_id'] = $hero_id;
        $blocks = [$header];

        if ($intro_raw !== '') {
            $blocks[] = ['type' => 'wysiwyg', 'id' => 'mb_intro', 'html' => wpautop($intro_raw)];
        }
        if (!empty($gallery_ids)) {
            $blocks[] = ['type' => 'gallery', 'id' => 'mb_gallery', 'image_ids' => $gallery_ids];
        }
        if ($link !== '') {
            $blocks[] = [
                'type' => 'callout', 'id' => 'mb_link', 'variant' => 'links',
                'title' => 'Member Discount', 'gated_tier' => $gate,
                'items' => [['icon' => 'globe', 'label' => $link_title ?: $post->post_title, 'url' => $link]],
            ];
        }
        if ($code_raw !== '') {
            $blocks[] = [
                'type' => 'callout', 'id' => 'mb_code', 'variant' => 'note',
                'title' => 'How To Claim', 'gated_tier' => $gate,
                'body' => wp_kses_post($code_raw),
            ];
        }
        if ($details !== '') {
            $blocks[] = ['type' => 'wysiwyg', 'id' => 'mb_details', 'gated_tier' => $gate, 'html' => wp_kses_post($details)];
        }

        return ['schema' => 1, '_meta' => ['title' => $post->post_title, 'generated' => 'default-member-benefit-layout'], 'blocks' => $blocks];
    }

    /**
     * Return the first non-public tier taxonomy slug for a post, or null if public/unset.
     * Used by synthesized CPT layouts to decide whether to add gated_tier to download blocks.
     */
    private static function synth_gate_tier(int $post_id): ?string
    {
        $terms = wp_get_object_terms($post_id, 'tier', ['fields' => 'slugs']);
        if (is_wp_error($terms)) return null;
        foreach ((array) $terms as $slug) {
            if (is_string($slug) && $slug !== '' && $slug !== 'public') return $slug;
        }
        return null;
    }

    /** Cache invalidation — a synthesized CPT's postmeta changed (ACF save, form submit).
     *  Fires for loothprint, loothcuts, useful_links, document, member-benefit. */
    public static function on_synth_cpt_saved($post_id, $post = null, $update = null): void
    {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        self::invalidate_render_cache((int) $post_id);
    }

    /**
     * Build a viewer descriptor from WP's current-user context.
     * Used by Renderer + TierResolver. Shape documented in TierResolver.
     */
    public static function current_viewer(): array
    {
        if (!is_user_logged_in()) return TierResolver::anonymous();
        $u = wp_get_current_user();
        /* Tier comes from the user's WP role, not from a taxonomy. The
           Arbiter (lg-patreon-stripe-poller on dev, the legacy mu-plugin
           stack on live) writes one of looth1..looth4 to every paying
           user; TierResolver::tiersFromRoles maps those to the canonical
           gating slugs blocks declare. The legacy taxonomy-on-user path
           was always empty in practice — see
           docs/STRANGLER-COORDINATION.md §1. */
        $tiers = TierResolver::tiersFromRoles($u->roles ?? []);
        return [
            'is_admin'      => current_user_can('manage_options'),
            'is_delinquent' => false,  /* TODO: read from billing plugin in Phase 5 */
            'tiers'         => $tiers,
            'preview_role'  => isset($_GET['lg_preview_role']) ? sanitize_key((string) $_GET['lg_preview_role']) : null,
        ];
    }

    /** Event postmeta the renderer reads LIVE (event-header block). When the
     *  showrunner Sheet bridge writes any of these via update_field, the
     *  anon render cache must be busted — otherwise logged-out visitors keep
     *  seeing the pre-edit page (the layout meta itself never changed, so the
     *  layout-meta path below wouldn't fire). The bare ACF value keys, not
     *  the `_`-prefixed field-key references. */
    private const EVENT_RENDER_META = [
        'events_start_date_and_time_',
        'time_of_event',
        'zoom_url_for_looth_group_virtual_event',
    ];

    /** Drop the anon rendered-HTML cache for one post so the next anonymous
     *  view re-renders fresh. (Logged-in viewers always render fresh.) */
    private static function invalidate_render_cache(int $post_id): void
    {
        delete_post_meta($post_id, LG_LAYOUT_V2_RENDERED_AT_META);
    }

    /** Cache invalidation hook handler — meta changes. */
    public static function on_post_meta_changed($meta_id, $object_id, $meta_key, $_meta_value): void
    {
        if ($meta_key === LG_LAYOUT_V2_META_KEY) {
            self::invalidate_render_cache((int) $object_id);
            return;
        }
        /* An event's live-read render meta (date / time / zoom) changed —
           invalidate so Sheet-driven edits actually surface to anon viewers. */
        if (in_array($meta_key, self::EVENT_RENDER_META, true)) {
            $post = get_post((int) $object_id);
            if ($post instanceof \WP_Post && $post->post_type === 'event') {
                self::invalidate_render_cache((int) $object_id);
            }
        }
    }

    /** Cache invalidation hook handler — taxonomy term changes. The `tier`
     *  taxonomy directly affects gating (auto-gate + render-time scrub) on
     *  every managed CPT. Events additionally DISPLAY region / event-type /
     *  language in the event-header, so a change to any of those must
     *  invalidate too. Without this, anon-cached HTML keeps the OLD terms
     *  until the cache_epoch bumps for some other reason. */
    public static function on_object_terms_set($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids): void
    {
        $post = get_post((int) $object_id);
        if (!$post instanceof \WP_Post) return;
        if (!in_array($post->post_type, self::MANAGED_CPTS, true)) return;

        $relevant = $taxonomy === 'tier'
            || ($post->post_type === 'event'
                && in_array($taxonomy, ['region', 'event-type', 'language'], true));
        if (!$relevant) return;

        self::invalidate_render_cache((int) $object_id);
    }

    /** Cache invalidation — an event's title / body / status changed. The
     *  default event layout renders post_content live, so a re-publish from
     *  the Sheet (wp_update_post) must bust the anon cache even when no meta
     *  key changed. Fires only for the `event` CPT (save_post_event). */
    public static function on_event_saved($post_id, $post = null, $update = null): void
    {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        self::invalidate_render_cache((int) $post_id);
    }

    /**
     * Filter callback for EWWW Image Optimizer's eio_do_lazyload hook.
     * Returns false (skip lazy-load) when we're rendering a v2-managed
     * post. Other pages keep EWWW's behavior intact.
     */
    public static function suppress_eio_on_v2_posts($enabled, $request_uri = ''): bool
    {
        if (!is_singular(self::MANAGED_CPTS)) return (bool) $enabled;
        global $post;
        if (!self::manages($post)) return (bool) $enabled;
        return false;
    }

    /** Cache invalidation hook handler — option changes (dash saves). */
    public static function on_option_changed($option, $_old, $_new): void
    {
        if ($option !== LG_LAYOUT_V2_STYLE_OPTION && $option !== LG_LAYOUT_V2_BRAND_OPTION) return;
        WpAssets::regenerate_bundle();
        /* Anonymous-viewer HTML caches across all v2 posts are stale. We bump a
           global cache key rather than walking every post — cheap, correct. */
        update_option('lg_layout_v2_cache_epoch', time());
    }
}
