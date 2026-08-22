<?php
/**
 * 196-header-probe.php — SHOW the account menu, in a real browser, at real
 * widths, in both themes.
 *
 * ⚠️ NOT A MEMBER SURFACE, and not reachable except through
 * platform/nginx/lane-preview-196-switch-menu.conf, which is itself behind the
 * dev2 gate cookie at server level. It renders nothing but the shared header
 * partial from THIS BRANCH, with a hand-built ctx.
 *
 * WHY IT HAS TO EXIST. Every membership page requires
 * '/srv/lg-shared/site-header.php', and /srv/lg-shared is a symlink into the
 * SERVING CHECKOUT — which serves main. So a lane preview of a PAGE shows the
 * branch's body wearing MAIN's header, and the header is the whole of #196
 * (trap-harness-and-serve-answer-from-main). Without this file the only way to
 * look at the change is to merge it first, which is the exact order this repo
 * keeps getting bitten by.
 *
 * ?viewer= picks the ctx. Default is the one the issue is about.
 *   patreon-tester  a listed tester Patreon is already charging  -> Switch
 *   tester          a listed tester with no Patreon              -> Join
 *   member          a signed-in member outside the cohort        -> neither
 *   anon            logged out                                   -> the anon pill
 *
 * It reads NO database and takes NO identity from the request: the capabilities
 * are literals below. A probe that resolved a real viewer could render one
 * person's standing to another person, and it would also be a second definition
 * of the thing under test.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/lg-shared/site-header.php';
require dirname(__DIR__, 2) . '/lg-shared/site-footer.php';

$viewer = (string)($_GET['viewer'] ?? 'patreon-tester');
$valid  = ['patreon-tester', 'tester', 'member', 'anon'];
if (!in_array($viewer, $valid, true)) { $viewer = 'patreon-tester'; }

$caps = ['manage_options' => false];
switch ($viewer) {
    case 'patreon-tester': $caps['stripe_testgroup'] = true;  $caps['patreon_paying'] = true;  break;
    case 'tester':         $caps['stripe_testgroup'] = true;  $caps['patreon_paying'] = false; break;
    case 'member':         $caps['stripe_testgroup'] = false; $caps['patreon_paying'] = true;  break;
}

$ctx = $viewer === 'anon'
    ? ['authenticated' => false, 'tier' => 'public']
    : [
        'authenticated' => true,
        'tier'          => 'lite',
        'display_name'  => 'preview viewer',
        'capabilities'  => $caps,
        'msg_unread'    => 0,
        'notif_unread'  => 0,
        'active_nav'    => '',
        'profile_url'   => '/profile/edit',
        'logout_url'    => '/logout',
    ];

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
$base = rtrim((string)($_SERVER['LG_196_BASE'] ?? '/preview/196-switch-menu'), '/');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>#196 header preview — <?= $h($viewer) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<style>
.p196{max-width:760px;margin:0 auto;padding:2rem 1.25rem 4rem;line-height:1.6}
.p196 h1{font-size:1.35rem;margin:0 0 .5rem}
.p196 nav a{display:inline-block;margin:0 .6rem .6rem 0;padding:.45rem .9rem;border-radius:6px;
  text-decoration:none;box-shadow:inset 0 0 0 2px currentColor;font-weight:600}
.p196 .now{background:#6b7c52;color:#fff;box-shadow:none}
.p196 p{margin:0 0 .9rem}
html[data-lguser-theme="dark"] .p196{color:#e5e7e1}
</style>
</head>
<body class="lg-membership-page">
<?php lg_shared_render_site_header($ctx); ?>
<main id="lg-main" class="p196">
  <h1>#196 — what the account menu shows</h1>
  <p>Open the account chip (top right). This is the real shared header from branch
     <code>196-switch-menu</code>, rendered for the viewer you pick below.</p>
  <nav>
    <?php foreach ($valid as $v): ?>
      <a class="<?= $v === $viewer ? 'now' : '' ?>" href="<?= $h($base) ?>/header/?viewer=<?= $h($v) ?>"><?= $h($v) ?></a>
    <?php endforeach; ?>
  </nav>
  <p><b>patreon-tester</b> — a member Patreon is already charging. The chip menu says
     <b>Switch</b> and goes to the instructions page; there is no Join anywhere.</p>
  <p><b>tester</b> — a listed tester with no Patreon. Unchanged: <b>Join</b>.</p>
  <p><b>member</b> — outside the soft-launch cohort. No Stripe entries at all,
     exactly as today, whatever their Patreon standing.</p>
  <p><b>anon</b> — untouched by #196.</p>
  <p style="margin-top:1.4rem"><a href="<?= $h($base) ?>/switch-billing/">The Switch page itself &rarr;</a></p>
</main>
<?php lg_shared_render_site_footer([]); ?>
</body>
</html>
