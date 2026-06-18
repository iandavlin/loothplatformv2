<?php
/**
 * blocks/callout/render.php
 *
 * Structured callout. One block, six role-named variants. List variants
 * (links, files, people, data) render items[] as a <ul>; prose variants
 * (note, quote) render body as paragraphs.
 *
 * Item shape: { icon, label, url, description?, ext?, size?, image_url?, initials? }
 *
 * @var array $args  Parsed + validated props
 * @var array $ctx   Render context
 */

use LG\LayoutV2\Renderer;
use LG\LayoutV2\Icons;

$title       = is_string($args['title'] ?? null)       ? trim((string) $args['title'])       : '';
$variant     = is_string($args['variant'] ?? null)     ? strtolower((string) $args['variant']) : 'links';
$body        = is_string($args['body'] ?? null)        ? (string) $args['body']               : '';
$attribution = is_string($args['attribution'] ?? null) ? trim((string) $args['attribution'])  : '';
$items       = is_array($args['items'] ?? null)        ? array_values($args['items'])         : [];

$known = ['links', 'files', 'people', 'data', 'note', 'quote'];
if (!in_array($variant, $known, true)) $variant = 'links';

$isList  = in_array($variant, ['links', 'files', 'people', 'data'], true);
$isProse = in_array($variant, ['note', 'quote'], true);

$editorMode = !empty($ctx['editor_mode']);

/* Empty-block elision: skip render entirely when there's nothing to show.
   In editor mode we still emit the shell so authors have a click target. */
if (!$editorMode) {
    if ($isList && empty($items) && $title === '') return;
    if ($isProse && trim(strip_tags($body)) === '' && $attribution === '') return;
}

$titleEdit = $editorMode ? ' data-lg-edit-prop="title"' : '';
$bodyEdit  = $editorMode ? ' data-lg-edit-prop="body"'  : '';
/* attribution text gets its own wrapper so the em-dash prefix can sit
   outside the contenteditable region (otherwise saves would include the
   leading "— ") */
$attrEdit  = $editorMode ? ' data-lg-edit-prop="attribution"' : '';

$safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
$safeAttr  = htmlspecialchars($attribution, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

$depth = (int) ($args['_depth'] ?? 1);
$ind   = Renderer::indent($depth);
$ind2  = $ind . '  ';
$ind3  = $ind2 . '  ';

$chevSvg = Icons::svg('chevron');
?>
<?= $ind ?><aside class="lg-callout lg-callout--<?= $variant ?>">
<?php if ($editorMode): ?>
<?= $ind2 ?><script type="application/json" data-lg-callout-state><?php
    /* Editor-mode-only: ship the structured state into the DOM so the FE
       editor modal can seed its rows without a separate REST round-trip.
       Wrapped in JSON_HEX_TAG to avoid early script termination via </. */
    echo json_encode(
        ['variant' => $variant, 'items' => $items],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
    );
?></script>
<?php endif; ?>
<?php if ($title !== '' || $editorMode): ?>
<?= $ind2 ?><div class="lg-callout__title"<?= $titleEdit ?>><?= $safeTitle ?></div>
<?php endif; ?>

<?php if ($isList): ?>
<?php
/* Filter out rows with neither label nor URL. Editor mode keeps empty rows
   so authors can fill them in. */
$rows = $editorMode
    ? $items
    : array_values(array_filter($items, function ($r) {
        if (!is_array($r)) return false;
        $hasUrl   = isset($r['url'])   && trim((string) $r['url'])   !== '';
        $hasLabel = isset($r['label']) && trim((string) $r['label']) !== '';
        return $hasUrl || $hasLabel;
    }));
?>
<?php if ($rows || $editorMode): ?>
<?= $ind2 ?><ul class="lg-callout__items">
<?php foreach ($rows as $row):
    $iconKey = is_string($row['icon'] ?? null) ? (string) $row['icon'] : 'link';
    $label   = is_string($row['label'] ?? null) ? trim((string) $row['label']) : '';
    $url     = is_string($row['url'] ?? null) ? trim((string) $row['url']) : '';
    $desc    = is_string($row['description'] ?? null) ? trim((string) $row['description']) : '';
    $ext     = is_string($row['ext'] ?? null) ? trim((string) $row['ext']) : '';
    $size    = is_string($row['size'] ?? null) ? trim((string) $row['size']) : '';
    $img     = is_string($row['image_url'] ?? null) ? trim((string) $row['image_url']) : '';
    $inits   = is_string($row['initials'] ?? null) ? trim((string) $row['initials']) : '';

    $labelHtml = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    $descHtml  = htmlspecialchars($desc,  ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    $extHtml   = htmlspecialchars($ext,   ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    $sizeHtml  = htmlspecialchars($size,  ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    $initHtml  = htmlspecialchars($inits, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

    /* Anchor href: empty url → render as <span> instead so it's still a
       valid row. Items without a destination are useful for "see this guest
       below" rows in people variant. */
    $tag      = $url !== '' ? 'a' : 'span';
    $hrefAttr = $url !== '' ? ' href="' . Renderer::attr($url) . '"' : '';
    $relAttr  = ($url !== '' && !str_starts_with($url, 'mailto:') && !str_starts_with($url, 'tel:'))
        ? ' rel="noopener" target="_blank"'
        : '';
?>
<?= $ind3 ?><li>
<?= $ind3 ?>  <<?= $tag ?> class="lg-callout__row"<?= $hrefAttr ?><?= $relAttr ?>>
<?php if ($variant === 'people'): ?>
<?= $ind3 ?>    <span class="lg-callout__icon">
<?php if ($img !== ''): ?>
<?= $ind3 ?>      <img src="<?= Renderer::attr($img) ?>" alt="<?= $labelHtml ?>" />
<?php elseif ($inits !== ''): ?>
<?= $ind3 ?>      <span class="lg-callout__initials"><?= $initHtml ?></span>
<?php else: ?>
<?= $ind3 ?>      <?= Icons::svg('avatar') ?>
<?php endif; ?>
<?= $ind3 ?>    </span>
<?php else: ?>
<?= $ind3 ?>    <span class="lg-callout__icon"><?= Icons::svg($iconKey) ?></span>
<?php endif; ?>
<?= $ind3 ?>    <span class="lg-callout__text">
<?= $ind3 ?>      <span class="lg-callout__label"><?= $labelHtml ?></span>
<?php if ($desc !== ''): ?>
<?= $ind3 ?>      <span class="lg-callout__meta"><?= $descHtml ?></span>
<?php endif; ?>
<?= $ind3 ?>    </span>
<?php if ($variant === 'files' && ($ext !== '' || $size !== '')): ?>
<?= $ind3 ?>    <span class="lg-callout__filemeta">
<?php if ($ext !== ''): ?><span class="lg-callout__ext"><?= $extHtml ?></span><?php endif; ?>
<?php if ($size !== ''): ?><span class="lg-callout__size"><?= $sizeHtml ?></span><?php endif; ?>
<?= $ind3 ?>    </span>
<?php else: ?>
<?= $ind3 ?>    <span class="lg-callout__chev"><?= $chevSvg ?></span>
<?php endif; ?>
<?= $ind3 ?>  </<?= $tag ?>>
<?= $ind3 ?></li>
<?php endforeach; ?>
<?= $ind2 ?></ul>
<?php endif; ?>
<?php endif; ?>

<?php if ($variant === 'note'): ?>
<?= $ind2 ?><div class="lg-callout__body"<?= $bodyEdit ?>><?= $body /* trusted: wp_kses_post on save */ ?></div>
<?php endif; ?>

<?php if ($variant === 'quote'): ?>
<?= $ind2 ?><div class="lg-callout__quote"<?= $bodyEdit ?>><?= $body /* trusted: wp_kses_post on save */ ?></div>
<?php if ($attribution !== '' || $editorMode): ?>
<?= $ind2 ?><div class="lg-callout__attr"><span class="lg-callout__attr-dash" aria-hidden="true">&mdash;&nbsp;</span><span<?= $attrEdit ?>><?= $safeAttr ?></span></div>
<?php endif; ?>
<?php endif; ?>

<?= $ind ?></aside>
