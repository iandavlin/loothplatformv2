<?php
declare(strict_types=1);

require_once __DIR__ . '/_render.php';  // looth_h, looth_initials

function looth_render_practice(array $rendered, array $members): void {
    $name = $rendered['name'] ?: 'Practice';
    $avi  = looth_h(looth_initials($name));
    $nameHtml = looth_h($name);
    $loc = $rendered['location'];
    $hasLocation = !empty($loc['text']) && empty($loc['hidden']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $nameHtml ?> · Looth</title>
<link rel="stylesheet" href="/profile/edit/edit.css">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
</head>
<body class="mode-view practice-view">
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
            <h1><?= $nameHtml ?></h1>
            <?php if (!empty($rendered['tagline'])): ?>
              <div class="biz"><?= looth_h($rendered['tagline']) ?></div>
            <?php endif; ?>
          </div>
          <div class="id-meta">
            <?php if ($hasLocation): ?>
              <span class="loc"><?= looth_h($loc['text']) ?></span>
            <?php endif; ?>
            <?php if (!empty($rendered['website'])): ?>
              <a class="loc" href="<?= looth_h($rendered['website']) ?>" rel="noopener noreferrer" target="_blank"><?= looth_h($rendered['website']) ?></a>
            <?php endif; ?>
          </div>
        </div>
      </header>

      <?php if (!empty($rendered['about'])): ?>
        <section class="section public" id="about" data-vis="public">
          <h3>About</h3>
          <div class="body-real"><p><?= nl2br(looth_h($rendered['about'])) ?></p></div>
        </section>
      <?php endif; ?>

      <?php if (!empty($members)): ?>
        <section class="section public" id="staff" data-vis="public">
          <h3>Staff</h3>
          <div class="body-real">
            <ul class="staff-list">
              <?php foreach ($members as $m): ?>
                <li>
                  <a class="staff-row" href="/u/<?= looth_h($m['slug']) ?>">
                    <span class="avi-sm"><?= looth_h(looth_initials($m['display_name'] ?: 'Member')) ?></span>
                    <span class="staff-name"><?= looth_h($m['display_name'] ?: 'Member') ?></span>
                    <?php if ($m['role'] === 'owner'): ?>
                      <span class="ann">owner</span>
                    <?php endif; ?>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </section>
      <?php endif; ?>
    </div>
  </main>
</div>
<?php lg_shared_render_site_footer(['logo_url' => LG_PROFILE_APP_LOGO_URL]); ?>
</body>
</html>
<?php
}
