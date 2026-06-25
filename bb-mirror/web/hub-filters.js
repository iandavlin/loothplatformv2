/* Hub control-sidebar interactivity:
 *   - Hub search  = LIVE in-page filter over the unified feed (primary), an AND
 *     dimension with the rail. Typing re-renders #hub-feed-results + the chip bar
 *     and updates the URL. A title dropdown stays as a secondary "quick jump".
 *   - Author box  = autocomplete; pick a name -> appended to the ?author= CSV.
 * Progressive enhancement: without JS the q form full-searches and the author
 * form sets one author (both server-rendered the same way).
 * Endpoint: <base>/?suggest=hub|author&q=<text>  (forums/_suggest.php). */
(function () {
  'use strict';
  var BASE = (window.LG_FORUM_BASE || '/hub').replace(/\/$/, '');
  var wrap = document.querySelector('.feed-toolbar-search');
  if (!wrap) return;

  function debounce(fn, ms) {
    var t; return function () { var a = arguments, c = this;
      clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); };
  }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (m) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]; }); }
  function hide(box) { box.hidden = true; box.innerHTML = ''; }

  /* ---- secondary: title quick-jump / author autocomplete dropdowns -------- */
  // Bold the matched substring (escape FIRST, then wrap — indexes computed on
  // the raw string but applied to its escaped twin would drift on &/<; so match
  // on the raw, slice the raw, escape each slice).
  function highlight(name, q) {
    var i = name.toLowerCase().indexOf(q.toLowerCase());
    if (i < 0) return esc(name);
    return esc(name.slice(0, i)) + '<b>' + esc(name.slice(i, i + q.length)) + '</b>' + esc(name.slice(i + q.length));
  }
  function avatarHtml(it) {
    if (it.avatar_url) {
      return '<img class="hub-suggest__av" src="' + esc(it.avatar_url) + '" alt="" loading="lazy">';
    }
    var init = (it.name || '?').trim().charAt(0).toUpperCase();
    return '<span class="hub-suggest__av hub-suggest__av--init" aria-hidden="true">' + esc(init) + '</span>';
  }
  function setActive(box, item) {
    box.querySelectorAll('.hub-suggest__item.is-active').forEach(function (el) { el.classList.remove('is-active'); });
    if (item) { item.classList.add('is-active'); item.scrollIntoView({ block: 'nearest' }); }
  }
  function wireDropdown(input, box, mode, onPick) {
    var run = debounce(function () {
      var q = input.value.trim();
      if (q.length < 2) { hide(box); return; }
      fetch(BASE + '/?suggest=' + mode + '&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.results || !d.results.length) { hide(box); return; }
          box.innerHTML = d.results.map(function (it) {
            if (mode === 'author') {
              return '<button type="button" class="hub-suggest__item" role="option" data-pick="' + esc(it.name) + '">' +
                     avatarHtml(it) +
                     '<span class="hub-suggest__name">' + highlight(it.name, q) + '</span>' +
                     '<span class="hub-suggest__n">' + it.n + (it.n === 1 ? ' post' : ' posts') + '</span></button>';
            }
            var label = it.kind === 'discussion' ? 'Discussion' : it.kind;
            return '<a class="hub-suggest__item" href="' + esc(it.url) + '">' +
                   '<span class="hub-suggest__kind">' + esc(label) + '</span>' + esc(it.title) + '</a>';
          }).join('');
          box.hidden = false;
        })
        .catch(function () { hide(box); });
    }, 160);
    input.addEventListener('input', run);
    input.addEventListener('focus', function () { if (input.value.trim().length >= 2) run(); });
    box.addEventListener('mousedown', function (e) {
      var pick = e.target.closest('[data-pick]');
      if (pick) { e.preventDefault(); onPick(pick.getAttribute('data-pick')); }
    });
    // Keyboard: ↑/↓ walk the list, Enter picks the active row (or the top one);
    // with the dropdown hidden, Enter falls through to the plain form submit
    // (the no-JS path: ?author=<typed text>).
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { hide(box); return; }
      if (box.hidden) return;
      var items = box.querySelectorAll('.hub-suggest__item');
      if (!items.length) return;
      var cur = box.querySelector('.hub-suggest__item.is-active');
      var idx = Array.prototype.indexOf.call(items, cur);
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        idx = e.key === 'ArrowDown'
          ? (idx + 1) % items.length
          : (idx <= 0 ? items.length - 1 : idx - 1);
        setActive(box, items[idx]);
      } else if (e.key === 'Enter') {
        var pick = cur || items[0];
        if (pick && pick.hasAttribute('data-pick')) { e.preventDefault(); onPick(pick.getAttribute('data-pick')); }
        else if (pick && pick.href) { e.preventDefault(); window.location.href = pick.href; }
      }
    });
  }

  function addAuthor(name) {
    var u = new URL(window.location.href);
    var cur = (u.searchParams.get('author') || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
    if (cur.indexOf(name) === -1) cur.push(name);
    u.searchParams.set('author', cur.join(','));   // AND-combines: keeps q/type/cat already in the URL
    u.searchParams.delete('offset');
    // The author field lives only inside #hub-fmodal. If the modal is open (the
    // mobile search tray, or the desktop dialog), apply in-place via forums.js's
    // a[href]->fmodalApply path — same as the facet links — so the tray STAYS OPEN
    // and the chips refresh, instead of a full navigation that closes it (Ian
    // 2026-06-25). NO forums.js edit: we just dispatch a click on a throwaway
    // in-body <a href>, which forums.js's existing modal-body delegate catches.
    var mbody = document.querySelector('#hub-fmodal:not([hidden]) .hub-fmodal__body');
    if (mbody) {
      var a = document.createElement('a');
      a.href = u.pathname + u.search; a.style.display = 'none';
      mbody.appendChild(a); a.click(); mbody.removeChild(a);
    } else {
      window.location.href = u.toString();
    }
  }

  /* ---- primary: live in-page feed filter ---------------------------------- */
  var inflight = null;
  function liveSearch(q) {
    var u = new URL(window.location.href);
    if (q) u.searchParams.set('q', q); else u.searchParams.delete('q');
    u.searchParams.delete('offset');
    if (inflight) inflight.abort();
    inflight = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var results = document.getElementById('hub-feed-results');
    if (results) results.classList.add('is-loading');
    fetch(u.toString(), { credentials: 'same-origin', signal: inflight && inflight.signal })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var nr = doc.getElementById('hub-feed-results');
        var cr = document.getElementById('hub-feed-results');
        if (nr && cr) cr.replaceWith(nr);
        // chip bar may appear / change / disappear
        var page = document.querySelector('.feed-page');
        var nc = doc.querySelector('.hub-chipbar');
        var cc = document.querySelector('.hub-chipbar');
        if (cc && nc) cc.replaceWith(nc);
        else if (cc && !nc) cc.remove();
        else if (!cc && nc && page) page.insertBefore(nc, page.querySelector('.feed-sort-bar'));
        // ALSO sync the in-modal chip bar (.hub-fmodal__chips) — the mobile search
        // tray's filter surface (the feed .hub-chipbar above is hidden on <=640).
        // Without this, a free-text q typed in the tray updated the feed but never
        // showed a chip in the tray (Ian 2026-06-25). Swap ONLY this element (not the
        // whole modal body) so the q input keeps focus mid-type. Desktop: it's
        // display:none >=641, so this is a harmless no-op there.
        var nmc = doc.querySelector('.hub-fmodal__chips');
        var omc = document.querySelector('.hub-fmodal__chips');
        if (omc && nmc) omc.replaceWith(nmc);
        else if (omc && !nmc) omc.remove();
        else if (!omc && nmc) {
          var mb = document.querySelector('#hub-fmodal .hub-fmodal__body');
          if (mb) mb.insertBefore(nmc, mb.firstChild);
        }
        history.replaceState({}, '', u.toString());
        // let other scripts (comment modal, embeds) re-bind swapped-in cards
        document.dispatchEvent(new CustomEvent('hub:feed-updated'));
      })
      .catch(function () {})
      .finally(function () {
        var el = document.getElementById('hub-feed-results');
        if (el) el.classList.remove('is-loading');
      });
  }

  // Hub search: LIVE in-page filter only — no quick-jump dropdown (the feed IS
  // the result). Author box keeps its autocomplete below.
  // Live in-page filter for EVERY hub q field — the modal's "Search the Hub" AND
  // the quick-search bubble on the bar (Ian 2026-06-20). One liveSearch() path;
  // fields mirror each other so opening Adv Search keeps what was typed.
  var qFields = document.querySelectorAll('[data-hub-search]');
  function syncQ(val, except) { qFields.forEach(function (n) { if (n !== except && n.value !== val) n.value = val; }); }
  qFields.forEach(function (qf) {
    qf.addEventListener('input', debounce(function () { var v = qf.value.trim(); syncQ(qf.value, qf); liveSearch(v); }, 280));
    var form = qf.closest('form');
    // Enter: live-filter only (don't reload the whole page).
    if (form) form.addEventListener('submit', function (e) { e.preventDefault(); var v = qf.value.trim(); syncQ(qf.value, qf); liveSearch(v); });
  });

  var aIn  = wrap.querySelector('[data-hub-author]');
  var aBox = wrap.querySelector('[data-hub-suggest="author"]');
  if (aIn && aBox) wireDropdown(aIn, aBox, 'author', addAuthor);

  document.addEventListener('click', function (e) {
    if (!e.target.closest('.hub-tsearch')) {
      wrap.querySelectorAll('.hub-suggest').forEach(function (b) { hide(b); });
    }
  });
})();

/* Category accordion — single-open. Chevron toggles its leaves; opening one
 * collapses any other open parent. The parent NAME stays a filter link. */
(function () {
  'use strict';
  var acc = document.getElementById('hub-cat-accordion');
  if (!acc) return;
  acc.addEventListener('click', function (e) {
    var chev = e.target.closest('.hub-acc__chev');
    if (!chev || chev.classList.contains('hub-acc__chev--none')) return;
    // The rail renders native <details class="hub-acc">; intercept the chevron
    // so the parent NAME stays a filter link, and drive the native `open`
    // attribute ourselves (CSS + browser key off [open], not a class).
    e.preventDefault();
    var item = chev.closest('.hub-acc');
    if (!item) return;
    var willOpen = !item.open;
    // single-open: collapse any other open parent
    acc.querySelectorAll('details.hub-acc[open]').forEach(function (o) {
      if (o === item) return;
      o.open = false;
      var c = o.querySelector('.hub-acc__chev'); if (c) c.setAttribute('aria-expanded', 'false');
    });
    item.open = willOpen;
    chev.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
  });
})();
