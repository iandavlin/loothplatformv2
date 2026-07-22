<?php
declare(strict_types=1);

use Looth\ProfileApp\Practice;
use Looth\ProfileApp\Profile;

function looth_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function looth_initials(string $name): string {
    $name = trim($name) ?: '?';
    $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
    return strtoupper(substr(($parts[0] ?? '?'), 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}

function looth_social_glyph(string $kind): string {
    return ['instagram'=>'📷','youtube'=>'▶','bandcamp'=>'🎵','web'=>'🔗','email'=>'✉','phone'=>'📞','x'=>'𝕏','tiktok'=>'♪','facebook'=>'ƒ','patreon'=>'P'][$kind] ?? '🔗';
}

function looth_places_key(): string {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $u = posix_getpwuid(posix_geteuid())['name'] ?? 'profile-app';
        $pdo = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=' . LG_PROFILE_APP_MYSQL_DB,
            $u, '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $s = $pdo->prepare("SELECT option_value FROM wp_options WHERE option_name='wpgmza_google_maps_api_key'");
        $s->execute();
        $cached = (string)$s->fetchColumn();
    } catch (Throwable $e) { $cached = ''; }
    return $cached;
}

function looth_render_editor(array $profile, string $mode, string $role): void {
    $isOwner = ($mode === 'editor');
    $displayName = $profile['display_name'] ?: 'Member';
    $avi  = looth_h(looth_initials($displayName));
    $slug = looth_h($profile['slug']);
    $displayHtml = looth_h($displayName);
    $loc = $profile['location'];
    $aboutVis  = $profile['sections']['about']['visibility'] ?? 'members';
    $aboutText = $profile['sections']['about']['data']['text'] ?? '';
    $aboutActive = !empty($profile['sections']['about']);
    $locVis = $loc['visibility'] ?? 'members';
    $socials     = $profile['socials']     ?? [];
    $instruments = $profile['instruments'] ?? [];
    $skills      = $profile['skills']      ?? [];
    $scenes      = $profile['scenes']      ?? [];
    $credentials = $profile['credentials'] ?? [];
    $highlights  = $profile['highlights']  ?? [];
    $businessName = $profile['business_name'] ?? '';
    $userId       = (int)($profile['user_id'] ?? 0);
    $practices    = $userId ? Practice::forUser($userId) : [];

    // Section order: persisted, then known set.
    $known = Profile::knownSectionKeys();
    $order = [];
    foreach (($profile['section_order'] ?? []) as $k) if (in_array($k, $known, true) && !in_array($k, $order, true)) $order[] = $k;
    foreach ($known as $k) if (!in_array($k, $order, true)) $order[] = $k;

    $bootstrap = json_encode([
        'profile'   => $profile,
        'practices' => array_map(fn($p) => [
            'uuid'    => $p['uuid'],
            'slug'    => $p['slug'],
            'name'    => $p['name'],
            'tagline' => $p['tagline'],
            'about'   => $p['about'],
            'website' => $p['website'],
            'location_text' => $p['location_text'],
            'location_visibility' => $p['location_visibility'],
            'role'    => $p['role'],
        ], $practices),
        'placesKey' => $isOwner ? looth_places_key() : '',
        'apiBase'   => '/profile-api/v0',
        'mode'      => $mode,
        'role'      => $role,
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $sectionDefs = [
        'about' => [
            'title' => 'About', 'vis' => $aboutVis,
            'active' => $aboutActive ? 1 : 0,
            'empty'  => '<span class="add-link">+ Add your About</span> — a short intro for other members.',
            'body'   => '<p id="about-body">' . nl2br(looth_h($aboutText)) . '</p>',
        ],
        'instruments' => [
            'title' => 'Instruments', 'vis' => 'public',
            'active' => count($instruments) ? 1 : 0,
            'empty'  => '<span class="add-link">+ Add instruments you play / work on</span>.',
            'body'   => '<div class="chips" id="instruments-chips">'
                        . implode('', array_map(fn($i) => '<span class="chip">' . looth_h($i['name']) . '</span>', $instruments))
                        . '</div>',
        ],
        'skills' => [
            'title' => 'Skills', 'vis' => 'public',
            'active' => count($skills) ? 1 : 0,
            'empty'  => '<span class="add-link">+ Add skills</span> — fret leveling, refret, build, electronics, etc.',
            'body'   => '<div class="chips" id="skills-chips">'
                        . implode('', array_map(fn($s) => '<span class="chip">' . looth_h($s['name']) . ($s['note'] ? ' <em class="ink-mute">— ' . looth_h($s['note']) . '</em>' : '') . '</span>', $skills))
                        . '</div>',
        ],
        'credentials' => [
            'title' => 'Credentials', 'vis' => 'public',
            'active' => count($credentials) ? 1 : 0,
            'empty'  => '<span class="add-link">+ Add credentials</span> — schooling, certifications, warranty authorizations.',
            'body'   => '<ul class="cred-list" id="cred-list">'
                        . implode('', array_map(fn($c) =>
                            '<li><b>' . looth_h($c['raw_issuer']) . '</b> — ' . looth_h($c['raw_program'])
                            . ($c['expires_at'] ? ' <span class="ann">expires ' . looth_h($c['expires_at']) . '</span>' : '')
                            . '</li>', $credentials))
                        . '</ul>',
        ],
        'scenes' => [
            'title' => 'Scenes', 'vis' => 'public',
            'active' => count($scenes) ? 1 : 0,
            'empty'  => '<span class="add-link">+ Pick scenes you work in</span>.',
            'body'   => '<div class="chips" id="scenes-chips">'
                        . implode('', array_map(fn($s) => '<span class="chip">' . looth_h($s['name']) . '</span>', $scenes))
                        . '</div>',
        ],
        'practices' => [
            'title' => 'Practices', 'vis' => 'public',
            'active' => count($practices) ? 1 : 0,
            'empty'  => '<span class="add-link">+ Add a practice</span> — your repair shop, build shop, or touring work.',
            'body'   => '<div class="my-practices" id="my-practices">'
                        . implode('', array_map(function($p) {
                            $loc = $p['location_text'] ? ' <span class="ann">— ' . looth_h($p['location_text']) . '</span>' : '';
                            return '<div class="pr-item" data-uuid="' . looth_h($p['uuid']) . '">'
                                 . '<span class="pr-name"><a href="/p/' . looth_h($p['slug']) . '">' . looth_h($p['name']) . '</a></span>'
                                 . $loc
                                 . '<span class="ann">' . looth_h($p['role']) . '</span>'
                                 . '</div>';
                        }, $practices))
                        . '</div>',
        ],
    ];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $isOwner ? 'Edit your profile' : looth_h($displayName) ?> · Looth</title>
<link rel="stylesheet" href="/profile/edit/edit.css">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<script>window.LOOTH_BOOT = <?= $bootstrap ?>;</script>
</head>
<body class="<?= $isOwner ? 'mode-editor' : 'mode-view' ?>">
<?php require __DIR__ . '/_chrome.php'; ?>
<div class="app" id="lg-main">
  <aside class="rail" id="lg-rail">
    <button type="button" class="rail-close" id="lg-rail-close" aria-label="Close sections menu">✕</button>
    <h6>Profile</h6>
    <?php foreach ($order as $k): ?>
      <div class="tab <?= $sectionDefs[$k]['active'] ? 'on' : '' ?>" data-anchor="<?= looth_h($k) ?>"><span class="dot"></span><?= looth_h($sectionDefs[$k]['title']) ?></div>
    <?php endforeach; ?>
    <div class="rail-foot">slice 2 · catalogs + directory</div>
  </aside>
  <div class="rail-backdrop" id="lg-rail-backdrop"></div>
  <main class="main">
    <div class="topbar">
      <button type="button" class="rail-toggle" id="lg-rail-toggle" aria-controls="lg-rail" aria-expanded="false" aria-label="Open sections menu"><span class="bars" aria-hidden="true">☰</span> Sections</button>
      <div class="crumbs">profile · <b><?= $slug ?></b></div>
      <?php if ($isOwner): ?>
        <div class="viewer"><span>👁 viewing as</span>
          <div class="seg" id="role">
            <button data-role="me"     class="<?= $role==='me'?'on':'' ?>">Me</button>
            <button data-role="member" class="<?= $role==='member'?'on':'' ?>">Member</button>
            <button data-role="public" class="<?= $role==='public'?'on':'' ?>">Public</button>
          </div>
          <span id="saveind" class="saveind"></span>
        </div>
      <?php else: ?>
        <div class="viewer"><a href="/profile/edit" class="btn">edit your own profile</a></div>
      <?php endif; ?>
    </div>
    <div class="canvas">
      <header class="idhead editable">
        <div class="avi-wrap editable">
          <div class="avi" id="avi-text"><?= $avi ?></div>
          <?php if ($isOwner): ?><button class="pencil" data-modal="avatar" title="Edit avatar">✎</button><?php endif; ?>
        </div>
        <div class="id-block">
          <div class="id-name">
            <span class="hov">
              <h1 id="disp-name"><?= $displayHtml ?></h1>
              <?php if ($isOwner): ?><button class="pencil" data-modal="name" title="Edit name">✎</button><?php endif; ?>
            </span>
            <div class="biz" id="disp-biz" <?= $businessName === '' ? 'hidden' : '' ?>><?= looth_h($businessName) ?></div>
          </div>
          <div class="id-meta">
            <span class="hov">
              <?php $hasLoc = !empty($loc['text']); ?>
              <?php if ($hasLoc): ?>
                <span class="loc" id="disp-loc"><?= looth_h($loc['text']) ?></span>
              <?php elseif ($isOwner): ?>
                <span class="loc loc-empty" id="disp-loc" data-modal="location"><em class="add-link">+ add your location</em></span>
              <?php else: ?>
                <span class="loc loc-empty" id="disp-loc"></span>
              <?php endif; ?>
              <?php if ($isOwner): ?><button class="pencil" data-modal="location" title="Edit location">✎</button><?php endif; ?>
            </span>
            <?php if (!empty($profile['member_since'])): ?>
              <span class="since">member since <?= looth_h(substr($profile['member_since'], 0, 4)) ?></span>
            <?php endif; ?>
            <span class="live">active</span>
          </div>
          <div class="socials editable" id="socials-row">
            <?php foreach ($socials as $s): ?>
              <a href="#"><span class="glyph"><?= looth_h(looth_social_glyph($s['kind'])) ?></span><?= looth_h($s['value']) ?></a>
            <?php endforeach; ?>
            <?php if ($isOwner): ?><button class="pencil" data-modal="socials" title="Edit socials">✎</button><?php endif; ?>
          </div>
          <div class="highlights editable" id="highlights-row">
            <?php foreach ($highlights as $h): ?>
              <a class="hl" href="/directory/members?<?= $h['kind']==='instrument'?'inst':'skill' ?>=<?= looth_h($h['slug']) ?>"><?= looth_h($h['name']) ?></a>
            <?php endforeach; ?>
            <?php if (!$highlights && $isOwner): ?><span class="add-hl">+ pick up to 3 highlights</span><?php endif; ?>
            <?php if ($isOwner): ?><button class="pencil" data-modal="highlights" title="Edit highlights">✎</button><?php endif; ?>
          </div>
        </div>
      </header>

      <?php foreach ($order as $k): $def = $sectionDefs[$k]; ?>
        <section class="section" id="<?= looth_h($k) ?>" data-kind="<?= looth_h($k) ?>"
                 data-vis="<?= looth_h($def['vis']) ?>" data-active="<?= $def['active'] ?>"
                 data-modal="<?= looth_h($k) ?>">
          <h3>
            <?php if ($isOwner): ?><span class="grip" title="Drag to reorder">⋮⋮</span><?php endif; ?>
            <?= looth_h($def['title']) ?>
            <span class="vis" data-v="<?= looth_h($def['vis']) ?>">👁 <?= looth_h($def['vis']) ?></span>
            <?php if ($isOwner): ?><button class="pencil" data-modal="<?= looth_h($k) ?>" title="Edit <?= looth_h($def['title']) ?>">✎</button><?php endif; ?>
          </h3>
          <div class="body-empty"><?= $def['empty'] ?></div>
          <div class="body-real"><?= $def['body'] ?></div>
        </section>
      <?php endforeach; ?>
    </div>
  </main>
</div>

<?php if ($isOwner): ?>
<!-- ───── modals (slice 1.5 ones + slice 2 additions) ───── -->
<div class="backdrop" id="b-name"><div class="modal">
  <div class="modal-head"><h2>Edit name</h2><button class="close" data-close>✕</button></div>
  <div class="modal-body">
    <div class="field"><label>Display name</label>
      <input type="text" id="f-name" value="<?= $displayHtml ?>" maxlength="120"></div>
    <div class="field"><label>Business name (optional)</label>
      <input type="text" id="f-biz" value="<?= looth_h($businessName) ?>" maxlength="120" placeholder="Your shop, sole-prop business, or primary affiliation"></div>
  </div>
  <div class="modal-foot"><button class="btn" data-close>Cancel</button>
    <button class="btn btn-pri" data-save="name">Save</button></div></div></div>

<div class="backdrop" id="b-location"><div class="modal">
  <div class="modal-head"><h2>Edit location</h2><button class="close" data-close>✕</button></div>
  <div class="modal-body">
    <div class="field"><label>Location</label>
      <input type="text" id="f-loc" placeholder="Start typing a city or address…" value="<?= looth_h($loc['text'] ?? '') ?>" autocomplete="off">
      <div class="loc-picker typeahead" id="loc-picker" hidden></div>
      <div class="loc-empty-state" id="loc-empty-state" hidden>
        No matches — try a more general place.
        <button type="button" class="link-btn" id="loc-text-only">Save anyway as text only</button>
      </div>
    </div>
    <div class="field"><label>Who can see your location?</label>
      <?php
        $visLabels = [
          'members' => 'Just members (default)',
          'public'  => 'Everyone (public)',
          'private' => 'Nobody (private)',
        ];
        foreach ($visLabels as $v => $label):
      ?>
        <label class="loc-vis-row"><input type="radio" name="loc-vis" value="<?= $v ?>" <?= $locVis===$v?'checked':'' ?>> <?= looth_h($label) ?></label>
      <?php endforeach; ?>
    </div></div>
  <div class="modal-foot"><button class="btn" data-close>Close</button></div></div></div>

<div class="backdrop" id="b-socials"><div class="modal">
  <div class="modal-head"><h2>Edit socials</h2><button class="close" data-close>✕</button></div>
  <div class="modal-body"><div class="socials-edit" id="socials-edit"></div>
    <button class="add-row" id="add-social">+ add a social</button></div>
  <div class="modal-foot"><button class="btn" data-close>Cancel</button>
    <button class="btn btn-pri" data-save="socials">Save</button></div></div></div>

<div class="backdrop" id="b-about"><div class="modal">
  <div class="modal-head"><h2>Edit about</h2><button class="close" data-close>✕</button></div>
  <div class="modal-body">
    <div class="field"><label>About</label><textarea id="f-about"><?= looth_h($aboutText) ?></textarea></div>
    <div class="field"><label>Visible to</label>
      <select id="f-about-vis"><?php foreach ($visLabels as $v => $label): ?>
        <option value="<?= $v ?>" <?= $aboutVis===$v?'selected':'' ?>><?= looth_h($label) ?></option>
      <?php endforeach; ?></select>
      <p class="ink-mute" style="margin:6px 0 0;font-size:12px">Members-only by default. “Everyone (public)” also shows this to logged-out visitors; “Nobody” keeps it private to you.</p>
      </div></div>
  <div class="modal-foot"><button class="btn" data-close>Cancel</button>
    <button class="btn btn-pri" data-save="about">Save</button></div></div></div>

<div class="backdrop" id="b-avatar"><div class="modal">
  <div class="modal-head"><h2>Edit avatar</h2><button class="close" data-close>✕</button></div>
  <div class="modal-body"><p class="ink-mute" style="margin:0;font-size:13px">Avatar upload arrives in a later slice. Today the WordPress / BB avatar is used automatically.</p></div>
  <div class="modal-foot"><button class="btn" data-close>Close</button></div></div></div>

<!-- slice 2 modals -->
<div class="backdrop" id="b-instruments"><div class="modal">
  <div class="modal-head"><h2>Edit instruments</h2><button class="close" data-close>✕</button></div>
  <div class="modal-body"><p class="ink-mute" style="margin:0 0 10px;font-size:12.5px">Tick everything you play or work on.</p>
    <div class="picker-grid" id="inst-picker"></div></div>
  <div class="modal-foot"><button class="btn" data-close>Cancel</button>
    <button class="btn btn-pri" data-save="instruments">Save</button></div></div></div>

<div class="backdrop" id="b-skills"><div class="modal modal-wide">
  <div class="modal-head"><h2>Edit skills</h2><button class="close" data-close>✕</button></div>
  <div class="modal-body"><p class="ink-mute" style="margin:0 0 10px;font-size:12.5px">Tick skills, add a one-line note (specialty, certification, etc.) where useful.</p>
    <div class="picker-grouped" id="skill-picker"></div></div>
  <div class="modal-foot"><button class="btn" data-close>Cancel</button>
    <button class="btn btn-pri" data-save="skills">Save</button></div></div></div>

<div class="backdrop" id="b-scenes"><div class="modal">
  <div class="modal-head"><h2>Edit scenes</h2><button class="close" data-close>✕</button></div>
  <div class="modal-body"><div class="picker-pills" id="scene-picker"></div></div>
  <div class="modal-foot"><button class="btn" data-close>Cancel</button>
    <button class="btn btn-pri" data-save="scenes">Save</button></div></div></div>

<div class="backdrop" id="b-credentials"><div class="modal modal-wide">
  <div class="modal-head"><h2>Credentials</h2><button class="close" data-close>✕</button></div>
  <div class="modal-body">
    <div id="cred-existing"></div>
    <div class="cred-form">
      <h4 class="modal-subh">Add a credential</h4>
      <div class="field"><label>Search the catalog (or type your own)</label>
        <input type="text" id="cred-search" placeholder="Plek, Taylor authorized, Roberto-Venn…" autocomplete="off">
        <div class="typeahead" id="cred-typeahead" hidden></div></div>
      <div class="row"><div class="field" style="flex:1"><label>Issuer</label>
        <input type="text" id="cred-issuer"></div>
        <div class="field" style="flex:1"><label>Program</label>
        <input type="text" id="cred-program"></div></div>
      <div class="row"><div class="field" style="flex:1"><label>Issued</label>
        <input type="date" id="cred-issued"></div>
        <div class="field" style="flex:1"><label>Expires</label>
        <input type="date" id="cred-expires"></div>
        <div class="field" style="flex:0 0 110px"><label>Visible to</label>
        <select id="cred-vis"><?php foreach ($visLabels as $v => $label): ?><option value="<?= $v ?>" <?= $v==='members'?'selected':'' ?>><?= looth_h($label) ?></option><?php endforeach; ?></select></div></div>
      <input type="hidden" id="cred-catalog-id">
    </div>
  </div>
  <div class="modal-foot"><button class="btn" data-close>Close</button>
    <button class="btn btn-pri" data-save="credentials">Add credential</button></div></div></div>

<div class="backdrop" id="b-highlights"><div class="modal">
  <div class="modal-head"><h2>Header highlights — pick up to 3</h2><button class="close" data-close>✕</button></div>
  <div class="modal-body"><p class="ink-mute" style="margin:0 0 10px;font-size:12.5px">Choose the things you most want viewers to see first. Pulled from your instruments + skills.</p>
    <div class="picker-grouped" id="hl-picker"></div></div>
  <div class="modal-foot"><button class="btn" data-close>Cancel</button>
    <button class="btn btn-pri" data-save="highlights">Save</button></div></div></div>

<div class="backdrop" id="b-practices"><div class="modal modal-wide">
  <div class="modal-head"><h2>Manage practices</h2><button class="close" data-close>✕</button></div>
  <div class="modal-body">
    <div id="pr-list" class="my-practices"></div>
    <h4 class="modal-subh">Create a new practice</h4>
    <div class="field"><label>Name</label><input type="text" id="pr-new-name" maxlength="160" placeholder="Bench Test Guitars"></div>
    <div class="field"><label>Tagline (optional)</label><input type="text" id="pr-new-tag" maxlength="200"></div>
    <div class="field"><label>Location (optional)</label><input type="text" id="pr-new-loc" placeholder="City, region"></div>
    <button class="btn btn-pri" id="pr-create">+ Create practice</button>
  </div>
  <div class="modal-foot"><button class="btn" data-close>Close</button></div></div></div>

<div class="backdrop" id="b-practice-edit"><div class="modal modal-wide">
  <div class="modal-head"><h2>Edit practice</h2><button class="close" data-close>✕</button></div>
  <div class="modal-body">
    <input type="hidden" id="pe-uuid">
    <div class="field"><label>Name</label><input type="text" id="pe-name" maxlength="160"></div>
    <div class="field"><label>Tagline</label><input type="text" id="pe-tagline" maxlength="200"></div>
    <div class="field"><label>About</label><textarea id="pe-about"></textarea></div>
    <div class="field"><label>Website</label><input type="text" id="pe-website" placeholder="https://"></div>
    <div class="field"><label>Location (text)</label><input type="text" id="pe-loc"></div>
    <div class="field"><label>Location visibility</label>
      <select id="pe-loc-vis">
        <option value="public">Everyone (public)</option>
        <option value="members">Just members</option>
        <option value="private">Nobody (private)</option>
      </select>
    </div>
  </div>
  <div class="modal-foot">
    <button class="btn" data-close>Cancel</button>
    <button class="btn btn-pri" id="pe-save">Save</button>
  </div></div></div>

<?php endif; ?>

<?php lg_shared_render_site_footer(['logo_url' => LG_PROFILE_APP_LOGO_URL]); ?>
<script src="/profile/edit/edit.js"></script>
</body>
</html>
<?php
}

function looth_render_claim_interstitial(array $user): void {
    $name = looth_h($user['display_name'] ?: 'there');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Start your profile · Looth</title><link rel="stylesheet" href="/profile/edit/edit.css"></head>
<body class="interstitial"><div class="interstitial-card">
  <h1>Welcome, <?= $name ?></h1>
  <p>Your profile hasn't been started yet. One click and you're in — you can fill anything in later.</p>
  <p><button id="claim-btn" class="btn btn-pri">Start my profile →</button></p>
  <p style="font-size:12px;color:var(--ink-mute)">No data is sent until you click.</p>
</div>
<script>
document.getElementById('claim-btn').addEventListener('click', async () => {
  const btn = document.getElementById('claim-btn');
  btn.disabled = true; btn.textContent = 'starting…';
  try {
    const res = await fetch('/profile-api/v0/me/claim', {method:'POST', credentials:'include',
      headers:{'Content-Type':'application/json'}, body: JSON.stringify({via:'direct'})});
    if (res.ok) location.href = '/profile/edit?just_claimed=1';
    else { btn.disabled = false; btn.textContent = 'try again'; }
  } catch (e) { btn.disabled = false; btn.textContent = 'try again'; }
});
</script></body></html>
<?php
}

function looth_render_login_interstitial(string $back = '/profile/edit'): void {
    // Host from config (env-branched) — a hardcoded dev URL here would bounce
    // LIVE users to the dev box at cutover (found in the 6/12 deploy audit).
    $login = 'https://' . LG_PROFILE_APP_HOST . '/wp-login.php?redirect_to='
           . urlencode('https://' . LG_PROFILE_APP_HOST . $back);
?>
<!doctype html><html><head><meta charset="utf-8"><title>Sign in to edit · Looth</title>
<link rel="stylesheet" href="/profile/edit/edit.css"></head>
<body class="interstitial"><div class="interstitial-card">
  <h1>Sign in to edit your profile</h1>
  <?php /* GH #51 / HK-025: the old copy leaked dev jargon ("lives outside
     WordPress... bounce through the WP login") and the page offered no way
     back out. Plain member language + an escape hatch. */ ?>
  <p>Log in to your Looth Group account and you&rsquo;ll land right back here in the editor.</p>
  <p><a class="btn btn-pri" href="<?= looth_h($login) ?>">Sign in &rarr;</a></p>
  <p><a href="/" style="font-size:14px;color:inherit;opacity:.75">&larr; Back to Looth Group</a></p>
</div></body></html>
<?php
}
