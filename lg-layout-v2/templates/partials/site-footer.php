<?php
/**
 * site-footer.php
 *
 * Rendered by LG\LayoutV2\SiteFooter on the buddyboss_footer hook (and
 * directly from single-v2.php for the lite template).
 *
 * Three nav-menu locations drive the link columns. Empty locations
 * collapse silently.
 */

if (!defined('ABSPATH')) exit;

$site_name = (string) get_bloginfo('name');
$tagline   = (string) get_bloginfo('description');
$home_url  = (string) home_url('/');
$year      = (int) current_time('Y');
$logo_id   = function_exists('get_theme_mod') ? (int) get_theme_mod('custom_logo') : 0;

$locations = \LG\LayoutV2\SiteFooter::MENU_LOCATIONS;
$menus     = get_nav_menu_locations();
?>
<footer class="lg-site-footer" role="contentinfo">
  <div class="lg-site-footer__inner">

    <div class="lg-site-footer__brand">
      <a class="lg-site-footer__logo" href="<?= esc_url($home_url) ?>" rel="home" aria-label="<?= esc_attr($site_name) ?> home">
        <?php if ($logo_id && function_exists('wp_get_attachment_image')) {
            echo wp_get_attachment_image($logo_id, 'medium', false, [
                'class' => 'lg-site-footer__logo-img',
                'alt'   => $site_name,
            ]);
        } else { ?>
          <span class="lg-site-footer__logo-text"><?= esc_html($site_name) ?></span>
        <?php } ?>
      </a>
      <?php if ($tagline !== ''): ?>
        <p class="lg-site-footer__tagline"><?= esc_html($tagline) ?></p>
      <?php endif; ?>
    </div>

    <nav class="lg-site-footer__nav" aria-label="Footer">
      <?php foreach ($locations as $loc => $label):
          if (empty($menus[$loc])) continue;
          $menu_obj = wp_get_nav_menu_object($menus[$loc]);
          $title    = $menu_obj ? (string) $menu_obj->name : (string) $label;
      ?>
        <div class="lg-site-footer__col">
          <h4 class="lg-site-footer__col-h"><?= esc_html($title) ?></h4>
          <?php
          wp_nav_menu([
              'theme_location' => $loc,
              'container'      => false,
              'menu_class'     => 'lg-site-footer__list',
              'depth'          => 1,
              'fallback_cb'    => false,
          ]);
          ?>
        </div>
      <?php endforeach; ?>
    </nav>

  </div>

  <div class="lg-site-footer__legal">
    <span class="lg-site-footer__copy">
      &copy; <?= $year ?> <?= esc_html($site_name) ?>. All rights reserved.
    </span>

    <?php if (!empty($menus[\LG\LayoutV2\SiteFooter::LEGAL_LOCATION])):
        wp_nav_menu([
            'theme_location' => \LG\LayoutV2\SiteFooter::LEGAL_LOCATION,
            'container'      => 'nav',
            'container_class'=> 'lg-site-footer__legal-nav',
            'menu_class'     => 'lg-site-footer__legal-links',
            'depth'          => 1,
            'fallback_cb'    => false,
        ]);
    endif; ?>

    <span class="lg-site-footer__mark">made with care</span>
  </div>
</footer>
