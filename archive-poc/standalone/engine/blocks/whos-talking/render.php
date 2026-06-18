<?php
/**
 * blocks/whos-talking/render.php
 *
 * @var array $args  { heading?: string, blurb?: string, _depth: int }
 * @var array $ctx   { sponsor, editor_mode, ... }
 *
 * Forum/tag cross-link panel. Reads sponsor.forum_url + sponsor.tag_url. Each
 * CTA renders only if its URL is present; if neither exists the whole block is
 * suppressed (nothing to link to). Auto-themes via --brand-*.
 *
 * The <lg-edit> marker is emitted by the Renderer wrapper — do NOT emit it here.
 */

use LG\LayoutV2\Renderer;

$sponsor = is_array($ctx['sponsor'] ?? null) ? $ctx['sponsor'] : null;
$depth   = (int) ($args['_depth'] ?? 1);
$ind     = Renderer::indent($depth);
$editorMode = !empty($ctx['editor_mode']);

$forumUrl = $sponsor !== null ? trim((string) ($sponsor['forum_url'] ?? '')) : '';
$name    = $sponsor !== null ? (trim((string) ($sponsor['display_name'] ?? '')) ?: trim((string) ($sponsor['name'] ?? ''))) : '';

/* Need at least a forum to link or a name to search; otherwise nothing to show. */
if ($forumUrl === '' && $name === '') {
    if ($editorMode) echo $ind . '<!-- lg-whos-talking: no forum_url and no sponsor name to search -->';
    return;
}

$heading = trim((string) ($args['heading'] ?? ''));
if ($heading === '') $heading = $name !== '' ? "See who's talking about {$name}" : "Join the conversation";
$blurb   = trim((string) ($args['blurb'] ?? ''));

$headingEdit = $editorMode ? ' data-lg-edit-prop="heading"' : '';
$blurbEdit   = $editorMode ? ' data-lg-edit-prop="blurb"' : '';

ob_start();
?>
<?= $ind ?><section class="lg-whos-talking">
<?= $ind ?>  <div class="lg-whos-talking__inner">
<?= $ind ?>    <div class="lg-whos-talking__copy">
<?= $ind ?>      <h2 class="lg-whos-talking__title"<?= $headingEdit ?>><?= Renderer::text($heading) ?></h2>
<?php if ($blurb !== '' || $editorMode): ?>
<?= $ind ?>      <p class="lg-whos-talking__blurb"<?= $blurbEdit ?>><?= Renderer::text($blurb) ?></p>
<?php endif; ?>
<?= $ind ?>    </div>
<?= $ind ?>    <div class="lg-whos-talking__links">
<?php if ($forumUrl !== ''): ?>
<?= $ind ?>      <a class="lg-whos-talking__link lg-whos-talking__link--forum" href="<?= Renderer::attr($forumUrl) ?>" target="_blank" rel="noopener noreferrer">
<?= $ind ?>        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
<?= $ind ?>        <span>Join the forum discussion</span>
<?= $ind ?>      </a>
<?php endif; ?>
<?php if ($name !== ''): /* regular keyword search on the Hub, not the tag-archive filter */ ?>
<?= $ind ?>      <a class="lg-whos-talking__link lg-whos-talking__link--tag" href="/hub/?q=<?= rawurlencode($name) ?>">
<?= $ind ?>        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
<?= $ind ?>        <span>Browse related content</span>
<?= $ind ?>      </a>
<?php endif; ?>
<?= $ind ?>    </div>
<?= $ind ?>  </div>
<?= $ind ?></section>
<?php
echo ob_get_clean();
