<?php
/**
 * blocks/recent-posts/render.php
 *
 * @var array $args  { heading?: string, author?: int, items?: array, _depth: int }
 * @var array $ctx   { sponsor, viewer, editor_mode, ... }
 *
 * Sponsor-post carousel. Cards come from the baked `items` prop (resolved from
 * the sponsor's sponsor-post CPT at author/materialize time), NOT a live query.
 * Each card: thumbnail + title + date + excerpt, linking to the post permalink.
 *
 * Reuses the shared [data-lg-carousel] markup. Auto-themes via --brand-*.
 * Empty items → render nothing.
 *
 * The <lg-edit> marker is emitted by the Renderer wrapper — do NOT emit it here.
 */

use LG\LayoutV2\Renderer;

$heading = trim((string) ($args['heading'] ?? '')) ?: 'Recent Posts';
$items   = is_array($args['items'] ?? null) ? $args['items'] : [];
$depth   = (int) ($args['_depth'] ?? 1);
$ind     = Renderer::indent($depth);
$editorMode  = !empty($ctx['editor_mode']);
$headingEdit = $editorMode ? ' data-lg-edit-prop="heading"' : '';

/* LIVE LOOP: when the host provides a sponsor_feed resolver (the standalone
   renderer queries the discovery index), loop over the sponsor's sponsor-post
   items at render time — a newly published post appears automatically with no
   re-materialize. Baked `items` are the fallback for resolver-less hosts. */
$author = (int) ($args['author'] ?? 0);
if ($author <= 0 && is_array($ctx['sponsor'] ?? null)) $author = (int) ($ctx['sponsor']['wp_user_id'] ?? 0);
if ($author > 0 && isset($ctx['sponsor_feed']) && is_callable($ctx['sponsor_feed'])) {
    $live = ($ctx['sponsor_feed'])('sponsor-post', $author, 6);
    if (is_array($live)) $items = $live;
}

$cards = [];
foreach ($items as $it) {
    if (!is_array($it)) continue;
    $title = trim((string) ($it['title'] ?? ''));
    $url   = trim((string) ($it['url'] ?? ''));
    $img   = trim((string) ($it['image'] ?? ''));
    $date  = trim((string) ($it['date'] ?? ''));
    $exc   = trim((string) ($it['excerpt'] ?? ''));
    if ($title === '' && $url === '') continue;
    $cards[] = ['title' => $title, 'url' => $url, 'image' => $img, 'date' => $date, 'excerpt' => $exc];
}

if (!$cards) {
    if ($editorMode) {
        echo $ind . '<!-- lg-recent-posts: no posts baked (author = '
            . (int) ($args['author'] ?? 0) . ') -->';
    }
    return;
}

ob_start();
?>
<?= $ind ?><section class="lg-recent-posts">
<?= $ind ?>  <div class="lg-recent-posts__head">
<?= $ind ?>    <h2 class="lg-recent-posts__title"<?= $headingEdit ?>><?= Renderer::text($heading) ?></h2>
<?= $ind ?>  </div>
<?= $ind ?>  <div class="lg-recent-posts__carousel" data-lg-carousel>
<?= $ind ?>    <button type="button" class="lg-recent-posts__nav lg-recent-posts__nav--prev" data-lg-carousel-prev aria-label="Previous">&lsaquo;</button>
<?= $ind ?>    <button type="button" class="lg-recent-posts__nav lg-recent-posts__nav--next" data-lg-carousel-next aria-label="Next">&rsaquo;</button>
<?= $ind ?>    <div class="lg-recent-posts__track" data-lg-carousel-track>
<?php foreach ($cards as $c): ?>
<?php $tag = $c['url'] !== '' ? 'a' : 'div'; ?>
<?= $ind ?>      <<?= $tag ?> class="lg-recent-posts__card"<?= $c['url'] !== '' ? ' href="' . Renderer::attr($c['url']) . '"' : '' ?>>
<?php if ($c['image'] !== ''): ?>
<?= $ind ?>        <div class="lg-recent-posts__thumb">
<?= $ind ?>          <img src="<?= Renderer::attr($c['image']) ?>" alt="<?= Renderer::attr($c['title']) ?>" loading="lazy" />
<?= $ind ?>        </div>
<?php endif; ?>
<?= $ind ?>        <div class="lg-recent-posts__body">
<?php if ($c['date'] !== ''): ?>
<?= $ind ?>          <span class="lg-recent-posts__date"><?= Renderer::text($c['date']) ?></span>
<?php endif; ?>
<?php if ($c['title'] !== ''): ?>
<?= $ind ?>          <h3 class="lg-recent-posts__name"><?= Renderer::text($c['title']) ?></h3>
<?php endif; ?>
<?php if ($c['excerpt'] !== ''): ?>
<?= $ind ?>          <p class="lg-recent-posts__excerpt"><?= Renderer::text($c['excerpt']) ?></p>
<?php endif; ?>
<?= $ind ?>        </div>
<?= $ind ?>      </<?= $tag ?>>
<?php endforeach; ?>
<?= $ind ?>    </div>
<?= $ind ?>  </div>
<?= $ind ?></section>
<?php
echo ob_get_clean();
