<?php
declare(strict_types=1);

/**
 * bin/render-check.php — inspect the profile block markup a given AUDIENCE gets,
 * straight from the working tree. No HTTP, no serve, no browser engine.
 *
 * WHY (profile-audit 2026-07-28): verifying a change to /u/ normally means either
 * a serve window or a CDP engine. The engine is the scarcest thing on this box and
 * the serve is not a lane's to write to — so a branch change could sit unverified
 * purely for lack of headroom. This calls looth_render_profile_blocks() directly
 * and inspects the emitted HTML, which is enough to answer the two questions that
 * actually bite:
 *
 *   1. DOES IT LEAK? Owner-only affordances must not render for member/public/admin.
 *   2. DOES IT CORRUPT THE LAYOUT ORDER? u.php's order() reads the saved section
 *      order straight off the DOM via `.lg-block:not(.lg-block--header)` ->
 *      data-block. ANY new element that picks up `.lg-block` or a `data-block`
 *      attribute silently rewrites members' section order on their next save.
 *      That is a data defect, not a visual one, and no screenshot would show it.
 *
 * Run as the DB role (CLI as ubuntu has no postgres role):
 *   sudo -n -u profile-app php profile-app/bin/render-check.php <userId> <role>
 *   role: me | member | public | admin      (u.php maps admin_edit -> 'me')
 *
 * Reads only. Prints counts + the order as u.php would read it. Not a gate —
 * a lens. Add assertions to a real gate if a defect class shows up twice.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$base = dirname(__DIR__);
require_once $base . '/config.php';
require_once $base . '/web/_render_blocks.php';

$uid  = (int)($argv[1] ?? 0);
$role = (string)($argv[2] ?? 'me');
if ($uid < 1) { fwrite(STDERR, "usage: render-check.php <userId> [me|member|public|admin]\n"); exit(2); }

ob_start();
looth_render_profile_blocks($uid, $role, null, '', $uid);
$html = ob_get_clean();

printf("user=%d role=%s  bytes=%d\n", $uid, $role, strlen($html));
foreach ([
    'lg-layoutrow    (Your layout row)' => 'class="lg-layoutrow"',
    'lg-addsec       (add-section card)' => 'class="lg-addsec"',
    'data-caddy-open (picker openers)'   => 'data-caddy-open',
    'data-block=     (real blocks)'      => 'data-block=',
] as $label => $needle) {
    printf("  %-34s : %d\n", $label, substr_count($html, $needle));
}

// Emulate u.php order(): .lg-block:not(.lg-block--header) -> data-block
$doc = new DOMDocument();
libxml_use_internal_errors(true);
$doc->loadHTML('<div>' . $html . '</div>');
libxml_clear_errors();
$xp = new DOMXPath($doc);
$sel = "//*[contains(concat(' ',normalize-space(@class),' '),' lg-block ')]"
     . "[not(contains(concat(' ',normalize-space(@class),' '),' lg-block--header '))]";

$order = [];
$leak  = 0;
foreach ($xp->query($sel) as $b) {
    $d = $b->getAttribute('data-block');
    if ($d !== '') $order[] = $d;
    $c = ' ' . preg_replace('/\s+/', ' ', $b->getAttribute('class')) . ' ';
    if (str_contains($c, ' lg-layoutrow ') || str_contains($c, ' lg-addsec ')) $leak++;
}
printf("  %-34s : [%s]\n", 'ORDER as u.php would read it', implode(', ', $order));
printf("  %-34s : %d   %s\n", 'non-block elements inside order()', $leak,
       $leak === 0 ? '(SAFE)' : '(*** WOULD CORRUPT SAVED ORDER ***)');

exit($leak === 0 ? 0 : 1);
