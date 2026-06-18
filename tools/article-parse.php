<?php
/**
 * Deterministic post-imgcap (article) -> lg-layout-v2 layout parser. NO AI.
 * Walks the inline-HTML body in document order:
 *   prose-run -> panel wysiwyg (wpautop preserves paragraphs + lists; make_clickable + host-shorten)
 *   <h2-6>    -> section-heading (OUTSIDE panels)
 *   <img>     -> image block, aspect-classified (Ian's rule: portrait pairs side-by-side in
 *                a columns block; square/wide render single full-width). non-image attachments dropped.
 * Brackets with post-header (tagline = first sentence; featured = _thumbnail_id) + post-footer.
 *
 * Run (dry-run, writes /tmp/lg-article-<id>.json):
 *   LG_PARSE_POST=<id> wp --path=/var/www/dev eval-file tools/article-parse.php
 *
 * NB: handles the INLINE-HTML content model. Posts whose content lives in the ACF
 * `img_cap_images_and_captions_repeater` (empty post_content) are flagged for a second pass.
 */

function ap_aspect(int $aid): array {  // [class, ratioStr]
    $m = $aid ? wp_get_attachment_metadata($aid) : null;
    $w = $m['width'] ?? 0; $h = $m['height'] ?? 0;
    if (!$w || !$h) return ['single', ''];
    $ar = $w / $h;
    if ($ar < 0.9)  return ['pair',   '2/3'];
    if ($ar <= 1.4) return ['single', '1/1'];
    return ['single', ''];   // wide → intrinsic (no crop)
}

/** prose-run HTML -> clean panel-ready HTML (or '' if empty). */
function ap_prose(string $html): string {
    $html = preg_replace('#</?div[^>]*>#i', "\n\n", $html);     // drop wrapper divs
    $html = str_replace("\xc2\xa0", ' ', $html);
    $html = trim($html);
    if (trim(strip_tags($html)) === '') return '';
    $html = wpautop($html);                                     // bare text + blank lines -> <p>; keeps <ul>/<li>
    $html = make_clickable($html);
    // shorten make_clickable's URL-text to the host so long querystrings don't overflow the panel
    $html = preg_replace_callback('#<a\s+href="(https?://[^"]+)"([^>]*)>(https?://[^<]+)</a>#i', function ($m) {
        $host = preg_replace('#^www\.#', '', parse_url($m[1], PHP_URL_HOST) ?: $m[3]);
        return '<a href="' . $m[1] . '"' . $m[2] . '>' . $host . '</a>';
    }, $html);
    return wp_kses_post($html);
}

/** Drop leading dateline/byline/bare-date <p>s (redundant with the post-header) so the
 *  tagline starts at the real lede. e.g. "LG Series 1, Episode 1 — by Todd Lunneborg", "April 14, 2026". */
function ap_strip_lead_meta(string $html): string {
    $meta = '#^(lg series\b.*|by\s+[a-z][\w.\-\' ]{1,40}|(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+\d{1,2},?\s+\d{4}|\d{1,2}[/\-]\d{1,2}[/\-]\d{2,4})$#i';
    while (preg_match('#^\s*<p[^>]*>(.*?)</p>#is', $html, $m)) {
        $txt = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));
        if ($txt !== '' && mb_strlen($txt) < 70 && preg_match($meta, $txt)) { $html = ltrim(substr($html, strlen($m[0]))); }
        else break;
    }
    return trim($html);
}

/** @return array{layout:?array, flag:?string, stats:array} */
function ap_parse(int $postId): array {
    $post = get_post($postId);
    if (!$post) return ['layout' => null, 'flag' => 'no such post', 'stats' => []];

    $title = html_entity_decode(get_the_title($postId), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $c = $post->post_content;
    $c = preg_replace('/<!--.*?-->/s', '', $c);
    $c = str_replace(["\r\n", "\r"], "\n", $c);

    if (substr_count($c, '<img') === 0 && (int) get_post_meta($postId, 'img_cap_images_and_captions_repeater', true) > 0) {
        return ['layout' => null, 'flag' => 'ACF img_cap repeater model (no inline imgs) — needs repeater pass', 'stats' => []];
    }

    // tokenize: split on <img> and <h2-6> headings (delimiters captured)
    $parts = preg_split('#(<img[^>]*>|<h[2-6][^>]*>.*?</h[2-6]>)#is', $c, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

    $rid = fn() => 'b_' . substr(md5(uniqid('', true)), 0, 6);
    // pass 1: ordered events (heading | image | prose)
    $events = []; $tagline = ''; $imgCount = 0; $dropped = 0;
    foreach ($parts as $part) {
        if (preg_match('#^<h([2-6])[^>]*>(.*?)</h[2-6]>$#is', $part, $m)) {
            $txt = trim(html_entity_decode(wp_strip_all_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($txt === '' || mb_strtolower($txt) === mb_strtolower($title)) continue;   // skip empty / title echo
            $events[] = ['t' => 'heading', 'level' => ($m[1] === '2' ? 'h2' : 'h3'), 'text' => $txt];
        } elseif (preg_match('#^<img#i', $part)) {
            preg_match('/src="([^"]+)"/i', $part, $s); preg_match('/alt="([^"]*)"/i', $part, $a);
            $src = $s[1] ?? ''; $alt = $a[1] ?? '';
            $aid = $src ? attachment_url_to_postid($src) : 0;
            if ($aid && strpos((string) get_post_mime_type($aid), 'image/') !== 0) { $dropped++; continue; }  // non-image
            [$cls, $ar] = ap_aspect($aid);
            $imgCount++;
            $events[] = ['t' => 'img', 'src' => $src, 'alt' => $alt, 'aid' => $aid, 'cls' => $cls, 'ar' => $ar];
        } else {
            $h = ap_prose($part);
            if ($h === '') continue;
            if ($tagline === '') {              // still seeking the lede — strip leading dateline/byline meta
                $h = ap_strip_lead_meta($h);
                if ($h === '') continue;        // run was pure metadata
                $tagline = mb_substr(preg_split('/(?<=[.!?])\s+/', trim(strip_tags($h)))[0] ?? '', 0, 180);
            }
            $events[] = ['t' => 'prose', 'html' => $h];
        }
    }

    // pass 2: a prose run that immediately PRECEDES an image becomes that image's image_text
    // (the figcaption, attached to the image). The image's alt is the lightbox label. Prose with
    // no following image (intro / transition / closing) stays a panel.
    $blocks = [];
    for ($k = 0, $ne = count($events); $k < $ne; $k++) {
        $e = $events[$k];
        if ($e['t'] === 'prose') {
            $plain = trim(html_entity_decode(wp_strip_all_tags(preg_replace('#</p>#i', "\n\n", $e['html'])), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            // attach to the next image ONLY if it's caption-length (short). Longer prose is body copy → panel.
            if (mb_strlen($plain) <= 400 && isset($events[$k + 1]) && $events[$k + 1]['t'] === 'img') {
                $events[$k + 1]['cap'] = preg_replace("/\n{3,}/", "\n\n", $plain);
                continue;
            }
            $blocks[] = ['type' => 'wysiwyg', 'style' => 'panel', 'html' => $e['html']];
        } elseif ($e['t'] === 'heading') {
            $blocks[] = ['type' => 'section-heading', 'level' => $e['level'], 'text' => $e['text']];
        } else {
            $cap = ($e['cap'] ?? '') !== '' ? $e['cap'] : $e['alt'];   // prose caption, else the short label
            $b = ['type' => 'image', 'id' => $rid(), 'image_id' => $e['aid'] ?: null, 'url' => $e['src'],
                  'alt' => $e['alt'], 'image_text' => $cap, 'variant' => 'variant-1', '_cls' => $e['cls']];
            if ($e['ar'] !== '') $b['aspect'] = $e['ar'];
            $blocks[] = $b;
        }
    }

    // pairing pass: adjacent portrait images (nothing between) -> 2-col columns
    $out = []; $i = 0; $n = count($blocks);
    while ($i < $n) {
        $b = $blocks[$i];
        if (($b['type'] ?? '') === 'image' && ($b['_cls'] ?? '') === 'pair'
            && isset($blocks[$i + 1]) && $blocks[$i + 1]['type'] === 'image' && ($blocks[$i + 1]['_cls'] ?? '') === 'pair') {
            $c1 = $b; $c2 = $blocks[$i + 1];
            unset($c1['_cls'], $c2['_cls']);   // no figure-number badges (they'd repeat 1,2,1,2 across pairs)
            $out[] = ['type' => 'columns', 'id' => $rid(), 'variant' => 'variant-1',
                      'columns' => [['blocks' => [$c1]], ['blocks' => [$c2]]]];
            $i += 2; continue;
        }
        unset($b['_cls']); $out[] = $b; $i++;
    }

    // number every image sequentially, top to bottom (reading order: left then right within a pair)
    $num = 0;
    foreach ($out as &$blk) {
        if (($blk['type'] ?? '') === 'image') { $blk['number'] = (string) (++$num); }
        elseif (($blk['type'] ?? '') === 'columns') {
            foreach ($blk['columns'] as &$col) {
                foreach ($col['blocks'] as &$cb) { if (($cb['type'] ?? '') === 'image') $cb['number'] = (string) (++$num); }
                unset($cb);
            }
            unset($col);
        }
    }
    unset($blk);

    if ($imgCount === 0 && !array_filter($out, fn($b) => ($b['type'] ?? '') === 'wysiwyg')) {
        return ['layout' => null, 'flag' => 'no content parsed', 'stats' => []];
    }

    $featured = (int) get_post_meta($postId, '_thumbnail_id', true);
    $tier = wp_get_post_terms($postId, 'tier', ['fields' => 'slugs']); $tier = is_wp_error($tier) ? [] : $tier;
    $blocks = array_merge(
        [['type' => 'post-header', 'title' => $title, 'tagline' => $tagline, 'featured_image_id' => $featured ?: null,
          'show_byline' => true, 'show_categories' => true, 'show_tags' => true]],
        $out,
        [['type' => 'post-footer', 'show_author' => true, 'show_related' => true]]
    );
    $layout = ['schema' => 1, '_meta' => ['importer' => 'article-parse/1', 'source_post' => $postId, 'imported_at' => gmdate('c')], 'blocks' => $blocks];

    $heads = count(array_filter($out, fn($b) => ($b['type'] ?? '') === 'section-heading'));
    $panels = count(array_filter($out, fn($b) => ($b['type'] ?? '') === 'wysiwyg'));
    $pairs = count(array_filter($out, fn($b) => ($b['type'] ?? '') === 'columns'));
    return ['layout' => $layout, 'flag' => null,
            'stats' => ['tier' => $tier[0] ?? 'public', 'images' => $imgCount, 'pairs' => $pairs, 'singles' => $imgCount - $pairs * 2,
                        'headings' => $heads, 'panels' => $panels, 'dropped_media' => $dropped]];
}

/* ---------------- runner ---------------- */
$one = getenv('LG_PARSE_POST');
if ($one) {
    $id = (int) $one;
    if (get_post_meta($id, '_lg_layout_v2', true)) { echo "post $id ALREADY has a layout — skip guard. (clear meta to redo)\n"; return; }
    $r = ap_parse($id);
    echo "\n===== ARTICLE $id : " . html_entity_decode(get_the_title($id), ENT_QUOTES | ENT_HTML5, 'UTF-8') . " =====\n";
    if ($r['flag']) { echo "  FLAGGED: {$r['flag']}\n"; return; }
    echo "  stats: " . json_encode($r['stats']) . "\n  tagline: " . mb_substr($r['layout']['blocks'][0]['tagline'], 0, 80) . "\n";
    foreach ($r['layout']['blocks'] as $b) {
        $t = $b['type']; $d = '';
        if ($t === 'section-heading') $d = $b['text'];
        elseif ($t === 'wysiwyg') $d = strlen($b['html']) . 'c' . (substr_count($b['html'], '<li') ? ' (' . substr_count($b['html'], '<li') . ' li)' : '');
        elseif ($t === 'image') $d = '[' . ($b['aspect'] ?? 'intrinsic') . '] #' . $b['image_id'] . ' ' . mb_substr($b['alt'], 0, 36);
        elseif ($t === 'columns') $d = 'PAIR ' . implode(' | ', array_map(fn($col) => '#' . $col['blocks'][0]['image_id'], $b['columns']));
        echo sprintf("    %-15s %s\n", $t, $d);
    }
    $out = '/tmp/lg-article-' . $id . '.json';
    file_put_contents($out, json_encode($r['layout'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    echo "  written: $out\n";
    return;
}
echo "Set LG_PARSE_POST=<id>.\n";
