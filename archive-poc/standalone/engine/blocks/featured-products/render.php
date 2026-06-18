<?php
/**
 * blocks/featured-products/render.php
 *
 * @var array $args  { heading?: string, author?: int, items?: array, _depth: int }
 * @var array $ctx   { sponsor, viewer, editor_mode, ... }
 *
 * Sponsor-product carousel. Cards come from the baked `items` prop (resolved
 * from the sponsor's sponsor-product CPT at author/materialize time), NOT a
 * live query — keeps the standalone render WordPress-free. Each card: thumbnail
 * + title + optional price, linking to the product permalink.
 *
 * Reuses the shared [data-lg-carousel] markup (same prev/next + scroll-snap JS
 * as the gallery carousel and post-footer cards). Auto-themes via --brand-*.
 *
 * Empty items → render nothing (an empty carousel is worse than no section).
 * In editor mode, leave a breadcrumb so the author knows it's unfilled.
 *
 * The <lg-edit> marker is emitted by the Renderer wrapper — do NOT emit it here.
 */

use LG\LayoutV2\Renderer;

$heading = trim((string) ($args['heading'] ?? '')) ?: 'Featured Products';
$items   = is_array($args['items'] ?? null) ? $args['items'] : [];
$depth   = (int) ($args['_depth'] ?? 1);
$ind     = Renderer::indent($depth);
$editorMode  = !empty($ctx['editor_mode']);
$headingEdit = $editorMode ? ' data-lg-edit-prop="heading"' : '';

/* LIVE LOOP: when the host provides a sponsor_feed resolver (the standalone
   renderer queries the discovery index), loop over the sponsor's sponsor-product
   posts at render time — so a newly published product appears automatically, no
   re-materialize. The baked `items` are only a fallback for hosts without the
   resolver (e.g. the WP editor preview). */
$author = (int) ($args['author'] ?? 0);
if ($author <= 0 && is_array($ctx['sponsor'] ?? null)) $author = (int) ($ctx['sponsor']['wp_user_id'] ?? 0);
if ($author > 0 && isset($ctx['sponsor_feed']) && is_callable($ctx['sponsor_feed'])) {
    $live = ($ctx['sponsor_feed'])('sponsor-product', $author, 12);
    if (is_array($live)) $items = $live;   // authoritative live loop (empty = none → suppress)
}

/* Normalize cards — keep only those with at least a title or url. */
$cards = [];
foreach ($items as $it) {
    if (!is_array($it)) continue;
    $title = trim((string) ($it['title'] ?? ''));
    $url   = trim((string) ($it['url'] ?? ''));
    $img   = trim((string) ($it['image'] ?? ''));
    $price = trim((string) ($it['price'] ?? ''));
    $badge = trim((string) ($it['badge'] ?? ''));
    if ($title === '' && $url === '') continue;
    $cards[] = ['title' => $title, 'url' => $url, 'image' => $img, 'price' => $price, 'badge' => $badge];
}

if (!$cards) {
    if ($editorMode) {
        echo $ind . '<!-- lg-feat-products: no products baked (author = '
            . (int) ($args['author'] ?? 0) . ') -->';
    }
    return;
}

ob_start();
?>
<?= $ind ?><section class="lg-feat-products">
<?= $ind ?>  <div class="lg-feat-products__head">
<?= $ind ?>    <h2 class="lg-feat-products__title"<?= $headingEdit ?>><?= Renderer::text($heading) ?></h2>
<?= $ind ?>  </div>
<?= $ind ?>  <div class="lg-feat-products__carousel" data-lg-carousel>
<?= $ind ?>    <button type="button" class="lg-feat-products__nav lg-feat-products__nav--prev" data-lg-carousel-prev aria-label="Previous">&lsaquo;</button>
<?= $ind ?>    <button type="button" class="lg-feat-products__nav lg-feat-products__nav--next" data-lg-carousel-next aria-label="Next">&rsaquo;</button>
<?= $ind ?>    <div class="lg-feat-products__track" data-lg-carousel-track>
<?php foreach ($cards as $c): ?>
<?php $tag = $c['url'] !== '' ? 'a' : 'div'; ?>
<?= $ind ?>      <<?= $tag ?> class="lg-feat-products__card"<?= $c['url'] !== '' ? ' href="' . Renderer::attr($c['url']) . '"' : '' ?>>
<?php if ($c['image'] !== ''): ?>
<?= $ind ?>        <div class="lg-feat-products__thumb">
<?= $ind ?>          <img src="<?= Renderer::attr($c['image']) ?>" alt="<?= Renderer::attr($c['title']) ?>" loading="lazy" />
<?php if ($c['badge'] !== ''): ?>
<?= $ind ?>          <span class="lg-feat-products__badge"><?= Renderer::text($c['badge']) ?></span>
<?php endif; ?>
<?= $ind ?>        </div>
<?php endif; ?>
<?= $ind ?>        <div class="lg-feat-products__body">
<?php if ($c['title'] !== ''): ?>
<?= $ind ?>          <h3 class="lg-feat-products__name"><?= Renderer::text($c['title']) ?></h3>
<?php endif; ?>
<?php if ($c['price'] !== ''): ?>
<?= $ind ?>          <span class="lg-feat-products__price"><?= Renderer::text($c['price']) ?></span>
<?php endif; ?>
<?= $ind ?>        </div>
<?= $ind ?>      </<?= $tag ?>>
<?php endforeach; ?>
<?= $ind ?>    </div>
<?= $ind ?>  </div>
<?= $ind ?></section>
<?php
echo ob_get_clean();
