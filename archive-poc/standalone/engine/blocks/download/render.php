<?php
/**
 * blocks/download/render.php
 *
 * A single downloadable file rendered as a download button. The loothprint /
 * loothcut conversion emits bare `{ "type": "download", "file_id": N }` blocks;
 * this renderer resolves that file_id through the injected media resolver
 * (`$ctx['media_resolver']`) — the same map the image/gallery blocks use — so
 * the URL, filename, mime and human size are all pre-baked at materialize time.
 * No `wp_get_attachment_url` at render: the standalone path has no WP, and the
 * resolver returns the right shape on both paths.
 *
 * Visually this reuses the callout `files` treatment (.lg-callout--files), so a
 * converted download looks identical to the native loothprint download the
 * builder synthesizes as a callout. A `.lg-download` host class is added for
 * future targeting.
 *
 * Gating: `download` is in Renderer::AUTO_GATE_TYPES, so a tiered post auto-gates
 * this block to its post_tier — the gate-CTA substitution happens in the Renderer
 * wrapper before we get here. Nothing to gate-check in this template.
 *
 * The <lg-edit> marker is emitted by the Renderer wrapper — do NOT emit it here.
 *
 * @var array $args  Parsed props: { file_id?: int, url?: string, label?: string,
 *                   title?: string, _depth: int }
 * @var array $ctx   Render context: { media_resolver, editor_mode, ... }
 */

use LG\LayoutV2\Renderer;
use LG\LayoutV2\Icons;

$fileId = (int)    ($args['file_id'] ?? 0);
$url    = is_string($args['url']   ?? null) ? trim((string) $args['url'])   : '';
$label  = is_string($args['label'] ?? null) ? trim((string) $args['label']) : '';
$title  = array_key_exists('title', $args) ? trim((string) $args['title']) : 'Download';
$depth  = (int) ($args['_depth'] ?? 1);

$editorMode = !empty($ctx['editor_mode']);

/* Resolve the file through the media map (url + metadata pre-baked at
   materialize). Explicit `url` on the block wins (off-site files). */
$filename = '';
$sizeHuman = '';
$mime = '';
if ($url === '' && $fileId > 0 && isset($ctx['media_resolver'])) {
    $media     = ($ctx['media_resolver'])($fileId);
    $url       = (string) ($media['url'] ?? '');
    $filename  = (string) ($media['filename'] ?? '');
    $sizeHuman = (string) ($media['filesize_human'] ?? '');
    $mime      = (string) ($media['mime'] ?? '');
    if ($label === '') {
        $label = (string) ($media['title'] ?? '');
        if ($label === '') $label = $filename;
    }
}
if ($filename === '' && $url !== '') $filename = basename(parse_url($url, PHP_URL_PATH) ?: $url);
if ($label === '') $label = $filename !== '' ? $filename : 'Download File';

/* No destination → nothing to render (a download with no file is dead).
   Editor mode keeps a click target so the block can be re-pointed. */
if ($url === '' && !$editorMode) return;

/* Extension badge from the filename (or mime fallback), and a matching icon. */
$ext = '';
if ($filename !== '' && ($dot = strrpos($filename, '.')) !== false) {
    $ext = strtoupper(substr($filename, $dot + 1));
}
$iconKey = 'file';
$extLower = strtolower($ext);
if ($extLower === 'zip')               $iconKey = 'file-zip';
elseif ($extLower === 'pdf')           $iconKey = 'file-pdf';
elseif ($extLower === 'dxf')           $iconKey = 'file-dxf';
elseif (str_contains($mime, 'zip'))    { $iconKey = 'file-zip'; if ($ext === '') $ext = 'ZIP'; }

$safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
$labelHtml = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
$extHtml   = htmlspecialchars($ext,   ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
$sizeHtml  = htmlspecialchars($sizeHuman, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

$hrefAttr = $url !== '' ? ' href="' . Renderer::attr($url) . '"' : '';
$tag      = $url !== '' ? 'a' : 'span';
/* `download` attribute prompts a save rather than in-tab navigation. Same-origin
   only — cross-origin download hints are ignored by browsers, harmless to keep. */
$dlAttr   = $url !== '' ? ' download rel="noopener"' : '';

$ind  = Renderer::indent($depth);
$ind2 = $ind . '  ';
$ind3 = $ind2 . '  ';
?>
<?= $ind ?><aside class="lg-callout lg-callout--files lg-download">
<?php if ($title !== ''): ?>
<?= $ind2 ?><div class="lg-callout__title"><?= $safeTitle ?></div>
<?php endif; ?>
<?= $ind2 ?><ul class="lg-callout__items">
<?= $ind3 ?><li>
<?= $ind3 ?>  <<?= $tag ?> class="lg-callout__row"<?= $hrefAttr ?><?= $dlAttr ?>>
<?= $ind3 ?>    <span class="lg-callout__icon"><?= Icons::svg($iconKey) ?></span>
<?= $ind3 ?>    <span class="lg-callout__text">
<?= $ind3 ?>      <span class="lg-callout__label"><?= $labelHtml ?></span>
<?= $ind3 ?>    </span>
<?php if ($ext !== '' || $sizeHuman !== ''): ?>
<?= $ind3 ?>    <span class="lg-callout__filemeta">
<?php if ($ext !== ''): ?><span class="lg-callout__ext"><?= $extHtml ?></span><?php endif; ?>
<?php if ($sizeHuman !== ''): ?><span class="lg-callout__size"><?= $sizeHtml ?></span><?php endif; ?>
<?= $ind3 ?>    </span>
<?php endif; ?>
<?= $ind3 ?>  </<?= $tag ?>>
<?= $ind3 ?></li>
<?= $ind2 ?></ul>
<?= $ind ?></aside>
