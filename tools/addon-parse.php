<?php
/**
 * Deterministic parser for the small "addon" CPTs -> lg-layout-v2. NO AI.
 *   useful_links   : a bookmark — useful_url + useful_link_description (+ optional gallery). PUBLIC.
 *                    -> post-header + callout(links) + footer.
 *   member-benefit : a members offer — hero + intro/details + a code/link (+ optional gallery).
 *                    -> post-header + intro + PAYWALL + details + callout(links) + footer (looth tier).
 *
 * Run (single):  LG_PARSE_POST=<id> wp eval-file tools/addon-parse.php
 * Run (batch):   LG_PARSE_CPT=useful_links|member-benefit wp eval-file tools/addon-parse.php
 * Writes /tmp/lg-addon-<id>.json.
 */

function ad_rid(): string { return 'b_' . substr(md5(uniqid('', true)), 0, 6); }

function ad_aspect(int $aid): array {
    $m = $aid ? wp_get_attachment_metadata($aid) : null;
    $w = $m['width'] ?? 0; $h = $m['height'] ?? 0;
    if (!$w || !$h) return ['single', ''];
    $ar = $w / $h;
    if ($ar < 0.9)  return ['pair', '2/3'];
    if ($ar <= 1.4) return ['single', '1/1'];
    return ['single', ''];
}

function ad_prose(string $html): string {
    $html = preg_replace('#</?div[^>]*>#i', "\n\n", $html);
    $html = str_replace("\xc2\xa0", ' ', $html);
    $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
    $html = trim($html);
    if (trim(strip_tags($html)) === '') return '';
    return wp_kses_post(make_clickable(wpautop($html)));
}

/** image blocks from a serialized/array gallery, with portrait pairing. */
function ad_gallery_blocks($gal): array {
    $gal = is_array($gal) ? $gal : (is_string($gal) && $gal !== '' ? @unserialize($gal) : []);
    $imgs = [];
    foreach ((array) $gal as $gid) {
        $gid = (int) $gid;
        if (!$gid || strpos((string) get_post_mime_type($gid), 'image/') !== 0) continue;
        [$cls, $ar] = ad_aspect($gid);
        $b = ['type' => 'image', 'id' => ad_rid(), 'image_id' => $gid,
              'alt' => get_post_meta($gid, '_wp_attachment_image_alt', true) ?: '', 'image_text' => '', 'variant' => 'variant-1', '_cls' => $cls];
        if ($ar !== '') $b['aspect'] = $ar;
        $imgs[] = $b;
    }
    $out = []; $i = 0; $n = count($imgs);
    while ($i < $n) {
        $b = $imgs[$i];
        if (($b['_cls'] ?? '') === 'pair' && isset($imgs[$i + 1]) && ($imgs[$i + 1]['_cls'] ?? '') === 'pair') {
            $c1 = $b; $c2 = $imgs[$i + 1]; unset($c1['_cls'], $c2['_cls']);
            $out[] = ['type' => 'columns', 'id' => ad_rid(), 'variant' => 'variant-1', 'columns' => [['blocks' => [$c1]], ['blocks' => [$c2]]]];
            $i += 2; continue;
        }
        unset($b['_cls']); $out[] = $b; $i++;
    }
    return $out;
}

function ad_icon_for(string $url): string {
    $h = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
    foreach (['youtube' => 'youtube', 'youtu.be' => 'youtube', 'instagram' => 'instagram', 'facebook' => 'facebook',
              'github' => 'github', 'linktr' => 'linktree'] as $needle => $icon) {
        if (strpos($h, $needle) !== false) return $icon;
    }
    return 'globe';
}

function ad_header(int $id, string $tagline, ?int $featured): array {
    return ['type' => 'post-header', 'title' => html_entity_decode(get_the_title($id), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'tagline' => $tagline, 'featured_image_id' => $featured ?: null,
            'show_byline' => true, 'show_categories' => true, 'show_tags' => true];
}

function ad_tier(int $id): string {
    $t = wp_get_post_terms($id, 'tier', ['fields' => 'slugs']); $t = is_wp_error($t) ? [] : $t;
    return $t[0] ?? 'public';
}

/** @return array{layout:?array, flag:?string, stats:array} */
function ad_parse(int $id): array {
    $pt = get_post_type($id);
    $tier = ad_tier($id);
    $featured = (int) get_post_meta($id, '_thumbnail_id', true);

    if ($pt === 'useful_links') {
        $url = trim((string) get_post_meta($id, 'useful_url', true));
        $desc = trim((string) get_post_meta($id, 'useful_link_description', true)) ?: html_entity_decode(get_the_title($id), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === '') return ['layout' => null, 'flag' => 'no useful_url', 'stats' => []];
        $body = [['type' => 'callout', 'id' => ad_rid(), 'variant' => 'links', 'title' => 'Resource',
                  'items' => [['icon' => ad_icon_for($url), 'label' => $desc, 'url' => $url, 'description' => '']]]];
        if (get_post_meta($id, 'post_addon_gallery_radio', true) === 'Yes')
            $body = array_merge($body, ad_gallery_blocks(get_post_meta($id, 'post_addon_gallery', true)));
        $blocks = array_merge([ad_header($id, $desc, $featured ?: null)], $body, [['type' => 'post-footer', 'show_author' => false, 'show_related' => true]]);
        $stats = ['cpt' => $pt, 'tier' => $tier, 'kind' => 'link', 'gated' => false];
    } elseif ($pt === 'member-benefit') {
        $intro = ad_prose((string) get_post_meta($id, 'member_benefits_introduction', true));
        $details = ad_prose((string) get_post_meta($id, 'member_benefits_full_details', true));
        $linkTitle = trim((string) get_post_meta($id, 'member_benefits_link_title', true));
        $link = trim((string) get_post_meta($id, 'member_benefits_link', true));
        $code = trim((string) get_post_meta($id, 'member_benefits_instructions_or_code', true));
        if ($linkTitle === '' && $link === '' && $details === '') return ['layout' => null, 'flag' => 'no offer fields', 'stats' => []];

        $teaser = []; $gated = [];
        if ($intro !== '') $teaser[] = ['type' => 'wysiwyg', 'style' => 'panel', 'html' => $intro];
        if ($details !== '') $gated[] = ['type' => 'wysiwyg', 'style' => 'panel', 'html' => $details];
        // the offer card (label = the deal, often holding the code) -> gated, members only
        $items = [['icon' => $link ? ad_icon_for($link) : 'link', 'label' => $linkTitle ?: 'Visit', 'url' => $link, 'description' => $code]];
        $gated[] = ['type' => 'callout', 'id' => ad_rid(), 'variant' => 'links', 'title' => 'Member Offer', 'items' => $items];
        if (get_post_meta($id, 'post_addon_gallery_radio', true) === 'Yes')
            $gated = array_merge($gated, ad_gallery_blocks(get_post_meta($id, 'post_addon_gallery', true)));

        $body = $teaser;
        if ($tier !== 'public') { $body[] = ['type' => 'paywall', 'tier' => $tier, 'label' => 'This member offer is for members']; }
        $body = array_merge($body, $gated);
        $tagline = $intro !== '' ? mb_substr(trim(strip_tags($intro)), 0, 160) : ($linkTitle ?: '');
        $blocks = array_merge([ad_header($id, $tagline, $featured ?: null)], $body, [['type' => 'post-footer', 'show_author' => false, 'show_related' => true]]);
        $stats = ['cpt' => $pt, 'tier' => $tier, 'kind' => 'offer', 'gated' => ($tier !== 'public')];
    } else {
        return ['layout' => null, 'flag' => "unsupported cpt $pt", 'stats' => []];
    }

    return ['layout' => ['schema' => 1, '_meta' => ['importer' => 'addon-parse/1', 'source_post' => $id, 'imported_at' => gmdate('c')], 'blocks' => $blocks],
            'flag' => null, 'stats' => $stats];
}

/* ---------------- runner ---------------- */
function ad_emit(int $id, bool $verbose): ?array {
    if (get_post_meta($id, '_lg_layout_v2', true)) { echo "  $id ALREADY has a layout — skip.\n"; return null; }
    $r = ad_parse($id);
    if ($r['flag']) { echo "  $id FLAGGED: {$r['flag']}\n"; return null; }
    $file = '/tmp/lg-addon-' . $id . '.json';
    file_put_contents($file, json_encode($r['layout'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    echo "  $id  " . json_encode($r['stats']) . "  -> $file\n";
    if ($verbose) foreach ($r['layout']['blocks'] as $b) {
        $d = $b['type'] === 'callout' ? ($b['title'] . ': ' . ($b['items'][0]['label'] ?? '') . ' -> ' . ($b['items'][0]['url'] ?? ''))
           : ($b['type'] === 'wysiwyg' ? strlen($b['html']) . 'c' : ($b['type'] === 'paywall' ? '>>> ' . $b['label'] : ($b['type'] === 'image' ? '#' . $b['image_id'] : '')));
        echo sprintf("    %-14s %s\n", $b['type'], $d);
    }
    return $r['stats'];
}

if ($cpt = getenv('LG_PARSE_CPT')) {
    $q = new WP_Query(['post_type' => $cpt, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids',
                       'meta_query' => [['key' => '_lg_layout_v2', 'compare' => 'NOT EXISTS']]]);
    $ok = 0; $flag = 0;
    foreach ($q->posts as $id) { ad_emit($id, false) ? $ok++ : $flag++; }
    echo "\n$cpt: $ok written, $flag flagged.\n";
    return;
}
if ($one = getenv('LG_PARSE_POST')) { ad_emit((int) $one, true); return; }
echo "Set LG_PARSE_POST=<id> or LG_PARSE_CPT=useful_links|member-benefit.\n";
