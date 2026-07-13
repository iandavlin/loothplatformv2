<?php
declare(strict_types=1);

/**
 * /g/<slug> — the CHAPTER PAGE (dmv-native lane). Native: no BuddyBoss, no WordPress.
 *
 * Routed by platform/nginx/strangler-profile-app.conf:
 *   location ~ "^/g/([\w\-]+)/?$"  ->  SCRIPT_FILENAME g.php, QUERY_STRING slug=$1
 *
 * SERVER-RENDERED: the header, member count and the discussions list are real HTML on first
 * paint (SEO + no loading flash). The MAP and the join/compose interactions hydrate client-side.
 *
 * MOBILE/DESKTOP SPLIT (docs/atlas/MOBILE-DESKTOP-SPLIT.md): one document, TWO media-gated CSS
 * files — g.css (base + desktop) and g-mobile.css (<=640). Never merged.
 *
 * LOCATION PRIVACY: this page contains NO geo query. The map fetches the EXISTING clamped path
 * /profile-api/v0/directory/members?pins=1&chapter=<slug>, whose pins are already run through
 * Visibility::locationPrecision() + Block::locationDisplay(). We only draw the catchment circle
 * (public chapter metadata) and plot whatever clamped pins that endpoint chooses to return.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_render.php';                       // looth_h(), looth_issue_bounce_if_needed()
require_once LG_PROFILE_APP_APP_ROOT . '/src/Chapters.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/DiscoveryComments.php';   // Chapters::posts() -> reply counts

use Looth\ProfileApp\Chapters;
use Looth\ProfileApp\Whoami;

looth_issue_bounce_if_needed();   // mint looth_id for logged-in WP users who arrive without one

$slug = trim((string)($_GET['slug'] ?? ''));
$ch   = $slug !== '' ? Chapters::bySlug($slug) : null;

if (!$ch) {
    http_response_code(404);
    // Minimal, on-brand 404 — a chapter URL that does not resolve is a clean miss, not a crash.
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>No such chapter · Looth</title>'
       . '<body style="font:16px/1.5 system-ui;max-width:32rem;margin:12vh auto;padding:0 1.25rem;color:#1a1d1a">'
       . '<h1 style="font-size:1.4rem">No such chapter</h1>'
       . '<p>That chapter doesn’t exist (or is no longer active). '
       . '<a href="/directory/members" style="color:#3a6b3a">Browse members</a>.</p>';
    exit;
}

$cid       = (int)$ch['id'];
$_whoami   = Whoami::resolve();
$uuid      = $_whoami['user_uuid'] ?? null;
$authed    = (bool)($_whoami['authenticated'] ?? false);

$memberCount = Chapters::memberCount($cid);
$isMember    = Chapters::isMember($cid, $uuid);
$canPost     = $isMember;                          // read = anyone, post = members (recommended rule)
$posts       = Chapters::posts($cid, 30, 0);       // discussions, newest first (with reply counts)
$radiusMi    = Chapters::radiusMi($ch);

// Small avatar through the serve resizer (CLAUDE.md: never a raw upload). Square 40px ladder.
$avatar = static function (?string $url, string $name): string {
    $name = trim($name) !== '' ? $name : 'Member';
    if ($url === null || trim($url) === '') {
        $initial = mb_strtoupper(mb_substr($name, 0, 1));
        return '<span class="ch-av ch-av--none" aria-hidden="true">' . looth_h($initial) . '</span>';
    }
    $rz  = static fn(int $w): string => looth_h($url . (str_contains($url, '?') ? '&' : '?') . 'w=' . $w);
    $set = $rz(40) . ' 40w, ' . $rz(80) . ' 80w, ' . $rz(120) . ' 120w';
    return '<img class="ch-av" src="' . $rz(80) . '" srcset="' . $set . '" sizes="40px"'
         . ' width="40" height="40" alt="" loading="lazy" decoding="async">';
};

// Human date for a discussion row. Absolute + short; the modal shows the full thread.
$when = static function (?string $ts): string {
    if (!$ts) return '';
    $t = strtotime($ts);
    if ($t === false) return '';
    $now = time();
    $d   = $now - $t;
    if ($d < 60)     return 'just now';
    if ($d < 3600)   return (int)($d / 60) . 'm ago';
    if ($d < 86400)  return (int)($d / 3600) . 'h ago';
    if ($d < 604800) return (int)($d / 86400) . 'd ago';
    return date('M j', $t);
};

// A discussion's display title: an explicit title, else the first line of the body.
$titleOf = static function (array $p): string {
    $t = trim((string)($p['title'] ?? ''));
    if ($t !== '') return $t;
    $body = trim((string)($p['body'] ?? ''));
    $firstLine = strtok($body, "\n");
    return $firstLine !== false ? $firstLine : $body;
};

require_once '/srv/lg-shared/site-header.php';
require_once '/srv/lg-shared/site-footer.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= looth_h($ch['name']) ?> · Looth</title>
<meta name="description" content="<?= looth_h((string)($ch['description'] ?? '')) ?>">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<link rel="stylesheet" href="/profile/edit/g.css?v=<?= @filemtime(__DIR__ . '/g.css') ?: '1' ?>">
<link rel="stylesheet" media="(max-width:640px)" href="/profile/edit/g-mobile.css?v=<?= @filemtime(__DIR__ . '/g-mobile.css') ?: '1' ?>">
<?php /* Leaflet 1.9.4, vendored (webroot/lib/leaflet — monorepo law: no CDN runtime dep). */ ?>
<link rel="stylesheet" href="/lib/leaflet/leaflet.css">
<script src="/lib/leaflet/leaflet.js"></script>
</head>
<body class="ch-body <?= $authed ? '' : 'ch--anon' ?>">
<?php
lg_shared_render_site_header([
    'logo_url'      => LG_PROFILE_APP_LOGO_URL,
    'authenticated' => $authed,
    'tier'          => (string)($_whoami['tier'] ?? 'public'),
    'display_name'  => (string)($_whoami['display_name'] ?? ''),
    'avatar_url'    => $_whoami['avatar_url'] ?? null,
    'capabilities'  => (array)($_whoami['capabilities'] ?? []),
    'msg_unread'    => null,
    'notif_unread'  => null,
    'profile_url'   => isset($_whoami['slug']) && $_whoami['slug']
        ? '/u/' . rawurlencode((string)$_whoami['slug'])
        : '/profile/edit',
    'active_nav'    => 'members',
    'logout_url'    => $authed ? '/wp-login.php?action=logout' : null,
]);
?>

<main class="ch" id="ch"
      data-slug="<?= looth_h($ch['slug']) ?>"
      data-member="<?= $isMember ? '1' : '0' ?>"
      data-authed="<?= $authed ? '1' : '0' ?>"
      data-canpost="<?= $canPost ? '1' : '0' ?>"
      data-src-base="/profile-api/v0">

  <header class="ch-hero">
    <div class="ch-hero__in">
      <h1 class="ch-name"><?= looth_h($ch['name']) ?></h1>
      <?php if (trim((string)($ch['description'] ?? '')) !== ''): ?>
        <p class="ch-desc"><?= looth_h((string)$ch['description']) ?></p>
      <?php endif; ?>
      <div class="ch-meta">
        <span class="ch-count"><b id="ch-count"><?= $memberCount ?></b>
          <span id="ch-count-label"><?= $memberCount === 1 ? 'member' : 'members' ?></span></span>
        <button type="button" id="ch-join"
                class="ch-join <?= $isMember ? 'is-member' : '' ?>"
                aria-pressed="<?= $isMember ? 'true' : 'false' ?>">
          <?= $isMember ? 'Joined ✓' : 'Join' ?>
        </button>
      </div>
    </div>
  </header>

  <div class="ch-cols">
    <!-- MAP: catchment circle + clamped member pins (via the existing directory pins path). -->
    <section class="ch-panel ch-mapwrap" aria-label="Chapter map">
      <div id="ch-map"
           data-lat="<?= looth_h((string)$ch['center_lat']) ?>"
           data-lng="<?= looth_h((string)$ch['center_lng']) ?>"
           data-radius-mi="<?= $radiusMi ?>"></div>
      <p class="ch-map-note">Approximate locations. Members who hide their location aren’t pinned.</p>
    </section>

    <!-- DISCUSSIONS: the single chapter content surface. -->
    <section class="ch-panel ch-discuss" aria-label="Discussions">
      <div class="ch-discuss__head">
        <h2>Discussions</h2>
        <button type="button" id="ch-compose" class="ch-compose"<?= $canPost ? '' : ' hidden' ?>>Start a discussion</button>
      </div>

      <ul class="ch-list" id="ch-list"<?= $posts ? '' : ' hidden' ?>>
        <?php foreach ($posts as $p):
            $pid = (int)$p['id']; ?>
        <li class="ch-post" data-post-id="<?= $pid ?>">
          <button type="button" class="ch-post__open" data-post-id="<?= $pid ?>">
            <span class="ch-post__title"><?= looth_h($titleOf($p)) ?></span>
            <span class="ch-post__foot">
              <?= $avatar($p['author_avatar'] ?? null, (string)($p['author_name'] ?? '')) ?>
              <span class="ch-post__by"><?= looth_h((string)($p['author_name'] ?? 'Member')) ?></span>
              <span class="ch-post__dot">·</span>
              <span class="ch-post__when"><?= looth_h($when($p['created_at'] ?? null)) ?></span>
              <span class="ch-post__replies" data-count="<?= (int)($p['comment_count'] ?? 0) ?>">
                <?= (int)($p['comment_count'] ?? 0) ?> <?= ((int)($p['comment_count'] ?? 0)) === 1 ? 'reply' : 'replies' ?>
              </span>
            </span>
          </button>
        </li>
        <?php endforeach; ?>
      </ul>

      <?php
      /* Modal data island: the full body + author for each rendered discussion, so opening a
       * thread needs ONE fetch (the replies) instead of two. JSON_HEX_TAG closes the </script>
       * breakout; every value re-enters the DOM through the modal's JS escaper. */
      $island = [];
      foreach ($posts as $p) {
          $island[(string)(int)$p['id']] = [
              'title'  => trim((string)($p['title'] ?? '')),
              'body'   => (string)($p['body'] ?? ''),
              'author' => (string)($p['author_name'] ?? 'Member'),
              'slug'   => $p['author_slug'] ?? null,
              'avatar' => $p['author_avatar'] ?? null,
              'when'   => $when($p['created_at'] ?? null),
          ];
      }
      ?>
      <script type="application/json" id="ch-posts-data"><?=
          json_encode($island, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}'
      ?></script>

      <div class="ch-empty" id="ch-empty"<?= $posts ? ' hidden' : '' ?>>
        <p class="ch-empty__h">No discussions yet.</p>
        <p class="ch-empty__s"><?= $canPost
            ? 'Be the first — say hello, or post a meetup.'
            : ($authed ? 'Join the chapter to start one.' : 'Nothing here yet.') ?></p>
      </div>
    </section>
  </div>
</main>

<?php lg_shared_render_site_footer(); ?>

<script>
/* Chapter page client — inline so it ships with the PHP (no separate webroot copy step).
 * Three jobs: (1) draw the map, (2) one-tap join/leave, (3) open a discussion in the modal
 * (read the thread, and — for members — reply or start a new discussion). Every modal
 * endpoint derives from data-src-base, so the network layer is one swappable seam. */
(function () {
  'use strict';
  var root = document.getElementById('ch');
  if (!root) return;
  var slug = root.getAttribute('data-slug');

  // ── MAP ─────────────────────────────────────────────────────────────────────
  var mapEl = document.getElementById('ch-map');
  var lat = mapEl ? parseFloat(mapEl.getAttribute('data-lat')) : NaN;
  var lng = mapEl ? parseFloat(mapEl.getAttribute('data-lng')) : NaN;
  var radiusMi = mapEl ? parseFloat(mapEl.getAttribute('data-radius-mi')) : NaN;
  // NaN guard: a chapter row with a null/garbage center or radius must not throw and take
  // the whole page's JS down with it. If the geo is unusable we simply skip the map.
  if (mapEl && window.L && isFinite(lat) && isFinite(lng) && isFinite(radiusMi) && radiusMi > 0) {
    var radiusM = radiusMi * 1609.344;
    var map = L.map(mapEl, { scrollWheelZoom: false });
    // OSM tiles — the house source (webroot/practice-sheet.js, profile-sheet.js).
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19, attribution: '© OpenStreetMap'
    }).addTo(map);
    var circle = L.circle([lat, lng], {
      radius: radiusM, color: '#2f5a2f', weight: 2, fillColor: '#6f9e57', fillOpacity: 0.14
    }).addTo(map);
    // Fit to the catchment. Derive the bounds from the centre + diameter via LatLng.toBounds
    // — NOT circle.getBounds(): in the vendored Leaflet 1.9.4 (merge f7401fe dropped the CDN
    // for the vendored build) L.Circle.getBounds() dereferences the map projection and throws
    // "Cannot read properties of undefined (reading 'layerPointToLatLng')" when called before
    // the map has an initial view — which is exactly our order, since we set the FIRST view
    // from these very bounds. toBounds is pure geodesy on the centre, so it needs no view and
    // yields the identical box (radiusM in each direction). Once fitBounds sets the view the
    // circle projects and renders normally.
    map.fitBounds(L.latLng(lat, lng).toBounds(radiusM * 2), { padding: [24, 24] });

    // Clamped pins — the endpoint decides precision; we just plot what it returns.
    fetch('/profile-api/v0/directory/members?pins=1&chapter=' + encodeURIComponent(slug),
          { credentials: 'include' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || !d.pins || !d.pins.length) return;
        var icon = L.divIcon({ className: 'ch-pin', iconSize: [14, 14] });
        d.pins.forEach(function (p) {
          if (typeof p.lat !== 'number' || typeof p.lng !== 'number') return;
          var m = L.marker([p.lat, p.lng], { icon: icon }).addTo(map);
          var label = (p.display_name || 'Member');
          if (p.slug) label = '<a href="/u/' + encodeURIComponent(p.slug) + '">' +
                              label.replace(/[<>&]/g, '') + '</a>';
          m.bindPopup(label + (p.text ? '<br><span class="ch-pin-loc">' +
                      String(p.text).replace(/[<>&]/g, '') + '</span>' : ''));
        });
      })
      .catch(function () {});
  } else if (mapEl) {
    // Unusable geo (or no Leaflet): drop the whole map panel rather than leave a dead box.
    var wrap = mapEl.closest('.ch-mapwrap');
    if (wrap) wrap.hidden = true;
  }

  // ── JOIN / LEAVE (one tap) ──────────────────────────────────────────────────
  var joinBtn = document.getElementById('ch-join');
  var countEl = document.getElementById('ch-count');
  var countLbl = document.getElementById('ch-count-label');
  if (joinBtn) {
    // Member button reads "Joined ✓" at rest, "Leave" on hover — the swap makes the
    // one-tap-leave intent unambiguous without a menu.
    function setJoinLabel(hovering) {
      if (root.getAttribute('data-member') !== '1') { joinBtn.textContent = 'Join'; return; }
      joinBtn.textContent = hovering ? 'Leave' : 'Joined ✓';
    }
    joinBtn.addEventListener('mouseenter', function () { setJoinLabel(true); });
    joinBtn.addEventListener('mouseleave', function () { setJoinLabel(false); });
    setJoinLabel(false);
    joinBtn.addEventListener('click', function () {
      if (root.getAttribute('data-authed') !== '1') {
        window.location.assign('/wp-login.php?redirect_to=' +
          encodeURIComponent(window.location.pathname));
        return;
      }
      var isMember = root.getAttribute('data-member') === '1';
      var method = isMember ? 'DELETE' : 'POST';
      joinBtn.disabled = true;
      fetch('/profile-api/v0/chapters/' + encodeURIComponent(slug) + '/join',
            { method: method, credentials: 'include' })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
        .then(function (d) {
          var nowMember = !!d.is_member;
          root.setAttribute('data-member', nowMember ? '1' : '0');
          joinBtn.classList.toggle('is-member', nowMember);
          joinBtn.setAttribute('aria-pressed', nowMember ? 'true' : 'false');
          setJoinLabel(false);
          if (typeof d.member_count === 'number') {
            countEl.textContent = d.member_count;
            if (countLbl) countLbl.textContent = d.member_count === 1 ? 'member' : 'members';
          }
          // Membership gates composing.
          root.setAttribute('data-canpost', nowMember ? '1' : '0');
          var cmp = document.getElementById('ch-compose');
          if (cmp) cmp.hidden = !nowMember;
        })
        .catch(function () {})
        .then(function () { joinBtn.disabled = false; });
    });
  }

  // ── DISCUSSION MODAL ────────────────────────────────────────────────────────
  // Every endpoint derives from data-src-base — THE SEAM. In production it is
  // "/profile-api/v0"; a harness can repoint it at a fixtures dir and the whole modal
  // is exercised with zero network. The overlay is built once, lazily, on first open.
  var base = (root.getAttribute('data-src-base') || '/profile-api/v0').replace(/\/+$/, '');
  var postsById = {};
  try {
    var island = document.getElementById('ch-posts-data');
    if (island) postsById = JSON.parse(island.textContent || '{}') || {};
  } catch (e) { postsById = {}; }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  // Body text: escape first, THEN turn newlines into breaks. Plain text — no markdown.
  function bodyHtml(s) { return esc(s).replace(/\n/g, '<br>'); }

  // Avatar through the resizer (CLAUDE.md: never a raw upload).
  function avatarHtml(url, name) {
    name = (name && name.trim()) || 'Member';
    if (!url) return '<span class="ch-av ch-av--none" aria-hidden="true">' +
      esc(name.charAt(0).toUpperCase()) + '</span>';
    var rz = url + (url.indexOf('?') >= 0 ? '&' : '?') + 'w=';
    return '<img class="ch-av" src="' + esc(rz + '80') + '" srcset="' +
      esc(rz + '40') + ' 40w, ' + esc(rz + '80') + ' 80w, ' + esc(rz + '120') + ' 120w" ' +
      'sizes="40px" width="40" height="40" alt="" decoding="async">';
  }

  function canPost()  { return root.getAttribute('data-canpost') === '1'; }
  function isAuthed() { return root.getAttribute('data-authed') === '1'; }

  var modal, modalBody, lastFocus;
  function ensureModal() {
    if (modal) return;
    modal = document.createElement('div');
    modal.className = 'ch-modal';
    modal.hidden = true;
    modal.innerHTML =
      '<div class="ch-modal__scrim" data-close></div>' +
      '<div class="ch-modal__panel" role="dialog" aria-modal="true" aria-label="Discussion">' +
        '<button type="button" class="ch-modal__x" data-close aria-label="Close">✕</button>' +
        '<div class="ch-modal__body"></div>' +
      '</div>';
    document.body.appendChild(modal);
    modalBody = modal.querySelector('.ch-modal__body');
    modal.addEventListener('click', function (e) {
      if (e.target.hasAttribute('data-close')) closeModal();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal && !modal.hidden) closeModal();
    });
  }
  function openModal() {
    ensureModal();
    lastFocus = document.activeElement;
    modal.hidden = false;
    document.body.classList.add('ch-modal-open');
    var x = modal.querySelector('.ch-modal__x');
    if (x) x.focus();
  }
  function closeModal() {
    if (!modal || modal.hidden) return;
    modal.hidden = true;
    document.body.classList.remove('ch-modal-open');
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  // Keep a list row's reply count honest after replies change (set to the true thread size).
  function setReplyCount(pid, n) {
    var badge = document.querySelector('.ch-post[data-post-id="' + pid + '"] .ch-post__replies');
    if (!badge) return;
    badge.setAttribute('data-count', String(n));
    badge.textContent = n + ' ' + (n === 1 ? 'reply' : 'replies');
  }

  // ---- Open a discussion: header from the island, replies over the wire --------
  function threadEl()      { return modalBody.querySelector('.ch-thread'); }
  function setThread(html) { var t = threadEl(); if (t) { t.innerHTML = html; t.removeAttribute('aria-busy'); } }

  function commentHtml(c) {
    var a = c.author || {};
    return '<li class="ch-reply" data-comment-id="' + (c.id | 0) + '">' +
      avatarHtml(a.avatar_url, a.name) +
      '<div class="ch-reply__in"><span class="ch-reply__by">' +
        (a.slug ? '<a href="/u/' + encodeURIComponent(a.slug) + '">' + esc(a.name || 'Someone') + '</a>'
                : esc(a.name || 'Someone')) +
      '</span><div class="ch-reply__text">' + bodyHtml(c.body) + '</div></div></li>';
  }
  function renderThread(comments) {
    if (!comments.length) { setThread('<p class="ch-thread__note">No replies yet.</p>'); return; }
    setThread('<ul class="ch-replies">' + comments.map(commentHtml).join('') + '</ul>');
  }
  function fetchThread(pid) {
    return fetch(base + '/chapter-posts/' + encodeURIComponent(pid) + '/comments', { credentials: 'include' })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
      .then(function (d) {
        var cs = d && d.comments ? d.comments : [];
        renderThread(cs);
        setReplyCount(pid, cs.length);
        return cs;
      });
  }

  function replyBoxHtml() {
    if (canPost()) {
      return '<form class="ch-reply-form" id="ch-reply-form">' +
        '<textarea class="ch-reply-form__body" name="body" rows="2" placeholder="Write a reply…" required></textarea>' +
        '<button type="submit" class="ch-reply-form__send">Reply</button>' +
      '</form>';
    }
    if (isAuthed()) return '<p class="ch-reply-hint">Join the chapter to reply.</p>';
    return '<p class="ch-reply-hint"><a href="/wp-login.php?redirect_to=' +
      encodeURIComponent(location.pathname) + '">Sign in</a> to reply.</p>';
  }
  function wireReply(pid) {
    var form = modalBody.querySelector('#ch-reply-form');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var ta = form.querySelector('[name=body]');
      var body = (ta.value || '').trim();
      if (!body) return;
      var send = form.querySelector('.ch-reply-form__send');
      send.disabled = true;
      fetch(base + '/chapter-posts/' + encodeURIComponent(pid) + '/comments', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ body: body })
      })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
        // Re-fetch so the new reply renders with the server's stitched identity, not a guess.
        .then(function () { ta.value = ''; return fetchThread(pid); })
        .catch(function () {})
        .then(function () { send.disabled = false; });
    });
  }

  function openPost(pid) {
    var post = postsById[pid] || {};
    openModal();
    var head =
      '<div class="ch-modal__post">' +
        (post.title ? '<h3 class="ch-modal__title">' + esc(post.title) + '</h3>' : '') +
        '<div class="ch-modal__meta">' +
          avatarHtml(post.avatar, post.author) +
          '<span class="ch-modal__by">' +
            (post.slug ? '<a href="/u/' + encodeURIComponent(post.slug) + '">' + esc(post.author || 'Member') + '</a>'
                       : esc(post.author || 'Member')) +
          '</span><span class="ch-post__dot">·</span>' +
          '<span class="ch-modal__when">' + esc(post.when || '') + '</span>' +
        '</div>' +
        (post.body ? '<div class="ch-modal__text">' + bodyHtml(post.body) + '</div>' : '') +
      '</div>';
    modalBody.innerHTML = head +
      '<div class="ch-thread" aria-busy="true"><p class="ch-thread__note">Loading replies…</p></div>' +
      replyBoxHtml();
    wireReply(pid);
    fetchThread(pid).catch(function () {
      setThread('<p class="ch-thread__note ch-thread__note--err">Couldn’t load replies.</p>');
    });
  }

  // ---- Start a discussion ------------------------------------------------------
  function openCompose() {
    if (!canPost()) return;
    openModal();
    modalBody.innerHTML =
      '<h3 class="ch-modal__title">Start a discussion</h3>' +
      '<form class="ch-form" id="ch-compose-form">' +
        '<input class="ch-form__title" name="title" maxlength="140" placeholder="Title (optional)">' +
        '<textarea class="ch-form__body" name="body" rows="6" placeholder="What’s happening in the chapter?" required></textarea>' +
        '<div class="ch-form__foot"><button type="submit" class="ch-form__submit">Post discussion</button></div>' +
      '</form>';
    var form = modalBody.querySelector('#ch-compose-form');
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var body = form.querySelector('[name=body]').value.trim();
      if (!body) return;
      var title = form.querySelector('[name=title]').value.trim();
      var submit = form.querySelector('.ch-form__submit');
      submit.disabled = true;
      fetch(base + '/chapters/' + encodeURIComponent(slug) + '/posts', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ title: title || null, body: body })
      })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
        .then(function (d) {
          if (!d || !d.ok) throw d;
          // The new discussion is SERVER-rendered on reload — the same render that painted
          // the rest of the list, so there is no client/server drift. The list is short.
          location.reload();
        })
        .catch(function () { submit.disabled = false; });
    });
  }

  // ---- Wire the affordances ----------------------------------------------------
  var list = document.getElementById('ch-list');
  if (list) {
    list.addEventListener('click', function (e) {
      var btn = e.target.closest('.ch-post__open');
      if (btn) openPost(btn.getAttribute('data-post-id'));
    });
  }
  var compose = document.getElementById('ch-compose');
  if (compose) compose.addEventListener('click', openCompose);
})();
</script>
</body>
</html>
