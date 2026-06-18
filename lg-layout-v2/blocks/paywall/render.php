<?php
/**
 * blocks/paywall/render.php
 *
 * Member-side render. Emits a labeled divider line that tells the viewer
 * they're crossing into members-only territory.
 *
 * For NON-members, the Renderer top-level loop catches a paywall block
 * whose tier the viewer doesn't satisfy, emits the gate-CTA card in
 * place, and breaks the loop (cuts everything below). This render is
 * never reached in that case.
 *
 * @var array $args  Parsed + validated props
 * @var array $ctx   Render context
 */

use LG\LayoutV2\Renderer;

$tier  = is_string($args['tier']  ?? null) ? (string) $args['tier']  : 'looth-pro';
$label = is_string($args['label'] ?? null) ? trim((string) $args['label']) : '';

if ($label === '') $label = 'Members only below';

$editorMode = !empty($ctx['editor_mode']);
$labelEdit  = $editorMode ? ' data-lg-edit-prop="label"' : '';
$safeLabel  = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

$depth = (int) ($args['_depth'] ?? 1);
$ind   = Renderer::indent($depth);
$ind2  = $ind . '  ';
?>
<?= $ind ?><div class="lg-paywall" data-tier="<?= Renderer::attr($tier) ?>">
<?= $ind2 ?><span class="lg-paywall__rule"></span>
<?= $ind2 ?><span class="lg-paywall__label"<?= $labelEdit ?>><?= $safeLabel ?></span>
<?= $ind2 ?><span class="lg-paywall__rule"></span>
<?= $ind ?></div>
