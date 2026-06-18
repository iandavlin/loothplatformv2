<?php
/**
 * archive-poc/web/about.php — /about/ standalone content page.
 *
 * Placeholder: there is no source WP /about/ page to port (404 on dev), so this
 * ships as a wired-up shell. Drop the real copy into the <main> body below when
 * it's ready — routing, header/footer, and styling are already in place.
 */
declare(strict_types=1);
require __DIR__ . '/_page-shell.php';
[$is_member, $tier] = lg_page_boot();

lg_page_open($is_member, 'About', 'About The Looth Group — the online community for luthiers and instrument repair specialists.', 'view-content arc-about-page', '');
?>
<h1>About</h1>
<p class="lg-page-sub">The Looth Group</p>
<p>The Looth Group is an online community for luthiers, musical-instrument repair and restoration specialists, and technicians.</p>
<p><em>More about us is on the way.</em></p>
<?php
lg_page_close();
