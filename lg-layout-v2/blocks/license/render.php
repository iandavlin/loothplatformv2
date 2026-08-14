<?php
/**
 * blocks/license/render.php
 *
 * The Creative Commons licence a post carries, drawn as a choice: the licence
 * name, a link to its canonical deed, and one chip per clause it imposes.
 *
 * ── The live-vs-baked decision, which is the point of this block ────────────
 * With `code` empty (the default, and what the loothprint synthesizer emits)
 * the licence is resolved AT RENDER from the post's own
 * `loothprint_creative_commons` meta — the same live-read pattern post-header
 * uses for title/hero/author. So a member who changes their licence in the form
 * changes the page, with no re-synthesis and no sync job.
 *
 * A non-empty `code` is a deliberate override: it pins the block to one licence
 * and stops it tracking the post.
 *
 * WP reads are guarded with function_exists() so the standalone/no-WP render
 * path degrades to "no licence" instead of fataling — same guard style as
 * post-header.
 *
 * The <lg-edit> marker is emitted by the Renderer wrapper — do NOT emit it here.
 *
 * @var array $args  Parsed props: { code?: string, title?: string,
 *                   show_deed?: bool, variant?: string, _depth: int }
 * @var array $ctx   Render context: { post_id, editor_mode, ... }
 */

use LG\LayoutV2\Renderer;
use LG\LayoutV2\Icons;
use LG\LayoutV2\Licenses;

$variant = is_string($args['variant'] ?? null) ? strtolower((string) $args['variant']) : 'note';
if (!in_array($variant, ['note', 'compact'], true)) $variant = 'note';

$title    = array_key_exists('title', $args) ? trim((string) $args['title']) : 'License';
$showDeed = !array_key_exists('show_deed', $args) || !empty($args['show_deed']);
$depth    = (int) ($args['_depth'] ?? 1);
$postId   = (int) ($ctx['post_id'] ?? 0);

$editorMode = !empty($ctx['editor_mode']);

/* ── Resolve the licence ────────────────────────────────────────────────── */
$code = is_string($args['code'] ?? null) ? strtolower(trim((string) $args['code'])) : '';
if ($code !== '' && !Licenses::is_valid($code)) $code = '';   /* unknown code → fall through to the post */

/* The STORED code, kept separate from the resolved one. The editor needs both:
   the picker highlights what is stored ('' = "follow the post"), while the page
   draws what that resolved to. Collapsing them would make "follow the post"
   look like a specific licence, so choosing it again would read as a change. */
$storedCode  = $code;
$followsPost = ($code === '');
if ($followsPost && $postId > 0 && function_exists('get_post_meta')) {
    $code = Licenses::from_meta((string) get_post_meta($postId, 'loothprint_creative_commons', true));
}

/* No licence anywhere. A post whose author never chose one must NOT be given a
   default here — that would put terms on their work that they never agreed to.
   Editor mode still needs a click target so the block can be pointed at one. */
if ($code === '' && !$editorMode) return;

$ind  = Renderer::indent($depth);
$ind2 = $ind . '  ';
$ind3 = $ind2 . '  ';

$classes = 'lg-license lg-license--' . $variant;

if ($code === '') {
    /* Editor-only empty state. */
    ?>
<?= $ind ?><aside class="<?= Renderer::attr($classes) ?>" data-lg-license-code="" data-lg-license-resolved="">
<?php if ($title !== ''): ?>
<?= $ind2 ?><div class="lg-license__title"><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') ?></div>
<?php endif; ?>
<?= $ind2 ?><p class="lg-license__empty">No licence chosen yet.</p>
<?= $ind ?></aside>
<?php
    return;
}

$name    = Licenses::name($code);
$short   = Licenses::short($code);
$deed    = Licenses::deed_url($code);
$clauses = Licenses::clauses($code);

$nameHtml  = htmlspecialchars($name,  ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
$shortHtml = htmlspecialchars($short, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
$titleHtml = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

/* compact shows the short code (CC BY-NC-SA 4.0); note shows the full name. */
$display = $variant === 'compact' ? $shortHtml : $nameHtml;

/* The accessible name is always the full licence — never the bare glyph and
   never "click here". aria-label on the region names the licence too, so the
   aside is reachable as a labelled landmark rather than an unexplained (cc). */
$regionLabel = Renderer::attr('Licence: ' . $name);

$linked = $showDeed && $deed !== '';
$tag      = $linked ? 'a' : 'span';
$hrefAttr = $linked ? ' href="' . Renderer::attr($deed) . '"' : '';
/* rel="license" is the standard machine-readable licence signal. */
$relAttr  = $linked ? ' rel="license noopener" target="_blank"' : '';
?>
<?= $ind ?><aside class="<?= Renderer::attr($classes) ?>" aria-label="<?= $regionLabel ?>" data-lg-license-code="<?= Renderer::attr($storedCode) ?>" data-lg-license-resolved="<?= Renderer::attr($code) ?>">
<?php if ($title !== ''): ?>
<?= $ind2 ?><div class="lg-license__title"><?= $titleHtml ?></div>
<?php endif; ?>
<?= $ind2 ?><<?= $tag ?> class="lg-license__name"<?= $hrefAttr ?><?= $relAttr ?>>
<?= $ind3 ?><span class="lg-license__mark" aria-hidden="true"><?= Icons::svg('cc') ?></span>
<?= $ind3 ?><span class="lg-license__label"><?= $display ?></span>
<?php if ($linked): ?>
<?= $ind3 ?><span class="lg-license__out" aria-hidden="true"><?= Icons::svg('external') ?></span>
<?php endif; ?>
<?= $ind2 ?></<?= $tag ?>>
<?php if (!empty($clauses)): ?>
<?= $ind2 ?><ul class="lg-license__clauses">
<?php foreach ($clauses as $c): ?>
<?= $ind3 ?><li class="lg-license__clause" title="<?= Renderer::attr((string) $c['description']) ?>"><span class="lg-license__clause-icon" aria-hidden="true"><?= Icons::svg((string) $c['icon']) ?></span><?= htmlspecialchars((string) $c['label'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') ?></li>
<?php endforeach; ?>
<?= $ind2 ?></ul>
<?php endif; ?>
<?= $ind ?></aside>
