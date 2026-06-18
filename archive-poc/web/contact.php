<?php
/**
 * archive-poc/web/contact.php — /contact/ standalone content page.
 *
 * Placeholder: there is no source WP /contact/ page to port (404 on dev), so
 * this ships as a wired-up shell. Drop the real contact copy / form details
 * into the <main> body below when ready.
 */
declare(strict_types=1);
require __DIR__ . '/_page-shell.php';
[$is_member, $tier] = lg_page_boot();

lg_page_open($is_member, 'Contact', 'Get in touch with The Looth Group.', 'view-content arc-contact-page', '');
?>
<h1>Contact</h1>
<p class="lg-page-sub">Get in touch</p>
<p>Reach the Looth Group team through the community forums or your account.</p>
<p><em>Contact details are on the way.</em></p>
<?php
lg_page_close();
