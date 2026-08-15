<?php
/**
 * blocks/taxonomy/render.php
 *
 * The categories a post is filed under — Loothprint Type and Content Topic —
 * as chips linking to their term archives.
 *
 * This is the one block in the 8/14 charter that was genuinely missing rather
 * than mis-measured: nothing in layout-v2 rendered these taxonomies at all.
 * Checked, not assumed — post-header reads only `tier`, post-footer only
 * `category`, and `loothprint_type` (18 terms) / `shared_category` (36 terms)
 * appeared on no page despite the form collecting them.
 *
 * Terms are read LIVE from the post, like the licence and download blocks, so a
 * page cannot drift from the form. There is deliberately NO term picker: Ian's
 * 8/14 ruling is that the form owns the details and v2 owns the page, and a
 * second editor for one value is how the two end up disagreeing.
 *
 * The <lg-edit> marker is emitted by the Renderer wrapper — do NOT emit it here.
 *
 * @var array $args  Parsed props: { taxonomies?: array, title?: string,
 *                   link?: bool, variant?: string, _depth: int }
 * @var array $ctx   Render context: { post_id, editor_mode, ... }
 */

use LG\LayoutV2\Renderer;

$variant = is_string($args['variant'] ?? null) ? strtolower((string) $args['variant']) : 'chips';
if (!in_array($variant, ['chips', 'inline'], true)) $variant = 'chips';

$title  = array_key_exists('title', $args) ? trim((string) $args['title']) : '';
$link   = !array_key_exists('link', $args) || !empty($args['link']);
$depth  = (int) ($args['_depth'] ?? 1);
$postId = (int) ($ctx['post_id'] ?? 0);

$editorMode = !empty($ctx['editor_mode']);

$taxonomies = $args['taxonomies'] ?? ['loothprint_type', 'shared_category'];
if (!is_array($taxonomies)) $taxonomies = [];

/* ── Collect the post's terms, live ─────────────────────────────────────────
   Guarded with function_exists() so the standalone/no-WP render path degrades
   to "no terms" instead of fataling — same guard style as post-header.

   Terms from different taxonomies are collected into ONE run on purpose: a
   reader does not care which taxonomy a label came from, only what the thing
   is. Which taxonomy it was stays available on each chip's title attribute. */
$rows = [];
if ($postId > 0 && function_exists('get_the_terms')) {
    foreach ($taxonomies as $tax) {
        $tax = (string) $tax;
        if ($tax === '') continue;
        if (function_exists('taxonomy_exists') && !taxonomy_exists($tax)) continue;

        $terms = get_the_terms($postId, $tax);
        if (!is_array($terms)) continue;   /* WP_Error or false — no terms, skip silently */

        $taxLabel = $tax;
        if (function_exists('get_taxonomy')) {
            $obj = get_taxonomy($tax);
            if ($obj && !empty($obj->labels->singular_name)) $taxLabel = (string) $obj->labels->singular_name;
        }

        foreach ($terms as $t) {
            if (!isset($t->name)) continue;
            $url = '';
            if ($link && function_exists('get_term_link')) {
                $maybe = get_term_link($t);
                if (is_string($maybe)) $url = $maybe;   /* WP_Error → unlinked chip, not a dead link */
            }
            $rows[] = ['name' => (string) $t->name, 'url' => $url, 'tax' => $taxLabel];
        }
    }
}

/* Nothing filed → nothing to render. An empty chip row is furniture with no
   content, which reads as a broken block rather than as "uncategorised".
   Editor mode keeps a target so the block can be seen and removed. */
if (!$rows && !$editorMode) return;

$ind  = Renderer::indent($depth);
$ind2 = $ind . '  ';
$ind3 = $ind2 . '  ';

$classes = 'lg-taxonomy lg-taxonomy--' . $variant;
$label   = $title !== '' ? $title : 'Filed under';
?>
<?= $ind ?><nav class="<?= Renderer::attr($classes) ?>" aria-label="<?= Renderer::attr($label) ?>">
<?php if ($title !== ''): ?>
<?= $ind2 ?><div class="lg-taxonomy__title"><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') ?></div>
<?php endif; ?>
<?php if (!$rows): ?>
<?= $ind2 ?><p class="lg-taxonomy__empty">Not filed under anything yet.</p>
<?php else: ?>
<?= $ind2 ?><ul class="lg-taxonomy__list">
<?php foreach ($rows as $r):
    $nameHtml = htmlspecialchars($r['name'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    /* The taxonomy is named in the title attribute so the grouping survives
       without relying on visual adjacency. */
    $titleAttr = Renderer::attr($r['tax'] . ': ' . $r['name']);
    $isLink    = $r['url'] !== '';
    $tag       = $isLink ? 'a' : 'span';
    $hrefAttr  = $isLink ? ' href="' . Renderer::attr($r['url']) . '"' : '';
?>
<?= $ind3 ?><li class="lg-taxonomy__item"><<?= $tag ?> class="lg-taxonomy__chip"<?= $hrefAttr ?> title="<?= $titleAttr ?>"><?= $nameHtml ?></<?= $tag ?>></li>
<?php endforeach; ?>
<?= $ind2 ?></ul>
<?php endif; ?>
<?= $ind ?></nav>
