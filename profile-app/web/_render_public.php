<?php
declare(strict_types=1);

use Looth\ProfileApp\Practice;
use Looth\ProfileApp\Profile;

/**
 * Public read renderer for /u/<slug>. Deliberately separate from
 * `_render.php` (the editor) so the live-look + modals path can never leak
 * pencils, grips, viewer-toggle, or inactive-section placeholders into an
 * anonymous response. Sections that the current viewer can't see are
 * OMITTED FROM THE HTML, not hidden via CSS.
 *
 * Inputs: a `Profile::renderForViewer($full, $role)` output (the role-
 * filtered shape) plus an indicator whether the location/socials had
 * member-only content that's been stripped.
 */

require_once __DIR__ . '/_render.php';  // looth_h, looth_initials, looth_social_glyph

function looth_render_public(array $rendered, string $viewerRole, int $userId): void {
    $displayName = $rendered['display_name'] ?: 'Member';
    $avi  = looth_h(looth_initials($displayName));
    $slug = looth_h($rendered['slug']);
    $displayHtml = looth_h($displayName);
    $loc = $rendered['location'];

    $hasLocation = !empty($loc['text']) && empty($loc['hidden']);

    // Section cards rendered only when there's content and the viewer can
    // see it. Practices placeholder NEVER appears on the public view.
    $sectionDefs = [];
    if (!empty($rendered['sections']['about']['data']['text'])) {
        $a = $rendered['sections']['about'];
        $sectionDefs['about'] = [
            'title' => 'About',
            'vis'   => $a['visibility'],
            'body'  => '<p>' . nl2br(looth_h($a['data']['text'] ?? '')) . '</p>',
        ];
    }
    if (!empty($rendered['instruments'])) {
        $sectionDefs['instruments'] = [
            'title' => 'Instruments', 'vis' => 'public',
            'body'  => '<div class="chips">'
                       . implode('', array_map(fn($i) => '<span class="chip">' . looth_h($i['name']) . '</span>', $rendered['instruments']))
                       . '</div>',
        ];
    }
    if (!empty($rendered['skills'])) {
        $sectionDefs['skills'] = [
            'title' => 'Skills', 'vis' => 'public',
            'body'  => '<div class="chips">'
                       . implode('', array_map(fn($s) =>
                           '<span class="chip">' . looth_h($s['name'])
                           . ($s['note'] ? ' <em class="ink-mute">— ' . looth_h($s['note']) . '</em>' : '')
                           . '</span>', $rendered['skills']))
                       . '</div>',
        ];
    }
    if (!empty($rendered['credentials'])) {
        $sectionDefs['credentials'] = [
            'title' => 'Credentials', 'vis' => 'public',
            'body'  => '<ul class="cred-list">'
                       . implode('', array_map(fn($c) =>
                           '<li><b>' . looth_h($c['raw_issuer']) . '</b> — ' . looth_h($c['raw_program'])
                           . ($c['expires_at'] ? ' <span class="ann">expires ' . looth_h($c['expires_at']) . '</span>' : '')
                           . '</li>', $rendered['credentials']))
                       . '</ul>',
        ];
    }
    if (!empty($rendered['scenes'])) {
        $sectionDefs['scenes'] = [
            'title' => 'Scenes', 'vis' => 'public',
            'body'  => '<div class="chips">'
                       . implode('', array_map(fn($s) => '<span class="chip">' . looth_h($s['name']) . '</span>', $rendered['scenes']))
                       . '</div>',
        ];
    }

    $practices = Practice::forUser($userId);
    if (!empty($practices)) {
        $rows = [];
        foreach ($practices as $p) {
            $loc = $p['location_text'] ? ' <span class="ann">— ' . looth_h($p['location_text']) . '</span>' : '';
            $rows[] = '<li><a class="practice-row" href="/p/' . looth_h($p['slug']) . '">'
                    . '<span class="avi-sm">' . looth_h(looth_initials($p['name'])) . '</span>'
                    . '<span class="practice-name">' . looth_h($p['name']) . '</span>'
                    . $loc
                    . '</a></li>';
        }
        $sectionDefs['practices'] = [
            'title' => 'Practices', 'vis' => 'public',
            'body'  => '<ul class="practice-list">' . implode('', $rows) . '</ul>',
        ];
    }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= looth_h($displayName) ?> · Looth</title>
<link rel="stylesheet" href="/profile/edit/edit.css">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
</head>
<body class="mode-view">
<?php require __DIR__ . '/_chrome.php'; ?>
<div class="public-app">
  <main class="main" id="lg-main">
    <div class="canvas">

      <header class="idhead">
        <div class="avi-wrap">
          <div class="avi"><?= $avi ?></div>
        </div>
        <div class="id-block">
          <div class="id-name">
            <h1><?= $displayHtml ?></h1>
            <?php if (!empty($rendered['business_name'])): ?>
              <div class="biz"><?= looth_h($rendered['business_name']) ?></div>
            <?php endif; ?>
          </div>
          <div class="id-meta">
            <?php if ($hasLocation): ?>
              <span class="loc"><?= looth_h($loc['text']) ?></span>
            <?php endif; ?>
            <?php if (!empty($rendered['member_since'])): ?>
              <span class="since">member since <?= looth_h(substr($rendered['member_since'], 0, 4)) ?></span>
            <?php endif; ?>
          </div>
          <?php if (!empty($rendered['socials'])): ?>
            <div class="socials">
              <?php foreach ($rendered['socials'] as $s): ?>
                <a href="#"><span class="glyph"><?= looth_h(looth_social_glyph($s['kind'])) ?></span><?= looth_h($s['value']) ?></a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if (!empty($rendered['highlights'])): ?>
            <div class="highlights">
              <?php foreach ($rendered['highlights'] as $h): ?>
                <a class="hl" href="/directory/members?<?= $h['kind']==='instrument'?'inst':'skill' ?>=<?= looth_h($h['slug']) ?>"><?= looth_h($h['name']) ?></a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </header>

      <?php foreach ($sectionDefs as $key => $def): ?>
        <section class="section public" id="<?= looth_h($key) ?>" data-vis="<?= looth_h($def['vis']) ?>">
          <h3><?= looth_h($def['title']) ?></h3>
          <div class="body-real"><?= $def['body'] ?></div>
        </section>
      <?php endforeach; ?>

      <a class="report-link" href="#" id="report-link">Report this profile</a>
    </div>
  </main>
</div>
<?php lg_shared_render_site_footer(['logo_url' => LG_PROFILE_APP_LOGO_URL]); ?>
<script>
document.getElementById('report-link').addEventListener('click', e => {
  e.preventDefault();
  const reason = prompt('Reason (short)?'); if (!reason) return;
  const body = prompt('Details? (optional)') || '';
  fetch('/profile-api/v0/reports', {method:'POST', credentials:'include',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({target_type:'profile', target_id: <?= $userId ?>, reason, body})})
    .then(r => r.json()).then(d => alert(d.ok ? 'Thanks — report logged.' : ('Error: ' + (d.error||'?'))));
});
</script>
</body>
</html>
<?php
}
