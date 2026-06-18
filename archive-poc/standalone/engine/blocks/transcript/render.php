<?php
/**
 * blocks/transcript/render.php
 *
 * Native <details>/<summary> accordion holding the transcript prose. The
 * content stays in the DOM at all times — only its `open` attribute toggles
 * — so search crawlers index the full transcript regardless of collapsed
 * state.
 *
 * @var array $args  Parsed + validated props
 * @var array $ctx   Render context
 */

use LG\LayoutV2\Renderer;

$label = is_string($args['label'] ?? null) ? trim((string) $args['label']) : '';
$body  = is_string($args['body']  ?? null) ? (string) $args['body']        : '';
$open  = !empty($args['open']);

if ($label === '') $label = 'Show transcript';

$editorMode = !empty($ctx['editor_mode']);
$labelEdit  = $editorMode ? ' data-lg-edit-prop="label"' : '';
$bodyEdit   = $editorMode ? ' data-lg-edit-prop="body"'  : '';

if (!$editorMode && trim(strip_tags($body)) === '') return;

$safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

$depth = (int) ($args['_depth'] ?? 1);
$ind   = Renderer::indent($depth);
$ind2  = $ind . '  ';
$openAttr = $open ? ' open' : '';
?>
<?= $ind ?><details class="lg-transcript"<?= $openAttr ?>>
<?= $ind2 ?><summary class="lg-transcript__label"><span<?= $labelEdit ?>><?= $safeLabel ?></span></summary>
<?= $ind2 ?><div class="lg-transcript__body"<?= $bodyEdit ?>><?= $body /* trusted: wp_kses_post on save */ ?></div>
<?= $ind ?></details>
