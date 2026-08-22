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
$postId = (int) ($ctx['post_id'] ?? 0);

$editorMode = !empty($ctx['editor_mode']);

/* ── No file pinned? FOLLOW THE POST. ───────────────────────────────────────
   Ported from lg-layout-v2/blocks/download/render.php, where it has lived since
   the download-block work. This is the VENDORED COPY of that block, and it is
   the one that serves /loothprint/ — so the guarantee only existed on the render
   path members do not use. That divergence is the same class of thing that let
   #187's image work land in one tree and not the other.

   A stored layout freezes whatever file_id it was built with; replacing a print
   file through the form creates a NEW attachment, the form says saved, and the
   page keeps offering the old one. Reading the post's own meta instead makes the
   drift structurally impossible rather than merely absent today.

   Off-WP this resolves through wp-shim.php's get_post_meta, which serves the
   materialized blob — so BOTH halves must be in place: the meta key baked into
   post_context.meta AND the attachment present in the media map. Both are done
   in archive-poc/bin/materializer.php; see the ⚠️ beside $ids there.

   Guarded with function_exists() so a host without either shim degrades to
   "no file" instead of fataling. */
if ($url === '' && $fileId <= 0 && $postId > 0
    && function_exists('get_post_meta') && function_exists('get_post_type')) {
    $metaKey = match (get_post_type($postId)) {
        'loothprint' => 'loothprint_3d_file',
        'loothcuts'  => 'loothcut_cnc_file',
        default      => '',
    };
    if ($metaKey !== '') $fileId = (int) get_post_meta($postId, $metaKey, true);
}

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
