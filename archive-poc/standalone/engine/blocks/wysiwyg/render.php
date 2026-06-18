<?php
/**
 * blocks/wysiwyg/render.php
 *
 * $args  — parsed props (validated against manifest schema)
 * $ctx   — render context (post, layout, viewer, editor_mode)
 *
 * Emits a single .lg-wysiwyg container with the author's rich-text HTML inside.
 * The HTML was already sanitized at save time via wp_kses_post in the metabox
 * handler; we trust it here without re-sanitizing (would double-escape entities).
 *
 * If the html prop is empty the block still renders an empty container so
 * the editor mode (Phase 4) has a click target.
 */

/** @var array $args */
/** @var array $ctx */

$html  = is_string($args['html']  ?? null) ? $args['html'] : '';
$style = is_string($args['style'] ?? null) ? strtolower((string) $args['style']) : 'plain';
if (!in_array($style, ['plain', 'panel'], true)) $style = 'plain';
?>
<?php $editProp = !empty($ctx['editor_mode']) ? ' data-lg-edit-prop="html"' : ''; ?>
<div class="lg-wysiwyg lg-wysiwyg--<?= $style ?>"<?= $editProp ?>><?php echo $html; ?></div>
