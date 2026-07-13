<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_render.php';

use Looth\ProfileApp\Db;
use Looth\ProfileApp\Whoami;

looth_issue_bounce_if_needed();   // mint looth_id for logged-in WP users who land here without one

$qs = $_GET;
$insts  = (array)($qs['inst']  ?? []);
$skills = (array)($qs['skill'] ?? []);
$music  = (array)($qs['music'] ?? []);
$creds  = (array)($qs['cred']  ?? []);
$lat    = isset($qs['lat']) ? (float)$qs['lat']    : null;
$lng    = isset($qs['lng']) ? (float)$qs['lng']    : null;
$radius = isset($qs['radius']) ? (int)$qs['radius'] : 50;
$locTxt = (string)($qs['loc'] ?? '');
$sort   = ($qs['sort'] ?? 'joined_asc') === 'joined_desc' ? 'joined_desc' : 'joined_asc';

$pg = Db::pg();
// Only surface tags that at least one member actually uses — an option that
// matches zero members is noise. The EXISTS clauses mirror each filter's own
// match semantics below (instruments/skills count both the full list AND
// migrated highlights). A facet that comes back empty is dropped from the bar.
$cats = [
    'instruments' => $pg->query("SELECT id, slug, name FROM instrument_catalog ic WHERE active=true
        AND (EXISTS (SELECT 1 FROM profile_instruments pi WHERE pi.instrument_id = ic.id)
          OR EXISTS (SELECT 1 FROM profile_highlights h WHERE h.kind='instrument' AND h.ref_id = ic.id))
        ORDER BY sort_order, name")->fetchAll(),
    'skills'      => $pg->query("SELECT id, slug, name, category FROM skill_catalog sc WHERE active=true
        AND (EXISTS (SELECT 1 FROM profile_skills ps WHERE ps.skill_id = sc.id)
          OR EXISTS (SELECT 1 FROM profile_highlights h WHERE h.kind='skill' AND h.ref_id = sc.id))
        ORDER BY category, sort_order, name")->fetchAll(),
    'music'       => $pg->query("SELECT slug, name FROM genre_catalog gc WHERE active=true
        AND EXISTS (SELECT 1 FROM profile_genres pg WHERE pg.genre_id = gc.id)
        ORDER BY sort_order, name")->fetchAll(),
    'credentials' => $pg->query("SELECT id, slug, issuer, program, category FROM credential_catalog cc WHERE active=true
        AND EXISTS (SELECT 1 FROM profile_credentials pc WHERE pc.owner_type='profile' AND pc.catalog_id = cc.id)
        ORDER BY category, issuer, program")->fetchAll(),
];

// Catalog options for the multiselect search bars. The four facets mirror the
// member-profile taxonomy (instruments, skills, credentials, music/genres);
// keep them in lockstep when the profile taxo changes.
$msCatalogs = [
    'inst'  => array_map(fn($c) => ['v' => $c['slug'], 'l' => $c['name']], $cats['instruments']),
    'skill' => array_map(fn($c) => ['v' => $c['slug'], 'l' => $c['name']], $cats['skills']),
    'music' => array_map(fn($c) => ['v' => $c['slug'], 'l' => $c['name']], $cats['music']),
    'cred'  => array_map(fn($c) => ['v' => $c['slug'], 'l' => $c['issuer'] . ' — ' . $c['program']], $cats['credentials']),
];
$msSelected = [
    'inst'  => array_values(array_map('strval', $insts)),
    'skill' => array_values(array_map('strval', $skills)),
    'music' => array_values(array_map('strval', $music)),
    'cred'  => array_values(array_map('strval', $creds)),
];

$placesKey = looth_places_key();
require_once '/srv/lg-shared/site-header.php';
require_once '/srv/lg-shared/site-footer.php';
$_whoami = Whoami::resolve();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Directory · Looth</title>
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<link rel="stylesheet" href="/profile/edit/edit.css">
<link rel="stylesheet" href="/profile/edit/directory.css?v=<?= @filemtime(__DIR__ . '/directory.css') ?: '1' ?>">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" crossorigin="">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js" crossorigin=""></script>
</head>
<body>
<?php
lg_shared_render_site_header([
    'logo_url'      => LG_PROFILE_APP_LOGO_URL,
    'authenticated' => (bool)($_whoami['authenticated'] ?? false),
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
    'logout_url'    => ($_whoami['authenticated'] ?? false) ? '/logout' : null,   // one-click endpoint, no WP interstitial (GH #55)
]);
?>
<div class="dir-header">Members <span class="dir-meta" id="dir-meta">loading…</span></div>
<div id="dir-map" class="dir-map" aria-hidden="true"></div>

<div class="dir-filterbar" id="dir-filterbar">
  <div class="filt loc">
    <span class="flab">Location</span>
    <input type="text" id="dir-loc" placeholder="Start typing a city…" value="<?= htmlspecialchars($locTxt, ENT_QUOTES) ?>">
    <input type="hidden" id="dir-lat" value="<?= $lat !== null ? htmlspecialchars((string)$lat, ENT_QUOTES) : '' ?>">
    <input type="hidden" id="dir-lng" value="<?= $lng !== null ? htmlspecialchars((string)$lng, ENT_QUOTES) : '' ?>">
  </div>
  <div class="filt radiusbox">
    <span class="flab">Radius</span>
    <select id="dir-radius">
      <?php foreach ([10,25,50,100,250] as $r): ?>
      <option value="<?= $r ?>" <?= $radius===$r?'selected':'' ?>>within <?= $r ?> mi</option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if (!($_whoami['authenticated'] ?? false)): ?>
  <div class="filt viewbox">
    <span class="flab">Show</span>
    <select id="dir-view">
      <option value="all">All members</option>
      <option value="visible">Visible profiles</option>
    </select>
  </div>
  <?php endif; ?>
  <?php if ($msCatalogs['inst']): ?><div class="filt"><span class="flab">Instruments</span><div class="ms" data-ms="inst" data-ph="Any instrument…"></div></div><?php endif; ?>
  <?php if ($msCatalogs['skill']): ?><div class="filt"><span class="flab">Skills</span><div class="ms" data-ms="skill" data-ph="Any skill…"></div></div><?php endif; ?>
  <?php if ($msCatalogs['music']): ?><div class="filt"><span class="flab">Music</span><div class="ms" data-ms="music" data-ph="Any genre…"></div></div><?php endif; ?>
  <?php if ($msCatalogs['cred']): ?><div class="filt"><span class="flab">Credentials</span><div class="ms" data-ms="cred" data-ph="Any credential…"></div></div><?php endif; ?>
  <div class="filt sortbox">
    <span class="flab">Joined</span>
    <div class="dir-sort" id="dir-sort" role="group" aria-label="Sort by join date">
      <button type="button" data-sort="joined_asc"  class="<?= $sort==='joined_asc'?'on':'' ?>">Oldest</button>
      <button type="button" data-sort="joined_desc" class="<?= $sort==='joined_desc'?'on':'' ?>">Newest</button>
    </div>
  </div>
</div>

<div class="dir-app">
  <main>
    <div class="dir-results" id="dir-results"></div>
    <button class="btn dir-load-more" id="dir-more" hidden>Load more</button>
  </main>
</div>

<script>
const CATALOGS = <?= json_encode($msCatalogs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const state = {
  inst:  <?= json_encode($msSelected['inst'],  JSON_UNESCAPED_SLASHES) ?>,
  skill: <?= json_encode($msSelected['skill'], JSON_UNESCAPED_SLASHES) ?>,
  music: <?= json_encode($msSelected['music'], JSON_UNESCAPED_SLASHES) ?>,
  cred:  <?= json_encode($msSelected['cred'],  JSON_UNESCAPED_SLASHES) ?>,
};
let curPage = 1;
let curSort = <?= json_encode($sort) ?>;
const DIR_ME_SLUG = <?= json_encode($_whoami['slug'] ?? null, JSON_UNESCAPED_SLASHES) ?>;

function escH(s){ return (s||'').toString().replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

// Filter-only query (no page) — shared by the list and the map-pin feed.
function filterQs() {
  const sp = new URLSearchParams();
  ['inst','skill','music','cred'].forEach(k => state[k].forEach(v => sp.append(k + '[]', v)));
  const loc = document.getElementById('dir-loc').value.trim();
  const lat = document.getElementById('dir-lat').value;
  const lng = document.getElementById('dir-lng').value;
  if (loc) sp.set('loc', loc);
  if (lat && lng) { sp.set('lat', lat); sp.set('lng', lng); sp.set('radius', document.getElementById('dir-radius').value); }
  sp.set('sort', curSort);
  return sp;
}
function buildQs(page) { const sp = filterQs(); sp.set('page', page); return sp.toString(); }

// ---------- multiselect search bars ----------
function labelFor(name, val) { const o = CATALOGS[name].find(o => o.v === val); return o ? o.l : val; }
function renderChips(root, name) {
  const ctrl = root.querySelector('.ms-control');
  ctrl.querySelectorAll('.ms-chip').forEach(c => c.remove());
  const search = root.querySelector('.ms-search');
  state[name].forEach(v => {
    const chip = document.createElement('span');
    chip.className = 'ms-chip';
    chip.innerHTML = `<span>${escH(labelFor(name, v))}</span><button type="button" aria-label="remove">×</button>`;
    chip.querySelector('button').addEventListener('click', e => {
      e.stopPropagation();
      state[name] = state[name].filter(x => x !== v);
      renderChips(root, name); applyFilters();
    });
    ctrl.insertBefore(chip, search);
  });
}
function renderMenu(root, name) {
  const menu = root.querySelector('.ms-menu');
  const q = root.querySelector('.ms-search').value.trim().toLowerCase();
  const opts = CATALOGS[name].filter(o => !q || o.l.toLowerCase().includes(q));
  if (!opts.length) { menu.innerHTML = '<div class="ms-none">no matches</div>'; return; }
  menu.innerHTML = opts.map(o =>
    `<div class="ms-opt${state[name].includes(o.v) ? ' sel' : ''}" data-v="${escH(o.v)}">${escH(o.l)}</div>`).join('');
  menu.querySelectorAll('.ms-opt').forEach(el => el.addEventListener('mousedown', e => {
    e.preventDefault();
    const v = el.getAttribute('data-v');
    if (state[name].includes(v)) state[name] = state[name].filter(x => x !== v);
    else state[name].push(v);
    root.querySelector('.ms-search').value = '';
    renderChips(root, name); renderMenu(root, name); applyFilters();
  }));
}
function initMultiselect(root) {
  const name = root.getAttribute('data-ms');
  root.innerHTML =
    `<div class="ms-control"><input class="ms-search" type="text" placeholder="${escH(root.getAttribute('data-ph') || '')}"></div>` +
    `<div class="ms-menu" hidden></div>`;
  const ctrl = root.querySelector('.ms-control');
  const search = root.querySelector('.ms-search');
  const menu = root.querySelector('.ms-menu');
  const open = () => { ctrl.classList.add('open'); menu.hidden = false; renderMenu(root, name); };
  const close = () => { ctrl.classList.remove('open'); menu.hidden = true; };
  ctrl.addEventListener('click', () => { search.focus(); open(); });
  search.addEventListener('focus', open);
  search.addEventListener('input', () => renderMenu(root, name));
  document.addEventListener('click', e => { if (!root.contains(e.target)) close(); });
  renderChips(root, name);
}

// ---------- results + map ----------
// Social-link glyphs (mirror of looth_social_icon in _render_blocks.php — the directory
// API can't reach that PHP helper, so the icon set is duplicated here). 24x24 stroke.
const SOC_ICONS = (() => {
  const P = {
    web:'<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18a14 14 0 0 1 0-18z"/>',
    email:'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
    phone:'<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
    instagram:'<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r=".9" fill="currentColor"/>',
    x:'<path d="M4 4l16 16M20 4 4 20"/>',
    youtube:'<path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.42a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 12a29 29 0 0 0 .46 5.58 2.78 2.78 0 0 0 1.94 2C5.12 20 12 20 12 20s6.88 0 8.6-.42a2.78 2.78 0 0 0 1.94-2A29 29 0 0 0 23 12a29 29 0 0 0-.46-5.58z"/><path d="M10 9v6l5-3z" fill="currentColor"/>',
    facebook:'<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>',
    tiktok:'<path d="M9 12a4 4 0 1 0 4 4V4a5 5 0 0 0 5 5"/>',
    patreon:'<circle cx="9" cy="11" r="6"/><line x1="18" y1="3" x2="18" y2="21"/>',
    linktree:'<path d="M12 3v18"/><path d="m5 8 7-5 7 5"/><path d="m5 14 7 5 7-5"/>',
    bandcamp:'<path d="M4 18l4-12h12l-4 12z"/>',
  };
  const wrap = p => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${p}</svg>`;
  const m = {_fb: wrap('<circle cx="12" cy="12" r="9"/><path d="M8 12h8"/>')};
  Object.keys(P).forEach(k => m[k] = wrap(P[k]));
  return m;
})();
// Absolute outbound URL for a stored handle (mirror of looth_social_url).
function socUrl(kind, value) {
  const v = (value || '').trim();
  if (!v) return '';
  if (kind === 'email') return 'mailto:' + v;
  if (kind === 'phone') return 'tel:' + v.replace(/[^\d+]/g, '');
  if (/^https?:\/\//i.test(v)) return v;
  const h = v.replace(/^[@/]+/, '');
  switch (kind) {
    case 'web': return 'https://' + h;
    case 'instagram': return 'https://instagram.com/' + h;
    case 'x': return 'https://x.com/' + h;
    case 'youtube': return 'https://youtube.com/@' + h;
    case 'facebook': return 'https://facebook.com/' + h;
    case 'tiktok': return 'https://tiktok.com/@' + h;
    case 'patreon': return 'https://patreon.com/' + h;
    case 'linktree': return 'https://linktr.ee/' + h;
    case 'bandcamp': return h.indexOf('.') > -1 ? 'https://' + h : 'https://' + h + '.bandcamp.com';
    default: return 'https://' + h;
  }
}
// Avatar image -> initials fallback on load error (textContent = no XSS).
function dirAviFallback(img) {
  const d = document.createElement('div');
  d.className = 'avi-sm';
  d.textContent = img.getAttribute('data-ini') || '';
  img.replaceWith(d);
}
function renderResults(items, append) {
  const wrap = document.getElementById('dir-results');
  const html = items.map(it => {
    const ini = escH((it.display_name||'?').split(/\s+/).map(w=>w[0]).join('').slice(0,2).toUpperCase());
    const avi = it.avatar_url
      ? `<img class="avi-sm" src="${escH(it.avatar_url)}" alt="" loading="lazy" data-ini="${ini}" onerror="dirAviFallback(this)">`
      : `<div class="avi-sm">${ini}</div>`;
    const banner = it.banner_url
      ? `<div class="dir-card__banner"><img src="${escH(it.banner_url)}" alt=""></div>`
      : '';
    const links = (it.links||[]).map(l => {
      const href = socUrl(l.kind, l.value);
      if (!href) return '';
      return `<a class="dir-link" href="${escH(href)}" target="_blank" rel="noopener noreferrer" title="${escH(l.kind)}" aria-label="${escH(l.kind)}">${SOC_ICONS[l.kind] || SOC_ICONS._fb}</a>`;
    }).join('');
    // Connect button (logged-in viewers only; API omits it.connect for anon).
    const cx = it.connect || null;
    let connectBtn = '';
    if (cx && cx.state !== 'self' && cx.state !== 'blocked') {
      const LBL = {none:'Connect', pending_out:'Requested', pending_in:'Accept', accepted:'Connected'};
      const act = cx.state === 'none' ? 'request' : (cx.state === 'pending_in' ? 'accept' : '');
      const dis = (cx.state === 'pending_out' || cx.state === 'accepted') ? ' disabled' : '';
      connectBtn = `<button type="button" class="dir-connect dir-connect--${cx.state}" data-act="${act}" data-uuid="${escH(it.uuid||'')}" data-cid="${cx.id||''}"${dis}>${LBL[cx.state]||'Connect'}</button>`;
    }
    return `
    <div class="dir-card">
      <a class="dir-card__main" href="/u/${escH(it.slug)}" data-slug="${escH(it.slug)}">
        ${banner}
        <div class="row1">
          ${avi}
          <div><div class="name">${escH(it.display_name||'Member')}</div>
          ${it.location?.text?`<div class="loc-row">${escH(it.location.text)}${it.distance_mi!=null?` · ${it.distance_mi} mi`:''}</div>`:''}
          </div>
        </div>
        ${it.highlights?.length?`<div class="hl-chips">${it.highlights.map(h=>`<span class="hl">${escH(h.name)}</span>`).join('')}</div>`:''}
      </a>
      <div class="dir-card__foot">
        ${links?`<div class="dir-links">${links}</div>`:'<span class="dir-foot-sp"></span>'}
        ${connectBtn}
      </div>
    </div>`;
  }).join('');
  if (append) wrap.insertAdjacentHTML('beforeend', html);
  else wrap.innerHTML = html || '<div class="dir-empty">no members match. try widening filters.</div>';
}

// Connect button styling (brand tokens; injected once, scoped to .dir-connect).
(function () {
  if (document.getElementById('dir-connect-css')) return;
  var s = document.createElement('style');
  s.id = 'dir-connect-css';
  s.textContent =
    '.dir-card__foot{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:0 12px 12px}' +
    '.dir-connect{border:1px solid var(--lg-sage-d,#6b7c52);background:var(--lg-sage-d,#6b7c52);color:#fff;' +
    'font:600 13px/1 var(--lg-font-sans,system-ui,sans-serif);border-radius:999px;padding:8px 15px;cursor:pointer;flex:0 0 auto}' +
    '.dir-connect--pending_out,.dir-connect--accepted{background:#fff;color:var(--lg-sage-d,#6b7c52)}' +
    '.dir-connect--pending_in{background:var(--lg-amber,#ecb351);border-color:var(--lg-amber,#ecb351);color:#1a1d1a}' +
    '.dir-connect[disabled]{opacity:.7;cursor:default}';
  document.head.appendChild(s);
})();

// Fire the connect action against the existing connections endpoints, optimistic.
async function dirHandleConnect(btn) {
  const act = btn.dataset.act;
  if (!act || btn.disabled) return;
  btn.disabled = true;
  try {
    let res;
    if (act === 'request') {
      res = await fetch('/profile-api/v0/connections', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ addressee_uuid: btn.dataset.uuid })
      });
    } else if (act === 'accept') {
      res = await fetch('/profile-api/v0/connections/' + btn.dataset.cid, {
        method: 'PATCH', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'accept' })
      });
    }
    if (res && res.ok) {
      if (act === 'request') { btn.textContent = 'Requested'; btn.className = 'dir-connect dir-connect--pending_out'; }
      else { btn.textContent = 'Connected'; btn.className = 'dir-connect dir-connect--accepted'; }
      btn.dataset.act = '';
    } else {
      btn.disabled = false;   // let the user retry on failure
    }
  } catch (_) { btn.disabled = false; }
}

// A member card: first left-click zooms the map to that member's pin; clicking the
// same card again (now the active card) opens their profile. Members with no map pin
// (no Location block, or anonymized) just open the profile on the first click.
function zoomToMember(main) {
  const slug = main.dataset.slug;
  const href = main.getAttribute('href');
  const rec = pinMarkerBySlug[slug];
  if (!rec || dirActiveSlug === slug) { window.location = href; return; }
  dirActiveSlug = slug;
  document.querySelectorAll('.dir-card.is-active').forEach(c => c.classList.remove('is-active'));
  main.closest('.dir-card')?.classList.add('is-active');
  const mapEl = document.getElementById('dir-map');
  if (mapEl) mapEl.scrollIntoView({behavior: 'smooth', block: 'center'});
  collapseDropoffs();
  dirCluster.zoomToShowLayer(rec.marker, () => {
    dirMap.setView([rec.lat, rec.lng], Math.max(dirMap.getZoom(), 13), {animate: true});
    rec.marker.openPopup();
  });
}
document.addEventListener('DOMContentLoaded', () => {
  const wrap = document.getElementById('dir-results');
  if (!wrap) return;
  wrap.addEventListener('click', (e) => {
    const cbtn = e.target.closest('.dir-connect');
    if (cbtn) { e.preventDefault(); e.stopPropagation(); dirHandleConnect(cbtn); return; }
    const main = e.target.closest('.dir-card__main');
    if (!main) return;   // social-link icons live outside .dir-card__main -> open normally
    // Let ctrl/cmd/shift/alt/middle-click open the profile in a new tab as usual.
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
    e.preventDefault();
    zoomToMember(main);
  });
});

async function loadPage(page, append) {
  const res = await fetch('/profile-api/v0/directory/members?' + buildQs(page), {credentials:'include'});
  const d = await res.json();
  document.getElementById('dir-meta').textContent = `${d.total} member${d.total===1?'':'s'} matching`;
  renderResults(d.items || [], append);
  document.getElementById('dir-more').hidden = !d.has_more;
  curPage = page;
}

// All matching members' pins (not just the current page) — plotted on the map.
async function loadPins() {
  if (!dirMap) return;
  const sp = filterQs(); sp.set('pins', '1');
  const res = await fetch('/profile-api/v0/directory/members?' + sp.toString(), {credentials:'include'});
  const d = await res.json();
  plotPins(d.pins || []);
}

function applyFilters() {
  loadPage(1, false);
  loadPins();
  window.history.replaceState({}, '', '/directory/members?' + buildQs(1));
}

// Wire up controls.
document.querySelectorAll('.ms').forEach(initMultiselect);
document.getElementById('dir-radius').addEventListener('change', applyFilters);
document.getElementById('dir-more').addEventListener('click', () => loadPage(curPage + 1, true));
const viewSel = document.getElementById('dir-view');
if (viewSel) viewSel.addEventListener('change', () => { viewFilter = viewSel.value; plotPins(lastPins); });
document.querySelectorAll('#dir-sort button').forEach(btn => btn.addEventListener('click', () => {
  if (curSort === btn.dataset.sort) return;
  curSort = btn.dataset.sort;
  document.querySelectorAll('#dir-sort button').forEach(b => b.classList.toggle('on', b === btn));
  applyFilters();
}));

// Map setup — Leaflet + OpenStreetMap (no API key needed) + marker clustering.
let dirMap = null, dirCluster = null, lastPins = [], viewFilter = 'all';
const pinIcon = L.divIcon({
  className: '',
  html: '<div style="width:14px;height:14px;border-radius:50%;background:var(--lg-rust);border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4)"></div>',
  iconSize: [14, 14], iconAnchor: [7, 7], popupAnchor: [0, -10],
});
// Anonymized "hidden member" pin — muted/grey so it reads as locked at a glance.
const pinIconGated = L.divIcon({
  className: '',
  html: '<div style="width:13px;height:13px;border-radius:50%;background:#9a948a;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.35);opacity:.9"></div>',
  iconSize: [13, 13], iconAnchor: [6.5, 6.5], popupAnchor: [0, -10],
});
// Drop-off child pins (revealed when a member "collapsed" pin is clicked) — a distinct
// smaller teal dot so they read differently from the member's home pin.
const pinIconDropoff = L.divIcon({
  className: '',
  html: '<div style="width:11px;height:11px;border-radius:50%;background:#0d7a6f;border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,.35)"></div>',
  iconSize: [11, 11], iconAnchor: [5.5, 5.5], popupAnchor: [0, -9],
});
// A member pin that has drop-offs: same dot + a small count badge so it reads as a
// "collapsed" group you can expand.
function pinIconWithCount(n) {
  return L.divIcon({
    className: '',
    html: '<div style="position:relative;width:14px;height:14px">'
        + '<div style="width:14px;height:14px;border-radius:50%;background:var(--lg-rust);border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4)"></div>'
        + '<div style="position:absolute;top:-7px;left:9px;min-width:14px;height:14px;padding:0 3px;border-radius:8px;background:#0d7a6f;color:#fff;border:1.5px solid #fff;font:700 9px/13px system-ui,sans-serif;text-align:center;box-sizing:border-box">' + n + '</div>'
        + '</div>',
    iconSize: [14, 14], iconAnchor: [7, 7], popupAnchor: [0, -10],
  });
}
let dirChildLayer = null, expandedSlug = null;
let pinMarkerBySlug = {}, dirActiveSlug = null;
function collapseDropoffs() {
  if (dirChildLayer) dirChildLayer.clearLayers();
  expandedSlug = null;
}
function expandDropoffs(p) {
  if (!dirChildLayer) dirChildLayer = L.layerGroup().addTo(dirMap);
  collapseDropoffs();
  expandedSlug = p.slug;
  const pts = [[p.lat, p.lng]];
  (p.dropoffs || []).forEach(k => {
    if (k.lat == null || k.lng == null) return;
    const m = L.marker([k.lat, k.lng], {icon: pinIconDropoff})
      .bindPopup('<div style="font-weight:600;font-size:12px;color:#1f1d1a">' + escH(k.name || 'Drop-off') + '</div>'
        + '<div style="font-size:11px;color:#8a8478">' + escH(p.display_name) + '</div>');
    dirChildLayer.addLayer(m);
    pts.push([k.lat, k.lng]);
  });
  if (pts.length > 1) dirMap.fitBounds(pts, {padding: [48, 48], maxZoom: 14});
}
function initDirMap() {
  if (dirMap) return;
  dirMap = L.map('dir-map', {zoomControl: true, scrollWheelZoom: true}).setView([39, -98], 3);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    maxZoom: 18,
  }).addTo(dirMap);
  // Cluster many overlapping pins into counts; spiderfy on click at max zoom.
  dirCluster = L.markerClusterGroup({chunkedLoading: true, maxClusterRadius: 50, spiderfyOnMaxZoom: true, showCoverageOnHover: false});
  dirMap.addLayer(dirCluster);
  dirMap.on('click', collapseDropoffs);   // click empty map to collapse an expanded pin
  loadPins();
}
function plotPins(pins) {
  if (!dirMap) return;
  lastPins = pins;
  dirCluster.clearLayers();
  collapseDropoffs();
  pinMarkerBySlug = {};
  dirActiveSlug = null;
  const pts = [];
  pins.forEach(p => {
    if (p.lat == null || p.lng == null) return;
    if (p.gated && viewFilter === 'visible') return;   // "Visible profiles" filter hides anonymized pins
    let m;
    if (p.gated) {
      m = L.marker([p.lat, p.lng], {icon: pinIconGated})
        .bindPopup(`<div style="font-size:13px;color:#6b665e;max-width:190px">${escH(p.message)}</div>`
          + `<a href="/wp-login.php" style="font-size:12px;font-weight:600;color:var(--lg-rust);text-decoration:none">Sign in to view</a>`);
    } else {
      const hasKids = !!(p.dropoffs && p.dropoffs.length);
      const isMe = !!(DIR_ME_SLUG && p.slug === DIR_ME_SLUG);
      m = L.marker([p.lat, p.lng], {icon: hasKids ? pinIconWithCount(p.dropoffs.length) : pinIcon, title: p.display_name})
        .bindPopup(`<a href="/u/${escH(p.slug)}" style="font-weight:600;text-decoration:none;color:#1f1d1a">${escH(p.display_name)}</a>`
          + (p.text ? `<div style="font-size:12px;color:#8a8478">${escH(p.text)}</div>` : '')
          + (hasKids ? `<div style="margin-top:4px;font-size:11px;color:#0d7a6f;font-weight:600">${p.dropoffs.length} drop-off location${p.dropoffs.length===1?'':'s'} — click pin to show</div>` : ''));
      if (hasKids || isMe) {
        m.on('click', (ev) => {
          if (expandedSlug === p.slug) { collapseDropoffs(); return; }
          if (p.dropoffs && p.dropoffs.length) { expandDropoffs(p); return; }
          // Owner-self interim: the feed doesn't carry my drop-offs yet, but I own
          // them so I can read them directly — lets the owner preview the expansion
          // before the canonical feed change lands.
          if (isMe) {
            fetch('/profile-api/v0/me/dropoffs', {credentials:'include'})
              .then(r => r.json())
              .then(d => {
                const items = (d.items || d.dropoffs || []).filter(k => k.lat != null && k.lng != null);
                if (items.length) expandDropoffs(Object.assign({}, p, {dropoffs: items}));
              }).catch(()=>{});
          }
        });
      }
    }
    dirCluster.addLayer(m);
    if (!p.gated && p.slug) pinMarkerBySlug[p.slug] = {marker: m, lat: p.lat, lng: p.lng};
    pts.push([p.lat, p.lng]);
  });
  if (pts.length) dirMap.fitBounds(pts, {padding: [32, 32], maxZoom: 10});
}

// Initialize map + first list load.
document.addEventListener('DOMContentLoaded', () => { initDirMap(); loadPage(1, false); });

// Location autocomplete via OSM Nominatim (server-proxied /me/location/search) — replaces the
// broken Google Places widget. Fills #dir-lat/#dir-lng on pick, then applyFilters(). No API key.
(function () {
  const input = document.getElementById('dir-loc');
  if (!input) return;
  input.parentNode.style.position = 'relative';
  const box = document.createElement('div');
  box.style.cssText = 'position:absolute;z-index:1200;top:100%;left:0;right:0;background:#fff;border:1px solid #e2e0d6;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.14);max-height:260px;overflow:auto;margin-top:4px;display:none';
  input.parentNode.appendChild(box);
  let timer = null, lastQ = null;
  const close = () => { box.style.display = 'none'; };
  function pick(lat, lng, label) {
    document.getElementById('dir-lat').value = lat;
    document.getElementById('dir-lng').value = lng;
    if (label) input.value = label;
    close(); applyFilters();
  }
  function summarize(row) {
    const a = row.address || {};
    const city = a.city || a.town || a.village || a.hamlet || a.suburb;
    const parts = [city, a.state, a.country].filter(Boolean);
    return parts.length ? parts.join(', ') : (row.display_name || '').slice(0, 80);
  }
  function run() {
    const q = input.value.trim();
    if (q === lastQ) return; lastQ = q;
    if (q.length < 3) { close(); return; }
    // Directory is viewable by anon, so geocode client-side via Nominatim (CORS, no auth/key).
    fetch('https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=6&q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
      .then(r => r.ok ? r.json() : [])
      .then(rows => {
        if (input.value.trim() !== q) return;                 // stale
        const items = (Array.isArray(rows) ? rows : []).map(row => ({ short: summarize(row), lat: row.lat, lng: row.lon, display_name: row.display_name }));
        box.innerHTML = '';
        if (!items.length) { close(); return; }
        items.slice(0, 6).forEach(it => {
          const b = document.createElement('button');
          b.type = 'button';
          b.style.cssText = 'display:block;width:100%;text-align:left;border:0;background:none;cursor:pointer;padding:9px 12px;font:600 13px/1.3 system-ui,sans-serif;color:#1f1d1a';
          b.textContent = it.short || it.display_name || '';
          b.addEventListener('mouseenter', () => { b.style.background = '#eef2ea'; });
          b.addEventListener('mouseleave', () => { b.style.background = 'none'; });
          b.addEventListener('click', () => pick(it.lat, it.lng, it.short || it.display_name));
          box.appendChild(b);
        });
        box.style.display = 'block';
      })
      .catch(close);
  }
  input.addEventListener('input', () => {
    document.getElementById('dir-lat').value = '';
    document.getElementById('dir-lng').value = '';            // typing invalidates the previous pin
    clearTimeout(timer); timer = setTimeout(run, 500);        // debounce (Nominatim ≤1 req/sec policy)
  });
  input.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
  document.addEventListener('click', e => { if (!box.contains(e.target) && e.target !== input) close(); });
})();
</script>
<?php lg_shared_render_site_footer(['logo_url' => LG_PROFILE_APP_LOGO_URL]); ?>
</body>
</html>
