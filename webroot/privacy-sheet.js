/* Looth — Privacy sheet (owner edit surface).
   Replaces the cramped .lg-viewas privacy controls + the per-block inline chips
   with ONE calm intro card at the top of edit profile that opens a pull-up sheet
   of per-section visibility SLIDERS (public / members / only-you), each with a
   plain-language description and a live "who can see this" sentence. Ported from
   Buck's _work/visibility-sandbox.html design.

   Surface gating: runs ONLY on the owner edit view (?view=me) — detected by the
   server-rendered .lg-shell--owner + .lg-pmp markup. That markup exists both in
   the desktop /u/?view=me page AND inside the mobile edit iframe (profile-sheet.js
   loads /u/?view=me; /pwa.js injects this layer there too), so this covers mobile
   AND desktop with no viewport gate. No-op everywhere else.

   Data flow — reuse, no new API: current values are read straight from the DOM the
   server already rendered (.lg-pmp[data-pmp-block][data-pmp-vis] + the header
   ceiling + .lg-disc-seg). Changes persist via the SAME endpoints the inline chips
   used (PATCH/PUT /profile-api/v0/me/<block>, key `visibility`; location uses
   location_visibility/location_exact_visibility; discussion uses
   PUT /me/discussion-visibility). We hide the old controls but keep them in the DOM
   as the source of truth + fallback. Server stays the validator + gate.

   Ceiling rule (live): a section can't be effectively MORE public than the whole
   profile (header). We mirror that exactly — you may still pick a more-open value,
   but the sheet shows the "limited by your profile" warning and the effective
   audience, identical to the inline chips' behaviour.

   PRO-GATE: the live profile-app does NOT pro-gate "Public" anywhere (every block
   offers public/members/private unconditionally). Buck's sandbox mocked a
   "Public needs Looth Pro" gate, but turning that on is the Ian-FINAL profile
   visibility-ceiling policy call. So PRO_GATE is OFF here to stay faithful to live
   behaviour — flip it on ONLY after Ian signs off. Do not invent the gate.

   Self-contained: injects its own <style> + nodes. No deps, no emoji. */
(function () {
  'use strict';
  if (window.__loothPrivacySheet) return;

  // ── Surface gate ──────────────────────────────────────────────────────────
  function isEditSurface() {
    return !!(document.querySelector('.lg-shell--owner') && document.querySelector('.lg-pmp'));
  }

  // ── Policy flags / vocab (mirror live profile-app) ──────────────────────────
  var PRO_GATE = false;                 // Ian-FINAL: keep OFF until signed off.
  var BASE = '/profile-api/v0';
  // block -> endpoint, method, payload key — VERBATIM from u.php's live pmp save
  // map. Only these blocks are persistable; a chip whose block isn't here (e.g.
  // 'resume', practice-* on /p/) gets no slider rather than a 404'ing one.
  var EP = {
    'header':          { url: BASE + '/me/header',              m: 'PATCH', k: 'visibility' },
    'craft':           { url: BASE + '/me/craft',               m: 'PATCH', k: 'visibility' },
    'about':           { url: BASE + '/me/about',               m: 'PATCH', k: 'visibility' },
    'skills':          { url: BASE + '/me/catalog/skills',      m: 'PUT',   k: 'visibility' },
    'services':        { url: BASE + '/me/catalog/services',    m: 'PUT',   k: 'visibility' },
    'instruments':     { url: BASE + '/me/catalog/instruments', m: 'PUT',   k: 'visibility' },
    'music':           { url: BASE + '/me/catalog/music',       m: 'PUT',   k: 'visibility' },
    'connect':         { url: BASE + '/me/connect',             m: 'PATCH', k: 'visibility' },
    'gallery':         { url: BASE + '/me/gallery',             m: 'PUT',   k: 'visibility' },
    'socials':         { url: BASE + '/me/socials',             m: 'PUT',   k: 'visibility' },
    'dropoffs':        { url: BASE + '/me/dropoffs',            m: 'PUT',   k: 'visibility' },
    'location-approx': { url: BASE + '/me/location',            m: 'PUT',   k: 'location_visibility' },
    'location-exact':  { url: BASE + '/me/location',            m: 'PUT',   k: 'location_exact_visibility' }
  };
  // Allowed option set per block (DB literals every endpoint accepts). All length-3,
  // so one slider geometry serves them all.
  var TIERS = {
    'location-exact': ['members', 'private', 'on_request'],
    '_default':       ['public', 'members', 'private']
  };
  function tiersFor(block) { return TIERS[block] || TIERS._default; }
  // Slider labels (Buck's sandbox wording) — value stays the DB literal.
  var LABEL = { 'public': 'Public', 'members': 'Members', 'private': 'Only you', 'on_request': 'On request' };
  // restrictiveness rank; on_request as restrictive as private for the ceiling clamp.
  var RANK = { 'public': 0, 'members': 1, 'private': 2, 'on_request': 2 };

  // Friendly name + one-line description per block (fallback: nearest heading text).
  var SECT = {
    'about':             { name: 'About',              desc: 'Your short bio at the top of your profile.' },
    'instruments':       { name: 'Instruments',        desc: 'The instruments you work on.' },
    'skills':            { name: 'Skills',             desc: 'What you specialize in.' },
    'services':          { name: 'Services',           desc: 'The services you offer.' },
    'music':             { name: 'Music',              desc: 'Your music and audio.' },
    'gallery':           { name: 'Gallery',            desc: 'Your photos and work samples.' },
    'connect':           { name: 'Connections',        desc: 'Who you’re connected with.' },
    'socials':           { name: 'Links',              desc: 'Your social and web links.' },
    'resume':            { name: 'Resume',             desc: 'Your resume link.' },
    'craft':             { name: 'Craft',              desc: 'Your craft details.' },
    'dropoffs':          { name: 'Drop-off locations', desc: 'Where customers can leave gear.' },
    'location-approx':   { name: 'Location (area)',    desc: 'Your general area shown on the map.' },
    'location-exact':    { name: 'Exact location',     desc: 'Your precise address.' },
    'practice-location': { name: 'Location',           desc: 'Where you work.' },
    'practice-hours':    { name: 'Hours',              desc: 'When you’re open.' },
    'practice-links':    { name: 'Links',              desc: 'Your business links.' }
  };

  var EYE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1.5 12S5.5 5 12 5s10.5 7 10.5 7-4 7-10.5 7S1.5 12 1.5 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  var WARN = '<svg viewBox="0 0 24 24" fill="none" stroke="#b5731f" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>';

  // ── State (read from DOM, mutated in the sheet, persisted in the background) ──
  var headerBlock = null;   // 'header' | 'practice-header'
  var state = {};           // block -> chosen DB-literal vis
  var rows = [];            // [{block, isHeader}]
  var discCur = null;       // 'public' | 'member' | null (no discussion control)

  function readModel() {
    state = {}; rows = []; headerBlock = null;
    var chips = Array.prototype.slice.call(document.querySelectorAll('.lg-pmp'));
    chips.forEach(function (c) {
      var b = c.getAttribute('data-pmp-block') || '';
      var v = c.getAttribute('data-pmp-vis') || 'members';
      if (!b || !EP[b] || state.hasOwnProperty(b)) return;   // only persistable blocks; de-dupe
      state[b] = v;
      if (/header$/.test(b)) { headerBlock = b; }
    });
    // Order: header (ceiling) first, then sections in DOM order.
    if (headerBlock) rows.push({ block: headerBlock, isHeader: true });
    chips.forEach(function (c) {
      var b = c.getAttribute('data-pmp-block') || '';
      if (!b || !EP[b] || /header$/.test(b)) return;
      if (rows.some(function (r) { return r.block === b; })) return;
      rows.push({ block: b, isHeader: false });
    });
    var seg = document.querySelector('.lg-disc-seg');
    discCur = seg ? (seg.getAttribute('data-disc-current') || 'public') : null;
  }

  function ceilingVis() { return headerBlock ? state[headerBlock] : null; }
  function sectName(block) {
    if (SECT[block]) return SECT[block].name;
    // fallback: nearest section heading
    var chip = document.querySelector('.lg-pmp[data-pmp-block="' + block + '"]');
    var h = chip && chip.closest('.lg-block') && chip.closest('.lg-block').querySelector('.lg-bh, h3, h2');
    return (h && h.textContent.trim()) || block;
  }
  function sectDesc(block) { return SECT[block] ? SECT[block].desc : ''; }

  // ── Who-can-see sentence for an effective vis ───────────────────────────────
  function sentence(vis, isSection) {
    var who = isSection ? 'this section' : 'your profile';
    if (vis === 'public')     return '<b>Anyone on the internet</b> can see ' + who + ' — even people without a Looth account.';
    if (vis === 'members')    return '<b>Signed-in Looth members</b> can see ' + who + '. The public web cannot.';
    if (vis === 'on_request') return 'Hidden until you <b>approve a request</b> to see ' + who + '.';
    if (!isSection) return '<b>Only you</b> can see your profile. You\u2019re removed from the directory, the map, and search until you switch back.';
    return '<b>Only you</b> can see ' + who + '. It never appears to anyone else, even other members.';
  }

  // ── Build / render ──────────────────────────────────────────────────────────
  function sliderHTML(block, isHeader) {
    var opts = tiersFor(block);
    var cur = state[block]; var idx = opts.indexOf(cur); if (idx < 0) idx = opts.indexOf('members'); if (idx < 0) idx = 1;
    var ceil = isHeader ? null : ceilingVis();
    var ceilRank = ceil ? RANK[ceil] : -1;
    // blocked stops: more public than the profile ceiling
    var blocked = opts.map(function (o) { return ceilRank >= 0 && RANK[o] < ceilRank; });
    var lastBlocked = -1; blocked.forEach(function (b, i) { if (b) lastBlocked = i; });
    var n = opts.length;                       // always 3
    var pos = idx / (n - 1);
    var html = '';
    html += '<div class="lpv-track">';
    html += '<div class="lpv-rail"></div>';
    if (lastBlocked >= 0) {
      var cw = lastBlocked / (n - 1);
      html += '<div class="lpv-ceil" style="left:14px;width:calc(' + (cw * 100) + '% - ' + (cw * 28) + 'px);"></div>';
    }
    html += '<div class="lpv-fill" style="width:calc(' + (pos * 100) + '% - ' + (pos * 28) + 'px);"></div>';
    html += '<div class="lpv-stops">' + opts.map(function (o, i) {
      return '<span class="lpv-stop' + (blocked[i] ? ' is-blocked' : '') + '" data-i="' + i + '"></span>';
    }).join('') + '</div>';
    html += '<div class="lpv-thumb" style="left:calc(14px + ' + pos + ' * (100% - 28px));"></div>';
    html += '</div>';
    html += '<div class="lpv-labels">' + opts.map(function (o, i) {
      return '<span data-i="' + i + '" class="' + (i === idx ? 'on' : '') + '">' + LABEL[o] +
        (PRO_GATE && o === 'public' ? ' <span class="lpv-pro">PRO</span>' : '') + '</span>';
    }).join('') + '</div>';
    // explanation
    var exp;
    if (!isHeader && RANK[cur] < ceilRank) {
      var capName = (ceil === 'members') ? 'members-only' : 'private';
      var seers = (ceil === 'members') ? 'members' : 'you';
      exp = '<div class="lpv-exp warn">' + WARN + '<div>You set this to <b>' + LABEL[cur] +
        '</b>, but your <b>profile is ' + capName + '</b> — so only ' + seers +
        ' actually see it. Raise <b>Profile visibility</b> to show it more widely.</div></div>';
    } else {
      exp = '<div class="lpv-exp">' + EYE + '<div>' + sentence(cur, !isHeader) + '</div></div>';
    }
    return html + exp;
  }

  function rowHTML(r) {
    var nm = r.isHeader ? 'Profile visibility' : sectName(r.block);
    var ds = r.isHeader ? 'This is the most anyone can see. Sections below can be more private, never more public.' : sectDesc(r.block);
    return '<div class="lpv-row' + (r.isHeader ? ' lpv-row--head' : '') + '" data-block="' + r.block + '">' +
      '<div class="lpv-name">' + nm + '</div>' +
      (ds ? '<div class="lpv-sub">' + ds + '</div>' : '') +
      '<div class="lpv-slider">' + sliderHTML(r.block, r.isHeader) + '</div>' +
      '</div>';
  }

  function discRowHTML() {
    if (!discCur) return '';
    var note = discCur === 'public'
      ? 'Your name &amp; avatar show on your discussion posts to everyone.'
      : 'Logged-out visitors see “private member” on your posts; signed-in members see you.';
    return '<div class="lpv-row lpv-row--disc">' +
      '<div class="lpv-name">Discussion posts</div>' +
      '<div class="lpv-sub">Who sees your identity on the posts you write in the Hub.</div>' +
      '<div class="lpv-seg" role="radiogroup">' +
        '<button type="button" data-disc="public" class="' + (discCur === 'public' ? 'on' : '') + '">Everyone</button>' +
        '<button type="button" data-disc="member" class="' + (discCur === 'member' ? 'on' : '') + '">Members only</button>' +
      '</div>' +
      '<div class="lpv-exp"><div>' + note + '</div></div>' +
      '</div>';
  }

  function renderSheet() {
    var body = sheet.querySelector('.lpv-body');
    var head = rows.filter(function (r) { return r.isHeader; }).map(rowHTML).join('');
    var secs = rows.filter(function (r) { return !r.isHeader; }).map(rowHTML).join('');
    body.innerHTML =
      head +
      (secs ? '<div class="lpv-divider">Per section</div>' + secs : '') +
      (discCur ? '<div class="lpv-divider">Discussion</div>' + discRowHTML() : '');
    wireRows();
  }

  // ── Persistence ─────────────────────────────────────────────────────────────
  function persist(block, vis) {
    var ep = EP[block];
    if (!ep) return Promise.resolve(true);
    var b = {}; b[ep.k] = vis;
    return fetch(ep.url, {
      method: ep.m, credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b)
    }).then(function (r) { return r.ok; }).catch(function () { return false; });
  }

  function setVis(block, vis, isHeader) {
    var prev = state[block];
    if (prev === vis) return;
    state[block] = vis;
    // keep the hidden source-of-truth chip in sync so any other reader sees it
    var chip = document.querySelector('.lg-pmp[data-pmp-block="' + block + '"]');
    if (chip) chip.setAttribute('data-pmp-vis', vis);
    renderSheet();   // header change re-derives every section's ceiling stripe
    persist(block, vis).then(function (ok) {
      if (!ok) { state[block] = prev; if (chip) chip.setAttribute('data-pmp-vis', prev); renderSheet(); toast('Couldn’t save — try again.'); }
    });
  }

  function setDisc(want) {
    if (want === discCur) return;
    var prev = discCur; discCur = want; renderSheet();
    fetch(BASE + '/me/discussion-visibility', {
      method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ discussion_visibility: want })
    }).then(function (r) { if (!r.ok) throw 0; var seg = document.querySelector('.lg-disc-seg'); if (seg) seg.setAttribute('data-disc-current', want); })
      .catch(function () { discCur = prev; renderSheet(); toast('Couldn’t update discussion posts.'); });
  }

  function wireRows() {
    sheet.querySelectorAll('.lpv-row').forEach(function (row) {
      var block = row.getAttribute('data-block');
      var isHeader = row.classList.contains('lpv-row--head');
      if (row.classList.contains('lpv-row--disc')) {
        row.querySelectorAll('[data-disc]').forEach(function (b) {
          b.addEventListener('click', function () { setDisc(b.getAttribute('data-disc')); });
        });
        return;
      }
      var opts = tiersFor(block);
      row.querySelectorAll('.lpv-stop,[data-i]').forEach(function (el) {
        el.addEventListener('click', function () {
          var i = parseInt(el.getAttribute('data-i'), 10); if (isNaN(i)) return;
          var vis = opts[i];
          if (PRO_GATE && vis === 'public') { /* gate hook — disabled by policy */ }
          setVis(block, vis, isHeader);
        });
      });
    });
  }

  // ── Toast ────────────────────────────────────────────────────────────────────
  var toastEl = null, toastT = 0;
  function toast(msg) {
    if (!toastEl) { toastEl = document.createElement('div'); toastEl.className = 'lpv-toast'; document.body.appendChild(toastEl); }
    toastEl.textContent = msg; toastEl.classList.add('show');
    clearTimeout(toastT); toastT = setTimeout(function () { toastEl.classList.remove('show'); }, 2600);
  }

  // ── Sheet open/close ──────────────────────────────────────────────────────────
  var sheet = null, backdrop = null;
  function openSheet() {
    readModel(); renderSheet();
    backdrop.classList.add('is-open'); sheet.classList.add('is-open');
    document.documentElement.style.overflow = 'hidden';
  }
  function closeSheet() {
    backdrop.classList.remove('is-open'); sheet.classList.remove('is-open');
    document.documentElement.style.overflow = '';
  }

  // ── Build DOM (card + sheet) ───────────────────────────────────────────────────
  function build() {
    injectStyles();

    // 1) hide the old controls (kept in DOM as source of truth)
    document.documentElement.setAttribute('data-lg-privacy', 'on');

    // 2) calm intro card at the top of edit profile. Insert at the TOP of the
    //    profile column (.lg-profile, grid-area:profile on desktop ≥1380) rather
    //    than as a raw .lg-shell child — the desktop owner layout is a named-area
    //    grid, so a bare child auto-places into the empty "spacer" cell (top-right).
    //    Top-of-.lg-profile renders full-width above the header card on BOTH desktop
    //    (760px column) and mobile (flex column, just under the slim View-as bar).
    var host = document.querySelector('.lg-profile') || document.querySelector('.lg-shell');
    var card = document.createElement('button');
    card.type = 'button'; card.id = 'lg-privacy-card';
    card.innerHTML =
      '<span class="lpc-ic">' + EYE + '</span>' +
      '<span class="lpc-tx"><span class="lpc-ttl">Privacy</span>' +
      '<span class="lpc-sub">Choose who can see your profile and each section — anyone, members, or just you.</span></span>' +
      '<span class="lpc-cta">Manage<span class="lpc-arrow">→</span></span>';
    card.addEventListener('click', openSheet);
    host.insertBefore(card, host.firstElementChild);

    // 3) the sheet
    backdrop = document.createElement('div'); backdrop.className = 'lpv-backdrop';
    backdrop.addEventListener('click', closeSheet);
    sheet = document.createElement('div'); sheet.className = 'lpv-sheet'; sheet.setAttribute('role', 'dialog'); sheet.setAttribute('aria-modal', 'true');
    sheet.innerHTML =
      '<div class="lpv-bar"><span class="lpv-ttl">Who can see your profile</span>' +
      '<button type="button" class="lpv-done">Done</button></div>' +
      '<div class="lpv-body"></div>';
    sheet.querySelector('.lpv-done').addEventListener('click', closeSheet);
    document.body.appendChild(backdrop); document.body.appendChild(sheet);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sheet.classList.contains('is-open')) closeSheet(); });
  }

  // ── Styles ──────────────────────────────────────────────────────────────────
  function injectStyles() {
    if (document.getElementById('lg-privacy-style')) return;
    var css = [
      /* hide the old privacy UI + inline chips (kept in DOM, just not shown) */
      'html[data-lg-privacy="on"] .lg-viewas__vis,',
      'html[data-lg-privacy="on"] .lg-viewas__disc,',
      'html[data-lg-privacy="on"] .lg-pmp{display:none!important}',
      /* tighten the shell gap that caused the big void on mobile */
      'html[data-lg-privacy="on"] .lg-shell{gap:14px}',

      /* ── intro card (calm settings card, brand tokens) ── */
      '#lg-privacy-card{display:flex;align-items:center;gap:13px;width:100%;text-align:left;cursor:pointer;',
      'background:var(--lg-sage-tint,#eef2e3);border:1px solid var(--lg-sage-3,#d4e0b8);border-radius:16px;',
      'padding:14px 15px;margin:0 0 14px;-webkit-tap-highlight-color:transparent;font:inherit;color:var(--lg-ink,#323532);',
      'transition:border-color .14s ease,box-shadow .16s ease,transform .12s ease}',
      '#lg-privacy-card:hover{border-color:var(--lg-sage,#87986a);box-shadow:0 6px 18px rgba(26,29,26,.08)}',
      '#lg-privacy-card:active{transform:translateY(1px)}',
      '#lg-privacy-card .lpc-ic{flex:0 0 auto;width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;',
      'background:#fff;color:var(--lg-sage-d,#6b7c52);border:1px solid var(--lg-sage-3,#d4e0b8)}',
      '#lg-privacy-card .lpc-ic svg{width:20px;height:20px}',
      '#lg-privacy-card .lpc-tx{display:flex;flex-direction:column;gap:2px;min-width:0;flex:1}',
      '#lg-privacy-card .lpc-ttl{font:700 15px/1.2 var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a)}',
      '#lg-privacy-card .lpc-sub{font:500 12.5px/1.4 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-mute,#6b6f6b)}',
      '#lg-privacy-card .lpc-cta{flex:0 0 auto;display:inline-flex;align-items:center;gap:5px;',
      'background:var(--lg-sage-d,#6b7c52);color:#fff;border-radius:999px;padding:8px 14px;font:800 12.5px/1 var(--lg-font-sans,system-ui,sans-serif)}',
      '#lg-privacy-card .lpc-arrow{transition:transform .15s ease}',
      '#lg-privacy-card:hover .lpc-arrow{transform:translateX(3px)}',
      '@media (max-width:560px){#lg-privacy-card .lpc-sub{display:none}}',

      /* ── sheet ── */
      '.lpv-backdrop{position:fixed;inset:0;background:rgba(20,22,18,.42);z-index:2147483000;opacity:0;visibility:hidden;transition:opacity .22s ease,visibility .22s}',
      '.lpv-backdrop.is-open{opacity:1;visibility:visible}',
      '.lpv-sheet{position:fixed;left:0;right:0;bottom:0;z-index:2147483001;margin:0 auto;width:100%;max-width:560px;',
      'background:var(--lg-cream,#fbfbf8);border-radius:22px 22px 0 0;box-shadow:0 -16px 48px rgba(0,0,0,.28);',
      'max-height:88vh;display:flex;flex-direction:column;transform:translateY(102%);transition:transform .28s cubic-bezier(.22,1,.36,1)}',
      '.lpv-sheet.is-open{transform:none}',
      '.lpv-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 18px 12px;',
      'border-bottom:1px solid var(--lg-line,#e3ddd0);position:sticky;top:0;background:var(--lg-cream,#fbfbf8);border-radius:22px 22px 0 0}',
      '.lpv-bar::before{content:"";position:absolute;top:7px;left:50%;transform:translateX(-50%);width:38px;height:4px;border-radius:999px;background:var(--lg-line,#e3ddd0)}',
      '.lpv-ttl{font:700 17px/1.2 var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a)}',
      '.lpv-done{border:0;background:var(--lg-sage-d,#6b7c52);color:#fff;border-radius:999px;padding:8px 16px;font:800 13px/1 var(--lg-font-sans,system-ui,sans-serif);cursor:pointer}',
      '.lpv-body{overflow-y:auto;-webkit-overflow-scrolling:touch;padding:14px 16px calc(20px + env(safe-area-inset-bottom,0px))}',
      '.lpv-divider{font:700 10.5px/1 var(--lg-font-sans,system-ui,sans-serif);letter-spacing:.12em;text-transform:uppercase;color:var(--lg-mute,#6b6f6b);margin:20px 2px 8px}',

      /* row */
      '.lpv-row{background:#fff;border:1px solid var(--lg-line,#e3ddd0);border-radius:14px;padding:14px 15px 12px;margin:0 0 10px}',
      '.lpv-row--head{border-color:var(--lg-sage-3,#d4e0b8);background:linear-gradient(180deg,#fcfdf9,#fff)}',
      '.lpv-name{font:700 15px/1.2 var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a)}',
      '.lpv-sub{font:500 12px/1.4 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-mute,#6b6f6b);margin:2px 0 0}',

      /* slider (ported from visibility-sandbox.html) */
      '.lpv-slider{margin-top:12px}',
      '.lpv-track{position:relative;height:40px}',
      '.lpv-rail{position:absolute;top:50%;left:14px;right:14px;height:6px;transform:translateY(-50%);background:var(--lg-sage-tint,#eef2e3);border-radius:999px}',
      '.lpv-ceil{position:absolute;top:50%;height:6px;transform:translateY(-50%);border-radius:999px;',
      'background:repeating-linear-gradient(45deg,#efe2d6,#efe2d6 5px,#f6ecdf 5px,#f6ecdf 10px)}',
      '.lpv-fill{position:absolute;top:50%;left:14px;height:6px;transform:translateY(-50%);background:var(--lg-sage,#87986a);border-radius:999px}',
      '.lpv-stops{position:absolute;inset:0;display:flex;justify-content:space-between;align-items:center;padding:0 8px}',
      '.lpv-stop{width:13px;height:13px;border-radius:50%;background:#fff;border:2px solid var(--lg-sage-3,#d4e0b8);cursor:pointer}',
      '.lpv-stop.is-blocked{border-color:#e4c9a8;background:#faf1e6}',
      '.lpv-thumb{position:absolute;top:50%;width:26px;height:26px;border-radius:50%;background:#fff;border:3px solid var(--lg-sage-d,#6b7c52);',
      'transform:translate(-50%,-50%);box-shadow:0 2px 8px rgba(0,0,0,.18);transition:left .16s ease;z-index:2;pointer-events:none}',
      '.lpv-labels{display:flex;justify-content:space-between;margin-top:3px;font:600 11.5px/1.2 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-mute,#6b6f6b)}',
      '.lpv-labels span{cursor:pointer;padding:2px 2px}',
      '.lpv-labels span:first-child{text-align:left}.lpv-labels span:last-child{text-align:right}',
      '.lpv-labels span.on{color:var(--lg-sage-d,#6b7c52);font-weight:800}',
      '.lpv-pro{display:inline-block;background:var(--lg-amber,#ecb351);color:#4a3613;font:800 8.5px/1 var(--lg-font-sans,system-ui,sans-serif);letter-spacing:.04em;padding:2px 5px;border-radius:999px;vertical-align:1px}',

      /* explanation strip */
      '.lpv-exp{display:flex;gap:8px;align-items:flex-start;margin-top:11px;padding:10px 11px;border-radius:11px;background:var(--lg-sage-tint,#eef2e3);font:13px/1.45 var(--lg-font-sans,system-ui,sans-serif);color:#3c4135}',
      '.lpv-exp svg{flex:0 0 auto;width:17px;height:17px;margin-top:1px}',
      '.lpv-exp b{color:var(--lg-charcoal,#1a1d1a)}',
      '.lpv-exp.warn{background:#fbeede;color:#6a4a2a}.lpv-exp.warn b{color:#8a531f}',

      /* discussion segmented toggle */
      '.lpv-seg{display:inline-flex;margin-top:12px;border:1px solid var(--lg-line,#e3ddd0);border-radius:999px;overflow:hidden;background:#fff}',
      '.lpv-seg button{border:0;background:none;cursor:pointer;padding:8px 16px;font:700 12.5px/1 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-mute,#6b6f6b)}',
      '.lpv-seg button.on{background:var(--lg-sage,#87986a);color:#fff}',

      /* toast */
      '.lpv-toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%) translateY(12px);z-index:2147483002;',
      'background:var(--lg-charcoal,#1a1d1a);color:#fff;padding:11px 16px;border-radius:12px;font:600 13px/1.3 var(--lg-font-sans,system-ui,sans-serif);',
      'box-shadow:0 8px 24px rgba(0,0,0,.3);opacity:0;visibility:hidden;transition:opacity .2s,transform .2s,visibility .2s;max-width:84vw}',
      '.lpv-toast.show{opacity:1;visibility:visible;transform:translateX(-50%) translateY(0)}',

      /* desktop: center the sheet as a modal */
      '@media (min-width:641px){',
      '.lpv-sheet{left:50%;right:auto;bottom:auto;top:50%;transform:translate(-50%,-46%) scale(.98);border-radius:20px;max-height:84vh;opacity:0;transition:opacity .2s ease,transform .22s ease}',
      '.lpv-sheet.is-open{transform:translate(-50%,-50%);opacity:1}',
      '.lpv-bar{border-radius:20px 20px 0 0}.lpv-bar::before{display:none}}',

      /* ── DARK MODE (sheet + card) ── */
      'html[data-lguser-theme="dark"] #lg-privacy-card{background:#22271f;border-color:#374231;color:#e7ebe1}',
      'html[data-lguser-theme="dark"] #lg-privacy-card .lpc-ic{background:#1a1d1a;border-color:#374231;color:#a9c089}',
      'html[data-lguser-theme="dark"] #lg-privacy-card .lpc-ttl{color:#f2f4ee}',
      'html[data-lguser-theme="dark"] #lg-privacy-card .lpc-sub{color:#9aa097}',
      'html[data-lguser-theme="dark"] .lpv-sheet,',
      'html[data-lguser-theme="dark"] .lpv-bar{background:#15171a}',
      'html[data-lguser-theme="dark"] .lpv-ttl{color:#f2f4ee}',
      'html[data-lguser-theme="dark"] .lpv-bar{border-bottom-color:#2b2f2a}',
      'html[data-lguser-theme="dark"] .lpv-row{background:#1b1e21;border-color:#2b2f2a}',
      'html[data-lguser-theme="dark"] .lpv-row--head{background:#1d221b;border-color:#3a472f}',
      'html[data-lguser-theme="dark"] .lpv-name{color:#f2f4ee}',
      'html[data-lguser-theme="dark"] .lpv-sub,html[data-lguser-theme="dark"] .lpv-labels{color:#9aa097}',
      'html[data-lguser-theme="dark"] .lpv-rail{background:#2b2f2a}',
      'html[data-lguser-theme="dark"] .lpv-stop{background:#15171a;border-color:#3a472f}',
      'html[data-lguser-theme="dark"] .lpv-thumb{background:#e7ebe1;border-color:#a9c089}',
      'html[data-lguser-theme="dark"] .lpv-exp{background:#22271f;color:#cdd3c6}',
      'html[data-lguser-theme="dark"] .lpv-exp b{color:#f2f4ee}',
      'html[data-lguser-theme="dark"] .lpv-exp.warn{background:#34291c;color:#e8c79b}',
      'html[data-lguser-theme="dark"] .lpv-seg{background:#15171a;border-color:#2b2f2a}',
      'html[data-lguser-theme="dark"] .lpv-divider{color:#9aa097}',

      /* ── DARK MODE (the EDIT surface itself) ──
         u.php hardcodes light colors that don't follow the dark theme: .lg-block
         cards are #fff, and .lg-viewas uses var(--lg-charcoal) (which the Dark theme
         flips LIGHT) → unreadable. This layer is edit-surface-only, so these rules
         are safely scoped to the owner editor (the surface Buck reported). */
      'html[data-lguser-theme="dark"] .lg-block{background:#1b1e21!important;border-color:#2b2f2a!important}',
      'html[data-lguser-theme="dark"] .lg-block .lg-bh,html[data-lguser-theme="dark"] .lg-block h1,html[data-lguser-theme="dark"] .lg-block h2,html[data-lguser-theme="dark"] .lg-block h3{color:#f2f4ee!important}',
      'html[data-lguser-theme="dark"] .lg-viewas{background:#121417!important;color:#cdd3c6!important}',
      'html[data-lguser-theme="dark"] .lg-viewas__edit{background:#2c312d!important;color:#f2f4ee!important}',
      'html[data-lguser-theme="dark"] .lg-bizpill{background:#22262a!important;border-color:#333833!important;color:#e7ebe1!important}',
      'html[data-lguser-theme="dark"] .lg-prec{background:#22262a!important;border-color:#333833!important;color:#e7ebe1!important}',
      'html[data-lguser-theme="dark"] .lg-chip,html[data-lguser-theme="dark"] .lg-link,html[data-lguser-theme="dark"] .lg-loc__f,html[data-lguser-theme="dark"] .lg-dropoff__f{background:#22262a!important;border-color:#333833!important;color:#e7ebe1!important}',
      'html[data-lguser-theme="dark"] .lg-edit.editing{background:#22262a!important;color:#e7ebe1!important}',
      'html[data-lguser-theme="dark"] .lg-idrow__cam,html[data-lguser-theme="dark"] .lg-banner__set{background:#22262a!important;color:#e7ebe1!important}',
      /* sections palette (caddy) + light menus + gate */
      'html[data-lguser-theme="dark"] .lg-caddy{background:#15171a!important;border-color:#2b2f2a!important}',
      'html[data-lguser-theme="dark"] .lg-caddy__head strong{color:#f2f4ee!important}',
      'html[data-lguser-theme="dark"] .lg-caddy__item{background:#1b1e21!important;border-color:#2b2f2a!important;color:#e7ebe1!important}',
      'html[data-lguser-theme="dark"] .lg-lm,html[data-lguser-theme="dark"] .lg-light-menu,html[data-lguser-theme="dark"] .lg-gate{background:#1b1e21!important;border-color:#2b2f2a!important;color:#e7ebe1!important}',
      'html[data-lguser-theme="dark"] .lg-block input,html[data-lguser-theme="dark"] .lg-block textarea,html[data-lguser-theme="dark"] .lg-caddy input{background:#22262a!important;color:#e7ebe1!important;border-color:#333833!important}'
    ].join('');
    var s = document.createElement('style'); s.id = 'lg-privacy-style'; s.textContent = css;
    document.head.appendChild(s);
  }

  // ── Boot ──────────────────────────────────────────────────────────────────────
  // app-settings.js owns data-lguser-theme; it also runs inside this iframe, so the
  // attr is normally already on <html>. As a belt-and-suspenders for the iframe
  // case, mirror a picked "dark" theme ourselves if nothing set the attr yet (only
  // 'dark' — other themes look right with the default light styling).
  function mirrorTheme() {
    try {
      var root = document.documentElement;
      if (!root.getAttribute('data-lguser-theme') && localStorage.getItem('lg-set-theme') === 'dark') {
        root.setAttribute('data-lguser-theme', 'dark');
      }
    } catch (e) {}
  }

  function boot() {
    if (!isEditSurface()) return;
    if (window.__loothPrivacySheet) return;
    window.__loothPrivacySheet = true;
    mirrorTheme();
    build();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
