<?php
/**
 * blocks/embed/render.php
 *
 * $args  — parsed props (validated against manifest schema)
 * $ctx   — render context (post, layout, viewer, editor_mode)
 *
 * Emits a <figure class="lg-embed"> with an aspect-ratio reservation box.
 * Inside the box: either the oEmbed-resolved iframe (when WP can resolve
 * the URL) or a fallback link (when it can't, or in the CLI harness).
 *
 * The aspect-ratio inline style is the ONLY inline style v2 accepts —
 * it's content-derived (validated enum), not author-typed CSS. The CSS
 * cascade architecture stays clean.
 */

/** @var array $args */
/** @var array $ctx */

$url     = is_string($args['url']     ?? null) ? trim((string) $args['url'])     : '';
$ratio   = is_string($args['ratio']   ?? null) ? trim((string) $args['ratio'])   : '16x9';
$caption = is_string($args['caption'] ?? null) ? (string) $args['caption']       : '';
$variant = is_string($args['variant'] ?? null) ? strtolower((string) $args['variant']) : 'variant-1';
if (!in_array($variant, ['variant-1', 'variant-2'], true)) $variant = 'variant-1';

if ($url === '') return;   /* no URL → no embed */

$safeCaption = htmlspecialchars($caption, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

/* Instagram fast path. WP's oEmbed has been broken for Instagram since
   Meta retired api.instagram.com/oembed/ in 2020; resolution now requires
   a Facebook app token most sites don't have. The fix: bypass WP's
   provider machinery entirely and emit the same blockquote markup Meta
   returns from graph.facebook.com/instagram_oembed. Their embeds.js
   replaces it with a responsive iframe client-side — no token needed.
   We also skip the aspect-ratio reservation box: IG's iframe sets its
   own height, and a fixed aspect-ratio would crop or squash it. */
if (preg_match('~^https?://(?:www\.)?instagram\.com/(?:p|reel|reels|tv)/([A-Za-z0-9_-]+)~i', $url, $m)) {
    /* Canonical permalink — strip query string, normalize /reels/ → /reel/
       (embed.js expects the singular form), force trailing slash. */
    $permalink = 'https://www.instagram.com/reel/' . $m[1] . '/';
    /* Preserve /p/ and /tv/ as-is. */
    if (stripos($url, '/p/')  !== false) $permalink = 'https://www.instagram.com/p/'  . $m[1] . '/';
    if (stripos($url, '/tv/') !== false) $permalink = 'https://www.instagram.com/tv/' . $m[1] . '/';
    $safePermalink = htmlspecialchars($permalink, ENT_QUOTES, 'UTF-8');
    unset($m);

    /* Load embeds.js once per page no matter how many IG embeds appear. */
    $loadScript = empty($GLOBALS['lg_v2_ig_embeds_js_loaded']);
    $GLOBALS['lg_v2_ig_embeds_js_loaded'] = true;
    ?>
    <figure class="lg-embed lg-embed--instagram lg-embed--<?= $variant ?>"><blockquote class="instagram-media" data-instgrm-permalink="<?= $safePermalink ?>" data-instgrm-version="14"></blockquote><?php if ($loadScript): ?><script async src="https://www.instagram.com/embed.js"></script><?php endif; ?><?php if ($caption !== ''): ?><figcaption class="lg-embed__caption"><?= $safeCaption ?></figcaption><?php endif; ?></figure>
    <?php
    return;
}

/* YouTube ratio is unambiguously derivable from the URL — shorts are
   always 9:16, watch/embed/youtu.be links are always 16:9. The author's
   `ratio` prop is overridden because URL shape is the truth (an author
   who pastes a shorts URL and leaves the picker at 16x9 just hasn't
   updated it). TikTok would slot in here the same way (always 9x16). */
/* YouTube ID + optional start-time extraction. Shorts → 9x16, otherwise
   16x9. The author's `ratio` prop is overridden because URL shape is the
   ground truth. */
$ytId = '';
$ytStart = 0;
$isShorts = false;
if (preg_match('~youtube\.com/shorts/([A-Za-z0-9_-]{6,})~i', $url, $m)) {
    $ytId = $m[1]; $isShorts = true; $ratio = '9x16';
} elseif (preg_match('~(?:youtube\.com/(?:watch\?v=|embed/|v/)|youtu\.be/)([A-Za-z0-9_-]{6,})~i', $url, $m)) {
    $ytId = $m[1]; $ratio = '16x9';
}
if ($ytId !== '' && preg_match('~[?&](?:t|start)=(\d+)~', $url, $m)) {
    $ytStart = (int) $m[1];
} elseif ($ytId !== '' && preg_match('~[?&]t=(\d+)m(\d+)s~i', $url, $m)) {
    $ytStart = ((int) $m[1]) * 60 + (int) $m[2];
}

/* Vimeo ID — same facade pattern as YouTube. Thumbnail fetched once
   server-side via Vimeo's public oembed endpoint, cached 12h. */
$vimeoId = '';
if (preg_match('~vimeo\.com/(?:video/|channels/[^/]+/|groups/[^/]+/videos/)?(\d+)~i', $url, $m)) {
    $vimeoId = $m[1];
    $ratio = '16x9';
}

/* Whitelist ratio against the manifest enum so a malformed value can't
   inject into the inline style. */
$ratioAllowed = ['16x9', '4x3', '1x1', '9x16', '21x9'];
if (!in_array($ratio, $ratioAllowed, true)) $ratio = '16x9';
[$w, $h] = explode('x', $ratio);
$aspectCss = "{$w}/{$h}";

/* Shared play-button SVG (YouTube's red bezel + white triangle, recolored
   in CSS per platform). */
$playSvg = '<svg viewBox="0 0 68 48" aria-hidden="true"><path class="lg-embed__play-bg" d="M66.52 7.74c-.78-2.93-2.49-5.41-5.42-6.19C55.79.13 34 0 34 0S12.21.13 6.9 1.55c-2.93.78-4.63 3.26-5.42 6.19C.06 13.05 0 24 0 24s.06 10.95 1.48 16.26c.78 2.93 2.49 5.41 5.42 6.19C12.21 47.87 34 48 34 48s21.79-.13 27.1-1.55c2.93-.78 4.64-3.26 5.42-6.19C67.94 34.95 68 24 68 24s-.06-10.95-1.48-16.26z"/><path d="M45 24 27 14v20" fill="#fff"/></svg>';

/* ── YouTube facade ──────────────────────────────────────────────
   Static thumbnail + play button. No YouTube JS loads until click.
   lg-front.js wires the click → swaps in a real youtube-nocookie.com
   iframe with autoplay=1. */
if ($ytId !== '') {
    $safeYtId = htmlspecialchars($ytId, ENT_QUOTES, 'UTF-8');
    /* maxresdefault.jpg isn't guaranteed for every video; hqdefault.jpg
       always exists. Srcset lets the browser try the higher-res first. */
    $thumbHi = "https://i.ytimg.com/vi/{$safeYtId}/maxresdefault.jpg";
    $thumbLo = "https://i.ytimg.com/vi/{$safeYtId}/hqdefault.jpg";
    ?>
<figure class="lg-embed lg-embed--<?= $variant ?> lg-embed--youtube<?= $isShorts ? ' lg-embed--shorts' : '' ?>">
  <div class="lg-embed__frame lg-embed__facade" style="aspect-ratio: <?= $aspectCss ?>;" data-yt-id="<?= $safeYtId ?>" data-yt-start="<?= (int) $ytStart ?>">
    <img class="lg-embed__poster" src="<?= htmlspecialchars($thumbLo, ENT_QUOTES, 'UTF-8') ?>" srcset="<?= htmlspecialchars($thumbHi, ENT_QUOTES, 'UTF-8') ?> 1280w, <?= htmlspecialchars($thumbLo, ENT_QUOTES, 'UTF-8') ?> 480w" sizes="100vw" loading="lazy" alt="" />
    <button type="button" class="lg-embed__play" aria-label="Play video"><?= $playSvg ?></button>
  </div>
<?php if ($caption !== ''): ?>
  <figcaption class="lg-embed__caption"><?= $safeCaption ?></figcaption>
<?php endif; ?>
</figure>
    <?php
    return;
}

/* ── Vimeo facade ────────────────────────────────────────────────
   Thumbnail URL fetched once via Vimeo oembed, cached 12h. */
if ($vimeoId !== '') {
    $safeVimeoId = htmlspecialchars($vimeoId, ENT_QUOTES, 'UTF-8');
    $thumb = '';
    if (function_exists('get_transient')) {
        $tk = 'lg_v2_vimeo_thumb_' . $vimeoId;
        $cached = get_transient($tk);
        if (is_string($cached) && $cached !== '') {
            $thumb = $cached;
        } elseif (function_exists('wp_remote_get')) {
            $resp = wp_remote_get('https://vimeo.com/api/oembed.json?url=' . rawurlencode($url), ['timeout' => 4]);
            if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
                $data = json_decode((string) wp_remote_retrieve_body($resp), true);
                if (is_array($data) && !empty($data['thumbnail_url'])) {
                    $thumb = (string) $data['thumbnail_url'];
                    set_transient($tk, $thumb, 12 * HOUR_IN_SECONDS);
                }
            }
        }
    }
    ?>
<figure class="lg-embed lg-embed--<?= $variant ?> lg-embed--vimeo">
  <div class="lg-embed__frame lg-embed__facade" style="aspect-ratio: <?= $aspectCss ?>;" data-vimeo-id="<?= $safeVimeoId ?>">
<?php if ($thumb !== ''): ?>
    <img class="lg-embed__poster" src="<?= htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8') ?>" loading="lazy" alt="" />
<?php endif; ?>
    <button type="button" class="lg-embed__play" aria-label="Play video"><?= $playSvg ?></button>
  </div>
<?php if ($caption !== ''): ?>
  <figcaption class="lg-embed__caption"><?= $safeCaption ?></figcaption>
<?php endif; ?>
</figure>
    <?php
    return;
}

/* ── Generic oEmbed fallback ─────────────────────────────────────
   Everything else (SoundCloud, Spotify, TikTok, etc.) goes through WP's
   provider list. Iframes load up-front — convert per-provider to a
   facade if any of these becomes a frequent embed target. */
$frameInner = '';
if (function_exists('wp_oembed_get')) {
    $oembedHtml = wp_oembed_get($url, ['discover' => false]);
    if (is_string($oembedHtml) && $oembedHtml !== '') {
        $frameInner = $oembedHtml;
    }
}
if ($frameInner === '') {
    $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    $frameInner = sprintf(
        '<a class="lg-embed__fallback" href="%s" rel="noopener noreferrer" target="_blank">View embed: %s</a>',
        $safeUrl, $safeUrl
    );
}
?>
<figure class="lg-embed lg-embed--<?= $variant ?>">
  <div class="lg-embed__frame" style="aspect-ratio: <?= $aspectCss ?>;">
<?= $frameInner ?>
  </div>
<?php if ($caption !== ''): ?>
  <figcaption class="lg-embed__caption"><?= $safeCaption ?></figcaption>
<?php endif; ?>
</figure>
