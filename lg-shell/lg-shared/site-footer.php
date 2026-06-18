<?php
/**
 * /srv/lg-shared/site-footer.php
 *
 * Shared site-footer partial.  Include from any strangler surface:
 *
 *   require_once '/srv/lg-shared/site-footer.php';
 *   lg_shared_render_site_footer([
 *       'logo_url' => 'https://…/logo.png',   // optional
 *   ]);
 *
 * CSS lives in site-header.css (.lg-chrome-foot* rules).
 */

declare(strict_types=1);

if (!function_exists('lg_shared_render_site_footer')) {
/**
 * @param array{ logo_url?: string } $ctx
 */
function lg_shared_render_site_footer(array $ctx = []): void
{
    $logo_url = (string)($ctx['logo_url'] ?? 'https://loothgroup.com/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png');
    $h = 'lg_shared_h';
    if (!function_exists('lg_shared_h')) {
        require_once __DIR__ . '/site-header.php'; // pulls in lg_shared_h
    }
    ?>
<footer class="lg-chrome-foot" role="contentinfo">
  <div class="lg-chrome-foot__inner">
    <div class="lg-chrome-foot__brand">
      <a class="lg-chrome-foot__logo" href="/" rel="home" aria-label="Looth Group home">
        <img src="<?= $h($logo_url) ?>" alt="Looth Group" width="56" height="56">
      </a>
      <p class="lg-chrome-foot__tag">Online Group for Luthiers, Musical Instrument Repair and Restoration Specialists and Technicians</p>
    </div>

    <nav class="lg-chrome-foot__cols" aria-label="Footer">
      <div class="lg-chrome-foot__col">
        <h3 class="lg-chrome-foot__h">Browse</h3>
        <ul>
          <li><a href="/">Home</a></li>
          <li><a href="/events/">Events</a></li>
          <li><a href="/sponsors/">Sponsors</a></li>
        </ul>
      </div>
      <div class="lg-chrome-foot__col">
        <h3 class="lg-chrome-foot__h">Community</h3>
        <ul>
          <li><a href="/hub/">The Hub</a></li>
          <li><a href="/archive-poc/">Activity</a></li>
          <li><a href="/directory/members/">Members</a></li>
        </ul>
      </div>
      <div class="lg-chrome-foot__col">
        <h3 class="lg-chrome-foot__h">About</h3>
        <ul>
          <li><a href="/about/">About</a></li>
          <li><a href="/contact/">Contact</a></li>
        </ul>
      </div>
    </nav>
  </div>

  <div class="lg-chrome-foot__legal">
    <span>© <?= date('Y') ?> The Looth Group. All rights reserved.</span>
    <nav aria-label="Legal">
      <a href="https://loothtool.com/privacy/">Privacy</a>
      <a href="https://loothtool.com/terms/">Terms</a>
    </nav>
  </div>
</footer>
<?php
} // end function
} // end if !function_exists
