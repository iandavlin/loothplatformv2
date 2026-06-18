<?php
/**
 * Deterministic video-post -> lg-layout-v2 layout parser. NO AI.
 * Works purely off post_content: video URL, description prose, timestamp chapters,
 * and "Label:" link groups. Emits a layout array, or null + reason if it can't.
 *
 * Run (dry-run, prints, writes nothing):
 *   wp --path=/var/www/dev eval-file tools/video-parse.php
 */

function vp_clean_content(string $html): string {
    $html = preg_replace('/\[embed\](.*?)\[\/embed\]/is', '$1', $html);   // unwrap [embed]
    $html = preg_replace('#</(p|div|h[1-6]|li)>#i', "\n", $html);
    $html = preg_replace('#<br\s*/?>#i', "\n", $html);
    $html = wp_strip_all_tags($html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = str_replace("\xc2\xa0", ' ', $html);   // nbsp -> space so trim()/empty-line skip catches leading junk
    $html = preg_replace("/[ \t]+\n/", "\n", $html);
    return trim($html);
}

function vp_video_id(string $text): ?array {
    if (preg_match('#(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|live/|shorts/))([A-Za-z0-9_-]{6,})#i', $text, $m)) {
        return ['id' => $m[1], 'url' => 'https://youtu.be/' . $m[1]];
    }
    return null;
}

function vp_ts_seconds(string $ts): int {
    $p = array_map('intval', explode(':', $ts));
    if (count($p) === 3) return $p[0]*3600 + $p[1]*60 + $p[2];
    if (count($p) === 2) return $p[0]*60 + $p[1];
    return 0;
}

function vp_host(string $url): string {
    $h = parse_url($url, PHP_URL_HOST) ?: $url;
    return preg_replace('/^www\./', '', $h);
}
function vp_icon(string $url): string {
    $h = strtolower(vp_host($url));
    if (str_contains($h, 'instagram')) return 'instagram';
    if (str_contains($h, 'youtu'))     return 'youtube';
    if (str_contains($h, 'facebook'))  return 'facebook';
    return 'globe';
}

/** @return array{layout:?array, flag:?string, stats:array} */
function vp_parse(int $postId): array {
    $post = get_post($postId);
    $title = $post ? $post->post_title : '';
    $raw  = $post ? $post->post_content : '';
    $vid  = vp_video_id($raw . ' ' . (string) get_post_meta($postId, 'video_video_url', true));
    $text = vp_clean_content($raw);
    $lines = explode("\n", $text);

    if (!$vid) return ['layout'=>null, 'flag'=>'no video URL found', 'stats'=>[]];

    $descLines = []; $chapters = []; $groups = []; $curIdx = -1; $droppedProse = [];
    $sectionLabels = '/^(timestamps?|chapters?|featured guest|guests?|links?|resources?|in this episode|tools.*)\s*:?\s*$/i';
    // genuine trailing boilerplate to drop (vs. real description, which we now keep wherever it appears)
    $boilerplate = '/^(aired on\b|keywords?\s*:|tags?\s*:|#\S|subscribe\b|follow (us|along)\b|find us\b|produced by\b)/i';
    $seenStructured = false;

    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === '') { continue; }
        // the embed URL itself — already extracted; skip so it doesn't flip seenStructured and eat the description
        if (str_contains($ln, $vid['id'])) { continue; }

        // 1) timestamp chapter line:  HH:MM:SS Title  /  M:SS – Title
        if (preg_match('/^(\d{1,2}:\d{2}(?::\d{2})?)\s*[–\-—]?\s*(.+)$/u', $ln, $m) && !filter_var($ln, FILTER_VALIDATE_URL)) {
            $chapters[] = ['ts'=>$m[1], 'sec'=>vp_ts_seconds($m[1]), 'title'=>trim($m[2])];
            $seenStructured = true; $curIdx = -1; continue;
        }
        // 2) a known section header on its own line -> just a segmenter
        if (preg_match($sectionLabels, $ln)) { $seenStructured = true; $curIdx = -1; continue; }

        // 3) "Label: URL" on one line
        if (preg_match('/^(.{2,80}?):\s*(https?:\/\/\S+)$/', $ln, $m)) {
            $groups[] = ['title'=>trim($m[1]), 'urls'=>[$m[2]]]; $curIdx = count($groups)-1;
            $seenStructured = true; continue;
        }
        // 4) "Label:" alone -> open a group, URLs follow
        if (preg_match('/^([^:]{2,80}):$/', $ln) && !filter_var($ln, FILTER_VALIDATE_URL)) {
            $groups[] = ['title'=>trim(rtrim($ln, ':')), 'urls'=>[]]; $curIdx = count($groups)-1;
            $seenStructured = true; continue;
        }
        // 5) bare URL -> belongs to current group, else its own group
        if (preg_match('#^https?://\S+$#', $ln)) {
            if ($curIdx >= 0) { $groups[$curIdx]['urls'][] = $ln; }
            else { $groups[] = ['title'=>'Links', 'urls'=>[$ln]]; $curIdx = count($groups)-1; }
            $seenStructured = true; continue;
        }
        // 6) prose. Keep it as description wherever it appears (some posts list chapters BEFORE
        //    the description); drop only recognized boilerplate (aired-on / keywords / hashtags).
        if (preg_match($boilerplate, $ln)) { $droppedProse[] = $ln; }   // expected noise — silent
        else { $descLines[] = $ln; }
        $curIdx = -1;
    }

    // drop the video URL itself if it slipped into a group
    foreach ($groups as $i=>$g) {
        $g['urls'] = array_values(array_filter($g['urls'], fn($u)=> !str_contains($u, $vid['id'])));
        $groups[$i] = $g;
    }
    $groups = array_values(array_filter($groups, fn($g)=> !empty($g['urls'])));

    // ACF related-links repeater (post_related_links_repeater): description+url pairs many posts
    // store outside post_content. Built as proper items (label = the curated description).
    $seenUrls = [];
    foreach ($groups as $g) foreach ($g['urls'] as $u) $seenUrls[$u] = true;
    $acfItems = [];
    $relCount = (int) get_post_meta($postId, 'post_related_links_repeater', true);
    for ($i = 0; $i < $relCount; $i++) {
        $u = trim((string) get_post_meta($postId, "post_related_links_repeater_{$i}_post_related_link_url", true));
        if ($u === '' || isset($seenUrls[$u]) || str_contains($u, $vid['id'])) continue;
        $d = trim((string) get_post_meta($postId, "post_related_links_repeater_{$i}_post_related_link_description", true));
        $acfItems[] = ['icon'=>vp_icon($u), 'label'=>($d !== '' ? $d : vp_host($u)), 'url'=>$u, 'description'=>''];
        $seenUrls[$u] = true;
    }

    $desc = trim(implode("\n\n", $descLines));
    // URL-only post (bare embed, no prose/chapters/links/related): fall through to emit a minimal
    // embed-only layout (post-header + embed + post-footer), tier-gated like any other,
    // rather than bailing. The video URL was already found above (else $vid was null → flagged).
    $embedOnly = ($desc === '' && !$chapters && !$groups && !$acfItems);

    // ---- assemble layout ----
    // drop a leading event banner ("Looth Group Live — <date>") so the tagline starts at the real first sentence
    $taglineSrc = preg_replace('/^\s*Looth Group Live\b[^\n]*\R+/iu', '', $desc);
    $tagline = $taglineSrc !== '' ? mb_substr(preg_split('/(?<=[.!?])\s+/', $taglineSrc)[0] ?? $taglineSrc, 0, 180) : '';
    $blocks = [];
    $blocks[] = ['type'=>'post-header','tagline'=>$tagline,'show_read_time'=>true,'variant'=>'variant-1'];
    $blocks[] = ['type'=>'embed','url'=>$vid['url'],'caption'=>$title];
    if ($desc !== '') $blocks[] = ['type'=>'wysiwyg','html'=>'<p>'.implode('</p><p>', array_map('esc_html', explode("\n\n",$desc))).'</p>'];
    if ($chapters) {
        $blocks[] = ['type'=>'section-heading','level'=>'h3','text'=>'Chapters'];
        $body = '<p>'.implode('<br>', array_map(function($c) use ($vid){
            return '<a class="lg-ts-link" href="'.$vid['url'].'?t='.$c['sec'].'s"><strong>'.$c['ts'].'</strong> '.esc_html($c['title']).'</a>';
        }, $chapters)).'</p>';
        $blocks[] = ['type'=>'callout','variant'=>'note','title'=>'Chapters','body'=>$body];
    }
    foreach ($groups as $g) {
        $items = array_map(fn($u)=>['icon'=>vp_icon($u),'label'=>vp_host($u),'url'=>$u,'description'=>''], $g['urls']);
        $blocks[] = ['type'=>'callout','variant'=>'links','title'=>$g['title'],'items'=>$items];
    }
    if ($acfItems) $blocks[] = ['type'=>'callout','variant'=>'links','title'=>'Related Links','items'=>$acfItems];
    $blocks[] = ['type'=>'post-footer','show_author'=>true,'show_related'=>true,'show_comments'=>true,'show_share'=>true];

    $layout = [
        'schema' => 1,
        '_meta' => ['importer'=>'video-parse/1', 'source_post'=>$postId, 'imported_at'=>gmdate('c')],
        'blocks' => $blocks,
    ];
    // ---- soft review flags: heuristic judgement calls worth a human glance (not failures) ----
    $review = [];
    if (count($chapters) === 1)                       $review[] = 'lone chapter (possible false positive)';
    if (array_filter($chapters, fn($c)=>trim($c['title'])===''))  $review[] = 'chapter w/ empty title';
    if (preg_match($boilerplate, (string)$tagline))   $review[] = 'tagline looks like boilerplate';

    return ['layout'=>$layout, 'flag'=>null,
            'stats'=>['desc_chars'=>strlen($desc),'chapters'=>count($chapters),'link_groups'=>count($groups),'acf_links'=>count($acfItems),'embed_only'=>$embedOnly],
            'review'=>$review];
}

/* ---------------- runner ---------------- */
function vp_print_summary(int $id, array $r): void {
    echo "\n===== POST $id : ".get_the_title($id)." =====\n";
    if ($r['flag']) { echo "  FLAGGED: {$r['flag']}\n"; return; }
    echo "  stats: ".json_encode($r['stats'])."\n";
    if (!empty($r['review'])) echo "  ⚑ REVIEW: ".implode('; ', $r['review'])."\n";
    foreach ($r['layout']['blocks'] as $b) {
        $t=$b['type']; $d='';
        if ($t==='post-header') $d='tagline: '.mb_substr($b['tagline'],0,70);
        elseif ($t==='embed') $d=$b['url'];
        elseif ($t==='section-heading') $d=$b['text'];
        elseif ($t==='wysiwyg') $d=strlen($b['html']).' chars';
        elseif ($t==='callout' && ($b['variant']??'')==='note') $d='Chapters: '.substr_count($b['body'],'lg-ts-link').' entries';
        elseif ($t==='callout') $d="'".$b['title']."' -> ".implode(', ', array_map(fn($i)=>$i['label'],$b['items']));
        echo sprintf("    %-15s %s\n", $t, $d);
    }
}

// Single-post mode: LG_PARSE_POST=<id> wp eval-file ... → parse one, dry-run, write JSON to /tmp.
$one = getenv('LG_PARSE_POST');
if ($one) {
    $id = (int)$one;
    if (get_post_meta($id, '_lg_layout_v2', true)) {
        echo "post $id ALREADY has a _lg_layout_v2 layout — skip-if-exists guard. (clear its meta first if you mean to redo it)\n";
        return;
    }
    $r = vp_parse($id);
    vp_print_summary($id, $r);
    if (!$r['flag']) {
        $out = '/tmp/lg-parse-'.$id.'.json';
        file_put_contents($out, json_encode($r['layout'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        echo "\n  stamp: ".json_encode($r['layout']['_meta'])."\n";
        echo "  layout JSON written: $out\n";
    }
    return;
}

/* ---------------- dry-run over a sample ---------------- */
$sample = [70899, 70875, 70645, 70472];
foreach ($sample as $id) vp_print_summary($id, vp_parse($id));
