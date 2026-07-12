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
      data-canpost="<?= $canPost ? '1' : '0' ?>">

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
 * Three jobs: (1) draw the map, (2) one-tap join/leave, (3) open a discussion in the modal.
 * The modal seam (data-src-base + native fragment endpoints) is wired in a follow-up; the
 * click handler is stubbed to a graceful fallback until then. */
(function () {
  'use strict';
  var root = document.getElementById('ch');
  if (!root) return;
  var slug = root.getAttribute('data-slug');

  // ── MAP ─────────────────────────────────────────────────────────────────────
  var mapEl = document.getElementById('ch-map');
  if (mapEl && window.L) {
    var lat = parseFloat(mapEl.getAttribute('data-lat'));
    var lng = parseFloat(mapEl.getAttribute('data-lng'));
    var radiusMi = parseFloat(mapEl.getAttribute('data-radius-mi'));
    var radiusM = radiusMi * 1609.344;
    var map = L.map(mapEl, { scrollWheelZoom: false });
    // OSM tiles — the house source (webroot/practice-sheet.js, profile-sheet.js).
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19, attribution: '© OpenStreetMap'
    }).addTo(map);
    var circle = L.circle([lat, lng], {
      radius: radiusM, color: '#3a6b3a', weight: 1.5, fillColor: '#6f9e57', fillOpacity: 0.08
    }).addTo(map);
    map.fitBounds(circle.getBounds(), { padding: [24, 24] });

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

  // ── OPEN A DISCUSSION (modal seam wired in the follow-up step) ──────────────
  var list = document.getElementById('ch-list');
  if (list) {
    list.addEventListener('click', function (e) {
      var btn = e.target.closest('.ch-post__open');
      if (!btn) return;
      var pid = btn.getAttribute('data-post-id');
      // Placeholder until the modal seam lands: deep-link that the modal will intercept.
      window.dispatchEvent(new CustomEvent('lg:open-chapter-post', { detail: { postId: pid, slug: slug } }));
    });
  }

  // Compose affordance — the composer branch is wired with the modal seam.
  var compose = document.getElementById('ch-compose');
  if (compose) {
    compose.addEventListener('click', function () {
      window.dispatchEvent(new CustomEvent('lg:compose-chapter-post', { detail: { slug: slug } }));
    });
  }
})();
</script>
</body>
</html>
