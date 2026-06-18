<?php
/**
 * Deterministic loothprint -> lg-layout-v2 layout parser. NO AI.
 * Maps the loothprint ACF field set (per recipes/loothprint.md) to v2 blocks:
 *   post-header -> images/gallery -> wysiwyg(panel) -> embed? -> download -> links? -> license? -> post-footer
 * The `download` block holds the file ONLY; it auto-gates from the post's `tier` term.
 *
 * Run (dry-run, writes JSON to /tmp, prints nothing to WP):
 *   LG_PARSE_POST=<id> wp --path=/var/www/dev eval-file tools/loothprint-parse.php
 */

function lp_clean(string $html): string {
    $html = str_replace(["\r\n", "\r"], "\n", $html);   // normalize line endings so \n{2,} paragraph splits fire
    $html = preg_replace('#</(p|div|h[1-6]|li)>#i', "\n\n", $html);
    $html = preg_replace('#<br\s*/?>#i', "\n", $html);
    $html = wp_strip_all_tags($html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = str_replace("\xc2\xa0", ' ', $html);
    return trim($html);
}
function lp_host(string $url): string {
    $h = parse_url($url, PHP_URL_HOST) ?: $url;
    return preg_replace('/^www\./', '', $h);
}
function lp_icon(string $url): string {
    $h = strtolower(lp_host($url));
    if (str_contains($h, 'instagram')) return 'instagram';
    if (str_contains($h, 'youtu'))     return 'youtube';
    if (str_contains($h, 'facebook'))  return 'facebook';
    return 'globe';
}

/** @return array{layout:?array, flag:?string, stats:array} */
/** `document` is a thin sibling: a PDF download (file_upload attachment or pdf_url), a
 *  thumbnail, and a usually-empty description. No gallery/video/onshape. Routes /document/<id>/. */
function lp_parse_document(int $postId, $post): array {
    $fileId = (int) get_post_meta($postId, 'file_upload', true);
    $pdfUrl = trim((string) (get_post_meta($postId, 'pdf_url', true) ?: get_post_meta($postId, 'download_url', true)));
    if (!$fileId && $pdfUrl === '') return ['layout' => null, 'flag' => 'no document file (file_upload/pdf_url)', 'stats' => []];

    $title    = html_entity_decode(get_the_title($postId), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $featured = (int) get_post_meta($postId, '_thumbnail_id', true);
    $tier     = wp_get_post_terms($postId, 'tier', ['fields' => 'slugs']); $tier = is_wp_error($tier) ? [] : $tier;

    $text = lp_clean($post->post_content);
    $descLines = [];
    foreach (preg_split('/\n{2,}/', $text) as $para) { $para = trim(preg_replace('/[ \t]+/', ' ', $para)); if ($para !== '') $descLines[] = $para; }
    $desc    = implode("\n\n", $descLines);
    $tagline = $desc !== '' ? mb_substr(preg_split('/(?<=[.!?])\s+/', $desc)[0] ?? $desc, 0, 180) : '';

    $blocks = [];
    $blocks[] = ['type' => 'post-header', 'title' => $title, 'tagline' => $tagline, 'featured_image_id' => $featured ?: null,
                 'show_byline' => true, 'show_categories' => true, 'show_tags' => true];
    if ($desc !== '') $blocks[] = ['type' => 'wysiwyg', 'style' => 'panel',
                                   'html' => make_clickable('<p>' . implode('</p><p>', array_map('esc_html', $descLines)) . '</p>')];
    // the money shot — PDF. Prefer the attachment (ext/size auto-derive); else the raw URL.
    $blocks[] = $fileId ? ['type' => 'download', 'file_id' => $fileId]
                        : ['type' => 'download', 'url' => $pdfUrl, 'label' => $title, 'ext' => 'PDF', 'icon' => 'file-pdf'];
    $blocks[] = ['type' => 'post-footer', 'show_author' => true, 'show_related' => true];

    return ['layout' => ['schema' => 1, '_meta' => ['importer' => 'loothprint-parse/1', 'source_post' => $postId, 'imported_at' => gmdate('c')], 'blocks' => $blocks],
            'flag' => null,
            'stats' => ['tier' => $tier[0] ?? 'public', 'images' => 0, 'has_video' => false, 'support_rows' => 0,
                        'has_license' => false, 'desc_chars' => strlen($desc), 'dropped_media' => 0,
                        'doc_file' => $fileId ? "attachment:$fileId" : 'url']];
}

function lp_parse(int $postId): array {
    $post = get_post($postId);
    if (!$post) return ['layout' => null, 'flag' => 'no such post', 'stats' => []];
    if ($post->post_type === 'document') return lp_parse_document($postId, $post);

    // CPT-aware field map. loothcuts is the same shape with a `loothcut_` prefix; its
    // download is `cnc_file` and its prose lives in an ACF field, not post_content.
    $pre     = $post->post_type === 'loothcuts' ? 'loothcut' : 'loothprint';
    $fileKey = $pre === 'loothcut' ? 'loothcut_cnc_file' : 'loothprint_3d_file';

    $fileId = (int) get_post_meta($postId, $fileKey, true);
    if (!$fileId) return ['layout' => null, 'flag' => "no $fileKey", 'stats' => []];

    $title    = html_entity_decode(get_the_title($postId), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $featured = (int) get_post_meta($postId, '_thumbnail_id', true);
    $imagesAll = array_values(array_filter(array_map('intval', (array) maybe_unserialize(get_post_meta($postId, "{$pre}_more_images", true)))));
    // more_images can contain non-image attachments (e.g. a self-hosted .mp4 demo) — an <img>
    // can't render those. Keep image mime-types only; count drops for the review stat.
    $images       = array_values(array_filter($imagesAll, fn($iid) => strpos((string) get_post_mime_type($iid), 'image/') === 0));
    $droppedMedia = count($imagesAll) - count($images);
    $video    = trim((string) get_post_meta($postId, "{$pre}_video_instructions", true));
    $onshape  = trim((string) get_post_meta($postId, "{$pre}_onshape_link", true));
    $coffee   = trim((string) get_post_meta($postId, "{$pre}_buy_me_a_coffee", true));
    $license  = trim((string) get_post_meta($postId, "{$pre}_creative_commons", true));
    $tier     = wp_get_post_terms($postId, 'tier', ['fields' => 'slugs']);
    $tier     = is_wp_error($tier) ? [] : $tier;

    // ---- prose -> paragraphs + lifted bare URLs. loothprint prose = post_content;
    //      loothcuts prose = the `about_your_loothcut` ACF field. ----
    $rawDesc = $pre === 'loothcut' ? (string) get_post_meta($postId, 'loothcut_about_your_loothcut', true) : $post->post_content;
    $text = lp_clean($rawDesc);
    $descLines = []; $bodyUrls = [];
    foreach (preg_split('/\n{2,}/', $text) as $para) {
        $para = trim(preg_replace('/[ \t]+/', ' ', $para));
        if ($para === '') continue;
        if (preg_match('#^https?://\S+$#', $para)) { $bodyUrls[] = $para; continue; }
        $descLines[] = $para;
    }
    $desc    = implode("\n\n", $descLines);
    $tagline = $desc !== '' ? mb_substr(preg_split('/(?<=[.!?])\s+/', $desc)[0] ?? $desc, 0, 180) : '';

    // ---- Source & Support rows (ACF + lifted body URLs), public companions ----
    $support = [];
    if ($onshape !== '') $support[] = ['icon' => 'globe', 'label' => 'Onshape source', 'url' => $onshape, 'description' => 'CAD source files'];
    foreach ($bodyUrls as $u) $support[] = ['icon' => lp_icon($u), 'label' => lp_host($u), 'url' => $u, 'description' => ''];
    if ($coffee !== '')  $support[] = ['icon' => 'link', 'label' => 'Support the maker', 'url' => $coffee, 'description' => 'Buy the maker a coffee'];

    // ---- assemble ----
    $blocks = [];
    $blocks[] = ['type' => 'post-header', 'title' => $title, 'tagline' => $tagline,
                 'featured_image_id' => $featured ?: null, 'show_byline' => true, 'show_categories' => true, 'show_tags' => true];

    // build photos: <=2 -> image blocks, >2 -> gallery
    if (count($images) > 2) {
        $blocks[] = ['type' => 'gallery', 'image_ids' => $images, 'columns' => 3];
    } else {
        foreach ($images as $iid) {
            $alt = (string) get_post_meta($iid, '_wp_attachment_image_alt', true);
            $blocks[] = ['type' => 'image', 'image_id' => $iid, 'alt' => $alt, 'image_text' => $alt, 'variant' => 'variant-1'];
        }
    }

    if ($desc !== '') {
        // esc_html first, then make_clickable so inline/bare URLs in the prose become <a> links
        $html = make_clickable('<p>' . implode('</p><p>', array_map('esc_html', $descLines)) . '</p>');
        // make_clickable sets the link TEXT to the full URL; long querystrings then overflow the
        // panel. Shorten the visible text to the host (href keeps the full URL).
        $html = preg_replace_callback('#<a\s+href="(https?://[^"]+)"([^>]*)>(https?://[^<]+)</a>#i', function ($m) {
            $host = preg_replace('#^www\.#', '', parse_url($m[1], PHP_URL_HOST) ?: $m[3]);
            return '<a href="' . $m[1] . '"' . $m[2] . '>' . $host . '</a>';
        }, $html);
        $blocks[] = ['type' => 'wysiwyg', 'style' => 'panel', 'html' => $html];
    }
    // instructional video stays PUBLIC (teaser) — gated_tier:"public" opts the embed out of
    // auto-gating (embed is in AUTO_GATE_TYPES); only the download is the gated deliverable.
    if ($video !== '') $blocks[] = ['type' => 'embed', 'url' => $video, 'gated_tier' => 'public'];

    // the money shot — file only, auto-gates from the post tier
    $blocks[] = ['type' => 'download', 'file_id' => $fileId];

    if ($support) $blocks[] = ['type' => 'callout', 'variant' => 'links', 'title' => 'Source & Support', 'items' => $support];
    if ($license !== '') $blocks[] = ['type' => 'callout', 'variant' => 'note', 'title' => 'License',
                                      'body' => '<p>' . esc_html($license) . '</p>'];

    $blocks[] = ['type' => 'post-footer', 'show_author' => true, 'show_related' => true];

    $layout = [
        'schema' => 1,
        '_meta'  => ['importer' => 'loothprint-parse/1', 'source_post' => $postId, 'imported_at' => gmdate('c')],
        'blocks' => $blocks,
    ];
    return ['layout' => $layout, 'flag' => null,
            'stats' => ['tier' => $tier[0] ?? 'public', 'images' => count($images), 'has_video' => $video !== '',
                        'support_rows' => count($support), 'has_license' => $license !== '', 'desc_chars' => strlen($desc),
                        'dropped_media' => $droppedMedia]];
}

/* ---------------- runner ---------------- */
function lp_print_summary(int $id, array $r): void {
    echo "\n===== LOOTHPRINT $id : " . html_entity_decode(get_the_title($id), ENT_QUOTES | ENT_HTML5, 'UTF-8') . " =====\n";
    if ($r['flag']) { echo "  FLAGGED: {$r['flag']}\n"; return; }
    echo "  stats: " . json_encode($r['stats']) . "\n";
    foreach ($r['layout']['blocks'] as $b) {
        $t = $b['type']; $d = '';
        if ($t === 'post-header')      $d = 'hero=' . ($b['featured_image_id'] ?? '-') . '  tagline: ' . mb_substr($b['tagline'], 0, 56);
        elseif ($t === 'image')        $d = '#' . $b['image_id'] . ' ' . mb_substr($b['alt'], 0, 40);
        elseif ($t === 'gallery')      $d = count($b['image_ids']) . ' photos';
        elseif ($t === 'wysiwyg')      $d = strlen($b['html']) . ' chars';
        elseif ($t === 'embed')        $d = $b['url'];
        elseif ($t === 'download')     $d = 'file_id=' . $b['file_id'] . ' (auto-gates from tier ' . ($r['stats']['tier'] ?? '?') . ')';
        elseif ($t === 'callout')      $d = "'" . $b['title'] . "' (" . $b['variant'] . ') ' . (($b['variant'] === 'links') ? implode(', ', array_map(fn($i) => $i['label'], $b['items'])) : '');
        echo sprintf("    %-13s %s\n", $t, $d);
    }
}

$one = getenv('LG_PARSE_POST');
if ($one) {
    $id = (int) $one;
    if (get_post_meta($id, '_lg_layout_v2', true)) {
        echo "loothprint $id ALREADY has a _lg_layout_v2 layout — skip-if-exists guard. (clear its meta first to redo)\n";
        return;
    }
    $r = lp_parse($id);
    lp_print_summary($id, $r);
    if (!$r['flag']) {
        $out = '/tmp/lg-loothprint-' . $id . '.json';
        file_put_contents($out, json_encode($r['layout'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        echo "\n  layout JSON written: $out\n";
    }
    return;
}
echo "Set LG_PARSE_POST=<id> to parse one loothprint.\n";
