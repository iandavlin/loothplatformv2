<?php
/**
 * blocks/post-header/render.php
 *
 * Hero header for an article. Composes the post's featured image, title,
 * byline (author + publication + date + read time), author links (from
 * user meta), and a tags strip below the photo.
 *
 * Most of the data is pulled dynamically from WP at render time:
 *   - title       → get_the_title($post_id)
 *   - author      → user data of the post author
 *   - image       → image_id prop, falls back to post thumbnail
 *   - tags        → categories + tier taxonomy terms
 *   - read time   → word count of the layout's wysiwyg blocks ÷ 220 wpm
 *
 * Author links are pulled from user meta. Slot config is filterable so the
 * site can swap to its actual ACF field keys / icon set / link titles via
 * `lg_layout_v2_post_header_author_links`.
 *
 * @var array $args  Parsed + validated props
 * @var array $ctx   Render context — includes post_id when in WP
 */

use LG\LayoutV2\Renderer;

$post_id = (int) ($ctx['post_id'] ?? 0);
$variant = is_string($args['variant'] ?? null) ? strtolower((string) $args['variant']) : 'variant-1';
if (!in_array($variant, ['variant-1', 'variant-2', 'variant-3', 'video', 'sponsor'], true)) $variant = 'variant-1';

$image_id = (int) ($args['image_id'] ?? 0);
$tagline  = trim((string) ($args['tagline'] ?? ''));
$show_read_time = !empty($args['show_read_time']);

/* ── CPT type badge ─────────────────────────────────────────────────
   A small "kind" chip in the eyebrow (Loothprint / Article / Video / …)
   for every managed CPT, so a post reads the same on its own page as it
   does on a Hub card. Labels mirror the Hub vocabulary (the $kind_label
   map in bb-mirror/web/forums/_feed.php); CPTs not in that map fall back
   to a title-cased slug. get_post_type() resolves on both paths — WP
   natively, and the standalone renderer injects post_type into the
   post_context it hands the shim. */
$post_type  = $post_id > 0 && function_exists('get_post_type') ? (string) get_post_type($post_id) : '';
$type_label = [
    'post-imgcap'      => 'Article',
    'post-type-videos' => 'Video',
    'sponsor-post'     => 'Sponsor',
    'loothprint'       => 'Loothprint',
    'loothcuts'        => 'Loothcut',
    'document'         => 'Document',
    'useful_links'     => 'Link',
    'member-benefit'   => 'Benefit',
    'shorty'           => 'Short',
    'event'            => 'Event',
][$post_type] ?? ($post_type !== '' ? ucwords(str_replace(['-', '_'], ' ', $post_type)) : '');

/* ── Hero image: explicit image_id, else featured image, else nothing. ── */
$photo_url = '';
$photo_alt = '';
if ($image_id > 0) {
    $media = ($ctx['media_resolver'] ?? null);
    if (is_callable($media)) {
        $m = $media($image_id);
        $photo_url = (string) ($m['url'] ?? '');
        $photo_alt = (string) ($m['alt'] ?? '');
    }
} elseif ($post_id > 0 && function_exists('get_the_post_thumbnail_url')) {
    $photo_url = (string) (get_the_post_thumbnail_url($post_id, 'full') ?: '');
    $thumb_id  = (int) (get_post_thumbnail_id($post_id) ?: 0);
    if ($thumb_id) $photo_alt = (string) (get_post_meta($thumb_id, '_wp_attachment_image_alt', true) ?: '');
}

/* ── Title + author ────────────────────────────────────────────────── */
$title = $post_id > 0 && function_exists('get_the_title') ? (string) get_the_title($post_id) : '';
$author_id   = $post_id > 0 ? (int) get_post_field('post_author', $post_id) : 0;
$author      = $author_id ? get_userdata($author_id) : null;
$author_name = $author ? (string) $author->display_name : '';
/* Custom author photo (ACF `author_image` attachment) → gravatar fallback. */
$avatar_url = '';
if ($author_id) {
    $custom_avatar_id = (int) (get_user_meta($author_id, 'author_image', true) ?: 0);
    if ($custom_avatar_id > 0 && function_exists('wp_get_attachment_image_url')) {
        $avatar_url = (string) (wp_get_attachment_image_url($custom_avatar_id, 'thumbnail') ?: '');
    }
    if ($avatar_url === '' && function_exists('get_avatar_url')) {
        $avatar_url = (string) (get_avatar_url($author_id, ['size' => 96]) ?: '');
    }
}

/* Publication: site name by default. ACF `brand_website` belongs to the
   author, not the article — leave the publication line as the site brand. */
$pub_name = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '';

/* ── Date + read time ─────────────────────────────────────────────── */
$date_str = $post_id > 0 && function_exists('get_the_date') ? (string) get_the_date('', $post_id) : '';
$read_min = 0;
if ($show_read_time && $post_id > 0 && function_exists('get_post_meta')) {
    $layout = get_post_meta($post_id, defined('LG_LAYOUT_V2_META_KEY') ? LG_LAYOUT_V2_META_KEY : '_lg_layout_v2', true);
    $data   = is_array($layout) ? $layout : json_decode((string) $layout, true);
    $words  = 0;
    if (is_array($data['blocks'] ?? null)) {
        $walk = function (array $blocks) use (&$walk, &$words): void {
            foreach ($blocks as $b) {
                if (!is_array($b)) continue;
                $type = $b['type'] ?? '';
                if ($type === 'wysiwyg' && isset($b['html'])) {
                    $words += str_word_count(wp_strip_all_tags((string) $b['html']));
                } elseif ($type === 'heading' || $type === 'section-heading') {
                    $words += str_word_count((string) ($b['text'] ?? ''));
                } elseif ($type === 'image' && isset($b['description'])) {
                    $words += str_word_count(wp_strip_all_tags((string) $b['description']));
                }
                if (is_array($b['columns'] ?? null)) {
                    foreach ($b['columns'] as $col) {
                        if (is_array($col['blocks'] ?? null)) $walk($col['blocks']);
                    }
                }
            }
        };
        $walk($data['blocks']);
    }
    $read_min = max(1, (int) round($words / 220));
}

/* Taxonomies — two surfaces:
   1. `tier` is surfaced PROMINENTLY in the byline area (single badge,
      colored) so subscribers can see at a glance what tier this post
      requires. Defaults to "Public" when nothing is set.
   2. Everything else (shared_category, article-type, post_tag, series)
      goes in the bottom tags strip where many tags can wrap freely.
   Filterable so site-level config can swap taxonomies without editing
   this file. */
$tier_terms = [];
$tags = [];
if ($post_id > 0 && function_exists('get_the_terms')) {
    $tier_raw = get_the_terms($post_id, 'tier');
    if (is_array($tier_raw)) {
        foreach ($tier_raw as $t) {
            if (!is_object($t)) continue;
            $tier_terms[] = ['name' => (string) $t->name, 'slug' => (string) $t->slug, 'url' => (string) (get_term_link($t) ?: '#')];
        }
    }

    $bottom_taxes = function_exists('apply_filters')
        ? (array) apply_filters('lg_layout_v2_post_header_bottom_taxes', ['shared_category', 'article-type', 'post_tag', 'series'])
        : ['shared_category', 'article-type', 'post_tag', 'series'];
    foreach ($bottom_taxes as $tax) {
        $terms = get_the_terms($post_id, (string) $tax);
        if (is_array($terms)) {
            foreach ($terms as $t) {
                if (!is_object($t)) continue;
                /* EVERY taxonomy chip links to the Hub search (live filter over
                   the unified feed), NOT the WP term archive (/tag/<slug>/,
                   /category/<slug>/, /article-type/…, /series/… — all legacy WP
                   surfaces). The Hub reads ?q= as a free-text query, so search
                   by the human NAME — it matches feed content better than a
                   hyphenated slug. */
                $tags[] = [
                    'name' => (string) $t->name,
                    'url'  => '/hub/?q=' . urlencode((string) $t->name),
                    'tax'  => (string) $tax,
                ];
            }
        }
    }
}
if (function_exists('apply_filters')) {
    $tags = apply_filters('lg_layout_v2_post_header_tags', $tags, $post_id);
}

/* Drop any tag whose name matches the post author — the byline already
   communicates authorship, so a "Dan Erlewine" chip in the meta strip is
   redundant. Match case-insensitively to be forgiving. */
if ($author_id) {
    $author_dn = (string) get_the_author_meta('display_name', $author_id);
    if ($author_dn !== '') {
        $tags = array_values(array_filter($tags, function ($t) use ($author_dn) {
            return strcasecmp((string) $t['name'], $author_dn) !== 0;
        }));
    }
}

/* Author archive URL — used by both the avatar link in the byline and
   the "All posts" icon in the social row. Computed once. Points at the Hub
   filtered to this author; the Hub matches authors by NAME (CSV), so we build
   /hub/?author=<name> from the rendered $author_name. Built directly (not via
   the WP author archive + lg_layout_v2_author_archive_url filter) so the
   standalone renderer, which doesn't run WP filters, produces the same URL. */
$author_archive_url = '';
if ($author_name !== '') {
    $author_archive_url = '/hub/?author=' . rawurlencode($author_name);
}

/* ── Author links: 4 slots, each backed by a user-meta key + SVG icon.
   Filterable so the site can swap to its real ACF field keys without
   editing this file. ───────────────────────────────────────────── */
/* Author link slots backed by ACF user-meta keys. Order = render order;
   empty values are silently skipped so authors with only some links set
   don't get awkward gaps. Filter to override icons or add slots. */
$link_slots = [
    'looth_group_profile' => [
        'meta_key' => 'author_looth_group_profile',
        'title'    => 'Looth Group profile',
        'svg'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/></svg>',
    ],
    'website' => [
        'meta_key' => 'author_website',
        'title'    => 'Website',
        'svg'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2c3 3 3 17 0 20M12 2c-3 3-3 17 0 20"/></svg>',
    ],
    'instagram' => [
        'meta_key' => 'author_instagram',
        'title'    => 'Instagram',
        'svg'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor"/></svg>',
    ],
    'facebook' => [
        'meta_key' => 'author_facebook',
        'title'    => 'Facebook',
        'svg'      => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M13 10h3l.5-3H13V5c0-.9.3-1.5 1.6-1.5H17V.8C16.6.7 15.3.5 13.9.5 11 .5 9.1 2.3 9.1 5.6V7H6v3h3.1v8H13v-8z"/></svg>',
    ],
    'youtube' => [
        'meta_key' => 'author_youtube',
        'title'    => 'YouTube',
        'svg'      => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M21.6 7.2s-.2-1.4-.8-2c-.7-.8-1.5-.8-1.9-.8C16 4.2 12 4.2 12 4.2s-4 0-6.9.2c-.4.1-1.2.1-1.9.8-.6.6-.8 2-.8 2S2.2 8.8 2.2 10.5v1.5c0 1.7.2 3.3.2 3.3s.2 1.4.8 2c.7.8 1.7.7 2.1.8 1.6.2 6.7.2 6.7.2s4 0 6.9-.2c.4-.1 1.2-.1 1.9-.8.6-.6.8-2 .8-2s.2-1.7.2-3.3v-1.5c0-1.7-.2-3.3-.2-3.3zM10 14V8l5 3-5 3z"/></svg>',
    ],
    'linktree' => [
        'meta_key' => 'author_linktree',
        'title'    => 'Linktree',
        'svg'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M5 7l7 7 7-7M5 17l7-7 7 7"/></svg>',
    ],
];
if (function_exists('apply_filters')) {
    $link_slots = apply_filters('lg_layout_v2_post_header_author_links', $link_slots, $author_id);
}
$hidden_links = is_array($args['hidden_links'] ?? null) ? array_map('strval', $args['hidden_links']) : [];
$author_links = [];
if ($author_id) {
    foreach ($link_slots as $slot_key => $slot) {
        if (in_array((string) $slot_key, $hidden_links, true)) continue;
        $url = trim((string) get_user_meta($author_id, (string) $slot['meta_key'], true));
        if ($url === '') continue;
        $author_links[] = [
            'key'   => (string) $slot_key,
            'url'   => $url,
            'title' => (string) ($slot['title'] ?? ''),
            'svg'   => (string) ($slot['svg'] ?? ''),
        ];
    }
    /* Computed slots — not user-meta driven. Mirrored in post-footer. */
    if (!in_array('bp_profile', $hidden_links, true) && function_exists('bp_core_get_user_domain')) {
        $bp_url = (string) bp_core_get_user_domain($author_id);
        if ($bp_url !== '') {
            $author_links[] = [
                'key'   => 'bp_profile',
                'url'   => $bp_url,
                'title' => 'Member profile',
                'svg'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3 2.7-5.5 6-5.5s6 2.5 6 5.5"/><circle cx="17" cy="6" r="2.4"/><path d="M14 14c1-.6 2-.9 3-.9 2.4 0 4.4 1.7 4.4 4"/></svg>',
            ];
        }
    }
    if (!in_array('author_archive', $hidden_links, true) && $author_archive_url !== '') {
        $author_links[] = [
            'key'   => 'author_archive',
            'url'   => $author_archive_url,
            'title' => $author_name !== '' ? ('All posts by ' . $author_name) : 'All posts by this author',
            'svg'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h12M3 12h12M3 18h8"/><circle cx="19" cy="17" r="3"/><path d="M21.5 19.5L23 21"/></svg>',
        ];
    }
}

/* ── Editor-mode hook for inline tagline editing ──────────────────── */
$editorMode = !empty($ctx['editor_mode']);
$taglineEdit = $editorMode ? ' data-lg-edit-prop="tagline"' : '';

/* Title + tagline come from get_the_title() which already runs WP's
   title filters (wptexturize, convert_chars). Those produce HTML-safe
   entities like &#8217; for curly apostrophes — re-escaping them with
   htmlspecialchars would turn the & into &amp;, surfacing the entity
   text. Echo them directly. */
$safeTitle   = $title;
$safeTagline = $tagline;
$safeName    = htmlspecialchars($author_name, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
$safePub     = htmlspecialchars($pub_name,   ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

$depth = (int) ($args['_depth'] ?? 1);
$ind   = Renderer::indent($depth);

/* ── Sponsor variant — early fork ────────────────────────────────── */
if ($variant === 'sponsor') {
    /* Brand meta lives on the post author (user_{id} ACF prefix).
       Fall back to display_name / safe defaults when fields are absent. */
    $sp_prefix     = $author_id ? 'user_' . $author_id : '';
    $sp_brand_name = $sp_prefix ? (string) (get_field('brand_name', $sp_prefix) ?: $author_name) : $author_name;

    $sp_color_1      = $sp_prefix ? (string) (get_field('brand_primary_color_1',         $sp_prefix) ?: '#D4E0B8') : '#D4E0B8';
    $sp_color_2      = $sp_prefix ? (string) (get_field('brand_secondary_color_',         $sp_prefix) ?: '#87986A') : '#87986A';
    $sp_color_header = $sp_prefix ? (string) (get_field('brand_third_color_header_color', $sp_prefix) ?: '#1a1a1a') : '#1a1a1a';

    /* Luminance-driven title text color so light/dark header backgrounds stay readable. */
    $sp_hex = ltrim($sp_color_header, '#');
    if (strlen($sp_hex) === 3) $sp_hex = $sp_hex[0].$sp_hex[0].$sp_hex[1].$sp_hex[1].$sp_hex[2].$sp_hex[2];
    $sp_lum = (strlen($sp_hex) === 6)
        ? (0.299 * hexdec(substr($sp_hex,0,2)) + 0.587 * hexdec(substr($sp_hex,2,2)) + 0.114 * hexdec(substr($sp_hex,4,2))) / 255
        : 0;
    $sp_title_color = $sp_lum > 0.55 ? '#1a1a1a' : '#ffffff';

    /* Logo — ACF returns attachment array or raw ID. */
    $sp_logo_url = '';
    if ($sp_prefix) {
        $sp_logo_raw = get_field('brand-logo1', $sp_prefix);
        if (is_array($sp_logo_raw) && !empty($sp_logo_raw['url'])) {
            $sp_logo_url = $sp_logo_raw['url'];
        } elseif (is_numeric($sp_logo_raw) && (int) $sp_logo_raw > 0) {
            $sp_logo_url = (string) (wp_get_attachment_image_url((int) $sp_logo_raw, 'thumbnail') ?: '');
        }
    }

    /* Social links — only non-empty ones render. */
    $sp_socials    = [];
    $sp_social_map = [
        'brand_website'   => ['label' => 'Website',   'svg' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2c3 3.5 3 17.5 0 20M12 2c-3 3.5-3 17.5 0 20"/>'],
        'brand_instagram' => ['label' => 'Instagram', 'svg' => '<rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/>'],
        'brand_facebook'  => ['label' => 'Facebook',  'svg' => '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>'],
        'brand_youtube'   => ['label' => 'YouTube',   'svg' => '<path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19.1c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33z"/><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02"/>'],
    ];
    if ($sp_prefix) {
        foreach ($sp_social_map as $key => $meta) {
            $url = trim((string) (get_field($key, $sp_prefix) ?: ''));
            if ($url !== '') $sp_socials[] = ['url' => $url, 'label' => $meta['label'], 'svg' => $meta['svg']];
        }
    }

    /* CTA strip: sponsor page, email, website, archive (brand_tag).
       brand_tag currently points to the old search-results archive;
       TODO: update to /archive-poc/ URL format once query schema is confirmed. */
    $sp_cta = [];
    if ($sp_prefix) {
        $sp_page_url    = trim((string) (get_field('tlg_sponsor_page_url', $sp_prefix) ?: ''));
        $sp_email       = trim((string) (get_field('brand_email',          $sp_prefix) ?: ''));
        $sp_website_url = trim((string) (get_field('brand_website',        $sp_prefix) ?: ''));
        $sp_archive_url = trim((string) (get_field('brand_tag',            $sp_prefix) ?: ''));

        if ($sp_page_url    !== '') $sp_cta[] = ['href' => $sp_page_url,          'label' => 'Visit sponsor page', 'svg' => '<path d="M20 7H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>'];
        if ($sp_email       !== '') $sp_cta[] = ['href' => 'mailto:' . $sp_email, 'label' => 'Send email',        'svg' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>'];
        if ($sp_website_url !== '') $sp_cta[] = ['href' => $sp_website_url,       'label' => 'Visit website',     'svg' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2c3 3.5 3 17.5 0 20M12 2c-3 3.5-3 17.5 0 20"/>'];
        if ($sp_archive_url !== '') $sp_cta[] = ['href' => $sp_archive_url,       'label' => 'See all posts',     'svg' => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>'];
    }
    ?>
<?= $ind ?><header class="lg-post-header lg-post-header--sponsor">
<?php if ($photo_url !== ''): ?>
<?= $ind ?>  <div class="lg-post-header__spr-hero">
<?= $ind ?>    <img src="<?= Renderer::attr($photo_url) ?>" alt="<?= Renderer::attr($photo_alt) ?>" loading="eager" fetchpriority="high" />
<?php if ($tier_terms): ?>
<?= $ind ?>    <div class="lg-post-header__eyebrow">
<?php foreach ($tier_terms as $tier): ?>
<?= $ind ?>      <span class="lg-post-header__chip lg-post-header__chip--tier lg-post-header__chip--tier--<?= Renderer::attr($tier['slug']) ?>"><?= htmlspecialchars((string) $tier['name'], ENT_QUOTES, 'UTF-8') ?></span>
<?php endforeach; ?>
<?= $ind ?>    </div>
<?php endif; ?>
<?= $ind ?>  </div>
<?php elseif ($tier_terms): ?>
<?= $ind ?>  <div class="lg-post-header__eyebrow lg-post-header__eyebrow--static">
<?php foreach ($tier_terms as $tier): ?>
<?= $ind ?>    <span class="lg-post-header__chip lg-post-header__chip--tier lg-post-header__chip--tier--<?= Renderer::attr($tier['slug']) ?>"><?= htmlspecialchars((string) $tier['name'], ENT_QUOTES, 'UTF-8') ?></span>
<?php endforeach; ?>
<?= $ind ?>  </div>
<?php endif; ?>
<?= $ind ?>  <div class="lg-post-header__spr-title" style="background:<?= Renderer::attr($sp_color_header) ?>">
<?= $ind ?>    <h1 class="lg-post-header__title" style="color:<?= Renderer::attr($sp_title_color) ?>"><?= $safeTitle ?></h1>
<?php if ($tagline !== '' || $editorMode): ?>
<?= $ind ?>    <p class="lg-post-header__tagline" style="color:<?= $sp_lum > 0.55 ? 'rgba(0,0,0,0.55)' : 'rgba(255,255,255,0.7)' ?>"<?= $taglineEdit ?>><?= $safeTagline ?></p>
<?php endif; ?>
<?= $ind ?>  </div>
<?= $ind ?>  <div class="lg-post-header__spr-brand" style="border-bottom-color:<?= Renderer::attr($sp_color_1) ?>">
<?php if ($sp_logo_url !== ''): ?>
<?= $ind ?>    <div class="lg-post-header__spr-logo" style="border-color:<?= Renderer::attr($sp_color_1) ?>">
<?= $ind ?>      <img src="<?= Renderer::attr($sp_logo_url) ?>" alt="<?= Renderer::attr($sp_brand_name) ?> logo" loading="lazy" />
<?= $ind ?>    </div>
<?php endif; ?>
<?= $ind ?>    <div class="lg-post-header__spr-id">
<?= $ind ?>      <span class="lg-post-header__spr-label">Sponsored by</span>
<?= $ind ?>      <span class="lg-post-header__spr-name"><?= htmlspecialchars($sp_brand_name, ENT_QUOTES, 'UTF-8') ?></span>
<?= $ind ?>    </div>
<?php if ($sp_socials): ?>
<?= $ind ?>    <nav class="lg-post-header__spr-socials" aria-label="<?= Renderer::attr($sp_brand_name) ?> links" style="color:<?= Renderer::attr($sp_color_2) ?>">
<?php foreach ($sp_socials as $s): ?>
<?= $ind ?>      <a href="<?= Renderer::attr($s['url']) ?>" title="<?= Renderer::attr($s['label']) ?>" target="_blank" rel="noopener noreferrer">
<?= $ind ?>        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $s['svg'] ?></svg>
<?= $ind ?>      </a>
<?php endforeach; ?>
<?= $ind ?>    </nav>
<?php endif; ?>
<?= $ind ?>  </div>
<?php if ($sp_cta): ?>
<?= $ind ?>  <div class="lg-post-header__spr-cta">
<?php foreach ($sp_cta as $cta): ?>
<?= $ind ?>    <a href="<?= Renderer::attr($cta['href']) ?>"<?= strpos($cta['href'], 'mailto:') !== 0 ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
<?= $ind ?>      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $cta['svg'] ?></svg>
<?= $ind ?>      <span><?= htmlspecialchars($cta['label'], ENT_QUOTES, 'UTF-8') ?></span>
<?= $ind ?>    </a>
<?php endforeach; ?>
<?= $ind ?>  </div>
<?php endif; ?>
<?php if ($tags): ?>
<?= $ind ?>  <div class="lg-post-header__meta-strip">
<?= $ind ?>    <div class="lg-post-header__meta-strip-inner">
<?= $ind ?>      <div class="lg-post-header__chips">
<?php foreach ($tags as $tag): ?>
<?= $ind ?>        <a class="lg-post-header__chip" href="<?= Renderer::attr($tag['url']) ?>"><?= htmlspecialchars((string) $tag['name'], ENT_QUOTES, 'UTF-8') ?></a>
<?php endforeach; ?>
<?= $ind ?>      </div>
<?= $ind ?>    </div>
<?= $ind ?>  </div>
<?php endif; ?>
<?= $ind ?></header>
<?php
    return; /* sponsor variant complete */
}
/* ── Standard variants (variant-1 / variant-2 / variant-3 / video) ── */
?>
<?= $ind ?><header class="lg-post-header lg-post-header--<?= $variant ?>">
<?= $ind ?>  <div class="lg-post-header__hero">
<?php if ($photo_url !== ''): ?>
<?= $ind ?>    <img class="lg-post-header__photo" src="<?= Renderer::attr($photo_url) ?>" alt="<?= Renderer::attr($photo_alt) ?>" loading="eager" fetchpriority="high" />
<?php endif; ?>
<?= $ind ?>    <div class="lg-post-header__scrim" aria-hidden="true"></div>
<?php if ($tier_terms || $type_label !== ''): ?>
<?= $ind ?>    <div class="lg-post-header__eyebrow">
<?php if ($type_label !== ''): ?>
<?= $ind ?>      <span class="lg-post-header__chip lg-post-header__chip--type lg-post-header__chip--type--<?= Renderer::attr($post_type) ?>"><?= htmlspecialchars($type_label, ENT_QUOTES, 'UTF-8') ?></span>
<?php endif; ?>
<?php foreach ($tier_terms as $tier): ?>
<?= $ind ?>      <span class="lg-post-header__chip lg-post-header__chip--tier lg-post-header__chip--tier--<?= Renderer::attr($tier['slug']) ?>"><?= htmlspecialchars((string) $tier['name'], ENT_QUOTES, 'UTF-8') ?></span>
<?php endforeach; ?>
<?= $ind ?>    </div>
<?php endif; ?>
<?= $ind ?>    <div class="lg-post-header__body">
<?= $ind ?>      <div class="lg-post-header__inner">
<?php if ($title !== ''): ?>
<?= $ind ?>        <h1 class="lg-post-header__title"><?= $safeTitle ?></h1>
<?php endif; ?>
<?php if ($tagline !== '' || $editorMode): ?>
<?= $ind ?>        <p class="lg-post-header__tagline"<?= $taglineEdit ?>><?= $safeTagline ?></p>
<?php endif; ?>
<?= $ind ?>        <div class="lg-post-header__byline">
<?php if ($avatar_url !== ''): ?>
<?php if ($author_archive_url !== ''): ?>
<?= $ind ?>          <a class="lg-post-header__avatar-link" href="<?= Renderer::attr($author_archive_url) ?>" aria-label="<?= Renderer::attr($author_name !== '' ? ('All posts by ' . $author_name) : 'All posts by this author') ?>">
<?= $ind ?>            <img class="lg-post-header__avatar" src="<?= Renderer::attr($avatar_url) ?>" alt="" loading="lazy" />
<?= $ind ?>          </a>
<?php else: ?>
<?= $ind ?>          <img class="lg-post-header__avatar" src="<?= Renderer::attr($avatar_url) ?>" alt="" loading="lazy" />
<?php endif; ?>
<?php endif; ?>
<?php if ($author_name !== '' || $pub_name !== ''): ?>
<?= $ind ?>          <div class="lg-post-header__id">
<?php if ($author_name !== ''): ?>
<?= $ind ?>            <span class="lg-post-header__name"><?= $safeName ?></span>
<?php endif; ?>
<?php if ($pub_name !== ''): ?>
<?= $ind ?>            <span class="lg-post-header__pub"><?= $safePub ?></span>
<?php endif; ?>
<?= $ind ?>          </div>
<?php endif; ?>
<?php
$meta_parts = [];
if ($date_str !== '') $meta_parts[] = $date_str;
if ($read_min > 0)    $meta_parts[] = $read_min . ' min read';
?>
<?php if ($meta_parts): ?>
<?= $ind ?>          <span class="lg-post-header__meta"><?= htmlspecialchars(implode(' · ', $meta_parts), ENT_QUOTES, 'UTF-8') ?></span>
<?php endif; ?>
<?php
$can_edit = !empty($ctx['can_edit']);
/* When the viewer can edit, expose every editable user-meta value on the
   social-row wrapper. The shared author-info modal reads these attrs to
   pre-fill its fields — same modal lives in the post-footer. */
$author_meta_attrs = '';
if ($can_edit && $author_id) {
    foreach (['author_about','author_looth_group_profile','author_website','author_instagram','author_facebook','author_youtube','author_linktree'] as $k) {
        $v = (string) (get_user_meta($author_id, $k, true) ?: '');
        $author_meta_attrs .= ' data-meta-' . str_replace('_','-',$k) . '="' . Renderer::attr($v) . '"';
    }
    $author_meta_attrs .= ' data-author-id="' . (int) $author_id . '"';
}
?>
<?php if ($author_links || $can_edit): ?>
<?= $ind ?>          <span class="lg-post-header__links"<?= $author_meta_attrs ?>>
<?php foreach ($author_links as $link): ?>
<?= $ind ?>            <a href="<?= Renderer::attr($link['url']) ?>" title="<?= Renderer::attr($link['title']) ?>" data-slot="<?= Renderer::attr($link['key']) ?>" rel="noopener" target="_blank"><?= $link['svg'] ?></a>
<?php endforeach; ?>
<?php if ($can_edit): ?>
<?= $ind ?>            <button type="button" class="lg-post-header__links-edit" data-lg-header-links-edit title="Manage social icons" aria-label="Manage social icons">
<?= $ind ?>              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
<?= $ind ?>            </button>
<?php endif; ?>
<?= $ind ?>          </span>
<?php endif; ?>
<?= $ind ?>        </div>
<?= $ind ?>      </div>
<?= $ind ?>    </div>
<?= $ind ?>  </div>
<?php if ($tags): ?>
<?= $ind ?>  <div class="lg-post-header__meta-strip">
<?= $ind ?>    <div class="lg-post-header__meta-strip-inner">
<?= $ind ?>      <div class="lg-post-header__chips">
<?php foreach ($tags as $tag): ?>
<?= $ind ?>        <a class="lg-post-header__chip" href="<?= Renderer::attr($tag['url']) ?>"><?= htmlspecialchars((string) $tag['name'], ENT_QUOTES, 'UTF-8') ?></a>
<?php endforeach; ?>
<?= $ind ?>      </div>
<?= $ind ?>    </div>
<?= $ind ?>  </div>
<?php endif; ?>
<?= $ind ?></header>
