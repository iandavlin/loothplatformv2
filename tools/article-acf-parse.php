<?php
/**
 * Deterministic post-imgcap (article) -> lg-layout-v2 parser for the ACF
 * `img_cap_images_and_captions_repeater` content model (empty post_content).
 * Companion to tools/article-parse.php (which handles the inline-HTML model). NO AI.
 *
 * Each repeater row (field order = author order) may carry:
 *   img_cap_repeater_image  (single attachment id)   -> image block (aspect-classified)
 *   gallery2                (serialized id array)     -> image blocks (Ian's pairing rule)
 *   img_cap_repeater_text   (HTML)                    -> figcaption on a lone single image
 *                                                        (short, link-free) else a panel
 *   repeater_oembed         (youtube/instagram URL)   -> embed block
 *
 * Gating (Ian 6/4): teaser-then-paywall. For non-public tiers a `paywall` section
 * block (tier = post tier) is inserted after row 0's blocks; anon gets the lead
 * image + opening, the rest is trimmed server-side.
 *
 * Run (dry-run, writes /tmp/lg-acf-<id>.json):  LG_PARSE_POST=<id> wp eval-file tools/article-acf-parse.php
 * Batch all unconverted ACF posts (writes each json + summary):  LG_PARSE_ALL=1 wp eval-file tools/article-acf-parse.php
 */

function acf_aspect(int $aid): array {            // [class, ratioStr] — Ian's rule
    $m = $aid ? wp_get_attachment_metadata($aid) : null;
    $w = $m['width'] ?? 0; $h = $m['height'] ?? 0;
    if (!$w || !$h) return ['single', ''];
    $ar = $w / $h;
    if ($ar < 0.9)  return ['pair', '2/3'];       // portrait -> pairs side-by-side
    if ($ar <= 1.4) return ['single', '1/1'];     // ~square
    return ['single', ''];                        // wide -> intrinsic (no crop)
}

/** HTML run -> clean panel-ready HTML (or '' if empty). */
function acf_prose(string $html): string {
    $html = preg_replace('#</?div[^>]*>#i', "\n\n", $html);
    $html = str_replace("\xc2\xa0", ' ', $html);
    $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);   // un-rendered markdown bold
    $html = trim($html);
    if (trim(strip_tags($html)) === '') return '';
    $html = wpautop($html);
    $html = make_clickable($html);
    $html = preg_replace_callback('#<a\s+href="(https?://[^"]+)"([^>]*)>(https?://[^<]+)</a>#i', function ($m) {
        $host = preg_replace('#^www\.#', '', parse_url($m[1], PHP_URL_HOST) ?: $m[3]);
        return '<a href="' . $m[1] . '"' . $m[2] . '>' . $host . '</a>';
    }, $html);
    return wp_kses_post($html);
}

/** plain-text length + link presence test for the caption-vs-panel decision. */
function acf_is_caption(string $html): bool {
    if (stripos($html, '<a ') !== false) return false;                 // has links -> keep as panel (captions drop them)
    $plain = trim(html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return $plain !== '' && mb_strlen($plain) <= 300;
}

/** @return array{layout:?array, flag:?string, stats:array} */
function acf_parse(int $postId): array {
    $n = (int) get_post_meta($postId, 'img_cap_images_and_captions_repeater', true);
    if ($n < 1) return ['layout' => null, 'flag' => 'no img_cap repeater rows', 'stats' => []];

    $title = html_entity_decode(get_the_title($postId), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $rid = fn() => 'b_' . substr(md5(uniqid('', true)), 0, 6);

    $blocks = []; $tagline = ''; $imgCount = 0; $row0End = 0; $embeds = 0;
    for ($i = 0; $i < $n; $i++) {
        $base = "img_cap_images_and_captions_repeater_{$i}_";
        $g = $base;
        $rowImgs = [];                                    // image block refs added this row (for caption attach)

        // 1. single image
        $sid = (int) get_post_meta($postId, $g . 'img_cap_repeater_image', true);
        if ($sid && strpos((string) get_post_mime_type($sid), 'image/') === 0) {
            [$cls, $ar] = acf_aspect($sid);
            $b = ['type' => 'image', 'id' => $rid(), 'image_id' => $sid, 'alt' => get_post_meta($sid, '_wp_attachment_image_alt', true) ?: '',
                  'image_text' => '', 'variant' => 'variant-1', '_cls' => $cls];
            if ($ar !== '') $b['aspect'] = $ar;
            $blocks[] = $b; $rowImgs[] = count($blocks) - 1; $imgCount++;
        }

        // 2. gallery
        $gal = get_post_meta($postId, $g . 'gallery2', true);
        $gal = is_array($gal) ? $gal : (is_string($gal) && $gal !== '' ? @unserialize($gal) : []);
        foreach ((array) $gal as $gid) {
            $gid = (int) $gid;
            if (!$gid || strpos((string) get_post_mime_type($gid), 'image/') !== 0) continue;
            [$cls, $ar] = acf_aspect($gid);
            $b = ['type' => 'image', 'id' => $rid(), 'image_id' => $gid, 'alt' => get_post_meta($gid, '_wp_attachment_image_alt', true) ?: '',
                  'image_text' => '', 'variant' => 'variant-1', '_cls' => $cls];
            if ($ar !== '') $b['aspect'] = $ar;
            $blocks[] = $b; $imgCount++;
        }

        // 3. text — caption on a lone single image, else panel
        $txt = (string) get_post_meta($postId, $g . 'img_cap_repeater_text', true);
        $html = acf_prose($txt);
        if ($html !== '') {
            if ($tagline === '') {
                $tagline = mb_substr(preg_split('/(?<=[.!?])\s+/', trim(strip_tags($html)))[0] ?? '', 0, 180);
            }
            $loneImg = (count($rowImgs) === 1 && count((array) $gal) === 0);
            if ($loneImg && acf_is_caption($html)) {
                $plain = trim(html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $blocks[$rowImgs[0]]['image_text'] = $plain;
            } else {
                $blocks[] = ['type' => 'wysiwyg', 'style' => 'panel', 'html' => $html];
            }
        }

        // 4. oembed (youtube / instagram)
        $oe = trim((string) get_post_meta($postId, $g . 'repeater_oembed', true));
        if ($oe !== '') {
            $blocks[] = ['type' => 'embed', 'id' => $rid(), 'url' => $oe, 'ratio' => '16x9', 'caption' => '', 'variant' => 'variant-1'];
            $embeds++;
        }

        if ($i === 0) $row0End = count($blocks);          // mark teaser boundary
    }

    if (!$blocks) return ['layout' => null, 'flag' => 'rows present but no renderable content', 'stats' => []];

    // pairing pass: adjacent portrait images -> 2-col columns
    $paired = []; $j = 0; $m = count($blocks);
    while ($j < $m) {
        $b = $blocks[$j];
        if (($b['type'] ?? '') === 'image' && ($b['_cls'] ?? '') === 'pair'
            && isset($blocks[$j + 1]) && $blocks[$j + 1]['type'] === 'image' && ($blocks[$j + 1]['_cls'] ?? '') === 'pair') {
            $c1 = $b; $c2 = $blocks[$j + 1]; unset($c1['_cls'], $c2['_cls']);
            $paired[] = ['type' => 'columns', 'id' => $rid(), 'variant' => 'variant-1',
                         'columns' => [['blocks' => [$c1]], ['blocks' => [$c2]]], '_row0' => ($j + 1 < $row0End)];
            $j += 2; continue;
        }
        unset($b['_cls']); $b['_row0'] = ($j < $row0End); $paired[] = $b; $j++;
    }

    // number images sequentially (reading order)
    $num = 0;
    foreach ($paired as &$blk) {
        if (($blk['type'] ?? '') === 'image') $blk['number'] = (string) (++$num);
        elseif (($blk['type'] ?? '') === 'columns')
            foreach ($blk['columns'] as &$col) foreach ($col['blocks'] as &$cb)
                if (($cb['type'] ?? '') === 'image') $cb['number'] = (string) (++$num);
        unset($col, $cb);
    }
    unset($blk);

    // teaser-then-paywall: insert after row-0 blocks for non-public tiers
    $tier = wp_get_post_terms($postId, 'tier', ['fields' => 'slugs']); $tier = is_wp_error($tier) ? [] : $tier;
    $postTier = $tier[0] ?? 'public';
    $body = []; $gated = false;
    foreach ($paired as $blk) {
        $isRow0 = $blk['_row0'] ?? false; unset($blk['_row0']);
        if (!$gated && !$isRow0 && $postTier !== 'public') {
            $body[] = ['type' => 'paywall', 'tier' => $postTier, 'label' => 'The rest of this article is for members'];
            $gated = true;
        }
        $body[] = $blk;
    }

    $featured = (int) get_post_meta($postId, '_thumbnail_id', true);
    $out = array_merge(
        [['type' => 'post-header', 'title' => $title, 'tagline' => $tagline, 'featured_image_id' => $featured ?: null,
          'show_byline' => true, 'show_categories' => true, 'show_tags' => true]],
        $body,
        [['type' => 'post-footer', 'show_author' => true, 'show_related' => true]]
    );
    $layout = ['schema' => 1, '_meta' => ['importer' => 'article-acf-parse/1', 'source_post' => $postId, 'imported_at' => gmdate('c')], 'blocks' => $out];

    $panels = count(array_filter($body, fn($b) => ($b['type'] ?? '') === 'wysiwyg'));
    $pairs  = count(array_filter($body, fn($b) => ($b['type'] ?? '') === 'columns'));
    return ['layout' => $layout, 'flag' => null,
            'stats' => ['tier' => $postTier, 'rows' => $n, 'images' => $imgCount, 'pairs' => $pairs, 'panels' => $panels,
                        'embeds' => $embeds, 'gated' => $gated]];
}

/* ---------------- runner ---------------- */
function acf_emit(int $id, bool $verbose): ?array {
    if (get_post_meta($id, '_lg_layout_v2', true)) { echo "post $id ALREADY has a layout — skip.\n"; return null; }
    $r = acf_parse($id);
    if ($r['flag']) { echo "  $id FLAGGED: {$r['flag']}\n"; return null; }
    $file = '/tmp/lg-acf-' . $id . '.json';
    file_put_contents($file, json_encode($r['layout'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    echo "  $id  " . json_encode($r['stats']) . "  -> $file\n";
    if ($verbose) {
        echo "  tagline: " . mb_substr($r['layout']['blocks'][0]['tagline'], 0, 90) . "\n";
        foreach ($r['layout']['blocks'] as $b) {
            $t = $b['type']; $d = '';
            if ($t === 'wysiwyg') $d = strlen($b['html']) . 'c';
            elseif ($t === 'image') $d = '[' . ($b['aspect'] ?? 'intrinsic') . '] #' . $b['image_id'] . ' cap:' . mb_substr($b['image_text'], 0, 30);
            elseif ($t === 'columns') $d = 'PAIR ' . implode(' | ', array_map(fn($c) => '#' . $c['blocks'][0]['image_id'], $b['columns']));
            elseif ($t === 'embed') $d = $b['url'];
            elseif ($t === 'paywall') $d = '>>> ' . $b['label'];
            echo sprintf("    %-15s %s\n", $t, $d);
        }
    }
    return $r['stats'];
}

if (getenv('LG_PARSE_ALL')) {
    $q = new WP_Query(['post_type' => 'post-imgcap', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids',
                       'meta_query' => [['key' => '_lg_layout_v2', 'compare' => 'NOT EXISTS']]]);
    $ok = 0; $flag = 0;
    foreach ($q->posts as $id) {
        if ((int) get_post_meta($id, 'img_cap_images_and_captions_repeater', true) < 1) continue;   // not an ACF post
        $s = acf_emit($id, false);
        $s ? $ok++ : $flag++;
    }
    echo "\nACF batch: $ok written, $flag flagged/skipped.\n";
    return;
}
$one = getenv('LG_PARSE_POST');
if ($one) { acf_emit((int) $one, true); return; }
echo "Set LG_PARSE_POST=<id> (single, verbose) or LG_PARSE_ALL=1 (batch).\n";
