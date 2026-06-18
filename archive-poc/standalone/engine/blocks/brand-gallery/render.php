<?php
/**
 * blocks/brand-gallery/render.php
 *
 * @var array $args  { heading?: string, _depth: int }
 * @var array $ctx   { sponsor, editor_mode, ... }
 *
 * Sponsor image gallery. Pulls pre-resolved URLs from $ctx['sponsor']
 * .gallery_urls (Lane-A stored resolved URLs, not attachment IDs), so no media
 * resolver is needed. Renders a lightbox carousel reusing the shared
 * [data-lg-carousel] + [data-lg-lightbox] front-end. Empty → render nothing.
 *
 * The <lg-edit> marker is emitted by the Renderer wrapper — do NOT emit it here.
 */

use LG\LayoutV2\Renderer;

$sponsor = is_array($ctx['sponsor'] ?? null) ? $ctx['sponsor'] : null;
$heading = trim((string) ($args['heading'] ?? '')) ?: 'Gallery';
$depth   = (int) ($args['_depth'] ?? 1);
$ind     = Renderer::indent($depth);
$editorMode  = !empty($ctx['editor_mode']);
$headingEdit = $editorMode ? ' data-lg-edit-prop="heading"' : '';

$urls = [];
if ($sponsor !== null && is_array($sponsor['gallery_urls'] ?? null)) {
    foreach ($sponsor['gallery_urls'] as $u) {
        $u = trim((string) $u);
        if ($u !== '') $urls[] = $u;
    }
}

if (!$urls) {
    if ($editorMode) echo $ind . '<!-- lg-brand-gallery: no gallery_urls on sponsor record -->';
    return;
}

ob_start();
?>
<?= $ind ?><section class="lg-brand-gallery">
<?= $ind ?>  <div class="lg-brand-gallery__head">
<?= $ind ?>    <h2 class="lg-brand-gallery__title"<?= $headingEdit ?>><?= Renderer::text($heading) ?></h2>
<?= $ind ?>  </div>
<?= $ind ?>  <div class="lg-brand-gallery__carousel" data-lg-carousel>
<?= $ind ?>    <button type="button" class="lg-brand-gallery__nav lg-brand-gallery__nav--prev" data-lg-carousel-prev aria-label="Previous">&lsaquo;</button>
<?= $ind ?>    <button type="button" class="lg-brand-gallery__nav lg-brand-gallery__nav--next" data-lg-carousel-next aria-label="Next">&rsaquo;</button>
<?= $ind ?>    <div class="lg-brand-gallery__track" data-lg-carousel-track>
<?php foreach ($urls as $u): ?>
<?= $ind ?>      <div class="lg-brand-gallery__tile">
<?= $ind ?>        <img src="<?= Renderer::attr($u) ?>" alt="" loading="lazy" data-lg-lightbox data-lg-caption="" />
<?= $ind ?>      </div>
<?php endforeach; ?>
<?= $ind ?>    </div>
<?= $ind ?>  </div>
<?= $ind ?></section>
<?php
echo ob_get_clean();
