<?php
/**
 * blocks/gallery/render.php
 *
 * @var array $args  { image_ids: int[], columns?: int, layout?: string,
 *                    image_text?: string, variant?: string, _depth: int }
 * @var array $ctx   { viewer, editor_mode, manifests, media_resolver }
 *
 * Renders an N-column tile grid. Each tile is a lightbox-tagged <img> with
 * its data-lg-caption pulled from the WP attachment's own post_excerpt — so
 * the per-image caption travels with the file (same model as the image block).
 *
 * The optional `image_text` prop is rendered as a figcaption below the grid,
 * for article-side commentary on the gallery as a whole. Per-image article
 * text isn't supported here intentionally — if you need that, use individual
 * image blocks (likely inside a columns row).
 *
 * The <lg-edit> marker is emitted by the Renderer wrapper — do NOT emit it here.
 */

use LG\LayoutV2\Renderer;

$imageIds  = is_array($args['image_ids'] ?? null) ? $args['image_ids'] : [];
$imageIds  = array_values(array_filter(array_map('intval', $imageIds), fn($n) => $n > 0));
$columns   = (int) ($args['columns'] ?? 3);
if (!in_array($columns, [2, 3, 4], true)) $columns = 3;
$layout    = is_string($args['layout'] ?? null) ? strtolower((string) $args['layout']) : 'grid';
if (!in_array($layout, ['grid', 'masonry', 'carousel'], true)) $layout = 'grid';
$imageText = (string) ($args['image_text'] ?? '');
$variant   = is_string($args['variant'] ?? null) ? strtolower((string) $args['variant']) : 'variant-1';
if (!in_array($variant, ['variant-1', 'variant-2'], true)) $variant = 'variant-1';
$depth     = (int) ($args['_depth'] ?? 1);

/* Resolve each attachment ID through the media resolver. The harness ships a
   stub map; in WP, the resolver wraps wp_get_attachment_image_url + alt. The
   lightbox caption is the attachment's own post_excerpt, fetched directly
   since the resolver doesn't surface it. */
$tiles = [];
foreach ($imageIds as $id) {
    $media = ($ctx['media_resolver'])($id);
    $url   = (string) ($media['url'] ?? '');
    if ($url === '') {
        /* Missing/invalid attachment → placeholder tile so the grid keeps its
           shape and the author can see at a glance which slots need fixing. */
        $tiles[] = ['placeholder' => true];
        continue;
    }
    $alt = (string) ($media['alt'] ?? '');
    $cap = '';
    if (function_exists('wp_get_attachment_caption')) {
        $cap = (string) wp_get_attachment_caption($id);
    }
    $tiles[] = ['url' => $url, 'alt' => $alt, 'cap' => $cap];
}

/* Three-tile minimum: pad with placeholders if the author hasn't filled the
   block yet. Three reads as "intentional gallery"; two would just look like
   two image blocks. The placeholders are obvious affordances for "add a
   photo here" in edit mode. */
while (count($tiles) < 3) {
    $tiles[] = ['placeholder' => true];
}

/* Split image_text on blank lines into paragraphs. */
$textParagraphs = [];
if ($imageText !== '') {
    foreach (preg_split('/\R{2,}/', trim($imageText)) as $para) {
        $para = trim($para);
        if ($para === '') continue;
        $textParagraphs[] = nl2br(Renderer::text($para));
    }
}

$editorMode   = !empty($ctx['editor_mode']);
$textEditAttr = $editorMode ? ' data-lg-edit-prop="image_text"' : '';

$ind = Renderer::indent($depth);

ob_start();
?>
<?= $ind ?><figure class="lg-gallery lg-gallery--<?= $variant ?> lg-gallery--<?= $layout ?> lg-gallery--cols-<?= $columns ?>">
<?php if ($layout === 'carousel'): ?>
<?= $ind ?>  <div class="lg-gallery__carousel-wrap" data-lg-carousel>
<?= $ind ?>    <button type="button" class="lg-gallery__nav lg-gallery__nav--prev" data-lg-carousel-prev aria-label="Previous">&lsaquo;</button>
<?= $ind ?>    <button type="button" class="lg-gallery__nav lg-gallery__nav--next" data-lg-carousel-next aria-label="Next">&rsaquo;</button>
<?php endif; ?>
<?= $ind ?>  <div class="lg-gallery__grid"<?= $layout === 'carousel' ? ' data-lg-carousel-track' : '' ?>>
<?php foreach ($tiles as $i => $t): ?>
<?php if (!empty($t['placeholder'])): ?>
<?= $ind ?>    <div class="lg-gallery__tile lg-gallery__tile--placeholder" aria-hidden="true">
<?= $ind ?>      <svg class="lg-gallery__placeholder-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
<?= $ind ?>        <rect x="3" y="5" width="18" height="14" rx="2"/>
<?= $ind ?>        <circle cx="9" cy="11" r="1.5"/>
<?= $ind ?>        <path d="M21 17l-5-5-4 4-3-3-6 6"/>
<?= $ind ?>      </svg>
<?= $ind ?>    </div>
<?php else: ?>
<?= $ind ?>    <div class="lg-gallery__tile">
<?= $ind ?>      <img class="lg-gallery__img"
<?= $ind ?>           src="<?= Renderer::attr($t['url']) ?>"
<?= $ind ?>           alt="<?= Renderer::attr($t['alt']) ?>"
<?= $ind ?>           loading="lazy"
<?= $ind ?>           data-lg-lightbox
<?= $ind ?>           data-lg-caption="<?= Renderer::attr($t['cap']) ?>" />
<?= $ind ?>    </div>
<?php endif; ?>
<?php endforeach; ?>
<?= $ind ?>  </div>
<?php if ($layout === 'carousel'): ?>
<?= $ind ?>  </div>
<?php endif; ?>
<?php if ($textParagraphs): ?>
<?= $ind ?>  <figcaption class="lg-gallery__caption"<?= $textEditAttr ?>>
<?php foreach ($textParagraphs as $p): ?>
<?= $ind ?>    <p><?= $p ?></p>
<?php endforeach; ?>
<?= $ind ?>  </figcaption>
<?php endif; ?>
<?= $ind ?></figure>
<?php
echo ob_get_clean();
