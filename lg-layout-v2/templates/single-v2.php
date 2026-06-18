<?php
/**
 * Minimal lite-chrome template for v2-managed posts.
 *
 * Bypasses theme + Elementor so the_content filter actually fires (and v2's
 * WpRenderer can replace the content with the engine's HTML). Phase 2 ships
 * this as the simplest possible template; Phase 5 may extend it with shared
 * header/footer chrome lifted from v1's single-managed.php.
 *
 * Used via template_include filter registered in Plugin::boot().
 */
if (!defined('ABSPATH')) exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<?php wp_head(); ?>
</head>
<body <?php body_class('lg-page lg-page--v2'); ?>>
<?php
/* wp_body_open is where the admin bar renders to position itself at the top
   of the page (since WP 5.2). Without this, the bar still renders via the
   fallback wp_footer hook but visually lands at the bottom. */
wp_body_open();
/* Site header is plugin-resident (LG\LayoutV2\SiteHeader). The lite template
   bypasses get_header() entirely to keep v2 articles free of BB's content
   wrapper, so include the partial directly from the plugin. */
LG\LayoutV2\SiteHeader::render();
?>
<?php
/* Sidebar is opt-in. When no widget is assigned at Appearance →
   Widgets → "Post Sidebar (v2)" we emit exactly the same <main>
   the old template did — byte-identical DOM, no wrapper div, no
   extra CSS. Add a widget and the template switches into the
   two-column grid layout. */
if (is_active_sidebar('lg-v2-post-sidebar')): ?>
<div class="lg-layout lg-layout--with-sidebar">
  <main class="lg-main">
    <?php
    while (have_posts()) {
        the_post();
        the_content();
    }
    ?>
  </main>
  <aside class="lg-v2-sidebar" aria-label="<?php esc_attr_e('Sidebar', 'lg-layout-v2'); ?>">
    <?php dynamic_sidebar('lg-v2-post-sidebar'); ?>
  </aside>
</div>
<style>
/* Template-scoped — no need to thread through CssBuilder or rev the
   bundle for chrome outside the article. Breakpoint: 1025px+ is
   desktop (side-by-side); ≤1024px stacks article first, sidebar
   below. Sidebar column is a fixed 320px so it doesn't squeeze the
   article's readable column. */
.lg-layout--with-sidebar {
  display: grid;
  grid-template-columns: 1fr;
  gap: 32px;
  align-items: start;
}
.lg-layout--with-sidebar > .lg-v2-sidebar {
  padding-inline: var(--lg-article-padding-inline, 16px);
}
.lg-v2-sidebar__widget { margin-bottom: 28px; }
.lg-v2-sidebar__title  { font: 700 14px/1.2 var(--lg-font-sans, system-ui); text-transform: uppercase; letter-spacing: 0.08em; margin: 0 0 12px; }
@media (min-width: 1025px) {
  .lg-layout--with-sidebar {
    grid-template-columns: minmax(0, 1fr) 320px;
    max-width: calc(var(--lg-article-max-wide, 1320px) + 32px);
    margin-inline: auto;
    padding-inline: var(--lg-article-padding-inline, 16px);
  }
  .lg-layout--with-sidebar > .lg-main { min-width: 0; }
  .lg-layout--with-sidebar > .lg-v2-sidebar { padding-inline: 0; position: sticky; top: 88px; }
  /* When the article is constrained by the grid column, neutralize the
     full-bleed escape on direct children so they don't bust the grid. */
  .lg-layout--with-sidebar .lg-article > .lg-post-header,
  .lg-layout--with-sidebar .lg-article > .lg-post-footer,
  .lg-layout--with-sidebar .lg-article > .lg-fullbleed {
    width: auto;
    max-width: 100%;
    margin-inline: 0;
  }
}
</style>
<?php else: ?>
<main class="lg-main">
  <?php
  while (have_posts()) {
      the_post();
      the_content();
  }
  ?>
</main>
<?php endif; ?>
<?php
/* Site footer is plugin-resident (LG\LayoutV2\SiteFooter). Mirrors the
   header pattern — included directly because the lite template bypasses
   get_footer() too. */
LG\LayoutV2\SiteFooter::render();
wp_footer();
?>
</body>
</html>
