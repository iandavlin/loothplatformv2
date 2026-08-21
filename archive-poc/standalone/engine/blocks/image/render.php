<?php
/**
 * blocks/image/render.php
 *
 * Emits the image block HTML.
 *
 * @var array $args  Parsed + validated props from the layout JSON:
 *                   { image_id: int, url?: string, alt?: string,
 *                     image_text?: string, _depth: int }
 * @var array $ctx   Render context: { viewer, editor_mode, manifests, media_resolver }
 *
 * Caption surface: `image_text` (per-instance) renders both as the
 * figcaption beneath the image AND as the lightbox caption (data-lg-caption
 * on the <img>, picked up by lg-front.js). The WP attachment's
 * post_excerpt is no longer used — per-instance prose is what readers see
 * in both surfaces, so they match.
 *
 * Legacy fallback: if `image_text` is empty but the (deprecated) `description`
 * prop is present on an older post, render that as the figcaption. Old
 * `caption` prop is ignored.
 *
 * Click-to-lightbox is wired by front-end JS (lg-front.js) against
 * [data-lg-lightbox] on the .lg-image__img element.
 *
 * The <lg-edit> marker is emitted by the Renderer wrapper — do NOT emit it here.
 */

use LG\LayoutV2\Renderer;

$imageId     = (int)    ($args['image_id']    ?? 0);
$url         = (string) ($args['url']         ?? '');
$alt         = (string) ($args['alt']         ?? '');
$imageText   = (string) ($args['image_text']  ?? $args['description'] ?? '');
$number      = trim((string) ($args['number'] ?? ''));
$variant     = is_string($args['variant'] ?? null) ? strtolower((string) $args['variant']) : 'variant-1';
if (!in_array($variant, ['variant-1', 'variant-2'], true)) $variant = 'variant-1';
$depth       = (int)    ($args['_depth']      ?? 1);

/* Resolve url from image_id if not explicit. The media resolver is injected
   via $ctx so the harness can supply a stub map while WP supplies a real one.
   $url is the FULL-size canonical (lightbox + fallback). $displayUrl is the
   smaller variant we'd rather hit in the inline <img> so pages load light;
   the lightbox swaps up to $url on click for detail-zoom. */
$displayUrl = '';
$srcset     = '';
if ($url === '' && $imageId > 0) {
    $media = ($ctx['media_resolver'])($imageId);
    $url   = $media['url'] ?? '';
    if ($alt === '') $alt = $media['alt'] ?? '';

    /* WP's generated variants were the right instinct and the wrong delivery:
       they are still RAW uploads, so they bypass the resizer, ship at whatever
       widths WordPress happened to generate, and carry no dimensions (#187).
       Img answers all three from the same metadata — the resizer's own buckets,
       capped so it never offers a candidate wider than the source. */
    $mediaSizes = is_array($media['sizes'] ?? null) ? $media['sizes'] : [];
    $displayUrl = \LG\LayoutV2\Img::src($url, 800, $mediaSizes);
    $srcset     = \LG\LayoutV2\Img::srcset($url, $mediaSizes, [400, 800, 1200, 1600]);
    $imgDims    = \LG\LayoutV2\Img::dims($url, $mediaSizes, 800);
}
/* An explicit `url` prop (no image_id) has no metadata behind it, but it is
   still one of our uploads and still worth resizing. */
if ($displayUrl === '') $displayUrl = \LG\LayoutV2\Img::src($url, 800);
if (!isset($imgDims)) $imgDims = \LG\LayoutV2\Img::dims($url, [], 800);

/* Lightbox caption: the per-instance image_text — same prose that lives
   in the figcaption underneath. Newlines collapsed to spaces so the
   single-line pill at the lightbox bottom reads cleanly even when the
   author entered multiple paragraphs. */
$lightboxCaption = $alt !== '' ? $alt : ($imageText !== '' ? trim(preg_replace('/\s+/', ' ', $imageText)) : '');

/* Split `image_text` on blank lines into paragraphs for the figcaption. */
$textParagraphs = [];
if ($imageText !== '') {
    foreach (preg_split('/\R{2,}/', trim($imageText)) as $para) {
        $para = trim($para);
        if ($para === '') continue;
        $textParagraphs[] = nl2br(Renderer::text($para));
    }
}

$editorMode    = !empty($ctx['editor_mode']);
$textEditAttr  = $editorMode ? ' data-lg-edit-prop="image_text"' : '';

/* Crop / focal-point: when `aspect` is set the frame locks to that ratio
   and the img fills via object-fit: cover with object-position driven by
   focal_x/y. The FE editor exposes a draggable focal dot inside the frame
   so authors can tweak the center without re-uploading. */
$aspect = is_string($args['aspect'] ?? null) ? trim((string) $args['aspect']) : '';
$focalX = max(0, min(100, (int) ($args['focal_x'] ?? 50)));
$focalY = max(0, min(100, (int) ($args['focal_y'] ?? 50)));
$zoom   = max(100, min(500, (int) ($args['zoom'] ?? 100)));
$frameStyle = $aspect !== '' ? sprintf('aspect-ratio: %s;', Renderer::attr($aspect)) : '';
$imgStyle   = '';
if ($aspect !== '') {
    $imgStyle = sprintf('object-fit: cover; object-position: %d%% %d%%;', $focalX, $focalY);
    if ($zoom !== 100) {
        /* transform-origin tied to focal so zoom pivots on the subject */
        $imgStyle .= sprintf(' transform: scale(%s); transform-origin: %d%% %d%%;',
            number_format($zoom / 100, 2), $focalX, $focalY);
    }
}

$ind = Renderer::indent($depth);

ob_start();
?>
<?php
/* Tack on a marker class when an aspect is set so the columns-context
   override in shell.css knows to step out of the way. */
$figureCls = 'lg-image lg-image--' . $variant . ($aspect !== '' ? ' lg-image--has-aspect' : '');
?>
<?= $ind ?><figure class="<?= $figureCls ?>"<?php if ($editorMode): ?> data-lg-aspect="<?= Renderer::attr($aspect) ?>" data-lg-focal-x="<?= (int) $focalX ?>" data-lg-focal-y="<?= (int) $focalY ?>" data-lg-zoom="<?= (int) $zoom ?>"<?php endif; ?>>
<?= $ind ?>  <div class="lg-image__image">
<?= $ind ?>    <div class="lg-image__frame"<?php if ($frameStyle !== ''): ?> style="<?= $frameStyle ?>"<?php endif; ?>>
<?= $ind ?>      <img class="lg-image__img"
<?php if ($imgStyle !== ''): ?>
<?= $ind ?>           style="<?= $imgStyle ?>"
<?php endif; ?>
<?= $ind ?>           src="<?= Renderer::attr($displayUrl) ?>"
<?php if ($srcset !== ''): ?>
<?= $ind ?>           srcset="<?= Renderer::attr($srcset) ?>"
<?= $ind ?>           sizes="(min-width: 960px) 760px, 100vw"
<?php endif; ?>
<?php if ($imgDims !== ''): ?>
<?= $ind ?>          <?= ltrim($imgDims) ?>
<?php endif; ?>
<?= $ind ?>           alt="<?= Renderer::attr($alt) ?>"
<?= $ind ?>           loading="lazy"
<?= $ind ?>           data-lg-lightbox
<?= $ind ?>           data-lg-fullsize-src="<?= Renderer::attr($url) ?>"
<?= $ind ?>           data-lg-caption="<?= Renderer::attr($lightboxCaption) ?>" />
<?php if ($number !== ''): ?>
<?= $ind ?>      <span class="lg-image__badge" aria-hidden="true"><?= Renderer::text($number) ?></span>
<?php endif; ?>
<?= $ind ?>    </div>
<?= $ind ?>  </div>
<?php if ($textParagraphs): ?>
<?= $ind ?>  <figcaption class="lg-image__caption lg-image__caption--long"<?= $textEditAttr ?>>
<?php foreach ($textParagraphs as $p): ?>
<?= $ind ?>    <p><?= $p ?></p>
<?php endforeach; ?>
<?= $ind ?>  </figcaption>
<?php endif; ?>
<?= $ind ?></figure>
<?php
echo ob_get_clean();
