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
use Looth\ProfileApp\Visibility;
use Looth\ProfileApp\HtmlSanitize;

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

// ROSTER (CHAPTER-V2 ask 2): the first page of members, visibility-gated SERVER-SIDE inside
// Chapters::members() (ghost containment + master switch + header ceiling for THIS viewer). The
// rest lazy-loads client-side via /chapters/<slug>/members. memberCount above is the full
// population and can exceed what is listed (members-only/private-header members count but do not
// show to this viewer) — the same list-vs-count split the map already uses for hidden pins.
const CH_ROSTER_PAGE = 24;
$vArr   = Visibility::viewer();
// Fetch one extra to decide the "Show more" button without a second query, then trim to the page.
$roster = Chapters::members($cid, (int)$vArr['id'], (bool)$vArr['admin'], CH_ROSTER_PAGE + 1, 0);
$rosterHasMore = count($roster) > CH_ROSTER_PAGE;
if ($rosterHasMore) array_pop($roster);

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

    <!-- MEMBERS: the roster. Identity only (name+avatar->/u/); visibility is enforced SERVER-SIDE
         in Chapters::members() (ghost containment + master switch + header ceiling for THIS viewer).
         First page is server-rendered; the rest lazy-loads from /chapters/<slug>/members. -->
    <section class="ch-panel ch-members" id="ch-members" aria-label="Members">
      <div class="ch-members__head">
        <h2>Members</h2>
        <span class="ch-members__count" id="ch-members-count"><?= $memberCount ?></span>
      </div>
      <ul class="ch-roster" id="ch-roster"<?= $roster ? '' : ' hidden' ?>>
        <?php foreach ($roster as $m):
            $mslug = trim((string)($m['slug'] ?? ''));
            $mname = (string)($m['display_name'] ?? 'Member');
            $av    = $avatar($m['avatar_url'] ?? null, $mname); ?>
        <li class="ch-roster__item">
          <?php if ($mslug !== ''): ?>
          <a class="ch-roster__link" href="/u/<?= looth_h(rawurlencode($mslug)) ?>" title="<?= looth_h($mname) ?>">
            <?= $av ?><span class="ch-roster__name"><?= looth_h($mname) ?></span>
          </a>
          <?php else: /* no slug -> not linkable; show identity without a dead /u/ link */ ?>
          <span class="ch-roster__link ch-roster__link--nolink">
            <?= $av ?><span class="ch-roster__name"><?= looth_h($mname) ?></span>
          </span>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <p class="ch-members__empty" id="ch-members-empty"<?= $roster ? ' hidden' : '' ?>>No members yet — be the first to join.</p>
      <?php /* Full page returned => there may be more; the client pages the rest and hides on exhaustion. */ ?>
      <button type="button" id="ch-roster-more" class="ch-roster__more"<?= $rosterHasMore ? '' : ' hidden' ?>>Show more members</button>
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
          $ph = trim((string)($p['body_html'] ?? ''));
          $island[(string)(int)$p['id']] = [
              'title'  => trim((string)($p['title'] ?? '')),
              'body'   => (string)($p['body'] ?? ''),
              // body_html is SANITIZED on STORE; re-sanitize on RENDER too (idempotent) so the
              // client only ever injects allowlisted HTML — belt and braces. null => plain text.
              'body_html' => $ph !== '' ? HtmlSanitize::chapterHtml($ph) : null,
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
          // Reflect the viewer's own join/leave in the roster immediately.
          chRefreshRoster();
        })
        .catch(function () {})
        .then(function () { joinBtn.disabled = false; });
    });
  }

  // ── MEMBER ROSTER (CHAPTER-V2 ask 2) ────────────────────────────────────────
  // Page 1 is server-rendered (visibility already decided by Chapters::members); this only
  // pages the rest and refreshes page 1 when the viewer joins/leaves. The client plots what
  // the endpoint returns — it never decides who is visible.
  var rosterEl    = document.getElementById('ch-roster');
  var rosterMore  = document.getElementById('ch-roster-more');
  var rosterEmpty = document.getElementById('ch-members-empty');
  var rosterCount = document.getElementById('ch-members-count');
  var rosterBase  = (root.getAttribute('data-src-base') || '/profile-api/v0').replace(/\/+$/, '');
  var rosterPage  = 1;      // page 1 already in the DOM
  var rosterBusy  = false;

  function rosterAvatar(m, name) {
    if (m.avatar_url) {
      var img = document.createElement('img'), u = String(m.avatar_url),
          sep = u.indexOf('?') >= 0 ? '&' : '?';
      img.className = 'ch-av'; img.width = 40; img.height = 40; img.alt = '';
      img.loading = 'lazy'; img.decoding = 'async';
      img.src = u + sep + 'w=80';
      img.srcset = u+sep+'w=40 40w, ' + u+sep+'w=80 80w, ' + u+sep+'w=120 120w';
      img.sizes = '40px';
      return img;
    }
    var s = document.createElement('span');
    s.className = 'ch-av ch-av--none'; s.setAttribute('aria-hidden', 'true');
    s.textContent = (String(name).charAt(0) || 'M').toUpperCase();
    return s;
  }

  function rosterItem(m) {
    var li = document.createElement('li'); li.className = 'ch-roster__item';
    var slugv = (m.slug == null ? '' : String(m.slug)).trim();
    var name  = m.display_name || 'Member';
    var wrap  = document.createElement(slugv ? 'a' : 'span');
    wrap.className = 'ch-roster__link' + (slugv ? '' : ' ch-roster__link--nolink');
    if (slugv) { wrap.href = '/u/' + encodeURIComponent(slugv); wrap.title = name; }
    var nm = document.createElement('span'); nm.className = 'ch-roster__name'; nm.textContent = name;
    wrap.appendChild(rosterAvatar(m, name)); wrap.appendChild(nm);
    li.appendChild(wrap);
    return li;
  }

  function rosterFetch(page) {
    return fetch(rosterBase + '/chapters/' + encodeURIComponent(slug) + '/members?page=' + page,
                 { credentials: 'include' })
      .then(function (r) { return r.ok ? r.json() : null; });
  }

  // Hoisted so the join/leave handler above can call it. Reloads page 1 from scratch.
  function chRefreshRoster() {
    if (!rosterEl) return;
    rosterFetch(1).then(function (d) {
      if (!d) return;
      rosterEl.innerHTML = '';
      (d.members || []).forEach(function (m) { rosterEl.appendChild(rosterItem(m)); });
      var any = (d.members || []).length > 0;
      rosterEl.hidden = !any;
      if (rosterEmpty) rosterEmpty.hidden = any;
      rosterPage = 1;
      if (rosterMore) rosterMore.hidden = !d.has_more;
      if (rosterCount && typeof d.member_count === 'number') rosterCount.textContent = d.member_count;
    }).catch(function () {});
  }

  if (rosterMore) {
    rosterMore.addEventListener('click', function () {
      if (rosterBusy) return;
      rosterBusy = true; rosterMore.disabled = true;
      rosterFetch(rosterPage + 1).then(function (d) {
        if (d) {
          (d.members || []).forEach(function (m) { rosterEl.appendChild(rosterItem(m)); });
          rosterPage += 1;
          rosterMore.hidden = !d.has_more;
        }
      }).catch(function () {}).then(function () { rosterBusy = false; rosterMore.disabled = false; });
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
        // body_html is server-sanitized (store + render); safe to inject. Plain text falls back
        // to the escaping renderer. Never inject post.body (plaintext) as HTML.
        (post.body_html ? '<div class="ch-modal__text ch-rich">' + post.body_html + '</div>'
          : (post.body ? '<div class="ch-modal__text">' + bodyHtml(post.body) + '</div>' : '')) +
      '</div>';
    modalBody.innerHTML = head +
      '<div class="ch-thread" aria-busy="true"><p class="ch-thread__note">Loading replies…</p></div>' +
      replyBoxHtml();
    wireReply(pid);
    fetchThread(pid).catch(function () {
      setThread('<p class="ch-thread__note ch-thread__note--err">Couldn’t load replies.</p>');
    });
  }

  // ---- Quill (lazy, on compose intent only — never eager, never for anon) ------
  // Vendored (webroot/lib/quill, no CDN). Loaded once on first compose; if it fails the
  // composer degrades to the plain textarea (still fully functional, plain text).
  var quillP = null;
  function loadQuill() {
    if (quillP) return quillP;
    quillP = new Promise(function (resolve) {
      if (window.Quill) { resolve(window.Quill); return; }
      var css = document.createElement('link');
      css.rel = 'stylesheet'; css.href = '/lib/quill/quill.snow.css';
      document.head.appendChild(css);
      var s = document.createElement('script');
      s.src = '/lib/quill/quill.js';
      s.onload  = function () { resolve(window.Quill || null); };
      s.onerror = function () { resolve(null); };
      document.head.appendChild(s);
    });
    return quillP;
  }
  // The chapter toolbar — deliberately matches the sanitizer allowlist (no image/code-block):
  // headings, bold/italic/underline/strike, lists, blockquote, link, clear.
  var CH_QUILL_TOOLBAR = [
    [{ header: [2, 3, false] }],
    ['bold', 'italic', 'underline', 'strike'],
    ['blockquote'],
    [{ list: 'ordered' }, { list: 'bullet' }],
    ['link'],
    ['clean'],
  ];

  // ---- Start a discussion ------------------------------------------------------
  function openCompose() {
    if (!canPost()) return;
    openModal();
    modalBody.innerHTML =
      '<h3 class="ch-modal__title">Start a discussion</h3>' +
      '<form class="ch-form" id="ch-compose-form">' +
        '<input class="ch-form__title" name="title" maxlength="140" placeholder="Title (optional)">' +
        '<div class="ch-form__editor" id="ch-editor"></div>' +
        '<textarea class="ch-form__body" name="body" rows="6" placeholder="What’s happening in the chapter?" required></textarea>' +
        '<div class="ch-form__foot"><button type="submit" class="ch-form__submit" disabled>Post discussion</button></div>' +
      '</form>';
    var form   = modalBody.querySelector('#ch-compose-form');
    var ta     = form.querySelector('[name=body]');
    var submit = form.querySelector('.ch-form__submit');
    var editor = form.querySelector('#ch-editor');
    var quill  = null;

    loadQuill().then(function (Q) {
      if (Q && editor) {
        ta.hidden = true; ta.removeAttribute('required');   // Quill takes over; textarea is the fallback
        quill = new Q(editor, {
          theme: 'snow',
          placeholder: 'What’s happening in the chapter?',
          bounds: editor,
          modules: { toolbar: CH_QUILL_TOOLBAR },
        });
      } else if (editor) {
        editor.parentNode.removeChild(editor);              // no Quill -> plain textarea stays
      }
      submit.disabled = false;
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var title = form.querySelector('[name=title]').value.trim();
      var payload;
      if (quill) {
        var text = quill.getText().trim();                  // Quill appends a trailing \n
        if (!text) return;                                  // formatting-only / empty
        payload = { title: title || null, body: text, body_html: quill.root.innerHTML };
      } else {
        var body = ta.value.trim();
        if (!body) return;
        payload = { title: title || null, body: body };
      }
      submit.disabled = true;
      fetch(base + '/chapters/' + encodeURIComponent(slug) + '/posts', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
        .then(function (d) {
          if (!d || !d.ok) throw d;
          // The new discussion is SERVER-rendered on reload — the same render (and the same
          // sanitizer) that painted the rest of the list, so there is no client/server drift.
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
