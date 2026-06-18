<?php
/**
 * blocks/section-heading/render.php
 *
 * Boxed section heading. Same level-driven semantic tag as the heading
 * block, wrapped in a chrome container. Variant axis drives the box's
 * color palette (default cream/sage vs inverted ink/amber).
 *
 * @var array $args  Parsed + validated props from the layout JSON
 * @var array $ctx   Render context (post, layout, viewer, editor_mode)
 */

$text    = is_string($args['text']    ?? null) ? trim((string) $args['text']) : '';
$level   = is_string($args['level']   ?? null) ? strtolower((string) $args['level'])   : 'h2';
$variant = is_string($args['variant'] ?? null) ? strtolower((string) $args['variant']) : 'variant-1';
if (!in_array($level,   ['h2', 'h3', 'h4'],         true)) $level   = 'h2';
if (!in_array($variant, ['variant-1', 'variant-2', 'variant-3'], true)) $variant = 'variant-1';

if ($text === '') return;   /* don't emit empty boxes */

$safeText  = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
$editProp  = !empty($ctx['editor_mode']) ? ' data-lg-edit-prop="text"' : '';
$classList = "lg-section-heading lg-section-heading--$level lg-section-heading--$variant";
?>
<<?= $level ?> class="<?= $classList ?>"<?= $editProp ?>><?= $safeText ?></<?= $level ?>>
