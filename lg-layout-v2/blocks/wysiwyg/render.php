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

// Loothalong links carried inside body content (e.g. Sheet-bridge event copy)
// must open in a NEW tab so the reader's current loothgroup.com tab is never
// navigated away to the Zoom gate (Ian 2026-07-12). We rewrite at RENDER time so
// existing rows are covered without an import pass, and only touch anchors that
// don't already declare their own target. The stripos guard makes this a no-op
// for the overwhelming majority of content (the block otherwise renders as-is).
if ($html !== '' && stripos($html, 'loothalong.php') !== false) {
    $html = preg_replace_callback('#<a\b([^>]*)>#i', static function (array $m): string {
        $attrs = $m[1];
        // Only loothalong.php anchors, and only when no target is set already.
        if (!preg_match('#href\s*=\s*("[^"]*loothalong\.php[^"]*"|\'[^\']*loothalong\.php[^\']*\'|[^\s"\'>]*loothalong\.php[^\s>]*)#i', $attrs)) {
            return $m[0];
        }
        if (preg_match('#\btarget\s*=#i', $attrs)) {
            return $m[0];
        }
        $rel = preg_match('#\brel\s*=#i', $attrs) ? '' : ' rel="noopener"';
        return '<a' . $attrs . ' target="_blank"' . $rel . '>';
    }, $html) ?? $html;
}
?>
<?php $editProp = !empty($ctx['editor_mode']) ? ' data-lg-edit-prop="html"' : ''; ?>
<div class="lg-wysiwyg lg-wysiwyg--<?= $style ?>"<?= $editProp ?>><?php echo $html; ?></div>
