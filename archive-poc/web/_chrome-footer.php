<?php
require __DIR__.'/../config.php';
$LOGO = LG_ARCHIVE_POC_LOGO_URL;
?>
<footer class="lg-chrome-foot" role="contentinfo">
  <div class="lg-chrome-foot__inner">
    <div class="lg-chrome-foot__brand">
      <a class="lg-chrome-foot__logo" href="/" rel="home" aria-label="Looth Group home">
        <img src="<?= h($LOGO) ?>" alt="Looth Group" width="56" height="56">
      </a>
      <p class="lg-chrome-foot__tag">Online Group for Luthiers, Musical Instrument Repair and Restoration Specialists and Technicians</p>
    </div>

    <nav class="lg-chrome-foot__cols" aria-label="Footer">
      <div class="lg-chrome-foot__col">
        <h3 class="lg-chrome-foot__h">Browse</h3>
        <ul>
          <li><a href="/">Home</a></li>
          <li><a href="/calendar/">Calendar</a></li>
          <li><a href="/sponsors/">Sponsors</a></li>
          <li><a href="/shops/">Shops</a></li>
        </ul>
      </div>
      <div class="lg-chrome-foot__col">
        <h3 class="lg-chrome-foot__h">Community</h3>
        <ul>
          <li><a href="/hub/">Forums</a></li>
          <?php /* "Activity" removed (HK-012 / GH #44): /activity/ 301s straight
                   back to home — the activity feed folded into the Hub, which is
                   already linked above. A dead footer link reads as broken nav. */ ?>
          <li><a href="/members/">Members</a></li>
        </ul>
      </div>
      <div class="lg-chrome-foot__col">
        <h3 class="lg-chrome-foot__h">About</h3>
        <ul>
          <li><a href="/about/">About</a></li>
          <?php /* Join funnel = the Patreon canonical (Ian 6/12; /lgjoin/ is
                   admin-gated pre-launch — anon was hitting a gate stub). */ ?>
          <li><a href="https://www.patreon.com/c/theloothgroup/membership">Membership</a></li>
          <li><a href="/contact/">Contact</a></li>
        </ul>
      </div>
    </nav>
  </div>

  <div class="lg-chrome-foot__legal">
    <span>© <?= date('Y') ?> The Looth Group. All rights reserved.</span>
    <nav aria-label="Legal">
      <a href="/privacy/">Privacy</a>
      <a href="/terms/">Terms</a>
      <a href="/request-refund/">Billing &amp; Refund</a>
    </nav>
  </div>
</footer>
