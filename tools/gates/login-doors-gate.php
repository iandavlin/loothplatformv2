<?php
/**
 * login-doors-gate.php — every door into the site keeps the destination.
 *
 * dest-capture-gate.php proves the VALIDATOR is right. This proves the DOORS
 * actually call it: it renders the real shared chrome (the partial itself, not
 * a copy) and asserts the sign-in hrefs carry the reader's destination — and
 * that a bare or hostile request still yields the byte-identical bare login.
 *
 *   php tools/gates/login-doors-gate.php
 *
 * WHY THIS EXISTS: the chrome "Sign in" is the most-used door on the site and
 * it silently carried nothing for months while every test stayed green. It also
 * guards trap #1 in this repo — lg-shell/lg-shared/site-header.php is a DEAD
 * copy; edit that one and you ship nothing. This gate renders the SERVING
 * partial (lg-shared/site-header.php, the /srv/lg-shared symlink target), so a
 * fix applied to the dead copy fails here.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$fail = 0;
$pass = 0;

function check(string $label, $got, $expect): void
{
    global $fail, $pass;
    if ($got === $expect) { $pass++; return; }
    $fail++;
    printf("  FAIL  %s\n         expected=%s\n         got     =%s\n",
        $label, var_export($expect, true), var_export($got, true));
}

function contains(string $label, string $haystack, string $needle, bool $want = true): void
{
    global $fail, $pass;
    // grep -c counts LINES, not occurrences (trap #3) — substr_count is the honest
    // form and this gate exists partly because a marker was miscounted that way.
    $n = substr_count($haystack, $needle);
    if (($n > 0) === $want) { $pass++; return; }
    $fail++;
    printf("  FAIL  %s\n         %s %s (found %d)\n",
        $label, $want ? 'expected to find' : 'expected NOT to find', var_export($needle, true), $n);
}

/** Render the SERVING shared header for a given request + auth state. */
function render_header(string $requestUri, bool $authed): string
{
    static $loaded = false;
    $_SERVER['HTTP_HOST']   = 'dev2.loothgroup.com';
    $_SERVER['REQUEST_URI'] = $requestUri;
    if (!$loaded) {
        require_once dirname(__DIR__, 2) . '/lg-shared/site-header.php';
        $loaded = true;
    }
    ob_start();
    lg_shared_render_site_header([
        'authenticated' => $authed,
        'tier'          => $authed ? 'pro' : 'public',
        'display_name'  => $authed ? 'test-member' : '',
        'capabilities'  => ['manage_options' => false],
        'msg_unread'    => 0,
        'notif_unread'  => 0,
        'logout_url'    => '/wp-login.php?action=logout',
    ]);
    return (string) ob_get_clean();
}

echo "=== login-doors: the shared chrome (lg-shared/site-header.php) ===\n";

$dest = '/hub/gear/some-thread/?highlight=42';
$want = '/wp-login.php?redirect_to=' . rawurlencode($dest);
$html = render_header($dest, false);

// Desktop button AND phone-drawer item — BOTH doors, both were bare.
contains('desktop "Sign in" carries the destination',
    $html, 'class="lg-chrome__signin" href="' . htmlspecialchars($want, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '"');
contains('mobile-menu "Sign in" carries the destination',
    $html, 'class="lg-chrome__menu-signin"><a href="' . htmlspecialchars($want, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '"');
contains('no bare sign-in href survives anywhere in the chrome',
    $html, 'href="/wp-login.php"', false);
contains('the JS twin is shipped to anon', $html, '/lg-shared/lg-destination.js?v=');

// Ruling 5: a request with nothing bindable must emit the byte-identical bare
// login it emitted before this lane — no destination, no behaviour change.
$bare = render_header('//evil.example/hub/', false);
contains('hostile REQUEST_URI falls back to a BARE login (desktop)',
    $bare, 'class="lg-chrome__signin" href="/wp-login.php"');
contains('hostile REQUEST_URI falls back to a BARE login (mobile)',
    $bare, 'class="lg-chrome__menu-signin"><a href="/wp-login.php"');
contains('hostile REQUEST_URI binds nothing', $bare, 'redirect_to=', false);

// Ruling 3: already on an auth page → no destination (that is the loop).
$onLogin = render_header('/wp-login.php?loggedout=true', false);
contains('on /wp-login.php the door binds nothing', $onLogin, 'redirect_to=', false);

// A signed-in member has no sign-in door at all, and pays nothing for this lane.
$authed = render_header($dest, true);
contains('signed-in chrome has no sign-in door', $authed, 'lg-chrome__signin', false);
contains('signed-in chrome does not load the JS twin', $authed, 'lg-destination.js', false);

echo "\n=== login-doors: no converted door still emits a bare sign-in ===\n";

/**
 * The doors this lane converted. Each must no longer contain a hard-coded bare
 * sign-in href. This is the inventory that stops a door regressing quietly —
 * "one posture" is only true while every one of these routes through the helper.
 */
$doors = [
    'lg-shared/site-header.php',
    'profile-app/web/_render_blocks.php',
    'profile-app/web/directory-members.php',
    'profile-app/web/_render.php',
    'archive-poc/api/v0/comments.php',
    'archive-poc/web/fp-discuss.js',
    'archive-poc/web/archive.js',
    'archive-poc/standalone/render.php',
    'bb-mirror/web/_chrome.php',
    'bb-mirror/web/forums/_single-topic.php',
    'bb-mirror/api/v0/topic.php',
    'lg-layout-v2/blocks/post-footer/render.php',
    'membership-pages/lib/whoami.php',
    'membership-pages/web/connect-your-patreon.php',
    'membership-pages/web/join.php',
    'membership-pages/web/manage-subscription.php',
    'webroot/hub-polish.js',
];

foreach ($doors as $rel) {
    $path = $root . '/' . $rel;
    if (!is_readable($path)) {
        $fail++;
        echo "  FAIL  missing door file: {$rel}\n";
        continue;
    }
    $src = (string) file_get_contents($path);
    // Strip comments-ish lines so prose mentioning /wp-login.php doesn't trip it.
    $code = preg_replace('#^\s*(//|\*|/\*|\#|<\?php /\*).*$#m', '', $src) ?? $src;

    // A bare sign-in URL: /wp-login.php closed immediately by a quote, i.e. no
    // ?redirect_to and no concatenation. The inline fallbacks this lane kept on
    // purpose always continue '?redirect_to=', so they don't match.
    //
    // Allowed on the SAME LINE: a cross-host surface passing /wp-login.php as
    // the $base argument to lg_dest_login_url(). That is the helper being
    // called, not a door going bare.
    $bareHits = [];
    foreach (explode("\n", $code) as $lineNo => $line) {
        if (!preg_match_all('#[\'"`]/wp-login\.php[\'"`]#', $line, $m)) continue;
        if (strpos($line, 'lg_dest_login_url') !== false) continue;   // it IS the helper call
        $bareHits[] = ($lineNo + 1) . ': ' . trim($line);
    }
    if ($bareHits) {
        $fail++;
        printf("  FAIL  %s still emits a bare sign-in URL:\n         %s\n",
            $rel, implode("\n         ", $bareHits));
    } else {
        $pass++;
    }
}

echo "\n=== login-doors: gated surfaces still show their TEASER (ruling 3) ===\n";

/**
 * Ruling 3's second half: a gated surface must show its own teaser, never bounce
 * the visitor back to login. This lane only changed WHERE the teaser's sign-in
 * link goes — the teaser itself must be untouched, and there must be no new
 * redirect on these paths.
 *
 * These render inside profile-app, which requires /srv/lg-shared/lg-destination.php
 * — a path that only resolves once this branch is the checkout /srv points at. So
 * this leg asserts on source rather than rendering. A live FREE-member-to-a-
 * Pro-only-destination walk is still owed and needs a serve window.
 */
$gateFile = $root . '/profile-app/web/_render_blocks.php';
$gateSrc  = (string) file_get_contents($gateFile);
foreach ([
    'members-only teaser heading kept'  => 'This profile is members-only',
    'practice teaser heading kept'      => 'This practice is members-only',
    'join CTA kept alongside sign-in'   => 'class="lg-gate__join" href="/lgjoin/"',
    'sign-in door goes through helper'  => 'lg_dest_login_url(lg_dest_here())',
] as $label => $needle) {
    contains($label, $gateSrc, $needle);
}
// No gated surface may have gained a redirect-to-login.
contains('no wp_redirect/header(Location) added to the gate paths',
    $gateSrc, 'wp-login.php?redirect_to', false);

echo "\n=== login-doors: trap #1 — the dead lg-shell copy must stay dead ===\n";
$dead = $root . '/lg-shell/lg-shared/site-header.php';
if (is_readable($dead)) {
    $deadSrc = (string) file_get_contents($dead);
    // If someone "fixes" the dead copy, they shipped nothing. Say so loudly here
    // rather than letting a green suite imply the door works.
    if (substr_count($deadSrc, 'lg_dest_login_url') > 0) {
        $fail++;
        echo "  FAIL  lg-shell/lg-shared/site-header.php was edited — that copy is NOT served.\n"
           . "        The serving header is lg-shared/site-header.php (/srv/lg-shared).\n";
    } else {
        $pass++;
        echo "  ok    dead copy untouched (serving header is lg-shared/site-header.php)\n";
    }
} else {
    $pass++;
    echo "  ok    no lg-shell copy present\n";
}

printf("\n  %d passed, %d failed\n", $pass, $fail);
if ($fail > 0) {
    echo "###### login-doors GATE RED — do not push ######\n";
    exit(1);
}
echo "###### login-doors GATE GREEN ######\n";
exit(0);
