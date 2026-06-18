<?php
/**
 * blocks/brand-hero/render.php
 *
 * @var array $args  { show_hero_image?: bool, tagline?: string, related_label?: string, _depth: int }
 * @var array $ctx   { sponsor, viewer, editor_mode, ... }
 *
 * Sponsor-page hero. All data comes from $ctx['sponsor'] (the Lane-A brand
 * record), NOT from props — the props are just display toggles. Theming comes
 * from the article root's --brand-* CSS vars (emitted by Renderer from the
 * sponsor's colors), so this block carries no per-sponsor color logic; it just
 * reads var(--brand-*, <fallback>).
 *
 * Null-degradation is the whole game here: any field on the record can be null
 * (only total-vise is fully populated). Every section is guarded so a sparse
 * sponsor renders just what it has — no broken <img>, no empty bar, no dead CTA.
 *
 * The <lg-edit> marker is emitted by the Renderer wrapper — do NOT emit it here.
 */

use LG\LayoutV2\Renderer;

$sponsor = is_array($ctx['sponsor'] ?? null) ? $ctx['sponsor'] : null;
$depth   = (int) ($args['_depth'] ?? 1);
$ind     = Renderer::indent($depth);

/* No sponsor record bound to this layout → nothing to render. In editor mode
   leave a breadcrumb so the author knows the block needs a sponsor key. */
if ($sponsor === null) {
    if (!empty($ctx['editor_mode'])) {
        echo $ind . '<!-- lg-brand-hero: no sponsor record on this layout (set the sponsor key) -->';
    }
    return;
}

$showHero  = ($args['show_hero_image'] ?? true) !== false;
$tagline   = trim((string) ($args['tagline'] ?? ''));
$relLabel  = trim((string) ($args['related_label'] ?? '')) ?: 'Related Content';

/* Identity */
$name      = trim((string) ($sponsor['display_name'] ?? '')) ?: trim((string) ($sponsor['name'] ?? ''));
$logoUrl   = trim((string) ($sponsor['logo_url'] ?? ''));
$hero      = is_array($sponsor['hero'] ?? null) ? $sponsor['hero'] : [];
$heroUrl   = trim((string) ($hero['url'] ?? ''));
$heroCap   = trim((string) ($hero['caption'] ?? ''));
$heroTitle = trim((string) ($hero['title'] ?? ''));
if ($tagline === '') $tagline = $heroCap;

/* Socials — only non-empty links render. Globe = website (always last). */
$social   = is_array($sponsor['social'] ?? null) ? $sponsor['social'] : [];
$website  = trim((string) ($sponsor['website'] ?? ''));
$socialMap = [
    'facebook'  => ['label' => 'Facebook',  'svg' => '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>'],
    'instagram' => ['label' => 'Instagram', 'svg' => '<rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/>'],
    'youtube'   => ['label' => 'YouTube',   'svg' => '<path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19.1c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33z"/><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02"/>'],
];
$socials = [];
foreach ($socialMap as $key => $meta) {
    $url = trim((string) ($social[$key] ?? ''));
    if ($url !== '') $socials[] = ['url' => $url, 'label' => $meta['label'], 'svg' => $meta['svg']];
}
if ($website !== '') {
    $socials[] = ['url' => $website, 'label' => 'Website',
        'svg' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2c3 3.5 3 17.5 0 20M12 2c-3 3.5-3 17.5 0 20"/>'];
}

/* CTA strip — Visit Website / Visit Forum / Related Content. Each only if its
   URL is present on the record. */
$forumUrl = trim((string) ($sponsor['forum_url'] ?? ''));
$tagUrl   = trim((string) ($sponsor['tag_url'] ?? ''));
$ctas = [];
if ($website !== '')  $ctas[] = ['href' => $website,  'label' => 'Visit Website', 'svg' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2c3 3.5 3 17.5 0 20M12 2c-3 3.5-3 17.5 0 20"/>'];
if ($forumUrl !== '') $ctas[] = ['href' => $forumUrl, 'label' => 'Visit Forum',   'svg' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'];
/* "Related Content" runs a regular keyword search for the brand on the Hub
   (/hub/?q=…) — the same search the Hub's own box + tag chips use — NOT the old
   tag-archive filter. Shown whenever we have a brand name to search for. */
if ($name !== '')     $ctas[] = ['href' => '/hub/?q=' . rawurlencode($name), 'label' => $relLabel, 'svg' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>'];

$editorMode   = !empty($ctx['editor_mode']);
$taglineEdit  = $editorMode ? ' data-lg-edit-prop="tagline"' : '';

ob_start();
?>
<?= $ind ?><header class="lg-brand-hero">
<?php if ($showHero && $heroUrl !== ''): ?>
<?= $ind ?>  <div class="lg-brand-hero__banner">
<?= $ind ?>    <img class="lg-brand-hero__banner-img" src="<?= Renderer::attr($heroUrl) ?>" alt="<?= Renderer::attr($heroTitle !== '' ? $heroTitle : $name) ?>" loading="eager" fetchpriority="high" />
<?= $ind ?>  </div>
<?php endif; ?>
<?= $ind ?>  <div class="lg-brand-hero__bar">
<?php if ($logoUrl !== ''): ?>
<?= $ind ?>    <div class="lg-brand-hero__logo">
<?= $ind ?>      <img src="<?= Renderer::attr($logoUrl) ?>" alt="<?= Renderer::attr($name) ?> logo" loading="eager" />
<?= $ind ?>    </div>
<?php endif; ?>
<?= $ind ?>    <div class="lg-brand-hero__id">
<?php if ($name !== ''): ?>
<?= $ind ?>      <h1 class="lg-brand-hero__name"><?= Renderer::text($name) ?></h1>
<?php endif; ?>
<?php if ($tagline !== '' || $editorMode): ?>
<?= $ind ?>      <p class="lg-brand-hero__tagline"<?= $taglineEdit ?>><?= Renderer::text($tagline) ?></p>
<?php endif; ?>
<?= $ind ?>    </div>
<?php if ($socials): ?>
<?= $ind ?>    <nav class="lg-brand-hero__socials" aria-label="<?= Renderer::attr($name) ?> links">
<?php foreach ($socials as $s): ?>
<?= $ind ?>      <a href="<?= Renderer::attr($s['url']) ?>" title="<?= Renderer::attr($s['label']) ?>" target="_blank" rel="noopener noreferrer">
<?= $ind ?>        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $s['svg'] ?></svg>
<?= $ind ?>      </a>
<?php endforeach; ?>
<?= $ind ?>    </nav>
<?php endif; ?>
<?= $ind ?>  </div>
<?php if ($ctas): ?>
<?= $ind ?>  <div class="lg-brand-hero__cta">
<?php foreach ($ctas as $c): ?>
<?= $ind ?>    <a class="lg-brand-hero__cta-btn" href="<?= Renderer::attr($c['href']) ?>" target="_blank" rel="noopener noreferrer">
<?= $ind ?>      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $c['svg'] ?></svg>
<?= $ind ?>      <span><?= Renderer::text($c['label']) ?></span>
<?= $ind ?>    </a>
<?php endforeach; ?>
<?= $ind ?>  </div>
<?php endif; ?>
<?= $ind ?></header>
<?php
echo ob_get_clean();
