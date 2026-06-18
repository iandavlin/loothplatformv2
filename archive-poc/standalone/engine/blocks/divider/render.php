<?php
/**
 * blocks/divider/render.php
 *
 * $args  — parsed props (validated against manifest schema)
 * $ctx   — render context (post, layout, viewer, editor_mode)
 *
 * Emits a real <hr> with the .lg-divider class + an optional --<variant>
 * modifier class. The Renderer doesn't apply variant classes uniformly
 * (gap in v2's current implementation; the manifest contract says it
 * should), so divider's render.php reads $args['variant'] itself for now.
 */

/** @var array $args */
/** @var array $ctx */

$variant = isset($args['variant']) && is_string($args['variant']) ? trim($args['variant']) : '';
$class   = $variant !== '' ? "lg-divider lg-divider--{$variant}" : 'lg-divider';
?>
<hr class="<?= $class ?>" />
