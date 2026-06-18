<?php
declare(strict_types=1);

/**
 * /weekly/ — STANDALONE weekly-digest pages (Ian 6/12).
 *
 *   /weekly/           → issue index (newest first)
 *   /weekly/<slug>/    → one issue: the curated sections as web cards
 *
 * Members-only, matching the WP version's gate: logged-out visitors get the
 * shell + a sign-in card (leak-safe — section data never renders for anon).
 * No WP boot: issue + cards read via read-only MySQL (weekly-query.php);
 * viewer state via the cached whoami loopback (same as the events landing).
 */

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/weekly-query.php';
require '/srv/lg-shared/site-header.php';
require '/srv/lg-shared/site-footer.php';

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string)parse_url($request_uri, PHP_URL_PATH);
$slug = ''; $showAll = false; $rawMode = false;
if (preg_match('#^/weekly/all/?$#', $path)) { $showAll = true; }
elseif (preg_match('#^/weekly/([a-z0-9\-]+)/raw/?$#', $path, $m)) { $slug = $m[1]; $rawMode = true; }
elseif (preg_match('#^/weekly/([a-z0-9\-]+)/?$#', $path, $m)) { $slug = $m[1]; }

$who    = lg_events_whoami();
$authed = ($who['authenticated'] ?? false) === true;
$ctx    = [
    'authenticated' => $authed,
    'tier'          => (string)($who['tier'] ?? 'public'),
    'display_name'  => (string)($who['display_name'] ?? ''),
    'avatar_url'    => $who['avatar_url'] ?? null,
    'capabilities'  => (array)($who['capabilities'] ?? []),
    'msg_unread'    => null,
    'notif_unread'  => null,
    'logo_url'      => LG_EVENTS_LOGO,
    // Viewer's public profile (convergence doc); $who['slug'] is the user's, NOT the digest $slug above.
    'profile_url'   => !empty($who['slug']) ? '/u/' . rawurlencode((string)$who['slug']) : '/profile/edit',
    'active_nav'    => 'hub',
    'logout_url'    => $authed ? '/wp-login.php?action=logout' : null,
];

$db     = lg_events_db();
$issue  = null;
$issues = [];
$title  = 'Weekly Digest — The Looth Group';

/* VIS-1 (Ian 6/12 pm): the digest is PUBLIC — logged-out visitors see the
   email view too; the gates live on the click-throughs (tiered posts, hub,
   event zoom). Anon serves get the forum-author bylines masked (discussion-
   identity rule). */
$emailHtml = '';
if ($slug === '' && !$showAll) $slug = lg_weekly_latest_slug($db);   // default = CURRENT issue (Ian 6/12)
if ($slug !== '') {
    $issue = lg_weekly_issue($db, $slug);
    if (!$issue) { http_response_code(404); }
    else {
        $title = (string)$issue['post']['post_title'] . ' — The Looth Group';
        $emailHtml = lg_weekly_campaign_html($db, $issue['data'], !$authed);
        // LEAD (current, unsent) issue has no sent FluentCRM body → render the
        // email on the fly from the issue's section data so the lead previews
        // as the real email instead of the plain web-card fallback (Ian 6/14).
        if ($emailHtml === '' && !empty($issue['data']['sections'])) {
            $emailHtml = lg_weekly_email_preview_html($slug, !$authed);
        }
    }
} else {
    $issues = lg_weekly_issues($db);
}

/* /weekly/<slug>/raw — the email document itself, verbatim, for the iframe.
   Same member gate; anon gets nothing. */
if ($rawMode) {
    if (!$issue || $emailHtml === '') { http_response_code(404); header('Content-Type: text/plain'); echo 'not found'; exit; }
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: private, max-age=300');
    echo $emailHtml;
    exit;
}

/** "May 25 – Jun 8" range line. */
$range = static function (string $from, string $to): string {
    $f = $from ? strtotime($from) : 0; $t = $to ? strtotime($to) : 0;
    if (!$f || !$t) return '';
    return date('M j', $f) . ' – ' . date('M j, Y', $t);
};
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($title) ?></title>
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<style>
/* Weekly digest — small, self-contained; tokens from site-header.css. */
.lg-wk{max-width:880px;margin:0 auto;padding:28px 20px 56px;font-family:var(--lg-font-sans)}
.lg-wk__head{font:800 28px/1.2 var(--lg-font-serif);color:var(--lg-charcoal);margin:0 0 4px}
.lg-wk__sub{color:#6b7163;margin:0 0 24px}
.lg-wk__issue{display:block;background:#fff;border:1px solid var(--lg-line);border-radius:14px;padding:18px 20px;margin:0 0 12px;text-decoration:none;color:inherit}
.lg-wk__issue:hover{border-color:var(--lg-sage)}
.lg-wk__issue h2{margin:0 0 2px;font:700 18px/1.3 var(--lg-font-serif);color:var(--lg-charcoal)}
.lg-wk__issue span{color:#8a9080;font-size:13px}
.lg-wk__sec-h{font:800 15px/1 var(--lg-font-sans);letter-spacing:.08em;text-transform:uppercase;color:var(--lg-rust);margin:34px 0 14px;padding-bottom:8px;border-bottom:2px solid var(--lg-line)}
.lg-wk__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px}
.lg-wk__card{display:block;background:#fff;border:1px solid var(--lg-line);border-radius:12px;overflow:hidden;text-decoration:none;color:inherit}
.lg-wk__card:hover{border-color:var(--lg-sage)}
.lg-wk__thumb{aspect-ratio:16/9;background:#e8e6df center/cover no-repeat}
.lg-wk__card h3{font:700 15px/1.35 var(--lg-font-sans);color:var(--lg-charcoal);margin:10px 12px 4px}
.lg-wk__when,.lg-wk__type{display:block;margin:0 12px 10px;color:#8a9080;font-size:12.5px}
.lg-wk__rows .lg-wk__card{display:flex;align-items:center;gap:12px;padding:10px 14px}
.lg-wk__rows h3{margin:0;flex:1}
.lg-wk__rows .lg-wk__when{margin:0;white-space:nowrap}
.lg-wk__sponsor{outline:2px solid #f0c987;outline-offset:-2px}
.lg-wk__sponsor-tag{display:inline-block;background:#fdf0d8;color:#8a6326;font:800 10px/1 var(--lg-font-sans);letter-spacing:.06em;text-transform:uppercase;border-radius:4px;padding:3px 7px;margin:10px 12px 0}
.lg-wk__gate{max-width:480px;margin:60px auto;background:#fff;border:1px solid var(--lg-line);border-radius:16px;padding:34px;text-align:center}
.lg-wk__gate h1{font:800 22px/1.3 var(--lg-font-serif);color:var(--lg-charcoal);margin:0 0 10px}
.lg-wk__gate p{color:#6b7163;margin:0 0 20px}
.lg-wk__gate a{display:inline-block;background:var(--lg-sage);color:#fff;border-radius:999px;padding:11px 26px;font-weight:700;text-decoration:none}
.lg-wk__back{display:inline-block;margin:0 0 18px;color:#6b7163;text-decoration:none;font-size:14px}
.lg-wk__back:hover{color:var(--lg-charcoal)}
.lg-wk__mail{display:block;width:100%;border:1px solid var(--lg-line);border-radius:14px;background:#fff;height:1200px}
.lg-wk__sub-bar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:var(--lg-charcoal,#2f3128);border-radius:14px;padding:16px 18px;margin:0 0 18px}
.lg-wk__sub-txt{flex:1 1 240px;display:flex;flex-direction:column;gap:2px}
.lg-wk__sub-txt b{color:#fff;font-size:15px}
.lg-wk__sub-txt small{color:#b8bdac;font-size:12.5px}
.lg-wk__sub-bar input[type=email]{flex:1 1 220px;border:0;border-radius:999px;padding:11px 16px;font-size:14px}
.lg-wk__sub-bar button{border:0;border-radius:999px;background:var(--lg-sage,#87986a);color:#fff;font-weight:700;font-size:14px;padding:11px 22px;cursor:pointer}
.lg-wk__sub-bar button:hover{filter:brightness(1.06)}
.lg-wk__hp{position:absolute;left:-9999px;width:1px;height:1px;opacity:0}

/* ── Dark counterpart (Ian 6/14) ─────────────────────────────────────────────
   The card surfaces above hardcode a light bg (#fff / light placeholder) while
   their text follows the site-header --lg-* tokens that flip light in dark →
   titles washed out on white cards. Re-point ONLY the hardcoded-light surfaces
   to the dark card token; text already follows the tokens. Mirror archive-poc's
   two-gate pattern so it tracks the same user/OS theme switch:
     1. html[data-lguser-theme="dark"]  — explicit Dark pick (overlay stamps it)
     2. OS prefers-color-scheme: dark    — only while no theme attr is stamped
   --lguser-card / --lguser-line come from app-settings.js (fallbacks mirror it).
   .lg-wk__sponsor-tag is self-contained (own light bg + dark text) → legible on
   a dark card either way, left as-is. */
html[data-lguser-theme="dark"] .lg-wk__issue,
html[data-lguser-theme="dark"] .lg-wk__card,
html[data-lguser-theme="dark"] .lg-wk__gate,
html[data-lguser-theme="dark"] .lg-wk__mail{background:var(--lguser-card,#1e2124)}
html[data-lguser-theme="dark"] .lg-wk__thumb{background:var(--lguser-line,#2c312d)}
@media (prefers-color-scheme: dark){
  html:not([data-lguser-theme]) .lg-wk__issue,
  html:not([data-lguser-theme]) .lg-wk__card,
  html:not([data-lguser-theme]) .lg-wk__gate,
  html:not([data-lguser-theme]) .lg-wk__mail{background:var(--lguser-card,#1e2124)}
  html:not([data-lguser-theme]) .lg-wk__thumb{background:var(--lguser-line,#2c312d)}
}
</style>
</head>
<body class="lg-weekly-page">

<?php lg_shared_render_site_header($ctx); ?>

<main id="lg-main" class="lg-wk">
<?php if (!$authed): ?>
    <?php /* Logged-out signup (Ian 6/12): the digest is public to READ; this
             captures non-member emails into the CRM's non-member list with
             double opt-in. Members are auto-subscribed at signup — they never
             see this bar. */ ?>
    <form class="lg-wk__sub-bar" id="lg-wk-sub">
        <div class="lg-wk__sub-txt"><b>Get the Weekly Digest in your inbox</b>
            <small>Free — luthier events, new videos, and shop talk, every week.</small></div>
        <input type="text" name="website" class="lg-wk__hp" tabindex="-1" autocomplete="off" aria-hidden="true">
        <input type="email" name="email" required placeholder="you@example.com" aria-label="Email address">
        <button type="submit">Subscribe</button>
    </form>
    <script>
    (function(){var f=document.getElementById('lg-wk-sub');if(!f)return;
      f.addEventListener('submit',function(e){e.preventDefault();
        var b=f.querySelector('button');b.disabled=true;b.textContent='Subscribing\u2026';
        var data=new URLSearchParams();data.set('action','lg_weekly_signup');
        data.set('email',f.email.value);data.set('website',f.website.value);
        fetch('/wp-admin/admin-ajax.php',{method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/x-www-form-urlencoded'},body:data.toString()})
        .then(function(r){return r.json()}).then(function(j){
          if(j&&j.ok){f.innerHTML='<div class="lg-wk__sub-txt"><b>'+
            (j.state==='subscribed'?'You\u2019re subscribed \u2713':'Check your inbox \u2709')+'</b><small>'+
            (j.state==='subscribed'?'The next digest will land in your inbox.':'Click the confirmation link we just sent and you\u2019re in.')+'</small></div>';}
          else{b.disabled=false;b.textContent='Subscribe';alert(j&&j.error==='bad_email'?'That email doesn\u2019t look right.':'Could not subscribe \u2014 try again.');}
        }).catch(function(){b.disabled=false;b.textContent='Subscribe';});
      });})();
    </script>
<?php endif; ?>
<?php if ($slug !== '' && !$issue): ?>
    <a class="lg-wk__back" href="/weekly/all/">&larr; All digests</a>
    <h1 class="lg-wk__head">Not found</h1>
    <p class="lg-wk__sub">That digest doesn&rsquo;t exist (or isn&rsquo;t published).</p>

<?php elseif ($issue && $emailHtml !== ''): ?>
    <a class="lg-wk__back" href="/weekly/all/">&larr; All digests</a>
    <?php /* THE EMAIL, displayed as the email (Ian 6/12): the exact campaign
             HTML in an isolated iframe so its inline email CSS can't fight the
             site shell. Height syncs from the document inside. */ ?>
    <iframe class="lg-wk__mail" id="lg-wk-mail" src="/weekly/<?= $h($slug) ?>/raw" title="<?= $h((string)$issue['post']['post_title']) ?>"></iframe>
    <script>
    (function(){var f=document.getElementById('lg-wk-mail');if(!f)return;
      function fit(){try{var d=f.contentDocument;if(d&&d.body)f.style.height=Math.max(900,d.documentElement.scrollHeight)+'px';}catch(e){}}
      f.addEventListener('load',function(){fit();setTimeout(fit,400);setTimeout(fit,1500);});
      window.addEventListener('resize',fit);})();
    </script>

<?php elseif ($issue): ?>
    <a class="lg-wk__back" href="/weekly/all/">&larr; All digests</a>
    <h1 class="lg-wk__head"><?= $h((string)$issue['post']['post_title']) ?></h1>
    <p class="lg-wk__sub"><?= $h($range((string)($issue['data']['date_from'] ?? ''), (string)($issue['data']['date_to'] ?? ''))) ?></p>

    <?php
    // Resolve every referenced post in ONE query, then walk the sections.
    $allIds = [];
    foreach (($issue['data']['sections'] ?? []) as $s) {
        foreach ((array)($s['post_ids'] ?? []) as $pid) $allIds[] = (int)$pid;
    }
    $cards = lg_weekly_resolve($db, $allIds);

    foreach (($issue['data']['sections'] ?? []) as $s):
        $isHeader = !empty($s['is_header']);
        $label    = trim((string)($s['label'] ?? ''));
        if ($isHeader) {
            if ($label !== '') echo '<h2 class="lg-wk__sec-h">' . $h($label) . '</h2>';
            continue;
        }
        $tpl  = (string)($s['template'] ?? 'card');
        $rows = [];
        foreach ((array)($s['post_ids'] ?? []) as $pid) {
            if (isset($cards[(int)$pid])) $rows[] = $cards[(int)$pid];
        }
        // manual_items: {title,url} hand-entries from the composer — pass through.
        foreach ((array)($s['manual_items'] ?? []) as $mi) {
            if (!is_array($mi) || empty($mi['title'])) continue;
            $rows[] = ['title' => (string)$mi['title'], 'url' => (string)($mi['url'] ?? '#'),
                       'thumb' => '', 'when' => '', 'type' => '', 'excerpt' => ''];
        }
        if (!$rows) continue;
        if ($label !== '') echo '<h2 class="lg-wk__sec-h">' . $h($label) . '</h2>';

        if ($tpl === 'date-forward' || $tpl === 'forum'): ?>
            <div class="lg-wk__rows">
            <?php foreach ($rows as $r): ?>
                <a class="lg-wk__card" href="<?= $h((string)$r['url']) ?>">
                    <h3><?= $h((string)$r['title']) ?></h3>
                    <?php if ($r['when'] !== ''): ?><span class="lg-wk__when"><?= $h((string)$r['when']) ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
            </div>
        <?php else: /* card / sponsor grids */ ?>
            <div class="lg-wk__grid">
            <?php foreach ($rows as $r): ?>
                <a class="lg-wk__card<?= $tpl === 'sponsor' ? ' lg-wk__sponsor' : '' ?>" href="<?= $h((string)$r['url']) ?>">
                    <?php if ($r['thumb'] !== ''): ?><div class="lg-wk__thumb" style="background-image:url('<?= $h((string)$r['thumb']) ?>')"></div><?php endif; ?>
                    <?php if ($tpl === 'sponsor'): ?><span class="lg-wk__sponsor-tag">Sponsored</span><?php endif; ?>
                    <h3><?= $h((string)$r['title']) ?></h3>
                    <?php if ($r['when'] !== ''): ?><span class="lg-wk__when"><?= $h((string)$r['when']) ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
            </div>
        <?php endif;
    endforeach; ?>

<?php else: ?>
    <h1 class="lg-wk__head">Weekly Digest</h1>
    <p class="lg-wk__sub">Every issue of the members&rsquo; round-up — events, new videos, shop talk, and what the community is building.</p>
    <?php if (!$issues): ?>
        <p>No digests yet.</p>
    <?php else: foreach ($issues as $i): ?>
        <a class="lg-wk__issue" href="/weekly/<?= $h($i['slug']) ?>/">
            <h2><?= $h($i['title']) ?></h2>
            <span><?= $h($range($i['from'], $i['to']) ?: date('M j, Y', strtotime($i['date']))) ?></span>
        </a>
    <?php endforeach; endif; ?>
<?php endif; ?>
</main>

<?php lg_shared_render_site_footer(['logo_url' => LG_EVENTS_LOGO]); ?>

</body>
</html>
